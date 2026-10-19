@extends('layouts.app')

@section('title', 'Guest Reservations & Bookings')
@section('subtitle', 'Reservations | Advanced Stay Scheduling & Room Allotment')

@section('top_action')
    <button onclick="openBookingModal()" class="bg-[#421A2B] hover:bg-[#341421] text-white text-[11px] font-bold px-3 py-1 rounded shadow-sm">
        + New Guest Reservation
    </button>
@endsection

@section('content')
<div class="px-3 pt-2 space-y-3 max-w-7xl mx-auto">

    <!-- Filter Bar -->
    <div class="workspace-toolbar flex flex-wrap items-center justify-between gap-2 text-xs">
        <div class="font-bold text-slate-800 uppercase tracking-wide">
            Reservation Status Filter:
        </div>
        <div class="flex items-center space-x-1.5">
            <a href="{{ route('bookings.index', ['status' => 'scheduled']) }}" class="px-2.5 py-1 rounded text-xs font-bold {{ $status === 'scheduled' ? 'bg-[#421A2B] text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-300' }}">
                Scheduled (Active)
            </a>
            <a href="{{ route('bookings.index', ['status' => 'checked_in']) }}" class="px-2.5 py-1 rounded text-xs font-bold {{ $status === 'checked_in' ? 'bg-[#0284c7] text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-300' }}">
                Checked-In
            </a>
            <a href="{{ route('bookings.index', ['status' => 'cancelled']) }}" class="px-2.5 py-1 rounded text-xs font-bold {{ $status === 'cancelled' ? 'bg-rose-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-300' }}">
                Cancelled
            </a>
            <a href="{{ route('bookings.index', ['status' => 'all']) }}" class="px-2.5 py-1 rounded text-xs font-bold {{ $status === 'all' ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-300' }}">
                All Records
            </a>
        </div>
    </div>

    <!-- Bookings Table -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
            <span>RESERVATION SCHEDULE LOG</span>
        </div>

        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead>
                    <tr>
                        <th>BOOKING #</th>
                        <th>ROOM</th>
                        <th>GUEST DETAILS</th>
                        <th>ARRIVAL TIME</th>
                        <th>DEPARTURE TIME</th>
                        <th>TIER</th>
                        <th>STATUS</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($bookings as $booking)
                        <tr>
                            <td class="font-mono font-bold text-[#421A2B]">
                                #{{ str_pad($booking->id, 5, '0', STR_PAD_LEFT) }}
                            </td>
                            <td class="font-bold">
                                Room {{ $booking->room->number ?? 'N/A' }}
                                <span class="block text-[10px] text-slate-500 font-normal">({{ $booking->room->type ?? '' }})</span>
                            </td>
                            <td class="text-left pl-3">
                                <div class="font-bold text-slate-900">{{ $booking->guest_name }}</div>
                                <div class="text-[10px] text-slate-500 font-mono">{{ $booking->guest_contact ?? 'No phone' }} &bull; {{ $booking->headcount }} pax</div>
                            </td>
                            <td class="font-mono text-emerald-800 font-semibold text-[11px]">
                                {{ $booking->arrival_at->format('m/d/Y h:i A') }}
                            </td>
                            <td class="font-mono text-slate-600 text-[11px]">
                                {{ $booking->departure_at->format('m/d/Y h:i A') }}
                            </td>
                            <td class="font-mono font-bold uppercase text-[10px]">
                                {{ $booking->rate_tier }}
                            </td>
                            <td>
                                @php
                                    $bStyle = match($booking->status) {
                                        'scheduled'  => 'bg-emerald-100 text-emerald-900 border-emerald-300',
                                        'checked_in' => 'bg-blue-100 text-blue-900 border-blue-300',
                                        'cancelled'  => 'bg-rose-100 text-rose-900 border-rose-300',
                                        default      => 'bg-slate-100 text-slate-800',
                                    };
                                @endphp
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase border {{ $bStyle }}">
                                    {{ $booking->status }}
                                </span>
                            </td>
                            <td class="space-x-1 whitespace-nowrap text-right">
                                @if($booking->isScheduled())
                                    <form method="POST" action="{{ route('checkin.store') }}" class="inline-block" onsubmit="return confirm('Check in this reservation now?')">
                                        @csrf
                                        <input type="hidden" name="booking_id" value="{{ $booking->id }}">
                                        <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                        <input type="hidden" name="room_id" value="{{ $booking->room_id }}">
                                        <input type="hidden" name="guest_name" value="{{ $booking->guest_name }}">
                                        <input type="hidden" name="guest_contact" value="{{ $booking->guest_contact }}">
                                        <input type="hidden" name="rate_tier" value="{{ $booking->rate_tier }}">
                                        <input type="hidden" name="headcount" value="{{ $booking->headcount }}">
                                        <button type="submit" class="btn-checkin font-bold" @disabled($booking->rate_tier === 'promo' && !config('sedona.midnight_promo_enabled'))>{{ $booking->rate_tier === 'promo' && !config('sedona.midnight_promo_enabled') ? 'Promo paused' : 'Check-In' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('bookings.cancel', $booking->id) }}" class="inline-block" onsubmit="return confirm('Cancel this reservation?')">
                                        @csrf
                                        <button type="submit" class="px-2 py-1 bg-rose-100 hover:bg-rose-200 text-rose-800 text-[10px] font-bold rounded border border-rose-300">
                                            Cancel
                                        </button>
                                    </form>
                                @else
                                    <span class="text-slate-400 text-[11px]">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-8 text-center text-slate-400">
                                No reservation records found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($bookings->hasPages())
            <div class="p-2 border-t mt-2">
                {{ $bookings->links() }}
            </div>
        @endif
    </div>

