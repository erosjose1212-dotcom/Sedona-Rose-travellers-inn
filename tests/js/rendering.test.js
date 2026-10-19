import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

class Element {
    constructor(tag = 'div') {
        this.tagName = tag;
        this.children = [];
        this.textContent = '';
        this.listeners = {};
        this.classList = { add() {}, remove() {}, toggle() {} };
    }

    appendChild(child) {
        this.children.push(child);
        return child;
    }

    replaceChildren(...children) {
        this.children = children;
    }

    addEventListener(event, handler) {
        this.listeners[event] = handler;
    }

    set innerHTML(value) {
        assert.doesNotMatch(value, /<img|onerror|<script/i, 'Untrusted text reached the HTML parser');
        this.children = [];
    }
}

function documentWith(ids) {
    const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
    const listeners = {};
    return { elements, listeners, addEventListener: (event, handler) => { listeners[event] = handler; }, createElement: tag => new Element(tag), getElementById: id => elements[id] };
}

function descendants(element) {
    return [element, ...element.children.flatMap(descendants)];
}

test('stock delivery preview adds units and admin recount mode replaces the total explicitly', () => {
    const blade = readFileSync(new URL('../../resources/views/inventory/index.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.indexOf('function openStockModal('), blade.indexOf('</script>', blade.indexOf('function openStockModal(')));
    const document = documentWith(['stockModalTitle', 'stockModalItemName', 'stockModalCurrentCount', 'stockReason', 'stockModalQty', 'stockQuantityLabel', 'stockQuantityHelp', 'stockSubmitButton', 'stockBalancePreview', 'stockForm', 'stockModal']);
    const context = { document };
    runInNewContext(source, context);
    context.openStockModal(7, 'Delivery sample', '3');
    assert.equal(document.elements.stockModalQty.value, '');
    document.elements.stockModalQty.value = '30';
    context.updateStockBalancePreview();
    assert.equal(document.elements.stockBalancePreview.textContent, '33 units');
    document.elements.stockReason.value = 'adjustment';
    context.updateStockMode();
    assert.equal(document.elements.stockModalQty.value, '3');
    document.elements.stockModalQty.value = '30';
    context.updateStockBalancePreview();
    assert.equal(document.elements.stockBalancePreview.textContent, '30 units');
    context.openStockModal(8, 'Next delivery', '10');
    assert.equal(document.elements.stockReason.value, 'restock');
    assert.equal(document.elements.stockModalQty.value, '');
    assert.equal(document.elements.stockBalancePreview.textContent, '—');
});

test('alarm names stay literal text and snooze callbacks preserve their arguments', () => {
    const source = readFileSync(new URL('../../resources/js/audio.js', import.meta.url), 'utf8').split('// Global instance')[0];
    const document = documentWith(['activeAlarmBanner', 'alarmBannerRoomsList', 'alarmBannerTitle', 'alarmBannerIcon']);
    const window = {};
    runInNewContext(`${source}\nwindow.Engine = SedonaAudioEngine;`, { window, document });
    const engine = Object.create(window.Engine.prototype);
    const calls = [];
    engine.snoozeRoom = (...args) => calls.push(args);
    const guestName = '<img src=x onerror=alert(1)>';
    engine.updateAlarmBanner([{ roomId: '5', roomNumber: "201'", guestName, timeSummary: 'Overdue', stageLabel: 'Due', stageBadgeClass: 'bg-rose-600', folioId: 19, checkoutTimeStr: '2026-10-07', currentStage: 2 }], 2);
    const nodes = descendants(document.elements.alarmBannerRoomsList);
    assert.ok(nodes.some(node => node.textContent === guestName));
    assert.equal(nodes.filter(node => node.tagName === 'img').length, 0);
    nodes.find(node => node.textContent === 'Snooze 5m').listeners.click();
    assert.deepEqual(calls, [['5', 5]]);
    assert.equal(nodes.find(node => node.textContent === 'Checkout').href, '/checkout/19');
});

test('checkout alarms ring fifteen minutes before, at checkout, and fifteen minutes after, then reset on extension', () => {
    const source = readFileSync(new URL('../../resources/js/audio.js', import.meta.url), 'utf8').split('// Global instance')[0];
    const due = Date.parse('2026-10-14T07:04:58+08:00');
    let clock = due;
    let checkout = due;
    const attributes = { 'data-room-id': '8', 'data-room-number': '8', 'data-folio-id': '1', 'data-guest-name': 'Test guest' };
    const row = { getAttribute: name => name === 'data-checkout-time' ? new Date(checkout).toISOString() : attributes[name], querySelector: () => null };
    const document = { querySelectorAll: () => [row] };
    const window = {};
    runInNewContext(`${source}\nwindow.Engine = SedonaAudioEngine;`, { window, document, Date: class extends Date { static now() { return clock; } } });
    const engine = Object.create(window.Engine.prototype);
    let mode;
    engine.isRoomSnoozed = () => false;
    engine.isStageSilenced = () => false;
    engine.setSoundMode = value => { mode = value; };
    engine.updateAlarmBanner = () => {};
    for (const [offset, expected] of [[-901000, null], [-900000, 'stage1'], [-1000, 'stage1'], [0, 'stage2'], [899000, 'stage2'], [900000, 'stage3']]) {
        clock = due + offset;
        engine.evaluateAlarms();
        assert.equal(mode, expected);
    }
    checkout += 3600000;
    engine.evaluateAlarms();
    assert.equal(mode, null, 'Extending the stay must stop the old checkout alarm');
});

test('cart names stay literal text while quantities and submitted item fields work', () => {
    const blade = readFileSync(new URL('../../resources/views/pos/index.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.lastIndexOf('<script>') + 8, blade.lastIndexOf('</script>'));
    const document = documentWith(['cartItemsContainer', 'cartGrandTotal', 'submitOrderBtn']);
    const context = { document };
    runInNewContext(source, context);
    const name = '<img src=x onerror=alert(1)>';
    context.addToCart('9', name, 50);
    let nodes = descendants(document.elements.cartItemsContainer);
    assert.ok(nodes.some(node => node.textContent === name));
    assert.equal(nodes.find(node => node.name === 'items[0][id]').value, '9');
    nodes.find(node => node.textContent === '+').listeners.click();
    nodes = descendants(document.elements.cartItemsContainer);
    assert.equal(nodes.find(node => node.name === 'items[0][qty]').value, 2);
    assert.equal(document.elements.cartGrandTotal.textContent, '₱100.00');
    assert.equal(document.elements.submitOrderBtn.disabled, false);
});

for (const view of ['index', 'tv']) {
    test(`kitchen ${view} waits for deferred audio before acknowledging new orders`, () => {
        const blade = readFileSync(new URL(`../../resources/views/kitchen/${view}.blade.php`, import.meta.url), 'utf8');
        const source = blade.slice(blade.lastIndexOf('<script>') + 8, blade.lastIndexOf('</script>')).replace(/\{\{[^]*?\}\}/g, '2');
        const document = documentWith(['tv-clock', 'tv-date']);
        const window = {};
        const stored = new Map();
        let chimes = 0;
        runInNewContext(source, { document, window, sessionStorage: { getItem: key => stored.get(key), setItem: (key, value) => stored.set(key, value) }, setInterval() {}, setTimeout() {} });
        assert.equal(stored.size, 0);
        window.sedonaAudio = { playKitchenChime() { chimes++; } };
        document.listeners.DOMContentLoaded();
        assert.equal(chimes, 1);
        assert.equal([...stored.values()][0], '2');
    });
}

test('checkin preview reads each configured rate from the HTML dataset keys', () => {
    const blade = readFileSync(new URL('../../resources/views/dashboard/partials/folio-modals.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.indexOf('let currentRoomRates ='), blade.indexOf('const FOLIO_EXTENSION_OPTIONS ='));
    const document = documentWith(['checkInRoomId', 'checkInModalTitle', 'checkInDateTime', 'checkInDateTimeDisplay', 'checkInTierSelect', 'checkInRatePreview', 'checkInModal', 'checkInOpenTimeNotice']);
    const context = { document, setInterval() {}, clearInterval() {} };
    runInNewContext(source, context);
    const roomDataset = { roomId: '12', roomNumber: '201', roomType: 'Classic', 'rate-3h': '395', 'rate-6h': '777', 'rate-12h': '975', 'rate-24h': '1700', ratePromo: '750' };
    context.openCheckInModal(roomDataset);
    assert.equal(document.elements.checkInTierSelect.value, '3h');
    for (const [tier, expected] of [['6h', '₱777.00'], ['3h', '₱395.00'], ['12h', '₱975.00'], ['24h', '₱1,700.00'], ['promo', '₱750.00'], ['open', '₱395.00']]) {
        document.elements.checkInTierSelect.value = tier;
        context.updateCheckInRatePreview();
        assert.equal(document.elements.checkInRatePreview.textContent, expected);
    }
    assert.match(document.elements.checkInOpenTimeNotice.textContent, /No fixed checkout.*grace ends at.*₱130/);
});

test('walkin tender inputs require only the selected method and never invent money received', () => {
    const blade = readFileSync(new URL('../../resources/views/pos/index.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.lastIndexOf('<script>') + 8, blade.lastIndexOf('</script>'));
    const document = documentWith(['orderTypeSelect', 'roomChargeSection', 'walkinPosSection', 'walkinPaymentMethod', 'walkinCashFields', 'walkinGcashFields', 'walkinCashTendered', 'walkinGcashAmount', 'walkinGcashReference', 'selectedFolioId', 'posRoomSelect']);
    document.elements.posRoomSelect.selectedOptions = [{ dataset: { folioId: '21' } }];
    const context = { document };
    runInNewContext(source, context);
    document.elements.orderTypeSelect.value = 'walkin_pos';
    document.elements.walkinPaymentMethod.value = 'cash';
    context.toggleOrderDestination();
    assert.equal(document.elements.selectedFolioId.disabled, true);
    assert.equal(document.elements.walkinCashTendered.required, true);
    assert.equal(document.elements.walkinCashTendered.disabled, false);
    assert.equal(document.elements.walkinGcashAmount.disabled, true);
    assert.equal(document.elements.walkinCashTendered.value, undefined);
    document.elements.walkinPaymentMethod.value = 'gcash';
    context.toggleWalkinPayment();
    assert.equal(document.elements.walkinCashTendered.disabled, true);
    assert.equal(document.elements.walkinGcashAmount.required, true);
    assert.equal(document.elements.walkinGcashReference.required, true);
    assert.equal(document.elements.walkinGcashAmount.value, undefined);
    document.elements.orderTypeSelect.value = 'room_charge';
    context.toggleOrderDestination();
    assert.equal(document.elements.selectedFolioId.value, '21');
    assert.equal(document.elements.selectedFolioId.disabled, false);
    document.elements.posRoomSelect.selectedOptions = [{ dataset: { folioId: '22' } }];
    context.updateSelectedFolio();
    assert.equal(document.elements.selectedFolioId.value, '22');
    assert.equal(document.elements.walkinPaymentMethod.disabled, true);
    for (const id of ['walkinCashTendered', 'walkinGcashAmount', 'walkinGcashReference']) {
        assert.equal(document.elements[id].required, false);
        assert.equal(document.elements[id].disabled, true);
    }
});

test('checkout waits for captured autosaves in order and blocks later draft writes', async () => {
    const blade = readFileSync(new URL('../../resources/views/checkout/process.blade.php', import.meta.url), 'utf8');
    const saveSource = blade.slice(blade.indexOf('let initializingSettlement = true;'), blade.indexOf('function togglePaymentInputs()'));
    const submitSource = blade.slice(blade.indexOf("document.getElementById('checkoutProcessForm').addEventListener('submit'"), blade.lastIndexOf('</script>'));
    const document = documentWith(['paymentMethodSelect', 'inpCashTendered', 'inpGcashAmount', 'inpGcashRef', 'inpDiscountRef', 'inpCheckInTime', 'checkoutProcessForm', 'settlementSaveStatus']);
    const overrideReason = { value: 'Covering assigned cashier' };
    document.querySelector = selector => selector.includes('shift_override_reason') ? overrideReason : { value: 'csrf-token' };
    document.elements.paymentMethodSelect.value = 'cash';
    document.elements.inpCashTendered.value = '200';
    const requests = [];
    let submitted = false;
    document.elements.checkoutProcessForm.submit = () => { submitted = true; };
    const context = {
        document, console, currentDeposit: 500, applyDeposit: true, currentDiscountType: 'NONE',
        currentRoomRate: 395, finalBalanceToPay: 0, depositSaveTimer: null, checkInSaveTimer: null,
        clearTimeout() {},
        fetch(url, options) {
            return new Promise(resolve => requests.push({ body: JSON.parse(options.body), resolve }));
        },
    };
    runInNewContext(`${saveSource}\n${submitSource}`.replace(/\{\{[^]*?\}\}/g, 'local'), context);

    await context.saveSettlementDraftToServer();
    assert.equal(requests.length, 0, 'Opening checkout must not save an empty draft');
    runInNewContext('initializingSettlement = false;', context);
    const firstSave = context.saveSettlementDraftToServer();
    context.currentDeposit = 300;
    overrideReason.value = 'Correcting the covered deposit';
    document.elements.inpCashTendered.value = '400';
    const secondSave = context.saveSettlementDraftToServer();
    await new Promise(setImmediate);
    assert.equal(requests.length, 1);
    assert.equal(Object.hasOwn(requests[0].body, 'security_deposit'), false, 'Drafts cannot submit a deposit collection');
    assert.equal(requests[0].body.cash_tendered, 200);
    assert.equal(requests[0].body.shift_override_reason, 'Covering assigned cashier');
    requests[0].resolve({ ok: true, json: async () => ({ success: true }) });
    await firstSave;
    await new Promise(setImmediate);
    assert.equal(requests.length, 2);
    assert.equal(Object.hasOwn(requests[1].body, 'security_deposit'), false);
    assert.equal(requests[1].body.cash_tendered, 400);
    assert.equal(requests[1].body.shift_override_reason, 'Correcting the covered deposit');

    const form = document.elements.checkoutProcessForm;
    const submission = form.listeners.submit.call(form, { preventDefault() {} });
    context.saveSettlementDraftToServer();
    await new Promise(setImmediate);
    assert.equal(submitted, false, 'Settlement must wait for outstanding drafts');
    requests[1].resolve({ ok: true, json: async () => ({ success: true }) });
    await secondSave;
    await submission;
    assert.equal(submitted, true);
    assert.equal(requests.length, 2, 'No draft may write after settlement starts');
});

test('checkout requires a reference only when a GCash payment is entered', () => {
    const blade = readFileSync(new URL('../../resources/views/checkout/process.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.indexOf('function calculateChange()'), blade.indexOf('// Initialize display state'));
    const document = documentWith(['paymentMethodSelect', 'inpCashTendered', 'inpGcashAmount', 'inpGcashRef', 'inpChange']);
    const context = { document, finalBalanceToPay: 785, depositSaveTimer: null, updatingStayEstimate: false, syncBillingStatementUrl() {}, clearTimeout() {}, setTimeout() {} };
    runInNewContext(source, context);
    document.elements.paymentMethodSelect.value = 'split';
    document.elements.inpCashTendered.value = '500.04';
    document.elements.inpGcashAmount.value = '300';
    context.calculateChange();
    assert.equal(document.elements.inpGcashRef.required, true);
    assert.equal(document.elements.inpChange.value, '₱15.04');
    document.elements.paymentMethodSelect.value = 'cash';
    context.calculateChange();
    assert.equal(document.elements.inpGcashRef.required, false);
    document.elements.paymentMethodSelect.value = 'split';
    document.elements.inpGcashAmount.value = '0';
    context.calculateChange();
    assert.equal(document.elements.inpGcashRef.required, false);
    document.elements.paymentMethodSelect.value = 'gcash';
    document.elements.inpGcashAmount.value = '785';
    context.calculateChange();
    assert.equal(document.elements.inpGcashRef.required, true);
});

test('checkout estimate follows the live clock and keeps the full fifteen minute grace', () => {
    const blade = readFileSync(new URL('../../resources/views/checkout/process.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.indexOf('function onCheckInTimeChange('), blade.indexOf('function onRoomRateChange('));
    const document = documentWith(['inpActualStayDiff', 'inpExcessOvertime', 'inpAddHoursRate']);
    const checkIn = '2026-10-14T04:04:58+08:00';
    const checkout = Date.parse(checkIn) + 3 * 3600000;
    let clock = checkout + 900000;
    let saves = 0;
    const context = { document, Date: class extends Date { static now() { return clock; } }, checkOutTimestamp: checkout,
        totalBookedHours: 3, overstayGraceMs: 900000, xtendHours: 0, addHourUnitPrice: 130,
        checkInSaveTimer: null, recalcGrossAndSettlement() {}, clearTimeout() {}, setTimeout() { saves++; } };
    runInNewContext(source, context);
    context.onCheckInTimeChange(checkIn);
    assert.equal(document.elements.inpExcessOvertime.value, '0 Hours');
    clock += 1000;
    context.onCheckInTimeChange(checkIn);
    assert.equal(document.elements.inpExcessOvertime.value, '1 Hours');
    clock = checkout + 2 * 3600000 + 10 * 60000;
    context.onCheckInTimeChange(checkIn);
    assert.equal(document.elements.inpExcessOvertime.value, '2 Hours');
    assert.equal(context.addHoursRate, 260);
    assert.equal(saves, 0, 'Live estimates must not continually submit settlement drafts');
});

test('extend modal submits the selected twelve or twenty-four hour package with its matching rate and checkout', () => {
    const blade = readFileSync(new URL('../../resources/views/dashboard/partials/folio-modals.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.indexOf('function openXtendModal('), blade.indexOf('function closeXtendModal('));
    const document = documentWith(['xtendForm', 'convertStayForm', 'xtendCurrentTier', 'xtendCurrentCheckout',
        'xtendConversionRate', 'xtendConversionCheckout', 'xtendConvert12Option', 'xtendConvertOption',
        'xtendConversionHint', 'xtendMode', 'xtendConversionReason', 'xtendModalTitle', 'xtendModal', 'xtendHoursOption',
        'xtendConversionTier', 'xtendConversionSubmit']);
    const options = { extendUrl: '/extend', convertUrl: '/convert', currentTier: '6h', currentCheckout: 'Current cutoff', canConvert: true,
        conversions: { '12h': { rate: 1195, checkout: '12-hour cutoff' }, '24h': { rate: 2100, checkout: '24-hour cutoff' } } };
    const context = { document, FOLIO_EXTENSION_OPTIONS: { 1: options }, currentExtensionOptions: null };
    runInNewContext(source, context);
    context.openXtendModal(1, '21');
    assert.equal(document.elements.xtendConvert12Option.disabled, false);
    assert.equal(document.elements.xtendConvertOption.disabled, false);
    for (const [tier, rate, checkout] of [['12h', '₱1,195.00', '12-hour cutoff'], ['24h', '₱2,100.00', '24-hour cutoff']]) {
        document.elements.xtendMode.value = tier;
        context.updateXtendMode();
        assert.equal(document.elements.convertStayForm.action, '/convert');
        assert.equal(document.elements.xtendConversionTier.value, tier);
        assert.equal(document.elements.xtendConversionRate.textContent, rate);
        assert.equal(document.elements.xtendConversionCheckout.textContent, checkout);
    }
    options.openTime = true;
    context.openXtendModal(1, '21');
    assert.equal(document.elements.xtendHoursOption.disabled, true);
    assert.equal(document.elements.xtendConversionTier.value, '12h');
});

test('a billing-printed stay cannot open the extension or conversion modal', () => {
    const blade = readFileSync(new URL('../../resources/views/dashboard/partials/folio-modals.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.indexOf('function openXtendModal('), blade.indexOf('function closeXtendModal('));
    const notices = [];
    const context = { FOLIO_EXTENSION_OPTIONS: { 1: { timeLocked: true } }, currentExtensionOptions: null,
        window: { alert: message => notices.push(message) },
        document: { getElementById() { throw new Error('Locked stay must not open an editable modal'); } } };
    runInNewContext(source, context);
    context.openXtendModal(1, '21');
    assert.equal(context.currentExtensionOptions, null);
    assert.match(notices[0], /Stay times and package are locked/);
});

test('midnight promo live checkout estimate keeps fractional booked hours through the full grace period', () => {
    const blade = readFileSync(new URL('../../resources/views/checkout/process.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.indexOf('function onCheckInTimeChange('), blade.indexOf('function onRoomRateChange('));
    const document = documentWith(['inpActualStayDiff', 'inpExcessOvertime', 'inpAddHoursRate']);
    const checkIn = '2026-10-15T23:15:37+08:00';
    const cutoff = Date.parse('2026-10-16T06:00:00+08:00');
    let clock = cutoff + 900000;
    const context = { document, Date: class extends Date { static now() { return clock; } }, checkOutTimestamp: cutoff,
        totalBookedHours: (cutoff - Date.parse(checkIn)) / 3600000, overstayGraceMs: 900000, xtendHours: 0, addHourUnitPrice: 130,
        checkInSaveTimer: null, recalcGrossAndSettlement() {}, clearTimeout() {}, setTimeout() {} };
    runInNewContext(source, context);
    context.onCheckInTimeChange(checkIn);
    assert.equal(document.elements.inpExcessOvertime.value, '0 Hours');
    clock += 1000;
    context.onCheckInTimeChange(checkIn);
    assert.equal(document.elements.inpExcessOvertime.value, '1 Hours');
    assert.equal(context.addHoursRate, 130);
});

test('admin time correction preview uses the fixed midnight cutoff and flags daytime arrivals', () => {
    const blade = readFileSync(new URL('../../resources/views/admin/check-in-times.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.indexOf("document.querySelectorAll('[data-time-correction-form]')"), blade.indexOf('</script>'));
    const listeners = {};
    const input = { value: '2026-10-15T23:30', checkValidity: () => true, addEventListener: (name, callback) => { listeners[name] = callback; } };
    const preview = { dataset: { midnightPromo: 'true', extraHours: '1', packageHours: '11' } };
    const form = { querySelector: selector => selector === '[data-corrected-time]' ? input : preview, addEventListener() {} };
    runInNewContext(source, { document: { querySelectorAll: () => [form] }, Date, Intl });
    assert.match(preview.textContent, /10\/16\/2026.*07:00 AM/);
    input.value = '2026-10-16T01:30';
    listeners.input();
    assert.match(preview.textContent, /10\/16\/2026.*07:00 AM/);
    input.value = '2026-10-16T12:00';
    listeners.input();
    assert.match(preview.textContent, /must be between 8 PM and 6 AM/);
    preview.dataset.midnightPromo = 'false';
    preview.dataset.packageHours = '3';
    listeners.input();
    assert.match(preview.textContent, /10\/16\/2026.*03:00 PM/);
});

test('invalid admin correction fields display an inline explanation before submission', () => {
    const blade = readFileSync(new URL('../../resources/views/admin/check-in-times.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.indexOf("document.querySelectorAll('[data-time-correction-form]')"), blade.indexOf('</script>'));
    const listeners = {};
    const input = { value: '2026-10-16T11:00', checkValidity: () => true, addEventListener() {} };
    const preview = { dataset: { midnightPromo: 'false', packageHours: '3' } };
    const feedback = { hidden: true, textContent: '' };
    const form = {
        querySelector: selector => ({ '[data-corrected-time]': input, '[data-corrected-checkout]': preview, '[data-correction-feedback]': feedback })[selector],
        addEventListener: (name, handler) => { listeners[name] = handler; },
    };
    runInNewContext(source, { document: { querySelectorAll: () => [form] }, Date, Intl });
    assert.equal(typeof listeners.invalid, 'function');
    listeners.invalid({ target: { name: 'reason', validationMessage: 'Please fill out this field.' } });
    assert.equal(feedback.hidden, false);
    assert.match(feedback.textContent, /Enter a reason/);
    listeners.invalid({ target: { name: 'checked_in_at', validationMessage: 'Value must be earlier.' } });
    assert.match(feedback.textContent, /Value must be earlier/);
});

test('mobile navigation restores page interaction on dismissal and desktop resize', () => {
    const blade = readFileSync(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.indexOf('function togglePrimaryNav()'), blade.indexOf('function toggleNavDropdown(event)'));
    const element = (...initialClasses) => {
        const classes = new Set(initialClasses);
        return {
            attributes: {}, focusCount: 0,
            classList: {
                contains: name => classes.has(name),
                add: name => classes.add(name),
                remove: name => classes.delete(name),
                toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
            },
            setAttribute(name, value) { this.attributes[name] = value; },
            focus() { this.focusCount++; },
        };
    };
    const sidebar = element();
    const firstLink = element();
    sidebar.querySelector = () => firstLink;
    const overlay = element('hidden');
    const toggle = element();
    const workspace = { inert: false };
    const body = element();
    const elements = { primaryNavMobile: sidebar, sidebarOverlay: overlay, primaryNavToggle: toggle };
    let onBreakpointChange;
    const document = { body, getElementById: id => elements[id], querySelector: () => workspace };
    const window = { matchMedia: () => ({ addEventListener: (event, handler) => { onBreakpointChange = handler; } }) };
    const context = { document, window };
    runInNewContext(source, context);

    context.togglePrimaryNav();
    assert.equal(sidebar.classList.contains('is-open'), true);
    assert.equal(overlay.classList.contains('hidden'), false);
    assert.equal(workspace.inert, true);
    assert.equal(body.classList.contains('nav-open'), true);
    assert.equal(toggle.attributes['aria-expanded'], 'true');
    assert.equal(firstLink.focusCount, 1);

    onBreakpointChange({ matches: false });
    assert.equal(workspace.inert, true);
    onBreakpointChange({ matches: true });
    assert.equal(sidebar.classList.contains('is-open'), false);
    assert.equal(overlay.classList.contains('hidden'), true);
    assert.equal(workspace.inert, false);
    assert.equal(body.classList.contains('nav-open'), false);
    assert.equal(toggle.attributes['aria-expanded'], 'false');
    assert.equal(toggle.focusCount, 0, 'Desktop resize must not focus the hidden mobile button');

    context.togglePrimaryNav();
    context.closePrimaryNav();
    assert.equal(workspace.inert, false);
    assert.equal(toggle.focusCount, 1);
});
test('explicit check-in save waits for payment drafts and keeps unsaved time out of those drafts', async () => {
    const blade = readFileSync(new URL('../../resources/views/checkout/process.blade.php', import.meta.url), 'utf8');
    const saveSource = blade.slice(blade.indexOf('let initializingSettlement = true;'), blade.indexOf('function togglePaymentInputs()'));
    const handlerSource = blade.slice(blade.indexOf("document.getElementById('checkoutCheckInTimeForm')?.addEventListener('submit'"), blade.indexOf("document.getElementById('checkoutProcessForm').addEventListener('submit'"));
    const document = documentWith(['paymentMethodSelect', 'inpCashTendered', 'inpGcashAmount', 'inpGcashRef', 'inpDiscountRef', 'inpCheckInTime', 'checkoutCheckInTimeForm', 'settlementSaveStatus']);
    document.querySelector = selector => selector.includes('shift_override_reason') ? { value: '' } : { value: 'csrf-token' };
    document.elements.paymentMethodSelect.value = 'cash';
    document.elements.inpCashTendered.value = '1000';
    document.elements.inpCheckInTime.value = '2026-10-17T13:00:00+08:00';
    const requests = [];
    let submitted = false;
    const form = document.elements.checkoutCheckInTimeForm;
    form.submit = () => { submitted = true; };
    const context = { document, currentDeposit: 0, applyDeposit: true, currentDiscountType: 'NONE',
        currentRoomRate: 395, finalBalanceToPay: 395, depositSaveTimer: null, checkInSaveTimer: null,
        clearTimeout() {}, fetch(url, options) { return new Promise(resolve => requests.push({ body: JSON.parse(options.body), resolve })); } };
    runInNewContext(`${saveSource}\n${handlerSource}`.replace(/\{\{[^]*?\}\}/g, 'local'), context);
    runInNewContext('initializingSettlement = false;', context);
    assert.equal(requests.length, 0, 'No change should save before the cashier clicks Save');
    const submission = form.listeners.submit.call(form, { preventDefault() {} });
    await new Promise(setImmediate);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].body.cash_tendered, 1000);
    assert.equal(requests[0].body.checked_in_at, '2026-10-17T13:00:00+08:00');
    assert.equal(Object.hasOwn(requests[0].body, 'actual_checked_in_at'), false);
    assert.equal(submitted, false);
    requests[0].resolve({ ok: true, json: async () => ({ success: true }) });
    await submission;
    assert.equal(submitted, true, 'Time correction posts only after payment drafts finish');
    await context.saveSettlementDraftToServer();
    assert.equal(requests.length, 1, 'A stale draft cannot write after time correction begins');
});
