# Messaging Voice Notes — Tasks

Status: **Draft, awaiting review**
See `requirements.md` and `design.md` for the rationale and
technical detail behind each task.

Each numbered section below is **one pull request**. PRs land in
the order given. No PR depends on a column or function from a
later PR.

Per repo policy: every numbered PR is a fresh branch + fresh PR
— never push follow-up commits onto a previously-opened PR's
branch, even when the next fix is closely related.

---

## PR 1 — Schema + model + signer widening
Branch: `feat/messaging-voice-notes-schema-and-signer`

- [ ] **1.1** Bump `MATRIX_MLM_DB_VERSION` to `1.0.25` in
      `matrix-mlm.php`.
- [ ] **1.2** Schema migration in
      `Matrix_MLM_Database::maybe_upgrade()`: defensive
      `ADD COLUMN` probes for `kind`, `duration_ms`, and
      `waveform_peaks_json` on `matrix_message_attachments`,
      and the matching `KEY kind (kind)` index. Mirror the
      idempotent INFORMATION_SCHEMA pattern already in use.
- [ ] **1.3** Add `Matrix_MLM_Messaging::VOICE_ALLOWED_MIME`,
      `VOICE_MAX_DURATION_SECONDS_HARD`,
      `VOICE_WAVEFORM_PEAKS` constants plus the three new
      `default_settings()` keys (`allow_voice_notes`,
      `voice_max_duration_seconds`, `voice_max_bytes`).
- [ ] **1.4** Implement `Matrix_MLM_Messaging::voice_allowed_mime()`
      (filter-aware, returns intersection with
      `wp_get_mime_types()`).
- [ ] **1.5** Implement `classify_attachment($id)` and rewire
      `send()` to call it for each id; reject with structured
      `WP_Error` on unsupported MIME (`status: 415`).
- [ ] **1.6** Implement `probe_voice_duration_ms($id)` with
      cached `_matrix_messaging_voice_duration_ms` postmeta.
      Reject the send when probe returns 0 or > configured
      cap; in the cap case, also unlink the on-disk file
      (it will not be referenced by any message row).
- [ ] **1.7** Persist `kind`, `duration_ms`, and
      `waveform_peaks_json` columns on insert into
      `matrix_message_attachments`. Read them back in
      `ajax_fetch_thread` / `ajax_fetch_older` and include
      them on each attachment object in the response.
- [ ] **1.8** Move voice uploads onto a fixed subtree via
      a scoped `upload_dir` filter (`/matrix-messaging-voice/<year>/<month>/`).
      Drop `.htaccess` + `web.config` deny files on first creation
      of the subtree (mirror `Matrix_MLM_User_Loan::ensure_upload_guards`).
- [ ] **1.9** Widen `Matrix_MLM_Attachment_Signer::ALLOWED_SUBTREES`
      to include `/matrix-messaging-voice/`.
- [ ] **1.10** Implement `Matrix_MLM_Attachment_Signer::participant_can_fetch_voice($real_relative)`
      and update `handle_request()` to allow either
      `manage_matrix_mlm` OR a thread participant.
- [ ] **1.11** Manual smoke: post a hand-crafted send AJAX with
      an audio attachment id; verify the new columns populate,
      the signed URL plays back for the participant, and a
      non-participant gets 403 on the same URL.

---

## PR 2 — Recorder + playback UI
Branch: `feat/messaging-voice-notes-ui`

- [ ] **2.1** Localisation: add the new i18n strings
      (recorder labels, error toasts, screen-reader player
      labels) to `Matrix_MLM_User_Messaging::render`'s
      `wp_localize_script` payload. Surface
      `allowVoiceNotes`, `voiceMaxDurationSeconds`, and
      `voiceMaxBytes` from `Matrix_MLM_Messaging::get_settings()`.
