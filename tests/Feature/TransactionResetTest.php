<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class TransactionResetTest extends TestCase
{
    use DatabaseTruncation;

    private string $backupPath;

    private Folio $folio;

    /** Rebuild without an outer test transaction so SQLite can create a real backup. */
    protected function beforeTruncatingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo('2026-10-14 12:00:00');
        $this->backupPath = sys_get_temp_dir().'/sedona-reset-test-'.Str::uuid().'.sqlite';
        $this->mock(MaintenanceMode::class)->shouldReceive('active')->andReturn(true);
        $user = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '8')->firstOrFail();
        $room->update(['status' => 'occupied', 'base_rate_3h' => 411]);
        $this->folio = Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Reset guest'])->id,
            'user_id' => $user->id, 'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h',
            'checked_in_at' => now(), 'expected_checkout_at' => now()->addHours(3), 'room_charge' => 411, 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        File::delete($this->backupPath);
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_reset_backs_up_everything_clears_history_and_preserves_master_data_and_numbering(): void
    {
        $this->populateHistory();
        DB::table('document_sequences')->insert(['name' => 'scti:20261014', 'last_number' => 25]);
        PosItem::firstOrFail()->update(['stock_quantity' => 47, 'price' => 161]);
        Room::where('number', '13')->update(['status' => 'maintenance']);
        Room::where('is_staff_quarters', true)->update(['status' => 'occupied']);
        $preserved = [];
        foreach (['users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions',
            'role_has_permissions', 'pos_items', 'discount_rates', 'accounting_settings', 'document_sequences',
            'migrations', 'password_reset_tokens'] as $table) {
            $preserved[$table] = DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all();
        }
        $rooms = DB::table('rooms')->orderBy('id')->get()->map(function (object $room): array {
            $attributes = (array) $room;
            if ($room->status === 'occupied' && ! $room->is_staff_quarters) {
                $attributes['status'] = 'available';
                $attributes['updated_at'] = now()->format('Y-m-d H:i:s');
            }

            return $attributes;
        })->all();

        $this->artisan('pms:reset-transactions', ['--force' => true, '--backup' => $this->backupPath])->assertSuccessful();

        foreach ($preserved as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all(), $table);
        }
        $this->assertSame($rooms, DB::table('rooms')->orderBy('id')->get()->map(fn (object $room): array => (array) $room)->all());
        foreach (['guests', 'folios', 'bookings', 'orders', 'order_items', 'payment_entries',
            'payment_refund_allocations', 'expenses', 'shifts', 'shift_tasks', 'approval_requests',
            'cash_movements', 'business_days', 'business_day_snapshots', 'statement_lines', 'statement_matches',
            'statement_exclusions', 'invoices', 'accounting_exports', 'weekly_reports', 'financial_operations',
            'inventory_events', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'database.transactions_reset']);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame('STAY-20261014-002', Folio::generateTransactionId());
        $this->assertDatabaseHas('document_sequences', ['name' => 'scti:20261014', 'last_number' => 25]);

        $backup = new PDO('sqlite:'.$this->backupPath);
        $this->assertSame('ok', $backup->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame($this->folio->transaction_id, $backup->query('SELECT transaction_id FROM folios')->fetchColumn());
        $this->assertSame(47, $backup->query('SELECT stock_quantity FROM pos_items ORDER BY id LIMIT 1')->fetchColumn());
        $this->assertSame(2, $backup->query('SELECT COUNT(*) FROM payment_entries')->fetchColumn());
        $this->assertSame(2, $backup->query('SELECT COUNT(*) FROM accounting_exports')->fetchColumn());
        $backup = null;
    }

    public function test_reset_without_force_does_not_create_a_backup_or_delete_anything(): void
    {
        $this->artisan('pms:reset-transactions', ['--backup' => $this->backupPath])->assertFailed();

        $this->assertDatabaseHas('folios', ['id' => $this->folio->id, 'status' => 'active']);
        $this->assertFileDoesNotExist($this->backupPath);
    }

    public function test_reset_refuses_to_run_while_the_system_is_open(): void
    {
        $this->mock(MaintenanceMode::class)->shouldReceive('active')->andReturn(false);

        $this->artisan('pms:reset-transactions', ['--force' => true, '--backup' => $this->backupPath])->assertFailed();

        $this->assertDatabaseHas('folios', ['id' => $this->folio->id, 'status' => 'active']);
        $this->assertFileDoesNotExist($this->backupPath);
    }

    public function test_backup_failure_does_not_delete_history_or_overwrite_an_existing_file(): void
    {
        File::put($this->backupPath, 'Keep existing backup');

        $this->artisan('pms:reset-transactions', ['--force' => true, '--backup' => $this->backupPath])->assertFailed();

        $this->assertSame('Keep existing backup', File::get($this->backupPath));
        $this->assertDatabaseHas('folios', ['id' => $this->folio->id, 'status' => 'active']);
    }

    public function test_failure_during_reset_rolls_back_all_deletions_and_room_changes(): void
    {
        DB::unprepared("CREATE TRIGGER reject_reset_audit BEFORE INSERT ON audit_logs WHEN NEW.event = 'database.transactions_reset' BEGIN SELECT RAISE(ABORT, 'Test reset failure'); END");

        $this->artisan('pms:reset-transactions', ['--force' => true, '--backup' => $this->backupPath])->assertFailed();

        $this->assertDatabaseHas('folios', ['id' => $this->folio->id, 'status' => 'active']);
        $this->assertDatabaseHas('guests', ['id' => $this->folio->guest_id, 'name' => 'Reset guest']);
        $this->assertDatabaseHas('rooms', ['id' => $this->folio->room_id, 'status' => 'occupied']);
        $this->assertDatabaseHas('document_sequences', ['name' => 'stay:20261014', 'last_number' => 1]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'database.transactions_reset']);
        $this->assertFileExists($this->backupPath);
    }

    public function test_unsupported_database_driver_is_rejected_before_connecting_or_backing_up(): void
    {
        config(['database.default' => 'mysql']);

        $this->artisan('pms:reset-transactions', ['--force' => true, '--backup' => $this->backupPath])->assertFailed();

        $this->assertFileDoesNotExist($this->backupPath);
        $this->assertDatabaseHas('folios', ['id' => $this->folio->id, 'status' => 'active'], 'sqlite');
        config(['database.default' => 'sqlite']);
    }

    private function populateHistory(): void
    {
        $userId = $this->folio->user_id;
        $shift = DB::table('shifts')->insertGetId(['opened_by' => $userId, 'shift_type' => 'day', 'shift_date' => now()->toDateString(), 'opened_at' => now(), 'closed_at' => now()]);
        DB::table('shifts')->insert(['opened_by' => $userId, 'shift_type' => 'night', 'shift_date' => now()->toDateString(), 'opened_at' => now(), 'handover_from_id' => $shift]);
        DB::table('shift_tasks')->insert(['shift_id' => $shift, 'created_by' => $userId, 'task' => 'Old task']);
        $approval = DB::table('approval_requests')->insertGetId(['kind' => 'cash_drop', 'source_type' => 'shift', 'source_id' => $shift, 'requested_by' => $userId, 'payload' => '{}', 'source_hash' => str_repeat('a', 64), 'status' => 'executed', 'reason' => 'Old request']);
        $cash = DB::table('cash_movements')->insertGetId(['shift_id' => $shift, 'user_id' => $userId, 'approval_request_id' => $approval, 'operation_key' => Str::uuid(), 'kind' => 'cash_drop', 'destination' => 'safe', 'amount' => 10, 'reference' => 'OLD-CASH', 'business_date' => now()->toDateString(), 'posted_at' => now()]);
        $order = DB::table('orders')->insertGetId(['user_id' => $userId, 'folio_id' => $this->folio->id, 'room_id' => $this->folio->room_id, 'shift_id' => $shift]);
        $item = PosItem::firstOrFail();
        DB::table('order_items')->insert(['order_id' => $order, 'pos_item_id' => $item->id, 'item_name' => $item->name, 'unit_price' => 10, 'quantity' => 1, 'subtotal' => 10]);
        $payment = DB::table('payment_entries')->insertGetId(['folio_id' => $this->folio->id, 'order_id' => $order, 'shift_id' => $shift, 'user_id' => $userId, 'kind' => 'deposit_received', 'cash_amount' => 100, 'posted_at' => now(), 'approval_request_id' => $approval]);
        $refund = DB::table('payment_entries')->insertGetId(['folio_id' => $this->folio->id, 'user_id' => $userId, 'kind' => 'deposit_refund', 'cash_amount' => 25, 'posted_at' => now(), 'reversal_of_id' => $payment]);
        DB::table('payment_refund_allocations')->insert(['refund_entry_id' => $refund, 'deposit_entry_id' => $payment, 'amount' => 25]);
        $expense = DB::table('expenses')->insertGetId(['user_id' => $userId, 'shift_id' => $shift, 'approval_request_id' => $approval, 'voucher_number' => 'OLD-EXP-1', 'expense_date' => now()->toDateString(), 'category' => 'other', 'description' => 'Old expense', 'amount' => 10]);
        DB::table('expenses')->insert(['user_id' => $userId, 'voucher_number' => 'OLD-EXP-2', 'expense_date' => now()->toDateString(), 'category' => 'other', 'description' => 'Old reversal', 'amount' => -10, 'reversal_of_id' => $expense]);
        $line = DB::table('statement_lines')->insertGetId(['provider' => 'gcash', 'import_hash' => str_repeat('b', 64), 'fingerprint' => str_repeat('c', 64), 'reference' => 'OLD-GCASH', 'posted_at' => now(), 'business_date' => now()->toDateString(), 'gross_amount' => 100, 'net_amount' => 100, 'user_id' => $userId]);
        DB::table('statement_matches')->insert(['statement_line_id' => $line, 'payment_entry_id' => $payment, 'cash_movement_id' => $cash, 'amount' => 100, 'user_id' => $userId, 'created_at' => now()]);
        DB::table('statement_exclusions')->insert(['statement_line_id' => $line, 'approval_request_id' => $approval, 'user_id' => $userId, 'reason' => 'Old exclusion', 'created_at' => now()]);
        $day = DB::table('business_days')->insertGetId(['business_date' => now()->toDateString(), 'status' => 'closed', 'version' => 1]);
        DB::table('business_day_snapshots')->insert(['business_day_id' => $day, 'version' => 1, 'snapshot' => '{}', 'sha256' => str_repeat('d', 64), 'user_id' => $userId, 'reason' => 'Old close', 'created_at' => now()]);
        $export = DB::table('accounting_exports')->insertGetId(['business_day_id' => $day, 'version' => 1, 'destination' => 'CSV', 'lines' => '[]', 'configuration' => '{}', 'sha256' => str_repeat('e', 64), 'created_by' => $userId]);
        DB::table('accounting_exports')->insert(['business_day_id' => $day, 'version' => 2, 'destination' => 'CSV', 'lines' => '[]', 'configuration' => '{}', 'sha256' => str_repeat('f', 64), 'created_by' => $userId, 'correction_of_id' => $export]);
        DB::table('invoices')->insert(['folio_id' => $this->folio->id, 'order_id' => $order, 'number' => 'OLD-INV-1', 'business_date' => now()->toDateString(), 'document' => '{}', 'user_id' => $userId]);
        DB::table('accounting_settings')->where('id', 1)->update(['configuration' => json_encode(['invoice_enabled' => true, 'invoice_series' => 'KEEP', 'next_sequence' => 25])]);
        DB::table('weekly_reports')->insert(['created_by' => $userId, 'week_start' => now()->startOfWeek()->toDateString(), 'week_end' => now()->endOfWeek()->toDateString()]);
        DB::table('bookings')->insert(['room_id' => $this->folio->room_id, 'user_id' => $userId, 'guest_name' => 'Future booking', 'rate_tier' => '3h', 'arrival_at' => now()->addDay(), 'departure_at' => now()->addDay()->addHours(3)]);
        DB::table('financial_operations')->insert(['operation_key' => Str::uuid(), 'user_id' => $userId, 'kind' => 'folio.checkin', 'source_id' => $this->folio->id, 'request_hash' => str_repeat('a', 64), 'created_at' => now()]);
        DB::table('inventory_events')->insert(['pos_item_id' => $item->id, 'item_name' => $item->name, 'event_type' => 'sold', 'quantity_change' => -3, 'balance_after' => 47, 'user_id' => $userId, 'reference_id' => (string) $order, 'created_at' => now()]);
        DB::table('audit_logs')->insert(['user_id' => $userId, 'event' => 'folio.checked_in', 'auditable_id' => $this->folio->id]);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $userId, 'payload' => '', 'last_activity' => now()->timestamp]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        DB::table('job_batches')->insert(['id' => 'old-batch', 'name' => 'Old batch', 'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'created_at' => now()->timestamp]);
        DB::table('failed_jobs')->insert(['uuid' => Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Old failure']);
        DB::table('cache')->insert(['key' => 'old-folio', 'value' => '1', 'expiration' => now()->addHour()->timestamp]);
        DB::table('cache_locks')->insert(['key' => 'old-lock', 'owner' => 'old-owner', 'expiration' => now()->addHour()->timestamp]);
        DB::table('password_reset_tokens')->insert(['email' => User::findOrFail($userId)->email, 'token' => 'Keep token', 'created_at' => now()]);
    }
}
