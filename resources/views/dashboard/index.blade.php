@extends('layouts.app')

@section('title', 'Front Desk')
@section('subtitle', 'A clear view of your rooms, guests, and daily operations.')


@section('content')
<div class="dashboard-page">

    <!-- KPI Summary Row -->
    <div class="dashboard-stats">
        <div class="stat-card">
            <div class="stat-label"><span>Total rooms</span><span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z"/></svg></span></div>
            <div class="stat-number">{{ $totalRooms }}</div>
            <p class="stat-detail">Guest rooms across the property</p>
        </div>
        <div class="stat-card stat-available">
            <div class="stat-label"><span>Available</span><span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 5h16v14H4z M8 12l3 3 5-6"/></svg></span></div>
            <div class="stat-number">{{ $availableRooms }}</div>
            <p class="stat-detail">Ready for the next check-in</p>
        </div>
        <div class="stat-card stat-occupied">
            <div class="stat-label"><span>Occupied</span><span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M3 18V7 M21 18v-7 M3 15h18 M3 9h5v6 M8 11h13v4"/></svg></span></div>
            <div class="stat-number">{{ $occupiedRooms }}</div>
            <p class="stat-detail">Guests currently staying</p>
        </div>
        <div class="stat-card stat-occupancy">
            <div class="stat-label"><span>Occupancy</span><span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 20V4 M4 20h16 M8 16v-5 M13 16V7 M18 16V3"/></svg></span></div>
            <div class="stat-number">{{ $occupancyRate }}%</div>
            <div class="occupancy-track" aria-hidden="true"><span style="width: {{ $occupancyRate }}%"></span></div>
        </div>
    </div>

    @if(auth()->check() && auth()->user()->hasAnyRole(['cashier', 'front_desk']))
        <!-- Cashier personal shift performance (own sales only — never global) -->
        <div class="shift-summary">
            <div class="shift-summary-label">
                <span class="shift-summary-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 7h18v13H3z M7 7V4h10v3 M3 12h18 M10 12v3h4v-3"/></svg></span>
                <div><strong>My shift</strong><br><small>{{ $myShiftLabel ?? '' }}</small></div>
            </div>
            <div class="shift-summary-details">
                <span><b>{{ $myCheckIns ?? 0 }}</b> check-ins</span>
                <span><b>{{ $myOrdersCount ?? 0 }}</b> orders</span>
                <span class="shift-summary-amount"><small>Net sales</small> ₱{{ number_format($myShiftRevenue ?? 0, 2) }}</span>
            </div>
        </div>
    @endif

    <!-- Admin / Owner Pending Force Checkout Loss Resolution Banner -->
    @if(count($pendingForceCheckouts) > 0 && auth()->user()->hasAnyRole(['admin', 'owner']))
        <div class="bg-amber-50 border border-amber-400 p-3 mx-1 text-xs">
            <div class="font-bold text-amber-900 mb-1">
                ATTENTION: Pending Force Checkout Requests ({{ count($pendingForceCheckouts) }})
            </div>
            <div class="space-y-1.5">
                @foreach($pendingForceCheckouts as $pfc)
                    <div class="flex items-center justify-between bg-white p-2 border border-amber-300">
                        <div>
                            <strong>Room {{ $pfc->room->number ?? 'N/A' }}</strong> &bull;
                            Guest: {{ $pfc->guest->name ?? 'Guest' }} &bull;
                            Unsettled Total: <strong class="font-mono text-red-700">₱{{ number_format($pfc->net_total, 2) }}</strong> &bull;
                            Reason: <span class="italic text-slate-700">{{ $pfc->force_reason ?? 'Skip-out' }}</span>
                        </div>
                        <a href="{{ route('approvals.index') }}" class="px-3 py-1 bg-rose-700 hover:bg-rose-800 text-white font-bold text-xs rounded">Review recorded request</a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- ACTIVE CHECKOUT ALARM BANNER (Multi-Room Audio Trigger) -->
    <div id="activeAlarmBanner" class="hidden bg-purple-900 border-2 border-purple-500 text-white p-3 mx-1 shadow-lg rounded-lg">
        <div class="flex items-center justify-between mb-2">
            <div class="flex items-center space-x-2 font-black text-xs md:text-sm">
                <span class="text-base" id="alarmBannerIcon">⚠️</span>
                <span id="alarmBannerTitle">AUDIBLE CHECKOUT ALARM: GUEST STAY OVERDUE DETECTED</span>
            </div>
            <button onclick="if(window.sedonaAudio) window.sedonaAudio.stopAllAlarms()" class="px-3 py-1 bg-purple-950 hover:bg-black text-white text-[11px] font-bold rounded border border-purple-400 shadow-sm">
                Stop Alarm Sound
            </button>
        </div>
        <div id="alarmBannerRoomsList" class="space-y-1.5">
            <!-- Dynamically populated by SedonaAudioEngine -->
        </div>
    </div>

    <!-- Main 2-Column Matrix -->
    <div class="dashboard-panels">

        <!-- LEFT COLUMN: AVAILABLE ROOMS -->
        <div>
            <div class="hms-card">
                <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
                    <span class="room-panel-title"><span class="pill-dot text-emerald-500"></span>Available rooms</span>
                    <span class="room-panel-count">{{ $rooms->where('status', 'available')->where('is_staff_quarters', false)->count() }} ready</span>
                </div>

                <div class="room-list-scroll">
                    <table class="hms-table available-room-table">
                        <thead class="sticky top-0 z-10 bg-slate-100">
                            <tr>
                                <th>ACTION</th>
                                <th>ROOM</th>
                                <th>FL</th>
                                <th>ROOM TYPE</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rooms->where('status', 'available') as $room)
                                @if($room->is_staff_quarters)
                                    <tr class="bg-indigo-50/70">
                                        <td class="space-y-1">
                                            <span class="px-2 py-0.5 bg-indigo-700 text-white text-[10px] font-bold rounded">Staff</span>
                                            @php $staffFolio = $room->activeFolio ?? $room->getOrCreateStaffFolio(); @endphp
                                            <button onclick="openAddOnModal({{ $staffFolio->id }}, '{{ $room->number }} (Staff Quarters)')" class="px-2 py-0.5 bg-[#0284c7] hover:bg-[#0369a1] text-white text-[10px] font-bold rounded shadow-sm">
                                                + Add On
                                            </button>
                                        </td>
                                        <td class="font-bold text-indigo-950 font-mono">{{ $room->number }}</td>
                                        <td class="text-slate-500 font-mono text-[11px]">{{ $room->floor }}</td>
                                        <td class="text-left pl-2 text-indigo-900 font-medium">Permanent Employee Quarters</td>
                                    </tr>
                                @else
                                    <tr>
                                        <td>
                                            <button onclick="openCheckInModal(this.dataset)" data-room-id="{{ $room->id }}" data-room-number="{{ $room->number }}" data-room-type="{{ $room->type }}" data-rate-3h="{{ $room->base_rate_3h }}" data-rate-4h="{{ $room->base_rate_4h }}" data-rate-6h="{{ $room->base_rate_6h }}" data-rate-12h="{{ $room->base_rate_12h }}" data-rate-24h="{{ $room->base_rate_24h }}" data-rate-promo="{{ $room->base_rate_promo }}" class="btn-checkin">
                                                Check In
                                            </button>
                                        </td>
                                        <td class="font-bold text-slate-900 font-mono text-sm">{{ $room->number }}</td>
                                        <td class="text-slate-500 font-mono text-[11px]">{{ $room->floor }}</td>
                                        <td class="text-left pl-2 font-medium">
                                            @php
                                                $roomUpper = strtoupper($room->type ?? '');
                                                $roomPill = str_contains($roomUpper, 'VIP') || str_contains($roomUpper, 'SUITE') ? 'pill-vip' : (str_contains($roomUpper, 'PREMIUM') ? 'pill-premium' : 'pill-classic');
                                            @endphp
                                            <span class="pill {{ $roomPill }}">{{ $room->type }}</span>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr>
                                    <td colspan="4" class="py-6 text-center text-slate-400">No rooms available under this filter.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: OCCUPIED ROOMS -->
        <div>
            <div class="hms-card">
                <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
                    <span class="room-panel-title"><span class="pill-dot text-brand"></span>Occupied rooms</span>
                    <span class="room-panel-count">{{ $rooms->where('status', 'occupied')->count() }} active</span>
                </div>

                @if($rooms->where('status', 'occupied')->isNotEmpty())
                <div class="room-list-scroll occupied-room-list">
                    <table class="hms-table occupied-room-table" aria-label="Occupied rooms">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th scope="col">Room</th>
                                <th scope="col">Check-in</th>
                                <th scope="col">Pax / Stay</th>
                                <th scope="col">Remaining</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($occupiedRoomsList as $room)
                                @php
                                    $folio = $room->activeFolio;
                                @endphp
                                @if($folio)
                                    <tr class="occupied-room-summary"
                                        data-room-id="{{ $room->id }}"
                                        data-room-number="{{ $room->number }}"
                                        data-guest-name="{{ $folio->guest->name ?? 'Guest' }}"
                                        data-checkout-time="{{ $folio->expected_checkout_at->toIso8601String() }}"
                                        data-stay-mode="{{ $folio->isOpenTime() ? 'open' : 'fixed' }}"
                                        data-checkin-time="{{ $folio->checked_in_at->toIso8601String() }}"
                                        data-base-rate="{{ $folio->room_charge }}"
                                        data-folio-id="{{ $folio->id }}"
                                        data-status="{{ $room->status }}">
                                        <td data-label="Room" class="font-bold font-mono text-sm">{{ $room->number }}</td>
                                        <td data-label="Check-in" class="font-mono text-[11px]">{{ $folio->checked_in_at->format('m/d/Y') }}<br>{{ $folio->checked_in_at->format('h:i A') }}</td>
                                        <td data-label="Pax / Stay"><span class="font-bold">{{ $folio->guest->headcount ?? 2 }} pax</span><br><span class="font-mono">{{ $folio->isOpenTime() ? 'OPEN TIME · 3H BASE' : strtoupper($folio->rate_tier) }}</span></td>
                                        <td data-label="Remaining" class="stay-countdown-timer font-mono text-[11px]">Calculating...</td>
                                        <td data-label="Status"><span class="pill pill-occupied"><span class="pill-dot"></span>OCCUPIED</span></td>
                                    </tr>
                                    <tr class="occupied-room-action-row">
                                        <td colspan="5">
                                            <div class="occupied-room-actions" role="group" aria-label="Actions for room {{ $room->number }}">
                                                <a href="{{ route('checkout.process', $folio->id) }}" class="btn-occupied font-bold">Occupied</a>
                                                @if($folio->isStayTimeLockedFor(auth()->user()))
                                                    <span class="text-xs font-semibold text-slate-500">Time locked · Billing printed</span>
                                                @else
                                                    <button onclick="openXtendModal({{ $folio->id }}, '{{ $room->number }}')" class="btn-xtend font-bold">{{ $folio->isOpenTime() ? 'Convert Package' : 'Extend' }}</button>
                                                @endif
                                                <button onclick="openDiscountModal(this.dataset.folioId, this.dataset.roomNumber, this.dataset.roomType, this.dataset.rateTier, this.dataset.discountType, this.dataset.discountRef)" data-folio-id="{{ $folio->id }}" data-room-number="{{ $room->number }}" data-room-type="{{ $room->type }}" data-rate-tier="{{ $folio->rate_tier }}" data-discount-type="{{ $folio->discount_type ?? 'NONE' }}" data-discount-ref="{{ $folio->discount_id_ref ?? '' }}" class="btn-discount font-bold">Discount</button>
                                                <button onclick="openTransferModal({{ $folio->id }}, '{{ $room->number }}')" class="btn-transfer font-bold">Transfer</button>
                                                <button onclick="openAddOnModal({{ $folio->id }}, '{{ $room->number }}')" class="btn-addon font-bold">Add On</button>
                                                <a href="{{ route('folios.orderslip', $folio->id) }}" target="_blank" class="btn-orderslip font-bold">Order Slip</a>
                                                <button type="button" onclick="openAddOnModal(this.dataset.folioId, this.dataset.roomNumber)" data-folio-id="{{ $folio->id }}" data-room-number="{{ $room->number }}" class="btn-xorder font-bold">X Order</button>
                                                <button onclick="openForceCheckoutModal({{ $folio->id }}, '{{ $room->number }}', '{{ number_format($folio->net_total, 2) }}')" class="btn-force font-bold" title="Force Checkout Request">Force</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                    <div class="rooms-empty text-center">
                        <span class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M3 18V7 M21 18v-7 M3 15h18 M3 9h5v6 M8 11h13v4"/></svg></span>
                        <span class="empty-title">No rooms currently occupied.</span>
                        <span class="empty-description">New guest stays will appear here after check-in.</span>
                    </div>
                @endif
            </div>
        </div>

    </div>

</div>

@include('dashboard.partials.folio-modals')
@endsection
