<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::SetUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_owner_sees_board()
    {
        $owner = User::where('email', 'owner@sedonapms.com')->first();
        $resp = $this->actingAs($owner)->get(route('dashboard'));
        $resp->assertStatus(200);
        $resp->assertSee('Frontdesk Apartment Occupancy Grid', false);
        $resp->assertSee('Occupancy Graph', false);
        $resp->assertSee('Status Filters', false);
        $resp->assertSee('VIP room', false);
        $resp->assertSee('Reports &amp; Audits', false);
        $resp->assertSee('System Settings', false);
    }

    public function test_admin_sees_board_cashier_keeps_tables()
    {
        $admin = User::where('email', 'admin@sedonapms.com')->first();
        $this->actingAs($admin)->get(route('dashboard'))->assertSee('Frontdesk Apartment Occupancy Grid', false);

        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $resp = $this->actingAs($cashier)->get(route('dashboard'));
        $resp->assertSee('AVAILABLE ROOMS', false);
        $resp->assertSee('OCCUPIED ROOMS', false);
    }

    public function test_board_filters()
    {
        $owner = User::where('email', 'owner@sedonapms.com')->first();
        $this->actingAs($owner)->get(route('dashboard', ['bstatus' => 'available']))->assertStatus(200);
        $this->actingAs($owner)->get(route('dashboard', ['bstatus' => 'late']))->assertStatus(200);
        $this->actingAs($owner)->get(route('dashboard', ['btier' => 'vip']))->assertStatus(200);
    }
}
