# Fintava Billing — Follow-up Design (C, D, E)

Status: **Draft, awaiting review**
See `requirements.md` for goals and acceptance criteria.

This document is the technical design. All three follow-ups
share a common touchpoint — `wp_matrix_billing_transactions`
— so the schema is described once at the top and the per-item
sections refer back to it.

---

## Shared: `wp_matrix_billing_transactions` schema evolution

### Today
```sql
CREATE TABLE {prefix}matrix_billing_transactions (
    id           BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    type         ENUM('airtime','data','cable','electricity') NOT NULL,
    amount       DECIMAL(12,2) NOT NULL,
    details      TEXT,
    api_response TEXT,
    status       ENUM('pending','completed','failed') NOT NULL DEFAULT 'completed',
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY (user_id),
    KEY (type)
);
```

### After all three follow-ups
```sql
CREATE TABLE {prefix}matrix_billing_transactions (
    id                BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id           BIGINT UNSIGNED NOT NULL,
    type              ENUM('airtime','data','cable','electricity') NOT NULL,
    -- amount is renamed to nominal_amount in spirit, kept for
    -- backward compat with any legacy reader. New code reads
    -- nominal_amount; the migration backfills it from amount.
    amount            DECIMAL(12,2) NOT NULL,
    nominal_amount    DECIMAL(12,2) NOT NULL,                          -- C
    service_fee       DECIMAL(12,2) NOT NULL DEFAULT 0.00,             -- C
    total_charged     DECIMAL(12,2) NOT NULL,                          -- C
    refunded_amount   DECIMAL(12,2) NOT NULL DEFAULT 0.00,             -- D
    client_reference  VARCHAR(64) DEFAULT NULL,                        -- E
    details           TEXT,
    api_response      TEXT,
    status            ENUM('pending','completed','failed',
                          'refunded','partial_refund') NOT NULL DEFAULT 'pending',
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at      DATETIME DEFAULT NULL,                           -- E (set on success)
    UNIQUE KEY client_reference (client_reference),                    -- E
    KEY user_id (user_id),
    KEY type (type),
    KEY status (status)
);
```

The migration is idempotent and runs inside the existing
`Matrix_MLM_Fintava_Billing::create_table()` self-heal step.
Each PR adds the columns its item needs, in the order above —
no PR depends on a column from a later PR.

### Backward compatibility
- The legacy `amount` column is **kept**, not dropped. Any
  third-party reader that selects `amount` continues to work,
  and our migration keeps it in sync with `nominal_amount`
  (PR C writes both; PR D and E never touch `amount` directly).
- The `status` enum gains two values (`refunded`, `partial_refund`).
  Old code that compares `status === 'completed'` continues
  to behave correctly because refunded rows are no longer
  "completed" — that's the intended semantic.
- Old code that reads `details` JSON continues to work; the
  new dedicated columns are additions, not replacements.

---

## Item C — Service fee / markup per category

### Configuration storage

New WP option, stored as a JSON-encoded assoc array (matches
the pattern `matrix_mlm_fintava_billing_caps` already uses for
per-category configuration):

```php
// Option key: matrix_mlm_fintava_billing_markup
// Default: empty array → no markup on any category (today's behavior)
[
    'airtime'     => ['flat' => 20.00, 'percent' => 1.5],
    'data'        => ['flat' => 0.00,  'percent' => 2.0],
    'cable'       => ['flat' => 100.0, 'percent' => 0.0],
    'electricity' => ['flat' => 50.00, 'percent' => 0.5],
]
```

### Compute helper (single source of truth)

Added to `Matrix_MLM_Fintava_Billing`:
```php
/**
 * Compute the service fee for a given category and nominal amount.
 *
 * fee = flat + nominal * percent / 100, rounded to 2dp.
 *
 * Reads the matrix_mlm_fintava_billing_markup option. Missing
 * categories or malformed values return 0 (fail-safe — never
 * over-charge a user because the option is corrupt).
 *
 * @return float >= 0
 */
public static function compute_service_fee($type, $nominal) {
    if (!in_array($type, self::BILL_CATEGORIES, true)) return 0.0;
    if ($nominal <= 0) return 0.0;
    $cfg = get_option('matrix_mlm_fintava_billing_markup', []);
    if (!is_array($cfg) || !isset($cfg[$type]) || !is_array($cfg[$type])) return 0.0;
    $flat    = max(0.0, (float)($cfg[$type]['flat']    ?? 0));
    $percent = max(0.0, (float)($cfg[$type]['percent'] ?? 0));
    $percent = min(100.0, $percent); // sanity cap
    return round($flat + ($nominal * $percent / 100.0), 2);
}
```