- [ ] **2.2** JS: implement `MatrixVoiceRecorder` (start /
      stop / preview / send / cancel / re-record). Feature-
      detect `MediaRecorder` + `getUserMedia`; render the
      microphone button only when both exist AND
      `allowVoiceNotes` is true.
- [ ] **2.3** JS: client-side waveform peak extraction (one-
      shot `OfflineAudioContext` → 64 amplitude buckets).
      Pass as `voice_meta.peaks` JSON on the send AJAX
      payload.
- [ ] **2.4** JS: voice bubble renderer — `<matrix-voice-player>`
      custom element with play / pause / scrub / elapsed /
      total. One-at-a-time playback in a thread (pause peers
      on `play`).
- [ ] **2.5** JS: read-receipt trigger — fire `mark_read` at
      >50% playback OR on `ended`, whichever comes first
      (debounce so seek-back-and-replay does not re-fire).
- [ ] **2.6** CSS: `.matrix-messaging__voice-player` and
      friends. Bubble matches existing chat-bubble visual
      rhythm.
- [ ] **2.7** Edit-window UX for voice: when a voice-only
      message is edited from the existing edit affordance,
      surface a "Delete and re-record" affordance instead of
      an inline body editor.
- [ ] **2.8** Manual smoke: record + send in a DM and in a
      team room, on Chrome (WebM/Opus) and Safari (MP4/AAC).
      Verify waveform draws, scrub works, one-at-a-time
      playback works, read receipt fires at >50%.

---

## PR 3 — Moderation surfacing
Branch: `feat/messaging-voice-notes-moderation`

- [ ] **3.1** Admin reports tab
      (`Matrix_MLM_Admin_Messaging`): when a reported
      message row has voice attachments, render an inline
      `<audio controls>` sourced from the signed URL. Gate
      on `manage_matrix_mlm` (already gated on the page;
      this is defense-in-depth on the player render).
- [ ] **3.2** Soft-deleted voice rows continue to render
      the moderator player even though recipients see the
      `(message deleted)` placeholder.
- [ ] **3.3** Extend `Matrix_MLM_Messaging::cron_cleanup` to
      hard-delete the WP attachment post AND the on-disk file
      for any voice attachment whose owning message has
      `deleted_at` older than 30 days. Image attachments
      get the same treatment in this PR — long-overdue
      garbage collection that the existing cron skipped.
- [ ] **3.4** Manual smoke: report a voice note, confirm
      moderator can play, soft-delete it, confirm recipient
      sees "(message deleted)" but moderator can still play.
      Run cleanup with `wp cron event run matrix_mlm_daily_cron`
      after backdating `deleted_at` to >30d ago, confirm
      the file leaves disk and the attachment post is gone.

---

## PR 4 — Operator settings UI + steering note
Branch: `feat/messaging-voice-notes-admin-settings`

- [ ] **4.1** Admin Messaging settings card: three new
      fields (`allow_voice_notes` checkbox,
      `voice_max_duration_seconds` numeric,
      `voice_max_bytes` numeric in MB). Form-level range
      validation; runtime model layer clamps to hard
      ceiling regardless.
- [ ] **4.2** Append a "Voice notes" section to
      `.kiro/steering/operator-security-notes.md` covering
      storage layout, the participant gate on the signer,
      and the global-disable runbook.
- [ ] **4.3** Manual smoke: toggle off, reload thread,
      confirm microphone disappears for new sends but
      existing voice notes still play; toggle back on.
      Lower duration cap to 30s, confirm recorder auto-
      stops at 30s with the "limit reached" toast.

---

## Out of scope (tracked for a follow-up spec)

- Voice notes on **support tickets** — separate spec when the
  ticket attachment surface is built.
- Voice notes on **admin announcements** — separate spec.
- **Server-side transcription** — separate spec; significant
  new dependency.
- **Hold-to-record + lock-to-hands-free** UX — incremental
  v1.1 polish on top of the v1 tap-to-toggle shape.
