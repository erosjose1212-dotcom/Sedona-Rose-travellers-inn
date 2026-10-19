/**
 * Sedona Court Executive PMS - Audio Engine & Reactive 3-Stage Stay Alarm System
 * High-reliability dual-engine: HTML5 Audio with Web Audio API Synthesizer Fallback
 * 
 * 3-Stage Alarm Timeline:
 *  - 1st Alarm: 15 minutes before checkout (T - 15m) -> Ascending Harmonious Warning Chime
 *  - 2nd Alarm: Actual checkout time (T)              -> Repeating Two-Pitch Checkout Tune
 *  - 3rd Alarm: 15 minutes after checkout (T + 15m)   -> Fast Alternating Grace-Ended Tune
 * 
 * Dynamic Recalibration on Xtend (Stay Extension):
 *  - Keyed by roomId/folioId + checkoutTime timestamp.
 *  - When extended, any active alarm immediately halts and all 3 stages reschedule to the new time.
 */

const STAY_ALARM_TUNES = {
    "stage1": {
        "file": "stay-warning.wav",
        "repeatMs": 8000,
        "volume": 0.8,
        "notes": [
            [
                523.25,
                0,
                0.22
            ],
            [
                659.25,
                0.26,
                0.22
            ],
            [
                783.99,
                0.52,
                0.22
            ],
            [
                1046.5,
                0.78,
                0.5
            ]
        ]
    },
    "stage2": {
        "file": "checkout-due.wav",
        "repeatMs": 4000,
        "volume": 0.85,
        "notes": [
            [
                783.99,
                0,
                0.18
            ],
            [
                523.25,
                0.24,
                0.35
            ],
            [
                783.99,
                0.7,
                0.18
            ],
            [
                523.25,
                0.94,
                0.45
            ]
        ]
    },
    "open": {
        "file": "open-time.wav",
        "repeatMs": 2000,
        "once": true,
        "volume": 0.85,
        "notes": [[659.25, 0, 0.18], [783.99, 0.24, 0.18], [1046.5, 0.48, 0.3], [783.99, 0.84, 0.18], [659.25, 1.08, 0.35]]
    },
    "stage3": {
        "file": "grace-ended.wav",
        "repeatMs": 2000,
        "volume": 0.9,
        "notes": [
            [
                1318.51,
                0,
                0.14
            ],
            [
                1046.5,
                0.2,
                0.14
            ],
            [
                1318.51,
                0.4,
                0.14
            ],
            [
                1046.5,
                0.6,
                0.14
            ],
            [
                1318.51,
                0.8,
                0.14
            ],
            [
                1046.5,
                1,
                0.14
            ]
        ]
    }
};

class SedonaAudioEngine {
    constructor() {
        this.audioCtx = null;
        this.isMuted = localStorage.getItem('sedona_audio_muted') === 'true';
        this.alarmAudios = {};
        this.activeAlarmOscillators = new Set();
        this.soundGeneration = 0;
        this.kitchenAudio = null;

        // Current active sound mode: null | 'stage1' | 'stage2' | 'stage3' | 'open'
        this.currentSoundMode = null;
        this.synthAlarmInterval = null;

        // Snoozed rooms: { [roomId]: timestampUntil }
        this.snoozedRooms = {};

        // Silenced stages: Set of `${roomId}_${checkoutTimeISO}_stage${stageNum}`
        this.silencedStages = this.loadSilencedStages();
        this.openTimeReminders = this.loadSilencedStages('sedona_open_time_reminders');
        this.openTimeBurstUntil = 0;
        this.openTimePendingKeys = [];
        this.openTimeAwaitingUnlock = false;

        this.initHtml5Audio();
        this.initAutoUnlock();
    }

    loadSilencedStages(storageKey = 'sedona_silenced_alarm_stages') {
        try {
            const stored = sessionStorage.getItem(storageKey);
            if (stored) {
                return new Set(JSON.parse(stored));
            }
        } catch (e) {}
        return new Set();
    }

