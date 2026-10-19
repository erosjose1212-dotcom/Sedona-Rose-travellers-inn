<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="20">
    <title>Sedona Court Travellers Inn - Live Room Availability</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@700;800&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #edf3ed;
            color: #1e293b;
            margin: 0;
            padding: 0;
            user-select: none;
            overflow: hidden;
            height: 100vh;
            width: 100vw;
        }
        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }
        .font-display {
            font-family: 'Playfair Display', serif;
        }
    </style>
</head>
<body class="flex flex-col justify-between p-3 box-border">

    <!-- Top Header & Available Count Text Banner -->
    <header class="bg-white/95 backdrop-blur-md rounded-2xl border border-[#cfe0d1] shadow-xs px-5 py-2.5 flex items-center justify-between gap-3 shrink-0">
        <!-- Hotel Identity -->
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-600 to-teal-800 flex items-center justify-center shadow-xs text-white text-xl font-black">
                🏨
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-lg md:text-xl font-display font-black tracking-tight text-slate-900 leading-tight uppercase">
                        SEDONA COURT
                    </h1>
                    <span class="text-[10px] font-mono font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200">
                        Lobby Display
                    </span>
                </div>
                <p class="text-[10px] text-slate-500 font-mono tracking-wider uppercase">
                    Traveller's Inn &bull; Live Room Availability Directory &bull; Station Reception
                </p>
            </div>
        </div>

        <!-- PROMINENT TEXT DISPLAY: Number of Available Rooms -->
        <div class="flex items-center gap-2.5 bg-[#e8f5eb] border-2 border-emerald-500/50 px-4 py-1.5 rounded-xl shadow-xs">
            <span class="w-3 h-3 rounded-full bg-emerald-500 animate-ping inline-block"></span>
            <div class="flex items-baseline gap-2">
                <span class="text-xs font-mono font-bold uppercase text-emerald-950 tracking-wider">
                    Available Rooms:
                </span>
                <span class="text-2xl font-black font-mono text-emerald-700 leading-none">
                    {{ $availableRooms }}
                </span>
                <span class="text-xs font-mono text-emerald-900 font-semibold">
                    / {{ $totalRooms }}
                </span>
            </div>
        </div>

        <!-- Manila Real-Time Digital Clock -->
        <div class="text-right font-mono">
            <div class="text-xl md:text-2xl font-black text-slate-900" id="display-clock">--:--:--</div>
            <div class="text-[10px] text-slate-500 uppercase tracking-wider font-semibold" id="display-date">--</div>
        </div>
    </header>

    <!-- 32-Room Single-Screen Matrix (8 Columns x 4 Rows) -->
    <main class="flex-1 my-2 grid grid-cols-8 gap-2 min-h-0">
        @foreach($rooms as $room)
            @php
                $isAvail = $room->status === 'available';
                $isOcc = $room->status === 'occupied';
                $isMaint = $room->status === 'maintenance';

                $cardBg = $isAvail 
                    ? 'bg-[#eef8f0] border-emerald-300 text-emerald-950 hover:border-emerald-500 shadow-xs' 
                    : ($isOcc ? 'bg-[#fcf0ef] border-rose-200 text-rose-950' : 'bg-slate-100 border-slate-300 text-slate-600');

                $tierBadge = match($room->tier ?? 'standard') {
                    'suite' => 'bg-purple-100 text-purple-800 border-purple-200',
                    'deluxe' => 'bg-blue-100 text-blue-800 border-blue-200',
                    default => 'bg-amber-100 text-amber-800 border-amber-200',
                };
            @endphp

            <div data-room-number="{{ $room->number }}" class="min-w-0 rounded-xl border p-2 flex flex-col justify-between transition-all duration-200 {{ $cardBg }}">
                <!-- Room Card Top -->
                <div class="flex items-center justify-between">
                    <span class="font-mono font-black text-lg md:text-xl tracking-tight leading-none">
                        {{ $room->number }}
                    </span>
                    <span class="text-[8px] font-mono font-extrabold uppercase px-1.5 py-0.5 rounded border tracking-wider {{ $tierBadge }}">
                        {{ $room->tier ? ucfirst($room->tier) : 'Std' }}
                    </span>
                </div>

                <!-- Room Type Label -->
                <div class="text-[10px] font-bold uppercase truncate text-slate-600 mt-0.5">
                    {{ strtoupper($room->type ?? 'Guest Room') }}
                </div>

                @if($isOcc && ! $room->is_staff_quarters)
                    @php($stay = $room->activeFolio)
                    <dl class="mt-1 space-y-1 text-[9px] leading-tight text-rose-900">
                        <div>
                            <dt class="text-[8px] font-bold uppercase text-rose-700">Check-in</dt>
                            <dd class="font-mono font-bold">{{ $stay?->checked_in_at?->copy()->setTimezone('Asia/Manila')->format('M d, h:i A') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[8px] font-bold uppercase text-rose-700">{{ $stay?->isOpenTime() ? 'Open Time · Base ends' : 'Expected checkout' }}</dt>
                            <dd class="font-mono font-bold">{{ $stay?->expected_checkout_at?->copy()->setTimezone('Asia/Manila')->format('M d, h:i A') ?? '—' }}</dd>
                        </div>
                    </dl>
                @endif

                <!-- Status Badge -->
                <div class="mt-1 pt-1 border-t border-slate-200/60 flex items-center justify-between">
                    @if($isAvail)
                        <span class="text-[9px] font-mono font-black text-emerald-700 uppercase tracking-wider flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            AVAILABLE
                        </span>
                        <span class="text-[9px] font-mono font-bold text-emerald-800">
                            Ready
                        </span>
                    @elseif($isOcc)
                        <span class="text-[9px] font-mono font-bold text-rose-700 uppercase tracking-wider flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                            OCCUPIED
                        </span>
                        <span class="text-[8px] font-mono text-rose-400">
                            In Use
                        </span>
                    @else
                        <span class="text-[9px] font-mono font-bold text-slate-500 uppercase tracking-wider">
                            SERVICE
                        </span>
                    @endif
                </div>
            </div>
        @endforeach
    </main>

    <!-- Bottom Rates & Directory Bar -->
    <footer class="bg-white/95 backdrop-blur-md rounded-xl border border-[#cfe0d1] shadow-xs px-4 py-2 flex flex-wrap items-center justify-between gap-3 text-xs shrink-0">
        <div class="flex items-center gap-4 text-[11px] font-mono">
            <span class="font-bold text-slate-800">Standard Rates:</span>
            <span class="text-slate-600">Short Stay (3h): <strong class="text-emerald-700">₱395</strong></span>
            <span class="text-slate-400">&bull;</span>
            <span class="text-slate-600">Half Day (12h): <strong class="text-emerald-700">₱1,195</strong></span>
            <span class="text-slate-400">&bull;</span>
            <span class="text-slate-600">Full Day (24h): <strong class="text-emerald-700">₱2,100</strong></span>
            <span class="text-slate-400">&bull;</span>
            <span class="text-slate-600">Excess Surcharge: <strong class="text-slate-700">₱130/hr</strong></span>
        </div>

        <div class="flex items-center gap-3 text-[11px] font-mono text-slate-500">
            <span>Doña Remedios Trinidad Hwy, San Rafael, Bulacan</span>
            <span>&bull;</span>
            <span class="font-bold text-slate-700">TEL: +63 (0939) 905-2816</span>
            <span>&bull;</span>
            <span class="text-emerald-600 font-bold">Live Sync: 20s</span>
        </div>
    </footer>

    <script>
        function updateDisplayClock() {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-US', {
                timeZone: 'Asia/Manila',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            });
            const dateStr = now.toLocaleDateString('en-US', {
                timeZone: 'Asia/Manila',
                weekday: 'short',
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
            const clockEl = document.getElementById('display-clock');
            const dateEl = document.getElementById('display-date');
            if (clockEl) clockEl.textContent = timeStr;
            if (dateEl) dateEl.textContent = dateStr;
        }
        setInterval(updateDisplayClock, 1000);
        updateDisplayClock();
    </script>
</body>
</html>
