<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\BusinessDay;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Folio;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\Shift;
use App\Models\StatementLine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BusinessDayService
{
    public function __construct(private AuditService $audit) {}

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return json_decode(DB::table('accounting_settings')->where('id', 1)->value('configuration'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function date(?CarbonInterface $at = null): string
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone('Asia/Manila')->subMinutes(390)->toDateString();
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function bounds(string $date): array
    {
        $start = CarbonImmutable::parse($date.' 06:30:00', 'Asia/Manila')->setTimezone(config('app.timezone'));

        return [$start, $start->addDay()];
    }

    public function assertWritable(?string $date = null): BusinessDay
    {
        $date ??= $this->date();
        DB::table('business_days')->insertOrIgnore(['business_date' => $date, 'status' => 'open', 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $day = BusinessDay::whereDate('business_date', $date)->lockForUpdate()->firstOrFail();
        if ($day->status === 'closed') {
            throw ValidationException::withMessages(['business_date' => 'This business day is closed. The owner must reopen it with a reason before changes.']);
        }

        return $day;
    }

    /** @return array<string, mixed> */
    public function snapshot(string $date): array
    {
        [$start, $end] = $this->bounds($date);
        $payments = PaymentEntry::where('posted_at', '>=', $start)->where('posted_at', '<', $end)->orderBy('id')->get();
        $folios = Folio::where('status', 'checked_out')->where('checked_out_at', '>=', $start)->where('checked_out_at', '<', $end)->orderBy('id')->get();
        $orders = Order::where('type', 'walkin_pos')->where('created_at', '>=', $start)->where('created_at', '<', $end)->orderBy('id')->get();
        $expenses = Expense::whereDate('expense_date', $date)->whereIn('status', ['approved', 'pending_review', 'rejected_paid'])->orderBy('id')->get();
        $movements = CashMovement::whereDate('business_date', $date)->orderBy('id')->get();
        $statements = StatementLine::whereDate('business_date', $date)->whereNotIn('id', DB::table('statement_exclusions')->select('statement_line_id'))->orderBy('id')->get();
        $shifts = Shift::where('opened_at', '<', $end)->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>', $start))->orderBy('id')->get();
        $exceptions = [];
        if (! config('sedona.continuous_shift_enabled') && $shifts->whereNull('closed_at')->isNotEmpty()) {
            $exceptions[] = 'An intersecting drawer is still open.';
        }
        if (config('sedona.shift_handover_enabled') && $shifts->contains(fn (Shift $shift): bool => in_array($shift->handover_status, ['pending', 'disputed'], true))) {
            $exceptions[] = 'Drawer handover requires acceptance or variance review.';
        }
        if ($shifts->contains(fn (Shift $shift): bool => BillingService::cents($shift->cash_variance) !== 0 && ! app(ApprovalService::class)->hasCurrentVarianceReview($shift))) {
            $exceptions[] = 'Outgoing cash variance needs independent review.';
        }
        if ($expenses->contains(fn (Expense $expense): bool => $expense->status !== 'approved')) {
            $exceptions[] = 'Paid expenses need independent review; rejected payments need correction.';
        }
        if (ApprovalRequest::whereIn('status', ['pending', 'approved'])->when(! config('sedona.shift_handover_enabled'), fn ($query) => $query->where('kind', '!=', 'handover_variance'))->where('created_at', '<', $end)->exists()) {
            $exceptions[] = 'An approval request is unresolved.';
        }
        foreach ($payments as $entry) {
            if (BillingService::cents($entry->gcash_amount) !== 0 && ! DB::table('statement_matches')->where('payment_entry_id', $entry->id)->exists()) {
                $exceptions[] = 'GCash movements need statement matching.';
            }
        }
        foreach ($statements as $line) {
            if (BillingService::cents(DB::table('statement_matches')->where('statement_line_id', $line->id)->sum('amount')) !== BillingService::cents($line->gross_amount)) {
                $exceptions[] = 'Statement '.$line->id.' has an unmatched amount.';
            }
        }
        if ($movements->contains(fn (CashMovement $movement): bool => $movement->kind === 'bank_deposit' && ! DB::table('statement_matches')->where('cash_movement_id', $movement->id)->exists())) {
            $exceptions[] = 'Bank deposits require statement matching.';
        }
        if ($folios->whereNotIn('id', $payments->whereIn('kind', ['settlement', 'write_off'])->pluck('folio_id'))->isNotEmpty() || $orders->whereNotIn('id', $payments->where('kind', 'pos_sale')->pluck('order_id'))->isNotEmpty()) {
            $exceptions[] = 'Historical sales lack journal provenance and need reconciliation.';
        }
        [$openingKnown, $openingLegacy] = $this->depositBalances($start);
        [$closingKnown, $closingLegacy] = $this->depositBalances($end);
        if ($closingLegacy > 0) {
            $exceptions[] = 'Outstanding legacy deposit provenance needs opening-balance reconciliation.';
        }

        return [
            'business_date' => $date, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(),
            'shift_handover_enabled' => (bool) config('sedona.shift_handover_enabled'),
            'continuous_shift_enabled' => (bool) config('sedona.continuous_shift_enabled'),
            'cash_cents' => BillingService::cents($payments->sum('cash_amount')), 'gcash_cents' => BillingService::cents($payments->sum('gcash_amount')),
            'opening_deposit_cents' => $openingKnown, 'closing_deposit_cents' => $closingKnown, 'opening_legacy_deposit_cents' => $openingLegacy, 'closing_legacy_deposit_cents' => $closingLegacy,
            'payments' => $payments->map(fn (PaymentEntry $entry): array => $entry->toArray() + ['refund_tax_cents' => InvoiceService::refundTaxCents($entry)])->all(), 'folios' => $folios->map(fn (Folio $f): array => $f->toArray() + ['deposit_applied' => app(BillingService::class)->calculate($f)['depositApplied']])->all(), 'orders' => $orders->toArray(),
            'expenses' => $expenses->toArray(), 'movements' => $movements->toArray(), 'statements' => $statements->toArray(), 'shifts' => $shifts->toArray(), 'handovers' => Shift::where('accepted_at', '>=', $start)->where('accepted_at', '<', $end)->orderBy('id')->get()->toArray(),
            'invoices' => Invoice::whereDate('business_date', $date)->orderBy('id')->get()->toArray(), 'exceptions' => array_values(array_unique($exceptions)),
        ];
    }

    /** @return array{int, int} */
    private function depositBalances(CarbonInterface $at): array
    {
        $known = 0;
        $legacy = 0;
        foreach (Folio::where('security_deposit', '>', 0)->where('checked_in_at', '<', $at)->get() as $folio) {
            $collections = $folio->paymentEntries()->where('kind', 'deposit')->get();
            $allRecorded = $collections->sum(fn (PaymentEntry $entry): int => BillingService::cents($entry->cash_amount) + BillingService::cents($entry->gcash_amount));
            $recorded = $collections->filter(fn (PaymentEntry $entry): bool => $entry->posted_at->lessThan($at))->sum(fn (PaymentEntry $entry): int => BillingService::cents($entry->cash_amount) + BillingService::cents($entry->gcash_amount));
            $initialLegacy = max(0, BillingService::cents($folio->security_deposit) - $allRecorded);
            $applied = $folio->checked_out_at && $folio->checked_out_at->lessThan($at) ? BillingService::cents(app(BillingService::class)->calculate($folio)['depositApplied']) : 0;
            $refunds = $folio->paymentEntries()->where('kind', 'deposit_refund')->where('posted_at', '<', $at)->get();
            $refundTotal = $refunds->sum(fn (PaymentEntry $entry): int => -BillingService::cents($entry->cash_amount) - BillingService::cents($entry->gcash_amount));
            if ($folio->deposit_refunded_at && $folio->deposit_refunded_at->lessThan($at)) {
                $refundTotal = max($refundTotal, BillingService::cents($folio->deposit_refunded_amount));
            }
            $knownRefunds = BillingService::cents(DB::table('payment_refund_allocations')->whereIn('refund_entry_id', $refunds->modelKeys())->sum('amount'));
            $known += max(0, $recorded - max(0, $applied - $initialLegacy) - $knownRefunds);
            $legacy += max(0, $initialLegacy - min($applied, $initialLegacy) - max(0, $refundTotal - $knownRefunds));
        }

        return [$known, $legacy];
    }

    public function close(Request $request, string $date, string $reason): BusinessDay
    {
        Gate::authorize('review-financial');

        return DB::transaction(function () use ($request, $date, $reason): BusinessDay {
            $day = $this->assertWritable($date);
            [, $end] = $this->bounds($date);
            if (now()->lessThan($end)) {
                throw ValidationException::withMessages(['business_date' => 'Wait until the 6:30 AM cutoff has passed.']);
            }
            $snapshot = $this->snapshot($date);
            if ($snapshot['exceptions'] !== []) {
                throw ValidationException::withMessages(['business_date' => implode(' ', $snapshot['exceptions'])]);
            }
            $version = $day->version + 1;
            DB::table('business_day_snapshots')->insert(['business_day_id' => $day->id, 'version' => $version, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'user_id' => $request->user()->id, 'reason' => $reason, 'created_at' => now()]);
            $day->update(['status' => 'closed', 'version' => $version, 'snapshot' => $snapshot, 'closed_by' => $request->user()->id, 'closed_at' => now()]);
            $this->audit->record($request, 'business_day.closed', $day, ['version' => $version, 'reason' => $reason]);

            return $day;
        });
    }

    public function reopen(Request $request, BusinessDay $day, string $reason): void
    {
        Gate::authorize('manage-accounting-setup');
        DB::transaction(function () use ($request, $day, $reason): void {
            $day = BusinessDay::whereKey($day->id)->lockForUpdate()->firstOrFail();
            abort_unless($day->status === 'closed', 409);
            $day->update(['status' => 'open']);
            $this->audit->record($request, 'business_day.reopened', $day, ['previous_version' => $day->version, 'reason' => $reason]);
        });
    }
}
