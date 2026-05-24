# Fintava Billing — Follow-up Requirements (C, D, E)

Status: **Draft, awaiting review**
Owner: onyema3
Scope: `gateways/class-matrix-fintava-billing.php`, `includes/admin/class-matrix-admin-gateways.php`, `wp_matrix_billing_transactions`

This document captures the three follow-ups identified after the
per-category visibility toggles landed (PR #258). Each is intended
to ship as its own pull request, in the order given here.

---

## Item C — Service fee / markup per category

### Problem
Today, the user is debited exactly the bill amount they typed
(`debit_for_purchase($user_id, $amount, ...)` in
`Matrix_MLM_Fintava_Billing`) and Fintava's API charges the
merchant exactly that amount. There is no margin built into the
flow, so the platform earns nothing on airtime, data, cable, or
electricity sales.

### Goal
Let the operator configure a per-category markup so that each
billing transaction generates a service fee that lands in the
platform's books, and so the user's wallet is debited for the
fee on top of the nominal amount sent to Fintava.

### Scope
- **In:** Configurable markup per category (airtime, data, cable,
  electricity). Markup is applied at purchase time, debited from
  the user, and recorded on the transaction row. Visible to the
  user as a line item before they confirm the purchase.
- **Out:** Per-network markup (e.g. different rates for MTN vs.
  Glo). Per-plan markup (e.g. specific data bundles). These can
  be added later without breaking the schema chosen here.

### User story
> As a platform operator, I want to charge a 2% + ₦20 markup on
> every airtime purchase, so that the platform earns revenue on
> bill-pay activity.

### Acceptance criteria
1. Admin Gateways → Fintava settings exposes a per-category
   markup configuration: a flat amount (₦) and a percentage (%)
   for each of the four categories. Defaults to 0/0 (no markup,
   matches today's behavior).
2. The user-facing purchase confirmation surfaces three values:
   nominal amount, service fee, and total to be debited.
3. The wallet is debited for `nominal + fee` in a single debit
   call. Fintava is called for `nominal` only.
4. The transaction row records `nominal_amount`, `service_fee`,
   and `total_charged` as separate columns so reporting can
   sum fee revenue without parsing JSON.
5. On Fintava failure, the full `total_charged` is refunded to
   the user (fee + nominal — the user is made whole).
6. Existing categories with markup = 0/0 behave exactly as today
   (no fee, no line item shown — confirmation collapses to
   single-amount display).

### Open question (default chosen)
- **Markup type — flat OR percent OR both?** Default: **both**,
  applied additively (`fee = flat + nominal * percent / 100`).
  Operators who want flat-only set percent = 0 and vice versa.
  Rationale: zero extra UI complexity, covers all real-world
  fee structures the team has discussed.
- **Charged on top vs. deducted from nominal?** Default:
  **charged on top**. Rationale: matches user expectation
  ("I asked for ₦1000 of airtime, I got ₦1000 of airtime, plus
  a service fee was disclosed"). Deducting would surprise users
  and complicate the Fintava call (we'd send a smaller amount
  than the user typed).
- **Visible to end user?** Default: **yes, as a line item** in
  the confirmation step. Rationale: hidden fees attract
  complaints and chargebacks. Disclosure is the safer default;
  an admin who wants opacity can set markup to flat 0 / percent 0.

---

## Item D — Admin history view + manual refund button

### Problem
There is no admin-facing view of billing transactions today.
`Matrix_MLM_Fintava_Billing::get_user_history()` exists for the
user dashboard, but admins have no way to inspect the full
ledger across users, search by reference/user/type, or remediate
a failed transaction.

The "refund" path that exists today (`refund_failed_purchase`)
only fires automatically when the Fintava API call returns
WP_Error. It does not handle: (a) Fintava returns success but the
upstream telco never delivers the airtime, (b) Fintava returns
success but the user disputes the purchase, (c) the user accidentally
bought the wrong plan and contacts support.

### Goal
Give admins a paginated, filterable list of billing transactions
and a per-row Refund action that credits the user's Matrix wallet
(with a required reason and a full audit trail).

### Scope
- **In:** New admin page under Gateways tabs ("Bill Payments
  History"). Filters: user (search by ID/email), type, status,
  date range. Per-row "Refund" button triggers a modal with
  reason (required, free text) and amount (defaults to
  `total_charged`, editable down — partial refunds allowed).
  Submitting credits the user's Matrix wallet with a
  refund-typed transaction and updates the billing row's
  status to `refunded` (full) or `partial_refund` (partial).
- **Out:** Calling Fintava's API to reverse the upstream
  telco transaction. Fintava does not expose a refund endpoint
  for the four billing categories used here, and these
  categories are operationally irreversible at the telco
  layer (you cannot un-credit an airtime top-up). The refund
  is purely a Matrix wallet credit — the platform absorbs
  the loss or recovers off-platform.

### User story
> As an admin, I want to refund a user's failed cable purchase
> after the user reports DSTV never activated their plan, so
> I can resolve the support ticket without writing SQL by hand.

### Acceptance criteria
1. New admin page lists `wp_matrix_billing_transactions` with
   pagination (25/page), sort by `created_at DESC` default.
2. Filters: user (ID or email), category (airtime/data/cable/
   electricity/all), status (completed/failed/refunded/
   partial_refund/all), date range.
3. Each row shows: id, user (linked to user profile), type,
   nominal amount, service fee, total charged, status,
   created_at, expandable JSON details.
4. Refund button is visible only for status `completed` or
   `partial_refund`. Disabled for `failed` (already refunded
   automatically) and `refunded` (fully refunded).
5. Refund modal:
   - Reason: required, max 500 chars, sanitized.
   - Amount: pre-filled with remaining unrefunded balance,
     editable down to ₦1.00, cannot exceed remaining balance.
   - Confirms via second click ("Are you sure?").
6. Submitting the refund:
   - Credits user's Matrix wallet with a refund-typed
     transaction (`bill_admin_refund` source, reference
     `ADMIN-REFUND-{transaction_id}-{uuid}`).
   - Inserts a row in a new `wp_matrix_billing_refunds`
     audit table with admin_user_id, transaction_id, amount,
     reason, wallet_credit_reference, created_at.
   - Updates the billing transaction status: full refund →
     `refunded`, partial → `partial_refund` and tracks
     `refunded_amount` cumulatively.
   - Sends `bill_admin_refund` notification to the affected
     user (existing notification framework).
7. Refund action is gated to capability `manage_options`.
8. Refunds cannot exceed `total_charged - already_refunded`
   (server-side enforced, even if the modal is bypassed).

### Open question (default chosen)
- **Capability for refund button?** Default: `manage_options`
  (super-admin only). Tighter than the Gateways page itself
  because issuing a refund moves money. If the team wants a
  separate "billing operator" role, that's a follow-up; the
  capability check sits in one place so swapping it later is
  one-line.
- **Notify the user on refund?** Default: **yes**, via
  `Matrix_MLM_Notifications::send_user_notification` so the
  user sees it in-app and via email if email is configured.
- **Audit table vs. extending transactions row?** Default:
  **separate `wp_matrix_billing_refunds` table.** Rationale:
  partial refunds need multiple rows per transaction; a
  single row cannot record a refund history. The transaction
  row keeps a `refunded_amount` denormalised column for the
  list-view query (avoids a JOIN on every page load).

---

## Item E — Idempotency (client_reference round-trip to Fintava)

### Problem
`Matrix_MLM_Fintava_Billing::make_request()` currently sends a
plain JSON body with no idempotency token. If the wallet debit
succeeds but the HTTP POST to Fintava times out (network blip,
WordPress timeout, server crash mid-call), the user-facing
flow has no way to know whether Fintava processed the airtime
purchase. Today the user is refunded on WP_Error and the bill
is dropped — but Fintava may have actually delivered the
airtime, in which case the platform has eaten both the cost
AND the refund.

A `client_reference` (or whatever Fintava names it — see
verification step below) lets the platform retry safely:
Fintava recognises the duplicate and returns the original
result rather than placing a second order.

### Goal
Generate a stable per-request `client_reference`, persist it
**before** the Fintava call, send it on the API call, and use
it to drive a reconciliation path for ambiguous (timeout)
outcomes.

### Scope
- **In:** Schema column `client_reference` on
  `wp_matrix_billing_transactions` (unique). Generation,
  persistence, send-on-request, and read-back-on-response
  for the four bill-purchase endpoints. Verification of the
  exact field name Fintava expects (TBD — see open question).
- **Out:** Reconciliation worker that polls Fintava for
  ambiguous transactions. The schema and pre-call persist
  enable it; the worker itself is a follow-up after E lands.
  Out: extending idempotency to the bank payout / virtual
  wallet flows in `class-matrix-fintava.php` (those flows
  have their own reference column, `wp_matrix_fintava_payouts.reference`,
  used for similar purposes — they need a separate audit
  before we touch them).

### User story
> As a platform operator, I want a Fintava purchase that
> times out mid-call to be safely retryable, so that we
> never double-charge a user or double-pay a telco for one
> intent-to-buy.

### Acceptance criteria
1. New schema column `client_reference VARCHAR(64) UNIQUE`
   on `wp_matrix_billing_transactions`. Self-healing
   migration in `Matrix_MLM_Fintava_Billing::create_table()`
   adds it on installs that already have the table.
2. `client_reference` is generated as `MTRX-BILL-{type}-
   {user_id}-{uuid_short}` (10-char base32 from a 64-bit
   `random_bytes` source — collision-resistant, not derived
   from anything an attacker can replay).
3. The reference is INSERTed into `wp_matrix_billing_transactions`
   (status `pending`) **before** `make_request()` is called.
   Wallet debit reference (`debit_for_purchase()` BILL-…)
   continues to be the wallet-side identifier; the new
   `client_reference` is the Fintava-side identifier.
4. The reference is sent on the outbound JSON body to
   Fintava under the field name Fintava documents (verify
   against Fintava docs as the first step of implementation;
   conservative fallback: send under both `client_reference`
   and `clientReference` if the docs are ambiguous).
5. On Fintava success: row status flips to `completed`,
   `api_response` records the Fintava-side reference if
   returned.
6. On Fintava WP_Error: row status flips to `failed`,
   wallet refund proceeds as today. The unique
   `client_reference` is preserved so a manual reconciliation
   can later check Fintava by it.
7. On Fintava timeout (specifically `wp_remote_request`
   returning WP_Error with code `http_request_failed`):
   row status stays `pending`. The wallet stays debited.
   A future reconciliation job (out of scope here) will
   query Fintava by `client_reference` and resolve.
8. Inserting a duplicate `client_reference` into the
   transactions table fails fast (the UNIQUE constraint
   makes this structurally impossible — the test is that
   wp_db->insert returns false on a synthesised duplicate).

### Open question (default chosen)
- **Where does the reference live?** Default: **column on
  `wp_matrix_billing_transactions`**. Rationale: idempotency
  is per-transaction, so a 1:1 relationship; a separate
  `wp_matrix_fintava_idempotency` table would just be a
  JOIN on every read for no upside. The unique index is
  the dedup gate.
- **Retention?** Default: **same as transactions**. We
  never expire transaction rows; the reference rides
  along forever. If/when transaction archival lands, the
  reference migrates with it.
- **Format?** Default: `MTRX-BILL-{type}-{user_id}-{uuid_short}`.
  Includes type and user_id as a debugging aid (you can eyeball
  a Fintava log line and immediately know which user / which
  category). UUID-short keeps total length ≤ 64 chars even
  for long type names.
- **Fintava field name?** Default: **verify against Fintava
  docs first**. We do not pick a name in this spec because
  Fintava is the authority; if the docs are silent or
  ambiguous, we send under both `clientReference` and
  `client_reference` and let Fintava pick.

---

## PR plan

These ship as **three separate PRs** in this order. Each is
independently mergeable and reviewable.

| PR | Branch                                          | Item |
|----|-------------------------------------------------|------|
| 1  | `feat/fintava-billing-service-fee`              | C    |
| 2  | `feat/fintava-billing-admin-history-refunds`    | D    |
| 3  | `feat/fintava-billing-idempotency-reference`    | E    |

Order rationale: C lands first because the schema
extension it introduces (`nominal_amount`, `service_fee`,
`total_charged`, `refunded_amount`) is a prerequisite for
D's history view (which displays those columns) and for D's
refund partial-amount logic (which needs `total_charged` as
the cap). E is last because its reference column is
orthogonal to C/D and adding it earlier would mean either
two schema migrations or coupling commits that should be
reviewed independently.
