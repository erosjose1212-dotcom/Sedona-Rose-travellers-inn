import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

test('alarm recordings are valid, distinct and loud PCM audio with the intended repeat lengths', () => {
    const recordings = [['stay-warning.wav', 8], ['checkout-due.wav', 4], ['grace-ended.wav', 2], ['open-time.wav', 2]];
    const buffers = recordings.map(([file, seconds]) => {
        const buffer = readFileSync(new URL(`../../public/sounds/${file}`, import.meta.url));
        assert.equal(buffer.toString('ascii', 0, 4), 'RIFF');
        assert.equal(buffer.toString('ascii', 8, 12), 'WAVE');
        assert.equal(buffer.readUInt16LE(20), 1);
        assert.equal(buffer.readUInt16LE(22), 1);
        assert.equal(buffer.readUInt32LE(24), 22050);
        assert.equal(buffer.readUInt16LE(34), 16);
        assert.equal(buffer.readUInt32LE(40), seconds * 22050 * 2);
        let peak = 0;
        for (let offset = 44; offset < buffer.length; offset += 2) {
            peak = Math.max(peak, Math.abs(buffer.readInt16LE(offset)) / 32768);
        }
        assert.ok(peak >= 0.89 && peak < 1, `${file} must be audible without clipping`);
        return buffer;
    });
    assert.equal(new Set(buffers.map(buffer => buffer.toString('base64'))).size, 4);
});

function audioHarness(play = () => Promise.resolve(), clock = () => Date.now(), stored = new Map(), audioState = { unlocked: true }) {
    const intervals = new Map();
    const oscillators = [];
    const gains = [];
    const listeners = {};
    class Audio {
        constructor(src) { this.src = src; this.paused = true; this.currentTime = 0; this.playCalls = 0; }
        play() { this.paused = false; this.playCalls++; return play(this); }
        pause() { this.paused = true; }
    }
    class AudioContext {
        constructor() { this.currentTime = 0; this.state = audioState.unlocked ? 'running' : 'suspended'; this.destination = {}; }
        resume() { if (audioState.unlocked) this.state = 'running'; return Promise.resolve(); }
        createOscillator() {
            const oscillator = { frequency: { setValueAtTime(value) { oscillator.frequencyValue = value; } },
                connect() {}, disconnect() {}, start(at) { oscillator.startAt = at; },
                stop(at) { oscillator.stopAt = at; if (at === undefined) { oscillator.stopped = true; } } };
            oscillators.push(oscillator);
            return oscillator;
        }
        createGain() {
            const values = [];
            const gain = { gain: { setValueAtTime(value, at) { values.push([value, at]); },
                linearRampToValueAtTime(value, at) { values.push([value, at]); } }, connect() {}, disconnect() {}, values };
            gains.push(gain);
            return gain;
        }
    }
    const window = { AudioContext, addEventListener(name, action) { listeners[name] = action; }, removeEventListener() {} };
    const storage = { getItem(key) { return stored.get(key) ?? null; }, setItem(key, value) { stored.set(key, value); } };
    const document = { getElementById() { return null; }, querySelectorAll() { return []; } };
    const source = readFileSync(new URL('../../resources/js/audio.js', import.meta.url), 'utf8').split('// Global instance')[0];
    runInNewContext(`${source}\nwindow.Engine = SedonaAudioEngine;`, {
        window, document, Audio, localStorage: storage, sessionStorage: storage, console,
        Date: class extends Date { static now() { return clock(); } },
        setInterval(action, delay) { const id = intervals.size + 1; intervals.set(id, { action, delay }); return id; },
        clearInterval(id) { intervals.delete(id); },
    });
    return { engine: new window.Engine(), intervals, oscillators, gains, listeners, document, stored };
}

