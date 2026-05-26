/* Matrix Messaging — <matrix-voice-player> custom element.
 *
 * Drop-in waveform-style audio player used by the messaging client to render
 * voice attachments returned by hydrate_message_rows() with kind='voice'.
 *
 * Markup contract:
 *
 *     <matrix-voice-player
 *         src="https://site/wp-json/.../signed-voice-url"
 *         duration-ms="42180"
 *         peaks="[0.04,0.12,0.31,...]">
 *     </matrix-voice-player>
 *
 * Attributes:
 *   src         — required. The signed REST URL minted by Matrix_MLM_Attachment_Signer.
 *                 We never see, and never accept, raw /uploads/ URLs.
 *   duration-ms — optional. Server-probed duration; used as the canonical total
 *                 because the audio element's metadata can lag on slow networks.
 *   peaks       — optional. JSON-encoded array of normalised 0..1 amplitudes,
 *                 length === Matrix_MLM_Messaging::VOICE_WAVEFORM_PEAKS (default 64).
 *                 Falls back to a flat baseline when missing or malformed.
 *
 * Behaviour:
 *   - Lazy-loads the audio element (attribute preload="none") so a chat
 *     window with 50 voice notes doesn't fan out to 50 simultaneous range
 *     requests. The audio element is only swapped in once the user clicks
 *     play, so the bandwidth cost of a thread of voice notes mirrors the
 *     existing image attachment cost (zero until interaction).
 *   - At most one player on the page is allowed to be playing at a time —
 *     starting a second one pauses the first. Implemented via a tiny
 *     module-level registry, not a global event bus, so the moderation
 *     surface (PR 3) can reuse the element without contending with the
 *     user-facing thread.
 *   - Click on the waveform seeks proportionally; keyboard left/right
 *     nudges by 5 seconds, space toggles play/pause.
 *   - Renders into the light DOM (no shadow root) so the host page's
 *     existing dashboard CSS variables, font stack, and dark/light mode
 *     classes apply without a repeated set of custom-property forwards.
 *     The styling is namespaced under .matrix-voice-player__* so it
 *     doesn't bleed.
 */
