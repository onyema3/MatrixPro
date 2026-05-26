/* Matrix Messaging — voice-note recorder.
 *
 * Wraps the browser's MediaRecorder API with the slice of behaviour the
 * messaging composer needs:
 *
 *   1. Pick the best supported MIME from the operator-configured allow-list
 *      (cfg.voiceAllowedMime) so the recorded blob is one the server's
 *      voice_allowed_mime() will accept after wp_check_filetype runs.
 *   2. Stream amplitude samples off an AnalyserNode while recording so the
 *      composer can paint a live level meter without waiting for the final
 *      blob.
 *   3. On stop, decode the assembled blob with OfflineAudioContext (or
 *      AudioContext.decodeAudioData on engines that don't expose the offline
 *      flavour) and downsample the PCM to N peak buckets, RMS-normalised to
 *      0..1, ready to ship to the server as the waveform_peaks_json column.
 *   4. Enforce the duration cap client-side as a UX safeguard — hitting the
 *      cap triggers an automatic stop() with reason='duration_cap' so the
 *      composer can surface "max length reached" rather than the server
 *      bouncing the eventual upload with a 413.
 *   5. Surface every async failure mode (mic permission denied, no input
 *      device, codec init failure, decode failure) through a single onError
 *      callback so the composer renders one error UX path.
 *
 * The class is intentionally self-contained — it pulls nothing from jQuery
 * or from the messaging client's globals, so the moderation surface (PR 3)
 * can reuse it for re-recording from the admin tab without dragging the
 * composer's wiring along.
 *
 * Lifecycle:
 *
 *     var rec = new MatrixVoiceRecorder({
 *         allowedMime:    ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg'],
 *         maxDurationMs:  120000,
 *         maxBytes:       2097152,
 *         peakBuckets:    64,
 *         onLevel:        function (rms01) { ... },         // 0..1 every ~50ms
 *         onDurationTick: function (elapsedMs) { ... },     // every ~250ms
 *         onComplete:     function (result) { ... },        // see below
 *         onError:        function (err) { ... }            // { code, message }
 *     });
 *     rec.start();              // returns a Promise (mic gUM resolves async)
 *     // ... user clicks stop ...
 *     rec.stop();               // triggers onComplete with the final blob
 *     // OR
 *     rec.cancel();             // tears down without firing onComplete
 *
 * onComplete result shape:
 *
 *     {
 *         blob:        Blob,        // the recorded audio, MIME = mimeType below
 *         mimeType:    string,      // chosen MIME from allowedMime
 *         extension:   string,      // file extension matching mimeType
 *         durationMs:  number,      // measured client-side; server re-probes
 *         peaks:       number[]     // length === peakBuckets, each in 0..1
 *     }
 */
