<?php

namespace Tests\Feature;

use App\Models\PosItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryStockOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[TestWith(['cashier'])]
    #[TestWith(['owner'])]
    public function test_stock_list_matches_the_requested_order_without_changing_catalog_data(string $role): void
    {
        $this->seed(DatabaseSeeder::class);
        $expected = ['Bangsilog', 'Chicksilog', 'Hotsilog', 'Longsilog', 'Porksilog', 'Tapsilog',
            'Buffalo Wings', 'Buttered Chicken', 'Chicharong Bulaklak', 'Calamares', 'French Fries', 'Lechon Kawali',
            'Garlic Chicken', "Tokwa't Baboy", 'Pancit Canton', 'Lomi', 'Sizzling Hotdog', 'Sizzling Tofu', 'Sizzling Sisig w/ Egg', 'Egg (Fried/Boiled)'];
        $before = PosItem::orderBy('id')->get()->toArray();
        $actor = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));

        $this->actingAs($actor)->get(route('inventory.index'))->assertOk()
            ->assertViewHas('items', fn ($items): bool => $items->take(20)->pluck('name')->all() === $expected)
            ->assertSeeInOrder($expected);

        $this->assertSame($before, PosItem::orderBy('id')->get()->toArray());
    }

    public function test_inventory_filters_preserve_custom_order_and_hide_retired_items(): void
    {
        $this->seed(DatabaseSeeder::class);
        $actor = User::factory()->create()->assignRole(Role::findOrCreate('cashier', 'web'));
        PosItem::where('name', 'Hotsilog')->update(['is_retired' => true]);
        PosItem::whereIn('name', ['Bangsilog', 'Chicksilog'])->update(['is_tracked' => true, 'stock_quantity' => 0, 'reorder_level' => 5]);
        $this->actingAs($actor)->get(route('inventory.index', ['category' => 'Breakfast']))->assertOk()
            ->assertViewHas('items', fn ($items): bool => $items->pluck('name')->all() === ['Bangsilog', 'Chicksilog', 'Longsilog', 'Porksilog', 'Tapsilog']);
        $this->get(route('inventory.index', ['search' => 'silog', 'low_stock' => 1]))->assertOk()
            ->assertViewHas('items', fn ($items): bool => $items->pluck('name')->all() === ['Bangsilog', 'Chicksilog']);
    }

    public function test_short_names_case_and_whitespace_keep_the_same_stock_positions(): void
    {
        $expected = [' bangsilog ', 'CHICKSILOG', 'Chicharon Bulaklak', 'Fries', 'Tokwat Baboy', 'Sizzling Sisig w Egg', 'eggs', 'Unlisted stock'];
        foreach (array_reverse($expected) as $name) {
            PosItem::create(['name' => $name, 'category' => 'Other', 'price' => 20, 'stock_quantity' => 3, 'is_tracked' => true]);
        }
        $actor = User::factory()->create()->assignRole(Role::findOrCreate('cashier', 'web'));
        $this->actingAs($actor)->get(route('inventory.index'))->assertOk()
            ->assertViewHas('items', fn ($items): bool => $items->pluck('name')->all() === $expected);
    }
}