    saveSilencedStages() {
        try {
            sessionStorage.setItem('sedona_silenced_alarm_stages', JSON.stringify([...this.silencedStages]));
        } catch (e) {}
    }

    initHtml5Audio() {
        for (const [mode, tune] of Object.entries(STAY_ALARM_TUNES)) {
            try {
                const audio = new Audio('/sounds/' + tune.file);
                audio.loop = !tune.once;
                audio.volume = 1;
                audio.preload = 'auto';
                this.alarmAudios[mode] = audio;
            } catch (error) {
                console.warn('[AudioEngine] Alarm audio unavailable:', error);
            }
        }
        try {
            this.kitchenAudio = new Audio('/sounds/kitchen-chime.mp3');
            this.kitchenAudio.volume = 0.9;
        } catch (error) {
            console.warn('[AudioEngine] Kitchen audio unavailable:', error);
        }
    }

    initAutoUnlock() {
        const unlock = () => {
            const resumed = this.ensureAudioContext();
            for (const [mode, audio] of Object.entries(this.alarmAudios)) {
                if (this.currentSoundMode === mode) continue;
                audio.muted = true;
                audio.play().then(() => {
                    if (this.currentSoundMode !== mode) {
                        audio.pause();
                        audio.currentTime = 0;
                    }
                    audio.muted = false;
                }).catch(() => { audio.muted = false; });
            }
            window.removeEventListener('click', unlock);
            window.removeEventListener('keydown', unlock);
            window.removeEventListener('touchstart', unlock);
            Promise.resolve(resumed).then(() => {
                if (this.openTimeAwaitingUnlock) {
                    this.openTimeAwaitingUnlock = false;
                    this.openTimeBurstUntil = 0;
                    this.evaluateAlarms();
                }
            });
        };
        window.addEventListener('click', unlock, { once: true });
        window.addEventListener('keydown', unlock, { once: true });
        window.addEventListener('touchstart', unlock, { once: true });
    }

    ensureAudioContext() {
        if (!this.audioCtx) {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (AudioContextClass) {
                this.audioCtx = new AudioContextClass();
            }
        }
        if (this.audioCtx && this.audioCtx.state === 'suspended') {
            return this.audioCtx.resume();
        }
    }

    toggleMute() {
        this.isMuted = !this.isMuted;
        localStorage.setItem('sedona_audio_muted', this.isMuted ? 'true' : 'false');
        if (this.isMuted) {
            this.stopAllAlarms();
        }
        this.updateAudioButtonState();
        return this.isMuted;
    }

    updateAudioButtonState() {
        const btn = document.getElementById('soundToggleBtn');
        const icon = document.getElementById('soundToggleIcon');
        const text = document.getElementById('soundToggleText');
        if (btn && icon && text) {
            if (this.isMuted) {
                btn.classList.remove('bg-emerald-800', 'text-emerald-100');
                btn.classList.add('bg-slate-700', 'text-slate-300');
                icon.textContent = '🔇';
                text.textContent = 'Muted';
            } else {
                btn.classList.remove('bg-slate-700', 'text-slate-300');
                btn.classList.add('bg-emerald-800', 'text-emerald-100');
                icon.textContent = '🔊';
                text.textContent = 'Sound ON';
            }
        }
    }

    /* -------------------------------------------------------------------------
     * SOUND ENGINE DISPATCHER (Stage 1, Stage 2, Stage 3)
     * ------------------------------------------------------------------------- */

    /**
     * Set the desired sound mode. Priority: stage3 > stage2 > stage1 > null
     */
    setSoundMode(mode) {
        if (this.isMuted) {
            this.stopAllAlarms();
            return;
        }

        if (this.currentSoundMode === mode) {
            // Already running in this mode
            return;
        }

        // Stop current sounds before switching
        this.stopAllAlarms();
        this.currentSoundMode = mode;

        if (mode === 'stage3') {
            this.startStage3Sound();
        } else if (mode === 'open') {
            this.startAlarmTune('open');
        } else if (mode === 'stage2') {
            this.startStage2Sound();
        } else if (mode === 'stage1') {
            this.startStage1Sound();
        }
    }