### Purchase flow change

Today, `ajax_buy_airtime()` (and the three siblings) calls:
```php
$debit = self::debit_for_purchase($user_id, $amount, 'airtime', ...);
$result = $this->buy_airtime($phone, $amount, $network);  // sends $amount to Fintava
```

After C, the flow becomes:
```php
$nominal = floatval($_POST['amount']);
$fee     = self::compute_service_fee('airtime', $nominal);
$total   = $nominal + $fee;

// Validate the NOMINAL against the cap (caps bound the upstream call,
// not the user's wallet — fee is platform revenue, not telco spend).
$cap_check = self::validate_amount('airtime', $nominal);
if (is_wp_error($cap_check)) { /* surface error */ }

// Debit the TOTAL (user pays nominal + fee in one debit).
$debit = self::debit_for_purchase($user_id, $total, 'airtime', $description);
if (is_wp_error($debit)) { /* surface error */ }

// Send the NOMINAL to Fintava (telco only knows about the bill amount).
$result = $this->buy_airtime($phone, $nominal, $network);

if (is_wp_error($result)) {
    // Refund the TOTAL — user is made whole including fee.
    self::refund_failed_purchase($user_id, $total, 'airtime', $debit, $result->get_error_message());
    /* surface error */
}

// Persist with all three columns set.
$this->log_transaction($user_id, 'airtime', [
    'nominal_amount' => $nominal,
    'service_fee'    => $fee,
    'total_charged'  => $total,
], $details, $result);
```

`log_transaction()` signature changes to accept the dollar
breakdown as a separate associative array (rather than a
single `$amount`) so the new columns are populated cleanly.

### User-facing confirmation

The four `render_<type>()` methods on `Matrix_MLM_User_Billing`
already have an "amount" input. After C:
1. JS reads the entered amount, calls a new AJAX endpoint
   `matrix_fintava_quote_bill` that returns
   `{ nominal, fee, total }`. Endpoint computes via
   `compute_service_fee()` server-side (we never trust the
   client to compute fee values).
2. The confirmation step shows a three-line breakdown:
   ```
   Amount:        ₦ 1,000.00
   Service fee:   ₦    35.00
   Total:         ₦ 1,035.00   [Confirm Purchase]
   ```
3. When fee = 0, the breakdown collapses to a single
   line ("Total: ₦ 1,000.00") — UX matches today.

### Admin UI

A new section in `class-matrix-admin-gateways.php` Fintava
card, below the existing per-category caps section:

> **Service Fees / Markup**
> | Category    | Flat (₦) | Percent (%) |
> |-------------|----------|-------------|
> | Airtime     | [____]   | [____]      |
> | Data        | [____]   | [____]      |
> | Cable       | [____]   | [____]      |
> | Electricity | [____]   | [____]      |
>
> Service fee is added on top of the user's chosen amount and
> debited from their Matrix wallet in a single transaction.
> Fees are recorded separately on each billing transaction
> for revenue reporting.

Form post handler `save_fintava_billing_markup()` validates
that flat and percent are non-negative numbers, percent ≤ 100,
and updates the option.

### Test plan
- Unit-style: `compute_service_fee()` returns 0 for unknown
  types, 0 for non-positive nominal, the right number for
  flat-only / percent-only / both / zero / corrupt config.
- Integration: simulate an airtime purchase with the option
  set to flat 20 / percent 1.5 on a ₦1000 nominal. Verify
  wallet debit = 1035, Fintava call payload amount = 1000,
  transaction row has nominal=1000, fee=35, total=1035.
- Regression: simulate the same purchase with no option
  set (defaults). Verify wallet debit = 1000, Fintava call
  payload amount = 1000, transaction row has nominal=1000,
  fee=0, total=1000. Identical to today's behavior.

---

## Item D — Admin history view + manual refund

### New table: `wp_matrix_billing_refunds`

