{{-- Shared folio actions for the table and board views. --}}

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: CHECK IN -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div id="checkInModal" class="fixed inset-0 z-50 flex items-center justify-center p-3 bg-black/60 hidden">
    <div class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto border-t-4 border-[#421A2B] p-5 shadow-2xl">
        <div class="flex items-center justify-between border-b pb-2 mb-3">
            <h3 class="font-bold text-[#421A2B] text-sm" id="checkInModalTitle">Check In Guest</h3>
            <button onclick="closeCheckInModal()" class="text-slate-500 hover:text-black font-bold text-lg">&times;</button>
        </div>

        <form method="POST" action="{{ route('checkin.store') }}" class="space-y-3" id="guestCheckInForm">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="room_id" id="checkInRoomId">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Customer / Guest Name:</label>
                <input type="text" name="guest_name" placeholder="Walk-in Guest Name" class="form-control-hms">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label id="checkInTimeLabel" class="block text-xs font-bold text-slate-700">Check-in Date & Time:</label>
                        <span id="checkInLiveBadge" class="text-[9px] uppercase font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 rounded inline-flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            Live
                        </span>
                    </div>
                    <input type="text" 
                           id="checkInDateTimeDisplay" 
                           readonly 
                           tabindex="-1" 
                           style="pointer-events: none; background-color: #f8fafc; user-select: none; border-color: #cbd5e1; text-align: center;" 
                           class="form-control-hms font-mono text-[11px] font-bold text-slate-800 cursor-not-allowed select-none !text-center w-full" 
                           value="{{ now()->format('m/d/Y h:i:s A') }}"
                           title="Check-in date & time is locked to system live clock">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">No. of Guest (Pax):</label>
                    <input type="number" name="headcount" value="2" min="1" max="8" class="form-control-hms font-bold">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Stay Duration Tier:</label>
                <select name="rate_tier" id="checkInTierSelect" onchange="updateCheckInRatePreview()" class="form-control-hms font-bold">
                    <option value="3h" selected>3 Hours Stay</option>
                    <option value="open">Open Time — starts with 3 hours</option>
                    <option value="4h">4 Hours Stay</option>
                    <option value="6h">6 Hours Stay</option>
                    <option value="12h">12 Hours Stay</option>
                    <option value="24h">24 Hours Stay</option>
                    @if(config('sedona.midnight_promo_enabled'))
                        <option value="promo">Midnight Promo (8 PM–6 AM)</option>
                    @endif
                </select>
            </div>

            <div class="bg-slate-50 p-2.5 border border-slate-200 text-xs flex items-center justify-between">
                <span class="text-slate-600 font-semibold" id="checkInRateLabel">Standard Room Rate:</span>
                <span class="font-mono font-bold text-sm text-[#421A2B]" id="checkInRatePreview">₱395.00</span>
            </div>

            <p id="checkInOpenTimeNotice" class="hidden text-xs text-slate-600">No fixed checkout. The first 3 hours use the standard room rate, with 15 minutes of grace, then ₱130 per additional hour or part thereof until checkout. Hourly reminders do not add another grace period.</p>

            <div class="flex items-center space-x-2 pt-1">
                <input type="checkbox" name="is_senior" id="isSenior" value="1" class="rounded text-red-700">
                <label for="isSenior" class="text-xs text-slate-700 cursor-pointer font-semibold">Apply Senior Citizen Discount</label>
            </div>
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_pwd" id="isPwd" value="1" class="rounded text-red-700">
                <label for="isPwd" class="text-xs text-slate-700 cursor-pointer font-semibold">Apply PWD Discount</label>
            </div>
            <div>
                <label for="checkInDiscountReference" class="block text-xs font-bold text-slate-700 mb-1">Senior / PWD ID Reference</label>
                <input type="text" name="discount_id_ref" id="checkInDiscountReference" maxlength="100" class="form-control-hms" placeholder="Required when applying a senior or PWD discount">
            </div>

            <div class="border-t pt-3 flex justify-end space-x-2">
                <button type="button" onclick="closeCheckInModal()" class="px-3 py-1.5 bg-slate-200 text-slate-700 text-xs font-bold rounded">Cancel</button>
                <button type="submit" class="px-5 py-1.5 bg-[#0284c7] hover:bg-[#0369a1] text-white text-xs font-bold rounded shadow">Process Check In</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: XTEND -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div id="xtendModal" class="fixed inset-0 z-50 flex items-center justify-center p-3 bg-black/60 hidden">
    <div class="bg-white w-full max-w-md max-h-[90vh] overflow-y-auto rounded-xl border-t-4 border-[#421A2B] p-5 shadow-2xl">
        <div class="flex items-center justify-between border-b pb-2 mb-3">
            <h3 class="font-bold text-[#421A2B] text-sm" id="xtendModalTitle">Extend Stay</h3>
            <button onclick="closeXtendModal()" class="text-slate-500 hover:text-black font-bold text-lg">&times;</button>
        </div>

        <p class="text-xs text-slate-600 mb-3">Current package: <strong id="xtendCurrentTier"></strong><br>Scheduled checkout: <strong id="xtendCurrentCheckout"></strong></p>
        <label for="xtendMode" class="block text-xs font-bold text-slate-700 mb-1">How would the guest like to extend?</label>
        <select id="xtendMode" onchange="updateXtendMode()" class="form-control-hms font-bold mb-3">
            <option value="hours" id="xtendHoursOption">Add extra hours at ₱130 per hour</option>
            <option value="12h" id="xtendConvert12Option">Convert to 12-hour package</option>
            <option value="24h" id="xtendConvertOption">Convert to 24-hour package</option>
            <option value="unavailable" hidden disabled>No forward package available</option>
        </select>
        <p id="xtendConversionHint" class="text-xs text-slate-500 mb-3">No forward package is available. Fixed stays can add extra hours; Open Time continues billing automatically.</p>
        <form method="POST" id="xtendForm" class="space-y-3">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Select Hours to Extend (₱130.00/hr):</label>
                <select name="hours" class="form-control-hms font-bold">
                    <option value="1">1 Hour (+₱130.00)</option>
                    <option value="2">2 Hours (+₱260.00)</option>
                    <option value="3" selected>3 Hours (+₱390.00)</option>
                    <option value="6">6 Hours (+₱780.00)</option>
                    <option value="12">12 Hours (+₱1,560.00)</option>
                </select>
            </div>

            <div class="border-t pt-3 flex justify-end space-x-2">
                <button type="button" onclick="closeXtendModal()" class="px-3 py-1.5 bg-slate-200 text-slate-700 text-xs font-bold rounded">Cancel</button>
                <button type="submit" class="btn-occupied">Save Extension</button>
            </div>
        </form>
        <form method="POST" id="convertStayForm" class="hidden space-y-3">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="rate_tier" id="xtendConversionTier" value="24h">
            <div class="rounded-lg border border-rose-100 bg-rose-50 p-3 text-xs space-y-2">
                <p>New package base rate: <strong id="xtendConversionRate"></strong></p>
                <p>New checkout: <strong id="xtendConversionCheckout"></strong></p>
                <p>This replaces the current room package and any separate extra-hour extension. The new package runs from the original check-in time. Existing discounts and add-ons are included when the bill is recalculated.</p>
                <p>The 15-minute checkout grace period starts after the new checkout time.</p>
            </div>
            <label for="xtendConversionReason" class="block text-xs font-bold text-slate-700">Reason</label>
            <input id="xtendConversionReason" name="reason" value="Guest requested a 24-hour package." required maxlength="500" class="form-control-hms">
            <div class="border-t pt-3 flex justify-end gap-2">
                <button type="button" onclick="closeXtendModal()" class="px-3 py-1.5 bg-slate-200 text-slate-700 text-xs font-bold rounded">Cancel</button>
                <button type="submit" id="xtendConversionSubmit" class="btn-occupied">Convert Package</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: DISCOUNT -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div id="discountModal" class="fixed inset-0 z-50 flex items-center justify-center p-3 bg-black/60 hidden">
    <div class="bg-white w-full max-w-sm border-t-4 border-[#0284c7] p-5 shadow-2xl">
        <div class="flex items-center justify-between border-b pb-2 mb-3">
            <h3 class="font-bold text-[#0284c7] text-sm" id="discountModalTitle">Apply Discount</h3>
            <button onclick="closeDiscountModal()" class="text-slate-500 hover:text-black font-bold text-lg">&times;</button>
        </div>

        <form method="POST" id="discountForm" class="space-y-3">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div>
                <label for="discountTypeSelect" class="block text-xs font-bold text-slate-700 mb-1">Discount Classification:</label>
                <select name="discount_type" id="discountTypeSelect" onchange="updateDiscountPreview()" class="form-control-hms font-bold text-xs">
                    <option value="none">No Discount / Clear Existing</option>
                    <option value="senior">Senior Citizen (Statutory BIR Table)</option>
                    <option value="pwd">Person with Disability - PWD (Statutory BIR Table)</option>
                    <option value="dc">Sedona Discount Card (DC Loyalty Card)</option>
                </select>
            </div>

            <input type="hidden" name="kind" value="folio_override" id="discountApprovalKind" disabled>
            <input type="hidden" name="source_id" id="discountApprovalSource" disabled>
            <input type="hidden" name="payload[discount_type]" id="discountApprovalType" disabled>
            <input type="hidden" name="payload[discount_id_ref]" id="discountApprovalReference" disabled>
            <div id="discountApprovalGroup" class="hidden space-y-2">
                <p class="text-xs text-amber-800">Replacing a custom discount needs owner/admin approval. The bill changes only after the request is approved and executed in Approvals &amp; Tasks.</p>
                <label for="discountApprovalReason" class="block text-xs font-bold text-slate-700">Reason for discount change</label>
                <input name="reason" id="discountApprovalReason" maxlength="1000" disabled class="form-control-hms text-xs">
            </div>

            <div id="discountRefGroup">
                <label for="discountIdRef" class="block text-xs font-bold text-slate-700 mb-1">Card / ID Reference #:</label>
                <input type="text" name="discount_id_ref" id="discountIdRef" maxlength="100" placeholder="e.g. OSCA-2024-9912 or DC-4091" class="form-control-hms font-mono text-xs font-bold">
                <span class="text-[10px] text-slate-500">Enter the ID reference before applying a Senior or PWD discount.</span>
            </div>

            <div class="p-2.5 bg-slate-100 border border-slate-300 rounded flex items-center justify-between text-xs">
                <div>
                    <span class="font-bold text-slate-800 uppercase text-[10px] block">Fixed Table Deduction:</span>
                    <span class="text-slate-500 text-[10px]" id="discountTierNotice">Classic Room (3h)</span>
                </div>
                <div class="font-mono font-black text-rose-700 text-base" id="discountAmountPreview">
                    -₱0.00
                </div>
            </div>
            <p id="discountZeroNotice" class="hidden text-xs text-amber-800">The recorded discount table has no deduction for this package. Applying it will not reduce the bill.</p>

            <div class="border-t pt-3 flex justify-end space-x-2">
                <button type="button" onclick="closeDiscountModal()" class="px-3 py-1.5 bg-slate-200 text-slate-700 text-xs font-bold rounded">Cancel</button>
                <button type="submit" id="discountSubmitButton" class="px-4 py-1.5 bg-[#0284c7] hover:bg-[#0369a1] text-white text-xs font-bold rounded">Apply Fixed Discount</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: ROOM TRANSFER -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div id="transferModal" class="fixed inset-0 z-50 flex items-center justify-center p-3 bg-black/60 hidden">
    <div class="bg-white w-full max-w-md border-t-4 border-[#0284c7] p-5 shadow-2xl">
        <div class="flex items-center justify-between border-b pb-2 mb-3">
            <h3 class="font-bold text-[#0284c7] text-sm" id="transferModalTitle">Transfer Room</h3>
            <button onclick="closeTransferModal()" class="text-slate-500 hover:text-black font-bold text-lg">&times;</button>
        </div>

        <form method="POST" id="transferForm" class="space-y-3">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Select Target Available Room:</label>
                <select name="target_room_id" required class="form-control-hms font-bold">
                    @forelse($availableRoomsList as $ar)
                        <option value="{{ $ar->id }}">Room {{ $ar->number }} &bull; Floor {{ $ar->floor }} ({{ $ar->type }})</option>
                    @empty
                        <option value="" disabled selected>No other rooms currently available</option>
                    @endforelse
                </select>
            </div>
            <p class="text-[11px] text-slate-500">
                Notice: Old room will immediately transition to <strong>Available</strong>, and all active billings & food orders will follow the guest to the target room.
            </p>

            <div class="border-t pt-3 flex justify-end space-x-2">
                <button type="button" onclick="closeTransferModal()" class="px-3 py-1.5 bg-slate-200 text-slate-700 text-xs font-bold rounded">Cancel</button>
                <button type="submit" class="px-4 py-1.5 bg-[#0284c7] hover:bg-[#0369a1] text-white text-xs font-bold rounded">Complete Transfer</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: FORCE CHECKOUT REQUEST -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div id="forceCheckoutModal" class="fixed inset-0 z-50 flex items-center justify-center p-3 bg-black/60 hidden">
    <div class="bg-white w-full max-w-md border-t-4 border-[#374151] p-5 shadow-2xl">
        <div class="flex items-center justify-between border-b pb-2 mb-3">
            <h3 class="font-bold text-slate-900 text-sm" id="forceModalTitle">Request Force Checkout</h3>
            <button onclick="closeForceCheckoutModal()" class="text-slate-500 hover:text-black font-bold text-lg">&times;</button>
        </div>

        <form method="POST" id="forceForm" class="space-y-3">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Select Incident Reason:</label>
                <select name="force_reason" class="form-control-hms font-bold">
                    <option value="Guest skipped out without settlement">Guest skipped out without settlement</option>
                    <option value="Overstay and unreachable">Overstay and unreachable</option>
                    <option value="Disputed billing charges">Disputed billing charges</option>
                    <option value="Emergency property eviction">Emergency property eviction</option>
                </select>
            </div>

            <div class="border-t pt-3 flex justify-end space-x-2">
                <button type="button" onclick="closeForceCheckoutModal()" class="px-3 py-1.5 bg-slate-200 text-slate-700 text-xs font-bold rounded">Cancel</button>
                <button type="submit" class="px-4 py-1.5 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded">Submit for Approval</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<dialog id="guestOrdersDialog" aria-labelledby="guestOrdersDialogTitle" class="w-[min(96vw,64rem)] max-w-none p-0 border-0 rounded-lg shadow-2xl backdrop:bg-black/60">
    <div class="flex items-center justify-between gap-3 border-t-4 border-[#421A2B] border-b p-4">
        <h3 id="guestOrdersDialogTitle" class="font-bold text-[#421A2B] text-sm">ADD-ONS</h3>
        <button type="button" onclick="document.getElementById('guestOrdersDialog').close()" aria-label="Close add-ons" class="text-slate-500 hover:text-black font-bold text-xl">&times;</button>
    </div>
    <iframe id="guestOrdersFrame" title="Add-ons and previous orders" class="w-full h-[75vh] min-h-80 border-0"></iframe>
</dialog>
<script>
    let currentRoomRates = {};

    let checkInClockInterval = null;

    function syncCheckInLiveTime() {
        const now = new Date();

        const displayEl = document.getElementById('checkInDateTimeDisplay');
        if (displayEl) {
            const timeStr = now.toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            const dateStr = now.toLocaleDateString('en-US', { timeZone: 'Asia/Manila', month: '2-digit', day: '2-digit', year: 'numeric' });
            displayEl.value = `${dateStr} ${timeStr}`;
        }
        if (document.getElementById('checkInTierSelect')?.value === 'open') updateCheckInRatePreview();
    }

    function openCheckInModal(room) {
        document.getElementById('checkInRoomId').value = room.roomId;
        document.getElementById('checkInModalTitle').textContent = `Check In Room ${room.roomNumber} (${room.roomType})`;
        document.getElementById('checkInTierSelect').value = '3h';
        
        syncCheckInLiveTime();
        if (checkInClockInterval) clearInterval(checkInClockInterval);
        checkInClockInterval = setInterval(syncCheckInLiveTime, 1000);

        currentRoomRates = { '3h': Number(room['rate-3h']), '4h': Number(room['rate-4h']), '6h': Number(room['rate-6h']), '12h': Number(room['rate-12h']), '24h': Number(room['rate-24h']), 'promo': Number(room.ratePromo) };
        updateCheckInRatePreview();
        document.getElementById('checkInModal').classList.remove('hidden');
    }
    function updateCheckInRatePreview() {
        const tier = document.getElementById('checkInTierSelect').value;
        const rate = currentRoomRates[tier === 'open' ? '3h' : tier] || 0;
        document.getElementById('checkInRatePreview').textContent = `₱${rate.toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
        const rateLabel = document.getElementById('checkInRateLabel');
        if (rateLabel) rateLabel.textContent = tier === 'open' ? 'Starting Room Charge:' : 'Standard Room Rate:';
        document.getElementById('checkInOpenTimeNotice')?.classList.toggle('hidden', tier !== 'open');
        if (tier === 'open' && document.getElementById('checkInOpenTimeNotice')) {
            const format = time => new Date(time).toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', hour12: true });
            const baseEnd = Date.now() + 3 * 3600000;
            document.getElementById('checkInOpenTimeNotice').textContent = `No fixed checkout. 3-hour base ends at ${format(baseEnd)}; grace ends at ${format(baseEnd + 15 * 60000)}. Then ₱130 per additional hour or part thereof until checkout. Hourly reminders do not restart grace.`;
        }
    }
    function closeCheckInModal() {
        if (checkInClockInterval) {
            clearInterval(checkInClockInterval);
            checkInClockInterval = null;
        }
        document.getElementById('checkInModal').classList.add('hidden');
    }

    const FOLIO_EXTENSION_OPTIONS = {{ \Illuminate\Support\Js::from($folioExtensionOptions) }};
    let currentExtensionOptions = null;

    function openXtendModal(folioId, roomNumber) {
        const options = FOLIO_EXTENSION_OPTIONS[folioId];
        if (!options) return;
        currentExtensionOptions = options;
        document.getElementById('xtendForm').action = options.extendUrl;
        document.getElementById('convertStayForm').action = options.convertUrl;
        document.getElementById('xtendCurrentTier').textContent = options.openTime ? 'Open Time (3-hour base, then ₱130/hour)' : options.currentTier === 'promo' ? 'Midnight promo' : options.currentTier;
        document.getElementById('xtendCurrentCheckout').textContent = options.currentCheckout || 'Not set';
        document.getElementById('xtendConvert12Option').disabled = !options.conversions['12h'];
        document.getElementById('xtendConvertOption').disabled = !options.conversions['24h'];
        document.getElementById('xtendConversionHint').classList.toggle('hidden', options.canConvert);
        document.getElementById('xtendHoursOption').disabled = Boolean(options.openTime);
        document.getElementById('xtendMode').value = options.openTime ? (options.conversions['12h'] ? '12h' : options.conversions['24h'] ? '24h' : 'unavailable') : 'hours';
        document.getElementById('xtendConversionReason').value = 'Guest requested a 24-hour package.';
        updateXtendMode();
        document.getElementById('xtendModalTitle').textContent = `Extend Stay - Room ${roomNumber}`;
        document.getElementById('xtendModal').classList.remove('hidden');
    }
    function updateXtendMode() {
        const tier = document.getElementById('xtendMode').value;
        const conversion = currentExtensionOptions?.conversions[tier];
        const converting = Boolean(conversion);
        const unavailable = tier === 'unavailable';
        if (converting) {
            document.getElementById('xtendConversionTier').value = tier;
            document.getElementById('xtendConversionRate').textContent = `₱${Number(conversion.rate).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            document.getElementById('xtendConversionCheckout').textContent = conversion.checkout;
            document.getElementById('xtendConversionReason').value = `Guest requested a ${tier === '12h' ? 12 : 24}-hour package.`;
            document.getElementById('xtendConversionSubmit').textContent = `Convert to ${tier === '12h' ? 12 : 24} Hours`;
        }
        document.getElementById('xtendForm').classList.toggle('hidden', converting || unavailable);
        document.getElementById('convertStayForm').classList.toggle('hidden', !converting);
    }
    function closeXtendModal() {
        document.getElementById('xtendModal').classList.add('hidden');
    }

    let currentDiscountContext = { roomType: 'Classic Room', rateTier: '3h' };
    const FOLIO_DISCOUNT_OPTIONS = {{ \Illuminate\Support\Js::from($folioDiscountOptions) }};
    const FOLIO_CUSTOM_DISCOUNTS = {{ \Illuminate\Support\Js::from($activeFolios->pluck('discount_override_amount', 'id')) }};
    const DISCOUNT_APPROVAL_URL = {{ \Illuminate\Support\Js::from(route('approvals.store')) }};

    function openDiscountModal(folioId, roomNumber, roomType, rateTier, currentDiscountType, currentDiscountRef) {
        document.getElementById('discountForm').action = `/folios/${folioId}/discount`;
        document.getElementById('discountModalTitle').textContent = `Apply Fixed Discount - Room ${roomNumber} (${roomType})`;
        
        currentDiscountContext = { folioId, currentType: (currentDiscountType || 'none').toUpperCase(), roomType: roomType || 'Classic Room', rateTier: (rateTier || '3h').toLowerCase() };
        document.getElementById('discountApprovalReason').value = '';
        
        const dt = (currentDiscountType || 'none').toLowerCase();
        document.getElementById('discountTypeSelect').value = (dt === 'senior' || dt === 'pwd' || dt === 'dc') ? dt : 'none';
        document.getElementById('discountIdRef').value = currentDiscountRef || '';
        
        updateDiscountPreview();
        document.getElementById('discountModal').classList.remove('hidden');
    }

    function updateDiscountPreview() {
        const type = document.getElementById('discountTypeSelect').value.toUpperCase();
        document.getElementById('discountIdRef').required = ['SENIOR', 'PWD'].includes(type);
        document.getElementById('discountIdRef').disabled = type === 'NONE';
        const requiresApproval = type !== currentDiscountContext.currentType && FOLIO_CUSTOM_DISCOUNTS[currentDiscountContext.folioId] != null;
        document.getElementById('discountForm').action = requiresApproval ? DISCOUNT_APPROVAL_URL : `/folios/${currentDiscountContext.folioId}/discount`;
        document.getElementById('discountApprovalGroup').classList.toggle('hidden', !requiresApproval);
        document.getElementById('discountSubmitButton').textContent = requiresApproval ? 'Request discount approval' : 'Apply Fixed Discount';
        for (const id of ['discountApprovalKind', 'discountApprovalSource', 'discountApprovalType', 'discountApprovalReference', 'discountApprovalReason']) {
            document.getElementById(id).disabled = !requiresApproval;
        }
        document.getElementById('discountApprovalReason').required = requiresApproval;
        document.getElementById('discountApprovalSource').value = currentDiscountContext.folioId;
        document.getElementById('discountApprovalType').value = type;
        document.getElementById('discountZeroNotice').classList.add('hidden');
        const tier = currentDiscountContext.roomType.toUpperCase().includes('VIP') ? 'VIP' : (currentDiscountContext.roomType.toUpperCase().includes('PREMIUM') ? 'PREMIUM' : 'CLASSIC');
        const dur = currentDiscountContext.rateTier === 'promo' ? 'Midnight promo' : currentDiscountContext.rateTier;

        document.getElementById('discountTierNotice').textContent = `${tier} Room (${dur})`;
        
        if (type === 'NONE') {
            document.getElementById('discountAmountPreview').textContent = '₱0.00';
            document.getElementById('discountRefGroup').style.opacity = '0.5';
            return;
        }

        document.getElementById('discountRefGroup').style.opacity = '1';
        const amount = Number(FOLIO_DISCOUNT_OPTIONS[currentDiscountContext.folioId]?.[type] || 0);
        document.getElementById('discountAmountPreview').textContent = `-₱${amount.toFixed(2)}`;
        document.getElementById('discountZeroNotice').classList.toggle('hidden', amount !== 0);
    }

    function closeDiscountModal() {
        document.getElementById('discountModal').classList.add('hidden');
    }
    document.getElementById('discountForm').addEventListener('submit', function () {
        document.getElementById('discountApprovalReference').value = document.getElementById('discountIdRef').value.trim();
        if (document.getElementById('discountApprovalType').value === 'NONE') {
            document.getElementById('discountApprovalReference').disabled = true;
        }
    });

    function openTransferModal(folioId, roomNumber) {
        document.getElementById('transferForm').action = `/folios/${folioId}/transfer`;
        document.getElementById('transferModalTitle').textContent = `Transfer Guest from Room ${roomNumber}`;
        document.getElementById('transferModal').classList.remove('hidden');
    }
    function closeTransferModal() {
        document.getElementById('transferModal').classList.add('hidden');
    }

    let guestOrdersChanged = false;
    function openAddOnModal(folioId, roomNumber) {
        const url = {{ \Illuminate\Support\Js::from(route('folios.orders.index', ['folio' => '__FOLIO__'])) }};
        guestOrdersChanged = false;
        document.getElementById('guestOrdersDialogTitle').textContent = `ADD-ONS — Room ${roomNumber}`;
        document.getElementById('guestOrdersFrame').src = url.replace('__FOLIO__', encodeURIComponent(folioId)) + '?panel=1';
        document.getElementById('guestOrdersDialog').showModal();
    }
    window.addEventListener('message', event => {
        if (event.origin !== window.location.origin || event.source !== document.getElementById('guestOrdersFrame').contentWindow) {
            return;
        }
        if (event.data?.type === 'sedona:guest-orders-updated') {
            guestOrdersChanged = true;
        }
        if (event.data?.type === 'sedona:guest-orders-close') {
            document.getElementById('guestOrdersDialog').close();
        }
    });
    document.getElementById('guestOrdersDialog').addEventListener('close', () => {
        document.getElementById('guestOrdersFrame').removeAttribute('src');
        if (guestOrdersChanged) {
            window.location.reload();
        }
    });

    function openForceCheckoutModal(folioId, roomNumber, netTotal) {
        document.getElementById('forceForm').action = `/folios/${folioId}/force-checkout`;
        document.getElementById('forceModalTitle').textContent = `Request Force Checkout - Room ${roomNumber} (₱${netTotal})`;
        document.getElementById('forceCheckoutModal').classList.remove('hidden');
    }
    function closeForceCheckoutModal() {
        document.getElementById('forceCheckoutModal').classList.add('hidden');
    }
</script>
@endpush