    /**
     * Stop all active alarm sounds
     */
    stopAllAlarms() {
        this.currentSoundMode = null;
        this.soundGeneration++;
        for (const audio of Object.values(this.alarmAudios)) {
            try {
                audio.pause();
                audio.currentTime = 0;
            } catch (error) {}
        }
        if (this.synthAlarmInterval !== null) {
            clearInterval(this.synthAlarmInterval);
            this.synthAlarmInterval = null;
        }
        for (const oscillator of this.activeAlarmOscillators) {
            try { oscillator.stop(); } catch (error) {}
        }
        this.activeAlarmOscillators.clear();
    }

    // Alias for legacy buttons
    stopDigitalAlarm() {
        this.stopAllAlarms();
    }

    startStage1Sound() {
        this.startAlarmTune('stage1');
    }

    startStage2Sound() {
        this.startAlarmTune('stage2');
    }

    startStage3Sound() {
        this.startAlarmTune('stage3');
    }

    startAlarmTune(mode) {
        const generation = this.soundGeneration;
        const fallback = () => {
            if (this.currentSoundMode === mode && this.soundGeneration === generation && !this.isMuted) {
                this.startSynthTune(mode);
            }
        };
        const audio = this.alarmAudios[mode];
        if (!audio) {
            fallback();
            return;
        }
        try {
            audio.currentTime = 0;
            audio.muted = false;
            const playback = audio.play();
            if (playback) playback.then(() => this.markOpenTimeDelivered(mode, generation), fallback);
        } catch (error) {
            fallback();
        }
    }

    startSynthTune(mode) {
        if (this.synthAlarmInterval !== null) return;
        const tune = STAY_ALARM_TUNES[mode];
        const playTune = () => {
            if (this.isMuted || this.currentSoundMode !== mode) return;
            this.ensureAudioContext();
            if (!this.audioCtx) return;
            if (mode === 'open' && this.audioCtx.state !== 'running') {
                this.openTimeAwaitingUnlock = true;
                return;
            }
            this.markOpenTimeDelivered(mode, this.soundGeneration);
            const now = this.audioCtx.currentTime;
            for (const [frequency, offset, duration] of tune.notes) {
                const oscillator = this.audioCtx.createOscillator();
                const gain = this.audioCtx.createGain();
                const start = now + offset;
                oscillator.type = 'triangle';
                oscillator.frequency.setValueAtTime(frequency, start);
                gain.gain.setValueAtTime(0, start);
                gain.gain.linearRampToValueAtTime(tune.volume, start + 0.015);
                gain.gain.setValueAtTime(tune.volume, start + duration - 0.04);
                gain.gain.linearRampToValueAtTime(0, start + duration);
                oscillator.connect(gain);
                gain.connect(this.audioCtx.destination);
                this.activeAlarmOscillators.add(oscillator);
                oscillator.onended = () => {
                    oscillator.disconnect();
                    gain.disconnect();
                    this.activeAlarmOscillators.delete(oscillator);
                };
                oscillator.start(start);
                oscillator.stop(start + duration);
            }
        };
        playTune();
        if (!tune.once) this.synthAlarmInterval = setInterval(playTune, tune.repeatMs);
    }

    markOpenTimeDelivered(mode, generation) {
        if (mode !== 'open' || this.currentSoundMode !== mode || this.soundGeneration !== generation || this.isMuted) return;
        for (const key of this.openTimePendingKeys) this.openTimeReminders.add(key);
        try { sessionStorage.setItem('sedona_open_time_reminders', JSON.stringify([...this.openTimeReminders])); } catch (error) {}
    }

