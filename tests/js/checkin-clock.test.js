import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

test('check-in displays Manila time without submitting a cashier editable timestamp', () => {
    const blade = readFileSync(new URL('../../resources/views/dashboard/partials/folio-modals.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.indexOf('function syncCheckInLiveTime()'), blade.indexOf('function openCheckInModal('));
    const serverTime = Date.parse('2026-10-08T16:15:00.000Z');
    class DeviceDate extends Date {
        constructor(timestamp = serverTime + 16 * 3600000) {
            super(timestamp);
        }
    }
    const elements = { checkInDateTimeDisplay: {} };
    const context = {
        Date: DeviceDate,
        window: { sedonaClock: { now: () => serverTime } },
        document: { getElementById: id => elements[id] },
    };
    runInNewContext(source, context);

    context.syncCheckInLiveTime();

    assert.doesNotMatch(blade, /name="checked_in_at"/);
    assert.equal(elements.checkInDateTimeDisplay.value, '10/09/2026 12:15:00 AM');
});
