<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\Shift;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialPostingService
{
    public function __construct(private ShiftAccessService $shiftAccess, private AuditService $audit, private BusinessDayService $days) {}

    /**
     * Reserve the key before touching mutable financial or inventory state.
     * The reservation, result, audit, and business changes commit together.
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(): array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function execute(Request $request, string $kind, ?int $sourceId, array $payload, Closure $action): array
    {
        $key = strtolower($request->validate(['operation_key' => 'required|uuid'])['operation_key']);
        $request->merge(['operation_key' => $key]);
        unset($payload['operation_key']);
        ksort($payload);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $key, $kind, $sourceId, $hash, $action): array {
            DB::table('financial_operations')->insertOrIgnore([
                'operation_key' => $key, 'user_id' => $request->user()->id,
                'kind' => $kind, 'source_id' => $sourceId, 'request_hash' => $hash, 'created_at' => now(),
            ]);
            $operation = DB::table('financial_operations')->where('operation_key', $key)->lockForUpdate()->first();
            abort_unless((int) $operation->user_id === (int) $request->user()->id, 403);
            if ($operation->kind !== $kind || $operation->source_id != $sourceId || ! hash_equals($operation->request_hash, $hash)) {
                throw ValidationException::withMessages(['operation_key' => 'This submission was already used with different details. Reload before starting a new transaction.']);
            }
            if ($operation->result !== null) {
                return json_decode($operation->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $action();
            DB::table('financial_operations')->where('operation_key', $key)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR)]);

            return $result;
        }, 3);
    }

    public function post(Request $request, Folio|Order $source, string $kind, int $cashCents, int $gcashCents, ?string $reference = null, ?int $approvalId = null, ?int $reversalId = null): PaymentEntry
    {
        $this->days->assertWritable();
        $shift = $kind === 'write_off'
            ? Shift::whereNull('closed_at')->lockForUpdate()->first()
            : $this->shiftAccess->requireOperatingShift($request->user(), $request->input('shift_override_reason'), $kind);
        if ($kind === 'write_off' && $shift?->is_frozen) {
            throw ValidationException::withMessages(['payment_method' => 'The active shift is frozen.']);
        }
        $reference = trim($reference ?? '');
        if ($gcashCents !== 0 && $reference === '') {
            throw ValidationException::withMessages(['gcash_reference' => 'Record the GCash transaction reference for this money movement.']);
        }
        $entry = PaymentEntry::create([
            'folio_id' => $source instanceof Folio ? $source->id : null,
            'order_id' => $source instanceof Order ? $source->id : null,
            'shift_id' => $shift?->id, 'user_id' => $request->user()->id,
            'operation_key' => $request->input('operation_key'), 'kind' => $kind,
            'cash_amount' => $cashCents / 100, 'gcash_amount' => $gcashCents / 100,
            'gcash_reference' => $gcashCents !== 0 ? $reference : null, 'posted_at' => now(),
            'business_date' => $this->days->date(), 'approval_request_id' => $approvalId, 'reversal_of_id' => $reversalId,
        ]);
        $this->audit->record($request, 'payment.'.$kind, $source, [
            'transaction_id' => $source->transaction_id,
            'receipt_number' => $source instanceof Folio ? $source->receipt_number : null,
            'payment_entry_id' => $entry->id, 'operation_key' => $entry->operation_key,
            'shift_id' => $entry->shift_id, 'cash_amount' => $entry->cash_amount,
            'gcash_amount' => $entry->gcash_amount, 'gcash_reference' => $entry->gcash_reference,
            'provider_status' => $gcashCents !== 0 ? 'recorded_unverified' : null,
        ]);

        return $entry;
    }

    public function allocateRefund(PaymentEntry $refund, Folio $folio, int $amountCents): void
    {
        $deposits = $folio->paymentEntries()->where('kind', 'deposit')->orderBy('id')->lockForUpdate()->get();
        $recordedTotal = $deposits->sum(fn (PaymentEntry $entry): int => BillingService::cents($entry->cash_amount) + BillingService::cents($entry->gcash_amount));
        $legacyDeposit = max(0, BillingService::cents($folio->security_deposit) - $recordedTotal);
        $appliedLeft = max(0, BillingService::cents(app(BillingService::class)->calculate($folio)['depositApplied']) - $legacyDeposit);
        foreach ($deposits as $deposit) {
            $allocated = BillingService::cents(DB::table('payment_refund_allocations')->where('deposit_entry_id', $deposit->id)->sum('amount'));
            $original = BillingService::cents($deposit->cash_amount) + BillingService::cents($deposit->gcash_amount);
            $appliedHere = min($appliedLeft, $original);
            $appliedLeft -= $appliedHere;
            $available = $original - $appliedHere - $allocated;
            $amount = min($amountCents, max(0, $available));
            if ($amount > 0) {
                DB::table('payment_refund_allocations')->insert(['refund_entry_id' => $refund->id, 'deposit_entry_id' => $deposit->id, 'amount' => $amount / 100]);
                $amountCents -= $amount;
            }
            if ($amountCents === 0) {
                break;
            }
        }
    }
}