function openTimeRow() {
    const attributes = { 'data-room-id': '8', 'data-room-number': '8', 'data-folio-id': '123', 'data-stay-mode': 'open',
        'data-checkin-time': '2026-10-16T10:00:00+08:00', 'data-checkout-time': '2026-10-16T13:00:00+08:00', 'data-base-rate': '395.00' };
    const timer = { textContent: '' };
    return { attributes, timer, getAttribute: name => attributes[name], querySelector: () => timer };
}

test('an autoplay-blocked Open Time reminder remains pending until audio is unlocked, then rings once', async () => {
    let clock = Date.parse('2026-10-16T12:45:00+08:00');
    const audioState = { unlocked: false };
    const { engine, document, listeners, oscillators } = audioHarness(
        () => Promise.reject(new Error('Autoplay blocked')), () => clock, new Map(), audioState,
    );
    document.querySelectorAll = () => [openTimeRow()];
    engine.evaluateAlarms();
    await Promise.resolve();
    assert.equal(engine.openTimeReminders.size, 0, 'Blocked playback must not consume the reminder');
    clock += 6000;
    engine.evaluateAlarms();
    assert.equal(engine.openTimeReminders.size, 0);
    const blockedAttempts = engine.alarmAudios.open.playCalls;
    clock += 6000;
    engine.evaluateAlarms();
    assert.equal(engine.alarmAudios.open.playCalls, blockedAttempts, 'Wait for a gesture instead of repeatedly attempting blocked audio');
    audioState.unlocked = true;
    listeners.click();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    engine.evaluateAlarms();
    await Promise.resolve();
    assert.equal(engine.openTimeReminders.size, 1);
    assert.equal(oscillators.filter(oscillator => !oscillator.stopped).length, 5);
    clock += 6000;
    engine.evaluateAlarms();
    const played = engine.alarmAudios.open.playCalls;
    clock += 6000;
    engine.evaluateAlarms();
    assert.equal(engine.alarmAudios.open.playCalls, played, 'Delivered reminders must not repeat');
});

for (const action of ['mute', 'acknowledge', 'checkout', 'convert']) {
    test(`unlocking blocked Open Time audio does not revive a reminder after ${action}`, async () => {
        const clock = Date.parse('2026-10-16T12:45:00+08:00');
        const audioState = { unlocked: false };
        const { engine, document, listeners, oscillators } = audioHarness(
            () => Promise.reject(new Error('Autoplay blocked')), () => clock, new Map(), audioState,
        );
        const row = openTimeRow();
        document.querySelectorAll = () => [row];
        engine.evaluateAlarms();
        await Promise.resolve();
        if (action === 'mute') engine.toggleMute();
        if (action === 'acknowledge') engine.silenceStage('8', row.attributes['data-checkout-time'], 'open-123-1');
        if (action === 'checkout') document.querySelectorAll = () => [];
        if (action === 'convert') {
            row.attributes['data-stay-mode'] = 'fixed';
            row.attributes['data-checkout-time'] = '2026-10-16T22:00:00+08:00';
        }
        audioState.unlocked = true;
        listeners.click();
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
        engine.evaluateAlarms();
        assert.equal(engine.openTimeReminders.size, 0);
        assert.equal(oscillators.length, 0);
        assert.equal(engine.currentSoundMode, null);
    });
}

test('Open Time rings briefly at base reminders then once hourly without changing billing grace', async () => {
    let clock = Date.parse('2026-10-16T12:44:59+08:00');
    const { engine, document } = audioHarness(undefined, () => clock);
    const row = openTimeRow();
    document.querySelectorAll = () => [row];
    const audio = engine.alarmAudios.open;
    engine.evaluateAlarms();
    assert.equal(audio.playCalls, 0);
    for (const [time, count] of [['12:45:00', 1], ['13:00:00', 2], ['13:15:00', 3], ['14:15:00', 4], ['15:15:00', 5]]) {
        clock = Date.parse(`2026-10-16T${time}+08:00`);
        engine.evaluateAlarms();
        await Promise.resolve();
        assert.equal(audio.playCalls, count);
        assert.equal(audio.loop, false);
        clock += 2000;
        engine.evaluateAlarms();
        assert.equal(audio.playCalls, count);
        clock += 4000;
        engine.evaluateAlarms();
        assert.equal(engine.currentSoundMode, null);
        assert.equal(audio.paused, true);
    }
    assert.match(row.timer.textContent, /OPEN TIME.*5h 15m used.*₱785\.00/);
    for (const audio of [engine.alarmAudios.stage1, engine.alarmAudios.stage2, engine.alarmAudios.stage3]) assert.equal(audio.playCalls, 0);
});

