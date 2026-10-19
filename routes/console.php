<?php

use App\Services\TransactionResetService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pms:clean-trial', function (): int {
    $this->error('Trial resets are disabled in v3 to protect financial and audit history. Use a separate disposable test database.');

    return 1;
})->purpose('Protect financial history from trial resets');

Artisan::command('pms:reset-transactions {--force : Confirm deletion of all transaction history} {--backup= : Full SQLite backup destination}', function (TransactionResetService $reset): int {
    if (! $this->option('force')) {
        $this->error('This deletes guests, stays, orders, payments, shifts, reports and transaction history. Use --force to confirm. Accounts, menu, stock quantities, settings and receipt sequences are preserved.');

        return 1;
    }
    if (! app()->isDownForMaintenance()) {
        $this->error('Run php artisan down first, then retry. No data was changed.');

        return 1;
    }

    $backupPath = $this->option('backup') ?: storage_path('app/private/database-backups/before-reset-'.now()->format('Ymd-His').'-'.Str::uuid().'.sqlite');
    try {
        $deleted = $reset->reset($backupPath);
    } catch (Throwable $exception) {
        report($exception);
        $this->error('Reset failed: '.$exception->getMessage());

        return 1;
    }

    $this->info('Backup saved to: '.$backupPath);
    $this->info('Cleared '.array_sum($deleted).' rows. Accounts, menu, stock quantities, room rates, settings and receipt sequences were preserved.');
    $this->info('Run php artisan up to reopen the system. Staff will need to log in and open the shared drawer.');

    return 0;
})->purpose('Back up and clear transactions while keeping accounts, catalog, stock and configuration');
