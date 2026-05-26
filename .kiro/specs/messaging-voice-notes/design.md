# Messaging Voice Notes — Design

Status: **Draft, awaiting review**
See `requirements.md` for goals and acceptance criteria.

This document is the technical design. The work splits into four
shippable PRs (see `tasks.md`); each PR's slice of the schema and
code lives in its own section here.

---

## Touchpoints overview

```
Browser
  ├─ MediaRecorder ──────► Blob (audio/webm | audio/mp4)
  │                          │
  │                          ▼
  │                    XHR POST to async-upload.php
  │                          │
  │                          ▼
  │                    WP attachment post (post_mime_type = audio/...)
  │
  └─ matrix_messaging_send AJAX
       attachment_ids = [<wp_attachment_id>, ...]

Server
  Matrix_MLM_Messaging::send()
    ├─ validate each attachment id
    │     ├─ MIME whitelist gate (audio types → kind = 'voice')
    │     ├─ duration cap probe (server-side)
    │     ├─ byte cap probe
    │     └─ ban / block / rate-limit (existing)
    ├─ INSERT matrix_messages row
    ├─ INSERT matrix_message_attachments rows (one per id)
    │     - kind = 'voice' | 'image'
    │     - duration_ms, waveform_peaks_json (voice-only)
    └─ enqueue offline-recipient notifications

  Render path (ajax_fetch_thread / ajax_fetch_older)
    └─ for each voice attachment
         └─ Matrix_MLM_Attachment_Signer::sign_relative_path(...)
              with widened ALLOWED_SUBTREES + participant gate
```

---

## Shared: schema evolution

### `wp_matrix_message_attachments` today (DB 1.0.23)

```sql
CREATE TABLE {prefix}matrix_message_attachments (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    message_id      BIGINT UNSIGNED NOT NULL,
    attachment_id   BIGINT UNSIGNED NOT NULL,           -- wp_posts.ID
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY message_id (message_id),
    KEY attachment_id (attachment_id)
);
```

### After this spec lands (DB 1.0.25)

```sql
CREATE TABLE {prefix}matrix_message_attachments (
    id                   BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    message_id           BIGINT UNSIGNED NOT NULL,
    attachment_id        BIGINT UNSIGNED NOT NULL,
    kind                 ENUM('image','voice') NOT NULL DEFAULT 'image', -- new
    duration_ms          INT UNSIGNED DEFAULT NULL,                       -- new (voice only)
    waveform_peaks_json  TEXT DEFAULT NULL,                               -- new (voice only)
    sort_order           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY message_id (message_id),
    KEY attachment_id (attachment_id),
    KEY kind (kind)
);
```

The migration is idempotent and runs inside the existing
`Matrix_MLM_Database::maybe_upgrade()` slow path. Three defensive
`ADD COLUMN` probes via `INFORMATION_SCHEMA.COLUMNS`, mirroring
the pattern already established for the e-pin and 2FA columns.
Backfill: existing rows are all images (the table predates voice
support entirely), so `kind` defaults to `image` on existing rows
and the two voice-only columns stay NULL — no UPDATE pass needed.

`waveform_peaks_json` is `TEXT` rather than a fixed shape because
peak counts may vary in v1.1 (we ship 64 buckets in v1, but a
future "long voicemail" mode might want 128). Reading code
should `json_decode` defensively and fall back to a flat bar if
the column is NULL or malformed — never throw.

---

## Shared: MIME whitelist + duration probe

### Whitelisted audio MIME types

The server-side whitelist is intentionally short:

| MIME type      | Container | Codec | Why we accept it |
|----------------|-----------|-------|------------------|
| `audio/webm`   | WebM      | Opus  | Chrome / Firefox / Edge default |
| `audio/ogg`    | Ogg       | Opus  | Older Firefox; legacy desktop |
| `audio/mp4`    | MP4       | AAC   | Safari (iOS + macOS) default |
| `audio/mpeg`   | MP3       | MP3   | Defensive — some Android browsers fall through to MP3 |

Anything outside this list is rejected at validation time with a
clear error code (`matrix_messaging_voice_unsupported_mime`).
We do NOT accept `audio/wav` — it produces 10x the file size for
no perceptual quality gain at speech bitrates and would let a
single voice note exhaust the byte cap.

