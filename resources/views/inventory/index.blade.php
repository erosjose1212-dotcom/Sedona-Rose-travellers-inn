@extends('layouts.app')

@section('title', 'Pantry & POS Inventory Control')
@section('subtitle', 'Stock Deliveries, On-Hand Quantities & Low Stock Alerts')

@section('top_action')
    <div class="flex items-center space-x-2">
        @if($canAdjustStock)
        <button type="button" onclick="openRecountModal()" class="bg-[#0284c7] hover:bg-[#0369a1] text-white text-[11px] font-bold px-3 py-1.5 rounded shadow-sm flex items-center space-x-1">
            <span>📋</span>
            <span>Stock Recount</span>
        </button>
        @endif
        <a href="{{ route('inventory.export-csv') }}" class="bg-emerald-700 hover:bg-emerald-800 text-white text-[11px] font-bold px-3 py-1.5 rounded shadow-sm flex items-center space-x-1">
            <span>📥</span>
            <span>Export Movement CSV</span>
        </a>
    </div>
@endsection

@section('content')
<div class="px-3 pt-2 space-y-4 max-w-7xl mx-auto">
    @if(session('success'))<p role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</p>@endif
    @if($errors->any())<div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800"><p class="font-semibold">Stock was not saved.</p><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <!-- KPI Summary Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white p-3 border-l-4 border-slate-700 shadow-sm rounded">
            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 block">Catalog Pos Items</span>
            <div class="text-xl font-bold font-mono text-slate-900 mt-0.5">{{ $totalItems }}</div>
            <span class="text-[10px] text-slate-400">Total dining, minibar & beverage items</span>
        </div>

        <div class="bg-white p-3 border-l-4 border-emerald-600 shadow-sm rounded">
            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 block">Total Units In Stock</span>
            <div class="text-xl font-bold font-mono text-emerald-800 mt-0.5">{{ number_format($totalUnits) }}</div>
            <span class="text-[10px] text-slate-400">Tracked inventory units on hand</span>
        </div>

        <div class="bg-white p-3 border-l-4 {{ $lowStockCount > 0 ? 'border-amber-500 bg-amber-50/20' : 'border-slate-300' }} shadow-sm rounded">
            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 block">Low Stock / Reorder Needed</span>
            <div class="text-xl font-bold font-mono {{ $lowStockCount > 0 ? 'text-amber-700' : 'text-slate-700' }} mt-0.5">
                {{ $lowStockCount }}
            </div>
            <span class="text-[10px] {{ $lowStockCount > 0 ? 'text-amber-600 font-bold' : 'text-slate-400' }}">
                {{ $lowStockCount > 0 ? 'Action required: items at or below reorder level' : 'All items sufficiently stocked' }}
            </span>
        </div>

        <div class="workspace-toolbar rounded">
            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 block">Stock Availability</span>
            <div class="text-xs font-bold text-slate-800 mt-1">Updates after each sale</div>
            <span class="text-[10px] text-slate-400">Sold items are deducted from available stock</span>
        </div>
    </div>

    <!-- Inventory Filter & Search Bar -->
    <div class="hms-card">
        <form method="GET" action="{{ route('inventory.index') }}" class="flex flex-wrap items-center justify-between gap-3 text-xs">
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search item name..." class="form-control-hms w-48 text-xs">
                
                <select name="category" class="form-control-hms text-xs" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat }}" {{ request('category') === $cat ? 'selected' : '' }}>
                            {{ ucfirst(str_replace('_', ' ', $cat)) }}
                        </option>
                    @endforeach
                </select>

                <label class="flex items-center space-x-1.5 cursor-pointer ml-2 text-slate-700 font-bold">
                    <input type="checkbox" name="low_stock" value="1" {{ request('low_stock') ? 'checked' : '' }} onchange="this.form.submit()" class="rounded border-slate-300 text-[#421A2B] focus:ring-0">
                    <span>Show Low Stock Only</span>
                </label>
            </div>

            <div class="flex items-center space-x-2">
                @if(request()->hasAny(['search', 'category', 'low_stock']))
                    <a href="{{ route('inventory.index') }}" class="text-[11px] text-slate-500 hover:text-slate-800 underline">Clear Filters</a>
                @endif
                <button type="submit" class="px-3 py-1 bg-[#333333] hover:bg-[#222222] text-white font-bold rounded text-xs">
                    Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Main Stock Table -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
            <span>INVENTORY CATALOG & ON-HAND QUANTITIES</span>
            <span class="text-[11px] font-normal text-slate-500">{{ $items->count() }} items listed</span>
        </div>

        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead>
                    <tr>
                        <th>ITEM NAME</th>
                        <th>CATEGORY</th>
                        <th>UNIT PRICE</th>
                        <th>TRACKED</th>
                        <th>STOCK ON HAND</th>
                        <th>REORDER LVL</th>
                        <th>STATUS</th>
                        <th>QUICK ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        @php
                            $isLow = $item->is_tracked && ($item->stock_quantity <= $item->reorder_level);
                            $isOut = $item->is_tracked && ($item->stock_quantity <= 0);
                        @endphp
                        <tr class="{{ $isOut ? 'bg-rose-50/40' : ($isLow ? 'bg-amber-50/30' : '') }}">
                            <td class="font-bold text-slate-900 text-left pl-3">{{ $item->name }}</td>
                            <td class="text-left uppercase text-[10px] text-slate-600">{{ str_replace('_', ' ', $item->category) }}</td>
                            <td class="font-mono font-bold">₱{{ number_format($item->price, 2) }}</td>
                            <td>
                                @if($item->is_tracked)
                                    <span class="px-1.5 py-0.5 bg-slate-100 text-slate-700 text-[10px] font-bold rounded">Yes</span>
                                @else
                                    <span class="px-1.5 py-0.5 bg-slate-50 text-slate-400 text-[10px] rounded">Untracked</span>
                                @endif
                            </td>
                            <td>
                                @if($item->is_tracked)
                                    <span class="font-mono font-black text-sm px-2 py-0.5 rounded {{ $isOut ? 'bg-rose-600 text-white' : ($isLow ? 'bg-amber-100 text-amber-900 font-bold border border-amber-300' : 'bg-emerald-50 text-emerald-800') }}">
                                        {{ $item->stock_quantity }}
                                    </span>
                                    @if($item->requiresEgg())
                                        <span class="block text-[9px] text-amber-700 font-bold mt-0.5">🍳 Uses Egg</span>
                                    @elseif($item->isEggItem())
                                        <span class="block text-[9px] text-emerald-700 font-bold mt-0.5">🥚 Egg Inventory</span>
                                    @endif
                                @else
                                    <span class="text-slate-400 font-mono">—</span>
                                @endif
                            </td>
                            <td class="font-mono text-slate-600">{{ $item->is_tracked ? $item->reorder_level : '—' }}</td>
                            <td>
                                @if($item->is_available && !$isOut)
                                    <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 text-[10px] font-bold rounded uppercase">In Stock</span>
                                @else
                                    <span class="px-2 py-0.5 bg-rose-100 text-rose-800 text-[10px] font-bold rounded uppercase">Out of Stock</span>
                                @endif
                            </td>
                            <td>
                                <button type="button" 
                                    onclick="openStockModal(this.dataset.itemId, this.dataset.itemName, this.dataset.stockQuantity)" data-item-id="{{ $item->id }}" data-item-name="{{ $item->name }}" data-stock-quantity="{{ $item->stock_quantity ?? 0 }}"
                                    class="px-2.5 py-1 bg-slate-800 hover:bg-black text-white text-[10px] font-bold rounded shadow-sm">
                                    {{ $canAdjustStock ? '+ Restock / Adjust' : '+ Add Stock' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-6 text-center text-slate-400">No items matched your filter query.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Inventory Movements Ledger -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <span>AUDIT MOVEMENT LEDGER (LAST 40 TRANSACTIONS)</span>
                <span class="px-2 py-0.5 bg-slate-100 text-slate-700 text-[10px] font-bold rounded">Append-Only</span>
            </div>
            <a href="{{ route('inventory.export-csv') }}" class="text-[11px] text-[#421A2B] hover:underline font-bold">
                Export Full History &rarr;
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="hms-table text-xs">
                <thead>
                    <tr>
                        <th>TIME</th>
                        <th>ITEM NAME</th>
                        <th>EVENT TYPE</th>
                        <th>QTY CHANGE</th>
                        <th>BALANCE AFTER</th>
                        <th>REFERENCE</th>
                        <th>OPERATOR</th>
                        <th>NOTES</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentEvents as $ev)
                        <tr>
                            <td class="font-mono text-slate-500">{{ $ev->created_at->format('m/d H:i') }}</td>
                            <td class="font-bold text-slate-800 text-left pl-2">{{ $ev->item_name }}</td>
                            <td>
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase {{ in_array($ev->event_type, ['sold', 'walkin_pos', 'room_charge', 'quick_addon']) ? 'bg-sky-100 text-sky-800' : (in_array($ev->event_type, ['restock']) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800') }}">
                                    {{ str_replace('_', ' ', $ev->event_type) }}
                                </span>
                            </td>
                            <td class="font-mono font-bold {{ $ev->quantity_change > 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                {{ $ev->quantity_change > 0 ? "+{$ev->quantity_change}" : $ev->quantity_change }}
                            </td>
                            <td class="font-mono font-bold text-slate-900">{{ $ev->balance_after }}</td>
                            <td class="font-mono text-[11px] text-slate-600">{{ $ev->reference_id ?? '—' }}</td>
                            <td>{{ $ev->operator_name ?? 'Staff' }}</td>
                            <td class="text-left text-[11px] text-slate-500 max-w-xs truncate">{{ $ev->notes ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-4 text-center text-slate-400">No inventory movements recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal 1: Single Item Restock / Adjustment -->
<div id="stockModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-3">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full border border-slate-300 overflow-hidden">
        <div class="bg-slate-900 text-white px-4 py-2.5 flex items-center justify-between font-bold text-xs uppercase tracking-wider">
            <span id="stockModalTitle">Add Stock</span>
            <button type="button" onclick="closeStockModal()" class="text-white hover:text-slate-200 text-base leading-none">&times;</button>
        </div>

        <form id="stockForm" method="POST" action="" class="p-4 space-y-3 text-xs">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

            <div class="p-2.5 bg-slate-50 border rounded text-slate-700">
                Item: <strong id="stockModalItemName" class="text-slate-900"></strong><br>
                Current Recorded Count: <span id="stockModalCurrentCount" class="font-mono font-bold text-emerald-800"></span> units
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-700 mb-1"><span id="stockQuantityLabel">Units to Add</span>: <span class="text-rose-600">*</span></label>
                <input type="number" name="stock_quantity" id="stockModalQty" required min="1" max="100000" oninput="updateStockBalancePreview()" class="form-control-hms font-mono font-bold text-center text-base">
                <p id="stockQuantityHelp" class="mt-1 text-slate-500">Enter only the delivery quantity. Stock 3 + delivery 30 = 33. Stock is added immediately without approval.</p>
                <p class="mt-1 font-bold text-slate-700">Resulting balance: <span id="stockBalancePreview">—</span></p>
            </div>

            @if($canAdjustStock)
            <div>
                <label class="block text-[11px] font-bold text-slate-700 mb-1">Adjustment Reason: <span class="text-rose-600">*</span></label>
                <select name="reason" id="stockReason" required onchange="updateStockMode()" class="form-control-hms">
                    <option value="restock">New Stock Inflow (Restock)</option>
                    <option value="adjustment">Count Correction (Recount)</option>
                    <option value="damage_spoilage">Damage / Spoilage / Expired</option>
                    <option value="return">Returned Stock</option>
                </select>
            </div>
            @else
                <input type="hidden" name="reason" id="stockReason" value="restock">
            @endif

            <div>
                <label class="block text-[11px] font-bold text-slate-700 mb-1">Notes / Supplier Invoice #:</label>
                <input type="text" name="notes" placeholder="e.g. Received from San Miguel delivery or recount" class="form-control-hms">
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t">
                <button type="button" onclick="closeStockModal()" class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold rounded text-xs">
                    Cancel
                </button>
                <button type="submit" id="stockSubmitButton" class="px-4 py-1.5 bg-slate-900 hover:bg-black text-white font-bold rounded text-xs shadow">
                    Add Stock
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Admin/owner stock recount -->
@if($canAdjustStock)
<div id="recountModal" class="fixed inset-0 bg-black/60 z-50 hidden flex items-center justify-center p-3">
    <div class="bg-white rounded-lg shadow-2xl max-w-2xl w-full border border-slate-300 max-h-[90vh] flex flex-col overflow-hidden">
        <div class="bg-[#0284c7] text-white px-4 py-3 flex items-center justify-between font-bold text-xs uppercase tracking-wider">
            <div class="flex items-center space-x-2">
                <span>📋</span>
                <span>Front Desk Shift Inventory Audit & Recount</span>
            </div>
            <button type="button" onclick="closeRecountModal()" class="text-white hover:text-slate-200 text-base leading-none">&times;</button>
        </div>

        <form method="POST" action="{{ route('inventory.recount') }}" class="flex flex-col flex-1 overflow-hidden">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            
            <div class="p-3 bg-sky-50 border-b border-sky-100 text-sky-900 text-xs flex items-center justify-between">
                <span>Enter counted physical inventory in the minibar & front desk pantry. Unchanged fields remain as-is.</span>
                <span class="font-mono font-bold">{{ now()->format('m/d/Y h:i A') }}</span>
            </div>

            <div class="p-3 overflow-y-auto flex-1 space-y-2">
                <table class="w-full text-xs">
                    <thead class="bg-slate-100 text-slate-600 font-bold text-[10px] uppercase border-b">
                        <tr>
                            <th class="p-2 text-left">ITEM NAME</th>
                            <th class="p-2 text-left">CATEGORY</th>
                            <th class="p-2 text-center">SYSTEM QTY</th>
                            <th class="p-2 text-center w-28">PHYSICAL COUNT</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($items->where('is_tracked', true) as $it)
                            <tr>
                                <td class="p-2 font-bold text-slate-800">
                                    {{ $it->name }}
                                    @if($it->requiresEgg())
                                        <span class="text-[10px] text-amber-600 font-semibold block">(Uses 1 Egg)</span>
                                    @elseif($it->isEggItem())
                                        <span class="text-[10px] text-emerald-600 font-semibold block">(Egg Pantry Stock)</span>
                                    @endif
                                </td>
                                <td class="p-2 uppercase text-[10px] text-slate-500">{{ str_replace('_', ' ', $it->category) }}</td>
                                <td class="p-2 text-center font-mono font-bold text-slate-700">{{ $it->stock_quantity }}</td>
                                <td class="p-2 text-center">
                                    <input type="number" name="counts[{{ $it->id }}]" value="{{ $it->stock_quantity }}" min="0" max="10000" class="form-control-hms text-center font-mono font-bold w-20 py-0.5">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="pt-2">
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Stock Recount Audit Notes:</label>
                    <input type="text" name="notes" placeholder="e.g. End of Day Shift drawer and pantry verification" class="form-control-hms text-xs">
                </div>
            </div>

            <div class="p-3 bg-slate-100 border-t flex items-center justify-between">
                <span class="text-[11px] text-slate-500">All adjustments are logged to the immutable movement ledger.</span>
                <div class="flex items-center space-x-2">
                    <button type="button" onclick="closeRecountModal()" class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold rounded text-xs">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-1.5 bg-[#0284c7] hover:bg-[#0369a1] text-white font-bold rounded text-xs shadow">
                        Save Stock Recount
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
@endif

@push('scripts')
<script>
    function openStockModal(itemId, itemName, currentCount) {
        document.getElementById('stockModalTitle').textContent = `Stock: ${itemName}`;
        document.getElementById('stockModalItemName').textContent = itemName;
        document.getElementById('stockModalCurrentCount').textContent = currentCount;
        document.getElementById('stockReason').value = 'restock';
        updateStockMode();
        document.getElementById('stockForm').action = `/inventory/${itemId}/stock`;
        document.getElementById('stockModal').classList.remove('hidden');
    }

    function updateStockMode() {
        const adding = document.getElementById('stockReason').value === 'restock';
        const quantity = document.getElementById('stockModalQty');
        quantity.min = adding ? '1' : '0';
        quantity.value = adding ? '' : document.getElementById('stockModalCurrentCount').textContent;
        document.getElementById('stockQuantityLabel').textContent = adding ? 'Units to Add' : 'New Exact Physical Count';
        document.getElementById('stockQuantityHelp').textContent = adding ? 'Enter only the delivery quantity. Stock 3 + delivery 30 = 33. Added immediately without approval.' : 'Admin/owner adjustment: enter the final total on hand. This replaces the current count immediately.';
        document.getElementById('stockSubmitButton').textContent = adding ? 'Add Stock' : 'Save Stock Adjustment';
        updateStockBalancePreview();
    }

    function updateStockBalancePreview() {
        const input = document.getElementById('stockModalQty');
        const quantity = Number(input.value);
        const current = Number(document.getElementById('stockModalCurrentCount').textContent);
        const adding = document.getElementById('stockReason').value === 'restock';
        document.getElementById('stockBalancePreview').textContent = input.value !== '' && Number.isInteger(quantity) && quantity >= (adding ? 1 : 0) ? `${adding ? current + quantity : quantity} units` : '—';
    }

    function closeStockModal() {
        document.getElementById('stockModal').classList.add('hidden');
    }

    function openRecountModal() {
        document.getElementById('recountModal').classList.remove('hidden');
    }

    function closeRecountModal() {
        document.getElementById('recountModal').classList.add('hidden');
    }
</script>
@endpush
@endsection
