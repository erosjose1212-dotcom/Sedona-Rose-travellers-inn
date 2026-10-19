<?php

namespace App\Services;

use App\Models\AccountingExport;
use App\Models\BusinessDay;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccountingExportService
{
    public const ACCOUNTS = ['cash', 'gcash_clearing', 'guest_receivable', 'deposits', 'room_revenue', 'dining_revenue', 'overtime_revenue', 'surcharge_revenue', 'discounts', 'write_offs', 'sales_returns', 'expenses', 'petty_cash', 'bank', 'safe', 'bank_clearing', 'fees', 'owner_funds', 'vat_payable', 'revenue_tax_adjustment', 'cash_variance'];

    public function __construct(private BusinessDayService $days, private AuditService $audit) {}

    public function create(Request $request, BusinessDay $day): AccountingExport
    {
        Gate::authorize('review-financial');

        return DB::transaction(function () use ($request, $day): AccountingExport {
            $day = BusinessDay::whereKey($day->id)->lockForUpdate()->firstOrFail();
            abort_unless($day->status === 'closed' && $day->snapshot !== null, 409, 'Close and reconcile the business day first.');
            $settings = $this->days->settings();
            $existing = AccountingExport::where('business_day_id', $day->id)->where('version', $day->version)->where('destination', $settings['destination'])->first();
            if ($existing) {
                return $existing;
            }
            if (! ($settings['export_enabled'] ?? false) || empty($settings['opening_balance_reference']) || empty($settings['approval_reference']) || ($settings['tax_mode'] ?? 'unconfigured') === 'unconfigured') {
                throw ValidationException::withMessages(['export' => 'Approve accounting policy, opening balances, and account mappings before export.']);
            }
            foreach (self::ACCOUNTS as $account) {
                if (empty($settings['account_mappings'][$account])) {
                    throw ValidationException::withMessages(['export' => 'Account mapping is missing: '.$account]);
                }
            }
            if ($settings['tax_mode'] === 'vat_inclusive' && (count($day->snapshot['invoices']) < count($day->snapshot['orders']) + count(array_filter($day->snapshot['folios'], fn (array $folio): bool => ! $folio['force_checkout'])))) {
                throw ValidationException::withMessages(['export' => 'VAT export requires configured invoices for each paid sale. Reopen the day to issue missing invoices.']);
            }
            $lines = $this->lines($day->snapshot, $settings['account_mappings']);
            $prior = AccountingExport::where('business_day_id', $day->id)->where('status', 'delivered')->where('destination', $settings['destination'])->where('version', '<', $day->version)->latest('version')->first();
            if ($prior) {
                $previousSnapshot = DB::table('business_day_snapshots')->where('business_day_id', $day->id)->where('version', $prior->version)->value('snapshot');
                $previous = $this->lines(json_decode($previousSnapshot, true, 512, JSON_THROW_ON_ERROR), $prior->configuration['account_mappings']);
                $lines = array_merge(array_map(fn (array $line): array => ['source' => 'reverse:'.$prior->id.':'.$line['source'], 'account' => $line['account'], 'debit_cents' => $line['credit_cents'], 'credit_cents' => $line['debit_cents']], $previous), $lines);
            }
            if (array_sum(array_column($lines, 'debit_cents')) !== array_sum(array_column($lines, 'credit_cents'))) {
                throw ValidationException::withMessages(['export' => 'The accounting batch does not balance. Review source records.']);
            }
            $batch = AccountingExport::create(['business_day_id' => $day->id, 'version' => $day->version, 'destination' => $settings['destination'], 'status' => 'draft', 'lines' => $lines, 'configuration' => $settings, 'sha256' => hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR)), 'created_by' => $request->user()->id, 'correction_of_id' => $prior?->id]);
            $this->audit->record($request, 'export.prepared', $batch, ['day_version' => $day->version, 'sha256' => $batch->sha256, 'correction_of_id' => $prior?->id]);

            return $batch;
        });
    }

    /** @param array<string, mixed> $snapshot @param array<string, string> $accounts @return list<array{source: string, account: string, debit_cents: int, credit_cents: int}> */
    private function lines(array $snapshot, array $accounts): array
    {
        $lines = [];
        $pair = function (string $source, string $debit, string $credit, int $amount) use (&$lines, $accounts): void {
            if ($amount < 0) {
                [$debit, $credit] = [$credit, $debit];
                $amount = -$amount;
            }
            if ($amount === 0) {
                return;
            }
            $lines[] = ['source' => $source, 'account' => $accounts[$debit], 'debit_cents' => $amount, 'credit_cents' => 0];
            $lines[] = ['source' => $source, 'account' => $accounts[$credit], 'debit_cents' => 0, 'credit_cents' => $amount];
        };
        foreach ($snapshot['folios'] as $folio) {
            $source = 'folio:'.$folio['id'];
            $overtime = (($folio['extra_hours'] ?? 0) + ($folio['overtime_hours'] ?? 0)) * 13000;
            foreach (['room_revenue' => BillingService::cents($folio['room_charge']), 'overtime_revenue' => $overtime, 'surcharge_revenue' => BillingService::cents($folio['surcharge_total']) - $overtime, 'dining_revenue' => BillingService::cents($folio['pos_total'])] as $account => $amount) {
                $pair($source, 'guest_receivable', $account, $amount);
            }
            $pair($source, 'discounts', 'guest_receivable', BillingService::cents($folio['discount_amount']));
            $pair($source, 'deposits', 'guest_receivable', BillingService::cents($folio['deposit_applied']));
            if ($folio['force_checkout'] && $folio['force_by']) {
                $pair($source, 'write_offs', 'guest_receivable', BillingService::cents($folio['net_total']));
            }
        }
        foreach ($snapshot['payments'] as $payment) {
            $contra = match ($payment['kind']) {
                'deposit', 'deposit_refund' => 'deposits', 'pos_sale' => 'dining_revenue', 'pos_refund' => 'sales_returns', 'settlement' => 'guest_receivable', 'write_off' => null, default => throw ValidationException::withMessages(['export' => 'Unsupported journal kind.'])
            };
            if ($contra === null) {
                continue;
            }
            $pair('payment:'.$payment['id'], 'cash', $contra, BillingService::cents($payment['cash_amount']));
            $pair('payment:'.$payment['id'], 'gcash_clearing', $contra, BillingService::cents($payment['gcash_amount']));
            $pair('payment:'.$payment['id'], 'vat_payable', 'sales_returns', $payment['refund_tax_cents'] ?? 0);
        }
        foreach ($snapshot['expenses'] as $expense) {
            $credit = match ($expense['payment_source']) {
                'cash_drawer' => 'cash', 'petty_cash' => 'petty_cash', 'bank_transfer' => 'bank'
            };
            $pair('expense:'.$expense['id'], 'expenses', $credit, BillingService::cents($expense['amount']));
        }
        foreach ($snapshot['movements'] as $movement) {
            $contra = $movement['kind'] === 'bank_deposit' ? 'bank_clearing' : $movement['destination'];
            $pair('movement:'.$movement['id'], 'cash', $contra, BillingService::cents($movement['amount']));
        }
        foreach ($snapshot['statements'] as $statement) {
            $source = 'statement:'.$statement['id'];
            if ($statement['provider'] === 'bank') {
                $pair($source, 'bank', 'bank_clearing', BillingService::cents($statement['net_amount']));
                $pair($source, 'fees', 'bank_clearing', BillingService::cents($statement['fee_amount']));
            } else {
                $pair($source, 'fees', 'gcash_clearing', BillingService::cents($statement['fee_amount']));
            }
        }
        foreach ($snapshot['shifts'] as $shift) {
            if ($shift['closed_at'] && Carbon::parse($shift['closed_at'])->greaterThan(Carbon::parse($snapshot['starts_at'])) && Carbon::parse($shift['closed_at'])->lessThanOrEqualTo(Carbon::parse($snapshot['ends_at']))) {
                $pair('shift:'.$shift['id'], 'cash', 'cash_variance', BillingService::cents($shift['cash_variance']));
            }
        }
        foreach ($snapshot['handovers'] ?? [] as $handover) {
            $pair('handover:'.$handover['id'], 'cash', 'cash_variance', BillingService::cents($handover['handover_variance']));
        }
        foreach ($snapshot['invoices'] as $invoice) {
            $pair('invoice:'.$invoice['id'], 'revenue_tax_adjustment', 'vat_payable', $invoice['document']['tax_cents']);
        }

        return $lines;
    }

    public function review(Request $request, AccountingExport $batch): void
    {
        Gate::authorize('review-financial');
        DB::transaction(function () use ($request, $batch): void {
            $batch = AccountingExport::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_if($batch->created_by === $request->user()->id, 403, 'A different export reviewer is required.');
            abort_unless($batch->status === 'draft', 409);
            $this->requireCurrent($batch);
            $batch->update(['status' => 'reviewed', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $this->audit->record($request, 'export.reviewed', $batch, ['sha256' => $batch->sha256]);
        });
    }

    public function deliver(Request $request, AccountingExport $batch, string $reference): void
    {
        Gate::authorize('review-financial');
        DB::transaction(function () use ($request, $batch, $reference): void {
            $batch = AccountingExport::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_unless($batch->status === 'reviewed', 409);
            $this->requireCurrent($batch);
            $batch->update(['status' => 'delivered', 'delivered_at' => now(), 'delivery_reference' => $reference]);
            $this->audit->record($request, 'export.delivery_recorded', $batch, ['reference' => $reference, 'mode' => 'manual_csv']);
        });
    }

    public function requireCurrent(AccountingExport $batch): void
    {
        $day = BusinessDay::findOrFail($batch->business_day_id);
        abort_unless($day->status === 'closed' && $day->version === $batch->version, 409, 'This export was superseded by a reopened day.');
        abort_unless(hash_equals($batch->sha256, hash('sha256', json_encode($batch->lines, JSON_THROW_ON_ERROR))), 409, 'Export content failed its integrity check.');
    }

    public function csv(AccountingExport $batch): string
    {
        $this->requireCurrent($batch);
        abort_unless(in_array($batch->status, ['reviewed', 'delivered'], true), 409, 'Independent review is required before download.');
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['external_key', 'business_date', 'source', 'account', 'debit', 'credit'], ',', '"', '');
        $day = BusinessDay::findOrFail($batch->business_day_id);
        foreach ($batch->lines as $index => $line) {
            $account = preg_match('/^[=+@\-\t\r]/', $line['account']) ? "'".$line['account'] : $line['account'];
            fputcsv($handle, ['sedona:'.$day->business_date->toDateString().':v'.$batch->version.':'.$batch->id.':'.$index, $day->business_date->toDateString(), $line['source'], $account, number_format($line['debit_cents'] / 100, 2, '.', ''), number_format($line['credit_cents'] / 100, 2, '.', '')], ',', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
