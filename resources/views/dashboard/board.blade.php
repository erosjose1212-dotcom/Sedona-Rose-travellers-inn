@extends('layouts.app')

@section('title', 'Front Desk Operations Board')
@section('subtitle', 'Front Desk Operations | Apartment Occupancy Board')

@section('content')
<div class="bg-[#f6f3ed] px-3 pt-3 pb-6 -mb-6">
<div class="grid grid-cols-1 xl:grid-cols-12 gap-3 max-w-[1600px] mx-auto">

    <!-- LEFT SIDEBAR -->
    <aside class="xl:col-span-3 space-y-3">

        @if($shiftActivity !== null && auth()->user()->hasAnyRole(['admin', 'owner']))
            <section class="bg-white rounded-xl border border-[#e8e2d6] shadow-sm p-4" aria-label="Check-ins and checkouts by shift">
                <h2 class="font-black text-[13px] tracking-wide text-[#421A2B] uppercase">Check-ins &amp; Checkouts</h2>
                <p class="text-xs text-slate-500 mt-1">By shift · Manila time · starting {{ $shiftActivity['date'] }}</p>
                <div class="mt-3 rounded-lg border border-emerald-100 bg-emerald-50 p-3" aria-label="Currently signed-in cashiers">
                    <h3 class="text-xs font-bold text-[#421A2B]">Cashiers signed in now</h3>
                    @if($signedInCashiers === null)
                        <p class="text-xs text-slate-600 mt-2">Cashier session status unavailable.</p>
                    @else
                        <ul class="space-y-2 mt-2">
                            @forelse($signedInCashiers as $cashier)
                                <li class="text-xs">
                                    <span class="flex items-center gap-2 font-semibold text-emerald-900"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0" aria-hidden="true"></span>{{ $cashier['name'] }}</span>
                                    <span class="block text-[10px] text-slate-600 mt-1">Last activity: <time datetime="{{ $cashier['last_active_at']->toIso8601String() }}">{{ $cashier['last_active_at']->format('M j, g:i A') }}</time></span>
                                </li>
                            @empty
                                <li class="text-xs text-slate-600">No cashier signed in.</li>
                            @endforelse
                        </ul>
                        <p class="text-[10px] text-slate-500 mt-2">Updates on refresh. Closed browsers may remain listed until their session expires.</p>
                    @endif
                </div>
                <form method="GET" action="{{ route('dashboard') }}" class="flex flex-wrap items-end gap-2 mt-3">
                    <label class="text-xs text-slate-600">Shift start date
                        <input type="date" name="shift_date" value="{{ $shiftActivity['date'] }}" required class="form-control-hms block w-full">
                    </label>
                    <button type="submit" class="btn-addon">Show</button>
                </form>
                <table class="w-full text-xs mt-3">
                    <thead><tr class="text-slate-500"><th class="text-left py-2">Shift</th><th>Check-ins</th><th>Checkouts</th></tr></thead>
                    <tbody>
                        @foreach($shiftActivity['shifts'] as $shiftKey => $activity)
                            <tr class="border-t border-slate-100">
                                <th class="text-left py-3 font-semibold text-slate-700">{{ $activity['label'] }}<span class="block text-[10px] font-normal text-slate-500">{{ $activity['start']->format('g:i A') }}–{{ $activity['end']->format('g:i A') }}{{ $shiftKey === 'night' ? ' next day' : '' }}</span></th>
                                <td class="text-center"><strong class="text-base text-emerald-800">{{ $activity['check_ins']['stays'] }}</strong><span class="block text-[10px] text-slate-500">{{ $activity['check_ins']['guests'] }} guests</span></td>
                                <td class="text-center"><a class="font-bold text-base text-[#421A2B] underline" href="{{ route('receipts.index', ['date' => $shiftActivity['date'], 'shift' => $shiftKey]) }}">{{ $activity['check_outs']['stays'] }}</a><span class="block text-[10px] text-slate-500">{{ $activity['check_outs']['guests'] }} guests</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="text-[10px] text-slate-500 mt-2">Counts room stays, including guests who have already left. Cancelled stays and staff quarters are excluded.</p>
            </section>
        @endif

        <!-- Occupancy graph -->
        <div class="bg-white rounded-xl border border-[#e8e2d6] shadow-sm p-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="font-black text-[13px] tracking-wide text-[#421A2B] uppercase">Occupancy Graph</div>
                    <div class="text-[10px] tracking-widest text-slate-400 uppercase">Real-time distribution</div>
                </div>
                <span class="text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-full px-2 py-0.5 flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> LIVE
                </span>
            </div>

            <div class="flex items-center gap-4 mt-4">
                <div class="relative w-24 h-24 rounded-full shrink-0" style="background: conic-gradient(#EF4444 0 {{ $boardRate }}%, #ece5d8 {{ $boardRate }}% 100%);">
                    <div class="absolute inset-[10px] bg-white rounded-full flex flex-col items-center justify-center">
                        <span class="font-black text-lg text-[#421A2B] leading-none">{{ $boardRate }}%</span>
                        <span class="text-[8px] tracking-widest text-slate-400 uppercase mt-0.5">Booked</span>
                    </div>
                </div>
                <div class="min-w-0">
                    <div class="font-mono font-black text-2xl text-slate-900 leading-none">{{ $boardCounts['occupied'] }} <span class="text-sm text-slate-400 font-bold">/ {{ $boardTotal }}</span></div>
                    <div class="text-xs text-slate-500 mt-1">Occupied Apartments</div>
                    <div class="text-[11px] font-bold text-emerald-700 mt-0.5">{{ $boardCounts['available'] }} vacant &amp; ready</div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-t border-slate-100">
                <div class="flex items-center justify-between text-[10px] tracking-widest uppercase text-slate-400 mb-2">
                    <span>Status breakdown</span><span>Ratio &amp; %</span>
                </div>
                @php
                    $occPct = $boardTotal > 0 ? round($boardCounts['occupied'] / $boardTotal * 100, 1) : 0;
                    $avaPct = $boardTotal > 0 ? round($boardCounts['available'] / $boardTotal * 100, 1) : 0;
                    $latePct = $boardTotal > 0 ? round($boardCounts['late'] / $boardTotal * 100, 1) : 0;
                @endphp
                <div class="space-y-2.5 text-xs">
                    <div>
                        <div class="flex items-center justify-between font-semibold text-slate-700">
                            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-[#EF4444]"></span>Occupied</span>
                            <span class="font-mono">{{ $boardCounts['occupied'] }} ({{ $occPct }}%)</span>
                        </div>
                        <div class="h-1.5 bg-slate-100 rounded-full mt-1"><div class="h-1.5 bg-[#EF4444] rounded-full" style="width: {{ $occPct }}%"></div></div>
                    </div>
                    <div>
                        <div class="flex items-center justify-between font-semibold text-slate-700">
                            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-[#10B981]"></span>Available</span>
                            <span class="font-mono">{{ $boardCounts['available'] }} ({{ $avaPct }}%)</span>
                        </div>
                        <div class="h-1.5 bg-slate-100 rounded-full mt-1"><div class="h-1.5 bg-[#10B981] rounded-full" style="width: {{ $avaPct }}%"></div></div>
                    </div>
                    <div>
                        <div class="flex items-center justify-between font-semibold text-slate-700">
                            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-[#8B5CF6]"></span>Late Checkout</span>
                            <span class="font-mono">{{ $boardCounts['late'] }} ({{ $latePct }}%)</span>
                        </div>
                        <div class="h-1.5 bg-slate-100 rounded-full mt-1"><div class="h-1.5 bg-[#8B5CF6] rounded-full" style="width: {{ $latePct }}%"></div></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status filters -->
        <div class="bg-white rounded-xl border border-[#e8e2d6] shadow-sm p-4">
            <div class="font-black text-[13px] tracking-wide text-[#421A2B] uppercase mb-3">⚲ Status Filters</div>
            <div class="space-y-1.5 text-xs font-semibold">
                @php
                    $filterLink = fn($s) => route('dashboard', array_filter(['bstatus' => $s, 'btier' => ($btier ?? 'all') !== 'all' ? $btier : null]));
                    $filterRow = 'flex items-center justify-between px-3 py-2 rounded-lg border transition hover:border-[#421A2B]';
                @endphp
                <a href="{{ $filterLink('all') }}" class="{{ $filterRow }} {{ ($bstatus ?? 'all') === 'all' ? 'border-[#421A2B] text-[#421A2B]' : 'border-slate-200 text-slate-600' }}">
                    <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-slate-400"></span>All Statuses</span>
                    <span class="font-mono bg-slate-100 rounded-full px-2 py-0.5 text-[11px]">{{ $boardCounts['all'] }}</span>
                </a>
                <a href="{{ $filterLink('almost') }}" class="{{ $filterRow }} {{ ($bstatus ?? 'all') === 'almost' ? 'border-[#421A2B] text-[#421A2B]' : 'border-slate-200 text-slate-600' }}">
                    <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-amber-400"></span>⚠ Almost in Time</span>
                    <span class="font-mono bg-slate-100 rounded-full px-2 py-0.5 text-[11px]">{{ $boardCounts['almost'] }}</span>
                </a>
                <a href="{{ $filterLink('available') }}" class="{{ $filterRow }} {{ ($bstatus ?? 'all') === 'available' ? 'border-[#421A2B] text-[#421A2B]' : 'border-slate-200 text-slate-600' }}">
                    <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-[#10B981]"></span>Available</span>
                    <span class="font-mono bg-slate-100 rounded-full px-2 py-0.5 text-[11px]">{{ $boardCounts['available'] }}</span>
                </a>
                <a href="{{ $filterLink('occupied') }}" class="{{ $filterRow }} {{ ($bstatus ?? 'all') === 'occupied' ? 'border-[#421A2B] text-[#421A2B]' : 'border-slate-200 text-slate-600' }}">
                    <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-[#EF4444]"></span>Occupied</span>
                    <span class="font-mono bg-slate-100 rounded-full px-2 py-0.5 text-[11px]">{{ $boardCounts['occupied'] }}</span>
                </a>
                <a href="{{ $filterLink('late') }}" class="{{ $filterRow }} {{ ($bstatus ?? 'all') === 'late' ? 'border-[#421A2B] text-[#421A2B]' : 'border-slate-200 text-slate-600' }}">
                    <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-[#8B5CF6]"></span>Late Checkout</span>
                    <span class="font-mono bg-slate-100 rounded-full px-2 py-0.5 text-[11px]">{{ $boardCounts['late'] }}</span>
                </a>
            </div>
        </div>
    </aside>

    <!-- BOARD -->
    <section class="xl:col-span-9 space-y-3 min-w-0">

        <!-- Pending force checkout approvals -->
        @if(count($pendingForceCheckouts ?? []) > 0 && auth()->user()->can('review-financial'))
            <div class="bg-amber-50 border border-amber-400 rounded-xl p-3 text-xs">
                <div class="font-bold text-amber-900 mb-1">ATTENTION: Pending Force Checkout Requests ({{ count($pendingForceCheckouts) }})</div>
                <div class="space-y-1.5">
                    @foreach($pendingForceCheckouts as $pfc)
                        <div class="flex items-center justify-between bg-white p-2 border border-amber-300 rounded-lg">
                            <div>
                                <strong>Room {{ $pfc->room->number ?? 'N/A' }}</strong> &bull;
                                Guest: {{ $pfc->guest->name ?? 'Guest' }} &bull;
                                Unsettled Total: <strong class="font-mono text-red-700">₱{{ number_format($pfc->net_total, 2) }}</strong>
                            </div>
                            <a href="{{ route('approvals.index') }}" class="px-3 py-1 bg-rose-700 hover:bg-rose-800 text-white font-bold text-xs rounded">Review recorded request</a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Alarm banner (populated by SedonaAudioEngine) -->
        <div id="activeAlarmBanner" class="hidden bg-purple-900 border-2 border-purple-500 text-white p-3 shadow-lg rounded-xl">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center space-x-2 font-black text-xs md:text-sm">
                    <span class="text-base" id="alarmBannerIcon">⚠️</span>
                    <span id="alarmBannerTitle">AUDIBLE CHECKOUT ALARM: GUEST STAY OVERDUE DETECTED</span>
                </div>
                <button onclick="if(window.sedonaAudio) window.sedonaAudio.stopAllAlarms()" class="px-3 py-1 bg-purple-950 hover:bg-black text-white text-[11px] font-bold rounded border border-purple-400">Stop Alarm Sound</button>
            </div>
            <div id="alarmBannerRoomsList" class="space-y-1.5"></div>
        </div>

        <!-- Board header -->
        <div class="bg-white rounded-xl border border-[#e8e2d6] shadow-sm px-4 py-3 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="font-black text-base md:text-lg tracking-wide text-[#421A2B] uppercase">Frontdesk Apartment Occupancy Grid</h1>
                <p class="text-[10px] tracking-[0.18em] text-slate-400 uppercase mt-0.5">Interactive apartment board • Click any card to check in/out</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('dashboard', ['bstatus' => 'late']) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-bold text-slate-600 hover:border-[#421A2B] hover:text-[#421A2B]">
                    🔔 Alerts
                    @if(($boardCounts['late'] ?? 0) > 0)
                        <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-[#EF4444] text-white text-[10px] font-black">{{ $boardCounts['late'] }}</span>
                    @endif
                </a>
                <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-semibold text-slate-400 hover:border-[#421A2B] hover:text-[#421A2B]">⟳ Reset Board</a>
            </div>
        </div>

        <!-- Tier legend -->
        <div class="bg-white rounded-xl border border-[#e8e2d6] shadow-sm px-4 py-2.5 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs">
            <span class="font-black tracking-wide text-slate-700 uppercase text-[11px]">Apartment tiers:</span>
            <a href="{{ route('dashboard', array_filter(['bstatus' => ($bstatus ?? 'all') !== 'all' ? $bstatus : null, 'btier' => 'vip'])) }}" class="flex items-center gap-1.5 font-mono {{ ($btier ?? 'all') === 'vip' ? 'font-black text-[#5B21B6]' : 'text-slate-500' }}"><span class="w-2.5 h-2.5 rounded-full bg-[#8B5CF6]"></span>VIP room</a>
            <a href="{{ route('dashboard', array_filter(['bstatus' => ($bstatus ?? 'all') !== 'all' ? $bstatus : null, 'btier' => 'premium'])) }}" class="flex items-center gap-1.5 font-mono {{ ($btier ?? 'all') === 'premium' ? 'font-black text-blue-800' : 'text-slate-500' }}"><span class="w-2.5 h-2.5 rounded-full bg-[#3B82F6]"></span>Premium room</a>
            <a href="{{ route('dashboard', array_filter(['bstatus' => ($bstatus ?? 'all') !== 'all' ? $bstatus : null, 'btier' => 'classic'])) }}" class="flex items-center gap-1.5 font-mono {{ ($btier ?? 'all') === 'classic' ? 'font-black text-amber-800' : 'text-slate-500' }}"><span class="w-2.5 h-2.5 rounded-full bg-[#F59E0B]"></span>Classic room</a>
            @if(($btier ?? 'all') !== 'all' || ($bstatus ?? 'all') !== 'all')
                <a href="{{ route('dashboard') }}" class="text-[11px] font-bold text-[#421A2B] hover:underline">✕ Clear</a>
            @endif
            <span class="ml-auto font-mono text-slate-400 text-[11px] tracking-wider uppercase">Filtered apartments: <strong class="text-[#421A2B]">{{ count($boardRooms) }}</strong> / {{ $boardTotal }}</span>
        </div>

        <!-- Cards -->
        <div class="grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-5 gap-2.5">
            @forelse($boardRooms as $room)
                @php
                    $u = strtoupper($room->type ?? '');
                    $tierKey = str_contains($u, 'VIP') || str_contains($u, 'SUITE') ? 'vip' : (str_contains($u, 'PREMIUM') ? 'premium' : 'classic');
                    $tierLabel = $tierKey === 'vip' ? 'VIP room' : ($tierKey === 'premium' ? 'Premium room' : 'Classic room');
                    $tierPill = $tierKey === 'vip'
                        ? 'bg-[#F3E8FF] text-[#5B21B6] border-[#DDD6FE]'
                        : ($tierKey === 'premium' ? 'bg-blue-100 text-blue-800 border-blue-200' : 'bg-amber-100 text-amber-800 border-amber-200');
                    $folio = $room->activeFolio;
                    $isLate = $folio && $folio->isOverdue();
                @endphp

                @if($room->is_staff_quarters)
                    <div class="bg-indigo-50/60 rounded-xl border border-indigo-300 p-3 flex flex-col justify-between min-h-[168px]">
                        <div class="flex items-start justify-between gap-1">
                            <div>
                                <div class="font-mono font-black text-2xl text-indigo-950 leading-none">{{ $room->number }}</div>
                                <div class="text-[9px] tracking-widest text-slate-500 uppercase mt-1">Staff<br>Quarters</div>
                            </div>
                            <span class="text-[9px] font-black uppercase px-1.5 py-0.5 rounded border bg-indigo-700 text-white border-indigo-800">Staff</span>
                        </div>
                        <div class="text-xs font-bold text-indigo-950 mt-2">Housekeeping Staff</div>
                        <div class="text-[9px] tracking-widest text-slate-500 uppercase">Free housing • Staff</div>
                        <div class="mt-2 pt-2 border-t border-indigo-200/70 flex items-center justify-between">
                            <span class="text-[10px] font-mono font-bold text-indigo-700 uppercase">🧑‍🍳 Staff</span>
                            @php $staffFolio = $folio ?? $room->getOrCreateStaffFolio(); @endphp
                            <button onclick="openAddOnModal({{ $staffFolio->id }}, '{{ $room->number }} (Staff Quarters)')" class="text-[10px] font-bold text-indigo-700 bg-indigo-100 border border-indigo-200 rounded-full px-2 py-0.5">Quarters</button>
                        </div>
                    </div>
                @elseif($room->status === 'occupied' && $folio)
                    <a href="{{ route('checkout.process', $folio->id) }}" class="bg-[#fdf1f1] rounded-xl border border-rose-300 p-3 flex flex-col justify-between min-h-[168px] hover:shadow-md hover:border-rose-400 transition"
                       data-room-id="{{ $room->id }}" data-room-number="{{ $room->number }}" data-folio-id="{{ $folio->id }}" data-checkout-time="{{ $folio->expected_checkout_at->toIso8601String() }}" data-guest-name="{{ $folio->guest->name ?? 'Guest' }}" data-status="occupied" data-stay-mode="{{ $folio->isOpenTime() ? 'open' : 'fixed' }}" data-checkin-time="{{ $folio->checked_in_at->toIso8601String() }}" data-base-rate="{{ $folio->room_charge }}">
                        <div class="flex items-start justify-between gap-1">
                            <div>
                                <div class="font-mono font-black text-2xl text-slate-900 leading-none">{{ $room->number }}</div>
                                <div class="text-[9px] tracking-widest text-slate-500 uppercase mt-1">Apartment</div>
                            </div>
                            <span class="text-[9px] font-black uppercase px-1.5 py-0.5 rounded border {{ $tierPill }}">{{ $tierLabel }}</span>
                        </div>
                        <div class="text-xs font-bold text-slate-900 mt-2 truncate">{{ $folio->guest->name ?? 'Guest' }}</div>
                        <div class="text-[9px] tracking-widest text-slate-500 uppercase">{{ $room->type }} • {{ $folio->isOpenTime() ? 'Open Time · 3H base' : 'until '.$folio->expected_checkout_at->format('h:i A') }}</div>
                        <div class="stay-countdown-timer font-mono text-[10px] mt-1.5"></div>
                        <div class="mt-2 pt-2 border-t border-rose-200/70 flex items-center justify-between">
                            <span class="text-[10px] font-mono font-black text-rose-700 uppercase">● Occupied</span>
                            @if($isLate)
                                <span class="text-[10px] font-black text-[#5B21B6] bg-[#F3E8FF] border border-[#DDD6FE] rounded-full px-2 py-0.5">Late</span>
                            @else
                                <span class="text-[10px] font-bold text-rose-700 bg-rose-100 border border-rose-200 rounded-full px-2 py-0.5">In Use</span>
                            @endif
                        </div>
                    </a>
                @elseif($room->status === 'maintenance')
                    <div class="bg-slate-100 rounded-xl border border-slate-300 p-3 flex flex-col justify-between min-h-[168px] opacity-80">
                        <div class="flex items-start justify-between gap-1">
                            <div>
                                <div class="font-mono font-black text-2xl text-slate-500 leading-none">{{ $room->number }}</div>
                                <div class="text-[9px] tracking-widest text-slate-400 uppercase mt-1">Apartment</div>
                            </div>
                            <span class="text-[9px] font-black uppercase px-1.5 py-0.5 rounded border {{ $tierPill }}">{{ $tierLabel }}</span>
                        </div>
                        <div class="text-xs font-bold text-slate-500 mt-2">Service</div>
                        <div class="text-[9px] tracking-widest text-slate-400 uppercase">{{ $room->type }}</div>
                        <div class="mt-2 pt-2 border-t border-slate-200 flex items-center justify-between">
                            <span class="text-[10px] font-mono font-bold text-slate-500 uppercase">🔧 Service</span>
                            <span class="text-[10px] font-bold text-slate-500 bg-slate-200 border border-slate-300 rounded-full px-2 py-0.5">Offline</span>
                        </div>
                    </div>
                @else
                    <div onclick="openCheckInModal(this.dataset)" data-room-id="{{ $room->id }}" data-room-number="{{ $room->number }}" data-room-type="{{ $room->type }}" data-rate-3h="{{ $room->base_rate_3h }}" data-rate-4h="{{ $room->base_rate_4h }}" data-rate-6h="{{ $room->base_rate_6h }}" data-rate-12h="{{ $room->base_rate_12h }}" data-rate-24h="{{ $room->base_rate_24h }}" data-rate-promo="{{ $room->base_rate_promo }}"
                         class="bg-[#f2fbf4] rounded-xl border border-emerald-200 p-3 flex flex-col justify-between min-h-[168px] cursor-pointer hover:shadow-md hover:border-emerald-400 transition">
                        <div class="flex items-start justify-between gap-1">
                            <div>
                                <div class="font-mono font-black text-2xl text-slate-900 leading-none">{{ $room->number }}</div>
                                <div class="text-[9px] tracking-widest text-slate-500 uppercase mt-1">Apartment</div>
                            </div>
                            <span class="text-[9px] font-black uppercase px-1.5 py-0.5 rounded border {{ $tierPill }}">{{ $tierLabel }}</span>
                        </div>
                        <div class="text-xs font-bold text-slate-900 mt-2">Available</div>
                        <div class="text-[9px] tracking-widest text-slate-500 uppercase">{{ $room->type }}</div>
                        <div class="mt-2 pt-2 border-t border-emerald-200/60 flex items-center justify-between">
                            <span class="text-[10px] font-mono font-black text-emerald-700 uppercase">✓ Available</span>
                            <span class="text-[10px] font-bold text-emerald-700 bg-emerald-100/70 border border-emerald-200 rounded-full px-2 py-0.5">Ready</span>
                        </div>
                    </div>
                @endif
            @empty
                <div class="col-span-full bg-white rounded-xl border border-[#e8e2d6] p-8 text-center text-slate-400 text-sm">No apartments match this filter. <a href="{{ route('dashboard') }}" class="text-[#421A2B] font-bold hover:underline">Reset board</a></div>
            @endforelse
        </div>
    </section>
</div>
</div>

@include('dashboard.partials.folio-modals')
@endsection
