---
inclusion: manual
---

# Messaging voice-notes — operator notes

This file documents the operator-facing surface for messaging voice
notes (DB 1.0.25, plugin 2.x). It's not a user manual — it's the
written contract for the knobs an operator has, the invariants that
must hold across those knobs, and the small number of footguns that
are easier to spot up front than to learn from a support ticket.

## Settings card

`Messaging → Settings → Voice Notes` exposes three knobs. They
match the model-layer resolvers one-to-one; the form clamps to the
documented hard ceilings on save so the persisted value matches
what the runtime will serve.

| Setting | Option key | Default | Hard ceiling | Where it gates |
|---|---|---|---|---|
| Allow voice notes | `allow_voice_notes` | on | n/a | `is_voice_enabled()` |
| Max duration (seconds) | `voice_max_duration_seconds` | `120` | `300` | `get_voice_max_duration_seconds()` |
| Max file size (MB) | `voice_max_bytes` (stored as bytes) | `2 MB` | image cap | `get_voice_max_bytes()` |

### Allow voice notes (master toggle)

Off → composer hides the mic button, model layer rejects voice
attachments with HTTP 403, but **existing voice rows on the floor
remain playable**. The toggle is "no new voice notes", not
"moderate-out the existing ones". To retire an individual recording,
use the moderation queue's per-message Delete action; the file is
hard-deleted 30 days later by `cron_cleanup`.

### Max duration

The recorder reads this via `wp_localize_script` and **auto-stops
on hit**. Tightening this value takes effect on the next page load
without a JS bundle cache-bust because the value lives in
`window.MatrixMessaging.voiceMaxDurationSec`, not in any compiled
asset.

### Max file size

The form posts MB; the model rounds to bytes. The save handler
**clamps to the image attachment ceiling** so a future operator
who lowers the image cap to, say, 1 MB cannot accidentally leave
voice on a higher ceiling — voice should never be the heaviest
surface on the platform. The MB input's `max` attribute mirrors
this clamp so the upper bound shifts in lockstep when an operator
lowers the image cap.

## Storage layout

Voice files live under
`wp-content/uploads/matrix-messaging-voice/<year>/<month>/`,
NOT under the standard year/month uploads root. The subtree is
private:

- `.htaccess` deny on Apache
- `web.config` deny on IIS
- `nginx-deny.conf.example` dropped on first creation; operators
  on Nginx must wire it into their vhost manually
- The dashboard never links the raw `/uploads/...` URL —
  `Matrix_MLM_Attachment_Signer` is the only path to a voice
  file's bytes

If an operator needs to relocate the subtree (e.g. mount it on
S3 via a media-offload plugin), the standard WP filter chain
(`wp_handle_upload_prefilter`, `upload_dir`) applies; the model
layer is path-agnostic as long as `Matrix_MLM_Attachment_Signer`'s
`ALLOWED_SUBTREES` accepts the resulting relative path.

## Retention and cleanup

Voice files follow the messaging soft-delete-to-hard-delete
window (default 30 days):

1. A recipient or moderator soft-deletes a message →
   `matrix_messages.deleted_at` populated.
2. The recipient surface shows `(message deleted)`. The moderator
   surface keeps the inline player working — the file is still on
   disk for the post-delete window so the moderator can listen
   when evaluating a complaint.
3. `cron_cleanup()` runs daily. For every soft-deleted message
   older than `matrix_messaging_voice_cleanup_age_days` (default
   `30`), it walks the message's voice attachments and calls
   `wp_delete_attachment($id, true)` — removes the WP attachment
   post AND the on-disk file in one step.
4. The `matrix_message_attachments` rows for those messages are
   then dropped.
5. Finally `matrix_messages` rows older than the cutoff are
   hard-deleted (existing behaviour).

### Operator-tunable: `matrix_messaging_voice_cleanup_age_days`

```php
add_filter('matrix_messaging_voice_cleanup_age_days', function () {
    return 14;  // shorten retention to 14 days
});
```

Floors at 1 day on apply (a value `<= 0` would race the
`matrix_messages` `DELETE` and produce surprising same-day-loss
behaviour). The cron tick is capped at 1000 messages per pass so
a one-off mass delete doesn't exceed the WP scheduler's single-run
budget; subsequent ticks pick up the next batch.

### Image attachments are NOT touched

`cron_cleanup` deliberately leaves image attachments alone. They
live in the standard WP media library and may be linked from
elsewhere on the site (a draft post, a user profile); the existing
operator workflow is to clean them via the media library proper.
Voice files exist only to back a message and have no other
lifecycle, which is why their disk-cleanup is paired with the
message hard-delete.

## Observability

`cron_cleanup` emits a one-line `error_log` entry when at least
one voice file was processed, in the form:

```
[Matrix Messaging] Voice GC: deleted 5 files (3147892 bytes), skipped 0 orphan rows, age cutoff 30 days
```

This is the simplest observability surface that doesn't require
operators to grep across multiple cron runs to know whether the GC
is running. Skipped orphan rows count cases where
`wp_delete_attachment` returned false (post or file already gone
via the media library) — those rows are dropped from
`matrix_message_attachments` regardless so an orphan referencing
a non-existent post is never left behind.

## Capability model

| Surface | Cap |
|---|---|
| Composer mic button | per-user thread participant + `cfg.voiceEnabled` |
| Voice file via signed URL | thread participant OR `manage_matrix_mlm` |
| Settings card (this page) | `manage_matrix_messaging` OR `manage_options` |
| Moderation player | `manage_matrix_messaging` OR `manage_options` |

The signer's auth gate (`Matrix_MLM_Attachment_Signer::handle_request`)
runs **after** path integrity (HMAC, expiry, realpath, allowlist) so
a leaked URL with a tampered path or expired sig 4xx's before the
auth check ever runs.

## Migration / disable path

The settings option keys (`allow_voice_notes`,
`voice_max_duration_seconds`, `voice_max_bytes`) live in the same
`matrix_messaging_settings` array option as every other messaging
setting, so deactivating the messaging module via the existing
`enabled` toggle disables voice along with everything else; no
separate kill-switch is needed.

To purge every voice file from disk independently of message
deletion, an operator can run:

```php
// One-shot cleanup; floors retention to 1 day so every soft-
// deleted voice file with a deleted_at populated is eligible.
add_filter('matrix_messaging_voice_cleanup_age_days', '__return_zero');
do_action('matrix_mlm_daily_cron');
remove_all_filters('matrix_messaging_voice_cleanup_age_days');
```

This will not touch live (non-soft-deleted) voice files — those
require a moderator action first to set `deleted_at`. The
`matrix_messaging_voice_cleanup_age_days` filter is the only knob
that affects scheduling; there's no separate "purge everything"
helper, by design.
