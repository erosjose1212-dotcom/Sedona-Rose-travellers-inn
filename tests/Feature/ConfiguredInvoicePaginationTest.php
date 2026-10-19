<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConfiguredInvoicePaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_walk_in_invoice_pages_preserve_the_review_filter_and_have_independent_counters(): void
    {
        $owner = $this->actor('owner');
        $room = Room::create(['number' => '6', 'name' => 'Classic', 'type' => 'Classic Room', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['name' => 'Invoice pagination guest', 'headcount' => 1]);
        for ($index = 1; $index <= 21; $index++) {
            Folio::create(['room_id' => $room->id, 'guest_id' => $guest->id, 'user_id' => $owner->id, 'transaction_id' => 'SCTI-INVOICE-'.$index,
                'rate_tier' => '3h', 'checked_in_at' => now()->subHours(3), 'expected_checkout_at' => now(), 'checked_out_at' => now(), 'status' => 'checked_out', 'force_checkout' => false]);
            $this->sale($owner, $index);
        }
        ApprovalRequest::factory()->count(21)->create(['requested_by' => $owner->id, 'status' => 'approved']);

        $this->actingAs($owner)->get(route('approvals.index', ['status' => 'approved']))->assertOk()
            ->assertViewHas('invoiceFolios', fn ($folios): bool => $folios->total() === 21 && $folios->count() === 10)
            ->assertViewHas('invoiceSales', fn ($sales): bool => $sales->total() === 21 && $sales->count() === 10)
            ->assertSee('SCTI-INVOICE-21')->assertDontSee('SCTI-INVOICE-1</span>', false);
        $this->get(route('approvals.index', ['status' => 'approved', 'page' => 2, 'invoice_folio_page' => 2, 'invoice_sale_page' => 3]))->assertOk()
            ->assertViewHas('requests', fn ($requests): bool => $requests->currentPage() === 2 && $requests->count() === 1)
            ->assertViewHas('invoiceFolios', fn ($folios): bool => $folios->currentPage() === 2 && $folios->count() === 10 && $folios->first()->transaction_id === 'SCTI-INVOICE-11')
            ->assertViewHas('invoiceSales', fn ($sales): bool => $sales->currentPage() === 3 && $sales->count() === 1)
            ->assertSee('invoice_folio_page=3#configuredInvoices', false)->assertSee('invoice_sale_page=2#configuredInvoices', false)
            ->assertSee('id="configuredInvoices" class="hms-card"  open', false);
    }

    public function test_cashier_invoice_sales_do_not_include_other_operators(): void
    {
        $cashier = $this->actor('cashier');
        $other = $this->actor('owner');
        $this->sale($cashier, 1);
        $this->sale($other, 2);

        $this->actingAs($cashier)->get(route('approvals.index', ['invoice_sale_page' => 1]))->assertOk()
            ->assertViewHas('invoiceSales', fn ($sales): bool => $sales->total() === 1 && $sales->first()->user_id === $cashier->id)
            ->assertDontSee('POS-PAGE-2');
    }

    private function actor(string $role): User
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate($role, 'web'));
        foreach (['manage_folios', 'record_payments', 'manage_shifts', 'view_all_shifts'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }

    private function sale(User $operator, int $index): void
    {
        $order = Order::create(['transaction_id' => 'POS-PAGE-'.$index, 'user_id' => $operator->id, 'type' => 'walkin_pos', 'status' => 'delivered', 'total' => 50]);
        PaymentEntry::create(['order_id' => $order->id, 'user_id' => $operator->id, 'kind' => 'pos_sale', 'cash_amount' => 50, 'gcash_amount' => 0, 'posted_at' => now()]);
    }
}