The whitelist is exposed as `Matrix_MLM_Messaging::VOICE_ALLOWED_MIME`,
filterable via `matrix_messaging_voice_allowed_mime` for
operators who need to add a regional codec — but the filter must
return a subset of the union of WP's `wp_get_mime_types()` so a
typo cannot widen the upload-handler's accepted types.

### Server-side duration cap

`MediaRecorder` provides duration on the client, but a malicious
client can lie. We probe duration server-side using the same
`getID3`-style approach WP core uses for media metadata
(`wp_read_audio_metadata()` is the entry point — already
available because `wp-admin/includes/media.php` is loaded any
time `async-upload.php` runs). The probe runs once at attachment
registration time; the result is cached in
`wp_postmeta._matrix_messaging_voice_duration_ms` so we never
re-probe the file.

If the probe returns a duration that exceeds the configured cap,
the attachment is **deleted from disk** and the send is rejected.
This is stricter than the byte cap (which the upload handler
enforces before our code runs); a duration overflow gets to disk
first because we do not block the upload, only the send.

### Client-side byte cap pre-flight

The compose JS computes the recorded `Blob.size` and refuses to
even start the upload if it exceeds `voice_max_bytes`. Server-
side cap is the authority; client-side is a UX nicety.

---

## PR 1 — Schema + model + signer widening

Branch: `feat/messaging-voice-notes-schema-and-signer`

### Schema

`Matrix_MLM_Database::maybe_upgrade()` adds the three new columns
on `matrix_message_attachments` per the table above. Bumps
`MATRIX_MLM_DB_VERSION` to `1.0.25`.

### Settings

`Matrix_MLM_Messaging::default_settings()` gains three keys:

```php
'allow_voice_notes'              => 1,
'voice_max_duration_seconds'     => 120,
'voice_max_bytes'                => 2 * 1024 * 1024,
```

### Constants on `Matrix_MLM_Messaging`

```php
const VOICE_ALLOWED_MIME = [
    'audio/webm',
    'audio/ogg',
    'audio/mp4',
    'audio/mpeg',
];
const VOICE_MAX_DURATION_SECONDS_HARD = 300;
const VOICE_WAVEFORM_PEAKS = 64;       // documented client target
```

### `Matrix_MLM_Messaging::validate_attachments()` widening

Today the helper accepts any WP attachment id and trusts it; the
caller is the sole gate. After this PR:

```php
private static function classify_attachment($attachment_id) {
    $mime = (string) get_post_mime_type($attachment_id);
    if ($mime === '') {
        return new WP_Error('matrix_messaging_attachment_unknown', ...);
    }
    if (strpos($mime, 'image/') === 0) {
        return ['kind' => 'image', 'mime' => $mime];
    }
    if (in_array($mime, self::voice_allowed_mime(), true)) {
        return ['kind' => 'voice', 'mime' => $mime];
    }
    return new WP_Error('matrix_messaging_voice_unsupported_mime', ...);
}
```

`send()` calls `classify_attachment()` for each id, rejects any
that returns `WP_Error`, and passes the kind/duration/peaks
through to the `INSERT`s on `matrix_message_attachments`.

### Duration probe + cap enforcement

```php
private static function probe_voice_duration_ms($attachment_id) {
    $cached = (int) get_post_meta($attachment_id, '_matrix_messaging_voice_duration_ms', true);
    if ($cached > 0) {
        return $cached;
    }
    $path = get_attached_file($attachment_id);
    if (!$path || !file_exists($path)) {
        return 0;
    }
    require_once ABSPATH . 'wp-admin/includes/media.php';
    $meta = wp_read_audio_metadata($path);
    $seconds = is_array($meta) && isset($meta['length']) ? (float) $meta['length'] : 0.0;
    $ms = (int) round($seconds * 1000);
    if ($ms > 0) {
        update_post_meta($attachment_id, '_matrix_messaging_voice_duration_ms', $ms);
    }
    return $ms;
}
```

If `probe_voice_duration_ms()` returns 0 (probe failed) we treat
the duration as unknown and reject the send — better to refuse a
genuine recording than to accept an undurated blob that bypasses
the cap.

### Attachment-signer widening

