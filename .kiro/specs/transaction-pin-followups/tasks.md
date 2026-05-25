# Transaction PIN — Follow-up Tasks (A, B)

Status: **Draft, awaiting review**
See `requirements.md` and `design.md` for the rationale and
technical detail behind each task.

Each numbered section below is **one pull request**. Per the
team learning, never push additional commits onto an existing
PR's branch — open a new branch + PR for each task or follow-up.
PRs land in order. Neither PR depends on the other (Item B
doesn't touch the email path; Item A doesn't touch the lockout
internals), so they can ship in either order if needed.

---

## PR 1 — Item A: HTML email templates for PIN events
Branch: `feat/transaction-pin-html-emails`

- [ ] **1.1** Create
      `public/templates/emails/transaction-pin-set.php`. Body
      shape mirrors `subscription-deactivation.php`: greeting,
      headline `<p>`, metadata table (time / IP / UA), CTA
      paragraph linking to the Security tab, closing
      "If this wasn't you" line. Receives `$vars` with
      `display_name`, `site_name`, `time`, `ip`, `user_agent`.
- [ ] **1.2** Create `transaction-pin-change.php`,
      `transaction-pin-disable.php`, `transaction-pin-forgot.php`
      with the same shape. Per-event copy matches the inline
      strings currently in
      `Matrix_MLM_Transaction_Pin::notify()` (modulo HTML).
- [ ] **1.3** Create `transaction-pin-locked.php`. Same shape
      plus an extra `unlock_at` row and a stronger CTA on the
      "Use Forgot PIN to reset" line (this is the only
      template where the user is in an actively-broken state).
- [ ] **1.4** Add
      `Matrix_MLM_Notifications::send_transaction_pin_email($user_id, $event, $context)`
      per `design.md`. Resolves the user, builds the common
      `$vars` (display name / site name / time / IP / UA via
      `Matrix_MLM_Rate_Limiter::client_ip()`), merges
      `$context` (so `unlock_at` lands on the locked
      template), and dispatches to `send_email()`.
- [ ] **1.5** Add
      `Matrix_MLM_Notifications::pin_email_subject($event)`
      private helper covering the five events plus a
      defensive default. Localised strings live here so the
      i18n surface is grouped with the other email subjects.
- [ ] **1.6** Replace
      `Matrix_MLM_Transaction_Pin::notify()`'s body with a
      one-liner that delegates to
      `send_transaction_pin_email`. Constants and call sites
      (`set()`, `change()`, `disable()`, `forgot()`,
      `record_failure()`'s lockout trigger) stay unchanged.
- [ ] **1.7** Manual smoke: trigger each of the five events,
      verify the email arrives via the branded wrapper with
      the right subject line and a populated metadata block.
- [ ] **1.8** Manual smoke: drop a child-theme override
      (`stylesheet_directory()/matrix-mlm/emails/transaction-
      pin-set.php`) and re-trigger Set, verify the override
      renders.
- [ ] **1.9** Manual smoke: open the locked-PIN email in
      Outlook web with default settings, verify the metadata
      table doesn't get truncated by Outlook's CSS sandbox.

---

## PR 2 — Item B: Admin-tunable lockout thresholds
Branch: `feat/transaction-pin-tunable-lockout`

- [ ] **2.1** Add
      `Matrix_MLM_Transaction_Pin::lockout_threshold()`
      public static accessor. Reads
      `matrix_mlm_transaction_pin_lockout_threshold`,
      defaults to `self::HARD_LOCKOUT_THRESHOLD`, clamps to
      [3, 30].
- [ ] **2.2** Add
      `Matrix_MLM_Transaction_Pin::lockout_hours()`. Reads
      `matrix_mlm_transaction_pin_lockout_hours`, defaults to
      `self::HARD_LOCKOUT_HOURS`, clamps to [1, 168].
- [ ] **2.3** Update `record_failure()` to use the accessors
      instead of reading the constants directly. The constants
      themselves stay defined as the documented defaults.
- [ ] **2.4** Add the two number inputs to the Transaction
      PIN sub-section of `render_security_tab()` in
      `class-matrix-admin-settings.php`. Inline description
      copy: default value + range bounds + brief "users can
      self-recover via Forgot PIN regardless" note.
- [ ] **2.5** Extend the Security tab's save handler to add
      both option keys to the whitelist. Validate min/max
      bounds; on out-of-range, revert and surface
      `add_settings_error()` with operator-actionable copy.
- [ ] **2.6** Headless test: assert
      `lockout_threshold()` / `lockout_hours()` behave
      correctly across the four cases (unset → constant,
      below min → clamped, above max → clamped, in-range →
      verbatim).
- [ ] **2.7** Manual smoke: tune threshold to 3 / lockout to
      1h on staging, fail a verify 3 times, confirm
      `transaction_pin_locked_until` reflects 1h not 24h.
- [ ] **2.8** Manual smoke: change the option mid-lock,
      confirm the in-flight lock keeps its original unlock
      timestamp (the user-visible `unlock_at` doesn't shift
      retroactively — acceptance-criteria item 7 of
      `requirements.md` Item B).
- [ ] **2.9** Manual smoke: submit out-of-range values via
      the admin form, confirm the clamp fires and the
      operator sees the validation error.

---

## Out of scope (captured here so they don't get lost)

- **SMS twin for PIN events.** The platform has SMS
  notifications for some events (subscription deactivation,
  ticket replies) via `Matrix_MLM_Notifications::send_sms`.
  Adding SMS for PIN events is a parallel decision to the
  HTML-email work — it can land in either order, but the
  scope boundary is intentional in PR 1: refactor the email
  transport without expanding the notification surface.
- **Rate-limit knobs as options.** `VERIFY_MAX_ATTEMPTS` and
  `VERIFY_WINDOW_SECONDS` are also class constants today, but
  they belong to the rolling-window rate-limiter (which is
  separate from the persistent lockout) and were intentionally
  tuned to match `Matrix_MLM_Rate_Limiter`'s defaults. Promoting
  them is a separate decision with a separate threat model;
  out of scope for PR 2 here.
- **Per-user / per-path lockout overrides.** Both raised
  during the PR 2 design discussion and explicitly declined.
  See the "Out" sub-bullet in `requirements.md` Item B.