</div>

<!-- Modal: New Booking -->
<div id="bookingModal" class="fixed inset-0 z-50 flex items-center justify-center p-3 bg-black/60 hidden">
    <div class="bg-white w-full max-w-lg border-t-4 border-[#421A2B] p-5 shadow-2xl">
        <div class="flex items-center justify-between border-b pb-2 mb-3">
            <h3 class="font-bold text-[#421A2B] text-sm">Create New Room Reservation</h3>
            <button onclick="closeBookingModal()" class="text-slate-500 hover:text-black font-bold text-lg">&times;</button>
        </div>

        <form method="POST" action="{{ route('bookings.store') }}" class="space-y-3 text-xs">
            @csrf

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Target Room:</label>
                    <select name="room_id" required class="form-control-hms font-bold">
                        @foreach($availableRooms as $rm)
                            <option value="{{ $rm->id }}">Room {{ $rm->number }} ({{ $rm->type }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Rate Stay Tier:</label>
                    <select name="rate_tier" required class="form-control-hms font-bold">
                        <option value="3h">3 Hours Stay</option>
                        <option value="4h">4 Hours Stay</option>
                        <option value="6h">6 Hours Stay</option>
                        <option value="12h">12 Hours Stay</option>
                        <option value="24h" selected>24 Hours Stay</option>
                        @if(config('sedona.midnight_promo_enabled'))
                            <option value="promo">Midnight Promo (8 PM–6 AM)</option>
                        @endif
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Guest Full Name:</label>
                    <input type="text" name="guest_name" required placeholder="Full Name" class="form-control-hms">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Contact Phone:</label>
                    <input type="text" name="guest_contact" placeholder="09170000000" class="form-control-hms font-mono">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Arrival Date & Time:</label>
                    <input type="datetime-local" name="arrival_at" required value="{{ now()->addHours(2)->format('Y-m-d\TH:i') }}" class="form-control-hms font-mono">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">No. of Guests (Pax):</label>
                    <input type="number" name="headcount" value="2" min="1" max="8" class="form-control-hms font-mono">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Special Reservation Notes:</label>
                <textarea name="notes" rows="2" placeholder="e.g. VIP guest, late check-in, extra pillows" class="form-control-hms"></textarea>
            </div>

            <div class="pt-3 border-t flex justify-end space-x-2">
                <button type="button" onclick="closeBookingModal()" class="px-3 py-1.5 bg-slate-200 text-slate-700 font-bold rounded">Cancel</button>
                <button type="submit" class="px-4 py-1.5 bg-[#421A2B] hover:bg-[#341421] text-white font-bold rounded shadow">Save Reservation</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    function openBookingModal() {
        document.getElementById('bookingModal').classList.remove('hidden');
    }
    function closeBookingModal() {
        document.getElementById('bookingModal').classList.add('hidden');
    }
</script>
@endpush
@endsection
