@extends('layouts.app')

@section('title', 'Guest Check Out Processing')
@section('subtitle', 'Check Out | Settle Guest Stay & Issue Official Receipt')

@section('top_action')
    @if(auth()->user()->hasAnyRole(['admin', 'owner']))
        <a href="{{ route('admin.check-in-times.index', ['folio_id' => $folio->id]) }}" class="bg-brand text-white text-[11px] font-bold px-3 py-1 rounded">Correct check-in time</a>
    @endif
    <a href="{{ route('folios.orders.index', $folio) }}" class="bg-sky-700 text-white text-[11px] font-bold px-3 py-1 rounded">Add-ons &amp; order history</a>
    <a href="{{ route('dashboard') }}" class="bg-[#333333] hover:bg-[#222222] text-white text-[11px] font-bold px-3 py-1 rounded shadow-sm border border-[#666666]">
        &larr; Back to Front Desk Matrix
    </a>
@endsection

@section('content')
<div class="hms-card mb-4 text-sm">Cashiers can save fixed Senior, PWD and DC discounts directly. <a href="{{ route('approvals.index', ['folio_id' => $folio->id]) }}" class="underline font-semibold">Request rate or custom discount overrides for Room {{ $folio->room->number }}</a>. Execute approved overrides, then reload checkout. Save late check-in corrections below to update the bill. Printing does not lock an active stay. @if(auth()->user()->hasAnyRole(['owner', 'admin']))<a href="{{ route('admin.discounts.index') }}" class="underline font-semibold">Manage discount defaults and guest corrections</a>.@endif</div>
    <div class="rounded-xl border p-5 mx-2 mb-4 text-sm {{ $drawerStatus['state'] === 'ready' ? 'border-emerald-200 bg-emerald-50' : 'border-amber-300 bg-amber-50' }}" role="status" id="checkoutDrawerStatus">
        <h2 class="font-bold text-brand">{{ $drawerStatus['title'] }}</h2>
        <p class="mt-1 text-slate-700">{{ $drawerStatus['message'] }}</p>
        @if($drawerStatus['state'] === 'no_shift' && auth()->user()->can('manage_shifts') && auth()->user()->can('record_payments'))
            <form method="POST" action="{{ route('shifts.open') }}" id="checkoutStartShiftForm" class="mt-3 flex flex-wrap items-end gap-3">
                @csrf
                <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                <input type="hidden" name="checkout_folio_id" value="{{ $folio->id }}">
                <label for="checkoutOpeningCash" class="text-xs font-semibold">Starting cash in drawer (₱)
                    <input id="checkoutOpeningCash" name="opening_float" type="number" required min="0" max="100000" step="0.01" value="{{ old('opening_float') }}" placeholder="0.00" class="form-control-hms block mt-1">
                </label>
                <button class="inline-flex items-center rounded-lg bg-brand hover:bg-brand-hover px-4 py-2 font-semibold text-white">Start cashier shift</button>
                <p class="basis-full text-xs text-slate-600">Enter the money physically in the drawer now. Use 0 if it is empty. Start once before taking payments.</p>
            </form>
        @elseif($drawerStatus['state'] !== 'ready' && auth()->user()->can('manage_shifts'))
            <a href="{{ route('shifts.index') }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-lg bg-brand hover:bg-brand-hover px-4 py-2 mt-3 font-semibold text-white">{{ $drawerStatus['state'] === 'handover' ? 'Review drawer handover' : 'Review drawer status' }} <span class="text-xs">(new tab)</span></a>
            <p class="mt-2 text-xs text-slate-600">After resolving the drawer status, reload checkout. Entered payment details are saved as a draft when you leave a payment field.</p>
        @endif
    </div>