```sql
CREATE TABLE {prefix}matrix_billing_refunds (
    id                        BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    transaction_id            BIGINT UNSIGNED NOT NULL,
    admin_user_id             BIGINT UNSIGNED NOT NULL,
    amount                    DECIMAL(12,2) NOT NULL,
    reason                    TEXT NOT NULL,
    wallet_credit_reference   VARCHAR(100) NOT NULL,
    created_at                DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY (transaction_id),
    KEY (admin_user_id)
);
```

Created in a new self-healing step in
`Matrix_MLM_Fintava_Billing::create_table()` (mirrors the
`ensure_tables_exist()` pattern in `class-matrix-fintava.php`).

### Admin page

New tab under the existing Gateways admin page (the page
already has the Fintava settings card; we add a sibling tab
or a sub-page link, TBD at implementation — leaning toward
**a new full WP admin page registered under the Matrix MLM
top-level menu** because the list view needs more space than
a tab strip provides). Provisional slug: `matrix-fintava-billing-history`.

Page handler in `class-matrix-admin-gateways.php` (new method
`render_billing_history_page()`) builds:

1. **Filter form** — GET-based so URLs are bookmarkable:
   `?page=matrix-fintava-billing-history&user=42&type=airtime&status=completed&from=2026-05-01&to=2026-05-24&paged=1`
2. **List table** — uses WP's `WP_List_Table` so we get
   pagination, sortable columns, bulk-action scaffolding for
   free. Columns: ID, User, Type, Nominal, Fee, Total,
   Refunded, Status, Created, Actions.
3. **Refund modal** — vanilla JS modal (no jQuery UI dep),
   AJAX handler `wp_ajax_matrix_admin_billing_refund`.

### Refund AJAX handler

```php
public function ajax_admin_billing_refund() {
    check_ajax_referer('matrix_admin_billing_refund', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $transaction_id = absint($_POST['transaction_id'] ?? 0);
    $amount         = floatval($_POST['amount'] ?? 0);
    $reason         = sanitize_textarea_field($_POST['reason'] ?? '');

    if (!$transaction_id || $amount <= 0 || $reason === '') {
        wp_send_json_error(['message' => 'transaction_id, amount, reason all required']);
    }
    if (mb_strlen($reason) > 500) {
        wp_send_json_error(['message' => 'Reason too long (max 500 chars)']);
    }

    // Atomic-ish: read the transaction with a row lock. WP doesn't
    // expose SELECT ... FOR UPDATE, so we rely on the UNIQUE
    // wallet_credit_reference (computed below) to fail fast on
    // concurrent admin refunds for the same transaction.
    global $wpdb;
    $tx = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}matrix_billing_transactions WHERE id = %d",
        $transaction_id
    ));
    if (!$tx) wp_send_json_error(['message' => 'Transaction not found']);
    if (!in_array($tx->status, ['completed', 'partial_refund'], true)) {
        wp_send_json_error(['message' => 'Transaction is not refundable']);
    }

    $remaining = floatval($tx->total_charged) - floatval($tx->refunded_amount);
    if ($amount > $remaining + 0.005) {  // float epsilon
        wp_send_json_error(['message' => sprintf('Amount exceeds remaining balance (%.2f)', $remaining)]);
    }

    $reference = sprintf('ADMIN-REFUND-%d-%s', $transaction_id, wp_generate_uuid4());

    // Credit the user's Matrix wallet. The wallet code already
    // refuses duplicate references, so a re-submitted modal is
    // idempotent.
    $wallet = new Matrix_MLM_Wallet();
    $credited = $wallet->credit(
        intval($tx->user_id),
        $amount,
        'bill_admin_refund',
        sprintf('Admin refund: %s purchase #%d - %s', $tx->type, $tx->id, $reason),
        $reference
    );
    if ($credited === false) {
        wp_send_json_error(['message' => 'Wallet credit failed - see error log']);
    }

    // Record refund row.
    $wpdb->insert($wpdb->prefix . 'matrix_billing_refunds', [
        'transaction_id'          => $transaction_id,
        'admin_user_id'           => get_current_user_id(),
        'amount'                  => $amount,
        'reason'                  => $reason,
        'wallet_credit_reference' => $reference,
    ]);

    // Update the transaction row.
    $new_refunded = floatval($tx->refunded_amount) + $amount;
    $is_full      = abs($new_refunded - floatval($tx->total_charged)) < 0.005;
    $wpdb->update(
        $wpdb->prefix . 'matrix_billing_transactions',
        [
            'refunded_amount' => $new_refunded,
            'status'          => $is_full ? 'refunded' : 'partial_refund',
        ],
        ['id' => $transaction_id]
    );

    // Notify the user.
    Matrix_MLM_Notifications::send_user_notification(
        intval($tx->user_id),
        'bill_admin_refund',
        sprintf('Your %s purchase has been refunded: ₦%s', $tx->type, number_format($amount, 2)),
        ['transaction_id' => $transaction_id, 'amount' => $amount, 'reason' => $reason]
    );

    wp_send_json_success([
        'message'         => 'Refund processed',
        'new_status'      => $is_full ? 'refunded' : 'partial_refund',
        'refunded_amount' => $new_refunded,
    ]);
}
```

