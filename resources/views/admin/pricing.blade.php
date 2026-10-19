@extends('layouts.app')

@section('title', 'Master Pricing & Catalog Editor')
@section('subtitle', 'System Configuration | Master Pricing & POS Item Maintenance')

@section('top_action')
    <a href="{{ route('dashboard') }}" class="bg-[#333333] hover:bg-[#222222] text-white text-[11px] font-bold px-3 py-1 rounded shadow-sm border border-[#666666]">
        &larr; Return to Dashboard
    </a>
@endsection

@section('content')
<div class="px-3 pt-2 space-y-4 max-w-7xl mx-auto">

    <!-- Header -->
    <div class="workspace-toolbar flex items-center justify-between">
        <div>
            <h1 class="font-bold text-slate-900 text-sm uppercase tracking-wide">Master Rates & Item Catalog Maintenance</h1>
            <p class="text-xs text-slate-500">Authorized administrators and executives can adjust room stay duration prices and menu catalog rates.</p>
        </div>
        <div class="text-xs font-mono text-slate-600">
            Overtime Rate: <strong class="text-[#421A2B]">&#8369;130.00 / hour</strong>
        </div>
    </div>

    <!-- Flash Messages -->
    @if(session('success'))
        <div class="bg-emerald-50 border border-emerald-300 text-emerald-800 text-xs font-bold px-4 py-2.5 rounded flex items-center space-x-2">
            <span>&#9989;</span><span>{{ session('success') }}</span>
        </div>
    @endif
    @if(session('error'))
        <div class="bg-rose-50 border border-rose-300 text-rose-800 text-xs font-bold px-4 py-2.5 rounded flex items-center space-x-2">
            <span>&#9888;&#65039;</span><span>{{ session('error') }}</span>
        </div>
    @endif
    @if($errors->any())
        <div class="bg-rose-50 border border-rose-300 text-rose-800 text-xs px-4 py-2.5 rounded">
            <strong>Validation Error:</strong>
            <ul class="mt-1 list-disc list-inside space-y-0.5">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Section 1: Room Rates by Tier -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-3">
            ROOM STAY RATES MAINTENANCE (32 ROOMS)
        </div>

        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead>
                    <tr>
                        <th>ROOM #</th>
                        <th>FLOOR</th>
                        <th>ROOM TYPE</th>
                        <th>3 HOURS</th>
                        <th>4 HOURS</th>
                        <th>6 HOURS</th>
                        <th>12 HOURS</th>
                        <th>24 HOURS</th>
                        <th>MIDNIGHT PROMO</th>
                        <th>MAINTENANCE TOGGLE</th>
                        <th>UPDATE</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rooms as $room)
                        <tr>
                            <td class="font-bold font-mono text-sm">{{ $room->number }}</td>
                            <td class="font-mono text-[11px] text-slate-500">Floor {{ $room->floor }}</td>
                            <td class="text-left pl-2 font-medium">{{ $room->type }}</td>

                            @if($room->is_staff_quarters)
                                <td colspan="6" class="bg-indigo-50 text-indigo-900 font-bold text-center">
                                    Permanent Employee Quarters (Non-billable)
                                </td>
                                <td>
                                    <span class="text-slate-400 text-[10px]">Staff Room</span>
                                </td>
                                <td>&#8212;</td>
                            @else
                                    <td>
                                        <input form="roomPricing{{ $room->id }}" type="number" step="0.01" name="base_rate_3h" value="{{ $room->base_rate_3h }}" class="form-control-hms font-mono text-right !w-20">
                                    </td>
                                    <td>
                                        <input form="roomPricing{{ $room->id }}" type="number" step="0.01" name="base_rate_4h" min="0" max="999999.99" required value="{{ $room->base_rate_4h }}" class="form-control-hms font-mono text-right !w-20">
                                    </td>
                                    <td>
                                        <input form="roomPricing{{ $room->id }}" type="number" step="0.01" name="base_rate_6h" value="{{ $room->base_rate_6h }}" class="form-control-hms font-mono text-right !w-20">
                                    </td>
                                    <td>
                                        <input form="roomPricing{{ $room->id }}" type="number" step="0.01" name="base_rate_12h" value="{{ $room->base_rate_12h }}" class="form-control-hms font-mono text-right !w-24">
                                    </td>
                                    <td>
                                        <input form="roomPricing{{ $room->id }}" type="number" step="0.01" name="base_rate_24h" value="{{ $room->base_rate_24h }}" class="form-control-hms font-mono text-right !w-24">
                                    </td>
                                    <td>
                                        <input form="roomPricing{{ $room->id }}" type="number" step="0.01" name="base_rate_promo" value="{{ $room->base_rate_promo }}" class="form-control-hms font-mono text-right !w-24">
                                    </td>
                                    <td>
                                        <button form="roomPricing{{ $room->id }}" type="submit" formaction="{{ route('rooms.maintenance.toggle', $room->id) }}" class="px-2 py-1 {{ $room->status === 'maintenance' ? 'bg-amber-600 text-white' : 'bg-slate-200 text-slate-700' }} text-[10px] font-bold rounded">
                                            {{ $room->status === 'maintenance' ? 'In Maintenance' : 'Set Out of Order' }}
                                        </button>
                                    </td>
                                    <td>
                                        <form id="roomPricing{{ $room->id }}" method="POST" action="{{ route('admin.pricing.update') }}">
                                            @csrf
                                            <input type="hidden" name="room_id" value="{{ $room->id }}">
                                        <button type="submit" class="px-3 py-1 bg-[#0284c7] hover:bg-[#0369a1] text-white text-[11px] font-bold rounded">
                                            Save Rate
                                        </button>
                                        </form>
                                    </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 2: POS Item Master Catalog -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-3 flex items-center justify-between">
            <span>F&amp;B, REFRESHMENT &amp; AMENITY MASTER CATALOG ({{ count($posItems) }} ITEMS)</span>
            <button type="button" id="openAddItemBtn"
                class="bg-emerald-700 hover:bg-emerald-800 text-white text-[11px] font-bold px-3 py-1.5 rounded shadow-sm flex items-center space-x-1">
                <span>+</span>
                <span>Add New Item</span>
            </button>
        </div>

        <div class="overflow-x-auto max-h-[500px] overflow-y-auto">
            <table class="hms-table">
                <thead class="sticky top-0 bg-slate-100">
                    <tr>
                        <th>CATEGORY</th>
                        <th>ITEM DESCRIPTION</th>
                        <th>UNIT PRICE (&#8369;)</th>
                        <th>AVAILABILITY</th>
                        <th>ACTION</th>
                        <th>REMOVE</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($posItems as $item)
                        <tr>
                            <td class="font-bold text-[11px] text-slate-600 text-left pl-3">{{ $item->category }}</td>
                            <td class="font-bold text-slate-900 text-left pl-3">{{ $item->name }}</td>

                                <td style="width: 140px;">
                                    <input form="itemPricing{{ $item->id }}" type="number" step="0.01" name="price" value="{{ $item->price }}" class="form-control-hms font-mono text-right !w-28 font-bold">
                                </td>
                                <td style="width: 120px;">
                                    <label class="flex items-center justify-center space-x-1 cursor-pointer">
                                        <input form="itemPricing{{ $item->id }}" type="hidden" name="is_available" value="0">
                                        <input form="itemPricing{{ $item->id }}" type="checkbox" name="is_available" value="1" {{ $item->getRawOriginal('is_available') ? 'checked' : '' }} class="rounded text-red-700">
                                        <span class="text-xs font-semibold">{{ $item->getRawOriginal('is_available') ? 'Active' : 'Disabled' }}</span>
                                    </label>
                                </td>
                                <td style="width: 100px;">
                                    <form id="itemPricing{{ $item->id }}" method="POST" action="{{ route('admin.pricing.update') }}">
                                    @csrf
                                    <input type="hidden" name="pos_item_id" value="{{ $item->id }}">
                                    <button type="submit" class="px-3 py-1 bg-[#0284c7] hover:bg-[#0369a1] text-white text-[11px] font-bold rounded">
                                        Update
                                    </button>
                                    </form>
                                </td>

                            <td style="width: 80px;">
                                <form method="POST" action="{{ route('admin.pricing.items.destroy', $item->id) }}"
                                      onsubmit="return confirm('Retire this item from the active catalog? Existing transaction history will be preserved.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-2 py-1 bg-rose-100 hover:bg-rose-200 text-rose-800 text-[10px] font-bold rounded border border-rose-200">
                                        Remove
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Add New Catalog Item Modal -->
<div id="addItemModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-2xl max-w-lg w-full border border-slate-300 overflow-hidden">

        <div class="bg-[#421A2B] text-white px-4 py-3 flex items-center justify-between font-bold text-xs uppercase tracking-wider">
            <span>Add New F&amp;B / Amenity Catalog Item</span>
            <button type="button" id="closeAddItemBtn" class="text-white hover:text-slate-200 text-xl leading-none">&times;</button>
        </div>

        <form method="POST" action="{{ route('admin.pricing.items.store') }}" class="p-4 space-y-3 text-xs">
            @csrf

            <div>
                <label class="block text-[11px] font-bold text-slate-700 mb-1">Item Name <span class="text-rose-600">*</span></label>
                <input type="text" name="name" required maxlength="120"
                    placeholder="e.g. San Miguel Pale Pilsen (330mL)"
                    value="{{ old('name') }}"
                    class="form-control-hms w-full">
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-700 mb-1">Category <span class="text-rose-600">*</span></label>
                <div class="flex space-x-2">
                    <select id="categorySelect" name="category" class="form-control-hms flex-1">
                        <option value="">&#8212; select existing &#8212;</option>
                        @foreach($posItems->pluck('category')->unique()->sort()->values() as $cat)
                            <option value="{{ $cat }}" {{ old('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                        @endforeach
                        <option value="__new__">+ New category...</option>
                    </select>
                    <input type="text" id="categoryNewInput"
                        placeholder="Type new category name"
                        class="form-control-hms flex-1 hidden">
                </div>
                <p class="text-[10px] text-slate-400 mt-0.5">Select an existing category, or choose "+ New category..." to create one.</p>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-700 mb-1">Unit Price (&#8369;) <span class="text-rose-600">*</span></label>
                <input type="number" name="price" step="0.01" min="0" required
                    placeholder="0.00" value="{{ old('price') }}"
                    class="form-control-hms font-mono w-40">
            </div>

            <div class="border border-slate-200 rounded p-3 space-y-2 bg-slate-50">
                <label class="flex items-center space-x-2 cursor-pointer">
                    <input type="checkbox" name="is_tracked" value="1" id="trackedChk"
                        {{ old('is_tracked') ? 'checked' : '' }}
                        class="rounded border-slate-300 text-[#421A2B]">
                    <span class="text-[11px] font-bold text-slate-700">Track Inventory Stock</span>
                </label>
                <p class="text-[10px] text-slate-400">Enable to monitor stock levels and trigger low-stock alerts.</p>

                <div id="stockFields" class="{{ old('is_tracked') ? '' : 'hidden' }} grid grid-cols-2 gap-3 pt-1">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-600 mb-1">Initial Stock Qty</label>
                        <input type="number" name="stock_quantity" min="0" value="{{ old('stock_quantity', 0) }}"
                            class="form-control-hms font-mono w-full">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-600 mb-1">Reorder Level</label>
                        <input type="number" name="reorder_level" min="0" value="{{ old('reorder_level', 5) }}"
                            class="form-control-hms font-mono w-full">
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <label class="flex items-center space-x-2 cursor-pointer">
                    <input type="hidden" name="is_available" value="0">
                    <input type="checkbox" name="is_available" value="1"
                        {{ old('is_available', '1') === '1' ? 'checked' : '' }}
                        class="rounded border-slate-300 text-[#421A2B]">
                    <span class="text-[11px] font-bold text-slate-700">Available (active in POS)</span>
                </label>

                <label class="flex items-center space-x-2 cursor-pointer">
                    <input type="checkbox" name="kitchen_hours_only" value="1"
                        {{ old('kitchen_hours_only') ? 'checked' : '' }}
                        class="rounded border-slate-300 text-[#421A2B]">
                    <span class="text-[11px] font-bold text-slate-700">Prepared in Kitchen (Available 24/7)</span>
                </label>
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t">
                <button type="button" id="cancelAddItemBtn"
                    class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold rounded text-xs">
                    Cancel
                </button>
                <button type="submit"
                    class="px-4 py-1.5 bg-emerald-700 hover:bg-emerald-800 text-white font-bold rounded text-xs shadow">
                    + Add to Catalog
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    var addItemModal   = document.getElementById('addItemModal');
    var openBtn        = document.getElementById('openAddItemBtn');
    var closeBtn       = document.getElementById('closeAddItemBtn');
    var cancelBtn      = document.getElementById('cancelAddItemBtn');
    var categorySelect = document.getElementById('categorySelect');
    var categoryNew    = document.getElementById('categoryNewInput');
    var trackedChk     = document.getElementById('trackedChk');
    var stockFields    = document.getElementById('stockFields');

    openBtn.addEventListener('click', function() { addItemModal.classList.remove('hidden'); });
    closeBtn.addEventListener('click', function() { addItemModal.classList.add('hidden'); });
    cancelBtn.addEventListener('click', function() { addItemModal.classList.add('hidden'); });

    addItemModal.addEventListener('click', function(e) {
        if (e.target === addItemModal) addItemModal.classList.add('hidden');
    });

    categorySelect.addEventListener('change', function() {
        if (this.value === '__new__') {
            categoryNew.classList.remove('hidden');
            categoryNew.name = 'category';
            this.name = '';
            categoryNew.focus();
        } else {
            categoryNew.classList.add('hidden');
            categoryNew.name = '';
            this.name = 'category';
        }
    });

    trackedChk.addEventListener('change', function() {
        stockFields.classList.toggle('hidden', !this.checked);
    });

    @if($errors->any() && old('name'))
        addItemModal.classList.remove('hidden');
    @endif
</script>
@endpush
@endsection
