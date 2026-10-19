<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\PaymentEntry;
use App\Models\StatementLine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReconciliationService
{
    public function __construct(private BusinessDayService $days, private AuditService $audit, private FinancialPostingService $posting) {}

    public function import(Request $request, string $provider, string $csv): int
    {
        Gate::authorize('review-financial');
        abort_unless(in_array($provider, ['gcash', 'bank'], true), 422);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (is_array($header) && isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        if ($header !== ['reference', 'posted_at', 'gross_amount', 'fee_amount', 'net_amount']) {
            fclose($handle);
            throw ValidationException::withMessages(['statement' => 'Use the provided CSV template and column order.']);
        }
        $rows = [];
        try {
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== 5 || count($rows) >= 2000) {
                    throw ValidationException::withMessages(['statement' => 'Invalid row or more than 2,000 rows.']);
                }
                $data = Validator::make(array_combine($header, $row), ['reference' => 'required|string|max:100', 'posted_at' => 'required|date', 'gross_amount' => 'required|numeric|between:-50000000,50000000|decimal:0,2', 'fee_amount' => 'required|numeric|between:0,500000|decimal:0,2', 'net_amount' => 'required|numeric|between:-50000000,50000000|decimal:0,2'])->validate();
                $data['reference'] = trim($data['reference']);
                if ($data['reference'] === '' || BillingService::cents($data['gross_amount']) === 0 || BillingService::cents($data['gross_amount']) - BillingService::cents($data['fee_amount']) !== BillingService::cents($data['net_amount'])) {
                    throw ValidationException::withMessages(['statement' => 'Each row must have a reference, nonzero gross, and net equal to gross less fees.']);
                }
                $data['posted_at'] = Carbon::parse($data['posted_at'], 'Asia/Manila')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
                foreach (['gross_amount', 'fee_amount', 'net_amount'] as $field) {
                    $data[$field] = number_format(BillingService::cents($data[$field]) / 100, 2, '.', '');
                }
                $data['provider'] = $provider;
                $data['fingerprint'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
                $data['business_date'] = $this->days->date(Carbon::parse($data['posted_at']));
                $rows[] = $data;
            }
        } finally {
            fclose($handle);
        }

        return DB::transaction(function () use ($request, $rows, $csv): int {
            $count = 0;
            foreach ($rows as $row) {
                if (StatementLine::where('fingerprint', $row['fingerprint'])->exists()) {
                    continue;
                }
                $this->days->assertWritable($row['business_date']);
                $line = StatementLine::create($row + ['import_hash' => hash('sha256', $csv), 'user_id' => $request->user()->id]);
                $this->audit->record($request, 'statement.imported', $line, $row);
                $count++;
            }

            return $count;
        });
    }

    /** @param list<int> $ids */
    public function match(Request $request, StatementLine $line, array $ids): void
    {
        Gate::authorize('review-financial');
        sort($ids);
        $this->posting->execute($request, 'statement.match', $line->id, ['ids' => $ids], function () use ($request, $line, $ids): array {
            $line = StatementLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('statement_exclusions')->where('statement_line_id', $line->id)->exists(), 409, 'This import was excluded after review.');
            $this->days->assertWritable($line->business_date->toDateString());
            if (DB::table('statement_matches')->where('statement_line_id', $line->id)->exists()) {
                throw ValidationException::withMessages(['statement' => 'This statement is already matched.']);
            }
            $records = $line->provider === 'gcash' ? PaymentEntry::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() : CashMovement::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($records->count() !== count($ids) || $ids === []) {
                throw ValidationException::withMessages(['entries' => 'Select valid, distinct source movements.']);
            }
            $total = 0;
            foreach ($records as $record) {
                abort_if($record->user_id === $request->user()->id, 403, 'A different reviewer must match this payment.');
                $column = $line->provider === 'gcash' ? 'payment_entry_id' : 'cash_movement_id';
                if (DB::table('statement_matches')->where($column, $record->id)->exists()) {
                    throw ValidationException::withMessages(['entries' => 'A source movement is already matched.']);
                }
                $amount = $line->provider === 'gcash' ? BillingService::cents($record->gcash_amount) : -BillingService::cents($record->amount);
                $reference = $line->provider === 'gcash' ? $record->gcash_reference : $record->reference;
                if ($reference !== $line->reference || $amount === 0 || ($amount > 0) !== (BillingService::cents($line->gross_amount) > 0) || ($line->provider === 'bank' && $record->kind !== 'bank_deposit')) {
                    throw ValidationException::withMessages(['entries' => 'Provider, reference, and movement direction must match.']);
                }
                $this->days->assertWritable($record->business_date ? Carbon::parse($record->business_date)->toDateString() : $this->days->date($record->posted_at));
                DB::table('statement_matches')->insert(['statement_line_id' => $line->id, $column => $record->id, 'amount' => $amount / 100, 'user_id' => $request->user()->id, 'created_at' => now()]);
                $total += $amount;
            }
            if ($total !== BillingService::cents($line->gross_amount)) {
                throw ValidationException::withMessages(['entries' => 'Selected amounts must exactly equal the statement gross.']);
            }
            $this->audit->record($request, 'statement.matched', $line, ['source_ids' => $ids, 'gross_cents' => $total, 'fee_amount' => $line->fee_amount]);

            return ['statement_id' => $line->id];
        });
    }
}