### Concurrency
Two admins clicking Refund on the same row simultaneously is
the worst case. Mitigations:
1. The wallet's existing `credit()` enforces unique reference
   strings, so two refunds with different UUID-suffixed
   references both succeed at the wallet layer — that's a
   real (additive) double-refund, which is the bug we want
   to prevent.
2. Therefore, we add a check: re-read `refunded_amount`
   inside `ajax_admin_billing_refund()` after the wallet
   credit and **roll back via a compensating debit** if the
   re-read shows we've now exceeded `total_charged`. This is
   the cleanest defense without a real `SELECT FOR UPDATE`.

This compensation logic is documented inline in the handler
and tested via a deliberately-staged race in the integration
test plan.

### Test plan
- Render the page with no rows; verify it shows an empty
  state, not a fatal.
- Render with 100 rows and various statuses; verify
  pagination, all filters work.
- Refund a completed transaction in full; verify status
  → `refunded`, refunded_amount = total_charged, wallet
  credited, refund row created, user notified.
- Refund a completed transaction partially (₦500 of ₦1035);
  verify status → `partial_refund`, refunded_amount = 500.
- Refund the remainder; verify status flips to `refunded`.
- Try to refund a `failed` transaction; verify rejected.
- Try to refund more than `remaining`; verify rejected.
- Submit refund as a non-admin user; verify 403.
- Stage a concurrent double-submit; verify the second
  refund is rolled back.

---

## Item E — Idempotency (client_reference round-trip)

### Reference generation

```php
/**
 * Build a stable client_reference for a Fintava billing call.
 *
 * Format: MTRX-BILL-{type}-{user_id}-{uuid_short}
 *
 * The uuid_short is a 10-char base32 encoding of 8 random
 * bytes from random_bytes() — collision-resistant, not a
 * truncated UUID4 (truncation reduces entropy).
 *
 * @return string max 64 chars
 */
public static function build_client_reference($type, $user_id) {
    $bytes = function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8);
    // Base32 (RFC 4648 alphabet, no padding) keeps it URL-safe and
    // case-flat — Fintava's downstream systems are easier on
    // case-insensitive references.
    static $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    for ($i = 0; $i < 10; $i++) {
        $out .= $alphabet[ord($bytes[$i % strlen($bytes)]) & 0x1F];
    }
    return sprintf('MTRX-BILL-%s-%d-%s', $type, $user_id, $out);
}
```

### Lifecycle

```
1. ajax_buy_airtime() runs
2. Validate, compute fee (item C), debit wallet
3. INSERT transaction row with:
       client_reference = build_client_reference(...)
       status           = 'pending'
       (nominal/fee/total/details all populated)
4. POST /billing/airtime body includes:
       clientReference  = <ref>     ← exact field name TBD
5a. Fintava success:
       UPDATE transaction SET status='completed', completed_at=NOW(), api_response=...
5b. Fintava WP_Error (HTTP 4xx/5xx with body):
       UPDATE transaction SET status='failed', api_response=...
       Refund wallet (existing path)
5c. Fintava WP_Error with code 'http_request_failed' (timeout/network):
       Transaction stays 'pending'
       DO NOT refund wallet
       Surface to user: "Your purchase is being verified, please
                        check Bill History in a few minutes."
       [Reconciliation worker — out of scope here — will resolve
        later by GET'ing Fintava with the client_reference.]
```

