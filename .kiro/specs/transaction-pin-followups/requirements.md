# Transaction PIN — Follow-up Requirements (A, B)

Status: **Draft, awaiting review**
Owner: onyema3
Scope: `includes/class-matrix-transaction-pin.php`,
`includes/class-matrix-notifications.php`,
`includes/admin/class-matrix-admin-settings.php`,
`public/templates/emails/`

This document captures the two follow-ups identified after the
Transaction PIN hardening + enforcement series landed (PR #269 +
PR #270). Each is intended to ship as its own pull request, in
the order given here.

---

## Item A — HTML email templates for PIN events

### Problem
`Matrix_MLM_Transaction_Pin::notify()` currently builds plain-text
email bodies inline with `wp_mail()` for the five PIN-lifecycle
events (`set` / `change` / `disable` / `forgot` / `locked`). This
was a deliberate scope choice during PR #269 — the priority was
landing the security gate, not styling the notifications — but it
leaves the PIN events as the only branded emails on the platform
that don't use the HTML template pipeline.

The plain-text path has three concrete failure modes worth fixing:

1. **Inconsistent branding.** Every other transactional email on
   the platform (commission credits, withdrawal confirmations,
   subscription deactivation, ticket replies, password reset)
   already routes through `Matrix_MLM_Notifications::send_email()`
   which wraps the body in `public/templates/emails/base.php`
   (header logo, footer disclaimer, dark-mode-safe colour
   scheme). PIN events arrive in a plain monospaced wall of
   text that visually clashes with the rest of the user's inbox
   from this site.

2. **Operator can't customise without forking.** Other email
   surfaces have their copy in dedicated template files an
   operator can override via the standard WP child-theme
   override pattern (`stylesheet_directory()/matrix-mlm/emails/
   <slug>.php`). PIN events have their copy hardcoded in the
   `notify()` switch-case, so changing "Your transaction PIN
   was just changed" to a site-specific tone requires editing
   the plugin source.

3. **No HTML version means clients with HTML-only display
   preferences see the body fall back to the auto-generated
   text rendering**, which strips the line breaks the inline
   `\n\n` separators rely on. The result is a single-paragraph
   blob in some clients (notably Outlook web on default
   settings).

### Goal
Promote the five PIN-event bodies into individual template files
under `public/templates/emails/` and route them through
`Matrix_MLM_Notifications::send_email()` so they inherit the
existing HTML wrapper, child-theme overridability, and
plain-text fallback (which `send_email()` derives from the HTML
via `wp_strip_all_tags`).

### Scope
- **In:** Five new template files (one per event), one
  refactor of `Matrix_MLM_Transaction_Pin::notify()` to
  delegate to `send_email()`, and a thin variable-binding shim
  (the same pattern `send_subscription_deactivation_notification`
  uses to pass `$amount_str`, `$period_label`, etc.).
- **Out:** SMS twin for PIN events. Two-factor / recovery-code
  email re-templating (separate audit, separate PR — the 2FA
  surface has its own scope boundary). New event types beyond
  the current five.

### User story
> As an operator, I want the email a user receives when their
> PIN is locked to look like every other email from my platform
> — same header, same footer, same colours — so the recipient
> recognises it as legitimate and is more likely to act on the
> "Use Forgot PIN to reset" CTA.

### Acceptance criteria
1. `public/templates/emails/transaction-pin-set.php`,
   `transaction-pin-change.php`, `transaction-pin-disable.php`,
   `transaction-pin-forgot.php`, `transaction-pin-locked.php`
   exist, each receiving a `$vars` array with `time`, `ip`,
   `user_agent`, and event-specific extras (`unlock_at` for
   the locked template).
2. `Matrix_MLM_Transaction_Pin::notify()` no longer constructs
   subject + body inline. It calls a new helper
   `Matrix_MLM_Notifications::send_transaction_pin_email($user_id,
   $event, $context)` which loads the right template and
   delegates to `send_email()`.
3. Each template renders correctly in `base.php`'s wrapper
   (header logo, footer, brand colours).
4. Each template ships an English string for every variant
   (no `__()` lookups returning the bare key).
5. Plain-text fallback (auto-derived) is human-readable —
   no orphan HTML tags, no broken `<br>` artefacts.
6. Existing call sites in `Matrix_MLM_Transaction_Pin` (set /
   change / disable / forgot / locked, plus the
   `record_failure()` lockout trigger) all dispatch correctly.
7. The audit log line at `error_log('[Matrix PIN] …')` keeps
   firing — the email refactor is transport-only, not
   observability.
8. Child-theme override path
   (`stylesheet_directory()/matrix-mlm/emails/transaction-pin-
   <event>.php`) works for all five templates, mirroring how
   other emails on the platform are overridable.

### Open questions (defaults chosen)
- **One template per event vs. one shared template with a
  `$variant` switch?** Default: **one per event**. Rationale:
  matches the existing pattern (`commission.php`,
  `cug-application-admin.php`, `subscription-deactivation.php`
  are all one-template-per-event), keeps the per-event copy
  changes a single-file edit, and avoids a switch-case the
  operator has to navigate when overriding from a child theme.
- **Include the User-Agent string?** Default: **yes, but
  truncated to 200 chars** to match the audit log convention.
  Rationale: helps users identify a session ("Chrome on iPhone")
  but doesn't bloat the body when the UA is one of the absurdly
  long enterprise-MDM strings some corporate devices emit.

---

## Item B — Admin-tunable lockout thresholds

### Problem
`Matrix_MLM_Transaction_Pin` currently exposes its hard-lockout
knobs as class constants:

```php
const HARD_LOCKOUT_THRESHOLD = 10;  // wrong-PIN attempts before lock
const HARD_LOCKOUT_HOURS     = 24;  // duration of the lock
```

The values match common ATM behaviour ("10 wrong PINs and your
card is captured for 24h") and are a sensible default, but
operators with different threat profiles have no way to tune
them without forking the plugin:

- A high-security install (e.g. a financial co-operative
  serving a small membership) might want 5/72h for a tighter
  cap with longer cool-down.
- A high-volume install with many less-technical users might
  want 15/4h to absorb more typos before locking and to
  recover faster from accidental lockouts (since support load
  scales with locked-account count).
- A staging / QA install needs the lock to expire in minutes,
  not hours, to keep iteration loops short.

The lockout call sites already funnel through
`self::HARD_LOCKOUT_THRESHOLD` and `self::HARD_LOCKOUT_HOURS`,
so promoting these to options is mechanical — replace the two
constant reads with `get_option()` reads (with the constants
as defaults), and add a small admin UI on Settings → Security.

### Goal
Make `HARD_LOCKOUT_THRESHOLD` and `HARD_LOCKOUT_HOURS` configurable
at runtime via the WP options table and the admin Settings →
Security tab, with the current constant values as defaults. Keep
the constants in place as both the default fallback (so a
fresh install behaves identically to today) and the documented
canonical default (so an operator who saved an option and wants
to revert can read the original value off the source).

### Scope
- **In:** Two new options
  (`matrix_mlm_transaction_pin_lockout_threshold`,
  `matrix_mlm_transaction_pin_lockout_hours`), accessor methods
  that read them with the constants as defaults, admin UI rows
  on the existing Settings → Security tab, save-side validation
  (positive integer for both, sane upper bounds).
- **Out:** Per-user overrides (an operator wanting to reset the
  lockout for a specific user already has the Forgot PIN path).
  Per-path overrides (the gate is path-aware but the lockout is
  intentionally not — a user's PIN is the same across all
  five paths, so the lockout should be too). Tunable
  `VERIFY_MAX_ATTEMPTS` / `VERIFY_WINDOW_SECONDS` (those are
  the rolling per-request rate-limit, not the persistent
  lockout — they belong to a separate config surface and were
  intentionally tuned to match `Matrix_MLM_Rate_Limiter`'s
  defaults).

### User story
> As an operator running a small financial co-op where every
> member is known and trusted, I want to tighten the PIN
> lockout to 5 wrong attempts and 72 hours, because losing a
> card via fraud is far more costly to my members than a
> false-positive lockout. I shouldn't have to fork the plugin
> to do this.

### Acceptance criteria
1. New helper `Matrix_MLM_Transaction_Pin::lockout_threshold()`
   returns `(int) get_option('matrix_mlm_transaction_pin_lockout_threshold',
   self::HARD_LOCKOUT_THRESHOLD)`. New helper `lockout_hours()`
   does the same for the hour count.
2. The two existing call sites
   (`record_failure()`'s threshold check and lockout-window
   write) call the helpers instead of reading the constants
   directly. Constants stay defined as the default values.
3. Settings → Security tab has two new rows:
   "Wrong-PIN attempts before lockout" (number, min 3, max 30)
   and "Lockout duration (hours)" (number, min 1, max 168 = 7
   days). Defaults populated from the constants on a fresh
   install.
4. Save-side validation rejects values outside the bounds with
   a settings-API error and reverts to the default. (Defence
   against a misconfiguration that bricks the gate by setting
   the threshold to 0 — every wrong PIN would lock immediately
   — or to a million, defeating the purpose of the lockout.)
5. Existing constant-only behaviour is preserved when the
   options are unset (fresh install / first upgrade).
6. The `notify()` "PIN locked" email body and the
   `lockout_info()`-driven UI banner both surface the
   currently-active lockout duration (read off the option,
   not the constant) so the user sees the right unlock
   timestamp.
7. The `pin_required_for_request` lockout-message path that
   suggests "use Forgot PIN" remains unchanged — Forgot PIN
   is the universal escape hatch regardless of how the
   threshold or duration is tuned.

### Open questions (defaults chosen)
- **Bounds.** Default: threshold 3-30, hours 1-168 (1 hour to
  7 days). Rationale: 3 is the floor below which a typo on a
  numeric keypad becomes the lockout's primary failure mode
  rather than brute-force attempts; 30 is a soft cap above
  which the lockout stops being a meaningful brute-force
  defence. 1-168h covers the realistic operational range from
  staging (1h) through banking-grade (1 week). Operators
  wanting values outside those ranges almost certainly have a
  bug they should triage instead of silently bypassing.
- **Where the UI lives.** Default: extend the existing
  Settings → Security tab section that already hosts the
  master "Transaction PIN" toggle and the per-path
  `pin_required_for_*` checkboxes, rather than introducing a
  new "Lockout" sub-section. Rationale: keeps the PIN
  configuration on a single screen, consistent with how 2FA
  and Captcha settings are organised.
- **Should the option changes apply to currently-locked
  accounts?** Default: **no** — a user locked under the old
  duration keeps that duration until the existing
  `transaction_pin_locked_until` timestamp passes.
  Retroactively changing the unlock time of in-flight locks
  would require a sweep query on save and add complexity
  for marginal benefit; the affected user can self-heal via
  Forgot PIN regardless.