    /* -------------------------------------------------------------------------
     * KITCHEN BELL & OTHER CHIMES
     * ------------------------------------------------------------------------- */
    playKitchenChime() {
        if (this.isMuted) return;

        if (this.kitchenAudio) {
            this.kitchenAudio.currentTime = 0;
            this.kitchenAudio.play().catch(() => {
                this.synthesizeKitchenBell();
            });
        } else {
            this.synthesizeKitchenBell();
        }
    }

    synthesizeKitchenBell() {
        this.ensureAudioContext();
        if (!this.audioCtx) return;

        try {
            const chords = [587.33, 739.99, 880.00, 1174.66];
            const now = this.audioCtx.currentTime;

            chords.forEach((freq, idx) => {
                const osc = this.audioCtx.createOscillator();
                const gain = this.audioCtx.createGain();

                osc.type = 'triangle';
                osc.frequency.setValueAtTime(freq, now + idx * 0.12);

                gain.gain.setValueAtTime(0, now + idx * 0.12);
                gain.gain.linearRampToValueAtTime(0.3, now + idx * 0.12 + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.12 + 0.6);

                osc.connect(gain);
                gain.connect(this.audioCtx.destination);

                osc.start(now + idx * 0.12);
                osc.stop(now + idx * 0.12 + 0.62);
            });
        } catch (e) {}
    }

    /* -------------------------------------------------------------------------
     * SNOOZE, SILENCE, AND STATE CONTROLS
     * ------------------------------------------------------------------------- */

    /**
     * Snooze room for X minutes (default 5m)
     */
    snoozeRoom(roomId, minutes = 5) {
        this.snoozedRooms[roomId] = Date.now() + (minutes * 60 * 1000);
        this.evaluateAlarms();
    }

    isRoomSnoozed(roomId) {
        const snoozeUntil = this.snoozedRooms[roomId];
        if (!snoozeUntil) return false;
        if (Date.now() < snoozeUntil) return true;
        delete this.snoozedRooms[roomId];
        return false;
    }

    /**
     * Silence a specific stage for a room stay (e.g. Stage 1 silenced, but Stage 2 will still ring!)
     */
    silenceStage(roomId, checkoutTimeStr, stageNum) {
        const key = `${roomId}_${checkoutTimeStr}_stage${stageNum}`;
        this.silencedStages.add(key);
        this.saveSilencedStages();
        this.evaluateAlarms();
    }

    isStageSilenced(roomId, checkoutTimeStr, stageNum) {
        const key = `${roomId}_${checkoutTimeStr}_stage${stageNum}`;
        return this.silencedStages.has(key);
    }

