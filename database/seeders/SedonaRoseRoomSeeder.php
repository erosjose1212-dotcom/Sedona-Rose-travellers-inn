<?php

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SedonaRoseRoomSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $numbers = array_map(strval(...), range(1, 20));
            $extraRooms = Room::whereNotIn('number', $numbers)->get();
            foreach ($extraRooms as $room) {
                if ($room->folios()->exists() || $room->bookings()->exists() || $room->orders()->exists()) {
                    throw new RuntimeException('Room '.$room->number.' has history and cannot be removed automatically.');
                }
            }

            foreach (range(1, 20) as $number) {
                $type = $number <= 8 ? 'Classic Room' : 'Premium Room';
                $room = Room::firstOrNew(['number' => (string) $number]);
                if ($room->exists && $room->status === 'available' && $room->activeFolio()->exists()) {
                    $room->status = 'occupied';
                }
                if (! $room->exists || $room->type !== $type || $room->is_staff_quarters) {
                    $rates = $number <= 8 ? [395, 525, 785, 1195, 2100, 995] : [495, 625, 885, 1295, 2300, 1055];
                    $room->fill(array_combine(['base_rate_3h', 'base_rate_4h', 'base_rate_6h', 'base_rate_12h', 'base_rate_24h', 'base_rate_promo'], $rates));
                }
                if (! $room->exists) {
                    $room->floor = 1;
                    $room->status = 'available';
                }
                $room->fill(['name' => $type.' '.$number, 'type' => $type, 'is_staff_quarters' => false])->save();
            }

            Room::whereKey($extraRooms->modelKeys())->delete();
        });
    }
}
