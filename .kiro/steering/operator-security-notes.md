---
inclusion: manual
---

# Operator security notes

This file documents security expectations and protocol-level
constraints that operators of MatrixPro need to understand when
running the plugin in production. None of these are vulnerabilities
in this codebase — they're documented edges of the security model
that are easy to forget without a written contract.

## Webhook secrets

### Paystack — HMAC over body

Paystack signs every webhook with HMAC-SHA512 over the raw request
body, keyed by `webhook_secret`. A leaked secret only enables
forgery while it remains valid; rotation invalidates all prior
signatures. Replays require capturing a real (body, signature)
pair.

Operator handling: rotate annually or on any suspected exposure.

### Flutterwave — static `verif-hash` (audit L9)

Flutterwave's `verif-hash` header is a STATIC shared secret that
the platform echoes back verbatim on every webhook call. It is
NOT an HMAC over the request body. This means:

- Any leak of `webhook_hash` (a backup file, a log line, the
  `wp_options` table dumped during a DB migration, an operator
  pasting the value into a chat) lets an attacker forge arbitrary
  deposit-completion events without ever touching Flutterwave.
- There is no replay protection at the protocol layer; the same
  `{hash, body}` pair is valid forever.

This is a Flutterwave protocol shape, not a defect in this
plugin's verification logic (which uses `hash_equals()` for
constant-time compare and is otherwise as strong as the protocol
allows). The remaining defence is the server-side amount and
currency cross-check in `complete_deposit()` — even a forged
webhook can only credit amounts that match a `matrix_deposits`
row this plugin already wrote at initiation time.

**Operator handling:**

1. Treat `webhook_hash` as a high-sensitivity secret on par with
   the live API secret key.
2. Rotate quarterly via the Flutterwave dashboard and update
   `matrix_mlm_flutterwave_settings.webhook_hash` in the same
   change window.
3. Never log `webhook_hash`. Existing audit-log emitters in
   `gateways/class-matrix-flutterwave.php` already redact it;
   future additions must do the same.
4. If a leak is suspected, rotate immediately AND audit
   `matrix_deposits` for any `status='completed'` rows in the
   suspected window whose entry does not match a real Flutterwave
   dashboard transaction.
5. Prefer Paystack or Fintava for new merchant integrations when
   both options exist — both use HMAC-of-body which does not
   have this protocol shape.

### Fintava — HMAC over body

Same shape as Paystack: HMAC over the raw body, keyed by
`webhook_secret`. Rotate annually.

### Zebra / Bibimoney — URL-token compensation

Bibimoney's IPN does not send a signature header at all (per
their published spec). The plugin compensates by including an
operator-rotatable token in the IPN URL and validating it
constant-time inside `handle_payment_callback`. The deposit row
must exist (matched by VendorReference) AND the PSPreference,
amount, and currency must match what the plugin stored at
initiation. An attacker who knows the URL token still cannot
credit a wallet they did not already initiate a deposit on.

**Operator handling:** rotate the URL token whenever a sysadmin
with vhost-config access leaves the team.

## Encryption-at-rest secrets

The plugin encrypts TOTP shared secrets and similar at-rest
secrets via libsodium (XSalsa20-Poly1305) with an AES-256-GCM
fallback. Key derivation defaults to `wp_salt('auth')` with
per-context domain separation. Operators migrating between
environments should define `MATRIX_MLM_ENCRYPTION_KEY` in
`wp-config.php` so the new install can decrypt secrets exported
from the old.

## File-staging directories

Both backups and Laravel imports default to private directories
under `wp-content/` that are siblings of `uploads/` (NOT inside
it), with `.htaccess` + `web.config` + an `nginx-deny.conf.example`
snippet dropped on first creation:

- `wp-content/matrix-mlm-backups-private/`
- `wp-content/matrix-mlm-imports-private/`

For production, define one or both of these `wp-config.php`
constants to put the directories outside the webroot entirely:

```php
define('MATRIX_MLM_BACKUP_DIR', '/var/lib/matrix-mlm-backups');
define('MATRIX_MLM_IMPORT_DIR', '/var/lib/matrix-mlm-imports');
```

## Admin balance adjustments

The `add_user_balance` / `subtract_user_balance` AJAX handlers
have a per-call cap (`matrix_mlm_admin_balance_adjust_max`,
default 1,000,000) and a per-actor daily aggregate cap
(`matrix_mlm_admin_balance_adjust_daily_max`, default 5,000,000).
Set the daily cap to 0 to disable it; the per-call cap still
applies. Both can be tuned via WP-CLI:

```bash
wp option update matrix_mlm_admin_balance_adjust_max 500000
wp option update matrix_mlm_admin_balance_adjust_daily_max 2000000
```