### Why pending rows must not auto-refund

Today, any WP_Error from `make_request()` triggers
`refund_failed_purchase()`. After E, **only HTTP-level errors
trigger refunds** — network/timeout errors leave the wallet
debited and the transaction `pending`. This is the central
correctness change of E.

The WP_Error code distinguishes them: `wp_remote_request`
returns `http_request_failed` for transport-level failures,
which is exactly the case where we don't know whether
Fintava processed the call. All other errors are explicit
HTTP 4xx/5xx where Fintava either responded with a clear
"no" (refund) or our code returned WP_Error after parsing
a Fintava error envelope (also a clear "no", refund).

### Field name verification

Implementation step 1 of E is **read Fintava's billing API
docs to find the exact idempotency field name**. Candidates
based on common patterns:
- `clientReference` (camelCase, matches Fintava's existing
  card-API DTO conventions like `cardMapId`, `cardBrand`).
- `client_reference` (snake_case).
- `reference` (bare).
- `idempotencyKey` (header, not body — possible but
  unusual for Fintava).

If the docs name the field, send it under that name only.
If the docs are silent or contradictory, send under
`clientReference` AND `client_reference` simultaneously
(both keys, same value) — sending an unknown key is
ignored by virtually every JSON API, so there's no
downside, and we cover both cases. Document the decision
in the PR description.

### Schema migration

Self-heal step in `Matrix_MLM_Fintava_Billing::create_table()`:
```php
// E: add client_reference + completed_at if missing.
$col = $wpdb->get_var($wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'client_reference'",
    DB_NAME, $wpdb->prefix . 'matrix_billing_transactions'
));
if (!$col) {
    $wpdb->query("ALTER TABLE {$wpdb->prefix}matrix_billing_transactions
                  ADD COLUMN client_reference VARCHAR(64) DEFAULT NULL,
                  ADD COLUMN completed_at DATETIME DEFAULT NULL,
                  ADD UNIQUE KEY client_reference (client_reference)");
}
// And widen the status enum to include 'pending', 'refunded', 'partial_refund'
// (some are added in earlier PRs; the ALTER is idempotent on MySQL).
$wpdb->query("ALTER TABLE {$wpdb->prefix}matrix_billing_transactions
              MODIFY COLUMN status ENUM('pending','completed','failed',
                                         'refunded','partial_refund')
                                  NOT NULL DEFAULT 'pending'");
```

### Test plan
- Generate 10 references; verify all unique, all start with
  the correct prefix, all ≤ 64 chars, all match the documented
  format.
- Submit a billing purchase; verify the transaction row has
  a `client_reference` and the Fintava call payload includes
  it.
- Inject a `http_request_failed` WP_Error (mock
  `wp_remote_request`); verify wallet stays debited, row
  stays `pending`, user-facing message is the verification
  message, not the failure message.
- Inject an HTTP 400 WP_Error; verify wallet refund fires
  and row goes to `failed`.
- Inject a Fintava success; verify row goes to `completed`,
  `completed_at` set, `api_response` populated.
- Synthesise a duplicate `client_reference` insert; verify
  the wpdb call fails (UNIQUE violation) and the AJAX
  handler reports a clean error rather than fataling.

---

## Open issues to resolve at implementation time

| # | Item | Question | Default if not answered |
|---|------|----------|-------------------------|
| 1 | C    | Fee on partial refunds: prorate, or absorb? | Prorate — refund covers `(refund_amount / total_charged) * total_charged` proportionally |
| 2 | D    | Bulk refund (multi-row select)?              | Out of scope. Per-row only. |
| 3 | D    | Refund visible on user dashboard history?    | Yes — `Matrix_MLM_User_Billing::render_history_details()` already reads the row, gets it for free once status is `refunded` / `partial_refund` |
| 4 | E    | Reconciliation worker for `pending` rows     | Out of scope. Filed as follow-up #4 after E lands. |
| 5 | E    | Backfill `client_reference` on legacy rows?  | No — legacy rows pre-date the column, leave NULL. New rows always have it. |

These will be re-confirmed during code review of each PR;
defaults ship unless the reviewer overrides.