test('Open Time reminders survive refresh, respect mute and acknowledgment, and stop at checkout', async () => {
    let clock = Date.parse('2026-10-16T12:45:00+08:00');
    const first = audioHarness(undefined, () => clock);
    const row = openTimeRow();
    first.document.querySelectorAll = () => [row];
    first.engine.evaluateAlarms();
    await Promise.resolve();
    const reloaded = audioHarness(undefined, () => clock, first.stored);
    reloaded.document.querySelectorAll = () => [row];
    reloaded.engine.evaluateAlarms();
    assert.equal(reloaded.engine.alarmAudios.open.playCalls, 0);
    clock = Date.parse('2026-10-16T13:00:00+08:00');
    reloaded.engine.toggleMute();
    reloaded.engine.evaluateAlarms();
    assert.equal(reloaded.engine.alarmAudios.open.playCalls, 0);
    reloaded.engine.toggleMute();
    reloaded.engine.evaluateAlarms();
    await Promise.resolve();
    assert.equal(reloaded.engine.alarmAudios.open.playCalls, 1);
    reloaded.engine.silenceStage('8', row.attributes['data-checkout-time'], 'open-123-2');
    assert.equal(reloaded.engine.currentSoundMode, null);
    clock = Date.parse('2026-10-16T13:15:00+08:00');
    reloaded.engine.evaluateAlarms();
    assert.equal(reloaded.engine.alarmAudios.open.playCalls, 2);
    assert.match(row.timer.textContent, /₱395\.00/);
    clock += 1000;
    reloaded.engine.evaluateAlarms();
    assert.match(row.timer.textContent, /₱525\.00/);
    reloaded.document.querySelectorAll = () => [];
    reloaded.engine.evaluateAlarms();
    assert.equal(reloaded.engine.currentSoundMode, null);
    assert.equal(reloaded.engine.alarmAudios.open.paused, true);
});

test('Open Time converts back to normal package alarms and fixed alarms retain priority', () => {
    let clock = Date.parse('2026-10-16T12:45:00+08:00');
    const { engine, document } = audioHarness(undefined, () => clock);
    const row = openTimeRow();
    document.querySelectorAll = () => [row];
    engine.evaluateAlarms();
    row.attributes['data-stay-mode'] = 'fixed';
    row.attributes['data-checkout-time'] = '2026-10-16T22:00:00+08:00';
    engine.evaluateAlarms();
    assert.equal(engine.currentSoundMode, null);
    clock = Date.parse('2026-10-16T21:45:00+08:00');
    engine.evaluateAlarms();
    assert.equal(engine.currentSoundMode, 'stage1');
    const open = openTimeRow();
    open.attributes['data-room-id'] = '9';
    document.querySelectorAll = () => [row, open];
    engine.evaluateAlarms();
    assert.equal(engine.currentSoundMode, 'stage1');
});

test('Open Time synthesized fallback plays its own tune once and schedules no looping interval', async () => {
    const { engine, intervals, oscillators } = audioHarness(() => Promise.reject(new Error('Media unavailable')));
    engine.setSoundMode('open');
    await Promise.resolve();
    assert.deepEqual(oscillators.map(oscillator => oscillator.frequencyValue), [659.25, 783.99, 1046.5, 783.99, 659.25]);
    assert.equal(intervals.size, 0);
});