(function (root) {
    'use strict';

    if (typeof root.customElements === 'undefined' ||
        typeof root.HTMLElement === 'undefined') {
        // No custom-elements support (very old Edge / Safari pre-12).
        // Bail rather than ship a partial implementation; the messaging
        // client falls back to a plain <a href="..."> link in that case.
        return;
    }

    if (root.customElements.get('matrix-voice-player')) {
        return;
    }

    // Module-level registry of every live player. start() walks this list
    // and pauses any other element that's currently playing, giving us the
    // "one-voice-at-a-time" UX without leaking listeners onto window.
    var REGISTRY = [];

    function pauseOthers(self) {
        for (var i = 0; i < REGISTRY.length; i++) {
            var el = REGISTRY[i];
            if (el !== self && el._audio && !el._audio.paused) {
                el._audio.pause();
            }
        }
    }

    function formatTime(ms) {
        if (!isFinite(ms) || ms < 0) { ms = 0; }
        var s = Math.floor(ms / 1000);
        var m = Math.floor(s / 60);
        s = s % 60;
        return m + ':' + (s < 10 ? '0' + s : s);
    }

    function parsePeaks(attr) {
        if (!attr) { return null; }
        try {
            var parsed = JSON.parse(attr);
            if (!Array.isArray(parsed) || !parsed.length) { return null; }
            // Bound-clamp to 0..1 and coerce to numbers — defensive against
            // a future server-side pass that drifted from the spec, since
            // the canvas would otherwise paint arbitrary values.
            var out = [];
            for (var i = 0; i < parsed.length; i++) {
                var v = parseFloat(parsed[i]);
                if (!isFinite(v) || v < 0) { v = 0; }
                if (v > 1) { v = 1; }
                out.push(v);
            }
            return out;
        } catch (e) {
            return null;
        }
    }

    function MatrixVoicePlayer() {
        return Reflect.construct(HTMLElement, [], MatrixVoicePlayer);
    }
    MatrixVoicePlayer.prototype = Object.create(HTMLElement.prototype);
    MatrixVoicePlayer.prototype.constructor = MatrixVoicePlayer;

    Object.defineProperty(MatrixVoicePlayer, 'observedAttributes', {
        get: function () { return ['src', 'duration-ms', 'peaks']; }
    });

    MatrixVoicePlayer.prototype.connectedCallback = function () {
        if (this._mounted) { return; }
        this._mounted = true;
        REGISTRY.push(this);
        this.classList.add('matrix-voice-player');

        // Inner DOM. We don't use a template element — the markup is small
        // enough that an innerHTML write is cheaper than a clone-and-attach
        // pattern on first mount, and it sidesteps a known IE/Edge legacy
        // bug where document fragments inside attached templates lost
        // their event listeners after a DOM move.
        this.innerHTML =
            '<button type="button" class="matrix-voice-player__btn" aria-label="Play voice note">' +
                '<span class="matrix-voice-player__btn-icon" aria-hidden="true">' +
                    // Triangle play glyph in pure SVG so the player works
                    // on dashboards that don't include dashicons (the
                    // moderation surface in PR 3 is one of them).
                    '<svg viewBox="0 0 16 16" width="14" height="14" focusable="false">' +
                        '<path class="matrix-voice-player__btn-play" d="M3 2 L13 8 L3 14 Z"></path>' +
                        '<path class="matrix-voice-player__btn-pause" d="M3 2 H6 V14 H3 Z M10 2 H13 V14 H10 Z" style="display:none"></path>' +
                    '</svg>' +
                '</span>' +
            '</button>' +
            '<canvas class="matrix-voice-player__waveform" aria-hidden="true"></canvas>' +
            '<span class="matrix-voice-player__time" aria-live="off">0:00</span>';

        this._btn      = this.querySelector('.matrix-voice-player__btn');
        this._canvas   = this.querySelector('.matrix-voice-player__waveform');
        this._timeEl   = this.querySelector('.matrix-voice-player__time');
        this._iconPlay  = this.querySelector('.matrix-voice-player__btn-play');
        this._iconPause = this.querySelector('.matrix-voice-player__btn-pause');

        this.setAttribute('role', 'group');
        this.setAttribute('tabindex', '0');

        this._peaks       = parsePeaks(this.getAttribute('peaks'));
        this._durationMs  = parseInt(this.getAttribute('duration-ms'), 10) || 0;
        this._progress01  = 0;

        // Repaint when the host page's font/colour scheme switches — the
        // canvas reads CSS custom properties for fill / progress fill so
        // a theme toggle should redraw without re-render.
        this._onResize = this._scheduleRedraw.bind(this);
        root.addEventListener('resize', this._onResize);

        this._btn.addEventListener('click', this._onPlayPause.bind(this));
        this._canvas.addEventListener('click', this._onSeekClick.bind(this));
        this.addEventListener('keydown', this._onKeyDown.bind(this));

        this._renderTime();
        this._scheduleRedraw();
    };

    MatrixVoicePlayer.prototype.disconnectedCallback = function () {
        REGISTRY = REGISTRY.filter(function (el) { return el !== this; }, this);
        if (this._audio) {
            try { this._audio.pause(); } catch (e) {}
        }
        if (this._onResize) {
            root.removeEventListener('resize', this._onResize);
        }
    };

    MatrixVoicePlayer.prototype.attributeChangedCallback = function (name, oldVal, newVal) {
        if (!this._mounted) { return; }
        if (name === 'peaks') {
            this._peaks = parsePeaks(newVal);
            this._scheduleRedraw();
        } else if (name === 'duration-ms') {
            this._durationMs = parseInt(newVal, 10) || 0;
            this._renderTime();
            this._scheduleRedraw();
        } else if (name === 'src') {
            // src changed (e.g. the signer minted a fresh URL after expiry).
            // Tear down the previous audio element so the next play() picks
            // up the new src — without this we'd keep streaming the stale
            // signature and 401 mid-playback.
            if (this._audio) {
                try { this._audio.pause(); } catch (e) {}
                this._audio = null;
            }
            this._progress01 = 0;
            this._setPlayingIcon(false);
            this._renderTime();
            this._scheduleRedraw();
        }
    };

    MatrixVoicePlayer.prototype._ensureAudio = function () {
        if (this._audio) { return this._audio; }
        var src = this.getAttribute('src') || '';
        if (!src) { return null; }
        var a = new Audio();
        a.preload = 'none';
        a.src = src;
        var self = this;
        a.addEventListener('loadedmetadata', function () {
            // Server-probed duration wins, but if we don't have one (legacy
            // row, NULL duration_ms), use the audio element's reading.
            if (!self._durationMs && isFinite(a.duration) && a.duration > 0) {
                self._durationMs = Math.round(a.duration * 1000);
                self._renderTime();
            }
        });
        a.addEventListener('timeupdate', function () {
            var dur = self._effectiveDurationMs();
            if (dur > 0) {
                self._progress01 = Math.max(0, Math.min(1, (a.currentTime * 1000) / dur));
            }
            self._renderTime();
            self._scheduleRedraw();
        });
        a.addEventListener('play', function () {
            pauseOthers(self);
            self._setPlayingIcon(true);
        });
        a.addEventListener('pause', function () {
            self._setPlayingIcon(false);
        });
        a.addEventListener('ended', function () {
            self._progress01 = 0;
            self._setPlayingIcon(false);
            self._renderTime();
            self._scheduleRedraw();
        });
        a.addEventListener('error', function () {
            // 401/403/404 from the signer — make the affordance visually
            // dead rather than letting the user click into an infinite
            // pending state.
            self._btn.disabled = true;
            self._setPlayingIcon(false);
            self.classList.add('matrix-voice-player--error');
        });
        this._audio = a;
        return a;
    };

    MatrixVoicePlayer.prototype._effectiveDurationMs = function () {
        if (this._durationMs > 0) { return this._durationMs; }
        if (this._audio && isFinite(this._audio.duration) && this._audio.duration > 0) {
            return Math.round(this._audio.duration * 1000);
        }
        return 0;
    };

    MatrixVoicePlayer.prototype._onPlayPause = function (ev) {
        ev.preventDefault();
        var a = this._ensureAudio();
        if (!a) { return; }
        if (a.paused) {
            // play() returns a Promise on modern engines and rejects on
            // autoplay-policy or transient decode errors. Swallow the
            // rejection here and surface the failure via the audio
            // element's own 'error' event so we don't double-fire the
            // disabled-state styling.
            var p = a.play();
            if (p && typeof p.catch === 'function') {
                p.catch(function () {});
            }
        } else {
            a.pause();
        }
    };

    MatrixVoicePlayer.prototype._onSeekClick = function (ev) {
        var rect = this._canvas.getBoundingClientRect();
        if (rect.width <= 0) { return; }
        var x = ev.clientX - rect.left;
        var ratio = Math.max(0, Math.min(1, x / rect.width));
        var dur = this._effectiveDurationMs();
        if (dur <= 0) { return; }
        var a = this._ensureAudio();
        if (!a) { return; }
        // The audio element accepts seek before play; the next play() will
        // start from the seeked position. This mirrors WhatsApp's UX where
        // tapping the waveform on a never-played voice note primes the
        // playback head and shows the new bar position immediately.
        try { a.currentTime = (dur / 1000) * ratio; } catch (e) {}
        this._progress01 = ratio;
        this._renderTime();
        this._scheduleRedraw();
    };

    MatrixVoicePlayer.prototype._onKeyDown = function (ev) {
        if (ev.target !== this) { return; }
        if (ev.key === ' ' || ev.key === 'Enter') {
            ev.preventDefault();
            this._onPlayPause(ev);
        } else if (ev.key === 'ArrowLeft') {
            ev.preventDefault();
            this._nudge(-5000);
        } else if (ev.key === 'ArrowRight') {
            ev.preventDefault();
            this._nudge(5000);
        }
    };

    MatrixVoicePlayer.prototype._nudge = function (deltaMs) {
        var a = this._ensureAudio();
        if (!a) { return; }
        var dur = this._effectiveDurationMs();
        if (dur <= 0) { return; }
        var nextMs = Math.max(0, Math.min(dur, (a.currentTime * 1000) + deltaMs));
        try { a.currentTime = nextMs / 1000; } catch (e) {}
        this._progress01 = nextMs / dur;
        this._renderTime();
        this._scheduleRedraw();
    };

    MatrixVoicePlayer.prototype._setPlayingIcon = function (playing) {
        if (!this._iconPlay || !this._iconPause) { return; }
        this._iconPlay.style.display  = playing ? 'none' : '';
        this._iconPause.style.display = playing ? '' : 'none';
        this._btn.setAttribute('aria-label', playing ? 'Pause voice note' : 'Play voice note');
    };

    MatrixVoicePlayer.prototype._renderTime = function () {
        var dur = this._effectiveDurationMs();
        var elapsed = dur > 0 ? Math.round(dur * this._progress01) : 0;
        if (!this._timeEl) { return; }
        // Showing "0:42 / 1:30" rather than just elapsed gives users a
        // sense of how long the recording is before they commit to playing
        // it — the same affordance Telegram uses, and the same one users
        // told us was missing in the original UX research thread.
        this._timeEl.textContent = formatTime(elapsed) + ' / ' + formatTime(dur);
    };

    MatrixVoicePlayer.prototype._scheduleRedraw = function () {
        if (this._redrawScheduled) { return; }
        this._redrawScheduled = true;
        var self = this;
        root.requestAnimationFrame(function () {
            self._redrawScheduled = false;
            self._draw();
        });
    };

    MatrixVoicePlayer.prototype._draw = function () {
        var canvas = this._canvas;
        if (!canvas) { return; }
        var rect = canvas.getBoundingClientRect();
        var dpr  = root.devicePixelRatio || 1;
        var w = Math.max(1, Math.floor(rect.width  * dpr));
        var h = Math.max(1, Math.floor(rect.height * dpr));
        if (canvas.width !== w)  { canvas.width  = w; }
        if (canvas.height !== h) { canvas.height = h; }
        var ctx = canvas.getContext('2d');
        if (!ctx) { return; }
        ctx.clearRect(0, 0, w, h);

        // Pull theme colours from CSS custom properties so a dark-mode
        // toggle on the host body recolours the player without us
        // hardcoding hex values. Defaults match the messaging chrome.
        var styles = root.getComputedStyle(this);
        var idleColor    = (styles.getPropertyValue('--matrix-voice-bar-idle')   || '#cbd5e1').trim();
        var playedColor  = (styles.getPropertyValue('--matrix-voice-bar-played') || '#2563eb').trim();

        var peaks = this._peaks && this._peaks.length ? this._peaks : null;
        // Flat-bar fallback when the row carries no peaks — render 40 short
        // bars at 25% height so the affordance still looks like an audio
        // waveform and not an empty rectangle.
        if (!peaks) {
            peaks = [];
            for (var i = 0; i < 40; i++) { peaks.push(0.25); }
        }

        var n = peaks.length;
        var totalGap = 1 * dpr;
        var barWidth = Math.max(1, Math.floor((w - totalGap * (n - 1)) / n));
        var midY     = h / 2;
        var minBarH  = 2 * dpr;
        var progressX = w * Math.max(0, Math.min(1, this._progress01));

        for (var b = 0; b < n; b++) {
            var x = b * (barWidth + totalGap);
            var p = peaks[b];
            var barH = Math.max(minBarH, Math.floor(p * h));
            var y = Math.floor(midY - barH / 2);
            ctx.fillStyle = (x + barWidth) <= progressX ? playedColor : idleColor;
            ctx.fillRect(x, y, barWidth, barH);
        }
    };

    root.customElements.define('matrix-voice-player', MatrixVoicePlayer);
})(typeof window !== 'undefined' ? window : this);
