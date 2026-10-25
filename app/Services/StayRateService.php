<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\Room;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StayRateService
{
    public function __construct(private AuditService $audit) {}

    public function transfer(Folio $folio, Room $room, Request $request): void
    {
        $this->apply($folio, $room, $folio->rate_tier, (int) $folio->extra_hours, $request, 'folio.room_transferred');
    }

    public function convert(Folio $folio, string $tier, Request $request): void
    {
        if ($folio->room->getTierHours($tier) <= $folio->room->getTierHours($folio->rate_tier)) {
            throw ValidationException::withMessages(['rate_tier' => 'Choose a package longer than the current stay package.']);
        }
        if (! $this->canConvert($folio, $tier)) {
            throw ValidationException::withMessages(['rate_tier' => 'Choose a package that has not elapsed and does not shorten the checkout time already granted.']);
        }

        $this->apply($folio, $folio->room, $tier, 0, $request, 'folio.package_converted');
    }

    public function canConvert(Folio $folio, string $tier): bool
    {
        if (! $folio->isActive() || $folio->room->is_staff_quarters || ! in_array($tier, ['12h', '24h'], true)
            || $folio->room->getTierHours($tier) <= $folio->room->getTierHours($folio->rate_tier)) {
            return false;
        }
        $checkout = $folio->room->getCheckoutForTier($tier, $folio->checked_in_at);

        return $checkout->gt(now()) && $checkout->gte($folio->expected_checkout_at);
    }

    public function assertReservationAvailable(Room $room, CarbonInterface $arrival, CarbonInterface $departure, ?int $bookingId = null, bool $openTime = false): void
    {
        if ($openTime && Booking::where('room_id', $room->id)->where('status', 'scheduled')->where('departure_at', '>', $arrival)->exists()) {
            throw ValidationException::withMessages(['room_id' => 'This room has an upcoming reservation. Choose another room or a fixed stay package for Open Time.']);
        }
        if ($departure->gt($arrival) && Booking::hasConflict($room->id, $arrival->toDateTimeString(), $departure->toDateTimeString(), $bookingId)) {
            throw ValidationException::withMessages(['room_id' => 'This stay would overlap an existing reservation. Choose another room or a shorter stay.']);
        }
    }

    public function assertPromoArrival(string $tier, CarbonInterface $arrival, string $field = 'rate_tier'): void
    {
        $hour = $arrival->copy()->setTimezone('Asia/Manila')->hour;
        if ($tier === 'promo' && $hour >= 6 && $hour < 20) {
            throw ValidationException::withMessages([$field => 'Midnight promo check-in is available from 8 PM until before 6 AM, with checkout at 6 AM.']);
        }
    }

    private function apply(Folio $folio, Room $room, string $tier, int $extraHours, Request $request, string $event): void
    {
        $checkout = $room->getCheckoutForTier($tier, $folio->checked_in_at, $extraHours);
        $openTime = $folio->isOpenTime() && $event === 'folio.room_transferred';
        $this->assertReservationAvailable($room, now(), $checkout, $folio->booking_id, $openTime);
        $before = $folio->only(['room_id', 'rate_tier', 'stay_mode', 'room_charge', 'extra_hours', 'expected_checkout_at', 'net_total']);
        $folio->room_id = $room->id;
        $folio->setRelation('room', $room);
        $folio->rate_tier = $tier;
        $folio->stay_mode = $openTime ? 'open' : 'fixed';
        $folio->room_charge = $room->getRateForTier($tier);
        $folio->extra_hours = $extraHours;
        $folio->expected_checkout_at = $checkout;
        $folio->recalculate();
        $this->audit->record($request, $event, $folio, [
            'before' => $before,
            'after' => $folio->only(array_keys($before)),
            'reason' => $request->input('reason', 'Room transfer'),
            'operation_key' => $request->input('operation_key'),
        ]);
    }
}
