<?php
/**
 * Consolidated Wallet page.
 *
 * Replaces three previously-separate sidebar tabs:
 *
 *   - "Balance Transfer"  (user-to-user matrix wallet transfer) — DROPPED
 *      from the sidebar entirely as part of this consolidation. The
 *      class file Matrix_MLM_User_Transfer is kept on disk so the
 *      legacy ?tab=transfer URL doesn't fatal if anyone has it
 *      bookmarked, but the slug is removed from the dashboard
 *      whitelist and falls through to overview.
 *
 *   - "Bank Payout"       (matrix wallet → external Nigerian bank via
 *      Fintava) — folded in as the "Transfer to Bank" tab below.
 *      Renders the existing Matrix_MLM_User_Bank_Payout::render() body
 *      with $skip_header=true so this page owns the page-level H2.
 *
 *   - "Virtual Wallet"    (matrix wallet ↔ user's Fintava virtual
 *      account) — folded in as the page header (account number / name
 *      / bank / status / live balance) plus the "Transfer to Own
 *      Wallet" tab. Reuses the existing AJAX endpoints
 *      (matrix_fintava_wallet_balance, matrix_fintava_set_my_wallet_id,
 *      matrix_fintava_initiate_transfer, matrix_fintava_create_virtual_wallet)
 *      so no backend rewiring is needed.
 *
 * IMPORTANT — debit semantics for "Transfer to Bank":
 *   The legacy Bank Payout flow debits the user's *Matrix* wallet, not
 *   their Fintava virtual wallet. The merchant's Fintava balance is
 *   what funds the destination bank credit; the local Matrix balance
 *   is the bookkeeping side. The user-facing label on the consolidated
 *   page calls this "Transfer to Bank" because that's the action the
 *   user takes, but the embedded form continues to display "Source:
 *   Matrix Wallet" so there is no surprise about which balance moves.
 *   Switching the debit source to the virtual wallet is a separate
 *   backend change (different Fintava endpoint, different settlement
 *   model) and is intentionally NOT part of this PR.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Wallet {

    /**
     * Render the consolidated Wallet page.
     *
     * Branch order:
     *   1. Fintava integration disabled → warning, bail.
     *   2. User has no virtual wallet row yet → render the same Wallet
     *      page chrome (header cards, single "Create Fintava Wallet"
     *      action button, embedded create form pane, transaction
     *      history) so the page is consistent before and after
     *      onboarding. The create form itself is delegated to
     *      Matrix_MLM_User_Virtual_Wallet::render_create_form() so the
     *      onboarding markup stays in one canonical place.
     *   3. Wallet exists → render the header (account info + balances)
     *      and the three action buttons + form panes.
     */
    public function render($user_id) {
        $fintava   = new Matrix_MLM_Fintava();
        $is_active = $fintava->is_active();

        echo '<h2>' . esc_html__('Wallet', 'matrix-mlm') . '</h2>';
        echo '<p class="matrix-subtitle">' . esc_html__('Your virtual bank account and Matrix wallet, all in one place.', 'matrix-mlm') . '</p>';

        if (!$is_active) {
            echo '<div class="matrix-alert matrix-alert-warning">' .
                esc_html__('Wallet service is currently unavailable. Please contact support.', 'matrix-mlm') .
                '</div>';
            $this->render_base_styles();
            return;
        }

        $wallet = $fintava->get_user_wallet($user_id);
        $matrix_wallet  = new Matrix_MLM_Wallet();
        $matrix_balance = $matrix_wallet->get_balance($user_id);
        $currency       = get_option('matrix_mlm_currency_symbol', '₦');

        if (!$wallet) {
            // No-Fintava onboarding state. Render the standard Wallet
            // page chrome (Matrix Wallet card + Virtual Account
            // placeholder card on top, a single "Create Fintava Wallet"
            // action button that reveals the embedded create form on
            // click, and the transaction history table at the bottom)
            // instead of replacing the whole page with the bare create
            // form. The previous revision short-circuited to
            // Matrix_MLM_User_Virtual_Wallet::render_create_form()
            // directly when the user had no wallet, which made the
            // Wallet sidebar item feel like two different pages — a
            // "Create Wallet" mode for new users and a normal Wallet
            // page for everyone else. Users who landed on the page
            // expecting to see their Matrix balance were instead
            // confronted with a BVN form, which is the bug behind the
            // "wallet should be active with three buttons or a create
            // wallet button" report.
            //
            // The action button row shows three buttons in this state:
            //   - Transfer to Own Wallet — Matrix → Virtual. Needs a
            //     Fintava wallet as destination, which doesn't exist
            //     yet, so the matching pane shows a notice prompting
            //     the user to create their Virtual Account first
            //     (with a one-click jump to the create-wallet pane).
            //   - Wallet to Wallet — Matrix → another user's Matrix
            //     wallet (internal transfer). Doesn't require Fintava
            //     at all, so the matching pane renders the full
            //     functional form using the existing matrix_mlm_action
            //     transfer endpoint, identical to the wallet-exists
            //     flow.
            //   - Create Fintava Wallet — one-time onboarding CTA.
            //
            // "Transfer to Bank" is not surfaced here: it's exclusively
            // a Fintava bank-payout flow with no meaningful no-wallet
            // fallback (the source account doesn't exist yet), so
            // showing it would only invite confused clicks. Once the
            // wallet exists the normal flow below renders the full
            // three-button row including bank.
            $this->render_header_no_wallet($matrix_balance, $currency);
            $this->render_action_buttons_no_wallet();
            $this->render_panes_no_wallet($user_id, $matrix_balance, $currency);
            $this->render_transaction_history($user_id, $currency);
            $this->render_base_styles();
            $this->render_scripts_no_wallet($matrix_balance, $currency);
            return;
        }

        $this->render_header($wallet, $user_id, $matrix_balance, $currency, $fintava);
        $this->render_action_buttons();
        $this->render_panes($wallet, $user_id, $matrix_balance, $currency);
        // Transaction History is rendered as an always-visible table at
        // the bottom of the page rather than as a fourth toggleable
        // action pane: it's a read-only ledger, not an action, so
        // hiding it behind a button click would add an extra step
        // without any payoff. The action buttons above stay scoped
        // to "things that move money"; the table below answers
        // "what just happened to it".
        $this->render_transaction_history($user_id, $currency);
        $this->render_base_styles();
        $this->render_scripts($wallet, $matrix_balance, $currency);
    }

    /**
     * Render the wallet overview card: virtual account details + live
     * Fintava balance + Matrix wallet balance.
     *
     * Mirrors the virtual-wallet page's existing balance-fetch
     * resilience: if wallet_id is missing or Fintava errors, we degrade
     * to a placeholder with an inline "Verify & Save Wallet ID" form
     * (the only operator-recoverable failure mode), and surface the
     * raw error in an admin-only <details> block for diagnostics.
     */
    private function render_header($wallet, $user_id, $matrix_balance, $currency, $fintava) {
        // Same balance-fetch contract as Virtual_Wallet::render_wallet_with_transfer().
        // Two distinct failure modes intentionally surfaced separately
        // because they have different recovery paths:
        //   - missing_wallet_id  → user-recoverable via the inline form below
        //   - api_error          → not user-recoverable; just show the message
        $balance_value    = null;
        $balance_currency = $wallet->currency ?? 'NGN';
        $balance_error    = '';
        $balance_reason   = '';

        if (!empty($wallet->wallet_id)) {
            $result = $fintava->get_virtual_wallet_balance(
                $wallet->wallet_id,
                $wallet->account_number,
                $wallet->customer_id ?? null
            );
            if (is_wp_error($result)) {
                $balance_error  = Matrix_MLM_Fintava::normalize_api_message(
                    $result->get_error_message(),
                    __('Balance is unavailable.', 'matrix-mlm')
                );
                $balance_reason = 'api_error';
            } else {
                $balance_value    = $result['available_balance'];
                $balance_currency = $result['currency'];
            }
        } else {
            $balance_error  = __('Balance is unavailable.', 'matrix-mlm');
            $balance_reason = 'missing_wallet_id';
        }

        $is_admin_viewing = current_user_can('manage_matrix_mlm');
        ?>
        <div class="matrix-wallet-overview">
            <!-- Virtual Account card -->
            <div class="matrix-wallet-card matrix-wallet-card-virtual">
                <div class="matrix-wallet-card-header">
                    <span class="matrix-wallet-card-label"><?php esc_html_e('Virtual Account', 'matrix-mlm'); ?></span>
                    <span class="matrix-wallet-status matrix-wallet-status-<?php echo esc_attr($wallet->status); ?>">
                        <?php echo esc_html(ucfirst($wallet->status)); ?>
                    </span>
                </div>

                <div class="matrix-wallet-account-number">
                    <span><?php echo esc_html($wallet->account_number); ?></span>
                    <button type="button" class="matrix-wallet-copy-btn"
                            data-clipboard="<?php echo esc_attr($wallet->account_number); ?>">
                        <?php esc_html_e('Copy', 'matrix-mlm'); ?>
                    </button>
                </div>

                <div class="matrix-wallet-balance-row">
                    <div class="matrix-wallet-balance-label"><?php esc_html_e('Available Balance', 'matrix-mlm'); ?></div>
                    <div class="matrix-wallet-balance-value">
                        <span id="matrix-fintava-balance-amount">
                            <?php
                            if ($balance_value !== null) {
                                $sym = ($balance_currency === 'NGN' ? '₦' : ($balance_currency . ' '));
                                echo esc_html($sym . number_format($balance_value, 2));
                            } else {
                                echo '<span title="' . esc_attr($balance_error) . '">&mdash;</span>';
                            }
                            ?>
                        </span>
                        <button type="button" class="matrix-wallet-refresh-btn" id="matrix-fintava-refresh-balance"
                                title="<?php esc_attr_e('Refresh from Fintava', 'matrix-mlm'); ?>">
                            <?php esc_html_e('Refresh', 'matrix-mlm'); ?>
                        </button>
                    </div>
                    <?php if ($balance_error): ?>
                        <?php if ($balance_reason === 'missing_wallet_id'): ?>
                            <div class="matrix-wallet-help-text">
                                <?php esc_html_e('Balance lookup needs your Fintava Wallet ID. Add it below — we\'ll verify it against the live API and only save it if the account number matches yours.', 'matrix-mlm'); ?>
                            </div>
                            <div class="matrix-set-wallet-id-form">
                                <input type="text" id="matrix-fintava-wallet-id-input"
                                       placeholder="<?php esc_attr_e('Fintava Wallet ID', 'matrix-mlm'); ?>">
                                <button type="button" id="matrix-fintava-set-wallet-id-btn">
                                    <?php esc_html_e('Verify & Save', 'matrix-mlm'); ?>
                                </button>
                                <span id="matrix-fintava-set-wallet-id-status"></span>
                            </div>
                        <?php else: ?>
                            <div class="matrix-wallet-error-text">
                                <?php echo esc_html($balance_error); ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="matrix-wallet-meta-grid">
                    <div class="matrix-wallet-meta-item">
                        <div class="matrix-wallet-meta-label"><?php esc_html_e('Account Name', 'matrix-mlm'); ?></div>
                        <div class="matrix-wallet-meta-value"><?php echo esc_html($wallet->account_name); ?></div>
                    </div>
                    <div class="matrix-wallet-meta-item">
                        <div class="matrix-wallet-meta-label"><?php esc_html_e('Bank', 'matrix-mlm'); ?></div>
                        <div class="matrix-wallet-meta-value"><?php echo esc_html($wallet->bank_name); ?></div>
                    </div>
                </div>
            </div>

            <!-- Matrix Wallet card -->
            <div class="matrix-wallet-card matrix-wallet-card-matrix">
                <div class="matrix-wallet-card-header">
                    <span class="matrix-wallet-card-label"><?php esc_html_e('Matrix Wallet', 'matrix-mlm'); ?></span>
                </div>
                <div class="matrix-wallet-balance-value matrix-wallet-balance-value-large">
                    <?php echo esc_html($currency . number_format($matrix_balance, 2)); ?>
                </div>
                <div class="matrix-wallet-help-text matrix-wallet-help-text-light">
                    <?php esc_html_e('Internal earnings wallet. Use the actions below to move funds.', 'matrix-mlm'); ?>
                </div>
            </div>
        </div>

        <?php
        // Admin-only debug block — same diagnostic the virtual-wallet
        // page used to expose. Kept so support can see the raw Fintava
        // /customer/wallet/balance/{walletId} response without scraping
        // logs. Closed by default; gated on capability + wallet_id so
        // ordinary users never see it.
        if ($is_admin_viewing && !empty($wallet->wallet_id)) {
            $debug_raw  = $fintava->debug_balance_raw($wallet->wallet_id);
            $debug_json = wp_json_encode($debug_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            ?>
            <details class="matrix-fintava-balance-debug">
                <summary><?php esc_html_e('FINTAVA_BALANCE_DEBUG (admin only — temporary diagnostic)', 'matrix-mlm'); ?></summary>
                <p><?php esc_html_e('Raw response from GET /customer/wallet/balance/{walletId}.', 'matrix-mlm'); ?></p>
                <pre><?php echo esc_html($debug_json); ?></pre>
            </details>
            <?php
        }
    }

    /**
     * Render the three top-level action buttons.
     *
     * Buttons toggle the matching pane below (data-target → data-pane).
     * All panes are rendered server-side so the forms work even if the
     * tab-toggle JS fails to bind for any reason; only their visibility
     * is controlled client-side.
     *
     * Order is "narrowest → widest reach":
     *   own-wallet   — money stays under the same user (Matrix → Virtual)
     *   user-wallet  — money moves to another platform user, still on
     *                  the Matrix side (internal/wallet-to-wallet)
     *   bank         — money exits the platform entirely (external bank)
     *
     * Transaction History is intentionally NOT one of these buttons —
     * it lives as a static table at the bottom of the page (rendered
     * by render_transaction_history() in render()) because it's a
     * read-only ledger, not an action.
     *
     * No button starts in the .is-active state and all three panes
     * (see render_panes()) are rendered with the [hidden] attribute,
     * so the wallet page lands in a clean overview state on page
     * load: balance cards on top, three action buttons below them,
     * transaction history at the bottom, and no form competing for
     * attention. Clicking any of the three buttons reveals its
     * matching pane (see the click handler in render_scripts());
     * clicking the active button again collapses the pane back to
     * the overview state. An earlier revision marked "Transfer to
     * Bank" .is-active server-side to save users a click, but that
     * left the bank-payout form expanded by default below the
     * overview cards (and below the *button* labelled "Transfer to
     * Bank"), which read as duplicate UI — so we're back to the
     * symmetric "all collapsed" default that the other two actions
     * already used.
     */
    private function render_action_buttons() {
        ?>
        <div class="matrix-wallet-actions">
            <button type="button" class="matrix-wallet-action-btn" data-target="own-wallet">
                <span class="matrix-wallet-action-icon dashicons dashicons-randomize"></span>
                <span class="matrix-wallet-action-text">
                    <strong><?php esc_html_e('Transfer to Own Wallet', 'matrix-mlm'); ?></strong>
                    <small><?php esc_html_e('Move funds from your Matrix wallet to your Virtual account', 'matrix-mlm'); ?></small>
                </span>
            </button>
            <button type="button" class="matrix-wallet-action-btn" data-target="user-wallet">
                <span class="matrix-wallet-action-icon dashicons dashicons-share"></span>
                <span class="matrix-wallet-action-text">
                    <strong><?php esc_html_e('Wallet to Wallet', 'matrix-mlm'); ?></strong>
                    <small><?php esc_html_e('Send funds to another user\'s Matrix wallet (internal transfer)', 'matrix-mlm'); ?></small>
                </span>
            </button>
            <button type="button" class="matrix-wallet-action-btn" data-target="bank">
                <span class="matrix-wallet-action-icon dashicons dashicons-bank"></span>
                <span class="matrix-wallet-action-text">
                    <strong><?php esc_html_e('Transfer to Bank', 'matrix-mlm'); ?></strong>
                    <small><?php esc_html_e('Send funds to any Nigerian bank account', 'matrix-mlm'); ?></small>
                </span>
            </button>
        </div>
        <?php
    }

    /**
     * Render the three action panes (hidden by default; one is shown
     * when its matching action button is clicked).
     */
    private function render_panes($wallet, $user_id, $matrix_balance, $currency) {
        ?>
        <section class="matrix-wallet-pane" data-pane="own-wallet" hidden>
            <?php $this->render_transfer_to_own_wallet_form($wallet, $user_id, $matrix_balance, $currency); ?>
        </section>

        <section class="matrix-wallet-pane" data-pane="user-wallet" hidden>
            <?php $this->render_wallet_to_wallet_form($user_id, $matrix_balance, $currency); ?>
        </section>

        <section class="matrix-wallet-pane" data-pane="bank" hidden>
            <?php
            // Embed the existing Bank Payout flow body (form + history +
            // its own JS). $skip_header=true suppresses the legacy H2
            // and subtitle so this page's own header is the only one
            // visible. The bank-payout class still owns its bank list,
            // account-resolver, charge preview, manual-override path,
            // submit-status hint, and "Clear failed transactions"
            // toolbar — none of which need to be re-implemented here.
            //
            // [hidden] by default, like the other two panes. The form
            // only appears when the user clicks the "Transfer to Bank"
            // action button above (see the click handler in
            // render_scripts() — it removes [hidden] from the matching
            // pane and adds .is-active to the matching button). An
            // earlier revision left this pane visible on page load with
            // .is-active on the matching button, but that read as a
            // duplicated "Transfer to Bank" UI (the action button label
            // is identical to the pane's own submit button label), so
            // we're back to the symmetric "all collapsed" default.
            (new Matrix_MLM_User_Bank_Payout())->render($user_id, true);
            ?>
        </section>
        <?php
    }

    /**
     * Render the header for the no-Fintava-wallet onboarding state.
     *
     * Same two-card layout as the normal render_header() — Virtual
     * Account on the left, Matrix Wallet on the right — but the
     * Virtual Account card shows a "Not Activated" badge plus a
     * one-line explanation of what a Fintava wallet does, instead of
     * the account number / balance / refresh button. The Matrix
     * Wallet card is unchanged: the user has a Matrix balance
     * regardless of Fintava onboarding status, and seeing it here
     * gives them context for the Wallet-to-Wallet and (later, after
     * onboarding) Transfer-to-Bank flows.
     *
     * Kept separate from render_header() because that method needs
     * the live Fintava balance fetch (which requires $wallet) and
     * the inline "Verify & Save Wallet ID" recovery form (which is
     * meaningless for users who don't have a wallet at all). Sharing
     * one method via if-branching would have made both paths harder
     * to read.
     */
    private function render_header_no_wallet($matrix_balance, $currency) {
        ?>
        <div class="matrix-wallet-overview">
            <!-- Virtual Account placeholder -->
            <div class="matrix-wallet-card matrix-wallet-card-virtual">
                <div class="matrix-wallet-card-header">
                    <span class="matrix-wallet-card-label"><?php esc_html_e('Virtual Account', 'matrix-mlm'); ?></span>
                    <span class="matrix-wallet-status">
                        <?php esc_html_e('Not Activated', 'matrix-mlm'); ?>
                    </span>
                </div>
                <div class="matrix-wallet-help-text matrix-wallet-help-text-light" style="margin-top:16px;line-height:1.5;">
                    <?php esc_html_e('You don\'t have a Fintava virtual account yet. Create one to receive deposits and transfer funds to any Nigerian bank account.', 'matrix-mlm'); ?>
                </div>
            </div>

            <!-- Matrix Wallet card -->
            <div class="matrix-wallet-card matrix-wallet-card-matrix">
                <div class="matrix-wallet-card-header">
                    <span class="matrix-wallet-card-label"><?php esc_html_e('Matrix Wallet', 'matrix-mlm'); ?></span>
                </div>
                <div class="matrix-wallet-balance-value matrix-wallet-balance-value-large">
                    <?php echo esc_html($currency . number_format($matrix_balance, 2)); ?>
                </div>
                <div class="matrix-wallet-help-text matrix-wallet-help-text-light">
                    <?php esc_html_e('Internal earnings wallet. You can already send funds to another user below; create a Fintava wallet to also transfer to your own Virtual account or to any Nigerian bank.', 'matrix-mlm'); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the action button row shown to users who haven't
     * completed Fintava onboarding yet.
     *
     * Three buttons, mirroring the wallet-exists row's layout and
     * data-target/data-pane contract (see render_action_buttons() and
     * render_panes_no_wallet()):
     *
     *   own-wallet     — Matrix → Virtual. Pane shows a notice +
     *                    one-click jump to create-wallet because the
     *                    destination doesn't exist yet.
     *   user-wallet    — Matrix → Matrix (another user). Pane shows
     *                    the full functional wallet-to-wallet form;
     *                    this flow doesn't need Fintava at all.
     *   create-wallet  — one-time Fintava onboarding CTA.
     *
     * "Transfer to Bank" is intentionally omitted here — it's a pure
     * Fintava bank-payout flow with no meaningful no-wallet fallback
     * (the source account doesn't exist yet), so surfacing it would
     * only invite confused clicks. Once the wallet exists the full
     * three-button row (own-wallet, user-wallet, bank) is rendered by
     * render_action_buttons().
     *
     * Uses the same .matrix-wallet-actions row + .matrix-wallet-action-btn
     * markup as render_action_buttons() so the click-to-reveal pane
     * toggle in render_scripts_no_wallet() uses the exact same JS
     * contract.
     */
    private function render_action_buttons_no_wallet() {
        ?>
        <div class="matrix-wallet-actions">
            <button type="button" class="matrix-wallet-action-btn" data-target="own-wallet">
                <span class="matrix-wallet-action-icon dashicons dashicons-randomize"></span>
                <span class="matrix-wallet-action-text">
                    <strong><?php esc_html_e('Transfer to Own Wallet', 'matrix-mlm'); ?></strong>
                    <small><?php esc_html_e('Move funds from your Matrix wallet to your Virtual account', 'matrix-mlm'); ?></small>
                </span>
            </button>
            <button type="button" class="matrix-wallet-action-btn" data-target="user-wallet">
                <span class="matrix-wallet-action-icon dashicons dashicons-share"></span>
                <span class="matrix-wallet-action-text">
                    <strong><?php esc_html_e('Wallet to Wallet', 'matrix-mlm'); ?></strong>
                    <small><?php esc_html_e('Send funds to another user\'s Matrix wallet (internal transfer)', 'matrix-mlm'); ?></small>
                </span>
            </button>
            <button type="button" class="matrix-wallet-action-btn" data-target="create-wallet">
                <span class="matrix-wallet-action-icon dashicons dashicons-plus-alt"></span>
                <span class="matrix-wallet-action-text">
                    <strong><?php esc_html_e('Create Fintava Wallet', 'matrix-mlm'); ?></strong>
                    <small><?php esc_html_e('One-time setup. Get a virtual bank account to receive earnings and transfer to any bank.', 'matrix-mlm'); ?></small>
                </span>
            </button>
        </div>
        <?php
    }

    /**
     * Render the action panes shown to users who haven't completed
     * Fintava onboarding yet.
     *
     * Three panes (hidden by default; each revealed by clicking its
     * matching button in render_action_buttons_no_wallet()):
     *
     *   own-wallet    — Matrix → Virtual. The destination doesn't
     *                   exist yet, so we show an in-pane notice
     *                   instead of the full transfer form, with a
     *                   button that programmatically clicks the
     *                   "Create Fintava Wallet" action button (so
     *                   the user lands directly on the create form
     *                   without losing the "I want to move funds to
     *                   my own wallet" intent that brought them
     *                   here).
     *   user-wallet   — Matrix → Matrix internal transfer. Renders
     *                   the same form the wallet-exists flow uses
     *                   (render_wallet_to_wallet_form()) — that flow
     *                   doesn't depend on $wallet at all, only on
     *                   the user's Matrix balance.
     *   create-wallet — Embeds the canonical Fintava create form
     *                   from Matrix_MLM_User_Virtual_Wallet so the
     *                   onboarding markup stays in one place. On
     *                   submit success the create-form JS fires
     *                   location.reload(), and the page re-renders
     *                   through the wallet-exists flow with the
     *                   full three-button row + live Fintava
     *                   balance.
     */
    private function render_panes_no_wallet($user_id, $matrix_balance, $currency) {
        $user = get_userdata($user_id);
        $meta = Matrix_MLM_User::get_meta($user_id);
        ?>
        <section class="matrix-wallet-pane" data-pane="own-wallet" hidden>
            <h3><?php esc_html_e('Transfer to Own Wallet', 'matrix-mlm'); ?></h3>

            <div class="matrix-transfer-note">
                <?php esc_html_e('To transfer funds from your Matrix wallet into your own Virtual account you first need to create a Fintava virtual wallet. It only takes a moment — once it is set up, this option will be enabled for you.', 'matrix-mlm'); ?>
            </div>

            <div class="matrix-info-box">
                <p><strong><?php esc_html_e('Source:', 'matrix-mlm'); ?></strong> <?php esc_html_e('Matrix Wallet', 'matrix-mlm'); ?> &mdash; <?php echo esc_html($currency . number_format($matrix_balance, 2)); ?></p>
                <p><strong><?php esc_html_e('Destination:', 'matrix-mlm'); ?></strong> <?php esc_html_e('Your Virtual Account (not yet created)', 'matrix-mlm'); ?></p>
            </div>

            <button type="button"
                    class="matrix-btn matrix-btn-primary matrix-btn-block"
                    id="matrix-own-wallet-create-cta">
                <?php esc_html_e('Create Virtual Wallet to Continue', 'matrix-mlm'); ?>
            </button>
        </section>

        <section class="matrix-wallet-pane" data-pane="user-wallet" hidden>
            <?php $this->render_wallet_to_wallet_form($user_id, $matrix_balance, $currency); ?>
        </section>

        <section class="matrix-wallet-pane" data-pane="create-wallet" hidden>
            <?php (new Matrix_MLM_User_Virtual_Wallet())->render_create_form($user, $meta); ?>
        </section>
        <?php
    }

    /**
     * Inline JS for the no-Fintava-wallet onboarding state.
     *
     * Binds the action-button → pane toggle (UI only), the
     * Wallet-to-Wallet form submit + amount preview (Matrix → Matrix
     * internal transfer; works without Fintava), and the "Create
     * Virtual Wallet to Continue" CTA inside the own-wallet pane
     * (which programmatically clicks the create-wallet action button
     * so the user lands on the create form without losing context).
     *
     * The Wallet-to-Wallet binding is a verbatim port of the matching
     * block in render_scripts() — same matrix_mlm_action endpoint,
     * same matrix_action=transfer branch, same #matrix-w2w-form ID,
     * same client-side validation and confirmation copy. Copying it
     * over (rather than refactoring into a shared helper) keeps the
     * no-wallet path self-contained and avoids risking regressions in
     * the working wallet-exists path.
     *
     * AJAX-dependent handlers gate on matrixMLM the same way as
     * render_scripts(): if the global is missing we disable the W2W
     * form's inputs so the user gets a visible "form is dead"
     * affordance, but still bind the UI-only toggles so the page
     * stays navigable.
     *
     * Same jQuery-polling guard as the main render_scripts() — see
     * that method's header comment (and the bank-payout pane's twin
     * pattern) for why direct (function($){})(jQuery) on inline body
     * scripts breaks on sites where an optimizer plugin defers
     * jQuery to the footer.
     */
    private function render_scripts_no_wallet($matrix_balance, $currency) {
        ?>
        <script>
        (function() {
            var attempts = 0;
            var maxAttempts = 200; // 200 * 50ms = 10s ceiling

            function whenJQueryReady(cb) {
                if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn !== 'undefined') {
                    window.jQuery(cb);
                    return;
                }
                if (++attempts > maxAttempts) {
                    if (window.console && console.error) {
                        console.error('[Matrix MLM] jQuery not loaded after 10s; wallet onboarding toggle not bound.');
                    }
                    return;
                }
                setTimeout(function() { whenJQueryReady(cb); }, 50);
            }

            whenJQueryReady(function($) {
                'use strict';

                if (window.console && console.log) {
                    console.log('[Matrix MLM] Wallet onboarding handlers binding (no-Fintava state).');
                }

                // -----------------------------------------------------------
                // UI-only handlers (do NOT depend on matrixMLM).
                //
                // These bind first and unconditionally so the action
                // buttons + create-wallet CTA jump still work even
                // when matrixMLM is missing (e.g., an optimizer
                // dequeued matrix-mlm-public.js).
                //
                // IMPORTANT: handlers are bound via event delegation
                // on document (.on with selector argument) rather than
                // direct .on on matched elements. The previous
                // revision used direct binding, which assumes the
                // buttons exist in the DOM the moment whenJQueryReady
                // fires. That assumption holds for first-time pageviews
                // but breaks subtly if any of: a parent script
                // throws and nudges the parser, the document-ready
                // fires before our buttons get to the DOM, or jQuery's
                // .data() gets cached against an earlier attribute
                // state. Document delegation sidesteps all three —
                // jQuery walks up from the click target each time
                // and re-reads data-target fresh.
                // -----------------------------------------------------------

                // Action button → pane toggle. Same contract as the
                // multi-button toggle in render_scripts(): clicking the
                // active button collapses its pane back to the overview
                // state, clicking it from the overview state reveals
                // the pane.
                //
                // Visibility is controlled via BOTH the [hidden]
                // attribute AND an explicit inline display style.
                // Some themes ship CSS like `section { display: block
                // !important }` or scope `.matrix-wallet-pane` rules
                // that compete with our `.matrix-wallet-pane[hidden]
                // { display: none }` rule. Setting `style="display:
                // ..."` directly wins on specificity (inline styles
                // beat any non-!important selector), and removing
                // [hidden] keeps the HTML semantics aligned with the
                // visual state.
                $(document).on('click', '.matrix-wallet-action-btn', function() {
                    var $btn    = $(this);
                    var target  = $btn.attr('data-target');
                    var $pane   = $('.matrix-wallet-pane[data-pane="' + target + '"]');
                    var wasOpen = $btn.hasClass('is-active');

                    $('.matrix-wallet-action-btn').removeClass('is-active');
                    $('.matrix-wallet-pane').attr('hidden', 'hidden').css('display', 'none');

                    if (!wasOpen) {
                        $btn.addClass('is-active');
                        $pane.removeAttr('hidden').css('display', '');
                        if (window.innerWidth < 900) {
                            var top = $pane.offset().top - 20;
                            $('html, body').animate({ scrollTop: top }, 250);
                        }
                    }
                });

                // "Create Virtual Wallet to Continue" CTA inside the
                // own-wallet pane. Programmatically clicks the
                // create-wallet action button so the user is
                // teleported straight onto the canonical create form,
                // preserving their "I want to move funds to my own
                // wallet" intent.
                $(document).on('click', '#matrix-own-wallet-create-cta', function() {
                    $('.matrix-wallet-action-btn[data-target="create-wallet"]').trigger('click');
                });

                // -----------------------------------------------------------
                // AJAX-dependent handlers — gate on matrixMLM the same
                // way render_scripts() does.
                // -----------------------------------------------------------
                if (typeof matrixMLM === 'undefined' || !matrixMLM.ajaxUrl) {
                    $('#matrix-w2w-form :input').prop('disabled', true);
                    if (window.console && console.error) {
                        console.error('[Matrix MLM] matrixMLM global is not defined — wallet-to-wallet form not bound.');
                    }
                    return;
                }

                // -----------------------------------------------------------
                // Wallet → Wallet transfer (internal user-to-user).
                //
                // Verbatim port of the matching block in
                // render_scripts(). Hits matrix_mlm_action with
                // matrix_action=transfer; the server-side handler
                // (Matrix_MLM_Core::process_transfer) does the
                // username lookup, balance check, debit/credit,
                // matrix_transfers row insert, and recipient email
                // notification — none of which touch Fintava — so
                // this works fine for users with no virtual wallet.
                // -----------------------------------------------------------
                var ownCurrency    = '<?php echo esc_js($currency); ?>';
                var w2wMin         = <?php echo floatval(get_option('matrix_mlm_min_transfer', 500)); ?>;
                var w2wChargeType  = '<?php echo esc_js(get_option('matrix_mlm_transfer_charge_type', 'fixed')); ?>';
                var w2wChargeValue = <?php echo floatval(get_option('matrix_mlm_transfer_charge', 100)); ?>;
                var w2wBalance     = <?php echo floatval($matrix_balance); ?>;

                $('#matrix-w2w-amount').on('input', function() {
                    var amount = parseFloat($(this).val()) || 0;
                    if (amount > 0) {
                        var charge = w2wChargeType === 'percent'
                            ? (amount * w2wChargeValue / 100)
                            : w2wChargeValue;
                        charge = Math.round(charge * 100) / 100;
                        var total = amount + charge;
                        $('#matrix-w2w-charge').text(ownCurrency + charge.toLocaleString());
                        $('#matrix-w2w-total').text(ownCurrency + total.toLocaleString());
                        $('#matrix-w2w-receive').text(ownCurrency + amount.toLocaleString());
                        $('#matrix-w2w-charge-info').show();
                    } else {
                        $('#matrix-w2w-charge-info').hide();
                    }
                });

                $('#matrix-w2w-form').on('submit', function(e) {
                    e.preventDefault();

                    var recipient = ($('#matrix-w2w-recipient').val() || '').trim();
                    var amount    = parseFloat($('#matrix-w2w-amount').val());

                    if (!recipient) {
                        alert('<?php echo esc_js(__('Please enter the recipient\'s username.', 'matrix-mlm')); ?>');
                        return;
                    }
                    if (!amount || amount < w2wMin) {
                        alert('<?php echo esc_js(__('Amount must be at least the minimum transfer.', 'matrix-mlm')); ?>');
                        return;
                    }

                    var charge = w2wChargeType === 'percent'
                        ? (amount * w2wChargeValue / 100)
                        : w2wChargeValue;
                    charge = Math.round(charge * 100) / 100;
                    var total = amount + charge;
                    if (total > w2wBalance) {
                        alert('<?php echo esc_js(__('Insufficient Matrix wallet balance for amount + charge.', 'matrix-mlm')); ?>');
                        return;
                    }

                    if (!confirm('<?php echo esc_js(__('Send', 'matrix-mlm')); ?> ' + ownCurrency + amount.toLocaleString() + ' <?php echo esc_js(__('to', 'matrix-mlm')); ?> ' + recipient + '?\n\n<?php echo esc_js(__('Charge:', 'matrix-mlm')); ?> ' + ownCurrency + charge.toFixed(2) + '\n<?php echo esc_js(__('Total Debit:', 'matrix-mlm')); ?> ' + ownCurrency + total.toFixed(2))) {
                        return;
                    }

                    var $btn = $('#matrix-w2w-submit-btn');
                    $btn.prop('disabled', true).text('<?php echo esc_js(__('Processing…', 'matrix-mlm')); ?>');

                    $.ajax({
                        url:  matrixMLM.ajaxUrl,
                        type: 'POST',
                        data: {
                            action:        'matrix_mlm_action',
                            nonce:         matrixMLM.nonce,
                            matrix_action: 'transfer',
                            recipient:     recipient,
                            amount:        amount
                        },
                        success: function(res) {
                            if (res && res.success) {
                                alert((res.data && res.data.message) || '<?php echo esc_js(__('Transfer successful.', 'matrix-mlm')); ?>');
                                (typeof matrixMLMReload === "function" ? matrixMLMReload : function(){ window.location.reload(); })();
                            } else {
                                alert((res && res.data && res.data.message) || '<?php echo esc_js(__('Transfer failed.', 'matrix-mlm')); ?>');
                                $btn.prop('disabled', false).text('<?php echo esc_js(__('Send Transfer', 'matrix-mlm')); ?>');
                            }
                        },
                        error: function() {
                            alert('<?php echo esc_js(__('Network error. Please try again.', 'matrix-mlm')); ?>');
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Send Transfer', 'matrix-mlm')); ?>');
                        }
                    });
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render the Matrix-wallet ledger as an always-visible table at
     * the bottom of the Wallet page.
     *
     * This is the same data the dashboard's old standalone
     * Transactions tab used to show: every credit/debit row on the
     * user's Matrix wallet, ordered newest-first, capped at 50 rows
     * to keep the page light without paging. Lives on the wallet
     * page (and not as a toggleable pane) because it's a read-only
     * ledger, not an action — every wallet activity that the action
     * buttons above can produce ends up here, so this section reads
     * naturally as the receipt of those actions.
     *
     * Wrapped in its own <section> with the matrix-wallet-tx-history
     * class so the styling sits at the top level (white card, page
     * heading) instead of inheriting the .matrix-wallet-pane
     * "hidden + grey background" treatment used by the action panes.
     *
     * If the cap of 50 rows starts feeling restrictive we can grow
     * this into a paginated view; the underlying
     * Matrix_MLM_Wallet::get_transactions() already accepts a limit,
     * and adding offset/page params is a localized change that
     * doesn't affect the rest of the wallet page.
     */
    private function render_transaction_history($user_id, $currency) {
        $wallet       = new Matrix_MLM_Wallet();
        $transactions = $wallet->get_transactions($user_id, 50);
        ?>
        <section class="matrix-wallet-tx-history">
            <h2><?php esc_html_e('Transaction History', 'matrix-mlm'); ?></h2>

            <?php if (empty($transactions)): ?>
                <div class="matrix-info-box">
                    <p><?php esc_html_e('No transactions yet. Your wallet activity will appear here once you make a deposit, transfer, or earn a commission.', 'matrix-mlm'); ?></p>
                </div>
            <?php else: ?>
                <table class="matrix-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Type', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Amount', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Post Balance', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Description', 'matrix-mlm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx): ?>
                        <tr>
                            <td><?php echo esc_html(date('M d, Y H:i', strtotime($tx->created_at))); ?></td>
                            <td><span class="matrix-badge matrix-badge-<?php echo esc_attr($tx->type); ?>"><?php echo esc_html(ucfirst($tx->type)); ?></span></td>
                            <td class="<?php echo $tx->type === 'credit' ? 'text-success' : 'text-danger'; ?>">
                                <?php echo esc_html(($tx->type === 'credit' ? '+' : '-') . $currency . number_format($tx->amount, 2)); ?>
                            </td>
                            <td><?php echo esc_html($currency . number_format($tx->post_balance, 2)); ?></td>
                            <td><?php echo esc_html($tx->description); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * Render the Matrix → Virtual account transfer form + history.
     *
     * Functionally equivalent to the old #matrix-transfer-to-fintava-form
     * on the virtual-wallet page. Same AJAX action
     * (matrix_fintava_initiate_transfer), same destination prefill
     * trick (the user's own virtual account is the bank-credit
     * destination), same charge preview math.
     */
    private function render_transfer_to_own_wallet_form($wallet, $user_id, $matrix_balance, $currency) {
        $min_transfer = floatval(get_option('matrix_mlm_fintava_min_payout', 1000));
        $max_transfer = floatval(get_option('matrix_mlm_fintava_max_payout', 5000000));
        $charge_type  = get_option('matrix_mlm_fintava_charge_type', 'fixed');
        $charge_value = floatval(get_option('matrix_mlm_fintava_charge_value', 50));

        $fintava = new Matrix_MLM_Fintava();
        $payouts = $fintava->get_user_payouts($user_id, 10);

        // Compute effective max upfront so the user can't type a value
        // that would silently fail the (amount + charge) ≤ balance gate.
        // Same logic as the bank-payout form's amount ceiling.
        if ($charge_type === 'percent') {
            $rate            = $charge_value / 100;
            $balance_ceiling = (1 + $rate) > 0 ? $matrix_balance / (1 + $rate) : $matrix_balance;
        } else {
            $balance_ceiling = max(0, $matrix_balance - $charge_value);
        }
        $balance_ceiling = floor($balance_ceiling * 100) / 100;
        $effective_max   = min($max_transfer, $balance_ceiling);
        ?>
        <h3><?php esc_html_e('Transfer to Own Wallet', 'matrix-mlm'); ?></h3>

        <div class="matrix-transfer-note">
            <?php esc_html_e('Funds will be deducted from your Matrix wallet and credited to your Virtual account. From there you can spend or withdraw via any Nigerian bank channel.', 'matrix-mlm'); ?>
        </div>

        <div class="matrix-info-box">
            <p><strong><?php esc_html_e('Source:', 'matrix-mlm'); ?></strong> <?php esc_html_e('Matrix Wallet', 'matrix-mlm'); ?> &mdash; <?php echo esc_html($currency . number_format($matrix_balance, 2)); ?></p>
            <p><strong><?php esc_html_e('Destination:', 'matrix-mlm'); ?></strong> <?php esc_html_e('Your Virtual Account', 'matrix-mlm'); ?> (<?php echo esc_html($wallet->account_number); ?>)</p>
            <p><strong><?php esc_html_e('Transfer Limits:', 'matrix-mlm'); ?></strong> <?php echo esc_html($currency . number_format($min_transfer) . ' - ' . $currency . number_format($max_transfer)); ?></p>
            <p><strong><?php esc_html_e('Service Charge:', 'matrix-mlm'); ?></strong> <?php echo esc_html($charge_type === 'percent' ? $charge_value . '%' : $currency . number_format($charge_value, 2)); ?></p>
        </div>

        <div class="matrix-form-card">
            <form id="matrix-transfer-to-own-wallet-form" class="matrix-form">
                <div class="matrix-form-group">
                    <label><?php esc_html_e('Amount to Transfer', 'matrix-mlm'); ?> (<?php echo esc_html($currency); ?>)</label>
                    <input type="number" name="amount" id="matrix-own-wallet-amount"
                           min="<?php echo esc_attr($min_transfer); ?>"
                           max="<?php echo esc_attr($effective_max); ?>"
                           step="0.01" required
                           placeholder="<?php echo esc_attr(sprintf(__('Min %s, Max %s', 'matrix-mlm'), number_format($min_transfer), number_format($effective_max, 2))); ?>">
                    <div id="matrix-own-wallet-charge-info" class="matrix-charge-info" style="display:none;">
                        <small>
                            <?php esc_html_e('Charge:', 'matrix-mlm'); ?> <span id="matrix-own-wallet-charge">-</span> |
                            <?php esc_html_e('Total Debit:', 'matrix-mlm'); ?> <span id="matrix-own-wallet-total">-</span> |
                            <?php esc_html_e('You Receive:', 'matrix-mlm'); ?> <span id="matrix-own-wallet-receive">-</span>
                        </small>
                    </div>
                </div>

                <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block" id="matrix-own-wallet-submit-btn">
                    <?php esc_html_e('Transfer to Own Wallet', 'matrix-mlm'); ?>
                </button>
            </form>
        </div>

        <?php if (!empty($payouts)): ?>
        <h4 class="matrix-wallet-history-heading"><?php esc_html_e('Recent Transfers (Matrix → Virtual)', 'matrix-mlm'); ?></h4>
        <table class="matrix-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Date', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Amount', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Charge', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Status', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Reference', 'matrix-mlm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payouts as $p): ?>
                <tr>
                    <td><?php echo esc_html(date('M d, Y H:i', strtotime($p->created_at))); ?></td>
                    <td><?php echo esc_html($currency . number_format($p->amount, 2)); ?></td>
                    <td><?php echo esc_html($currency . number_format($p->charge, 2)); ?></td>
                    <td><span class="matrix-badge matrix-badge-<?php echo esc_attr($p->status); ?>"><?php echo esc_html(ucfirst($p->status)); ?></span></td>
                    <td><small><code><?php echo esc_html($p->reference); ?></code></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Render the user-to-user (Matrix wallet → Matrix wallet) transfer
     * form + history. AKA "internal transfer" / "wallet to wallet".
     *
     * Reuses the existing public-facing AJAX action that the legacy
     * Balance Transfer page used:
     *
     *   - Action:  matrix_mlm_action  (POST)
     *   - Branch:  matrix_action=transfer
     *   - Handler: Matrix_MLM_Core::process_transfer()
     *
     * The handler validates min amount, looks up the recipient by
     * username, computes the charge, debits the sender's Matrix wallet
     * (debit($user_id, total)), credits the recipient's Matrix wallet
     * (credit($recipient_id, amount), no charge on receiver), inserts a
     * row into {prefix}matrix_transfers, and fires
     * Matrix_MLM_Notifications::send_transfer_notification() so the
     * recipient gets the inbound-transfer email.
     *
     * Form ID is intentionally NOT #matrix-transfer-form: the legacy
     * Balance Transfer page used that ID and matrix-public.js binds a
     * delegated submit handler to it on document. If we kept the same
     * ID, both that global handler AND our inline handler would fire
     * on submit, sending the AJAX request twice. Using a fresh ID
     * (#matrix-w2w-form) and routing through our own handler gives us
     * a richer confirmation dialog (recipient + amount + charge spelled
     * out) without the double-fire risk.
     */
    private function render_wallet_to_wallet_form($user_id, $matrix_balance, $currency) {
        global $wpdb;

        $min_transfer = floatval(get_option('matrix_mlm_min_transfer', 500));
        $charge_type  = get_option('matrix_mlm_transfer_charge_type', 'fixed');
        $charge_value = floatval(get_option('matrix_mlm_transfer_charge', 100));

        // Compute the practical ceiling so the user can't type a value
        // that would silently fail the server-side balance check. Same
        // logic as the bank/own-wallet ceilings: balance / (1+rate) for
        // percent charges, balance - charge for fixed.
        if ($charge_type === 'percent') {
            $rate            = $charge_value / 100;
            $balance_ceiling = (1 + $rate) > 0 ? $matrix_balance / (1 + $rate) : $matrix_balance;
        } else {
            $balance_ceiling = max(0, $matrix_balance - $charge_value);
        }
        $effective_max = floor($balance_ceiling * 100) / 100;

        // History (incoming + outgoing). Same query the legacy
        // Balance Transfer page used; just trimmed to 10 rows so the
        // pane stays compact when stacked under the form.
        $transfers = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*,
                    u1.user_login as from_username,
                    u2.user_login as to_username
             FROM {$wpdb->prefix}matrix_transfers t
             LEFT JOIN {$wpdb->users} u1 ON t.from_user_id = u1.ID
             LEFT JOIN {$wpdb->users} u2 ON t.to_user_id   = u2.ID
             WHERE t.from_user_id = %d OR t.to_user_id = %d
             ORDER BY t.created_at DESC LIMIT 10",
            $user_id, $user_id
        ));
        ?>
        <h3><?php esc_html_e('Wallet to Wallet Transfer', 'matrix-mlm'); ?></h3>

        <div class="matrix-transfer-note">
            <?php esc_html_e('Send funds from your Matrix wallet to another user\'s Matrix wallet by entering their username. The recipient will be notified by email.', 'matrix-mlm'); ?>
        </div>

        <div class="matrix-info-box">
            <p><strong><?php esc_html_e('Source:', 'matrix-mlm'); ?></strong> <?php esc_html_e('Matrix Wallet', 'matrix-mlm'); ?> &mdash; <?php echo esc_html($currency . number_format($matrix_balance, 2)); ?></p>
            <p><strong><?php esc_html_e('Minimum Transfer:', 'matrix-mlm'); ?></strong> <?php echo esc_html($currency . number_format($min_transfer, 2)); ?></p>
            <p><strong><?php esc_html_e('Service Charge:', 'matrix-mlm'); ?></strong> <?php echo esc_html($charge_type === 'percent' ? $charge_value . '%' : $currency . number_format($charge_value, 2)); ?></p>
        </div>

        <div class="matrix-form-card">
            <form id="matrix-w2w-form" class="matrix-form">
                <div class="matrix-form-group">
                    <label><?php esc_html_e('Recipient Username', 'matrix-mlm'); ?></label>
                    <input type="text" name="recipient" id="matrix-w2w-recipient"
                           required autocomplete="off"
                           placeholder="<?php esc_attr_e('Enter the recipient\'s username', 'matrix-mlm'); ?>">
                </div>

                <div class="matrix-form-group">
                    <label><?php esc_html_e('Amount', 'matrix-mlm'); ?> (<?php echo esc_html($currency); ?>)</label>
                    <input type="number" name="amount" id="matrix-w2w-amount"
                           min="<?php echo esc_attr($min_transfer); ?>"
                           max="<?php echo esc_attr($effective_max); ?>"
                           step="0.01" required
                           placeholder="<?php echo esc_attr(sprintf(__('Min %s, Max %s', 'matrix-mlm'), number_format($min_transfer), number_format($effective_max, 2))); ?>">
                    <div id="matrix-w2w-charge-info" class="matrix-charge-info" style="display:none;">
                        <small>
                            <?php esc_html_e('Charge:', 'matrix-mlm'); ?> <span id="matrix-w2w-charge">-</span> |
                            <?php esc_html_e('Total Debit:', 'matrix-mlm'); ?> <span id="matrix-w2w-total">-</span> |
                            <?php esc_html_e('Recipient Receives:', 'matrix-mlm'); ?> <span id="matrix-w2w-receive">-</span>
                        </small>
                    </div>
                </div>

                <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block" id="matrix-w2w-submit-btn">
                    <?php esc_html_e('Send Transfer', 'matrix-mlm'); ?>
                </button>
            </form>
        </div>

        <?php if (!empty($transfers)): ?>
        <h4 class="matrix-wallet-history-heading"><?php esc_html_e('Recent Wallet Transfers', 'matrix-mlm'); ?></h4>
        <table class="matrix-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Date', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Direction', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('User', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Amount', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Charge', 'matrix-mlm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transfers as $t):
                    $is_sender = ((int) $t->from_user_id === (int) $user_id);
                ?>
                <tr>
                    <td><?php echo esc_html(date('M d, Y H:i', strtotime($t->created_at))); ?></td>
                    <td>
                        <?php if ($is_sender): ?>
                            <span class="matrix-badge matrix-badge-debit"><?php esc_html_e('Sent', 'matrix-mlm'); ?></span>
                        <?php else: ?>
                            <span class="matrix-badge matrix-badge-credit"><?php esc_html_e('Received', 'matrix-mlm'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($is_sender ? ($t->to_username ?? '—') : ($t->from_username ?? '—')); ?></td>
                    <td><?php echo esc_html($currency . number_format($t->amount, 2)); ?></td>
                    <td><?php echo esc_html($is_sender ? $currency . number_format($t->charge, 2) : '—'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Inline styles. Kept in this class (rather than the plugin's
     * shared CSS) because they're scoped to the Wallet page only and
     * tightly coupled to the markup above — moving them out would just
     * add a remote-fetch round-trip for no maintenance benefit.
     */
    private function render_base_styles() {
        ?>
        <style>
        .matrix-subtitle { color: #6b7280; margin: -10px 0 20px; font-size: 14px; }

        .matrix-wallet-overview {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }
        @media (max-width: 768px) {
            .matrix-wallet-overview { grid-template-columns: 1fr; }
        }
        .matrix-wallet-card {
            border-radius: 16px;
            padding: 24px 28px;
            color: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15);
        }
        .matrix-wallet-card-virtual {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 100%);
        }
        .matrix-wallet-card-matrix {
            background: linear-gradient(135deg, #312e81 0%, #4338ca 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .matrix-wallet-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 250px;
            height: 250px;
            background: rgba(255,255,255,0.06);
            border-radius: 50%;
        }
        .matrix-wallet-card > * { position: relative; z-index: 1; }

        .matrix-wallet-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .matrix-wallet-card-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.75);
        }
        .matrix-wallet-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            background: rgba(255,255,255,0.18);
        }
        .matrix-wallet-status-active { background: rgba(16, 185, 129, 0.35); }

        .matrix-wallet-account-number {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 2px;
            font-family: 'Courier New', monospace;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .matrix-wallet-copy-btn,
        .matrix-wallet-refresh-btn {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: #fff;
            padding: 4px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 11px;
            font-family: system-ui, sans-serif;
            letter-spacing: 0.5px;
        }
        .matrix-wallet-copy-btn:hover,
        .matrix-wallet-refresh-btn:hover { background: rgba(255,255,255,0.25); }

        .matrix-wallet-balance-row {
            margin: 4px 0 16px;
            padding: 12px 0 14px;
            border-top: 1px solid rgba(255,255,255,0.15);
            border-bottom: 1px solid rgba(255,255,255,0.15);
        }
        .matrix-wallet-balance-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.75);
            margin-bottom: 4px;
        }
        .matrix-wallet-balance-value {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .matrix-wallet-balance-value-large { font-size: 32px; line-height: 1.1; margin-top: 8px; }

        .matrix-wallet-meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .matrix-wallet-meta-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.75);
            margin-bottom: 2px;
        }
        .matrix-wallet-meta-value { font-size: 14px; font-weight: 600; }

        .matrix-wallet-help-text {
            font-size: 12px;
            color: #fde68a;
            margin-top: 6px;
        }
        .matrix-wallet-help-text-light { color: rgba(255,255,255,0.8); margin-top: 8px; }
        .matrix-wallet-error-text {
            font-size: 12px;
            color: #fecaca;
            margin-top: 4px;
        }

        .matrix-set-wallet-id-form {
            margin-top: 10px;
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }
        .matrix-set-wallet-id-form input {
            flex: 1;
            min-width: 220px;
            padding: 6px 10px;
            border: 1px solid rgba(255,255,255,0.4);
            background: rgba(255,255,255,0.1);
            color: #fff;
            border-radius: 6px;
            font-size: 13px;
        }
        .matrix-set-wallet-id-form input::placeholder { color: rgba(255,255,255,0.5); }
        .matrix-set-wallet-id-form button {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: #fff;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
        }
        #matrix-fintava-set-wallet-id-status {
            display: block;
            width: 100%;
            font-size: 12px;
            margin-top: 4px;
        }

        .matrix-fintava-balance-debug {
            margin: 16px 0;
            padding: 12px 16px;
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 6px;
            font-size: 12px;
            color: #92400e;
        }
        .matrix-fintava-balance-debug summary { cursor: pointer; font-weight: 600; }
        .matrix-fintava-balance-debug pre {
            margin: 8px 0 0;
            padding: 10px;
            background: #fff;
            border: 1px solid #fcd34d;
            border-radius: 4px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
            font-family: Menlo, Consolas, monospace;
            font-size: 11px;
            color: #1f2937;
            line-height: 1.4;
        }

        .matrix-wallet-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        @media (max-width: 900px) {
            .matrix-wallet-actions { grid-template-columns: 1fr; }
        }
        .matrix-wallet-action-btn {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px 24px;
            background: #fff;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.15s ease;
            text-align: left;
            font-family: inherit;
        }
        .matrix-wallet-action-btn:hover {
            border-color: #4f46e5;
            background: #eef2ff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px -2px rgba(79,70,229,0.2);
        }
        .matrix-wallet-action-btn.is-active {
            border-color: #4f46e5;
            background: #eef2ff;
            box-shadow: 0 4px 12px -2px rgba(79,70,229,0.25);
        }
        .matrix-wallet-action-icon {
            font-size: 28px;
            width: 28px;
            height: 28px;
            color: #4f46e5;
            flex-shrink: 0;
        }
        .matrix-wallet-action-text { display: flex; flex-direction: column; gap: 2px; }
        .matrix-wallet-action-text strong {
            font-size: 15px;
            color: #1f2937;
        }
        .matrix-wallet-action-text small {
            font-size: 12px;
            color: #6b7280;
        }

        .matrix-wallet-pane {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .matrix-wallet-pane[hidden] { display: none; }
        .matrix-wallet-pane h3:first-child { margin-top: 0; }

        /* Transaction History is rendered as an always-visible
           top-level section, not a pane. It needs breathing room
           above the page action panes/buttons and a heading that
           reads as "section" rather than "card title". */
        .matrix-wallet-tx-history { margin-top: 32px; }
        .matrix-wallet-tx-history h2 {
            margin: 0 0 16px;
            font-size: 20px;
            color: #1f2937;
        }
        .matrix-wallet-tx-history .matrix-table { margin-top: 0; }

        .matrix-wallet-history-heading { margin-top: 24px; }

        .matrix-transfer-note {
            background: #fefce8;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
            font-size: 13px;
            color: #92400e;
        }
        .matrix-charge-info {
            margin-top: 6px;
            padding: 8px 12px;
            background: #f0fdf4;
            border-radius: 4px;
            border: 1px solid #bbf7d0;
        }
        .matrix-charge-info small { color: #166534; }
        </style>
        <?php
    }

    /**
     * Inline JS for tab toggling, balance refresh, wallet-id verify,
     * copy-to-clipboard, and the Matrix → Virtual transfer form. The
     * embedded Bank Payout pane brings its own JS (also inline) — there
     * are no shared variables across the two so they coexist cleanly.
     */
    private function render_scripts($wallet, $matrix_balance, $currency) {
        $charge_type  = get_option('matrix_mlm_fintava_charge_type', 'fixed');
        $charge_value = floatval(get_option('matrix_mlm_fintava_charge_value', 50));
        ?>
        <script>
        // Wait for jQuery to load before binding any handlers. Same
        // pattern the embedded bank-payout pane uses: this inline
        // <script> is printed in the body while parsing, but a theme
        // or optimizer plugin (Astra, GeneratePress, OceanWP, WP
        // Rocket, FlyingPress, Perfmatters, SG Optimizer, etc.) often
        // defers jQuery to the footer. In that window, calling
        // `(function($){...})(jQuery)` directly throws ReferenceError
        // immediately, the script aborts, and NONE of the handlers
        // below ever bind — which presents to the user as "I clicked
        // Transfer to Bank and absolutely nothing happened". Polling
        // window.jQuery for up to ~10s lets the footer-loaded path
        // succeed once jQuery actually arrives.
        (function() {
            var attempts = 0;
            var maxAttempts = 200; // 200 * 50ms = 10s ceiling

            function whenJQueryReady(cb) {
                if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn !== 'undefined') {
                    window.jQuery(cb);
                    return;
                }
                if (++attempts > maxAttempts) {
                    if (window.console && console.error) {
                        console.error('[Matrix MLM] jQuery not loaded after 10s; wallet page handlers not bound.');
                    }
                    return;
                }
                setTimeout(function() { whenJQueryReady(cb); }, 50);
            }

            whenJQueryReady(function($) {
            'use strict';

            // -----------------------------------------------------------
            // UI-only handlers (do NOT depend on matrixMLM).
            //
            // These bind FIRST and unconditionally so the page
            // chrome — action-button pane toggle, copy-to-clipboard —
            // works even when matrixMLM is missing or arrives late.
            // The previous revision returned early when matrixMLM was
            // undefined, which left the action buttons inert (clicks
            // did literally nothing) on any site whose optimizer
            // deferred matrix-mlm-public.js — exactly the
            // "nothing happens when I click Transfer to Bank" report.
            // AJAX-dependent handlers further down still gate on
            // matrixMLM, but they're now allowed to fail individually
            // instead of taking the entire page's JS down with them.
            // -----------------------------------------------------------

            // -----------------------------------------------------------
            // Action button → pane toggling.
            //
            // Click toggles the corresponding pane. Clicking the active
            // button collapses its pane (so the user can return to the
            // overview state). Clicking the other button switches panes.
            // -----------------------------------------------------------
            $('.matrix-wallet-action-btn').on('click', function() {
                var $btn    = $(this);
                var target  = $btn.data('target');
                var $pane   = $('.matrix-wallet-pane[data-pane="' + target + '"]');
                var wasOpen = $btn.hasClass('is-active');

                $('.matrix-wallet-action-btn').removeClass('is-active');
                $('.matrix-wallet-pane').attr('hidden', true);

                if (!wasOpen) {
                    $btn.addClass('is-active');
                    $pane.removeAttr('hidden');
                    // Smooth-scroll the pane into view on small screens
                    // where the form might otherwise sit below the fold.
                    if (window.innerWidth < 900) {
                        var top = $pane.offset().top - 20;
                        $('html, body').animate({ scrollTop: top }, 250);
                    }
                }
            });

            // -----------------------------------------------------------
            // Copy account number to clipboard.
            // -----------------------------------------------------------
            $('.matrix-wallet-copy-btn').on('click', function() {
                var $btn = $(this);
                var text = $btn.data('clipboard');
                if (!text) { return; }
                var done = function() {
                    var original = $btn.text();
                    $btn.text('<?php echo esc_js(__('Copied!', 'matrix-mlm')); ?>');
                    setTimeout(function() { $btn.text(original); }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(String(text)).then(done, done);
                } else {
                    // Fallback for older browsers / non-secure contexts.
                    var $tmp = $('<input/>').val(String(text)).appendTo('body').select();
                    try { document.execCommand('copy'); } catch (e) {}
                    $tmp.remove();
                    done();
                }
            });

            // -----------------------------------------------------------
            // Everything below this point hits the WordPress AJAX
            // endpoint via matrixMLM.ajaxUrl/nonce. If the matrixMLM
            // global isn't available — usually because a caching
            // plugin dequeued matrix-mlm-public.js or the wp_footer
            // didn't run — disable the AJAX-dependent forms inline so
            // the user gets a visible "form is dead" affordance, then
            // bail out of binding the rest of the handlers. The
            // UI-only handlers above are already bound, so the action
            // buttons (and copy-to-clipboard) keep working regardless
            // — which means the user can still navigate the page even
            // when matrixMLM is unreachable.
            // -----------------------------------------------------------
            if (typeof matrixMLM === 'undefined' || !matrixMLM.ajaxUrl) {
                $('#matrix-transfer-to-own-wallet-form :input').prop('disabled', true);
                $('#matrix-w2w-form :input').prop('disabled', true);
                if (window.console && console.error) {
                    console.error('[Matrix MLM] matrixMLM global is not defined — wallet page AJAX handlers not bound.');
                }
                return;
            }

            // -----------------------------------------------------------
            // Refresh Fintava balance without reloading.
            // Reuses the existing matrix_fintava_wallet_balance handler.
            // -----------------------------------------------------------
            $('#matrix-fintava-refresh-balance').on('click', function() {
                var $btn = $(this);
                var $amt = $('#matrix-fintava-balance-amount');
                var orig = $btn.text();
                $btn.prop('disabled', true).text('…');

                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: { action: 'matrix_fintava_wallet_balance', nonce: matrixMLM.nonce },
                    success: function(res) {
                        $btn.prop('disabled', false).text(orig);
                        if (res && res.success && res.data && res.data.available_balance_formatted) {
                            $amt.text(res.data.available_balance_formatted);
                        } else {
                            var msg = (res && res.data && res.data.message)
                                ? res.data.message
                                : '<?php echo esc_js(__('Could not refresh balance.', 'matrix-mlm')); ?>';
                            alert(msg);
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text(orig);
                        alert('<?php echo esc_js(__('Network error refreshing balance.', 'matrix-mlm')); ?>');
                    }
                });
            });

            // -----------------------------------------------------------
            // Verify & Save missing Wallet ID.
            // The server-side handler refuses to save unless Fintava
            // confirms the wallet_id maps to the same account_number we
            // already have stored — so this is safe to expose to users.
            // -----------------------------------------------------------
            $('#matrix-fintava-set-wallet-id-btn').on('click', function() {
                var $btn    = $(this);
                var $input  = $('#matrix-fintava-wallet-id-input');
                var $status = $('#matrix-fintava-set-wallet-id-status');
                var id      = ($input.val() || '').trim();
                if (!id) {
                    $status.css('color', '#fecaca').text('<?php echo esc_js(__('Enter your Fintava Wallet ID first.', 'matrix-mlm')); ?>');
                    return;
                }
                var orig = $btn.text();
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Verifying…', 'matrix-mlm')); ?>');
                $status.css('color', '#a7f3d0').text('<?php echo esc_js(__('Calling Fintava…', 'matrix-mlm')); ?>');

                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: { action: 'matrix_fintava_set_my_wallet_id', nonce: matrixMLM.nonce, wallet_id: id },
                    success: function(res) {
                        $btn.prop('disabled', false).text(orig);
                        if (res && res.success) {
                            $status.css('color', '#a7f3d0').text((res.data && res.data.message) || '<?php echo esc_js(__('Saved.', 'matrix-mlm')); ?>');
                            setTimeout(function() { (typeof matrixMLMReload === "function" ? matrixMLMReload : function(){ window.location.reload(); })(); }, 600);
                        } else {
                            var err = (res && res.data && res.data.message)
                                ? res.data.message
                                : '<?php echo esc_js(__('Could not save Wallet ID.', 'matrix-mlm')); ?>';
                            $status.css('color', '#fecaca').text('✗ ' + err);
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text(orig);
                        $status.css('color', '#fecaca').text('<?php echo esc_js(__('Network error.', 'matrix-mlm')); ?>');
                    }
                });
            });

            // -----------------------------------------------------------
            // Matrix → Virtual transfer form.
            //
            // Fires the same matrix_fintava_initiate_transfer handler the
            // bank-payout form uses; the destination is just the user's
            // own virtual account (pre-filled below from server-side
            // wallet data), so Fintava's bank/credit lands on the user's
            // own wallet and the local Matrix balance is debited as the
            // bookkeeping side.
            // -----------------------------------------------------------
            var ownCurrency    = '<?php echo esc_js($currency); ?>';
            var ownChargeType  = '<?php echo esc_js($charge_type); ?>';
            var ownChargeValue = <?php echo floatval($charge_value); ?>;
            var ownBalance     = <?php echo floatval($matrix_balance); ?>;
            var ownAccountNum  = '<?php echo esc_js($wallet->account_number); ?>';
            var ownBankName    = '<?php echo esc_js($wallet->bank_name); ?>';
            var ownAccountName = '<?php echo esc_js($wallet->account_name); ?>';
            var ownBankCode    = '<?php echo esc_js($wallet->bank_code ?? ''); ?>';

            $('#matrix-own-wallet-amount').on('input', function() {
                var amount = parseFloat($(this).val()) || 0;
                if (amount > 0) {
                    var charge = ownChargeType === 'percent'
                        ? (amount * ownChargeValue / 100)
                        : ownChargeValue;
                    charge = Math.round(charge * 100) / 100;
                    var total = amount + charge;
                    $('#matrix-own-wallet-charge').text(ownCurrency + charge.toLocaleString());
                    $('#matrix-own-wallet-total').text(ownCurrency + total.toLocaleString());
                    $('#matrix-own-wallet-receive').text(ownCurrency + amount.toLocaleString());
                    $('#matrix-own-wallet-charge-info').show();
                } else {
                    $('#matrix-own-wallet-charge-info').hide();
                }
            });

            $('#matrix-transfer-to-own-wallet-form').on('submit', function(e) {
                e.preventDefault();

                var amount = parseFloat($('#matrix-own-wallet-amount').val());
                if (!amount || amount <= 0) {
                    alert('<?php echo esc_js(__('Please enter a valid amount.', 'matrix-mlm')); ?>');
                    return;
                }
                var charge = ownChargeType === 'percent'
                    ? (amount * ownChargeValue / 100)
                    : ownChargeValue;
                var total = amount + charge;
                if (total > ownBalance) {
                    alert('<?php echo esc_js(__('Insufficient Matrix wallet balance.', 'matrix-mlm')); ?>');
                    return;
                }
                if (!confirm('<?php echo esc_js(__('Transfer', 'matrix-mlm')); ?> ' + ownCurrency + amount.toLocaleString() + ' <?php echo esc_js(__('from your Matrix wallet to your Virtual account?', 'matrix-mlm')); ?>\n\n<?php echo esc_js(__('Charge:', 'matrix-mlm')); ?> ' + ownCurrency + charge.toFixed(2) + '\n<?php echo esc_js(__('Total Debit:', 'matrix-mlm')); ?> ' + ownCurrency + total.toFixed(2))) {
                    return;
                }

                var $btn = $('#matrix-own-wallet-submit-btn');
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Processing…', 'matrix-mlm')); ?>');

                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: {
                        action:         'matrix_fintava_initiate_transfer',
                        nonce:          matrixMLM.nonce,
                        amount:         amount,
                        account_number: ownAccountNum,
                        bank_code:      ownBankCode,
                        bank_name:      ownBankName,
                        account_name:   ownAccountName,
                        narration:      '<?php echo esc_js(__('Matrix to Virtual wallet transfer', 'matrix-mlm')); ?>'
                    },
                    success: function(res) {
                        if (res && res.success) {
                            alert((res.data && res.data.message) || '<?php echo esc_js(__('Transfer successful.', 'matrix-mlm')); ?>');
                            (typeof matrixMLMReload === "function" ? matrixMLMReload : function(){ window.location.reload(); })();
                        } else {
                            alert((res && res.data && res.data.message) || '<?php echo esc_js(__('Transfer failed.', 'matrix-mlm')); ?>');
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Transfer to Own Wallet', 'matrix-mlm')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error. Please try again.', 'matrix-mlm')); ?>');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Transfer to Own Wallet', 'matrix-mlm')); ?>');
                    }
                });
            });

            // -----------------------------------------------------------
            // Wallet → Wallet transfer (internal user-to-user).
            //
            // Hits the same matrix_mlm_action endpoint the legacy
            // Balance Transfer page used, with matrix_action=transfer.
            // The server-side handler (Matrix_MLM_Core::process_transfer)
            // does the username lookup, balance check, debit/credit,
            // matrix_transfers row insert, and recipient email
            // notification — we don't replicate any of that here.
            //
            // Form ID is #matrix-w2w-form (not the legacy
            // #matrix-transfer-form) so the global delegated submit
            // handler in public/js/matrix-public.js does NOT also fire.
            // The legacy handler binds to '#matrix-transfer-form' on
            // document; sharing that ID would double-submit every
            // transfer.
            // -----------------------------------------------------------
            var w2wMin         = <?php echo floatval(get_option('matrix_mlm_min_transfer', 500)); ?>;
            var w2wChargeType  = '<?php echo esc_js(get_option('matrix_mlm_transfer_charge_type', 'fixed')); ?>';
            var w2wChargeValue = <?php echo floatval(get_option('matrix_mlm_transfer_charge', 100)); ?>;
            var w2wBalance     = <?php echo floatval($matrix_balance); ?>;

            // Live charge preview as the user types the amount. Same
            // visual contract as the other two transfer forms on this
            // page — Charge | Total Debit | Recipient Receives.
            $('#matrix-w2w-amount').on('input', function() {
                var amount = parseFloat($(this).val()) || 0;
                if (amount > 0) {
                    var charge = w2wChargeType === 'percent'
                        ? (amount * w2wChargeValue / 100)
                        : w2wChargeValue;
                    charge = Math.round(charge * 100) / 100;
                    var total = amount + charge;
                    $('#matrix-w2w-charge').text(ownCurrency + charge.toLocaleString());
                    $('#matrix-w2w-total').text(ownCurrency + total.toLocaleString());
                    $('#matrix-w2w-receive').text(ownCurrency + amount.toLocaleString());
                    $('#matrix-w2w-charge-info').show();
                } else {
                    $('#matrix-w2w-charge-info').hide();
                }
            });

            $('#matrix-w2w-form').on('submit', function(e) {
                e.preventDefault();

                var recipient = ($('#matrix-w2w-recipient').val() || '').trim();
                var amount    = parseFloat($('#matrix-w2w-amount').val());

                if (!recipient) {
                    alert('<?php echo esc_js(__('Please enter the recipient\'s username.', 'matrix-mlm')); ?>');
                    return;
                }
                if (!amount || amount < w2wMin) {
                    alert('<?php echo esc_js(__('Amount must be at least the minimum transfer.', 'matrix-mlm')); ?>');
                    return;
                }

                var charge = w2wChargeType === 'percent'
                    ? (amount * w2wChargeValue / 100)
                    : w2wChargeValue;
                charge = Math.round(charge * 100) / 100;
                var total = amount + charge;
                if (total > w2wBalance) {
                    alert('<?php echo esc_js(__('Insufficient Matrix wallet balance for amount + charge.', 'matrix-mlm')); ?>');
                    return;
                }

                if (!confirm('<?php echo esc_js(__('Send', 'matrix-mlm')); ?> ' + ownCurrency + amount.toLocaleString() + ' <?php echo esc_js(__('to', 'matrix-mlm')); ?> ' + recipient + '?\n\n<?php echo esc_js(__('Charge:', 'matrix-mlm')); ?> ' + ownCurrency + charge.toFixed(2) + '\n<?php echo esc_js(__('Total Debit:', 'matrix-mlm')); ?> ' + ownCurrency + total.toFixed(2))) {
                    return;
                }

                var $btn = $('#matrix-w2w-submit-btn');
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Processing…', 'matrix-mlm')); ?>');

                $.ajax({
                    url:  matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: {
                        action:        'matrix_mlm_action',
                        nonce:         matrixMLM.nonce,
                        matrix_action: 'transfer',
                        recipient:     recipient,
                        amount:        amount
                    },
                    success: function(res) {
                        if (res && res.success) {
                            alert((res.data && res.data.message) || '<?php echo esc_js(__('Transfer successful.', 'matrix-mlm')); ?>');
                            (typeof matrixMLMReload === "function" ? matrixMLMReload : function(){ window.location.reload(); })();
                        } else {
                            alert((res && res.data && res.data.message) || '<?php echo esc_js(__('Transfer failed.', 'matrix-mlm')); ?>');
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Send Transfer', 'matrix-mlm')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error. Please try again.', 'matrix-mlm')); ?>');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Send Transfer', 'matrix-mlm')); ?>');
                    }
                });
            });

            }); // whenJQueryReady
        })(); // poll-for-jQuery IIFE
        </script>
        <?php
    }
}