<div class="checkout-page px-3 pt-2 max-w-6xl mx-auto">

    @if(auth()->user()->hasAnyRole(['cashier', 'front_desk', 'admin', 'owner']) && ! $room->is_staff_quarters)
        <section class="hms-card mb-4">
            <h2 class="font-bold text-brand">Correct a late check-in</h2>
                <p class="mt-2 text-sm text-slate-600">Enter when the guest actually arrived. Changes apply only after you click Save check-in time. Checkout will reload with the saved time and updated bill.</p>
                <form method="POST" action="{{ route('checkout.check-in-time', $folio) }}" id="checkoutCheckInTimeForm" class="mt-3 flex flex-wrap items-end gap-3">
                    @csrf
                    <input type="hidden" name="operation_key" value="{{ old('actual_checked_in_at') ? old('operation_key', (string) \Illuminate\Support\Str::uuid()) : (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="expected_checked_in_at" value="{{ $folio->checked_in_at->toIso8601String() }}">
                    <label class="min-w-0 max-w-full text-sm font-semibold">Actual check-in time (hotel time)
                        <input type="datetime-local" name="actual_checked_in_at" required max="{{ now('Asia/Manila')->format('Y-m-d\TH:i') }}" value="{{ old('actual_checked_in_at', $folio->checked_in_at->copy()->setTimezone('Asia/Manila')->format('Y-m-d\TH:i')) }}" class="form-control-hms block mt-1">
                    </label>
                    <label class="min-w-0 grow text-sm font-semibold">Reason for correction
                        <input type="text" name="reason" required maxlength="500" value="{{ old('actual_checked_in_at') ? old('reason') : '' }}" placeholder="Guest arrived before the check-in was recorded" class="form-control-hms block mt-1">
                    </label>
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-hover">Save check-in time</button>
                </form>
        </section>
    @endif

    @can('record_payments')
    <form method="POST" action="{{ route('folios.deposit.collect', $folio) }}" class="hms-card mb-4" onsubmit="return confirm('Confirm you have received this deposit from the guest?')">
        @csrf
        <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
        <h2 class="font-bold text-brand mb-2">Record deposit received</h2>
        <p class="text-sm text-slate-600 mb-3">Record actual money received. This adds to the deposit already held.</p>
        <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm">Amount received<input name="amount" type="number" min="0.01" max="100000" step="0.01" required class="form-control-hms block"></label>
            <label class="text-sm">Method<select name="payment_method" class="form-control-hms block"><option value="cash">Cash</option><option value="gcash">GCash</option></select></label>
            <label class="text-sm">GCash reference (required for GCash)<input name="gcash_reference" maxlength="50" class="form-control-hms block"></label>
            <button class="btn-hms-primary disabled:cursor-not-allowed disabled:opacity-50" @disabled($drawerStatus['state'] !== 'ready')>Record deposit received</button>
        </div>
        @include('shifts.override-reason')
    </form>
    @endcan

    <div class="hms-card">
        <div class="flex items-center justify-between border-b pb-2 mb-3">
            <h2 class="text-[#421A2B] text-base font-bold uppercase tracking-wide flex items-center space-x-2">
                <span>Official Guest Check Out &amp; Settlement</span>
            </h2>
            <div class="text-xs font-mono text-slate-600">
                Folio ID: <strong class="text-[#421A2B]">{{ $folio->transaction_id }}</strong>
            </div>
        </div>

        <form method="POST" action="{{ route('checkout.submit', $folio->id) }}" id="checkoutProcessForm">
            @csrf
            <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            @include('shifts.override-reason')

            @if($errors->any())
                <div role="alert" class="mb-3 border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800">
                    <strong>{{ old('actual_checked_in_at') ? 'Check-in time could not be saved.' : 'Checkout could not be completed.' }}</strong>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Top Stay Details 3-Column Grid (Tamper-Proof Readouts) -->
            <div class="checkout-details mb-3 text-xs bg-slate-50 p-3 border border-slate-200">
                
                <!-- Col 1: Room Number, Check In Time, Room Rate -->
                <div class="space-y-2">
                    <div class="flex items-center">
                        <label class="w-32 font-bold text-slate-700 text-[11px]">ROOM NUMBER:</label>
                        <input type="text" value="{{ $room->number ?? 'N/A' }} ({{ $room->type ?? '' }})" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms !text-left font-bold text-xs">
                    </div>

                    <div class="flex items-center">
                        <label class="w-32 font-bold text-slate-700 text-[11px]">CHECK IN TIME:</label>
                        <input type="text" 
                               value="{{ $checkInTime->format('m/d/Y h:i A') }}" 
                               readonly 
                               tabindex="-1" 
                               style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" 
                               class="form-control-hms !text-left text-[11px] font-mono font-bold text-slate-800"
                               title="Guest check-in time recorded in system">
                        <input type="hidden" name="checked_in_at" readonly id="inpCheckInTime" value="{{ $checkInTime->toIso8601String() }}">
                    </div>

                    <div class="flex items-center">
                        <label class="w-32 font-bold text-slate-700 text-[11px]">BASE ROOM RATE:</label>
                        <div class="flex items-center w-full">
                            <span class="px-2 py-1 bg-slate-100 border border-r-0 border-slate-300 text-slate-600 font-bold font-mono text-xs select-none">₱</span>
                            <input type="number" 
                                   step="0.01" 
                                   min="0" 
                                   name="room_charge" readonly
                                   id="inpRoomRate" 
                                   value="{{ number_format($roomRate, 2, '.', '') }}" 
                                   oninput="onRoomRateChange(this.value)" 
                                   class="form-control-hms font-mono font-bold text-slate-900 !w-full text-left bg-white border-slate-300 focus:border-sky-500 focus:ring-1 focus:ring-sky-500 shadow-inner" 
                                   title="Recorded room base rate; request changes through Approvals">
                        </div>
                    </div>
                </div>

                <!-- Col 2: Customer Name, Check Out Time, Add. Hours Rate -->
                <div class="space-y-2">
                    <div class="flex items-center">
                        <label class="w-36 font-bold text-slate-700 text-[11px]">CUSTOMER NAME:</label>
                        <input type="text" value="{{ $guest->name ?? '' }}" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms text-xs font-semibold">
                    </div>

                    <div class="flex items-center">
                        <label class="w-36 font-bold text-slate-700 text-[11px]">CHECK OUT TIME:</label>
                        <input type="text" id="checkoutTimeDisplay" value="{{ $checkOutTime->format('m/d/Y h:i A') }}" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms !text-left text-[11px] font-mono">
                    </div>

                    <div class="flex items-center">
                        <label class="w-36 font-bold text-slate-700 text-[11px]">ADD. HOURS RATE:</label>
                        <input type="text" id="inpAddHoursRate" value="₱{{ number_format($addHoursRate, 2) }}" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms font-mono text-purple-700 font-bold">
                    </div>
                </div>

                <!-- Col 3: Booked Stay, Xtend, Actual Stay Diff, Excess Overtime -->
                <div class="space-y-2">
                    <div class="flex items-center">
                        <label class="w-44 font-bold text-slate-700 text-[11px]">BOOKED STAY:</label>
                        <input type="text" value="{{ $folio->isOpenTime() ? 'Open Time (3-hour base)' : ($folio->rate_tier === 'promo' ? 'Midnight promo (6 AM checkout)' : $tierHours.' Hours ('.strtoupper($folio->rate_tier).')') }}" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms font-mono">
                    </div>

                    <div class="flex items-center">
                        <label class="w-44 font-bold text-sky-800 text-[11px]">XTEND / EXTENSIONS:</label>
                        <input type="text" value="+{{ $xtendHours }} Hours (₱{{ number_format($xtendHours * 130, 2) }})" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms font-mono font-bold text-sky-800">
                    </div>

                    <div class="flex items-center">
                        <label class="w-44 font-bold text-slate-700 text-[11px]">ACTUAL STAY DIFF:</label>
                        <input type="text" id="inpActualStayDiff" value="{{ $actualHoursDiff }} Hours" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms font-mono">
                    </div>

                    <div class="flex items-center">
                        <label class="w-44 font-bold text-slate-700 text-[11px]">{{ $folio->isOpenTime() ? 'ADDITIONAL HOURS:' : 'EXCESS OVERTIME:' }}</label>
                        <input type="text" id="inpExcessOvertime" value="{{ $excessHours }} Hours" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms font-mono font-bold text-purple-700">
                    </div>
                </div>
            </div>

            <!-- SECURITY DEPOSIT LEDGER SECTION -->
            <div class="section-bar-grey flex items-center justify-between">
                <span>SECURITY DEPOSIT LEDGER &amp; TRUST ACCOUNT</span>
                <span class="text-[10px] font-normal text-slate-300">Folio Ref: DEP-{{ $folio->transaction_id }}</span>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 py-2.5 px-3 mb-3 text-xs bg-slate-100 border border-slate-300">
                <div class="flex flex-wrap items-center gap-3">
                    <!-- Deposit Input -->
                    <div class="flex items-center space-x-2">
                        <label for="inpDeposit" class="font-bold text-slate-800 text-[11px]">GUEST DEPOSIT RECEIVED (IN TRUST):</label>
                        <div class="relative inline-flex items-center">
                            <span class="absolute left-2.5 text-slate-500 font-bold font-mono text-sm pointer-events-none select-none">₱</span>
                            <input type="number" 
                                   step="0.01" 
                                   min="0" 
                                   max="100000" 
                                   readonly
                                   id="inpDeposit" 
                                   value="{{ number_format($deposit, 2, '.', '') }}" 
                                   placeholder="0.00" 
                                   class="form-control-hms font-mono font-bold text-emerald-800 !w-32 pl-6 text-center bg-white border-slate-300 focus:border-emerald-600 focus:ring-1 focus:ring-emerald-500 shadow-inner" 
                                   title="Deposit confirmed as received; use Record deposit received to add money">
                        </div>
                    </div>

                    <!-- Option to Apply or Not Apply Deposit -->
                    <div class="flex items-center space-x-2">
                        <input type="hidden" name="apply_deposit" value="0">
                        <label class="inline-flex items-center space-x-1.5 cursor-pointer bg-white px-2.5 py-1 rounded border border-slate-300 shadow-sm hover:bg-slate-50 select-none">
                            <input type="checkbox" 
                                   id="chkApplyDeposit" 
                                   name="apply_deposit" 
                                   value="1" 
                                   {{ $applyDeposit ? 'checked' : '' }} 
                                   onchange="onApplyDepositToggle(this.checked)" 
                                   class="rounded text-emerald-600 focus:ring-emerald-500 w-4 h-4 cursor-pointer">
                            <span id="lblApplyDeposit" class="font-bold text-[11px] {{ $applyDeposit ? 'text-emerald-800' : 'text-slate-600' }}">
                                {{ $applyDeposit ? '✓ Applied to Bill' : '✕ Do Not Apply' }}
                            </span>
                        </label>
                    </div>

                    <!-- Remaining Balance of Deposit After Deduction of Gross Sub-Total -->
                    <div class="flex items-center space-x-2 bg-emerald-50 px-2.5 py-1 rounded border border-emerald-300" id="boxRemainingDeposit">
                        <label class="font-bold text-emerald-900 text-[11px]">REMAINING DEPOSIT BALANCE:</label>
                        <input type="text" 
                               id="inpRemainingDeposit" 
                               value="₱{{ number_format($remainingDeposit, 2) }}" 
                               readonly tabindex="-1" 
                               style="pointer-events: none; background-color: #fff; user-select: none;" 
                               class="form-control-hms font-mono font-bold text-emerald-800 !w-28 text-center text-xs border-emerald-400">
                        <span id="lblDepositExplanation" class="text-[10px] font-bold text-emerald-700">
                            {{ $remainingDeposit > 0 ? '(Refund to Guest)' : ($deposit > 0 ? '(Fully Absorbed)' : '(No Deposit)') }}
                        </span>
                    </div>
                </div>

                <div class="flex items-center space-x-2">
                    <a href="{{ route('folios.deposit_slip', $folio->id) }}?deposit={{ $deposit }}" id="depositSlipTopLink" target="_blank" class="px-2.5 py-1 bg-slate-700 hover:bg-slate-800 text-white font-bold text-[11px] rounded flex items-center space-x-1 shadow-sm">
                        <span>💰</span>
                        <span>Print Official Deposit Slip</span>
                    </a>
                </div>
            </div>

            <!-- Bottom Two Columns Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <!-- LEFT COLUMN: ADD ONS & ITEMIZED CHARGES -->
                <div>
                    <div class="section-bar-grey">ROOM SERVICE, EXTENSION &amp; ADD ON CHARGES</div>

                    <div class="border border-slate-300 mb-2 overflow-x-auto max-h-60 overflow-y-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-100 text-slate-700 font-bold text-[11px] border-b">
                                <tr>
                                    <th class="p-1.5 border-r">Item / Service Description</th>
                                    <th class="p-1.5 border-r text-center">Qty</th>
                                    <th class="p-1.5 border-r text-right">Price</th>
                                    <th class="p-1.5 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Room Base Stay -->
                                <tr class="border-b bg-slate-50/50">
                                    <td class="p-1.5 border-r font-medium">Room Lodging Base Charge ({{ strtoupper($folio->rate_tier) }} Tier)</td>
                                    <td class="p-1.5 border-r text-center font-mono">1</td>
                                    <td class="p-1.5 border-r text-right font-mono" id="tdRoomRatePrice">{{ number_format($roomRate, 2) }}</td>
                                    <td class="p-1.5 text-right font-mono font-bold" id="tdRoomRateAmount">{{ number_format($roomRate, 2) }}</td>
                                </tr>

                                <!-- Xtend Stay Extension if applicable -->
                                @if($xtendHours > 0)
                                    <tr class="border-b bg-sky-50/50">
                                        <td class="p-1.5 border-r font-medium text-sky-900">Stay Extension (+{{ $xtendHours }}h Xtend @ ₱130.00/hr)</td>
                                        <td class="p-1.5 border-r text-center font-mono">{{ $xtendHours }}</td>
                                        <td class="p-1.5 border-r text-right font-mono">130.00</td>
                                        <td class="p-1.5 text-right font-mono font-bold text-sky-800">{{ number_format($xtendHours * 130, 2) }}</td>
                                    </tr>
                                @endif

                                <!-- Excess Overtime if applicable -->
                                <tr class="border-b bg-purple-50/50" id="rowExcessOvertimeItem" style="{{ $excessHours > 0 ? '' : 'display: none;' }}">
                                    <td class="p-1.5 border-r font-medium text-purple-900" id="tdExcessHoursLabel">{{ $folio->isOpenTime() ? 'Additional Hours' : 'Excess Overtime' }} (+<span id="tdExcessHoursQtyText">{{ $excessHours }}</span>h @ ₱130.00/hr)</td>
                                    <td class="p-1.5 border-r text-center font-mono" id="tdExcessHoursQty">{{ $excessHours }}</td>
                                    <td class="p-1.5 border-r text-right font-mono">130.00</td>
                                    <td class="p-1.5 text-right font-mono font-bold text-purple-800" id="tdExcessHoursAmount">{{ number_format($excessHours * 130, 2) }}</td>
                                </tr>

                                <!-- POS / Kitchen / Amenity Items -->
                                @forelse($addOnItems as $addOn)
                                    <tr class="border-b hover:bg-slate-50">
                                        <td class="p-1.5 border-r font-medium">{{ $addOn['name'] }}</td>
                                        <td class="p-1.5 border-r text-center font-mono">{{ $addOn['qty'] }}</td>
                                        <td class="p-1.5 border-r text-right font-mono">{{ number_format($addOn['price'], 2) }}</td>
                                        <td class="p-1.5 text-right font-mono font-bold">{{ number_format($addOn['amount'], 2) }}</td>
                                    </tr>
                                @empty
                                    @if($xtendHours == 0 && $excessHours == 0)
                                        <tr>
                                            <td colspan="4" class="p-3 text-slate-400 text-center text-xs">
                                                No food, drink, or amenity add-ons billed to this folio.
                                            </td>
                                        </tr>
                                    @endif
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="space-y-1.5 text-xs bg-slate-50 p-2.5 border border-slate-200">
                        <div class="flex items-center justify-between">
                            <label class="font-bold text-slate-700 text-[11px]">TOTAL ADD ONS (DINING/AMENITIES):</label>
                            <input type="text" id="inpAddOnsTotal" value="₱{{ number_format($addOnsTotal, 2) }}" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms font-mono !w-40 text-right">
                        </div>

                        <div class="flex items-center justify-between">
                            <label class="font-bold text-slate-700 text-[11px]">GROSS SUB-TOTAL:</label>
                            <input type="text" id="inpSubTotal" value="₱{{ number_format($subTotal, 2) }}" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms font-mono !w-40 font-bold text-slate-900 text-right">
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN: DISCOUNT & FINAL SETTLEMENT -->
                <div>
                    <div class="section-bar-grey">SETTLEMENT &amp; PAYMENT DETAILS</div>

                    <div class="space-y-2 text-xs bg-slate-50 p-3 border border-slate-200">
                        <div class="flex items-center justify-between">
                            <label class="font-bold text-slate-700 text-[11px]">GROSS TOTAL CHARGE:</label>
                            <input type="text" id="inpTotalCharge" value="₱{{ number_format($totalCharge, 2) }}" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms font-mono !w-44 font-bold text-right">
                        </div>

                        <!-- DISCOUNT SELECTION & DEDUCTION -->
                        <div class="border border-slate-300 bg-white p-2.5 rounded space-y-2 shadow-sm">
                            <div class="flex items-center justify-between">
                                <label for="discountTypeSelect" class="font-bold text-slate-800 text-[11px]">DISCOUNT CLASSIFICATION:</label>
                                <select name="discount_type" id="discountTypeSelect" @disabled($folio->discount_override_amount !== null) onchange="onDiscountChange()" class="form-control-hms !w-44 font-bold text-xs bg-amber-50/40 border-amber-300 focus:border-amber-600">
                                    <option value="none" {{ strtolower($folio->discount_type ?? 'none') === 'none' ? 'selected' : '' }}>None (₱0.00)</option>
                                    <option value="senior" {{ strtolower($folio->discount_type ?? '') === 'senior' ? 'selected' : '' }}>Senior Citizen (-₱{{ number_format($discountOptions['SENIOR'] ?? 79, 2) }})</option>
                                    <option value="pwd" {{ strtolower($folio->discount_type ?? '') === 'pwd' ? 'selected' : '' }}>PWD Disability (-₱{{ number_format($discountOptions['PWD'] ?? 79, 2) }})</option>
                                    <option value="dc" {{ strtolower($folio->discount_type ?? '') === 'dc' ? 'selected' : '' }}>Sedona Card / DC (-₱{{ number_format($discountOptions['DC'] ?? 40, 2) }})</option>
                                </select>
                                @if($folio->discount_override_amount !== null)
                                    <input type="hidden" name="discount_type" value="{{ strtolower($folio->discount_type ?? 'none') }}">
                                @endif
                            </div>

                            <div class="flex items-center justify-between" id="rowDiscountRef" style="{{ strtolower($folio->discount_type ?? 'none') === 'none' ? 'display: none;' : '' }}">
                                <div>
                                    <label for="inpDiscountRef" class="font-bold text-slate-700 text-[11px] block">CARD / ID REFERENCE #:</label>
                                    <span class="text-[9px] text-slate-400">Required for official tax audit</span>
                                </div>
                                <input type="text" name="discount_id_ref" id="inpDiscountRef" maxlength="100" @required(in_array(strtoupper($folio->discount_type ?? ''), ['SENIOR', 'PWD'])) value="{{ $folio->discount_id_ref ?? '' }}" placeholder="e.g. OSCA-2024-9912 or DC-4091" oninput="updateDiscountBadgeRef(); syncBillingStatementUrl()" onblur="saveSettlementDraftToServer()" class="form-control-hms font-mono !w-44 font-bold text-xs">
                            </div>

                            <p class="text-xs text-slate-600">For Senior or PWD, select the discount and enter the ID reference, then save.</p>
                            <p class="text-xs text-slate-600">Fixed Senior, PWD and DC discounts can be saved directly. Custom discount changes need owner/admin approval. <a href="{{ route('approvals.index', ['folio_id' => $folio->id, 'discount_type' => strtoupper($folio->discount_type ?? 'NONE') === 'NONE' ? 'DC' : strtoupper($folio->discount_type)]) }}" class="underline font-semibold">Request a custom discount change for Room {{ $folio->room->number }}</a>, then apply the approved request and reload this bill.</p>
                            <button type="button" onclick="saveSettlementDraftToServer()" class="btn-occupied !px-3 !py-2 !text-xs">Save discount</button>
                            <p id="settlementSaveStatus" role="status" aria-live="polite" class="text-xs text-slate-700">Showing the saved guest bill.</p>

                            <div class="flex items-center justify-between pt-1 border-t border-slate-100">
                                <div>
                                    <label class="font-bold text-slate-700 text-[11px] block">TOTAL DISCOUNT DEDUCTION:</label>
                                    <span id="badgeDiscountType" class="px-1.5 py-0.5 rounded text-[10px] font-bold {{ $totalDiscount > 0 ? 'bg-rose-100 text-rose-800 border border-rose-300' : 'bg-slate-200 text-slate-600' }}">
                                        {{ $folio->discount_type ?? 'NONE' }}{{ $folio->discount_id_ref ? ' (' . $folio->discount_id_ref . ')' : '' }}
                                    </span>
                                </div>
                                <input type="text" id="inpTotalDiscount" value="{{ $totalDiscount > 0 ? '-₱' . number_format($totalDiscount, 2) : '₱0.00' }}" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms font-mono !w-44 font-bold text-rose-700 text-right">
                            </div>
                        </div>

                        <div class="flex items-center justify-between">
                            <label class="font-bold text-slate-700 text-[11px]">AMOUNT AFTER DISCOUNT:</label>
                            <input type="text" id="inpAmountToPay" value="₱{{ number_format($amountToPay, 2) }}" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms font-mono !w-44 font-bold text-right">
                        </div>

                        <div class="flex items-center justify-between">
                            <label class="font-bold text-slate-700 text-[11px]">LESS: SECURITY DEPOSIT:</label>
                            <input type="text" id="inpLessDeposit" value="{{ $applyDeposit && $depositApplied > 0 ? '-₱' . number_format($depositApplied, 2) : ($applyDeposit ? '₱0.00' : '₱0.00 (NOT APPLIED)') }}" readonly tabindex="-1" style="pointer-events: none; background-color: #f1f5f9; user-select: none; border-color: #cbd5e1;" class="form-control-hms font-mono !w-44 text-emerald-700 font-bold text-right">
                        </div>

                        <!-- Remaining Deposit Refund Row -->
                        <div class="flex items-center justify-between bg-emerald-50 p-1.5 border border-emerald-300 {{ $remainingDeposit > 0 ? '' : 'hidden' }}" id="rowRemainingDepositRefund">
                            <label class="font-bold text-emerald-900 text-xs">REMAINING DEPOSIT TO REFUND:</label>
                            <input type="text" id="inpRemainingDepositRefund" value="₱{{ number_format($remainingDeposit, 2) }}" readonly tabindex="-1" style="pointer-events: none; background-color: #fff; user-select: none; border-color: #10b981;" class="form-control-hms font-mono !w-44 font-bold text-emerald-800 text-sm text-right">
                        </div>

                        <div class="flex items-center justify-between bg-red-50 p-2 border border-red-300">
                            <label class="font-bold text-red-900 text-xs">FINAL BALANCE TO PAY:</label>
                            <input type="text" id="inpTotalAmountToPay" value="₱{{ number_format($totalAmountToPay, 2) }}" readonly tabindex="-1" style="pointer-events: none; background-color: #fff; user-select: none; border-color: #ef4444;" class="form-control-hms font-mono !w-44 font-black text-sm text-red-700 text-right">
                        </div>

                        <!-- Cashier Editable Settlement Fields -->
                        <div class="border-t border-slate-300 pt-2 space-y-2">
                            <div class="flex items-center justify-between">
                                <label class="font-bold text-slate-700 text-[11px]">PAYMENT METHOD:</label>
                                <select name="payment_method" id="paymentMethodSelect" onchange="togglePaymentInputs()" class="form-control-hms !w-44 font-bold">
                                    <option value="cash" @selected(old('payment_method', $folio->payment_method ?? 'cash') === 'cash')>Cash Payment</option>
                                    <option value="gcash" @selected(old('payment_method', $folio->payment_method) === 'gcash')>GCash E-Wallet</option>
                                    <option value="split" @selected(old('payment_method', $folio->payment_method) === 'split')>Split (Cash + GCash)</option>
                                </select>
                            </div>

                            <div class="flex items-center justify-between" id="rowCashTendered">
                                <label for="inpCashTendered" class="font-bold text-slate-700 text-[11px]">CASH TENDERED (₱):</label>
                                <input type="number" min="0" step="0.01" id="inpCashTendered" name="cash_tendered" value="{{ old('cash_tendered', $folio->cash_tendered) }}" placeholder="0.00" oninput="calculateChange()" onblur="saveSettlementDraftToServer()" class="form-control-hms font-mono !w-44 font-bold text-right">
                            </div>

                            <div class="flex items-center justify-between" id="rowGcashAmount" style="display: none;">
                                <label for="inpGcashAmount" class="font-bold text-slate-700 text-[11px]">GCASH AMOUNT PAID (₱):</label>
                                <input type="number" min="0" step="0.01" id="inpGcashAmount" name="gcash_amount" value="{{ old('gcash_amount', $folio->gcash_amount) }}" placeholder="0.00" oninput="calculateChange()" onblur="saveSettlementDraftToServer()" class="form-control-hms font-mono !w-44 font-bold text-right">
                            </div>

                            <div class="flex items-center justify-between" id="rowGcashRef" style="display: none;">
                                <label for="inpGcashRef" class="font-bold text-slate-700 text-[11px]">GCASH REFERENCE #:</label>
                                <input type="text" maxlength="50" id="inpGcashRef" name="gcash_reference" value="{{ old('gcash_reference', $folio->gcash_reference) }}" placeholder="Required for GCash payments" oninput="calculateChange()" onblur="saveSettlementDraftToServer()" class="form-control-hms font-mono !w-44">
                            </div>

                            <div class="flex items-center justify-between bg-emerald-50 p-2 border border-emerald-300">
                                <label class="font-bold text-emerald-900 text-xs">CHANGE DUE TO GUEST:</label>
                                <input type="text" id="inpChange" value="₱0.00" readonly tabindex="-1" style="pointer-events: none; background-color: #fff; user-select: none; border-color: #10b981;" class="form-control-hms font-mono !w-44 font-bold text-emerald-800 text-sm text-right">
                            </div>
                        </div>

                    </div>
                </div>

            </div>

            <!-- Bottom Actions Row -->
            <div class="mt-4 pt-3 border-t flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center space-x-2">
                    <a href="{{ route('folios.billing', $folio->id) }}?deposit={{ $deposit }}&checked_out_at={{ urlencode($checkOutTime->toIso8601String()) }}" id="billingStatementLink" target="_blank" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-800 text-white font-bold text-xs rounded">
                        📄 Statement / Billing
                    </a>
                    <a href="{{ route('folios.deposit_slip', $folio->id) }}?deposit={{ $deposit }}" id="depositSlipBottomLink" target="_blank" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-800 text-white font-bold text-xs rounded">
                        💰 Deposit Slip
                    </a>
                    <a href="{{ route('folios.deposit_refund', $folio->id) }}?deposit={{ $deposit }}" id="depositRefundLink" target="_blank" class="px-3 py-1.5 bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-xs rounded">
                        💵 Deposit Refund
                    </a>
                    <a href="{{ route('folios.orderslip', $folio->id) }}" target="_blank" class="px-3 py-1.5 bg-[#0284c7] hover:bg-[#0369a1] text-white font-bold text-xs rounded">
                        🍽️ Order Slip
                    </a>
                </div>

                <div class="flex items-center space-x-2">
                    <a href="{{ route('dashboard') }}" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-800 font-bold text-xs rounded">
                        Cancel &amp; Return
                    </a>
                    <button type="submit" id="completeCheckoutButton" @disabled($drawerStatus['state'] !== 'ready') onclick="return confirm('Confirm check out and finalize settlement?')" class="px-6 py-2 bg-[#421A2B] hover:bg-[#341421] text-white font-bold text-xs rounded shadow disabled:cursor-not-allowed disabled:opacity-50">
                        Complete Official Check-Out
                    </button>
                </div>
            </div>
            @if($drawerStatus['state'] !== 'ready')
                <p class="mt-3 text-sm text-amber-800">{{ $drawerStatus['message'] }} See the drawer status above.</p>
            @endif
        </form>
    </div>