test('acknowledging a previous guest cannot silence or duplicate the next stay reminder in the same room', async () => {
    let clock = Date.parse('2026-10-16T12:45:00+08:00');
    const { engine, document } = audioHarness(undefined, () => clock);
    const row = openTimeRow();
    document.querySelectorAll = () => [row];
    engine.evaluateAlarms();
    await Promise.resolve();
    engine.silenceStage('8', row.attributes['data-checkout-time'], 'open-123-1');
    row.attributes['data-folio-id'] = '456';
    engine.evaluateAlarms();
    assert.equal(engine.alarmAudios.open.playCalls, 1);
    clock += 6000;
    engine.evaluateAlarms();
    await Promise.resolve();
    assert.equal(engine.alarmAudios.open.playCalls, 2);
    clock += 6000;
    engine.evaluateAlarms();
    assert.equal(engine.alarmAudios.open.playCalls, 2);
});

test('each checkout stage uses its own looping tune at full application volume', () => {
    const { engine } = audioHarness();
    const files = ['stay-warning.wav', 'checkout-due.wav', 'grace-ended.wav'];
    const played = [];
    for (const [index, mode] of ['stage1', 'stage2', 'stage3'].entries()) {
        engine.setSoundMode(mode);
        const audio = engine.alarmAudios[mode];
        assert.equal(audio.src, `/sounds/${files[index]}`);
        assert.equal(audio.loop, true);
        assert.equal(audio.volume, 1);
        assert.equal(audio.paused, false);
        for (const previous of played) { assert.equal(previous.paused, true); }
        played.push(audio);
    }
    engine.toggleMute();
    for (const audio of played) { assert.equal(audio.paused, true); }
});

test('synth fallback keeps recognizable rising, ding-dong, and urgent tunes with faster repeats', async () => {
    const { engine, intervals, oscillators, gains } = audioHarness(() => Promise.reject(new Error('Media unavailable')));
    const expectations = [
        ['stage1', [523.25, 659.25, 783.99, 1046.5], 8000],
        ['stage2', [783.99, 523.25, 783.99, 523.25], 4000],
        ['stage3', [1318.51, 1046.5, 1318.51, 1046.5, 1318.51, 1046.5], 2000],
    ];
    for (const [mode, notes, interval] of expectations) {
        const before = oscillators.length;
        engine.setSoundMode(mode);
        await Promise.resolve();
        assert.deepEqual(oscillators.slice(before).map(osc => osc.frequencyValue), notes);
        assert.equal(intervals.size, 1);
        assert.equal([...intervals.values()][0].delay, interval);
        assert.ok(gains.slice(before).every(gain => Math.max(...gain.values.map(([value]) => value)) >= 0.75));
    }
    engine.stopAllAlarms();
    assert.equal(intervals.size, 0);
    assert.ok(oscillators.every(osc => osc.stopped));
});

test('a late failed media request cannot restart a silenced or replaced alarm', async () => {
    const pending = [];
    const { engine, intervals, oscillators } = audioHarness(() => new Promise((resolve, reject) => pending.push({ resolve, reject })));
    engine.setSoundMode('stage1');
    engine.setSoundMode('stage2');
    pending[0].reject(new Error('Old request failed'));
    await Promise.resolve();
    assert.equal(oscillators.length, 0);
    assert.equal(intervals.size, 0);
    engine.stopAllAlarms();
    pending[1].reject(new Error('Silenced request failed'));
    await Promise.resolve();
    assert.equal(oscillators.length, 0);
    assert.equal(intervals.size, 0);
});

test('unlocking audio does not pause or mute a newly started checkout alarm', async () => {
    const pending = [];
    const { engine, listeners } = audioHarness(() => new Promise(resolve => pending.push(resolve)));
    listeners.click();
    engine.setSoundMode('stage1');
    for (const resolve of pending) { resolve(); }
    await Promise.resolve();
    assert.equal(engine.alarmAudios.stage1.paused, false);
    assert.equal(engine.alarmAudios.stage1.muted, false);
});
