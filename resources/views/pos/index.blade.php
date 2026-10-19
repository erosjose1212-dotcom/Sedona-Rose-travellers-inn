@extends('layouts.app')

@section('title', 'Point of Sale & Dining')
@section('subtitle', 'Point of Sale (POS) | Kitchen Dining, Refreshments & Amenity Sales')

@section('top_action')
    <a href="{{ route('kitchen.view') }}" class="bg-[#333333] hover:bg-[#222222] text-white text-[11px] font-bold px-3 py-1 rounded shadow-sm border border-[#666666]">
        Open Kitchen KDS Display &rarr;
    </a>
@endsection

@section('content')
<div class="pos-page px-3 pt-2 space-y-4 max-w-7xl mx-auto">

    <!-- Categories Filter Bar -->
    <div class="workspace-toolbar flex flex-wrap items-center justify-between gap-2 text-xs">
        <div class="font-bold text-slate-800 uppercase tracking-wide">
            Menu & Inventory Catalog:
        </div>
        <div class="flex items-center flex-wrap gap-1">
            <a href="{{ route('pos.index', ['category' => 'all']) }}" class="px-2.5 py-1 rounded text-xs font-bold {{ $selectedCategory === 'all' ? 'bg-[#421A2B] text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-300' }}">
                All Items
            </a>
            @foreach($categories as $cat)
                <a href="{{ route('pos.index', ['category' => $cat]) }}" class="px-2.5 py-1 rounded text-xs font-bold {{ $selectedCategory === $cat ? 'bg-[#421A2B] text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-300' }}">
                    {{ $cat }}
                </a>
            @endforeach
        </div>
    </div>

    <!-- Main Grid: Catalog + Order Cart Sidebar -->
    <div class="pos-grid">

        <!-- Left 8 Cols: Item Catalog Grid -->
        <div class="min-w-0">
            <div class="hms-card">
                <div class="hms-card-header border-b pb-2 mb-3 flex items-center justify-between">
                    <span>SELECT ITEMS TO ORDER ({{ count($posItems) }})</span>
                    <span class="text-xs text-slate-500 font-normal">Click "+ Add" to populate cart</span>
                </div>

                <div class="pos-catalog-grid max-h-[650px] overflow-y-auto pr-1">
                    @forelse($posItems as $item)
                        @php
                            $stock = $item->stock_quantity ?? 0;
                            $isOut = !$item->isOrderable();
                            $isStockOut = $item->is_tracked && $stock <= 0;
                            $isLow = $item->is_tracked && ($stock <= ($item->reorder_level ?? 5)) && !$isStockOut;
                        @endphp
                        <div class="pos-catalog-item flex flex-col justify-between {{ $isOut ? 'opacity-60' : '' }}">
                            <div>
                                <div class="flex items-start justify-between gap-1">
                                    <span class="px-1.5 py-0.5 bg-slate-200 text-slate-700 font-bold text-[9px] uppercase rounded">
                                        {{ $item->category }}
                                    </span>
                                    <div class="flex items-center space-x-1">
                                        @if($item->is_tracked)
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-bold {{ $isStockOut ? 'bg-rose-100 text-rose-800 border border-rose-300' : ($isLow ? 'bg-amber-100 text-amber-800 border border-amber-300' : 'bg-emerald-100 text-emerald-800 border border-emerald-300') }}" title="Stock on hand">
                                                Stock: {{ $stock }}
                                            </span>
                                        @endif
                                        @if($item->kitchen_hours_only)
                                            <span class="text-[10px] text-amber-800 font-semibold" title="Prepared in Hot Kitchen">Kitchen</span>
                                        @endif
                                    </div>
                                </div>
                                <h3 class="font-bold text-slate-900 text-xs mt-1.5 leading-snug">{{ $item->name }}</h3>
                            </div>

                            <div class="mt-3 pt-2 border-t border-slate-200 flex items-center justify-between">
                                <span class="font-mono text-sm font-black text-[#421A2B]">₱{{ number_format($item->price, 2) }}</span>
                                @if($isOut)
                                    <button disabled class="px-2.5 py-1 bg-slate-400 text-white font-bold text-xs rounded opacity-50 cursor-not-allowed">
                                        Unavailable
                                    </button>
                                @else
                                    <button type="button" onclick="addToCart(this.dataset.itemId, this.dataset.itemName, this.dataset.itemPrice)" data-item-id="{{ $item->id }}" data-item-name="{{ $item->name }}" data-item-price="{{ $item->price }}" class="px-3 py-1 bg-[#0284c7] hover:bg-[#0369a1] text-white font-bold text-xs rounded shadow-sm">
                                        + Add
                                    </button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="col-span-full py-8 text-center text-slate-400">
                            No menu items found for this category.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Right 4 Cols: Order Cart & Checkout -->
        <div class="lg:col-span-4">
            <div class="hms-card sticky top-2">
                <div class="hms-card-header border-b pb-2 mb-3 flex items-center justify-between">
                    <span>ORDER CART & DISPATCH</span>
                    <button onclick="clearCart()" class="text-xs text-rose-700 hover:underline font-bold">Clear All</button>
                </div>

                <form method="POST" action="{{ route('pos.order.store') }}" id="posOrderForm" class="space-y-3">
                    @csrf
                    <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    @include('shifts.override-reason')

                    <!-- Order Destination Type -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Order Destination:</label>
                        <select name="order_type" id="orderTypeSelect" onchange="toggleOrderDestination()" class="form-control-hms font-bold">
                            <option value="room_charge">Charge to Guest Room Folio</option>
                            <option value="walkin_pos">Direct Walk-In Sale (POS)</option>
                        </select>
                    </div>

                    <!-- Room Folio Dropdown -->
                    <div id="roomChargeSection">
                        <label class="block text-xs font-bold text-slate-700 mb-1">Select Occupied Room:</label>
                        <input type="hidden" name="folio_id" id="selectedFolioId" value="{{ $occupiedRooms->first()?->activeFolio?->id }}">
                        <select name="room_id" id="posRoomSelect" onchange="updateSelectedFolio()" class="form-control-hms font-bold">
                            @forelse($occupiedRooms as $occ)
                                <option value="{{ $occ->id }}" data-folio-id="{{ $occ->activeFolio?->id }}">
                                    Room {{ $occ->number }} &bull; {{ $occ->is_staff_quarters ? 'Permanent Employee Quarters (Staff Order)' : ($occ->activeFolio->guest->name ?? 'Guest') }}
                                </option>
                            @empty
                                <option value="" disabled selected>No occupied rooms currently active</option>
                            @endforelse
                        </select>
                    </div>

                    <!-- Walk-in POS Payment Section -->
                    <div id="walkinPosSection" class="hidden space-y-2">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Payment Method:</label>
                            <select name="payment_method" id="walkinPaymentMethod" disabled onchange="toggleWalkinPayment()" class="form-control-hms font-bold">
                                <option value="cash">Cash Payment</option>
                                <option value="gcash">GCash E-Wallet</option>
                            </select>
                        </div>
                        <div id="walkinCashFields">
                            <label for="walkinCashTendered" class="block text-xs font-bold text-slate-700 mb-1">Cash Received:</label>
                            <input type="number" name="cash_tendered" id="walkinCashTendered" min="0" step="0.01" disabled class="form-control-hms font-mono" placeholder="Enter amount received">
                        </div>
                        <div id="walkinGcashFields" class="hidden space-y-2">
                            <div>
                                <label for="walkinGcashAmount" class="block text-xs font-bold text-slate-700 mb-1">GCash Amount Received:</label>
                                <input type="number" name="gcash_amount" id="walkinGcashAmount" min="0" step="0.01" disabled class="form-control-hms font-mono" placeholder="Confirm amount received">
                            </div>
                            <div>
                                <label for="walkinGcashReference" class="block text-xs font-bold text-slate-700 mb-1">GCash Reference:</label>
                                <input type="text" name="gcash_reference" id="walkinGcashReference" maxlength="50" disabled class="form-control-hms font-mono" placeholder="Transaction reference">
                            </div>
                        </div>
                    </div>

                    <!-- Cart Line Items Container -->
                    <div class="space-y-1.5 max-h-48 overflow-y-auto pr-1 border border-slate-200 p-2 bg-slate-50" id="cartItemsContainer">
                        <div class="text-center py-6 text-slate-400 text-xs" id="emptyCartNotice">
                            Cart is empty.<br>Click items on the left to add.
                        </div>
                    </div>

                    <!-- Special Instructions -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Kitchen / Room Notes:</label>
                        <input type="text" name="special_instructions" placeholder="e.g. Extra sinangag rice, deliver to Room 7" class="form-control-hms">
                    </div>

                    <!-- Grand Total Display -->
                    <div class="p-2.5 bg-slate-100 border border-slate-300 flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-700 uppercase">Order Total:</span>
                        <span class="text-lg font-black text-[#421A2B] font-mono" id="cartGrandTotal">₱0.00</span>
                    </div>

                    <button type="submit" id="submitOrderBtn" disabled class="w-full py-2 bg-[#421A2B] hover:bg-[#341421] disabled:bg-slate-300 disabled:cursor-not-allowed text-white font-bold text-xs rounded shadow">
                        Place Order & Dispatch Ticket
                    </button>
                </form>
            </div>
        </div>

    </div>

    <!-- Active Orders Live Dispatch Tracker -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
            <span>RECENT POS ORDERS & KITCHEN DISPATCH STATUS</span>
        </div>

        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead>
                    <tr>
                        <th>ORDER ID</th>
                        <th>TARGET</th>
                        <th>ITEMS ORDERED</th>
                        <th>TOTAL</th>
                        <th>STATUS</th>
                        <th>ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentOrders as $ord)
                        <tr>
                            <td class="font-mono font-bold">{{ $ord->transaction_id }}</td>
                            <td class="font-bold">
                                @if($ord->room)
                                    Room {{ $ord->room->number }} ({{ $ord->folio->guest->name ?? 'Guest' }})
                                @else
                                    <span class="text-amber-800">Walk-in POS</span>
                                @endif
                            </td>
                            <td class="text-left pl-3 text-slate-700">
                                @foreach($ord->items as $it)
                                    <span class="inline-block mr-2">{{ $it->quantity }}x {{ $it->item_name }}</span>
                                @endforeach
                            </td>
                            <td class="font-mono font-bold">₱{{ number_format($ord->total, 2) }}</td>
                            <td>
                                @php
                                    $stBadge = match($ord->status) {
                                        'new'       => 'bg-amber-100 text-amber-900 border-amber-300',
                                        'preparing' => 'bg-purple-100 text-purple-900 border-purple-300',
                                        'ready'     => 'bg-blue-100 text-blue-900 border-blue-300',
                                        'delivered' => 'bg-emerald-100 text-emerald-900 border-emerald-300',
                                        default     => 'bg-slate-100 text-slate-800',
                                    };
                                @endphp
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase border {{ $stBadge }}">
                                    {{ $ord->status }}
                                </span>
                            </td>
                            <td>
                                @if($ord->status !== 'delivered')
                                    <form method="POST" action="{{ route('pos.order.status', $ord->id) }}" class="inline-block">
                                        @csrf
                                        @if($ord->status === 'new')
                                            <input type="hidden" name="status" value="preparing">
                                            <button type="submit" class="px-2 py-1 bg-purple-700 hover:bg-purple-800 text-white font-bold text-[10px] rounded">Cooking</button>
                                        @elseif($ord->status === 'preparing')
                                            <input type="hidden" name="status" value="ready">
                                            <button type="submit" class="px-2 py-1 bg-blue-700 hover:bg-blue-800 text-white font-bold text-[10px] rounded">Ready</button>
                                        @elseif($ord->status === 'ready')
                                            <input type="hidden" name="status" value="delivered">
                                            <button type="submit" class="px-2 py-1 bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-[10px] rounded">Delivered</button>
                                        @endif
                                    </form>
                                @else
                                    <span class="text-emerald-700 text-[11px] font-bold">✓ Completed</span>
                                @endif
                                @if($ord->type === 'room_charge' && $ord->isNew() && $ord->isWithinCancellationGrace() && $ord->folio?->isActive())
                                    <a href="{{ route('folios.orders.index', $ord->folio_id) }}" class="inline-block px-2 py-1 bg-rose-700 hover:bg-rose-800 text-white font-bold text-[10px] rounded mt-1">Cancel order</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-4 text-center text-slate-400">No recent orders recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

