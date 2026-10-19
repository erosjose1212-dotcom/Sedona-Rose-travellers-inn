@extends('layouts.app')

@section('title', 'Kitchen Display System (KDS)')
@section('subtitle', 'Kitchen Display System (KDS) | Live Room Service Queue')

@section('top_action')
    <div class="flex items-center space-x-2 text-xs">
        <button onclick="if(window.sedonaAudio) window.sedonaAudio.playKitchenChime()" class="bg-amber-700 hover:bg-amber-800 text-white font-bold px-2.5 py-1 rounded text-xs">
            🔔 Test Kitchen Chime
        </button>
        <span class="text-slate-300">Auto Refresh: Active (15s)</span>
        <button onclick="window.location.reload()" class="bg-slate-700 hover:bg-slate-800 text-white font-bold px-3 py-1 rounded text-xs">
            ↻ Refresh Now
        </button>
    </div>
@endsection

@section('content')
<div class="px-3 pt-2 space-y-4">

    <!-- KDS Header Bar -->
    <div class="bg-white p-3 border-l-4 border-[#421A2B] shadow-sm flex flex-wrap items-center justify-between gap-3 text-xs">
        <div>
            <h1 class="font-bold text-slate-900 text-base">Kitchen Orders & Food Preparation Queue</h1>
            <p class="text-slate-500">Live dispatch feed for guest room service dining and walk-in kitchen favorites.</p>
        </div>
        <div class="flex items-center space-x-3 font-mono font-bold">
            <span class="px-2.5 py-1 bg-amber-100 text-amber-900 border border-amber-300 rounded">
                Pending: {{ $pendingOrders->where('status', 'new')->count() }}
            </span>
            <span class="px-2.5 py-1 bg-purple-100 text-purple-900 border border-purple-300 rounded">
                Cooking: {{ $pendingOrders->where('status', 'preparing')->count() }}
            </span>
            <span class="px-2.5 py-1 bg-blue-100 text-blue-900 border border-blue-300 rounded">
                Ready: {{ $pendingOrders->where('status', 'ready')->count() }}
            </span>
        </div>
    </div>

    <!-- Active Tickets Grid -->
    <div>
        <h2 class="font-bold text-xs text-white uppercase tracking-wider mb-2">
            Active Kitchen Tickets ({{ $pendingOrders->count() }})
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
            @forelse($pendingOrders as $order)
                @php
                    $cardBorder = match($order->status) {
                        'new'       => 'border-t-4 border-t-amber-500',
                        'preparing' => 'border-t-4 border-t-purple-600',
                        'ready'     => 'border-t-4 border-t-blue-600',
                        default     => 'border-t-4 border-t-slate-500',
                    };
                    $badgeStyle = match($order->status) {
                        'new'       => 'bg-amber-100 text-amber-900 border-amber-400',
                        'preparing' => 'bg-purple-100 text-purple-900 border-purple-400',
                        'ready'     => 'bg-blue-100 text-blue-900 border-blue-400',
                        default     => 'bg-slate-100 text-slate-900',
                    };
                @endphp

                <div class="bg-white shadow-md rounded-none border border-slate-300 {{ $cardBorder }} flex flex-col justify-between">
                    
                    <!-- Ticket Header -->
                    <div class="p-3 border-b border-slate-200 bg-slate-50">
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-bold text-slate-900 text-sm">
                                #{{ $order->transaction_id }}
                            </span>
                            <span class="px-2 py-0.5 text-[10px] font-bold uppercase border rounded {{ $badgeStyle }}">
                                {{ $order->status }}
                            </span>
                        </div>
                        
                        <div class="mt-1.5 flex items-center justify-between text-xs">
                            <div class="font-bold text-slate-800">
                                @if($order->room)
                                    <span class="text-[#421A2B] font-bold text-sm">ROOM {{ $order->room->number }}</span>
                                    <span class="block text-[11px] text-slate-500 font-normal">Guest: {{ $order->folio->guest->name ?? 'Guest' }}</span>
                                @else
                                    <span class="text-amber-800 font-bold">WALK-IN POS</span>
                                @endif
                            </div>
                            <div class="text-right text-[11px] font-mono text-slate-500">
                                <div>{{ $order->created_at->format('h:i A') }}</div>
                                <div class="text-[10px] font-semibold text-slate-700">({{ $order->created_at->diffForHumans(null, true) }} ago)</div>
                            </div>
                        </div>
                    </div>

                    <!-- Items List -->
                    <div class="p-3 space-y-2 flex-1">
                        <div class="text-[11px] font-bold uppercase text-slate-500 border-b pb-1">Ordered Items:</div>
                        <ul class="space-y-1.5 text-xs">
                            @foreach($order->items as $item)
                                <li class="flex items-start justify-between">
                                    <div class="font-semibold text-slate-900">
                                        <span class="inline-block w-5 font-mono font-bold text-[#421A2B]">{{ $item->quantity }}x</span>
                                        <span>{{ $item->item_name }}</span>
                                    </div>
                                    <span class="font-mono text-slate-500 text-[11px]">₱{{ number_format($item->subtotal, 2) }}</span>
                                </li>
                            @endforeach
                        </ul>

                        @if(!empty($order->special_instructions))
                            <div class="mt-2 p-1.5 bg-yellow-50 border border-yellow-300 text-[11px] text-yellow-900 font-medium">
                                <strong>Note:</strong> {{ $order->special_instructions }}
                            </div>
                        @endif
                    </div>

                    <!-- Action Bar -->
                    <div class="p-2.5 border-t border-slate-200 bg-slate-50">
                        <form method="POST" action="{{ route('pos.order.status', $order->id) }}">
                            @csrf
                            @if($order->status === 'new')
                                <input type="hidden" name="status" value="preparing">
                                <button type="submit" class="w-full py-1.5 bg-purple-700 hover:bg-purple-800 text-white font-bold text-xs rounded shadow">
                                    ▶ Start Cooking / Preparing
                                </button>
                            @elseif($order->status === 'preparing')
                                <input type="hidden" name="status" value="ready">
                                <button type="submit" class="w-full py-1.5 bg-blue-700 hover:bg-blue-800 text-white font-bold text-xs rounded shadow">
                                    ✓ Mark Food Ready for Delivery
                                </button>
                            @elseif($order->status === 'ready')
                                <input type="hidden" name="status" value="delivered">
                                <button type="submit" class="w-full py-1.5 bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-xs rounded shadow">
                                    ★ Mark Completed / Delivered
                                </button>
                            @endif
                        </form>
                    </div>

                </div>
            @empty
                <div class="col-span-full py-12 text-center text-slate-300 bg-white/10 border border-white/20">
                    <p class="text-sm font-semibold">No active kitchen orders pending right now.</p>
                    <p class="text-xs text-slate-400 mt-1">Incoming room service tickets will appear automatically.</p>
                </div>
            @endforelse
        </div>
    </div>

    <!-- Completed Today Summary Table -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
            <span>RECENTLY DELIVERED ORDERS TODAY ({{ count($completedToday) }})</span>
        </div>
        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead>
                    <tr>
                        <th>ORDER ID</th>
                        <th>DESTINATION</th>
                        <th>ITEMS</th>
                        <th>TOTAL AMOUNT</th>
                        <th>DISPATCHED TIME</th>
                        <th>STATUS</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($completedToday as $c)
                        <tr>
                            <td class="font-mono font-bold">{{ $c->transaction_id }}</td>
                            <td class="font-bold">{{ $c->room ? 'Room ' . $c->room->number : 'Walk-in POS' }}</td>
                            <td class="text-left pl-3">
                                @foreach($c->items as $it)
                                    <span class="inline-block mr-2">{{ $it->quantity }}x {{ $it->item_name }}</span>
                                @endforeach
                            </td>
                            <td class="font-mono font-bold">₱{{ number_format($c->total, 2) }}</td>
                            <td class="font-mono text-slate-600">{{ $c->updated_at->format('h:i A') }}</td>
                            <td><span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 font-bold rounded text-[10px]">DELIVERED</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-4 text-center text-slate-400">No completed orders yet today.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const currentNewOrders = {{ $pendingOrders->where('status', 'new')->count() }};
        const prevNewOrders = parseInt(sessionStorage.getItem('sedona_kds_new_orders') || '0', 10);
        if (currentNewOrders > 0 && currentNewOrders > prevNewOrders && window.sedonaAudio) {
            window.sedonaAudio.playKitchenChime();
        }
        sessionStorage.setItem('sedona_kds_new_orders', currentNewOrders.toString());
    });

    // Auto refresh KDS screen every 15 seconds to fetch new orders
    setTimeout(() => {
        window.location.reload();
    }, 15000);
</script>
@endpush
@endsection
