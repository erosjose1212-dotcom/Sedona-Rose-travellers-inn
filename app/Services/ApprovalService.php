<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Folio;
use App\Models\Invoice;
use App\Models\PaymentEntry;
use App\Models\PosItem;
use App\Models\Shift;
use App\Models\StatementLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    public function __construct(private FinancialPostingService $posting, private AuditService $audit, private BusinessDayService $days, private InventoryService $inventory, private ShiftAccessService $custody) {}

    /** @param array<string, mixed> $payload */
    public function request(Request $request, string $kind, int $sourceId, array $payload, string $reason, ?string $evidence = null): ApprovalRequest
    {
        $data = $this->validatePayload($kind, $payload);
        abort_unless(match ($kind) {
            'stock_adjustment', 'stock_recount' => $request->user()->hasAnyRole(['admin', 'owner']),
            'cash_drop', 'float_addition', 'bank_deposit', 'handover_variance', 'shift_variance' => $request->user()->can('manage_shifts'),
            'expense_review', 'expense_reversal' => $request->user()->can('manage_shifts'),
            'pos_refund' => $request->user()->can('post_pos_sales'),
            'statement_void' => $request->user()->can('review-financial'),
            default => $request->user()->can('manage_folios'),
        }, 403);
        if ($kind === 'folio_override' && isset($data['discount_override_amount'])) {
            abort_unless($request->user()->hasAnyRole(['admin', 'owner']), 403, 'Only an admin or owner can request a custom discount amount.');
        }

        return DB::transaction(function () use ($request, $kind, $sourceId, $data, $reason, $evidence): ApprovalRequest {
            $source = $this->source($kind, $sourceId);
            $this->checkSource($kind, $source, $data);
            if ($kind === 'expense_review' && ApprovalRequest::where('kind', $kind)->where('source_type', $source::class)->where('source_id', $sourceId)->whereIn('status', ['pending', 'approved'])->exists()) {
                throw ValidationException::withMessages(['approval' => 'This paid expense already has an unresolved review request.']);
            }
            if ($kind === 'invoice_tax_treatment') {
                if (trim($evidence ?? '') === '') {
                    throw ValidationException::withMessages(['evidence_reference' => 'Record the accountant or exemption evidence reference.']);
                }
                $this->days->assertWritable($this->days->date($source->checked_out_at));
            }
            $approval = ApprovalRequest::create(['kind' => $kind, 'source_type' => $source::class, 'source_id' => $sourceId, 'requested_by' => $request->user()->id, 'payload' => $data, 'source_hash' => $this->fingerprint($source, $kind, $data), 'status' => 'pending', 'reason' => $reason, 'evidence_reference' => $evidence]);
            $this->audit->record($request, 'approval.requested', $approval, ['kind' => $kind, 'source_id' => $sourceId, 'payload' => $data, 'reason' => $reason, 'evidence_reference' => $evidence]);
            if ($approval->isStockChange()) {
                $this->applyStockChange($request, $approval);
            } elseif ($approval->permitsOwnerSelfReview($request->user()) && $request->user()->can('review-financial')) {
                $approval->update(['status' => 'approved', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => 'Automatically approved by the owner for a room rate / custom discount change.']);
                $this->audit->record($request, 'approval.approved', $approval, ['automatic' => true, 'note' => $approval->review_note]);
            }

            return $approval;
        });
    }

    public function review(Request $request, ApprovalRequest $approval, bool $approve, string $note): void
    {
        Gate::authorize('review-financial');
        DB::transaction(function () use ($request, $approval, $approve, $note): void {
            $approval = ApprovalRequest::whereKey($approval->id)->lockForUpdate()->firstOrFail();
            abort_if($approval->requested_by === $request->user()->id && ! $approval->permitsOwnerSelfReview($request->user()), 403, 'A different reviewer is required.');
            abort_unless($approval->status === 'pending', 409, 'This request has already been reviewed.');
            $source = $this->source($approval->kind, $approval->source_id);
            if ($approve) {
                $this->requireCurrent($approval, $source);
            }
            $approval->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $request->user()->id, 'review_note' => $note, 'reviewed_at' => now()]);
            if ($approval->kind === 'expense_review' && $source->status === 'pending_review' && hash_equals($approval->source_hash, $this->fingerprint($source, $approval->kind, $approval->payload))) {
                $this->days->assertWritable($source->expense_date->toDateString());
                $source->update(['status' => $approve ? 'approved' : 'rejected_paid', 'approval_request_id' => $approval->id]);
                $approval->update(['status' => $approve ? 'executed' : 'rejected', 'executed_by' => $request->user()->id, 'executed_at' => now()]);
            }
            $this->audit->record($request, $approve ? 'approval.approved' : 'approval.rejected', $approval, ['note' => $note]);
        });
    }

    /** @return array<string, mixed> */
    public function execute(Request $request, ApprovalRequest $approval): array
    {
        abort_unless($approval->requested_by === $request->user()->id || $request->user()->can('review-financial'), 403);
        if ($approval->isStockChange()) {
            abort_unless($request->user()->hasAnyRole(['admin', 'owner']), 403);
        }

        return $this->posting->execute($request, 'approval.execute', $approval->id, ['approval_id' => $approval->id, 'shift_override_reason' => $request->input('shift_override_reason')], function () use ($request, $approval): array {
            $approval = ApprovalRequest::whereKey($approval->id)->lockForUpdate()->firstOrFail();
            $canApplyStock = $approval->isStockChange() && in_array($approval->status, ['pending', 'approved'], true);
            if (! $canApplyStock && ($approval->status !== 'approved' || ($approval->reviewed_by === $approval->requested_by && ! $approval->permitsOwnerSelfReview($approval->reviewer)))) {
                throw ValidationException::withMessages(['approval' => 'Independent approval is required and can be executed only once.']);
            }
            $source = $this->source($approval->kind, $approval->source_id);
            $this->requireCurrent($approval, $source);
            $data = $approval->payload;
            if ($approval->isStockChange()) {
                $this->applyStockChange($request, $approval);

                return ['approval_id' => $approval->id];
            }
            $this->days->assertWritable();
            if ($approval->kind === 'statement_void') {
                $this->days->assertWritable($source->business_date->toDateString());
                DB::table('statement_exclusions')->insert(['statement_line_id' => $source->id, 'approval_request_id' => $approval->id, 'user_id' => $request->user()->id, 'reason' => $approval->reason, 'created_at' => now()]);
            } elseif ($approval->kind === 'invoice_tax_treatment') {
                $this->days->assertWritable($this->days->date($source->checked_out_at));
            } elseif ($approval->kind === 'folio_override') {
                $source->fill($data);
                if (isset($data['discount_type'])) {
                    $source->senior_pwd_discount = in_array($data['discount_type'], ['SENIOR', 'PWD'], true);
                    $source->discount_override_amount = $data['discount_override_amount'] ?? null;
                    if ($data['discount_type'] === 'NONE') {
                        $source->discount_id_ref = null;
                    }
                }
                $source->recalculate();
            } elseif ($approval->kind === 'deposit_refund') {
                $amount = BillingService::cents($data['amount']);
                $entry = $this->posting->post($request, $source, 'deposit_refund', $data['payment_method'] === 'cash' ? -$amount : 0, $data['payment_method'] === 'gcash' ? -$amount : 0, $data['gcash_reference'] ?? null, $approval->id);
                $this->posting->allocateRefund($entry, $source, $amount);
                $source->update(['deposit_refunded_amount' => (BillingService::cents($source->deposit_refunded_amount) + $amount) / 100, 'deposit_refunded_at' => now()]);
            } elseif ($approval->kind === 'pos_refund') {
                $amount = BillingService::cents($data['amount']);
                $this->posting->post($request, $source->order, 'pos_refund', $data['payment_method'] === 'cash' ? -$amount : 0, $data['payment_method'] === 'gcash' ? -$amount : 0, $data['gcash_reference'] ?? null, $approval->id, $source->id);
            } elseif ($approval->kind === 'expense_reversal') {
                $shift = $source->payment_source === 'cash_drawer' ? $this->custody->requireOperatingShift($request->user(), $request->input('shift_override_reason'), 'expense.reversal') : null;
                Expense::create(['user_id' => $request->user()->id, 'shift_id' => $shift?->id, 'voucher_number' => Expense::generateVoucherNumber(), 'expense_date' => $this->days->date(), 'category' => $source->category, 'description' => 'Returned expense '.$source->voucher_number, 'amount' => -BillingService::cents($source->amount) / 100, 'payment_source' => $source->payment_source, 'receipt_reference' => $approval->evidence_reference, 'status' => 'approved', 'reversal_of_id' => $source->id, 'approval_request_id' => $approval->id, 'notes' => $approval->reason]);
                if ($source->status === 'rejected_paid') {
                    $source->update(['status' => 'approved']);
                }
            } elseif (in_array($approval->kind, ['cash_drop', 'float_addition', 'bank_deposit'], true)) {
                $shift = $this->custody->requireOperatingShift($request->user(), $request->input('shift_override_reason'), $approval->kind);
                if ($shift->id !== $source->id) {
                    throw ValidationException::withMessages(['shift' => 'The approved drawer is no longer active.']);
                }
                $amount = BillingService::cents($data['amount']);
                if ($approval->kind !== 'float_addition' && $amount > BillingService::cents(app(FinancialSummaryService::class)->shift($shift)['expectedCash'])) {
                    throw ValidationException::withMessages(['amount' => 'Movement exceeds expected drawer cash.']);
                }
                CashMovement::create(['shift_id' => $shift->id, 'user_id' => $request->user()->id, 'approval_request_id' => $approval->id, 'operation_key' => $request->input('operation_key'), 'kind' => $approval->kind, 'destination' => $data['destination'], 'amount' => ($approval->kind === 'float_addition' ? $amount : -$amount) / 100, 'reference' => $data['reference'], 'business_date' => $this->days->date(), 'posted_at' => now()]);
            } elseif ($approval->kind === 'handover_variance') {
                $source->update(['handover_status' => 'variance_approved']);
            } elseif ($approval->kind === 'write_off') {
                $bill = app(BillingService::class)->calculate($source);
                if (BillingService::cents($bill['finalBalanceToPay']) !== BillingService::cents($data['amount'])) {
                    throw ValidationException::withMessages(['approval' => 'The bill changed. Request a new write-off approval.']);
                }
                $source->fill($bill['attributes']);
                $source->fill(['status' => 'checked_out', 'checked_out_at' => now(), 'checked_out_by' => $request->user()->id, 'force_checkout' => true, 'force_reason' => $approval->reason, 'force_by' => $approval->reviewed_by, 'force_requested_by' => $approval->requested_by, 'loss_slip_reference' => 'FCE-'.str_pad((string) $source->id, 6, '0', STR_PAD_LEFT), 'cash_tendered' => 0, 'gcash_amount' => 0, 'gcash_reference' => null, 'change_due' => 0])->save();
                $source->issueReceipt();
                $this->posting->post($request, $source, 'write_off', 0, 0, null, $approval->id);
                $source->room->update(['status' => 'available']);
            }
            $approval->update(['status' => 'executed', 'executed_by' => $request->user()->id, 'executed_at' => now()]);
            $this->audit->record($request, 'approval.executed', $approval, ['kind' => $approval->kind, 'source_id' => $approval->source_id, 'payload' => $data]);

            return ['approval_id' => $approval->id];
        });
    }

    private function applyStockChange(Request $request, ApprovalRequest $approval): void
    {
        $data = $approval->payload;
        if ($approval->kind === 'stock_adjustment') {
            $this->inventory->setStockCount($approval->source_id, (int) $data['stock_quantity'], $data['reason'], $approval->reason, $request->user()->id);
        } else {
            $this->inventory->batchRecount($data['counts'], $request->user()->id, $approval->reason);
        }
        $approval->update(['status' => 'executed', 'executed_by' => $request->user()->id, 'executed_at' => now()]);
        $this->audit->record($request, 'approval.executed', $approval, ['kind' => $approval->kind, 'source_id' => $approval->source_id, 'payload' => $data, 'approval_required' => false]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function validatePayload(string $kind, array $payload): array
    {
        $money = ['amount' => 'required|numeric|min:0.01|max:500000|decimal:0,2', 'payment_method' => 'required|in:cash,gcash', 'gcash_reference' => 'required_if:payment_method,gcash|nullable|string|max:50'];
        $rules = match ($kind) {
            'deposit_refund', 'pos_refund' => $money,
            'folio_override' => ['room_charge' => 'sometimes|numeric|min:0|max:500000|decimal:0,2', 'checked_in_at' => 'prohibited',
                'discount_type' => 'sometimes|in:DC,NONE,SENIOR,PWD', 'discount_id_ref' => 'sometimes|string|max:100',
                'discount_override_amount' => 'sometimes|numeric|min:0|max:500000|decimal:0,2'],
            'stock_adjustment' => ['stock_quantity' => 'required|integer|min:0|max:100000', 'reason' => 'required|in:restock,adjustment,damage_spoilage,return'],
            'stock_recount' => ['counts' => 'required|array|min:1', 'counts.*' => 'nullable|integer|min:0|max:100000'],
            'cash_drop', 'float_addition', 'bank_deposit' => ['amount' => 'required|numeric|min:0.01|max:500000|decimal:0,2', 'destination' => $kind === 'bank_deposit' ? 'required|in:bank' : 'required|in:safe,owner_funds', 'reference' => 'required|string|max:100'],
            'invoice_tax_treatment' => ['taxable_amount' => 'required|numeric|min:0|max:500000|decimal:0,2'],
            'write_off' => ['amount' => 'required|numeric|min:0|max:500000|decimal:0,2'],
            'expense_review', 'expense_reversal', 'handover_variance', 'shift_variance', 'statement_void' => [],
            default => throw ValidationException::withMessages(['kind' => 'Unsupported approval kind.']),
        };
        $data = Validator::make($payload, $rules)->validate();
        if ($kind === 'folio_override' && $data === []) {
            throw ValidationException::withMessages(['payload' => 'Select at least one override.']);
        }

        return $data;
    }

    private function source(string $kind, int $id): Model
    {
        $class = match ($kind) {
            'stock_adjustment', 'stock_recount' => PosItem::class,
            'cash_drop', 'float_addition', 'bank_deposit', 'handover_variance', 'shift_variance' => Shift::class,
            'expense_review', 'expense_reversal' => Expense::class,
            'pos_refund' => PaymentEntry::class,
            'statement_void' => StatementLine::class,
            default => Folio::class,
        };

        return $class::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $data */
    private function checkSource(string $kind, Model $source, array $data): void
    {
        if ($kind === 'folio_override' && array_key_exists('checked_in_at', $data)) {
            throw ValidationException::withMessages(['checked_in_at' => 'Only RA (admin) can correct check-in time in Check-in Time Corrections. Cancel this old request and use the admin panel.']);
        }
        if ($source instanceof Folio) {
            if ($kind === 'deposit_refund') {
                abort_if($source->isActive(), 409, 'Settle the bill before refunding.');
                if (BillingService::cents($data['amount']) > BillingService::cents(app(BillingService::class)->calculate($source)['remainingDeposit'])) {
                    throw ValidationException::withMessages(['amount' => 'Refund exceeds the remaining deposit.']);
                }
            } elseif ($kind === 'invoice_tax_treatment') {
                abort_if(Invoice::where('folio_id', $source->id)->exists(), 409, 'The issued invoice is immutable.');
                abort_unless(! $source->isActive() && ! $source->force_checkout, 409, 'Settle a paid sale first.');
                if (BillingService::cents($data['taxable_amount']) > BillingService::cents($source->gross_total) - BillingService::cents($source->discount_amount)) {
                    throw ValidationException::withMessages(['taxable_amount' => 'Taxable amount exceeds the invoice total.']);
                }
            } else {
                abort_unless($source->isActive(), 409, 'Settled folios cannot be changed.');
                if ($kind === 'folio_override') {
                    $discountType = DiscountService::normalizeDiscountType($data['discount_type'] ?? $source->discount_type);
                    if (! $discountType && ! isset($data['discount_type']) && $source->senior_pwd_discount) {
                        $discountType = 'SENIOR';
                    }
                    if (in_array($discountType, ['SENIOR', 'PWD'], true) && trim($data['discount_id_ref'] ?? $source->discount_id_ref ?? '') === '') {
                        throw ValidationException::withMessages(['discount_id_ref' => 'Record the statutory discount ID reference.']);
                    }
                    if (isset($data['discount_override_amount']) && (! $discountType
                        || BillingService::cents($data['discount_override_amount']) > BillingService::cents($data['room_charge'] ?? $source->room_charge))) {
                        throw ValidationException::withMessages(['discount_override_amount' => 'Choose a discount type and an amount no greater than the room charge.']);
                    }
                }
            }
        }
        if ($kind === 'statement_void') {
            abort_if(DB::table('statement_matches')->where('statement_line_id', $source->id)->exists() || DB::table('statement_exclusions')->where('statement_line_id', $source->id)->exists(), 409, 'Only an unmatched, active import can be excluded.');
        }
        if ($kind === 'pos_refund') {
            abort_unless($source->kind === 'pos_sale' && $source->order_id, 409);
            if (($this->days->settings()['tax_mode'] ?? '') === 'vat_inclusive' && ! Invoice::where('order_id', $source->order_id)->exists()) {
                throw ValidationException::withMessages(['configuration' => 'Issue the original VAT invoice before recording its refund.']);
            }
            $reversed = PaymentEntry::where('reversal_of_id', $source->id)->get()->sum(fn (PaymentEntry $p): int => -BillingService::cents($p->cash_amount) - BillingService::cents($p->gcash_amount));
            if (BillingService::cents($data['amount']) > BillingService::cents($source->cash_amount) + BillingService::cents($source->gcash_amount) - $reversed) {
                throw ValidationException::withMessages(['amount' => 'Refund exceeds the original unreversed payment.']);
            }
        }
        if ($kind === 'expense_reversal') {
            abort_if($source->reversal_of_id || Expense::where('reversal_of_id', $source->id)->exists(), 409, 'This expense has already been reversed.');
        }
        if ($kind === 'expense_review') {
            abort_unless($source->status === 'pending_review', 409);
        }
        if ($kind === 'stock_recount') {
            $ids = array_keys(array_filter($data['counts'], fn ($count): bool => $count !== null));
            if ($ids === [] || PosItem::notRetired()->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['counts' => 'Recount includes missing or retired items.']);
            }
        }
        if (in_array($kind, ['cash_drop', 'float_addition', 'bank_deposit'], true)) {
            abort_unless($source->isOpen() && ! $source->is_frozen, 409);
        }
        if ($kind === 'handover_variance') {
            abort_unless(config('sedona.shift_handover_enabled'), 409, 'Shift handover is temporarily disabled.');
            abort_unless($source->handover_status === 'disputed', 409);
        }
        if ($kind === 'shift_variance') {
            abort_unless($source->closed_at && BillingService::cents($source->cash_variance) !== 0, 409, 'Variance review requires a closed drawer with a nonzero outgoing variance.');
        }
    }

    public function cancel(Request $request, ApprovalRequest $approval, string $reason): void
    {
        abort_unless($approval->requested_by === $request->user()->id || $request->user()->can('review-financial'), 403);
        DB::transaction(function () use ($request, $approval, $reason): void {
            $approval = ApprovalRequest::whereKey($approval->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($approval->status, ['pending', 'approved'], true) && $approval->kind !== 'expense_review', 409, 'Executed actions and actual paid expenses cannot be cancelled.');
            $approval->update(['status' => 'cancelled']);
            $this->audit->record($request, 'approval.cancelled', $approval, ['reason' => $reason]);
        });
    }

    public function invoiceTaxApproval(Folio $folio): ApprovalRequest
    {
        $approval = ApprovalRequest::where('kind', 'invoice_tax_treatment')->where('source_id', $folio->id)->where('status', 'executed')->latest('id')->lockForUpdate()->first();
        if (! $approval) {
            throw ValidationException::withMessages(['configuration' => 'Request and independently approve the statutory invoice taxable amount first.']);
        }
        $this->requireCurrent($approval, $folio);

        return $approval;
    }

    public function hasCurrentVarianceReview(Shift $shift): bool
    {
        return ApprovalRequest::where('kind', 'shift_variance')
            ->where('source_type', Shift::class)->where('source_id', $shift->id)
            ->where('status', 'executed')->whereColumn('reviewed_by', '!=', 'requested_by')
            ->where('source_hash', $this->fingerprint($shift, 'shift_variance', []))->exists();
    }

    private function requireCurrent(ApprovalRequest $approval, Model $source): void
    {
        $this->checkSource($approval->kind, $source, $approval->payload);
        if (! hash_equals($approval->source_hash, $this->fingerprint($source, $approval->kind, $approval->payload))) {
            throw ValidationException::withMessages(['approval' => 'Source details changed. Reject this request and create a new one.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function fingerprint(Model $source, string $kind, array $data): string
    {
        if ($kind === 'shift_variance') {
            return hash('sha256', json_encode($source->only(['closed_at', 'cash_total', 'expected_cash', 'cash_variance']), JSON_THROW_ON_ERROR));
        }
        if ($kind === 'invoice_tax_treatment') {
            return hash('sha256', json_encode($source->only(['status', 'gross_total', 'discount_amount', 'discount_type', 'checked_out_at']), JSON_THROW_ON_ERROR));
        }
        $state = match (true) {
            $source instanceof Folio => $source->only(['room_id', 'status', 'room_charge', 'checked_in_at', 'discount_type', 'discount_id_ref', 'discount_schedule', 'discount_override_amount', 'security_deposit', 'deposit_refunded_amount', 'deposit_status', 'pos_total', 'extra_hours', 'surcharge_total']),
            $source instanceof Expense => $source->only(['amount', 'status', 'payment_source', 'reversal_of_id']),
            $source instanceof Shift => $source->only(['closed_at', 'is_frozen', 'opened_by', 'handover_status', 'accepted_cash', 'incoming_user_id', 'cash_total', 'cash_variance']),
            $source instanceof PaymentEntry => $source->toArray(),
            default => $source->only(['stock_quantity', 'retired_at']),
        };
        if ($kind === 'stock_recount') {
            $state = PosItem::whereIn('id', array_keys($data['counts']))->orderBy('id')->lockForUpdate()->get(['id', 'stock_quantity', 'retired_at'])->toArray();
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
