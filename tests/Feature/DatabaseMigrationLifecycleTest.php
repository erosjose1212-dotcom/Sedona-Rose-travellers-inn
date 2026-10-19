<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseMigrationLifecycleTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_sqlite_migrations_can_be_rolled_back_and_reapplied_on_a_disposable_database(): void
    {
        $this->artisan('migrate:rollback', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('folios'));
        $this->assertFalse(Schema::hasTable('document_sequences'));

        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('folios'));
        $this->assertDatabaseHas('document_sequences', ['name' => 'scti', 'last_number' => 0]);
        $this->assertDatabaseCount('accounting_settings', 1);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
}
