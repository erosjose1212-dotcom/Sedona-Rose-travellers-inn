<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (['folios' => ['active', 'checked_in_at', 'expected_checkout_at'], 'bookings' => ['scheduled', 'arrival_at', 'departure_at']] as $table => [$status, $arrivalField, $checkoutField]) {
                DB::table($table)->where('rate_tier', 'promo')->where('status', $status)
                    ->whereIn('room_id', DB::table('rooms')->select('id')->where('is_staff_quarters', false))
                    ->orderBy('id')->lockForUpdate()->chunkById(100, function ($rows) use ($table, $arrivalField, $checkoutField): void {
                        foreach ($rows as $row) {
                            $arrival = CarbonImmutable::parse($row->{$arrivalField}, config('app.timezone'))->setTimezone('Asia/Manila');
                            $checkout = $arrival->startOfDay()->setHour(6);
                            if ($arrival->hour >= 6) {
                                $checkout = $checkout->addDay();
                            }
                            $checkout = $checkout->addHours((int) ($row->extra_hours ?? 0))->setTimezone(config('app.timezone'));
                            DB::table($table)->where('id', $row->id)->update([$checkoutField => $checkout->toDateTimeString()]);
                        }
                    });
            }
        });
    }

    /**
     * The previous checkout values cannot be recovered; rollback keeps corrected times.
     */
    public function down(): void {}
};
