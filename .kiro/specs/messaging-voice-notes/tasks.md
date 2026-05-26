# Messaging Voice Notes — Tasks

Status: **Shipped (all four PRs merged 2026-05-26).**
See `requirements.md` and `design.md` for the rationale and
technical detail behind each task. This document tracks the
final state of the implementation, including a small number of
deliberate divergences from the original plan (recorded in
**Implementation notes** at the bottom).

Each numbered section below was **one pull request**. PRs landed
in the order given. No PR depends on a column or function from a
later PR.

Per repo policy: every numbered PR is a fresh branch + fresh PR
— never push follow-up commits onto a previously-opened PR's
branch, even when the next fix is closely related.

---

## PR 1 — Schema + model + signer widening
Branch: `feat/messaging-voice-notes-schema-and-signer`
Merged: [#370](https://github.com/onyema3/MatrixPro/pull/370)

- [x] **1.1** Bump `MATRIX_MLM_DB_VERSION` to `1.0.25` in
      `matrix-mlm.php`.
- [x] **1.2** Schema migration in
      `Matrix_MLM_Database::maybe_upgrade()`: defensive
      `ADD COLUMN` probes for `kind`, `duration_ms`, and
      `waveform_peaks_json` on `matrix_message_attachments`,
      and the matching `KEY kind (kind)` index. Mirror the
      idempotent INFORMATION_SCHEMA pattern already in use.
- [x] **1.3** Add `Matrix_MLM_Messaging::VOICE_ALLOWED_MIME`,
      `VOICE_MAX_DURATION_SECONDS_HARD`,
      `VOICE_WAVEFORM_PEAKS` constants plus the three new
      `default_settings()` keys (`allow_voice_notes`,
      `voice_max_duration_seconds`, `voice_max_bytes`).
- [x] **1.4** Implement `Matrix_MLM_Messaging::voice_allowed_mime()`
      (filter-aware, returns intersection with
      `wp_get_mime_types()`).
- [x] **1.5** Implement `classify_attachment($id)` and rewire
      `send()` to call it for each id; reject with structured
      `WP_Error` on unsupported MIME (`status: 415`).
- [x] **1.6** Implement `probe_voice_duration_ms($id)` with
      cached `_matrix_messaging_voice_duration_ms` postmeta.
      Reject the send when probe returns 0 or > configured
      cap; in the cap case, also unlink the on-disk file
      (it will not be referenced by any message row).
- [x] **1.7** Persist `kind`, `duration_ms`, and
      `waveform_peaks_json` columns on insert into
      `matrix_message_attachments`. Read them back in
      `ajax_fetch_thread` / `ajax_fetch_older` and include
      them on each attachment object in the response.
- [x] **1.8** Move voice uploads onto a fixed subtree via
      `relocate_voice_attachment()` (`/matrix-messaging-voice/<year>/<month>/`).
      Drop `.htaccess` + `web.config` deny files on first creation
      of the subtree (mirror `Matrix_MLM_User_Loan::ensure_upload_guards`).
- [x] **1.9** Widen `Matrix_MLM_Attachment_Signer::ALLOWED_SUBTREES`
      to include `/matrix-messaging-voice/`.
- [x] **1.10** Implement `Matrix_MLM_Attachment_Signer::participant_can_fetch_voice($real_relative)`
      and update `handle_request()` to allow either
      `manage_matrix_mlm` OR a thread participant.
- [x] **1.11** Manual smoke: post a hand-crafted send AJAX with
      an audio attachment id; verify the new columns populate,
      the signed URL plays back for the participant, and a
      non-participant gets 403 on the same URL.

---

## PR 2 — Recorder + playback UI
Branch: `feat/messaging-voice-notes-ui`
Merged: [#372](https://github.com/onyema3/MatrixPro/pull/372)

- [x] **2.1** Localisation: add the new i18n strings
      (recorder labels, error toasts, screen-reader player
      labels) to `Matrix_MLM_User_Messaging::render`'s
      `wp_localize_script` payload. Surface
      `voiceEnabled`, `voiceMaxDurationSec`, `voiceMaxBytes`,
      `voiceWaveformPeaks`, `voiceAllowedMime` from
      `Matrix_MLM_Messaging::get_settings()` and the matching
      resolvers.
- [x] **2.2** JS: implement `MatrixVoiceRecorder` (start /
      stop / preview / send / cancel / re-record). Feature-
      detect `MediaRecorder` + `getUserMedia`; render the
      microphone button only when both exist AND
      `voiceEnabled` is true.
- [x] **2.3** JS: client-side waveform peak extraction (one-
      shot `OfflineAudioContext` → 64 amplitude buckets,
      AudioContext.decodeAudioData fallback for older Safari).
      Pass as `attachment_meta[<id>][peaks][]` JSON on the
      send AJAX payload.
- [x] **2.4** JS: voice bubble renderer — `<matrix-voice-player>`
      custom element with play / pause / scrub / elapsed /
      total. One-at-a-time playback in a thread (pause peers
      on `play`, via a module-level registry).
- [x] **2.5** JS: read-receipt trigger — fire `mark_read` at
      >50% playback OR on `ended`, whichever comes first
      (debounce so seek-back-and-replay does not re-fire).
- [x] **2.6** CSS: `.matrix-messaging__voice-player` and
      friends in a dedicated `public/css/matrix-voice-notes.css`
      stylesheet (see Implementation note 1).
- [x] **2.7** Edit-window UX for voice: when a voice-only
      message is edited from the existing edit affordance,
      surface a "Re-record" affordance instead of an inline
      body editor. The trash button is preserved alongside.
- [x] **2.8** Manual smoke: record + send in a DM and in a
      team room, on Chrome (WebM/Opus) and Safari (MP4/AAC).
      Verify waveform draws, scrub works, one-at-a-time
      playback works, read receipt fires at >50%.

---

## PR 3 — Moderation surfacing
Branch: `feat/messaging-voice-notes-moderation`
Merged: [#373](https://github.com/onyema3/MatrixPro/pull/373)

- [x] **3.1** Admin reports tab
      (`Matrix_MLM_Admin_Messaging`): when a reported
      message row has voice attachments, render an inline
      `<matrix-voice-player>` sourced from the signed URL.
      Gate on `manage_matrix_mlm` (already gated on the page;
      this is defense-in-depth on the player render).
      Implementation batches the
      `matrix_message_attachments` lookup via a single
      `voice_attachments_for_messages()` helper across the
      union of (open-queue rows, expander context rows) so
      the page render makes one trip to the table instead of
      up to 250 queries.
- [x] **3.2** Soft-deleted voice rows continue to render
      the moderator player even though recipients see the
      `(message deleted)` placeholder.
- [x] **3.3** Extend `Matrix_MLM_Messaging::cron_cleanup` to
      hard-delete the WP attachment post AND the on-disk file
      for any voice attachment whose owning message has
      `deleted_at` older than 30 days, via the new
      `cleanup_expired_voice_files($cutoff)` helper.
      Operator-tunable via the
      `matrix_messaging_voice_cleanup_age_days` filter
      (default 30 days, floored to 1 to prevent racing the
      `matrix_messages` DELETE). Cron tick capped at 1000
      messages so a one-off mass delete doesn't exceed the
      WP scheduler's single-run budget. **Image attachments
      are intentionally left alone** — see Implementation
      note 2.
- [x] **3.4** Manual smoke: report a voice note, confirm
      moderator can play, soft-delete it, confirm recipient
      sees "(message deleted)" but moderator can still play.
      Run cleanup with `wp cron event run matrix_mlm_daily_cron`
      after backdating `deleted_at` to >30d ago, confirm
      the file leaves disk and the attachment post is gone.

---

## PR 4 — Operator settings UI + steering note
Branch: `feat/messaging-voice-notes-admin-settings`
Merged: [#374](https://github.com/onyema3/MatrixPro/pull/374)

- [x] **4.1** Admin Messaging settings card: three new
      fields (`allow_voice_notes` checkbox,
      `voice_max_duration_seconds` numeric,
      `voice_max_mb` numeric in MB → stored as
      `voice_max_bytes`). Form-level range
      validation; runtime model layer clamps to hard
      ceiling regardless. The MB input's `max` attribute
      mirrors the image attachment cap so the upper bound
      shifts in lockstep when an operator lowers the image
      cap.
- [x] **4.2** Land the operator contract as a steering note.
      Implementation chose a **dedicated** steering file
      (`.kiro/steering/messaging-voice-notes.md`) rather than
      appending to `.kiro/steering/operator-security-notes.md`
      — see Implementation note 3.
- [x] **4.3** Manual smoke: toggle off, reload thread,
      confirm microphone disappears for new sends but
      existing voice notes still play; toggle back on.
      Lower duration cap to 30s, confirm recorder auto-
      stops at 30s with the "limit reached" toast.

---

## Implementation notes (deliberate divergences from the plan)

These are recorded so a future reader who finds the plan and
the code disagreeing knows the disagreement is on purpose.

1. **Voice CSS landed in its own stylesheet** rather than as an
   addition to `public/css/matrix-messaging.css`. Both the user
   composer and the admin moderation page enqueue the same
   `public/css/matrix-voice-notes.css` file, so the recorder +
   waveform-player styling has a clear ownership boundary and
   the admin page doesn't need to drag in the entire messaging
   chrome stylesheet.

2. **Image attachments are NOT garbage-collected by `cron_cleanup`**,
   even though task 3.3 originally proposed extending the same
   30-day pass to images. Reasoning (recorded in [#373](https://github.com/onyema3/MatrixPro/pull/373)):
   image attachments live in the standard WP media library and
   may be linked elsewhere on the site (a draft post, a user
   profile); the existing operator workflow is to clean them
   via the media library proper. Voice files have no other
   lifecycle (they exist solely to back a message), so it's
   safe to pair their disk-cleanup directly with the message
   hard-delete. Extending GC to images was deferred until the
   operator team can confirm there are no out-of-band link
   sources we don't know about.

3. **Steering note landed as a dedicated file** at
   `.kiro/steering/messaging-voice-notes.md` rather than as an
   appended section to `.kiro/steering/operator-security-notes.md`.
   The voice-notes operator contract is large enough (settings
   table, storage layout, retention timeline, capability matrix,
   observability format, runbook) that grafting it onto the
   webhook/encryption-focused security notes file would have
   buried it. A dedicated file is also easier to find via grep
   when an operator is hunting for "where do I tune the cleanup
   age?" The existing `operator-security-notes.md` is unchanged.

4. **`kind` column is `VARCHAR(16)`, not `ENUM('image','voice')`**
   as proposed in `design.md`. VARCHAR is more forward-compatible:
   adding a future kind (e.g. `'video'`, `'sticker'`) doesn't
   require an ALTER TABLE — `dbDelta` doesn't reliably ALTER
   ENUMs, and an INFORMATION_SCHEMA-probe-and-MODIFY shape adds
   migration surface for every future kind. The runtime layer
   gates kinds via `classify_attachment()` regardless of the
   storage shape, so the ENUM-style validation invariant is
   preserved at the application layer.

---

## Out of scope (tracked for a follow-up spec)

- Voice notes on **support tickets** — separate spec when the
  ticket attachment surface is built.
- Voice notes on **admin announcements** — separate spec.
- **Server-side transcription** — separate spec; significant
  new dependency.
- **Hold-to-record + lock-to-hands-free** UX — incremental
  v1.1 polish on top of the v1 tap-to-toggle shape.
