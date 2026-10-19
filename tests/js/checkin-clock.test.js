import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

test('check-in displays Manila time without submitting a cashier editable timestamp', () => {
    const blade = readFileSync(new URL('../../resources/views/dashboard/partials/folio-modals.blade.php', import.meta.url), 'utf8');
    const source = blade.slice(blade.indexOf('function syncCheckInLiveTime()'), blade.indexOf('function openCheckInModal('));
    class DeviceDate extends Date {
        constructor() {
            super('2026-10-08T16:15:00.000Z');
        }

        getFullYear() { return 2026; }
        getMonth() { return 9; }
        getDate() { return 8; }
        getHours() { return 9; }
        getMinutes() { return 15; }
        getSeconds() { return 0; }
    }
    const elements = { checkInDateTimeDisplay: {} };
    const context = { Date: DeviceDate, document: { getElementById: id => elements[id] } };
    runInNewContext(source, context);

    context.syncCheckInLiveTime();

    assert.doesNotMatch(blade, /name="checked_in_at"/);
    assert.equal(elements.checkInDateTimeDisplay.value, '10/09/2026 12:15:00 AM');
});
