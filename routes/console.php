<?php

use App\Services\ShiftCalendar;
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

Artisan::command('pms:schedule-shift-cutover {date : Future Manila date in YYYY-MM-DD format} {--time=05:00 : Manila start time, 05:00 or 06:01}', function (): int {
    $date = $this->argument('date');
    $time = (string) $this->option('time');
    try {
        ShiftCalendar::scheduleCutover($date, $time);
    } catch (InvalidArgumentException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $this->info('Reporting cutover scheduled for '.$date.' at '.($time === '06:01' ? '6:01 AM' : '5:00 AM').' Manila time.');

    return 0;
})->purpose('Save the one-time Manila reporting schedule cutover');