`Matrix_MLM_Attachment_Signer::ALLOWED_SUBTREES` gains a
messaging entry. Default WP places attachment uploads under
`/<year>/<month>/`, which is too broad to safely allow-list
verbatim — instead, voice notes are uploaded with a
`upload_dir` filter that pins them under
`/matrix-messaging-voice/<year>/<month>/`. The `.htaccess` deny
file is dropped in `/matrix-messaging-voice/` on first creation
(mirroring the loan-files pattern in
`Matrix_MLM_User_Loan::ensure_upload_guards`).

```php
const ALLOWED_SUBTREES = [
    '/matrix-loan-files/',
    '/matrix-healthcare-files/',
    '/matrix-messaging-voice/',   // new
];
```

The signer also grows a per-request authorisation hook so a
participant of the thread (not just `manage_matrix_mlm` admins)
can fetch a signed URL — the existing capability gate is too
narrow:

```php
// Inside handle_request(), after MIME / path validation:
if (!current_user_can('manage_matrix_mlm')
    && !self::participant_can_fetch_voice($real_relative)) {
    return new WP_Error('matrix_mlm_attachment_forbidden', ...);
}
```

`participant_can_fetch_voice()` resolves the relative path back
to a WP attachment id (via the `_wp_attached_file` meta query),
finds the `matrix_message_attachments` row that owns it, joins to
`matrix_messages.thread_id`, and checks
`Matrix_MLM_Messaging::user_can_view_thread()`. The lookup is
indexable (one composite index already exists; this PR adds a
companion index on `_wp_attached_file` value-prefix only if
profiling on a representative install shows the query is
expensive — ANALYZE on the dev install first).

### Out of `send()` scope (deliberately)

The recorder UI does not exist yet. PR 1 makes the model layer
voice-aware so the upload-and-send roundtrip works end-to-end
when the client posts an audio attachment id; the user-facing
feature is invisible until PR 2 lands.

---

## PR 2 — Recorder + playback UI

Branch: `feat/messaging-voice-notes-ui`

### Compose-bar recorder

`public/js/matrix-messaging.js` gains a `MatrixVoiceRecorder`
module. Lifecycle:

1. `init()` — feature-detects `MediaRecorder` and `getUserMedia`.
   Renders the microphone button only when both exist AND the
   server has reported `allowVoiceNotes: true` via the existing
   `wp_localize_script` payload.
2. `start()` — requests permission, creates a `MediaRecorder`
   with the best-supported MIME from the server-advertised
   whitelist (`MediaRecorder.isTypeSupported(...)` check ladder),
   begins capturing into a `Blob[]`, starts a 100 ms timer that
   updates the on-screen MM:SS label and auto-stops at the
   configured duration cap.
3. `stop()` — finalises the blob, computes 64 waveform peaks
   client-side using a one-shot `OfflineAudioContext` decode and
   downsample. Renders a preview row: play, scrub, re-record,
   cancel, send.
4. `send()` — uploads the blob to `wp-admin/async-upload.php`
   with the existing media nonce, captures the returned WP
   attachment id, then calls the existing
   `wp_ajax_matrix_messaging_send` with `attachment_ids: [id]`,
   `body: ''`, and a new `voice_meta: { duration_ms, peaks }`
   payload that the server uses to populate the new columns
   (server still re-probes duration as the source of truth).

### Voice bubble renderer

The thread renderer detects the new `kind: 'voice'` shape on
each attachment object returned by `ajax_fetch_thread` /
`ajax_fetch_older` and emits a `<matrix-voice-player>` custom
element instead of the `<img>` it would emit for `kind: 'image'`.
The element wires up `<audio>` with the signed URL, draws the
waveform with peaks scaled to bubble width, runs the elapsed-
time updater on `timeupdate`, fires `mark_read` at >50%
playback, and pauses any sibling player when one starts.

`public/css/matrix-messaging.css` grows
`.matrix-messaging__voice-player`, `.matrix-messaging__voice-wave`,
`.matrix-messaging__voice-progress` and a small set of state
modifiers (`--playing`, `--loading`, `--errored`).

### Localisation strings

A handful of new `i18n` strings on the `MatrixMessaging` localize
payload — recorder labels, error toasts, screen-reader text for
the player. Same shape as the existing typing-indicator strings.

### Edit-window UX for voice

