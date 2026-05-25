# Transaction PIN — Follow-up Design (A, B)

Status: **Draft, awaiting review**
See `requirements.md` for the user-facing rationale and
acceptance criteria. This document covers the technical detail.

---

## Item A — HTML email templates for PIN events

### Current state (post PR #269 + PR #270)

`Matrix_MLM_Transaction_Pin::notify()` is the single dispatch
point for all five lifecycle emails:

```php
private static function notify($user_id, $event, array $context = []) {
    $user = get_userdata((int) $user_id);
    if (!$user) { return; }
    $site = get_bloginfo('name');
    // … inline switch over $event builds $subject + $line …
    $body  = $line . "\n\n";
    $body .= sprintf(__('Time: %s', 'matrix-mlm'), $time) . "\n";
    if ($ip !== '') { $body .= sprintf(__('IP: %s', 'matrix-mlm'), $ip) . "\n"; }
    if ($ua !== '') { $body .= sprintf(__('Browser: %s', 'matrix-mlm'), $ua) . "\n"; }
    if (!empty($context['unlock_at'])) { … }
    $body .= "\n" . __('If this was not you, …', 'matrix-mlm');
    wp_mail($user->user_email, $subject, $body);
}
```

`Matrix_MLM_Notifications::send_email()` (already used by every
other transactional email on the platform) builds the HTML
wrapper from `public/templates/emails/base.php`, derives a
plain-text fallback via `wp_strip_all_tags()`, sets the right
`Content-Type` headers, and supports child-theme overrides.

### Target shape