    /* -------------------------------------------------------------------------
     * MAIN EVALUATION LOOP (Run every 1,000ms)
     * ------------------------------------------------------------------------- */
    evaluateOpenTime(el, now) {
        const checkIn = new Date(el.getAttribute('data-checkin-time')).getTime();
        const baseEnd = new Date(el.getAttribute('data-checkout-time')).getTime();
        const baseRate = Number(el.getAttribute('data-base-rate'));
        if (!Number.isFinite(checkIn) || !Number.isFinite(baseEnd) || !Number.isFinite(baseRate)) return null;
        const graceEnd = baseEnd + 15 * 60000;
        const elapsedMinutes = Math.floor(Math.max(0, now - checkIn) / 60000);
        const additionalHours = Math.ceil(Math.max(0, now - graceEnd) / 3600000);
        const charge = ((Math.round(baseRate * 100) + additionalHours * 13000) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const summary = `${Math.floor(elapsedMinutes / 60)}h ${elapsedMinutes % 60}m used · Room charge ₱${charge}`;
        const timer = el.querySelector('.stay-countdown-timer');
        if (timer) timer.textContent = `OPEN TIME · ${summary}`;
        if (now < baseEnd - 15 * 60000) return null;
        const cycle = now < baseEnd ? 1 : now < graceEnd ? 2 : 3 + Math.floor((now - graceEnd) / 3600000);
        const roomId = el.getAttribute('data-room-id');
        const folioId = el.getAttribute('data-folio-id');
        const checkoutTimeStr = el.getAttribute('data-checkout-time');
        const stage = `open-${folioId}-${cycle}`;
        return {
            roomId, folioId, checkoutTimeStr, currentStage: stage,
            roomNumber: el.getAttribute('data-room-number') || roomId,
            guestName: el.getAttribute('data-guest-name') || 'Guest',
            isOpenTime: true, notificationKey: `${folioId}_${checkoutTimeStr}_${stage}`,
            isSnoozed: this.isRoomSnoozed(roomId), isSilenced: this.isStageSilenced(roomId, checkoutTimeStr, stage),
            stageLabel: cycle === 1 ? 'OPEN TIME: 3H BASE ENDING SOON' : cycle === 2 ? 'OPEN TIME: 3H BASE COMPLETE' : cycle === 3 ? 'OPEN TIME: GRACE ENDED' : 'OPEN TIME: HOURLY REMINDER',
            stageBadgeClass: 'bg-teal-700 text-white', timeSummary: summary,
        };
    }

    evaluateAlarms() {
        // Query occupied room rows or cards across table and board views
        const roomElements = document.querySelectorAll('[data-room-id][data-checkout-time]');
        const now = Date.now();

        let highestActiveStage = null; // 3 > 2 > 1 > null
        const activeAlarmRooms = [];
        const openTimeRooms = [];

        roomElements.forEach(el => {
            if (el.getAttribute('data-stay-mode') === 'open') {
                const room = this.evaluateOpenTime(el, now);
                if (room) openTimeRooms.push(room);
                return;
            }
            const roomId = el.getAttribute('data-room-id');
            const roomNumber = el.getAttribute('data-room-number') || roomId;
            const guestName = el.getAttribute('data-guest-name') || 'Guest';
            const checkoutTimeStr = el.getAttribute('data-checkout-time');
            const folioId = el.getAttribute('data-folio-id');
            const timerCell = el.querySelector('.stay-countdown-timer');

            if (!checkoutTimeStr) return;

            const checkoutTime = new Date(checkoutTimeStr).getTime();
            if (isNaN(checkoutTime)) return;

            const remainingMs = checkoutTime - now;
            const overdueMs = now - checkoutTime;
            const isSnoozed = this.isRoomSnoozed(roomId);

            /* -----------------------------------------------------------------
             * Determine Alarm Stage:
             *  - Stage 3: overdueMs >= 15 * 60 * 1000 (15m AFTER checkout)
             *  - Stage 2: remainingMs <= 0 && overdueMs < 15 * 60 * 1000 (ACTUAL time up to 15m)
             *  - Stage 1: remainingMs <= 15 * 60 * 1000 && remainingMs > 0 (15m BEFORE checkout)
             *  - Normal:  remainingMs > 15 * 60 * 1000
             * ----------------------------------------------------------------- */
            let currentStage = null;

            if (overdueMs >= 15 * 60 * 1000) {
                currentStage = 3;
            } else if (remainingMs <= 0) {
                currentStage = 2;
            } else if (remainingMs <= 15 * 60 * 1000) {
                currentStage = 1;
            }

            // Update UI timer cell if present
            if (timerCell) {
                if (currentStage === 3) {
                    const totalSecs = Math.floor(overdueMs / 1000);
                    const overHrs = Math.floor(totalSecs / 3600);
                    const overMins = Math.floor((totalSecs % 3600) / 60);
                    const overFormatted = (overHrs > 0 ? `+${overHrs}h ` : '+') + `${overMins}m Critical`;
                    timerCell.innerHTML = `<span class="inline-flex items-center gap-1 text-rose-950 bg-rose-200 border-2 border-rose-500 px-1.5 py-0.5 rounded font-black font-mono text-[10px] animate-pulse">🚨 ${overFormatted}</span>`;
                } else if (currentStage === 2) {
                    const totalSecs = Math.floor(overdueMs / 1000);
                    const overMins = Math.floor(totalSecs / 60);
                    const overSecs = totalSecs % 60;
                    const overFormatted = `+${overMins}m ${overSecs < 10 ? '0' : ''}${overSecs}s Due`;
                    timerCell.innerHTML = `<span class="inline-flex items-center gap-1 text-purple-950 bg-purple-200 border border-purple-400 px-1.5 py-0.5 rounded font-bold font-mono text-[10px] animate-pulse">⏰ ${overFormatted}</span>`;
                } else if (currentStage === 1) {
                    const totalSecs = Math.floor(remainingMs / 1000);
                    const mins = Math.floor(totalSecs / 60);
                    const secs = totalSecs % 60;
                    const formatted = `${mins}m ${secs < 10 ? '0' : ''}${secs}s (Due Soon)`;
                    timerCell.innerHTML = `<span class="inline-flex items-center gap-1 text-amber-800 bg-amber-100 border border-amber-300 px-1.5 py-0.5 rounded font-bold font-mono text-[10px] animate-pulse">⏱ ${formatted}</span>`;
                } else {
                    const totalSecs = Math.floor(remainingMs / 1000);
                    const hrs = Math.floor(totalSecs / 3600);
                    const mins = Math.floor((totalSecs % 3600) / 60);
                    const secs = totalSecs % 60;
                    const formatted = (hrs > 0 ? `${hrs}h ` : '') + `${mins}m ${secs < 10 ? '0' : ''}${secs}s`;
                    timerCell.innerHTML = `<span class="text-slate-700 font-mono text-[11px]">${formatted}</span>`;
                }
            }

            // Check if this room has an active alarm stage that needs staff alert
            if (currentStage !== null) {
                const isSilenced = this.isStageSilenced(roomId, checkoutTimeStr, currentStage);

                // Format stage details for banner
                let stageLabel = '';
                let stageBadgeClass = '';
                let timeSummary = '';

                if (currentStage === 1) {
                    const totalSecs = Math.floor(remainingMs / 1000);
                    const mins = Math.floor(totalSecs / 60);
                    const secs = totalSecs % 60;
                    stageLabel = '1ST ALARM: 15M REMINDER';
                    stageBadgeClass = 'bg-amber-500 text-white';
                    timeSummary = `Due in ${mins}m ${secs < 10 ? '0' : ''}${secs}s`;
                } else if (currentStage === 2) {
                    const totalSecs = Math.floor(overdueMs / 1000);
                    const mins = Math.floor(totalSecs / 60);
                    const secs = totalSecs % 60;
                    stageLabel = '2ND ALARM: DUE NOW';
                    stageBadgeClass = 'bg-purple-600 text-white';
                    timeSummary = `Due Now (+${mins}m ${secs < 10 ? '0' : ''}${secs}s)`;
                } else if (currentStage === 3) {
                    const totalSecs = Math.floor(overdueMs / 1000);
                    const hrs = Math.floor(totalSecs / 3600);
                    const mins = Math.floor((totalSecs % 3600) / 60);
                    stageLabel = '3RD ALARM: +15M CRITICAL OVERTIME';
                    stageBadgeClass = 'bg-rose-600 text-white animate-pulse';
                    timeSummary = `${(hrs > 0 ? `${hrs}h ` : '') + `${mins}m`} Overtime (Excess Hour Applies)`;
                }

                // Banner displays all rooms currently in an alarm stage
                activeAlarmRooms.push({
                    roomId,
                    roomNumber,
                    guestName,
                    folioId,
                    checkoutTimeStr,
                    currentStage,
                    stageLabel,
                    stageBadgeClass,
                    timeSummary,
                    isSnoozed,
                    isSilenced
                });

                // Audio triggers if NOT snoozed and NOT silenced for this specific stage
                if (!isSnoozed && !isSilenced) {
                    if (highestActiveStage === null || currentStage > highestActiveStage) {
                        highestActiveStage = currentStage;
                    }
                }
            }
        });

        if (highestActiveStage === null && !this.isMuted && !this.openTimeAwaitingUnlock && openTimeRooms.length > 0) {
            const pending = openTimeRooms.filter(room => !room.isSnoozed && !room.isSilenced && !this.openTimeReminders.has(room.notificationKey));
            if (pending.length > 0 && now >= this.openTimeBurstUntil) {
                if (this.currentSoundMode === 'open') this.stopAllAlarms();
                this.openTimePendingKeys = pending.map(room => room.notificationKey);
                this.openTimeBurstUntil = now + 5000;
            }
        }
        const openTimeRinging = !this.openTimeAwaitingUnlock && openTimeRooms.some(room => !room.isSnoozed && !room.isSilenced && this.openTimePendingKeys.includes(room.notificationKey)) && now < this.openTimeBurstUntil;

        // Fixed checkout alarms retain priority over brief Open Time reminders.
        if (highestActiveStage === 3) {
            this.setSoundMode('stage3');
        } else if (highestActiveStage === 2) {
            this.setSoundMode('stage2');
        } else if (highestActiveStage === 1) {
            this.setSoundMode('stage1');
        } else if (openTimeRinging) {
            this.setSoundMode('open');
        } else {
            this.setSoundMode(null);
        }

        // Update Alarm Banner UI
        this.updateAlarmBanner([...activeAlarmRooms, ...openTimeRooms], highestActiveStage);
    }

    updateAlarmBanner(activeAlarmRooms, highestActiveStage) {
        const banner = document.getElementById('activeAlarmBanner');
        const bannerList = document.getElementById('alarmBannerRoomsList');
        const bannerTitle = document.getElementById('alarmBannerTitle');
        const bannerIcon = document.getElementById('alarmBannerIcon');

        if (!banner || !bannerList) return;

        if (activeAlarmRooms.length > 0) {
            banner.classList.remove('hidden');

            // Banner styling and title based on highest stage
            if (highestActiveStage === 3) {
                banner.className = 'bg-rose-950 border-2 border-rose-500 text-white p-3 mx-1 shadow-xl rounded-lg';
                if (bannerTitle) bannerTitle.textContent = '🚨 3RD ALARM: CRITICAL GUEST OVERTIME DETECTED (+15M EXCEEDED)';
                if (bannerIcon) bannerIcon.textContent = '🚨';
            } else if (highestActiveStage === 2) {
                banner.className = 'bg-purple-950 border-2 border-purple-500 text-white p-3 mx-1 shadow-lg rounded-lg';
                if (bannerTitle) bannerTitle.textContent = '⏰ 2ND ALARM: GUEST CHECKOUT DUE NOW';
                if (bannerIcon) bannerIcon.textContent = '⏰';
            } else if (highestActiveStage === 1) {
                banner.className = 'bg-amber-950 border-2 border-amber-500 text-white p-3 mx-1 shadow-md rounded-lg';
                if (bannerTitle) bannerTitle.textContent = '⚠️ 1ST ALARM: UPCOMING CHECKOUT REMINDER (15 MINUTES)';
                if (bannerIcon) bannerIcon.textContent = '⏱';
            } else if (activeAlarmRooms.some(room => room.isOpenTime)) {
                banner.className = 'bg-teal-950 border border-teal-600 text-white p-3 mx-1 shadow rounded-lg';
                if (bannerTitle) bannerTitle.textContent = '🔔 OPEN TIME — BASE PERIOD & HOURLY REMINDERS';
                if (bannerIcon) bannerIcon.textContent = '🔔';
            } else {
                // All active rooms are either snoozed or silenced
                banner.className = 'bg-slate-900 border-2 border-slate-600 text-slate-200 p-3 mx-1 shadow rounded-lg';
                if (bannerTitle) bannerTitle.textContent = '🔔 ACTIVE STAY MONITOR (ALL ALARMS SNOOZED / SILENCED)';
                if (bannerIcon) bannerIcon.textContent = '🔕';
            }

            bannerList.replaceChildren();
            const appendText = (parent, tag, className, text) => {
                const element = document.createElement(tag);
                element.className = className;
                element.textContent = text;
                parent.appendChild(element);
                return element;
            };

            activeAlarmRooms.forEach(room => {
                const row = appendText(bannerList, 'div', 'flex flex-wrap items-center justify-between bg-white text-slate-900 px-3 py-2 border border-slate-300 rounded shadow-sm text-xs gap-2', '');
                const details = appendText(row, 'div', 'flex items-center space-x-2 flex-wrap', '');
                appendText(details, 'span', `px-2 py-0.5 font-black text-[10px] rounded uppercase ${room.stageBadgeClass}`, room.stageLabel);
                appendText(details, 'strong', 'font-mono text-sm font-black text-slate-900', `ROOM ${room.roomNumber}`);
                appendText(details, 'span', 'text-slate-400', '•');
                appendText(details, 'span', 'text-slate-700 font-medium', room.guestName);
                appendText(details, 'span', 'text-slate-400', '•');
                appendText(details, 'span', 'font-bold font-mono text-purple-900', room.timeSummary);
                if (room.isSnoozed || room.isSilenced) {
                    appendText(details, 'span', `${room.isSnoozed ? 'text-amber-400' : 'text-slate-400'} font-bold ml-1.5`, room.isSnoozed ? '[Snoozed]' : room.isOpenTime ? '[Acknowledged]' : '[Silenced]');
                }

                const actions = appendText(row, 'div', 'flex items-center space-x-1.5 ml-auto', '');
                const addButton = (text, className, title, action) => {
                    const button = appendText(actions, 'button', className, text);
                    button.type = 'button';
                    button.title = title;
                    button.addEventListener('click', action);
                };
                if (room.folioId) {
                    addButton(room.isOpenTime ? 'Convert Package' : '+ Xtend', 'px-2.5 py-1 bg-sky-600 hover:bg-sky-700 text-white font-black text-[11px] rounded shadow-sm', 'Change Stay Package', () => window.openXtendModal(room.folioId, room.roomNumber));
                }
                if (!room.isOpenTime) addButton('Snooze 5m', 'px-2 py-1 bg-amber-600 hover:bg-amber-700 text-white font-bold text-[10px] rounded shadow-sm', 'Snooze for 5 minutes', () => this.snoozeRoom(room.roomId, 5));
                addButton(room.isOpenTime ? 'Acknowledge' : 'Silence', 'px-2 py-1 bg-slate-600 hover:bg-slate-700 text-white font-bold text-[10px] rounded shadow-sm', 'Acknowledge this alarm stage', () => this.silenceStage(room.roomId, room.checkoutTimeStr, room.currentStage));
                if (room.folioId) {
                    const checkout = appendText(actions, 'a', 'px-2.5 py-1 bg-rose-700 hover:bg-rose-800 text-white font-black text-[11px] rounded shadow-sm', 'Checkout');
                    checkout.href = `/checkout/${encodeURIComponent(room.folioId)}`;
                }
            });
        } else {
            this.setSoundMode(null);
            banner.classList.add('hidden');
        }
    }
}

// Global instance
window.sedonaAudio = new SedonaAudioEngine();

// Auto-run reactive loop every 1,000ms on pages with room countdowns
setInterval(() => {
    if (window.sedonaAudio) {
        window.sedonaAudio.evaluateAlarms();
    }
}, 1000);