@push('scripts')
<script>
    let cart = {};

    function addToCart(itemId, name, price) {
        if (!cart[itemId]) {
            cart[itemId] = { id: itemId, name: name, price: parseFloat(price), qty: 1 };
        } else {
            cart[itemId].qty++;
        }
        renderCart();
    }

    function updateQty(itemId, delta) {
        if (!cart[itemId]) return;
        cart[itemId].qty += delta;
        if (cart[itemId].qty <= 0) {
            delete cart[itemId];
        }
        renderCart();
    }

    function clearCart() {
        cart = {};
        renderCart();
    }

    function renderCart() {
        const container = document.getElementById('cartItemsContainer');
        const totalEl = document.getElementById('cartGrandTotal');
        const submitBtn = document.getElementById('submitOrderBtn');
        const keys = Object.keys(cart);

        if (keys.length === 0) {
            container.innerHTML = `<div class="text-center py-6 text-slate-400 text-xs" id="emptyCartNotice">Cart is empty.<br>Click items on the left to add.</div>`;
            totalEl.textContent = '₱0.00';
            submitBtn.disabled = true;
            return;
        }

        container.replaceChildren();
        let grandTotal = 0;
        const appendText = (parent, tag, className, text) => {
            const element = document.createElement(tag);
            element.className = className;
            element.textContent = text;
            parent.appendChild(element);
            return element;
        };

        keys.forEach((k, idx) => {
            const item = cart[k];
            const subtotal = item.price * item.qty;
            grandTotal += subtotal;

            const row = appendText(container, 'div', 'flex items-center justify-between p-1.5 bg-white border border-slate-200 text-xs', '');
            const details = appendText(row, 'div', 'flex-1 pr-1 truncate', '');
            appendText(details, 'div', 'font-bold text-slate-900 truncate text-[11px]', item.name);
            appendText(details, 'div', 'text-[10px] text-slate-500 font-mono', `₱${item.price.toFixed(2)} each`);
            const controls = appendText(row, 'div', 'flex items-center space-x-1', '');
            const addQuantityButton = (label, delta) => {
                const button = appendText(controls, 'button', 'w-5 h-5 bg-slate-200 hover:bg-slate-300 text-slate-800 font-bold text-xs flex items-center justify-center rounded', label);
                button.type = 'button';
                button.addEventListener('click', () => updateQty(item.id, delta));
            };
            addQuantityButton('-', -1);
            appendText(controls, 'span', 'font-mono font-bold w-4 text-center text-xs', item.qty);
            addQuantityButton('+', 1);
            appendText(row, 'div', 'w-16 text-right font-mono font-bold text-[#421A2B] text-xs pl-1', `₱${subtotal.toFixed(2)}`);
            [['id', item.id], ['qty', item.qty]].forEach(([field, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `items[${idx}][${field}]`;
                input.value = value;
                row.appendChild(input);
            });
        });

        totalEl.textContent = '₱' + grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        submitBtn.disabled = false;
    }

    function toggleOrderDestination() {
        const val = document.getElementById('orderTypeSelect').value;
        const roomSec = document.getElementById('roomChargeSection');
        const walkinSec = document.getElementById('walkinPosSection');

        if (val === 'room_charge') {
            roomSec.classList.remove('hidden');
            walkinSec.classList.add('hidden');
        } else {
            roomSec.classList.add('hidden');
            walkinSec.classList.remove('hidden');
        }
        toggleWalkinPayment();
        updateSelectedFolio();
    }

    function updateSelectedFolio() {
        const input = document.getElementById('selectedFolioId');
        input.value = document.getElementById('posRoomSelect').selectedOptions[0]?.dataset.folioId ?? '';
        input.disabled = document.getElementById('orderTypeSelect').value !== 'room_charge';
    }

    function toggleWalkinPayment() {
        const isWalkin = document.getElementById('orderTypeSelect').value === 'walkin_pos';
        const method = document.getElementById('walkinPaymentMethod');
        const isCash = isWalkin && method.value === 'cash';
        const isGcash = isWalkin && method.value === 'gcash';
        method.disabled = !isWalkin;
        document.getElementById('walkinCashFields').classList.toggle('hidden', !isCash);
        document.getElementById('walkinGcashFields').classList.toggle('hidden', !isGcash);
        [
            ['walkinCashTendered', isCash],
            ['walkinGcashAmount', isGcash],
            ['walkinGcashReference', isGcash],
        ].forEach(([id, applicable]) => {
            const input = document.getElementById(id);
            input.disabled = !applicable;
            input.required = applicable;
        });
    }
</script>
@endpush
@endsection
