<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="6">
    <title>Kitchen TV Queue Display | Sedona Court PMS</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=JetBrains+Mono:wght@700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #0c0f17;
            color: #f8fafc;
            margin: 0;
            padding: 16px;
            overflow-x: hidden;
        }
        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }
        .tv-card {
            background-color: #171d29;
            border: 2px solid #283347;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
        }
    </style>
</head>
<body class="min-h-screen flex flex-col justify-between select-none">

    <!-- Top Big-Screen Banner -->
    <header class="bg-[#171d29] border border-[#283347] rounded-xl p-4 mb-4 flex flex-wrap items-center justify-between gap-4 shadow-lg">
        <div class="flex items-center space-x-4">
            <div class="w-12 h-12 bg-amber-600 rounded-lg flex items-center justify-center text-white text-2xl font-black shadow-md">
                🍳
            </div>
            <div>
                <div class="flex items-center space-x-2">
                    <h1 class="text-xl md:text-2xl font-black text-white tracking-tight uppercase">
                        Sedona Court &bull; Kitchen TV Queue
                    </h1>
                    <span class="px-2 py-0.5 rounded text-[11px] font-mono font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30 uppercase tracking-widest">
                        FIFO Live Queue
                    </span>
                </div>
                <p class="text-xs text-slate-400 font-mono">
                    Large-Display High-Visibility Monitor (40" - 65") &bull; Auto-Sync: 6s
                </p>
            </div>
        </div>

        <!-- TV Summary Badges -->
        <div class="flex items-center space-x-3 font-mono font-black text-xs md:text-sm">
            <div class="px-3.5 py-1.5 rounded-lg bg-slate-800 text-slate-200 border border-slate-700">
                Rooms in Queue: <span class="text-amber-400 text-base">{{ $totalRoomsInQueue }}</span>
            </div>
            <div class="px-3.5 py-1.5 rounded-lg bg-slate-800 text-slate-200 border border-slate-700">
                Pending Items: <span class="text-white text-base">{{ $totalItemsPending }}</span>
            </div>
            <div class="px-3.5 py-1.5 rounded-lg bg-amber-950/60 text-amber-300 border border-amber-800/80">
                New: <span class="text-amber-400 text-base">{{ $newCount }}</span>
            </div>
            <div class="px-3.5 py-1.5 rounded-lg bg-purple-950/60 text-purple-300 border border-purple-800/80">
                Prep: <span class="text-purple-300 text-base">{{ $prepCount }}</span>
            </div>
            <div class="px-3.5 py-1.5 rounded-lg bg-blue-950/60 text-blue-300 border border-blue-800/80">
                Ready: <span class="text-blue-300 text-base">{{ $readyCount }}</span>
            </div>
        </div>

        <!-- Real-Time Manila Clock -->
        <div class="text-right font-mono">
            <div class="text-2xl md:text-3xl font-black text-white" id="tv-clock">--:--:--</div>
            <div class="text-[11px] text-amber-400 font-semibold" id="tv-date">--</div>
        </div>
    </header>

    <!-- Main Queue Grid -->
    <main class="flex-1">
        @if($totalRoomsInQueue === 0)
            <div class="h-96 flex flex-col items-center justify-center text-center p-8 tv-card">
                <div class="text-5xl mb-3">✅</div>
                <h2 class="text-2xl font-black text-slate-200 uppercase tracking-wide">All Kitchen Orders Cleared</h2>
                <p class="text-sm text-slate-400 mt-1 font-mono">Kitchen display is idle. New room service and dining orders will automatically appear with sound chimes.</p>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($groupedByRoom as $roomLabel => $orders)
                    @php
                        $firstOrder = $orders->first();
                        $hasUrgent = $orders->contains(fn($o) => $o->priority === 'urgent');
                        $overallStatus = $orders->contains(fn($o) => $o->status === 'new') ? 'new' : ($orders->contains(fn($o) => $o->status === 'preparing') ? 'preparing' : 'ready');

                        $borderClass = match($overallStatus) {
                            'new' => 'border-amber-500 shadow-amber-500/10',
                            'preparing' => 'border-purple-500 shadow-purple-500/10',
                            'ready' => 'border-blue-500 shadow-blue-500/10',
                            default => 'border-slate-700',
                        };

                        $headerBg = match($overallStatus) {
                            'new' => 'bg-amber-950/40 text-amber-300 border-amber-600/50',
                            'preparing' => 'bg-purple-950/40 text-purple-300 border-purple-600/50',
                            'ready' => 'bg-blue-950/40 text-blue-300 border-blue-600/50',
                            default => 'bg-slate-800 text-slate-300 border-slate-700',
                        };
                    @endphp

                    <div class="tv-card border-2 {{ $borderClass }} flex flex-col justify-between overflow-hidden">
                        <!-- Room Header -->
                        <div class="p-3.5 border-b {{ $headerBg }} flex items-center justify-between">
                            <div>
                                <span class="text-2xl md:text-3xl font-black tracking-tight font-mono text-white block">
                                    {{ strtoupper($roomLabel) }}
                                </span>
                                <span class="text-[11px] font-mono text-slate-400">
                                    Guest: {{ $firstOrder->folio->guest->name ?? 'Walk-In Guest' }}
                                </span>
                            </div>
                            <div class="text-right font-mono">
                                <span class="px-2 py-0.5 text-xs font-black uppercase rounded {{ $overallStatus === 'new' ? 'bg-amber-500 text-black' : ($overallStatus === 'preparing' ? 'bg-purple-600 text-white' : 'bg-blue-600 text-white') }}">
                                    {{ $overallStatus }}
                                </span>
                                <span class="block text-[11px] text-slate-400 mt-1">
                                    {{ $firstOrder->created_at->diffForHumans(null, true) }} ago
                                </span>
                            </div>
                        </div>

                        <!-- Items List -->
                        <div class="p-4 space-y-3 flex-1 bg-[#121620]">
                            @foreach($orders as $ord)
                                <div class="space-y-1.5 pb-2 border-b border-slate-800 last:border-b-0">
                                    <div class="text-[10px] font-mono text-slate-400 flex justify-between">
                                        <span>Order #{{ $ord->transaction_id }}</span>
                                        <span>{{ $ord->created_at->format('h:i A') }}</span>
                                    </div>
                                    <ul class="space-y-2">
                                        @foreach($ord->items as $item)
                                            <li class="flex items-baseline justify-between text-sm md:text-base font-bold">
                                                <span class="text-slate-100 flex items-center gap-2">
                                                    <span class="font-mono text-amber-400 font-black text-lg w-7 inline-block">
                                                        {{ $item->quantity }}x
                                                    </span>
                                                    <span>{{ $item->item_name }}</span>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if($ord->special_instructions)
                                        <p class="text-xs text-amber-200">Instructions: {{ $ord->special_instructions }}</p>
                                    @endif
                                    <form action="{{ route('pos.order.status', $ord) }}" method="POST" class="pt-2">
                                        @csrf
                                        @if($ord->status === 'new')
                                            <input type="hidden" name="status" value="preparing">
                                            <button type="submit" class="w-full py-2.5 rounded bg-purple-700 hover:bg-purple-600 text-white font-mono font-bold text-xs uppercase tracking-wider transition">▶ Start Cooking</button>
                                        @elseif($ord->status === 'preparing')
                                            <input type="hidden" name="status" value="ready">
                                            <button type="submit" class="w-full py-2.5 rounded bg-blue-700 hover:bg-blue-600 text-white font-mono font-bold text-xs uppercase tracking-wider transition">✓ Mark Ready For Dispatch</button>
                                        @elseif($ord->status === 'ready')
                                            <input type="hidden" name="status" value="delivered">
                                            <button type="submit" class="w-full py-2.5 rounded bg-emerald-700 hover:bg-emerald-600 text-white font-mono font-bold text-xs uppercase tracking-wider transition">★ Completed &amp; Dispatched</button>
                                        @endif
                                    </form>
                                </div>
                            @endforeach
                        </div>

                    </div>
                @endforeach
            </div>
        @endif
    </main>

    <!-- Bottom TV Control Strip -->
    <footer class="mt-4 pt-3 border-t border-slate-800 text-[11px] font-mono text-slate-500 flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center space-x-3">
            <span class="text-emerald-400 font-bold">● TV Display Active</span>
            <span>&bull;</span>
            <a href="{{ route('kitchen.view') }}" class="text-slate-400 hover:text-white underline">
                Switch to Interactive Kitchen Staff Board
            </a>
        </div>
        <div class="flex items-center space-x-2">
            <button onclick="if(window.sedonaAudio) window.sedonaAudio.playKitchenChime()" class="px-2 py-0.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded text-[10px]">
                🔔 Chime Test
            </button>
            <span>Station: SCTI-KITCHEN-01</span>
        </div>
    </footer>

    <script>
        function updateClock() {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            const dateStr = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
            const clockEl = document.getElementById('tv-clock');
            const dateEl = document.getElementById('tv-date');
            if (clockEl) clockEl.textContent = timeStr;
            if (dateEl) dateEl.textContent = dateStr;
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Sound Chime Trigger on New Orders
        document.addEventListener('DOMContentLoaded', () => {
            const prevCount = parseInt(sessionStorage.getItem('scti_pending_orders_count') || '0', 10);
            const currentCount = {{ $pendingOrders->count() }};
            if (currentCount > prevCount && window.sedonaAudio) {
                window.sedonaAudio.playKitchenChime();
            }
            sessionStorage.setItem('scti_pending_orders_count', currentCount.toString());
        });
    </script>
</body>
</html>