1. Five new template files in `public/templates/emails/`:

   ```
   transaction-pin-set.php
   transaction-pin-change.php
   transaction-pin-disable.php
   transaction-pin-forgot.php
   transaction-pin-locked.php
   ```

   Each receives a `$vars` array. Common keys (all five):

   - `display_name` — used in the "Hi {name}" greeting line
   - `site_name`    — for the "from {site}" disclaimer in the body
   - `time`         — pre-formatted `current_time('mysql')`
   - `ip`           — empty string when `REMOTE_ADDR` is unavailable
   - `user_agent`   — truncated to 200 chars (see Acceptance
                       Criteria item 1's open-question)

   `transaction-pin-locked.php` additionally receives
   `unlock_at` (mysql-format string).

   Template body shape mirrors `subscription-deactivation.php`
   (already in the repo): a top-level `<p>` with the headline,
   a metadata table with time / IP / UA, a CTA paragraph
   linking to the Security tab (`home_url('/matrix-dashboard/
   security/')`), and a closing "If this wasn't you" line.

2. New helper on `Matrix_MLM_Notifications`:

   ```php
   public static function send_transaction_pin_email($user_id, $event, array $context = []) {
       $user = get_userdata((int) $user_id);
       if (!$user) { return false; }
       $template = 'transaction-pin-' . $event;     // matches file slug
       $subject  = self::pin_email_subject($event); // small switch, see below
       $vars = array_merge([
           'display_name' => $user->display_name,
           'site_name'    => get_bloginfo('name'),
           'time'         => current_time('mysql'),
           'ip'           => Matrix_MLM_Rate_Limiter::client_ip(),
           'user_agent'   => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
       ], $context);
       return self::send_email($user->user_email, $subject, $template, $vars);
   }

   private static function pin_email_subject($event) {
       $site = get_bloginfo('name');
       switch ($event) {
           case 'set':     return sprintf(__('[%s] Transaction PIN set on your account', 'matrix-mlm'), $site);
           case 'change':  return sprintf(__('[%s] Transaction PIN changed', 'matrix-mlm'), $site);
           case 'disable': return sprintf(__('[%s] Transaction PIN disabled', 'matrix-mlm'), $site);
           case 'forgot':  return sprintf(__('[%s] Transaction PIN reset via Forgot PIN', 'matrix-mlm'), $site);
           case 'locked':  return sprintf(__('[%s] Transaction PIN locked', 'matrix-mlm'), $site);
       }
       return sprintf(__('[%s] Transaction PIN update', 'matrix-mlm'), $site); // defensive default
   }
   ```

3. `Matrix_MLM_Transaction_Pin::notify()` becomes a one-liner:

   ```php
   private static function notify($user_id, $event, array $context = []) {
       Matrix_MLM_Notifications::send_transaction_pin_email((int) $user_id, $event, $context);
   }
   ```

   Kept as a method (rather than inlined at every call site) so
   the audit-log `error_log('[Matrix PIN] …')` calls in `set()`,
   `change()`, etc. remain colocated with the email dispatch
   pattern they already pair with.

### Why a separate helper instead of calling `send_email()` directly?

- Centralises the variable binding (display name, time, IP, UA)
  in one place. Without the helper, each of the five call sites
  in `Transaction_Pin` would either duplicate that binding or
  the templates themselves would have to call `get_userdata()`
  / `Matrix_MLM_Rate_Limiter::client_ip()` from inside the view
  layer, which breaks the templates-are-pure-views convention
  every other email template follows.
- Centralises the subject-line lookup. Inlining the switch into
  `notify()` would put localised strings in the class file
  rather than the i18n surface (`Matrix_MLM_Notifications`)
  the rest of the email subjects use.
- Lets the caller (`Transaction_Pin`) stay event-shaped:
  `notify($user_id, 'locked', ['unlock_at' => $until])` reads
  cleanly. Direct `send_email()` calls would each have to know
  the template slug, the subject, and the variable shape.

### Migration / rollout

- No DB schema change.
- No option / setting change — operators get the new templates
  on plugin update with no action required.
- Default copy in the templates exactly matches the current
  inline plain-text bodies (modulo HTML formatting), so the
  user-perceptible change is visual: same content, branded
  wrapper.
- Plain-text fallback is auto-derived by `send_email()` from
  the HTML via `wp_strip_all_tags()`; we don't ship a separate
  `.txt` per template, matching how every other email on the
  platform works.

### Test plan

- Manual: trigger each of the five events, verify the email
  arrives in the wrapper with the right subject and a
  populated metadata block.
- Manual: drop a `transaction-pin-set.php` override in a
  child theme's `matrix-mlm/emails/` directory, trigger Set,
  verify the override renders.
- Manual: check the email in Outlook web / Gmail / Apple Mail
  for layout breaks. Specifically confirm the lockout-time row
  in `transaction-pin-locked.php` doesn't get truncated by
  Outlook's CSS sandbox.
- Headless: a smoke unit test that calls
  `send_transaction_pin_email($uid, 'set')` and asserts
  the recorded `wp_mail()` payload's body contains the
  expected localised headline (mock the actual transport).

---

## Item B — Admin-tunable lockout thresholds

### Current state

```php
// Constants
const HARD_LOCKOUT_THRESHOLD = 10;
const HARD_LOCKOUT_HOURS     = 24;

// Read sites (only two)
private static function record_failure($user_id) {
    // …
    if ($count >= self::HARD_LOCKOUT_THRESHOLD) {
        $until = date('Y-m-d H:i:s', time() + (self::HARD_LOCKOUT_HOURS * HOUR_IN_SECONDS));
        // …
    }
}
```

### Target shape

Two new accessors on `Matrix_MLM_Transaction_Pin`:

```php
/**
 * Threshold for the persistent failed-attempts counter.
 *
 * Read from the matrix_mlm_transaction_pin_lockout_threshold
 * option, falling back to HARD_LOCKOUT_THRESHOLD (the original
 * default) when the option is unset. Defensive clamps to the
 * documented bounds (3-30) so a corrupted option value can't
 * brick the gate by setting the threshold to 0.
 */
public static function lockout_threshold() {
    $raw = (int) get_option(
        'matrix_mlm_transaction_pin_lockout_threshold',
        self::HARD_LOCKOUT_THRESHOLD
    );
    if ($raw < 3)  { return 3; }
    if ($raw > 30) { return 30; }
    return $raw;
}

/**
 * Lockout duration in hours. See lockout_threshold() for the
 * read / clamp pattern.
 */
public static function lockout_hours() {
    $raw = (int) get_option(
        'matrix_mlm_transaction_pin_lockout_hours',
        self::HARD_LOCKOUT_HOURS
    );
    if ($raw < 1)   { return 1; }
    if ($raw > 168) { return 168; }
    return $raw;
}
```

`record_failure()` swaps the constant reads for accessor calls:

```php
if ($count >= self::lockout_threshold()) {
    $until = date('Y-m-d H:i:s', time() + (self::lockout_hours() * HOUR_IN_SECONDS));
    // … rest unchanged
}
```

The constants stay defined — they're now both the default
fallback (when the option is unset) AND the documented canonical
values for someone reading the source.

### Admin UI

Two new rows on `Matrix_MLM_Admin_Settings::render_security_tab()`,
inside the existing Transaction PIN sub-section that already
hosts the master toggle and per-path checkboxes:

```html
<tr>
  <th>Wrong-PIN attempts before lockout</th>
  <td>
    <input type="number" min="3" max="30"
           name="matrix_mlm_transaction_pin_lockout_threshold"
           value="{{ get_option(...) }}">
    <p class="description">
      How many consecutive wrong PINs before the user's PIN
      is locked. Default: 10. Range: 3-30.
    </p>
  </td>
</tr>
<tr>
  <th>Lockout duration (hours)</th>
  <td>
    <input type="number" min="1" max="168"
           name="matrix_mlm_transaction_pin_lockout_hours"
           value="{{ get_option(...) }}">
    <p class="description">
      How long the lock stays in effect after it trips.
      Default: 24. Range: 1-168 (1 hour to 7 days). Users can
      always self-recover via Forgot PIN regardless of this
      duration.
    </p>
  </td>
</tr>
```

Save handler (`save_security_settings()` or whichever method the
Security tab uses today) adds the two keys to its whitelist with
the same min/max clamps the accessors apply, plus a
`add_settings_error()` if the submitted value is out of range so
the operator gets feedback.

### Why clamp twice (once at save, once at read)?

Defence-in-depth. The save-side clamp catches the typical
admin-form input error and surfaces feedback. The read-side
clamp catches the edge cases where the option got written by:

- A direct database edit by a support engineer triaging a
  customer issue.
- A `wp-cli option update` invocation that bypasses the
  Settings API.
- A legacy migration script that ran before the validation
  was in place.

Without the read-side clamp, a corrupted option value would
silently degrade or break the gate. With it, the worst case is
the operator's UI shows an out-of-range value alongside the
clamped behaviour — discoverable via the inline "Default: 10,
Range: 3-30" copy.

### Migration / rollout

- No DB schema change.
- The two options default to unset, so existing installs see
  identical behaviour to today (constants fall through).
- `notify()`'s "Locked until: $unlock_at" body line and
  `lockout_info()` already read `transaction_pin_locked_until`
  off the row written by `record_failure()` — that timestamp
  reflects whatever `lockout_hours()` returned at the moment
  of lockout, so the user always sees the right unlock time
  even if the operator tunes the option mid-lock.

### Test plan

- Headless: unit test on `lockout_threshold()` /
  `lockout_hours()` covering: option unset → constant default,
  option below min → clamped to min, option above max →
  clamped to max, option = exact bound → returned verbatim.
- Manual: tune threshold to 3 hours / lockout 1h on a staging
  install, fail a verify 3 times, confirm the row's
  `transaction_pin_locked_until` reflects the new value.
- Manual: change the option mid-lock, confirm the existing
  lock keeps its original unlock time (acceptance-criteria
  item 7 of requirements).
- Smoke: save out-of-range values via the admin form,
  confirm the clamp + error surface fire.
