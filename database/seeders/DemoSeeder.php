<?php

namespace Database\Seeders;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /**
     * Seed mock guests, occupied folios, active shift, and expenses for demonstration/testing.
     */
    public function run(): void
    {
        $defaultCashier = User::where('email', 'pau@sedonapms.com')->first() ?? User::first();

        // ── 1. Seed Mock Guests ──────────────────────────────────────
        $guest1 = Guest::updateOrCreate(['contact' => '09171111111'], ['name' => 'Juan Dela Cruz', 'headcount' => 2, 'is_senior' => false, 'is_pwd' => false]);
        $guest2 = Guest::updateOrCreate(['contact' => '09172222222'], ['name' => 'Roberto Santos', 'headcount' => 2, 'is_senior' => false, 'is_pwd' => false]);
        $guest3 = Guest::updateOrCreate(['contact' => '09173333333'], ['name' => 'Elena Bautista', 'headcount' => 2, 'is_senior' => false, 'is_pwd' => false]);
        $guest4 = Guest::updateOrCreate(['contact' => '09174444444'], ['name' => 'Carlos Mendoza', 'headcount' => 1, 'is_senior' => false, 'is_pwd' => false]);
        $guest5 = Guest::updateOrCreate(['contact' => '09175555555'], ['name' => 'Antonio Ramos', 'headcount' => 2, 'is_senior' => false, 'is_pwd' => false]);
        $guest6 = Guest::updateOrCreate(['contact' => '09176666666'], ['name' => 'Grace Lim', 'headcount' => 2, 'is_senior' => false, 'is_pwd' => false]);
        $guest7 = Guest::updateOrCreate(['contact' => '09177777777'], ['name' => 'Michael Tan', 'headcount' => 2, 'is_senior' => false, 'is_pwd' => false]);

        // ── 2. Seed Mock Occupied Folios ─────────────────────────────
        // Room 13 (Premium, Overdue by 3 hours, Rate: 495, 3 excess hrs * 130 = 390, Total: 885)
        $r13 = Room::where('number', '13')->first();
        if ($r13) {
            $r13->update(['status' => 'occupied']);
            Folio::updateOrCreate(
                ['transaction_id' => 'SCTI-042880'],
                [
                    'room_id' => $r13->id,
                    'guest_id' => $guest1->id,
                    'user_id' => $defaultCashier->id,
                    'rate_tier' => '3h',
                    'checked_in_at' => now()->subHours(6),
                    'expected_checkout_at' => now()->subHours(3),
                    'extra_hours' => 3,
                    'status' => 'active',
                    'room_charge' => 495.00,
                    'surcharge_total' => 390.00,
                    'pos_total' => 0.00,
                    'gross_total' => 885.00,
                    'discount_amount' => 0.00,
                    'senior_pwd_discount' => false,
                    'net_total' => 885.00,
                    'payment_method' => 'cash',
                ]
            );
        }

        // Room 7 (Classic, 12h, with F&B items: 2x Coffee, 1x Tapsilog, 1x Mineral Water)
        $r7 = Room::where('number', '7')->first();
        if ($r7) {
            $r7->update(['status' => 'occupied']);
            $folio7 = Folio::updateOrCreate(
                ['transaction_id' => 'SCTI-056191'],
                [
                    'room_id' => $r7->id,
                    'guest_id' => $guest2->id,
                    'user_id' => $defaultCashier->id,
                    'rate_tier' => '12h',
                    'checked_in_at' => now()->subHours(3),
                    'expected_checkout_at' => now()->addHours(9),
                    'extra_hours' => 0,
                    'status' => 'active',
                    'pos_items' => [
                        ['name' => 'Coffee Sachet (Brown/Blanca)', 'qty' => 2, 'price' => 25, 'subtotal' => 50],
                        ['name' => 'Tapsilog', 'qty' => 1, 'price' => 160, 'subtotal' => 160],
                        ['name' => 'Mineral Water (Purified 500ml)', 'qty' => 1, 'price' => 25, 'subtotal' => 25],
                    ],
                    'room_charge' => 1195.00,
                    'surcharge_total' => 0.00,
                    'pos_total' => 235.00,
                    'gross_total' => 1430.00,
                    'discount_amount' => 0.00,
                    'senior_pwd_discount' => false,
                    'net_total' => 1430.00,
                    'payment_method' => 'cash',
                ]
            );

            // Add Kitchen Order for Room 7
            $coffee = PosItem::where('name', 'Coffee Sachet (Brown/Blanca)')->first();
            $tapsilog = PosItem::where('name', 'Tapsilog')->first();
            $water = PosItem::where('name', 'Mineral Water (Purified 500ml)')->first();
            if ($coffee && $tapsilog && $water) {
                $order7 = Order::updateOrCreate(
                    ['transaction_id' => 'ORD-07-01'],
                    [
                        'user_id' => $defaultCashier->id,
                        'folio_id' => $folio7->id,
                        'room_id' => $r7->id,
                        'type' => 'room_charge',
                        'status' => 'preparing',
                        'total' => 235.00,
                        'payment_method' => 'charge_to_room',
                    ]
                );
                OrderItem::updateOrCreate(['order_id' => $order7->id, 'pos_item_id' => $coffee->id], ['item_name' => $coffee->name, 'unit_price' => 25, 'quantity' => 2, 'subtotal' => 50]);
                OrderItem::updateOrCreate(['order_id' => $order7->id, 'pos_item_id' => $tapsilog->id], ['item_name' => $tapsilog->name, 'unit_price' => 160, 'quantity' => 1, 'subtotal' => 160]);
                OrderItem::updateOrCreate(['order_id' => $order7->id, 'pos_item_id' => $water->id], ['item_name' => $water->name, 'unit_price' => 25, 'quantity' => 1, 'subtotal' => 25]);
            }
        }

        // Room 4 (VIP Suite, 3h)
        $r4 = Room::where('number', '4')->first();
        if ($r4) {
            $r4->update(['status' => 'occupied']);
            Folio::updateOrCreate(
                ['transaction_id' => 'SCTI-000004'],
                [
                    'room_id' => $r4->id,
                    'guest_id' => $guest3->id,
                    'user_id' => $defaultCashier->id,
                    'rate_tier' => '3h',
                    'checked_in_at' => now()->subMinutes(90),
                    'expected_checkout_at' => now()->addMinutes(90),
                    'status' => 'active',
                    'room_charge' => 695.00,
                    'net_total' => 695.00,
                    'gross_total' => 695.00,
                ]
            );
        }

        // Room 10 (Classic, 3h)
        $r10 = Room::where('number', '10')->first();
        if ($r10) {
            $r10->update(['status' => 'occupied']);
            Folio::updateOrCreate(
                ['transaction_id' => 'SCTI-000010'],
                [
                    'room_id' => $r10->id,
                    'guest_id' => $guest4->id,
                    'user_id' => $defaultCashier->id,
                    'rate_tier' => '3h',
                    'checked_in_at' => now()->subMinutes(45),
                    'expected_checkout_at' => now()->addMinutes(135),
                    'status' => 'active',
                    'room_charge' => 395.00,
                    'net_total' => 395.00,
                    'gross_total' => 395.00,
                ]
            );
        }

        // Room 2 (VIP Suite, 12h)
        $r2 = Room::where('number', '2')->first();
        if ($r2) {
            $r2->update(['status' => 'occupied']);
            Folio::updateOrCreate(
                ['transaction_id' => 'SCTI-000002'],
                [
                    'room_id' => $r2->id,
                    'guest_id' => $guest5->id,
                    'user_id' => $defaultCashier->id,
                    'rate_tier' => '12h',
                    'checked_in_at' => now()->subHours(2),
                    'expected_checkout_at' => now()->addHours(10),
                    'status' => 'active',
                    'room_charge' => 1395.00,
                    'net_total' => 1395.00,
                    'gross_total' => 1395.00,
                ]
            );
        }

        // Room 17 (Premium, 3h)
        $r17 = Room::where('number', '17')->first();
        if ($r17) {
            $r17->update(['status' => 'occupied']);
            Folio::updateOrCreate(
                ['transaction_id' => 'SCTI-000017'],
                [
                    'room_id' => $r17->id,
                    'guest_id' => $guest6->id,
                    'user_id' => $defaultCashier->id,
                    'rate_tier' => '3h',
                    'checked_in_at' => now()->subMinutes(75),
                    'expected_checkout_at' => now()->addMinutes(105),
                    'status' => 'active',
                    'room_charge' => 495.00,
                    'net_total' => 495.00,
                    'gross_total' => 495.00,
                ]
            );
        }

        // Room 14 (Premium, 24h)
        $r14 = Room::where('number', '14')->first();
        if ($r14) {
            $r14->update(['status' => 'occupied']);
            Folio::updateOrCreate(
                ['transaction_id' => 'SCTI-000014'],
                [
                    'room_id' => $r14->id,
                    'guest_id' => $guest7->id,
                    'user_id' => $defaultCashier->id,
                    'rate_tier' => '24h',
                    'checked_in_at' => now()->subHours(5),
                    'expected_checkout_at' => now()->addHours(19),
                    'status' => 'active',
                    'room_charge' => 2300.00,
                    'net_total' => 2300.00,
                    'gross_total' => 2300.00,
                ]
            );
        }

        // ── 3. Seed Active Shift ─────────────────────────────────────
        Shift::updateOrCreate(
            ['opened_by' => $defaultCashier->id, 'closed_at' => null],
            [
                'shift_type' => 'day',
                'shift_date' => now()->toDateString(),
                'opened_at' => now()->startOfDay()->addHours(6),
                'is_frozen' => false,
                'room_revenue' => 6790.00,
                'kitchen_revenue' => 235.00,
                'gross_revenue' => 7025.00,
                'cash_total' => 7025.00,
                'gcash_total' => 0.00,
                'denomination_count' => [
                    '1000' => 6, '500' => 1, '200' => 2,
                    '100' => 1, '50' => 0, '20' => 1, 'coins' => 5,
                ],
                'handoff_notes' => 'Room 13 is in overtime status. Room 7 room service order dispatched to kitchen.',
            ]
        );

        // ── 4. Seed Operating Expenses ────────────────────────────────
        $expenses = [
            [
                'voucher_number' => 'EXP-20261001-001',
                'expense_date' => now()->toDateString(),
                'category' => 'utilities',
                'description' => 'Meralco Commercial Electricity Bill (Partial payment)',
                'amount' => 4500.00,
                'payment_source' => 'cash_drawer',
                'receipt_reference' => 'OR-991203',
                'status' => 'approved',
                'notes' => 'Settled via cashier cash drawer',
            ],
            [
                'voucher_number' => 'EXP-20261001-002',
                'expense_date' => now()->toDateString(),
                'category' => 'kitchen_inventory',
                'description' => 'Wet Market Meat & Vegetable Restock for Kitchen Dining',
                'amount' => 2850.00,
                'payment_source' => 'cash_drawer',
                'receipt_reference' => 'MR-4412',
                'status' => 'approved',
                'notes' => 'Purchased fresh pork belly, chicken, garlic, rice',
            ],
            [
                'voucher_number' => 'EXP-20261001-003',
                'expense_date' => now()->subDay()->toDateString(),
                'category' => 'laundry_linens',
                'description' => 'Commercial Laundry Services (Duvets & Bedspreads 45kg)',
                'amount' => 2250.00,
                'payment_source' => 'cash_drawer',
                'receipt_reference' => 'LND-8891',
                'status' => 'approved',
                'notes' => 'Floor 1 and 2 full linen exchange',
            ],
            [
                'voucher_number' => 'EXP-20261001-004',
                'expense_date' => now()->subDays(2)->toDateString(),
                'category' => 'maintenance_repairs',
                'description' => 'Room 4 AC Split-Type Freon Recharge & Filter Deep Cleaning',
                'amount' => 1800.00,
                'payment_source' => 'petty_cash',
                'receipt_reference' => 'SRV-1029',
                'status' => 'approved',
                'notes' => 'Contracted technician service',
            ],
            [
                'voucher_number' => 'EXP-20261001-005',
                'expense_date' => now()->subDays(3)->toDateString(),
                'category' => 'staff_allowances',
                'description' => 'Housekeeping & Front Desk Staff Meal Allowances',
                'amount' => 1200.00,
                'payment_source' => 'petty_cash',
                'receipt_reference' => 'STAFF-1001',
                'status' => 'approved',
                'notes' => '4 staff duty meals',
            ],
        ];

        foreach ($expenses as $exp) {
            \App\Models\Expense::updateOrCreate(
                ['voucher_number' => $exp['voucher_number']],
                array_merge($exp, ['user_id' => $defaultCashier->id])
            );
        }
    }
}
