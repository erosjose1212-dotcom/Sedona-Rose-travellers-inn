<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use App\Services\StayRateService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookingController extends Controller
{
    /**
     * Display all reservations.
     */
    public function index(Request $request): View
    {
        $status = $request->query('status', 'scheduled');

        $query = Booking::with(['room', 'createdBy'])->orderBy('arrival_at')->orderBy('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $bookings = $query->paginate(15)->withQueryString();
        $availableRooms = Room::where('is_staff_quarters', false)->orderByNumber()->get();

        return view('bookings.index', compact('bookings', 'availableRooms', 'status'));
    }

    /**
     * Store a new booking / reservation.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'guest_name' => 'required|string|max:255',
            'guest_contact' => 'nullable|string|max:50',
            'headcount' => 'required|integer|min:1|max:8',
            'rate_tier' => 'required|in:3h,4h,6h,12h,24h'.(config('sedona.midnight_promo_enabled') ? ',promo' : ''),
            'arrival_at' => 'required|date|after_or_equal:today',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($validated): void {
            $room = Room::whereKey($validated['room_id'])->lockForUpdate()->firstOrFail();
            if ($room->is_staff_quarters || $room->status === 'maintenance') {
                throw ValidationException::withMessages(['room_id' => 'This room cannot be reserved.']);
            }
            $arrival = Carbon::parse($validated['arrival_at']);
            app(StayRateService::class)->assertPromoArrival($validated['rate_tier'], $arrival, 'arrival_at');
            $departure = $room->getCheckoutForTier($validated['rate_tier'], $arrival);

            if ($room->folios()->where('status', 'active')->where('checked_in_at', '<', $departure)
                ->where(fn ($query) => $query->where('stay_mode', 'open')->orWhere('expected_checkout_at', '>', $arrival))->exists()) {
                throw ValidationException::withMessages(['arrival_at' => 'This room is occupied during the selected date/time.']);
            }

            // Check for conflicting reservations on same room
            if (Booking::hasConflict($room->id, $arrival->toDateTimeString(), $departure->toDateTimeString())) {
                throw ValidationException::withMessages(['arrival_at' => 'This room is already reserved for the selected date/time.']);
            }

            Booking::create([
                'room_id' => $room->id,
                'user_id' => Auth::id() ?? 1,
                'guest_name' => $validated['guest_name'],
                'guest_contact' => $validated['guest_contact'] ?? null,
                'headcount' => $validated['headcount'],
                'rate_tier' => $validated['rate_tier'],
                'arrival_at' => $arrival,
                'departure_at' => $departure,
                'status' => 'scheduled',
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return redirect()->route('bookings.index')
            ->with('success', 'Reservation created.');
    }

    /**
     * Cancel a booking.
     */
    public function cancel(Booking $booking): RedirectResponse
    {
        DB::transaction(function () use ($booking): void {
            Room::whereKey($booking->room_id)->lockForUpdate()->firstOrFail();
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'scheduled') {
                throw ValidationException::withMessages(['booking' => 'Only a scheduled reservation can be cancelled.']);
            }
            $locked->update(['status' => 'cancelled']);
        });

        return back()->with('info', "Booking #{$booking->id} for {$booking->guest_name} has been cancelled.");
    }
}