The existing edit endpoint refuses an empty `body` on a message
that has voice attachments. The "edit" button on the sender's
own voice bubble is repurposed: instead of an inline body
editor, it shows a one-click "Delete and re-record" affordance
that calls the existing delete AJAX and then immediately opens
the recorder. The 5-minute window applies the same way it does
to text + image. No new server endpoint.

---

## PR 3 — Moderation surfacing

Branch: `feat/messaging-voice-notes-moderation`

The admin reports tab in `Matrix_MLM_Admin_Messaging` already
renders the reported message body. After this PR, when a
reported message has voice attachments:

1. The row gains an inline `<audio controls>` element sourced
   from the signed URL.
2. The player respects the same `manage_matrix_mlm` capability
   gate that gates the rest of the page.
3. Soft-deleted voice rows still render the player (admins can
   listen to a flagged voice note even after it has been
   removed from the thread) until the 30-day cron sweep
   hard-deletes the underlying file.

`Matrix_MLM_Messaging::cron_cleanup` is extended to delete the
WP attachment post (and its on-disk file) for any
soft-deleted voice attachment older than 30 days — image rows
get the same treatment, but the existing cron only deletes
the message rows; it does not garbage-collect the attachment
posts. This was acceptable for images (a small
`/uploads/.../jpg` is not a privacy concern at the same level
as a voice recording of a member's voice), but for voice we
need the file to actually leave disk on the documented timeline.

---

## PR 4 — Operator settings UI + docs

Branch: `feat/messaging-voice-notes-admin-settings`

### Admin settings card

`includes/admin/class-matrix-admin-messaging.php` (the existing
settings card) gains the three new fields:

- `allow_voice_notes` (checkbox, default ON),
- `voice_max_duration_seconds` (numeric, range 10..300),
- `voice_max_bytes` (numeric, MB, range 0.5..(`max_attachment_bytes` / 1MB)).

The card validates ranges before save and refuses out-of-range
values with an admin notice; the runtime model layer also
clamps via the hard ceiling, so the form is the first line of
defence and the model is the second.

### Steering / operator notes

A new short section is added to
`.kiro/steering/operator-security-notes.md` describing:

- voice-note storage layout
  (`wp-content/uploads/matrix-messaging-voice/<year>/<month>/`),
- the role of the per-folder `.htaccess` deny,
- the signer-mediated participant gate as a privacy floor for
  voice files (a leaked signed URL is valid for ten minutes;
  the file itself is reachable only via the signer for the
  thread participants and admins),
- a checklist for what to do if a moderation incident
  requires a global disable — toggle the setting, hard-delete
  any affected voice attachments via the existing 30-day
  cleanup mechanism (or the manual moderation soft-delete
  path).

---

## Compatibility / fallbacks

- **Old browsers** — feature-detect `MediaRecorder` and
  `getUserMedia`. When absent, the microphone button is not
  rendered. No polyfill, no fallback recorder.
- **Old messaging clients (no JS update yet)** — the server
  returns `kind` on every attachment object regardless. A
  client that does not understand `voice` falls through to
  rendering nothing for the attachment, which is ugly but not
  broken; the v1.1 payload is forward-compatible.
- **Existing images** — every existing row defaults to
  `kind = 'image'`; render path is unchanged for those. No
  user-visible churn on existing threads.

## Test plan (manual smoke, per PR)

PR 1: upload an audio attachment via the existing send path
with a hand-crafted `attachment_ids[]` (no UI yet); verify the
new columns are populated correctly and the signer accepts the
participant. Verify a non-participant 403s on the same signed
URL.

PR 2: record + send a voice note in a DM and a team room from
Chrome and Safari. Verify the recipient hears it correctly,
the waveform renders, scrubbing works, only one player plays
at a time, and the read receipt fires at >50% playback.

PR 3: report a voice note, confirm the moderation row plays the
audio, soft-delete the message, confirm the recipient sees
"(message deleted)" but the moderator can still play the
reported audio. Wait out the 30-day cron (or invoke
`cron_cleanup` manually), verify the attachment file is gone
from disk and the WP attachment row is deleted.

PR 4: toggle `allow_voice_notes` off, reload a thread, confirm
the microphone button disappears for new sends; existing voice
notes in the thread continue to play.