(function (root) {
    'use strict';

    if (root.MatrixVoiceRecorder) {
        return;
    }

    /**
     * Map a chosen MediaRecorder MIME (which can include a codecs param) to
     * the bare MIME the server's voice_allowed_mime() compares against, plus
     * the file extension we should hand to async-upload so wp_check_filetype
     * doesn't reject the synthesised filename.
     *
     * The pairing matters because Chrome's MediaRecorder happily emits
     * 'audio/webm;codecs=opus', but wp_get_mime_types() keys the allow-list
     * on 'audio/webm'. The server-side intersection in voice_allowed_mime
     * already drops the codecs parameter, but we want the upload's filename
     * to land on the right extension regardless of what the engine returned.
     */
    var MIME_TO_EXT = {
        'audio/webm': 'webm',
        'audio/ogg':  'ogg',
        'audio/mp4':  'mp4',
        'audio/mpeg': 'mp3'
    };

    function bareMime(mimeWithCodecs) {
        if (!mimeWithCodecs) { return ''; }
        var semi = String(mimeWithCodecs).indexOf(';');
        return (semi === -1 ? String(mimeWithCodecs) : String(mimeWithCodecs).slice(0, semi)).trim().toLowerCase();
    }

    /**
     * Walk the operator's allow-list and return the first MIME the local
     * MediaRecorder reports it can encode. We probe both the bare MIME and
     * a codecs-decorated form because some Chromium builds answer 'no' to
     * the bare 'audio/webm' but 'yes' to 'audio/webm;codecs=opus'.
     */
    function pickSupportedMime(allowed) {
        if (typeof MediaRecorder === 'undefined' || typeof MediaRecorder.isTypeSupported !== 'function') {
            return '';
        }
        var probes = [];
        (allowed || []).forEach(function (m) {
            var bare = bareMime(m);
            if (!bare) { return; }
            if (bare === 'audio/webm') {
                probes.push('audio/webm;codecs=opus');
                probes.push('audio/webm');
            } else if (bare === 'audio/ogg') {
                probes.push('audio/ogg;codecs=opus');
                probes.push('audio/ogg');
            } else if (bare === 'audio/mp4') {
                probes.push('audio/mp4;codecs=mp4a.40.2');
                probes.push('audio/mp4');
            } else {
                probes.push(bare);
            }
        });
        for (var i = 0; i < probes.length; i++) {
            try {
                if (MediaRecorder.isTypeSupported(probes[i])) {
                    return probes[i];
                }
            } catch (e) {
                // Some engines throw rather than return false on unknown
                // codecs. Treat that as "not supported" and keep probing.
            }
        }
        return '';
    }

    /**
     * RMS-normalise an array of non-negative peaks to the 0..1 range. Uses
     * the maximum bucket as the reference so a quiet recording still looks
     * "filled" against its own loudest moment, rather than visually pinned
     * to the noise floor of a louder clip elsewhere.
     *
     * The server's bound-checker will clamp anything outside 0..1 anyway,
     * but we want the wire shape to be clean so a future operator filter
     * that introspects waveform_peaks_json doesn't get surprised by raw
     * float-32 magnitudes.
     */
    function normalisePeaks(buckets) {
        var max = 0;
        for (var i = 0; i < buckets.length; i++) {
            if (buckets[i] > max) { max = buckets[i]; }
        }
        if (max <= 0) {
            // Pure silence — emit a flat tiny baseline rather than zeros so
            // the player still draws something. The visual decision is the
            // player's, but giving it a non-zero floor avoids special-cased
            // "silent recording" code paths there.
            for (var k = 0; k < buckets.length; k++) {
                buckets[k] = 0.02;
            }
            return buckets;
        }
        for (var j = 0; j < buckets.length; j++) {
            // Round to 3 dp so the JSON envelope stays small (peak arrays
            // ship inside the message payload on every fetch_thread tick).
            buckets[j] = Math.round((buckets[j] / max) * 1000) / 1000;
        }
        return buckets;
    }

    /**
     * Downsample a single-channel PCM Float32Array into N RMS buckets.
     * Used by the offline-decode path on stop(). The inline RMS (rather
     * than peak amplitude) gives a perceptually flatter waveform that
     * doesn't spike on transient clicks — the same approach used by
     * Telegram and WhatsApp's voice-message UIs.
     */
    function bucketRms(samples, buckets) {
        var out = new Array(buckets);
        var n = samples.length;
        if (n === 0) {
            for (var k = 0; k < buckets; k++) { out[k] = 0; }
            return out;
        }
        var step = n / buckets;
        for (var b = 0; b < buckets; b++) {
            var start = Math.floor(b * step);
            var end   = Math.floor((b + 1) * step);
            if (end <= start) { end = start + 1; }
            if (end > n)      { end = n; }
            var sumSq = 0;
            var count = 0;
            for (var i = start; i < end; i++) {
                var v = samples[i];
                sumSq += v * v;
                count++;
            }
            out[b] = count ? Math.sqrt(sumSq / count) : 0;
        }
        return out;
    }

    /**
     * Decode an audio blob into a Float32 mono mixdown. Prefers
     * OfflineAudioContext where available because it doesn't churn through
     * the device's audio output during decode; falls back to a plain
     * AudioContext.decodeAudioData on Safari builds that historically had
     * spotty offline support. Both paths return a Promise<Float32Array>.
     */
    function decodeBlobToMono(blob) {
        return new Promise(function (resolve, reject) {
            var Ctor = root.OfflineAudioContext || root.webkitOfflineAudioContext;
            var fr = new FileReader();
            fr.onerror = function () { reject(new Error('blob_read_failed')); };
            fr.onload  = function () {
                var ab = fr.result;
                // We don't know sample rate or channel count up-front; spin
                // up a 1-channel @ 48k OfflineAudioContext just so we have
                // a decoder. The decoded buffer carries its own actual
                // sample rate, which we use below for the mixdown.
                var ctx;
                try {
                    ctx = Ctor ? new Ctor(1, 1, 48000) : new (root.AudioContext || root.webkitAudioContext)();
                } catch (e) {
                    reject(new Error('audio_context_failed'));
                    return;
                }
                var p = ctx.decodeAudioData(ab.slice(0));
                if (!p || typeof p.then !== 'function') {
                    // Pre-Promise decodeAudioData (very old Safari).
                    ctx.decodeAudioData(ab.slice(0), function (buf) {
                        resolve(mixdown(buf));
                    }, function () {
                        reject(new Error('decode_failed'));
                    });
                    return;
                }
                p.then(function (buf) { resolve(mixdown(buf)); })
                 .catch(function () { reject(new Error('decode_failed')); });
            };
            fr.readAsArrayBuffer(blob);
        });
    }

    function mixdown(audioBuffer) {
        var channels = audioBuffer.numberOfChannels || 1;
        var len = audioBuffer.length;
        var out = new Float32Array(len);
        for (var c = 0; c < channels; c++) {
            var data = audioBuffer.getChannelData(c);
            for (var i = 0; i < len; i++) {
                out[i] += data[i] / channels;
            }
        }
        return out;
    }

    function MatrixVoiceRecorder(opts) {
        opts = opts || {};
        this._allowedMime    = opts.allowedMime    || ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg'];
        this._maxDurationMs  = parseInt(opts.maxDurationMs, 10) || 120000;
        this._maxBytes       = parseInt(opts.maxBytes,      10) || 2097152;
        this._peakBuckets    = parseInt(opts.peakBuckets,   10) || 64;

        this._onLevel        = typeof opts.onLevel        === 'function' ? opts.onLevel        : function () {};
        this._onDurationTick = typeof opts.onDurationTick === 'function' ? opts.onDurationTick : function () {};
        this._onComplete     = typeof opts.onComplete     === 'function' ? opts.onComplete     : function () {};
        this._onError        = typeof opts.onError        === 'function' ? opts.onError        : function () {};

        this._stream         = null;
        this._recorder       = null;
        this._chunks         = [];
        this._mimeChosen     = '';
        this._extension      = 'webm';
        this._startTs        = 0;
        this._durationTimer  = null;
        this._levelTimer     = null;
        this._audioCtx       = null;
        this._analyser       = null;
        this._cancelled      = false;
        this._stopped        = false;
        this._byteCapHit     = false;
    }

    /**
     * Begin recording. Returns a Promise that resolves once the mic stream
     * is live and the MediaRecorder is in 'recording' state, or rejects
     * (and fires onError) on any failure between the gUM call and the
     * recorder's first tick.
     */
    MatrixVoiceRecorder.prototype.start = function () {
        var self = this;
        return new Promise(function (resolve, reject) {
            if (typeof MediaRecorder === 'undefined' ||
                !root.navigator || !root.navigator.mediaDevices ||
                typeof root.navigator.mediaDevices.getUserMedia !== 'function') {
                self._fail('unsupported', 'Voice recording is not supported by this browser.');
                reject(new Error('unsupported'));
                return;
            }
            var mime = pickSupportedMime(self._allowedMime);
            if (!mime) {
                self._fail('no_codec', 'Your browser does not support any of the allowed voice formats.');
                reject(new Error('no_codec'));
                return;
            }
            self._mimeChosen = mime;
            self._extension  = MIME_TO_EXT[bareMime(mime)] || 'webm';

            root.navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl:  true
                }
            }).then(function (stream) {
                if (self._cancelled) {
                    // start() raced cancel(); release the mic immediately.
                    stream.getTracks().forEach(function (t) { t.stop(); });
                    reject(new Error('cancelled'));
                    return;
                }
                self._stream = stream;
                try {
                    self._recorder = new MediaRecorder(stream, { mimeType: mime });
                } catch (e) {
                    self._fail('recorder_init_failed', 'Could not start the recorder. Try again.');
                    reject(e);
                    return;
                }
                self._chunks = [];
                self._recorder.ondataavailable = function (ev) {
                    if (ev.data && ev.data.size > 0) {
                        self._chunks.push(ev.data);
                    }
                    // Soft-abort if the rolling byte total has crossed the
                    // operator cap. We don't refuse to deliver the bytes
                    // we already have — we trigger stop(), which assembles
                    // the over-cap blob and surfaces it as a 'byte_cap'
                    // failure. The composer never uploads it; the user
                    // sees "recording too large".
                    var total = 0;
                    for (var i = 0; i < self._chunks.length; i++) { total += self._chunks[i].size; }
                    if (!self._byteCapHit && total > self._maxBytes) {
                        self._byteCapHit = true;
                        self._fail('byte_cap', 'Recording is larger than the allowed size limit.');
                        try { self._recorder.stop(); } catch (e) {}
                    }
                };
                self._recorder.onerror = function (ev) {
                    self._fail('recorder_error', (ev && ev.error && ev.error.message) || 'Recorder failed.');
                };
                self._recorder.onstop = function () {
                    self._teardownStream();
                    if (self._cancelled || self._byteCapHit) {
                        return;
                    }
                    self._finalise();
                };

                // Spin up an Analyser on a parallel pipe of the same stream
                // so the live level meter doesn't have to wait for the
                // recorder's chunked dataavailable cadence (which Chromium
                // delivers only every few seconds at default timeslice).
                try {
                    var ACtor = root.AudioContext || root.webkitAudioContext;
                    if (ACtor) {
                        self._audioCtx = new ACtor();
                        var src = self._audioCtx.createMediaStreamSource(stream);
                        self._analyser = self._audioCtx.createAnalyser();
                        self._analyser.fftSize = 1024;
                        src.connect(self._analyser);
                    }
                } catch (e) {
                    // Live level meter is a nice-to-have; silently fall
                    // back to a duration-only progress bar if it can't
                    // be wired up.
                    self._analyser = null;
                }

                self._startTs = Date.now();
                self._recorder.start(250); // 250ms timeslice -> snappier byte-cap check
                self._tickDuration();
                self._tickLevel();
                resolve();
            }).catch(function (err) {
                var name = (err && err.name) || '';
                var code = 'mic_failed';
                var msg  = 'Microphone access failed.';
                if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                    code = 'mic_blocked';
                    msg  = 'Microphone access was blocked. Allow it in your browser to record voice notes.';
                } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
                    code = 'mic_missing';
                    msg  = 'No microphone was found on this device.';
                } else if (name === 'NotReadableError' || name === 'TrackStartError') {
                    code = 'mic_busy';
                    msg  = 'Your microphone is in use by another application.';
                }
                self._fail(code, msg);
                reject(err || new Error(code));
            });
        });
    };

    MatrixVoiceRecorder.prototype._tickDuration = function () {
        var self = this;
        // 250 ms cadence — fast enough that the on-screen timer stays
        // smooth, slow enough that we don't dominate the main thread on
        // low-end Android.
        this._durationTimer = root.setInterval(function () {
            if (self._cancelled || self._stopped) { return; }
            var elapsed = Date.now() - self._startTs;
            if (elapsed >= self._maxDurationMs) {
                self._onDurationTick(self._maxDurationMs);
                self.stop('duration_cap');
                return;
            }
            self._onDurationTick(elapsed);
        }, 250);
    };

    MatrixVoiceRecorder.prototype._tickLevel = function () {
        if (!this._analyser) { return; }
        var self = this;
        var buf = new Uint8Array(this._analyser.fftSize);
        this._levelTimer = root.setInterval(function () {
            if (self._cancelled || self._stopped || !self._analyser) { return; }
            try {
                self._analyser.getByteTimeDomainData(buf);
            } catch (e) {
                return;
            }
            // Centre at 128, RMS, scale to 0..1.
            var sumSq = 0;
            for (var i = 0; i < buf.length; i++) {
                var v = (buf[i] - 128) / 128;
                sumSq += v * v;
            }
            var rms = Math.sqrt(sumSq / buf.length);
            if (rms > 1) { rms = 1; }
            self._onLevel(rms);
        }, 50);
    };

    /**
     * Stop the recorder normally. The optional reason argument is
     * advisory — 'duration_cap' is supplied by the auto-stop path so the
     * composer can surface a slightly different toast ("max length
     * reached") than the default "recording stopped". The result still
     * goes through onComplete.
     */
    MatrixVoiceRecorder.prototype.stop = function (reason) {
        if (this._stopped || this._cancelled) { return; }
        this._stopped = true;
        this._stopReason = reason || 'user';
        if (this._recorder && this._recorder.state !== 'inactive') {
            try { this._recorder.stop(); } catch (e) {}
        }
        this._clearTimers();
    };

    /**
     * Discard the recording entirely. Releases the mic and never fires
     * onComplete. Safe to call before start() resolves — in that case
     * start() honours the cancellation when its gUM Promise lands.
     */
    MatrixVoiceRecorder.prototype.cancel = function () {
        if (this._cancelled) { return; }
        this._cancelled = true;
        this._clearTimers();
        if (this._recorder && this._recorder.state !== 'inactive') {
            try { this._recorder.stop(); } catch (e) {}
        }
        this._teardownStream();
    };

    MatrixVoiceRecorder.prototype._clearTimers = function () {
        if (this._durationTimer) { root.clearInterval(this._durationTimer); this._durationTimer = null; }
        if (this._levelTimer)    { root.clearInterval(this._levelTimer);    this._levelTimer    = null; }
    };

    MatrixVoiceRecorder.prototype._teardownStream = function () {
        try {
            if (this._stream) {
                this._stream.getTracks().forEach(function (t) { t.stop(); });
            }
        } catch (e) {}
        this._stream = null;
        if (this._audioCtx && typeof this._audioCtx.close === 'function') {
            try { this._audioCtx.close(); } catch (e) {}
        }
        this._audioCtx = null;
        this._analyser = null;
    };

    MatrixVoiceRecorder.prototype._finalise = function () {
        var self = this;
        var mimeBare = bareMime(this._mimeChosen);
        var blob = new Blob(this._chunks, { type: mimeBare });
        var durationMs = Math.max(0, Date.now() - this._startTs);

        // Decode + downsample the recorded blob into the peak array. If
        // decoding fails (codec quirks, truncated header on a force-stop
        // race), still deliver the blob — the server will re-probe
        // duration and store NULL peaks; the player renders a flat-bar
        // fallback in that case.
        decodeBlobToMono(blob).then(function (samples) {
            var raw    = bucketRms(samples, self._peakBuckets);
            var peaks  = normalisePeaks(raw);
            self._onComplete({
                blob:       blob,
                mimeType:   mimeBare,
                extension:  self._extension,
                durationMs: durationMs,
                peaks:      peaks,
                stopReason: self._stopReason || 'user'
            });
        }).catch(function () {
            self._onComplete({
                blob:       blob,
                mimeType:   mimeBare,
                extension:  self._extension,
                durationMs: durationMs,
                peaks:      null,
                stopReason: self._stopReason || 'user'
            });
        });
    };

    MatrixVoiceRecorder.prototype._fail = function (code, message) {
        // Best-effort cleanup; we don't bubble teardown errors above the
        // primary failure so the composer's onError handler renders a
        // clean message.
        this._clearTimers();
        this._teardownStream();
        try { this._onError({ code: code, message: message }); } catch (e) {}
    };

    // Internal helpers exposed for the moderation surface (PR 3) which
    // wants to recompute peaks for legacy uploads.
    MatrixVoiceRecorder.bucketRms      = bucketRms;
    MatrixVoiceRecorder.normalisePeaks = normalisePeaks;
    MatrixVoiceRecorder.pickSupportedMime = pickSupportedMime;

    root.MatrixVoiceRecorder = MatrixVoiceRecorder;
})(typeof window !== 'undefined' ? window : this);
