# Messaging Voice Notes — Requirements

Status: **Draft, awaiting review**
Owner: onyema3
Scope: `includes/class-matrix-messaging.php`,
`includes/user/class-matrix-user-messaging.php`,
`includes/class-matrix-database.php` (schema bump),
`public/js/matrix-messaging.js`,
`public/css/matrix-messaging.css`,
`includes/class-matrix-attachment-signer.php` (allow-list widening).

This document captures the voice-note feature for the
member-to-member messaging surface. It is intentionally narrower
than the broad "voice everywhere" interpretation: we ship voice on
DMs, team rooms, and group rooms first, where the UI and
infrastructure already exist, and defer support tickets and admin
announcements to a follow-up so the moderation surface for those
contexts can be designed separately.

---

## Problem

The chat surface today is text + images. Members on mobile
keyboards (the majority of MatrixPro's audience in West Africa)
have asked for the ability to send a short recorded message
instead of typing. The request matches the WhatsApp / Telegram
voice-note pattern: hold-to-record, release-to-send, tap-to-play
in the conversation timeline.

The feature is small but touches every layer of the messaging
stack — schema (we need to know an attachment is audio, not an
image), validation (audio MIME whitelist + duration cap),
delivery (raw `/uploads/` URLs to a member's voice are a privacy
floor we are not comfortable with — they should ride the
attachment-signer the same way KYC files do), UI (recorder +
playback + waveform), and moderation (admins must be able to
listen to a reported voice note from the moderation queue).

## Goal

Let any active member record and send a voice note in any thread
they can already send a text message in, with the same block /
mute / report / edit-window / soft-delete / admin-moderation
semantics that text messages have today.

## Scope

### In scope (v1)

- Voice notes in **DMs, team rooms, and group rooms**.
- Recording in-browser via the standard `MediaRecorder` API,
  client-side encoded to **Opus in WebM or MP4** (whichever the
  browser advertises as supported — Safari prefers MP4/AAC, every
  other modern browser prefers WebM/Opus).
- Server-side **MIME whitelist** of audio types accepted, with a
  byte cap and a duration cap.
- Waveform-style playback control with elapsed / total time and
  scrub bar.
- Voice notes count against the existing
  `max_attachments_per_message` cap, the same as images. A
  message can carry images OR voice notes OR both, up to the
  per-message cap.
- All voice attachments served through `Matrix_MLM_Attachment_Signer`
  with a short-lived signed URL — raw `/uploads/` links to a
  voice file MUST NOT appear in any rendered HTML.
- The sender's existing 5-minute self-edit / self-delete window
  applies. (Edit on a voice note is "delete and re-record" — there
  is no in-place audio editor; the existing edit AJAX endpoint
  will refuse a body-only edit on a voice-only message and must
  surface a clear UI for "delete and re-record".)
- Moderation: voice notes can be reported with the existing
  reasons; the admin moderation page must be able to play the
  reported voice file inline.
- Soft-delete cleanup: when a voice message is soft-deleted, the
  underlying WP attachment is detached from the message row but
  retained until the 30-day hard-delete cron sweep.

### Out of scope (defer to v2 / later)

- Voice notes on **support tickets**. Tickets have a different
  attachment infrastructure today (none, in fact — replies are
  text-only) and an asynchronous review cadence; we want one
  surface battle-tested before we widen.
- Voice notes on **admin announcements**. Outbound-only context;
  worth doing later but not part of this drop.
- **Server-side transcription** to text. Adds a third-party
  dependency and a privacy surface that is out of proportion to
  the v1 ask. Documented as a future option in `design.md`.
- **Streaming playback** while still recording. Not how WhatsApp
  works either — record fully, stop, send.
- **Recording on devices that lack `MediaRecorder`**. We feature-
  detect and fall back to "voice recording is not supported on
  this browser" instead of polyfilling. Anything older than
  Chrome 49 / Safari 14.1 / Firefox 25 falls into this bucket.
- **Per-thread "voice notes off" toggle**. Operators can disable
  voice notes globally; per-thread granularity is not a v1 need.

## User stories

### Sender

> As a member, I want to hold a microphone button to record a
> voice note, see a recording timer, release to stop, and send
> with a single tap — so I do not have to type a long answer on
> a small phone keyboard.

> As a member, if I make a mistake while recording, I want to
> swipe to cancel before sending — so I do not have to send a
> bad recording and then immediately delete it.

### Recipient

> As a member, when I receive a voice note, I want to see a
> waveform-shaped playback control with the duration before I
> tap play — so I can decide whether to listen now (3-second
> reply) or later (45-second voicemail).

> As a member, I want the voice playback to behave the same way
> as the text bubble — same time stamp, same edited / deleted
> placeholder, same reaction picker, same reply context.

### Operator

> As a platform admin, when a voice note is reported, I want to
> open the moderation queue and play the reported audio inline
> in the same row as the report metadata — so I do not have to
> download the file, open it in a media player, and lose the
> per-row context.

> As a platform admin, I want a global toggle that disables voice
> notes entirely — so I can turn them off without uninstalling
> the feature if a moderation incident requires it.

## Acceptance criteria

### Sending

1. The compose bar exposes a microphone button when
   `matrix_mlm_messaging_settings.allow_voice_notes` is `1`
   AND the browser exposes `window.MediaRecorder`. When either
   precondition is false, the button is not rendered (it is not
   rendered-and-disabled — disabled would mislead users into
   thinking it might work after a click).
2. Tapping (or holding) the microphone button:
   - prompts for microphone permission via
     `navigator.mediaDevices.getUserMedia({ audio: true })` and
     refuses to start recording if permission is denied,
   - shows an in-place recording UI replacing the compose bar:
     a stop button, a cancel button, an MM:SS timer, and a
     pulsing red dot.
3. The duration cap is **2 minutes** by default, operator-tunable
   via `matrix_mlm_messaging_settings.voice_max_duration_seconds`
   with a hard ceiling of 5 minutes. The recorder auto-stops at
   the cap and surfaces a clear "recording stopped at limit"
   toast.
4. The byte cap is **2 MB** by default, operator-tunable via
   `matrix_mlm_messaging_settings.voice_max_bytes` with a hard
   ceiling of `max_attachment_bytes` (i.e. it can never exceed
   the global per-attachment cap).
5. On stop, the user can preview the recording before sending
   (play / re-record / cancel / send). On send, the file is
   uploaded via `wp.media`-style XHR to `wp-admin/async-upload.php`
   the same way image attachments are today, and the resulting
   WP attachment id is passed in the existing `attachment_ids[]`
   array on the existing `wp_ajax_matrix_messaging_send` action.
6. The send AJAX handler validates each attachment id's
   underlying `post_mime_type` against an audio whitelist when
   the `kind` flag on the attachment row says `voice`; rejects
   the send with a structured `WP_Error` (status 415) if any id
   has a non-whitelisted MIME.
7. `attachment_ids` continues to honor the existing
   `max_attachments_per_message` cap — a single send can carry
   up to N items mixed across image and voice.

### Receiving

8. The conversation timeline renders a voice attachment as a
   horizontal player containing: play button, animated waveform
   (or a flat bar if waveform peaks were not provided), elapsed
   timer, total duration, and an unobtrusive "voice note" label
   for screen readers (`aria-label`).
9. The audio source URL is always the **signed REST URL** from
   `Matrix_MLM_Attachment_Signer`. The raw `/uploads/` URL must
   not appear in the rendered HTML or in the AJAX JSON payload.
10. Tapping play streams from the signed URL. The player
    pauses any other voice note already playing in the same
    pane (one-at-a-time playback within a thread).
11. The played voice note is treated as "read" the moment the
    user reaches >50% playback OR finishes — the existing
    `mark_read` AJAX is fired with the message id, mirroring the
    image-loaded read receipt.
12. Soft-deleted voice notes render the existing
    `(message deleted)` placeholder. The signed URL for the
    underlying file is no longer issued — even with a still-
    valid recipient session, the moderation queue is the only
    surface that can play a soft-deleted voice note.

### Moderation

13. The admin moderation page (`Matrix_MLM_Admin_Messaging`'s
    reports tab) renders an inline player for a reported voice
    note, sourced from the same signed URL — gated by the
    existing `manage_matrix_mlm` capability, so a non-admin who
    obtains the signed URL still 403s.
14. When a moderator soft-deletes a voice note, the underlying
    WP attachment row is NOT immediately deleted — the
    nightly `cron_cleanup` does the hard delete after 30 days
    (parity with image and text cleanup). This window lets a
    revoked decision be undone.
15. When a user is banned (`Matrix_MLM_Messaging::ban_user`),
    they cannot send voice notes either. No new code path needed
    — the existing pre-send ban gate fires first.

### Operator surface

16. Admin Settings → Messaging exposes three new fields:
    - `Allow voice notes` (boolean, default ON),
    - `Voice note duration cap (seconds)` (default 120,
      hard-ceiling 300),
    - `Voice note byte cap (MB)` (default 2, hard-ceiling
      `max_attachment_bytes`).
    All three are persisted into the existing
    `matrix_mlm_messaging_settings` option blob with no new
    storage row.
17. The `matrix_messaging_voice_max_duration_seconds` and
    `matrix_messaging_voice_max_bytes` filters mirror the
    options for code-level overrides. Both filters are clamped
    to the hard ceiling at the consumer site so a misconfigured
    `add_filter` cannot raise the cap above the documented
    maximum.

### Privacy / security

18. Voice attachments are never URL-linked publicly. Direct
    requests to `wp-content/uploads/<voice-note-path>` MUST
    receive an HTTP error from the web-server-layer rules (we
    add a `.htaccess` deny for the messaging upload subtree on
    first creation, mirroring the loan-files pattern).
19. The Attachment Signer's allow-list (`ALLOWED_SUBTREES`)
    gains a messaging entry. Capability gate inside the signer
    is widened: a logged-in member can fetch a signed URL for a
    voice note in a thread they are an active participant in;
    `manage_matrix_mlm` admins continue to reach any signed
    URL the way they do today for KYC files.
20. The audit-log emitter for `matrix_messaging_send` MUST NOT
    write the audio body or the signed URL to the log row. The
    existing emitter already redacts attachment URLs; this is a
    "preserve the redaction in any new code path" item.

## Open questions (defaults chosen)

- **Codec** — Opus is the right default for size/quality. Browsers
  that cannot record Opus (older Safari) will record AAC in MP4.
  We accept both server-side; the player tag picks whichever the
  browser supports for playback. **Default: accept both**.
- **Recorder UX** — hold-to-record vs. tap-to-toggle. WhatsApp
  uses both (tap = lock for hands-free, hold = quick send).
  v1 ships **tap-to-toggle** (start, stop, send) as the simpler
  shape; the lock affordance is a v1.1 cosmetic add.
- **Waveform peaks** — pre-computed client-side at record stop
  (downsample the PCM to 64 amplitude buckets, send as a JSON
  array on the new `waveform_peaks` column). Server does not
  recompute. **Default: client-side, optional column**.
- **Max sample rate / bitrate** — let the browser pick.
  `MediaRecorder` defaults are sane (48 kHz / ~24 kbps Opus,
  ~128 kbps AAC). Operator-tunable knobs for these are out of
  v1 scope; the byte cap is the single sufficient brake.