</div>

@push('scripts')
<script>
    let currentRoomRate = {{ (float)$roomRate }};
    let tierHours = {{ (int)$tierHours }};
    let xtendHours = {{ (int)$xtendHours }};
    let totalBookedHours = {{ (float)$totalBookedHours }};
    const overstayGraceMs = {{ max(0, (int) config('sedona.overstay_grace_minutes', 15)) }} * 60 * 1000;
    let checkOutTimestamp = new Date("{{ $checkOutTime->toIso8601String() }}").getTime();
    let updatingStayEstimate = false;
    let addOnsTotal = {{ (float)$addOnsTotal }};
    let addHourUnitPrice = 130;
    let addHoursRate = {{ (float)$addHoursRate }};
    let grossTotal = {{ (float)$totalCharge }};
    const discountRates = @json($discountOptions);
    let currentDiscountType = "{{ strtolower($discountType ?? 'none') }}";
    let currentDiscountAmount = {{ (float)($totalDiscount ?? 0) }};
    let amountAfterDiscount = {{ $amountToPay }};
    let currentDeposit = {{ $deposit }};
    let applyDeposit = {{ $applyDeposit ? 'true' : 'false' }};
    let finalBalanceToPay = applyDeposit ? Math.max(0, amountAfterDiscount - currentDeposit) : amountAfterDiscount;
    let depositSaveTimer = null;
    let checkInSaveTimer = null;

    const baseBillingUrl = "{{ route('folios.billing', $folio->id) }}";
    const baseDepositSlipUrl = "{{ route('folios.deposit_slip', $folio->id) }}";
    const baseDepositRefundUrl = "{{ route('folios.deposit_refund', $folio->id) }}";

    function updateDiscountBadgeRef() {
        const badge = document.getElementById('badgeDiscountType');
        const refVal = document.getElementById('inpDiscountRef')?.value?.trim();
        if (badge && currentDiscountType !== 'none') {
            badge.textContent = currentDiscountType.toUpperCase() + (refVal ? ` (${refVal})` : '');
        }
    }

    function onDiscountChange() {
        const sel = document.getElementById('discountTypeSelect');
        currentDiscountType = (sel ? sel.value : 'none').toLowerCase();
        document.getElementById('inpDiscountRef').required = ['senior', 'pwd'].includes(currentDiscountType);
        const rowRef = document.getElementById('rowDiscountRef');
        const badge = document.getElementById('badgeDiscountType');
        const refVal = document.getElementById('inpDiscountRef')?.value?.trim();
        const refStr = refVal ? ` (${refVal})` : '';

        if (currentDiscountType === 'none') {
            currentDiscountAmount = 0.00;
            if (rowRef) rowRef.style.display = 'none';
            if (badge) {
                badge.textContent = 'NONE';
                badge.className = 'px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-200 text-slate-600';
            }
        } else {
            if (rowRef) rowRef.style.display = 'flex';
            if (currentDiscountType === 'senior') {
                currentDiscountAmount = discountRates['SENIOR'] || 0;
                if (badge) {
                    badge.textContent = 'SENIOR' + refStr;
                    badge.className = 'px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-300';
                }
            } else if (currentDiscountType === 'pwd') {
                currentDiscountAmount = discountRates['PWD'] || 0;
                if (badge) {
                    badge.textContent = 'PWD' + refStr;
                    badge.className = 'px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-300';
                }
            } else if (currentDiscountType === 'dc') {
                currentDiscountAmount = discountRates['DC'] || 0;
                if (badge) {
                    badge.textContent = 'DC' + refStr;
                    badge.className = 'px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-300';
                }
            }
        }

        currentDiscountAmount = Math.min(currentRoomRate, currentDiscountAmount);
        // Update Total Discount readout
        const inpTotalDiscount = document.getElementById('inpTotalDiscount');
        if (inpTotalDiscount) {
            inpTotalDiscount.value = currentDiscountAmount > 0 
                ? `-₱${currentDiscountAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` 
                : '₱0.00';
        }

        // Update Amount After Discount
        amountAfterDiscount = Math.max(0, grossTotal - currentDiscountAmount);
        const inpAmountToPay = document.getElementById('inpAmountToPay');
        if (inpAmountToPay) {
            inpAmountToPay.value = `₱${amountAfterDiscount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }

        updateCalculations();
        saveSettlementDraftToServer();
    }

    function onCheckInTimeChange(val) {
        if (!val) return;
        const cInDate = new Date(val);
        if (isNaN(cInDate.getTime())) return;
        checkOutTimestamp = Date.now();
        const checkoutDisplay = document.getElementById('checkoutTimeDisplay');
        if (checkoutDisplay) {
            checkoutDisplay.value = new Date(checkOutTimestamp).toLocaleString('en-US', { timeZone: 'Asia/Manila',
                month: '2-digit', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });
        }

        const diffMs = Math.max(0, checkOutTimestamp - cInDate.getTime());
        const actualHoursDiff = Math.max(1, Math.ceil(diffMs / (1000 * 60 * 60)));
        const billableOverstayMs = Math.max(0, diffMs - Math.round(totalBookedHours * 60 * 60 * 1000) - overstayGraceMs);
        const excessHours = Math.ceil(billableOverstayMs / (60 * 60 * 1000));
        addHoursRate = (xtendHours + excessHours) * addHourUnitPrice;

        const inpActualStayDiff = document.getElementById('inpActualStayDiff');
        if (inpActualStayDiff) inpActualStayDiff.value = `${actualHoursDiff} Hours`;

        const inpExcessOvertime = document.getElementById('inpExcessOvertime');
        if (inpExcessOvertime) inpExcessOvertime.value = `${excessHours} Hours`;

        const inpAddHoursRate = document.getElementById('inpAddHoursRate');
        if (inpAddHoursRate) inpAddHoursRate.value = `₱${addHoursRate.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

        const rowExcess = document.getElementById('rowExcessOvertimeItem');
        if (rowExcess) {
            if (excessHours > 0) {
                rowExcess.style.display = '';
                const qtyText = document.getElementById('tdExcessHoursQtyText');
                if (qtyText) qtyText.textContent = excessHours;
                const qty = document.getElementById('tdExcessHoursQty');
                if (qty) qty.textContent = excessHours;
                const amt = document.getElementById('tdExcessHoursAmount');
                if (amt) amt.textContent = (excessHours * addHourUnitPrice).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            } else {
                rowExcess.style.display = 'none';
            }
        }

        updatingStayEstimate = true;
        try {
            recalcGrossAndSettlement();
        } finally {
            updatingStayEstimate = false;
        }
    }

    function onRoomRateChange(val) {
        currentRoomRate = Math.max(0, parseFloat(val) || 0);

        const tdRoomRatePrice = document.getElementById('tdRoomRatePrice');
        const tdRoomRateAmount = document.getElementById('tdRoomRateAmount');
        if (tdRoomRatePrice) tdRoomRatePrice.textContent = currentRoomRate.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (tdRoomRateAmount) tdRoomRateAmount.textContent = currentRoomRate.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        recalcGrossAndSettlement();

        clearTimeout(checkInSaveTimer);
        checkInSaveTimer = setTimeout(() => {
            saveSettlementDraftToServer();
        }, 500);
    }

    function recalcGrossAndSettlement() {
        currentDiscountAmount = Math.min(currentRoomRate, discountRates[currentDiscountType.toUpperCase()] || 0);
        document.getElementById('inpTotalDiscount').value = `-₱${currentDiscountAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        const subTotal = currentRoomRate + addHoursRate + addOnsTotal;
        grossTotal = subTotal;

        const inpSubTotal = document.getElementById('inpSubTotal');
        if (inpSubTotal) inpSubTotal.value = `₱${subTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

        const inpTotalCharge = document.getElementById('inpTotalCharge');
        if (inpTotalCharge) inpTotalCharge.value = `₱${grossTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

        amountAfterDiscount = Math.max(0, grossTotal - currentDiscountAmount);
        const inpAmountToPay = document.getElementById('inpAmountToPay');
        if (inpAmountToPay) inpAmountToPay.value = `₱${amountAfterDiscount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

        updateCalculations();
    }

    function onApplyDepositToggle(checked) {
        applyDeposit = !!checked;
        const lbl = document.getElementById('lblApplyDeposit');
        if (lbl) {
            lbl.textContent = applyDeposit ? '✓ Applied to Bill' : '✕ Do Not Apply';
            lbl.className = 'font-bold text-[11px] ' + (applyDeposit ? 'text-emerald-800' : 'text-slate-600');
        }
        updateCalculations();
        saveSettlementDraftToServer();
    }

    function updateCalculations() {
        const depositApplied = applyDeposit ? Math.min(currentDeposit, amountAfterDiscount) : 0;
        const remainingDeposit = applyDeposit ? Math.max(0, currentDeposit - amountAfterDiscount) : currentDeposit;
        finalBalanceToPay = applyDeposit ? Math.max(0, amountAfterDiscount - currentDeposit) : amountAfterDiscount;

        // Update Remaining Deposit Balance readout & explanation
        const inpRemainingDeposit = document.getElementById('inpRemainingDeposit');
        if (inpRemainingDeposit) {
            inpRemainingDeposit.value = `₱${remainingDeposit.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }
        const lblDepositExplanation = document.getElementById('lblDepositExplanation');
        if (lblDepositExplanation) {
            if (!applyDeposit) {
                lblDepositExplanation.textContent = '(Not Applied - Full Refund)';
            } else if (remainingDeposit > 0) {
                lblDepositExplanation.textContent = '(Refund to Guest)';
            } else if (currentDeposit > 0) {
                lblDepositExplanation.textContent = '(Fully Absorbed)';
            } else {
                lblDepositExplanation.textContent = '(No Deposit)';
            }
        }

        // Update Less Deposit in settlement column
        const inpLessDeposit = document.getElementById('inpLessDeposit');
        if (inpLessDeposit) {
            if (applyDeposit && depositApplied > 0) {
                inpLessDeposit.value = `-₱${depositApplied.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            } else if (applyDeposit) {
                inpLessDeposit.value = '₱0.00';
            } else {
                inpLessDeposit.value = '₱0.00 (NOT APPLIED)';
            }
        }

        // Update Remaining Deposit Refund Row in settlement column
        const rowRemainingRefund = document.getElementById('rowRemainingDepositRefund');
        const inpRemainingRefund = document.getElementById('inpRemainingDepositRefund');
        if (rowRemainingRefund && inpRemainingRefund) {
            inpRemainingRefund.value = `₱${remainingDeposit.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            if (remainingDeposit > 0) {
                rowRemainingRefund.classList.remove('hidden');
            } else {
                rowRemainingRefund.classList.add('hidden');
            }
        }

        // Update Final Balance To Pay in settlement column
        const inpTotalAmountToPay = document.getElementById('inpTotalAmountToPay');
        if (inpTotalAmountToPay) {
            inpTotalAmountToPay.value = `₱${finalBalanceToPay.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }

        // Update deposit status badge if present
        const badge = document.getElementById('depositStatusBadge');
        if (badge) {
            if (currentDeposit > 0) {
                if (applyDeposit) {
                    badge.textContent = 'HELD IN TRUST (APPLIED)';
                    badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300';
                } else {
                    badge.textContent = 'HELD IN TRUST (NOT APPLIED)';
                    badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300';
                }
            } else {
                badge.textContent = 'NONE';
                badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold bg-slate-200 text-slate-700';
            }
        }

        // Update printable slip links
        const depositSlipTop = document.getElementById('depositSlipTopLink');
        if (depositSlipTop) {
            depositSlipTop.href = `${baseDepositSlipUrl}?deposit=${currentDeposit}`;
        }

        const depositSlipBottom = document.getElementById('depositSlipBottomLink');
        if (depositSlipBottom) {
            depositSlipBottom.href = `${baseDepositSlipUrl}?deposit=${currentDeposit}`;
        }

        const depositRefund = document.getElementById('depositRefundLink');
        if (depositRefund) {
            depositRefund.href = `${baseDepositRefundUrl}?deposit=${currentDeposit}&apply_deposit=${applyDeposit ? 1 : 0}&refund=${remainingDeposit}`;
        }

        // Re-sync payment and change calculations
        togglePaymentInputs();
        syncBillingStatementUrl();
    }

    function syncBillingStatementUrl() {
        const billingLink = document.getElementById('billingStatementLink');
        if (!billingLink) return;

        const method = document.getElementById('paymentMethodSelect')?.value || 'cash';
        const cashTendered = parseFloat(document.getElementById('inpCashTendered')?.value) || 0;
        const gcashAmount = parseFloat(document.getElementById('inpGcashAmount')?.value) || 0;
        const gcashRef = document.getElementById('inpGcashRef')?.value?.trim() || '';
        const discountRef = document.getElementById('inpDiscountRef')?.value?.trim() || '';

        let change = 0;
        if (method === 'cash') {
            change = cashTendered > 0 ? Math.max(0, cashTendered - finalBalanceToPay) : 0;
        } else if (method === 'gcash') {
            change = 0;
        } else if (method === 'split') {
            const remainingCashDue = Math.max(0, finalBalanceToPay - gcashAmount);
            change = cashTendered > 0 ? Math.max(0, cashTendered - remainingCashDue) : 0;
        }

        const url = new URL(baseBillingUrl);
        url.searchParams.set('deposit', currentDeposit);
        url.searchParams.set('apply_deposit', applyDeposit ? '1' : '0');
        url.searchParams.set('discount_type', currentDiscountType);
        if (discountRef) {
            url.searchParams.set('discount_id_ref', discountRef);
        }
        url.searchParams.set('payment_method', method);
        if (cashTendered > 0) {
            url.searchParams.set('cash_tendered', cashTendered);
        }
        if (gcashAmount > 0) {
            url.searchParams.set('gcash_amount', gcashAmount);
        }
        if (gcashRef) {
            url.searchParams.set('gcash_reference', gcashRef);
        }
        if (change > 0) {
            url.searchParams.set('change_due', change);
        }

        const cInInput = document.getElementById('inpCheckInTime');
        if (cInInput && cInInput.value) {
            url.searchParams.set('checked_in_at', cInInput.value);
        }
        url.searchParams.set('room_charge', currentRoomRate);
        url.searchParams.set('checked_out_at', "{{ $checkOutTime->toIso8601String() }}");

        billingLink.href = url.toString();
    }

    let initializingSettlement = true;
    let draftSaveQueue = Promise.resolve();
    let draftSaveRevision = 0;
    let settlementSubmitting = false;
    function saveSettlementDraftToServer() {
        if (initializingSettlement || settlementSubmitting) return draftSaveQueue;
        const revision = ++draftSaveRevision;
        const status = document.getElementById('settlementSaveStatus');
        const csrfToken = document.querySelector('input[name="_token"]')?.value || '{{ csrf_token() }}';
        const method = document.getElementById('paymentMethodSelect')?.value || 'cash';
        const cashTendered = parseFloat(document.getElementById('inpCashTendered')?.value) || 0;
        const gcashAmount = parseFloat(document.getElementById('inpGcashAmount')?.value) || 0;
        const gcashRef = document.getElementById('inpGcashRef')?.value?.trim() || '';
        const discountRef = document.getElementById('inpDiscountRef')?.value?.trim() || '';
        if (['senior', 'pwd'].includes(currentDiscountType) && !discountRef) {
            status.textContent = 'Enter the Senior / PWD ID reference, then click Save discount. This selection has not been saved.';
            return draftSaveQueue;
        }
        status.textContent = 'Saving changes…';

        let change = 0;
        if (method === 'cash') {
            change = cashTendered > 0 ? Math.max(0, cashTendered - finalBalanceToPay) : 0;
        } else if (method === 'gcash') {
            change = 0;
        } else if (method === 'split') {
            const remainingCashDue = Math.max(0, finalBalanceToPay - gcashAmount);
            change = cashTendered > 0 ? Math.max(0, cashTendered - remainingCashDue) : 0;
        }

        const draftBody = JSON.stringify({
            apply_deposit: applyDeposit ? 1 : 0,
            discount_type: currentDiscountType,
            discount_id_ref: discountRef,
            checked_in_at: document.getElementById('inpCheckInTime')?.value,
            room_charge: currentRoomRate,
            payment_method: method,
            cash_tendered: cashTendered,
            gcash_amount: gcashAmount,
            gcash_reference: gcashRef,
            shift_override_reason: document.querySelector('#checkoutProcessForm [name="shift_override_reason"]')?.value || null
        });
        draftSaveQueue = draftSaveQueue.then(() => fetch("{{ route('folios.deposit', $folio->id) }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: draftBody
        }))
        .then(async res => {
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                const messages = Object.values(data.errors || {}).flat();
                throw new Error(messages.join(' ') || 'Changes could not be saved. Reload the page and try again.');
            }
            if (revision === draftSaveRevision) status.textContent = 'Saved to this guest’s bill.';
            return data;
        })
        .catch(err => {
            if (revision === draftSaveRevision) status.textContent = 'Not saved: ' + err.message;
        });
        return draftSaveQueue;
    }

    function togglePaymentInputs() {
        const method = document.getElementById('paymentMethodSelect').value;
        const rowCash = document.getElementById('rowCashTendered');
        const rowGcash = document.getElementById('rowGcashAmount');
        const rowRef = document.getElementById('rowGcashRef');
        const inpGcash = document.getElementById('inpGcashAmount');
        const inpCash = document.getElementById('inpCashTendered');

        if (method === 'cash') {
            rowCash.style.display = 'flex';
            rowGcash.style.display = 'none';
            rowRef.style.display = 'none';
            if (!initializingSettlement) inpGcash.value = '';
        } else if (method === 'gcash') {
            rowCash.style.display = 'none';
            rowGcash.style.display = 'flex';
            rowRef.style.display = 'flex';
            if (!initializingSettlement) inpCash.value = '';

        } else if (method === 'split') {
            rowCash.style.display = 'flex';
            rowGcash.style.display = 'flex';
            rowRef.style.display = 'flex';
        }

        calculateChange();
    }

    function calculateChange() {
        const method = document.getElementById('paymentMethodSelect').value;
        const cashTendered = parseFloat(document.getElementById('inpCashTendered').value) || 0;
        const gcashAmount = parseFloat(document.getElementById('inpGcashAmount').value) || 0;
        document.getElementById('inpGcashRef').required = method !== 'cash' && gcashAmount > 0;
        let change = 0;

        if (method === 'cash') {
            change = cashTendered > 0 ? Math.max(0, cashTendered - finalBalanceToPay) : 0;
        } else if (method === 'gcash') {
            change = 0;
        } else if (method === 'split') {
            const remainingCashDue = Math.max(0, finalBalanceToPay - gcashAmount);
            change = cashTendered > 0 ? Math.max(0, cashTendered - remainingCashDue) : 0;
        }

        document.getElementById('inpChange').value = `₱${change.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

        syncBillingStatementUrl();

        if (!updatingStayEstimate) {
            clearTimeout(depositSaveTimer);
            depositSaveTimer = setTimeout(() => {
                saveSettlementDraftToServer();
            }, 500);
        }
    }

    // Initialize display state
    togglePaymentInputs();
    clearTimeout(depositSaveTimer);
    initializingSettlement = false;
    setInterval(() => {
        if (!settlementSubmitting) onCheckInTimeChange(document.getElementById('inpCheckInTime').value);
    }, 1000);
    document.getElementById('checkoutCheckInTimeForm')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (settlementSubmitting) return;
        const pendingDraft = saveSettlementDraftToServer();
        settlementSubmitting = true;
        clearTimeout(depositSaveTimer);
        clearTimeout(checkInSaveTimer);
        await pendingDraft;
        this.submit();
    });
    document.getElementById('checkoutProcessForm').addEventListener('submit', async function (event) {
        event.preventDefault();
        if (settlementSubmitting) return;
        settlementSubmitting = true;
        clearTimeout(depositSaveTimer);
        clearTimeout(checkInSaveTimer);
        await draftSaveQueue;
        this.submit();
    });
</script>
@endpush
@endsection
