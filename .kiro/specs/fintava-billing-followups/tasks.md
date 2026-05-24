# Fintava Billing — Follow-up Tasks (C, D, E)

Status: **Draft, awaiting review**
See `requirements.md` and `design.md` for the rationale and
technical detail behind each task.

Each numbered section below is **one pull request**. PRs land
in order. No PR depends on a column or function from a later PR.

---

## PR 1 — Item C: Service fee / markup per category
Branch: `feat/fintava-billing-service-fee`

- [ ] **1.1** Schema migration in
      `Matrix_MLM_Fintava_Billing::create_table()`:
      add `nominal_amount`, `service_fee`, `total_charged`
      columns. Backfill `nominal_amount = amount`,
      `total_charged = amount` on existing rows.
- [ ] **1.2** Add `Matrix_MLM_Fintava_Billing::compute_service_fee($type, $nominal)`
      with the algorithm in `design.md`. Defensive: return 0
      for unknown type, non-positive nominal, missing or
      malformed `matrix_mlm_fintava_billing_markup` option.
- [ ] **1.3** Update the four `ajax_buy_*` handlers to:
      compute fee, debit `nominal + fee`, send `nominal` to
      Fintava, refund `nominal + fee` on failure.
- [ ] **1.4** Update `log_transaction()` to accept the
      `{nominal, fee, total}` breakdown and INSERT it into
      the new columns.
- [ ] **1.5** Add `wp_ajax_matrix_fintava_quote_bill` AJAX
      handler that returns `{ nominal, fee, total }` for the
      confirmation step. Computes server-side via
      `compute_service_fee()`.
- [ ] **1.6** Update the four `render_<type>()` methods on
      `Matrix_MLM_User_Billing` to call the quote endpoint
      and show the breakdown before submit.
- [ ] **1.7** Add the per-category markup form in
      `class-matrix-admin-gateways.php` Fintava card.
      Validate non-negative numbers, percent ≤ 100.
      `save_fintava_billing_markup()` handler.
- [ ] **1.8** Manual smoke: airtime purchase with markup
      configured (verify wallet debit = nominal + fee,
      transaction row populated, user sees breakdown);
      airtime purchase with no markup (verify identical to
      today's behavior).

---

## PR 2 — Item D: Admin history view + manual refund
Branch: `feat/fintava-billing-admin-history-refunds`

- [ ] **2.1** Schema migration: add `refunded_amount` column
      to `wp_matrix_billing_transactions`. Widen `status` enum
      to include `refunded`, `partial_refund`. Backfill
      `refunded_amount = 0` on existing rows.
- [ ] **2.2** Schema migration: create
      `wp_matrix_billing_refunds` table per `design.md`.
      Add to `Matrix_MLM_Database::REQUIRED_TABLES`.
- [ ] **2.3** Register new admin page
      `matrix-fintava-billing-history` in the Matrix MLM
      menu. `render_billing_history_page()` method on
      `Matrix_MLM_Admin_Gateways`.
- [ ] **2.4** Build a `WP_List_Table` subclass with the
      columns + filters listed in `design.md`. Pagination
      25/page default.
- [ ] **2.5** Refund modal: vanilla JS, accessible (focus
      trap, escape closes). Pre-fills amount with remaining
      balance, requires reason ≤ 500 chars, double-click
      confirm.
- [ ] **2.6** AJAX handler `wp_ajax_matrix_admin_billing_refund`
      with the full body from `design.md`. Capability gate:
      `manage_options`. Concurrency compensation logic.
- [ ] **2.7** User notification: `bill_admin_refund` event
      type, plumbed through `Matrix_MLM_Notifications`.
- [ ] **2.8** Update `Matrix_MLM_User_Billing::render_history_details()`
      to surface refunded amount + status badge on rows
      that have been refunded.
- [ ] **2.9** Manual smoke: full refund of a completed
      transaction; partial refund (twice) until full;
      attempt-to-over-refund; non-admin attempt; concurrent
      double-submit (staged race).

---

## PR 3 — Item E: Idempotency / client_reference round-trip
Branch: `feat/fintava-billing-idempotency-reference`

- [ ] **3.1** Verify the exact Fintava field name from
      Fintava billing API docs. Document the decision in the
      PR description. Fall back to dual-key body if the docs
      are silent or contradictory (see `design.md`).
- [ ] **3.2** Schema migration: add `client_reference`
      VARCHAR(64) UNIQUE and `completed_at` DATETIME
      columns. Widen the `status` enum default from
      `'completed'` to `'pending'`. Existing rows remain
      `'completed'` — only new INSERTs default to pending.
- [ ] **3.3** Add `Matrix_MLM_Fintava_Billing::build_client_reference($type, $user_id)`
      with the format from `design.md` (`MTRX-BILL-{type}-
      {user_id}-{base32_short}`).
- [ ] **3.4** Update the four `ajax_buy_*` handlers to:
      generate reference, INSERT row at status=pending
      BEFORE the API call, send reference on the body,
      UPDATE row to completed/failed AFTER the call.
- [ ] **3.5** Update `make_request()` to inject the
      reference into the JSON body for the four billing
      POST endpoints (airtime, data, cable, electricity).
      Field name per 3.1.
- [ ] **3.6** Distinguish HTTP-error vs. transport-error
      WP_Errors in the four `ajax_buy_*` handlers:
      `http_request_failed` → leave row `pending`, do NOT
      refund. Other WP_Errors → refund as today.
- [ ] **3.7** Update the user-facing error message for
      transport-level failures to "Your purchase is being
      verified, please check Bill History in a few minutes."
- [ ] **3.8** Manual smoke: successful buy (row goes
      pending → completed); HTTP 400 buy (refund fires);
      mocked `http_request_failed` (row stays pending,
      wallet stays debited, message reflects verification);
      duplicate-reference INSERT attempt (UNIQUE violation
      surfaces as a clean error).

---

## Cross-PR considerations

- **No combined migrations.** Each PR runs its own
  ALTER inside `create_table()`. The migrations are
  idempotent (column-existence checks first), so the
  order of merges between branches in flight is safe.
- **No combined commits.** Per the team learning, each
  PR opens a fresh branch off `main` and is reviewed +
  merged independently. PR 2 rebases on `main` after
  PR 1 lands; PR 3 rebases on `main` after PR 2 lands.
- **Test approach is manual smoke.** The repo does not
  ship an automated test harness today. If the team
  adds one before E lands, the test plans in `design.md`
  port over directly.
- **Documentation.** Each PR updates the file-level
  docblock at the top of `class-matrix-fintava-billing.php`
  to reflect the new flow. The README does not need
  to change — it points to the admin UI for operator
  documentation.
