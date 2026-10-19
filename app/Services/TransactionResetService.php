<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class TransactionResetService
{
    /** @return array<string, int> Deleted row counts by table. */
    public function reset(string $backupPath): array
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            throw new DomainException('This reset supports SQLite databases only. No data was changed.');
        }
        if ($connection->transactionLevel() !== 0) {
            throw new DomainException('The reset cannot run inside another transaction.');
        }
        if (File::exists($backupPath)) {
            throw new DomainException('The backup destination already exists. Choose a new backup filename.');
        }

        File::ensureDirectoryExists(dirname($backupPath), 0700);
        $connection->statement('VACUUM main INTO ?', [$backupPath]);
        if (! File::isFile($backupPath) || File::size($backupPath) === 0) {
            throw new DomainException('The database backup could not be created. No data was changed.');
        }

        return $connection->transaction(function () use ($connection, $backupPath): array {
            foreach (['expenses' => 'reversal_of_id', 'payment_entries' => 'reversal_of_id',
                'shifts' => 'handover_from_id', 'accounting_exports' => 'correction_of_id'] as $table => $column) {
                $connection->table($table)->whereNotNull($column)->update([$column => null]);
            }

            $deleted = [];
            foreach (['statement_matches', 'statement_exclusions', 'statement_lines', 'payment_refund_allocations',
                'invoices', 'accounting_exports', 'business_day_snapshots', 'cash_movements',
                'payment_entries', 'expenses', 'shift_tasks', 'order_items', 'orders', 'folios',
                'bookings', 'guests', 'financial_operations', 'approval_requests', 'shifts',
                'business_days', 'weekly_reports', 'inventory_events', 'audit_logs',
                'sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks'] as $table) {
                $deleted[$table] = $connection->table($table)->delete();
            }

            $connection->table('rooms')->where('status', 'occupied')->where('is_staff_quarters', false)
                ->update(['status' => 'available', 'updated_at' => now()]);

            $connection->table('audit_logs')->insert([
                'event' => 'database.transactions_reset',
                'new_values' => json_encode(['backup' => $backupPath, 'deleted' => $deleted,
                    'receipt_sequences_preserved' => true], JSON_THROW_ON_ERROR),
                'user_agent' => 'Artisan CLI', 'created_at' => now(),
            ]);

            return $deleted;
        });
    }
}
