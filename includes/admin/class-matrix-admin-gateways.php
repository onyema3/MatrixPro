<?php
/**
 * Admin Payment Gateways Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Gateways {

    /**
     * Default gateway definitions used for seeding.
     */
    private static function default_gateways() {
        return [
            [
                'name' => 'Paystack',
                'slug' => 'paystack',
                'gateway_parameters' => json_encode([
                    'public_key' => '',
                    'secret_key' => '',
                    'webhook_secret' => ''
                ]),
                'supported_currencies' => json_encode(['NGN', 'GHS', 'ZAR', 'USD']),
                'min_amount' => 100.00,
                'max_amount' => 5000000.00,
                'fixed_charge' => 0.00,
                'percent_charge' => 1.50,
                'status' => 1,
            ],
            [
                'name' => 'Flutterwave',
                'slug' => 'flutterwave',
                'gateway_parameters' => json_encode([
                    'public_key' => '',
                    'secret_key' => '',
                    'encryption_key' => '',
                    'webhook_hash' => ''
                ]),
                'supported_currencies' => json_encode(['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'GBP', 'EUR']),
                'min_amount' => 100.00,
                'max_amount' => 10000000.00,
                'fixed_charge' => 0.00,
                'percent_charge' => 1.40,
                'status' => 1,
            ],
        ];
    }

    /**
     * Ensure the gateways table exists and contains the default gateways.
     * Idempotent: safe to call on every page load.
     * Returns array with 'inserted' count, 'skipped' count, and any 'errors'.
     */
    public static function ensure_default_gateways() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_gateways';
        $result = ['inserted' => 0, 'skipped' => 0, 'errors' => []];

        // Make sure the table exists; if not, create it directly with a clean
        // CREATE TABLE IF NOT EXISTS. We bypass dbDelta because the original
        // schema has inline UNIQUE constraints that dbDelta cannot handle.
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            $create_sql = "CREATE TABLE IF NOT EXISTS `$table` (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name varchar(100) NOT NULL,
                slug varchar(50) NOT NULL,
                gateway_parameters text,
                supported_currencies text,
                min_amount decimal(12,2) NOT NULL DEFAULT 0.00,
                max_amount decimal(12,2) NOT NULL DEFAULT 999999.99,
                fixed_charge decimal(12,2) NOT NULL DEFAULT 0.00,
                percent_charge decimal(5,2) NOT NULL DEFAULT 0.00,
                status tinyint(1) NOT NULL DEFAULT 1,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY slug (slug)
            ) $charset_collate";

            // Suppress wpdb's error display for this query so we can capture it.
            $previous_show_errors = $wpdb->show_errors;
            $wpdb->hide_errors();
            $wpdb->query($create_sql);
            if ($previous_show_errors) {
                $wpdb->show_errors();
            }

            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if (!$table_exists) {
                $result['errors'][] = sprintf(
                    __('Gateways table %s could not be created. SQL error: %s', 'matrix-mlm'),
                    $table,
                    $wpdb->last_error ?: __('unknown error (check database user CREATE permissions)', 'matrix-mlm')
                );
                return $result;
            }
        }

        // Format strings matching the table column types.
        // name, slug, gateway_parameters, supported_currencies = %s
        // min/max/fixed/percent = %f
        // status = %d
        $format = ['%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%d'];

        foreach (self::default_gateways() as $gw) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE slug = %s",
                $gw['slug']
            ));

            if ($existing) {
                $result['skipped']++;
                continue;
            }

            $inserted = $wpdb->insert($table, $gw, $format);
            if ($inserted === false) {
                $result['errors'][] = sprintf(
                    __('Failed to insert %s: %s', 'matrix-mlm'),
                    $gw['name'],
                    $wpdb->last_error ?: __('unknown error', 'matrix-mlm')
                );
            } else {
                $result['inserted']++;
            }
        }

        return $result;
    }

    public function render() {
        global $wpdb;

        // Handle form submissions BEFORE seeding so saves take precedence.
        if (isset($_POST['save_gateway']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_save_gateway')) {
            $this->save_gateway();
        }

        if (isset($_POST['save_fintava_gateway']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_save_fintava_gateway')) {
            $this->save_fintava_settings();
        }

        // Auto-seed default gateways on every page load. This is idempotent
        // (skips any slug that already exists) and self-healing — if the table
        // is empty for any reason, the gateways will be inserted right here
        // before the page is rendered.
        $seed_result = self::ensure_default_gateways();

        if ($seed_result['inserted'] > 0) {
            echo '<div class="notice notice-success"><p>' . sprintf(
                __('%d default payment gateway(s) created.', 'matrix-mlm'),
                $seed_result['inserted']
            ) . '</p></div>';
        }

        if (!empty($seed_result['errors'])) {
            foreach ($seed_result['errors'] as $err) {
                echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
            }
        }

        $gateways = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}matrix_gateways ORDER BY name ASC");
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Payment Gateways', 'matrix-mlm'); ?></h1>

            <?php if (empty($gateways)): ?>
                <div class="matrix-admin-card">
                    <h2><?php _e('No Payment Gateways Available', 'matrix-mlm'); ?></h2>
                    <p><?php _e('The default gateways could not be created automatically. Check your database permissions and the messages above for details.', 'matrix-mlm'); ?></p>
                </div>
            <?php endif; ?>

            <?php foreach ($gateways as $gateway):
                $params = json_decode($gateway->gateway_parameters, true);
                if (!is_array($params)) {
                    $params = [];
                }
                $currencies = json_decode($gateway->supported_currencies, true);
                if (!is_array($currencies)) {
                    $currencies = [];
                }
            ?>
                <div class="matrix-admin-card" style="margin-bottom: 20px;">
                    <h2>
                        <?php echo esc_html($gateway->name); ?>
                        <span class="matrix-badge matrix-badge-<?php echo $gateway->status ? 'active' : 'inactive'; ?>" style="font-size: 12px;">
                            <?php echo $gateway->status ? __('Active', 'matrix-mlm') : __('Inactive', 'matrix-mlm'); ?>
                        </span>
                    </h2>
                    <form method="post">
                        <?php wp_nonce_field('matrix_save_gateway'); ?>
                        <input type="hidden" name="gateway_id" value="<?php echo $gateway->id; ?>">
                        <input type="hidden" name="gateway_slug" value="<?php echo esc_attr($gateway->slug); ?>">

                        <table class="form-table">
                            <?php $this->render_gateway_fields($gateway->slug, $params); ?>

                            <tr>
                                <th><?php _e('Supported Currencies', 'matrix-mlm'); ?></th>
                                <td>
                                    <input type="text" name="supported_currencies" class="regular-text" value="<?php echo esc_attr(implode(', ', $currencies)); ?>">
                                    <p class="description"><?php _e('Comma-separated list of currency codes (e.g., NGN, USD, GHS)', 'matrix-mlm'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Min Amount', 'matrix-mlm'); ?></th>
                                <td><input type="number" name="min_amount" step="0.01" class="regular-text" value="<?php echo esc_attr($gateway->min_amount); ?>"></td>
                            </tr>
                            <tr>
                                <th><?php _e('Max Amount', 'matrix-mlm'); ?></th>
                                <td><input type="number" name="max_amount" step="0.01" class="regular-text" value="<?php echo esc_attr($gateway->max_amount); ?>"></td>
                            </tr>
                            <tr>
                                <th><?php _e('Fixed Charge', 'matrix-mlm'); ?></th>
                                <td><input type="number" name="fixed_charge" step="0.01" class="regular-text" value="<?php echo esc_attr($gateway->fixed_charge); ?>"></td>
                            </tr>
                            <tr>
                                <th><?php _e('Percent Charge (%)', 'matrix-mlm'); ?></th>
                                <td><input type="number" name="percent_charge" step="0.01" class="regular-text" value="<?php echo esc_attr($gateway->percent_charge); ?>"></td>
                            </tr>
                            <tr>
                                <th><?php _e('Status', 'matrix-mlm'); ?></th>
                                <td>
                                    <select name="status">
                                        <option value="1" <?php selected($gateway->status, 1); ?>><?php _e('Active', 'matrix-mlm'); ?></option>
                                        <option value="0" <?php selected($gateway->status, 0); ?>><?php _e('Inactive', 'matrix-mlm'); ?></option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <p><input type="submit" name="save_gateway" class="button button-primary" value="<?php _e('Save Settings', 'matrix-mlm'); ?>"></p>
                    </form>
                </div>
            <?php endforeach; ?>

            <!-- Fintava Pay Settings (stored in wp_options) -->
            <div class="matrix-admin-card" style="margin-bottom: 20px;">
                <h2>
                    <?php _e('Fintava Pay (Payouts & Virtual Wallet)', 'matrix-mlm'); ?>
                    <span class="matrix-badge matrix-badge-<?php echo get_option('matrix_mlm_fintava_status', 0) ? 'active' : 'inactive'; ?>" style="font-size: 12px;">
                        <?php echo get_option('matrix_mlm_fintava_status', 0) ? __('Active', 'matrix-mlm') : __('Inactive', 'matrix-mlm'); ?>
                    </span>
                    <?php
                    // Surface the active environment next to the status badge so
                    // admins can spot a Dev/Live mismatch at a glance.
                    $fintava_env = Matrix_MLM_Fintava::get_environment();
                    $env_label   = $fintava_env === 'dev'    ? __('DEV', 'matrix-mlm')
                                 : ($fintava_env === 'custom' ? __('CUSTOM', 'matrix-mlm')
                                 : __('LIVE', 'matrix-mlm'));
                    $env_class   = $fintava_env === 'live' ? 'active' : 'inactive';
                    ?>
                    <span class="matrix-badge matrix-badge-<?php echo esc_attr($env_class); ?>" style="font-size: 12px;" title="<?php esc_attr_e('Currently selected Fintava API environment', 'matrix-mlm'); ?>">
                        <?php echo esc_html($env_label); ?>
                    </span>
                </h2>
                <p class="description" style="margin-bottom: 15px;">
                    <?php _e('Fintava Pay powers bank payouts and virtual wallet generation. Paste your API Key from your Fintava dashboard below and pick the matching environment.', 'matrix-mlm'); ?>
                </p>
                <form method="post">
                    <?php wp_nonce_field('matrix_save_fintava_gateway'); ?>
                    <table class="form-table">
                        <tr>
                            <th><?php _e('Environment', 'matrix-mlm'); ?></th>
                            <td>
                                <?php
                                $current_env = strtolower(trim((string) get_option('matrix_mlm_fintava_environment', 'live')));
                                if ($current_env !== 'dev') {
                                    $current_env = 'live';
                                }
                                $is_overridden = defined('MATRIX_FINTAVA_API_BASE_URL') && MATRIX_FINTAVA_API_BASE_URL;
                                ?>
                                <select name="fintava_environment" <?php disabled($is_overridden); ?>>
                                    <option value="live" <?php selected($current_env, 'live'); ?>>
                                        <?php _e('Live (production)', 'matrix-mlm'); ?> &mdash; <?php echo esc_html(Matrix_MLM_Fintava::LIVE_BASE_URL); ?>
                                    </option>
                                    <option value="dev" <?php selected($current_env, 'dev'); ?>>
                                        <?php _e('Dev (sandbox)', 'matrix-mlm'); ?> &mdash; <?php echo esc_html(Matrix_MLM_Fintava::DEV_BASE_URL); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php if ($is_overridden): ?>
                                        <strong><?php _e('Override active:', 'matrix-mlm'); ?></strong>
                                        <?php
                                        printf(
                                            /* translators: %s: full URL pinned via the wp-config.php constant. */
                                            esc_html__('this dropdown is ignored because MATRIX_FINTAVA_API_BASE_URL is set to %s in wp-config.php. Remove that constant to manage the environment from this page.', 'matrix-mlm'),
                                            '<code>' . esc_html(MATRIX_FINTAVA_API_BASE_URL) . '</code>'
                                        );
                                        ?>
                                    <?php else: ?>
                                        <?php _e('Pick the environment that matches the API Key below. Live keys do not work on the Dev URL and vice versa &mdash; that mismatch is the most common source of "Invalid API Key" errors.', 'matrix-mlm'); ?>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('API Key', 'matrix-mlm'); ?></th>
                            <td>
                                <input type="password" name="fintava_secret_key" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_secret_key', '')); ?>" autocomplete="off">
                                <p class="description"><?php _e('Bearer token used for all Fintava API calls. Find it under Settings &rarr; API Keys &amp; Webhooks in your Fintava dashboard. Use the key that matches the environment selected above.', 'matrix-mlm'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Merchant ID', 'matrix-mlm'); ?></th>
                            <td>
                                <input type="text" name="fintava_merchant_id" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_merchant_id', Matrix_MLM_Fintava::DEFAULT_MERCHANT_ID)); ?>">
                                <p class="description"><?php _e('Your Fintava Merchant ID (UUID). Sent as the Merchant-Id header with every API request.', 'matrix-mlm'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Operating Account Number', 'matrix-mlm'); ?></th>
                            <td>
                                <input type="text" name="fintava_operating_account" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_operating_account', '')); ?>" inputmode="numeric" pattern="[0-9]{6,20}" maxlength="20" autocomplete="off">
                                <p class="description"><?php _e('NUBAN of the merchant Fintava wallet that funds Matrix &rarr; Virtual transfers. This is the <code>senderAccount</code> on Fintava\'s wallet-to-wallet endpoint &mdash; not the merchant UUID. Pre-fund this wallet so internal transfers can settle.', 'matrix-mlm'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Callback / Webhook URL', 'matrix-mlm'); ?></th>
                            <td>
                                <input type="url" name="fintava_callback_url" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_callback_url', Matrix_MLM_Fintava::DEFAULT_CALLBACK_URL)); ?>">
                                <p class="description"><?php _e('The URL Fintava will POST webhook events to. Set this same URL in your Fintava dashboard under Webhooks.', 'matrix-mlm'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Webhook Secret', 'matrix-mlm'); ?></th>
                            <td>
                                <input type="text" name="fintava_webhook_secret" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_webhook_secret', '')); ?>">
                                <p class="description">
                                    <?php _e('Optional but recommended &mdash; used to verify webhook signatures.', 'matrix-mlm'); ?><br>
                                    <?php _e('Webhook URL:', 'matrix-mlm'); ?> <code><?php echo esc_html(get_option('matrix_mlm_fintava_callback_url', Matrix_MLM_Fintava::DEFAULT_CALLBACK_URL)); ?></code>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Status', 'matrix-mlm'); ?></th>
                            <td>
                                <select name="fintava_status">
                                    <option value="1" <?php selected(get_option('matrix_mlm_fintava_status', 0), 1); ?>><?php _e('Active', 'matrix-mlm'); ?></option>
                                    <option value="0" <?php selected(get_option('matrix_mlm_fintava_status', 0), 0); ?>><?php _e('Inactive', 'matrix-mlm'); ?></option>
                                </select>
                                <p class="description"><?php _e('Master switch for the Fintava integration. When inactive, the entire Fintava feature set is disabled — virtual wallet display, Matrix → Virtual transfer, Transfer to Bank, and bills.', 'matrix-mlm'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Bank Payouts (Transfer to Bank)', 'matrix-mlm'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="fintava_payouts_enabled" value="1" <?php checked((int) get_option('matrix_mlm_fintava_payouts_enabled', 1), 1); ?>>
                                    <?php _e('Allow users to transfer from their Fintava virtual wallet to external Nigerian bank accounts.', 'matrix-mlm'); ?>
                                </label>
                                <p class="description">
                                    <?php
                                    /*
                                     * Dedicated toggle for the "Transfer to Bank" pane on
                                     * the Wallet page (Fintava virtual wallet → external bank
                                     * via /bank/credit). Independent from the master Status
                                     * select above and from the global "Allow users to
                                     * withdraw funds" toggle on Settings → Financial.
                                     *
                                     * When OFF: the Transfer to Bank button + pane disappear
                                     * from the Wallet page, and Matrix_MLM_Fintava::ajax_initiate_transfer
                                     * short-circuits with "Bank transfers via Fintava are
                                     * currently disabled" as a defence-in-depth gate.
                                     * Other Fintava features (virtual wallet display, Matrix →
                                     * Virtual transfer, bills, balance refresh) continue to
                                     * work normally — that's the whole point of having a
                                     * dedicated toggle instead of using the master Status
                                     * select for this.
                                     *
                                     * When the global Withdrawal Controls master kill switch
                                     * (matrix_mlm_withdrawals_enabled) is OFF, this gate is
                                     * effectively redundant — can_withdraw() blocks every
                                     * money-out path including bank payouts. The reverse is
                                     * not true: turning Bank Payouts off here leaves the
                                     * matrix-to-virtual transfer pane usable, so admins can
                                     * disable just the external cash-out without freezing
                                     * the rest of the platform.
                                     */
                                    _e('Toggle this off to keep the Fintava integration available (virtual wallet, Matrix → Virtual transfer) while blocking only the external bank-transfer pane. The Matrix Transfers flow stays available either way, so users who need to cash out can still send funds straight from their Matrix wallet to a bank account.', 'matrix-mlm');
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p><input type="submit" name="save_fintava_gateway" class="button button-primary" value="<?php _e('Save Fintava Settings', 'matrix-mlm'); ?>"></p>
                </form>

                <?php
                // TEMPORARY admin-only diagnostic. Renders the raw
                // /merchant/balance and /banks responses Fintava is currently
                // returning so an operator can confirm exactly which fields
                // exist and what they contain — without needing access to
                // server logs. Pairs with
                // Matrix_MLM_Fintava::debug_merchant_balance_raw() and
                // ::debug_banks_raw(). Gated to manage_matrix_mlm so it
                // never leaks for ordinary admins.
                //
                // Hidden behind ?fintava_diag=1 so the call is opt-in (each
                // dump fires a real Fintava API request, costing one round
                // trip per page render). When the link is followed the
                // <details> element renders open by default so the operator
                // doesn't have to expand twice.
                if (current_user_can('manage_matrix_mlm') && !empty(get_option('matrix_mlm_fintava_secret_key', ''))):
                    $diag_requested = !empty($_GET['fintava_diag']);
                    $diag_url = add_query_arg('fintava_diag', '1', remove_query_arg('fintava_diag'));
                    $fintava_for_diag = new Matrix_MLM_Fintava();
                ?>
                <div style="margin-top: 24px; padding: 16px; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 6px;">
                    <h3 style="margin: 0 0 8px; color: #92400e; font-size: 14px;">
                        <?php _e('Fintava Diagnostics (admin-only)', 'matrix-mlm'); ?>
                    </h3>
                    <p style="margin: 0 0 8px; font-size: 12px; color: #78350f;">
                        <?php _e('Use these dumps to confirm the exact response shape Fintava is returning today, then send the output to support if balances or the bank list look wrong.', 'matrix-mlm'); ?>
                    </p>
                    <p style="margin: 0;">
                        <a href="<?php echo esc_url($diag_url); ?>" class="button button-secondary">
                            <?php echo $diag_requested ? esc_html__('Refresh diagnostics', 'matrix-mlm') : esc_html__('Run diagnostics', 'matrix-mlm'); ?>
                        </a>
                    </p>

                    <?php if ($diag_requested): ?>
                        <?php
                        $balance_raw = $fintava_for_diag->debug_merchant_balance_raw();
                        $banks_raw   = $fintava_for_diag->debug_banks_raw();
                        $balance_json = wp_json_encode($balance_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $banks_json   = wp_json_encode($banks_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        ?>
                        <details open style="margin-top: 12px;">
                            <summary style="cursor:pointer;font-weight:600;color:#92400e;font-size:13px;">
                                <?php esc_html_e('GET /merchant/balance — raw response', 'matrix-mlm'); ?>
                            </summary>
                            <pre style="margin:8px 0 0;padding:10px;background:#fff;border:1px solid #fcd34d;border-radius:4px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;font-family:Menlo,Consolas,monospace;font-size:11px;color:#1f2937;line-height:1.4;"><?php echo esc_html($balance_json); ?></pre>
                        </details>
                        <details style="margin-top: 12px;">
                            <summary style="cursor:pointer;font-weight:600;color:#92400e;font-size:13px;">
                                <?php esc_html_e('GET /banks — raw response', 'matrix-mlm'); ?>
                            </summary>
                            <pre style="margin:8px 0 0;padding:10px;background:#fff;border:1px solid #fcd34d;border-radius:4px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;font-family:Menlo,Consolas,monospace;font-size:11px;color:#1f2937;line-height:1.4;"><?php echo esc_html($banks_json); ?></pre>
                        </details>
                    <?php endif; ?>

                    <?php
                    // /name/enquiry test panel. Decoupled from the
                    // ?fintava_diag=1 toggle above because this one needs
                    // operator input (account number + bank code) — the
                    // balance/banks dump only needs a click. Submitting
                    // this form re-renders the page with the dump open
                    // and the inputs preserved, so the operator can
                    // iterate without losing what they typed.
                    $ne_acct      = isset($_POST['matrix_ne_account']) ? sanitize_text_field(wp_unslash($_POST['matrix_ne_account'])) : '';
                    $ne_bank      = isset($_POST['matrix_ne_bank'])    ? sanitize_text_field(wp_unslash($_POST['matrix_ne_bank']))    : '';
                    $ne_submitted = isset($_POST['matrix_run_name_enquiry'])
                        && check_admin_referer('matrix_fintava_name_enquiry_diag', 'matrix_ne_nonce');
                    ?>
                    <div style="margin-top: 16px; padding-top: 12px; border-top: 1px dashed #f59e0b;">
                        <h4 style="margin: 0 0 8px; color: #92400e; font-size: 13px;">
                            <?php esc_html_e('GET /name/enquiry — test bank account verification', 'matrix-mlm'); ?>
                        </h4>
                        <p style="margin: 0 0 8px; font-size: 12px; color: #78350f;">
                            <?php esc_html_e('Paste a real 10-digit Nigerian bank account number and the matching bank code (3-digit CBN like 044 = Access, or the 6-digit NIBSS sortCode Fintava returns from /banks). Submitting will hit Fintava once and dump the raw response. This is the same call the bank-payout form makes when verifying an account.', 'matrix-mlm'); ?>
                        </p>
                        <form method="post" style="margin-bottom: 12px;">
                            <?php wp_nonce_field('matrix_fintava_name_enquiry_diag', 'matrix_ne_nonce'); ?>
                            <input type="text" name="matrix_ne_account" maxlength="10" pattern="\d{10}" required
                                   value="<?php echo esc_attr($ne_acct); ?>"
                                   placeholder="<?php esc_attr_e('10-digit account number', 'matrix-mlm'); ?>"
                                   style="width: 200px; margin-right: 8px;">
                            <input type="text" name="matrix_ne_bank" maxlength="10" required
                                   value="<?php echo esc_attr($ne_bank); ?>"
                                   placeholder="<?php esc_attr_e('Bank code (e.g. 044)', 'matrix-mlm'); ?>"
                                   style="width: 180px; margin-right: 8px;">
                            <input type="submit" name="matrix_run_name_enquiry" class="button button-secondary"
                                   value="<?php esc_attr_e('Run name enquiry', 'matrix-mlm'); ?>">
                        </form>
                        <?php if ($ne_submitted && $ne_acct !== '' && $ne_bank !== ''): ?>
                            <?php
                            $ne_raw  = $fintava_for_diag->debug_name_enquiry_raw($ne_acct, $ne_bank);
                            $ne_json = wp_json_encode($ne_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            ?>
                            <details open>
                                <summary style="cursor:pointer;font-weight:600;color:#92400e;font-size:13px;">
                                    <?php
                                    printf(
                                        /* translators: 1: account number, 2: bank code */
                                        esc_html__('GET /name/enquiry — raw response (account=%1$s, sortCode=%2$s)', 'matrix-mlm'),
                                        esc_html($ne_acct),
                                        esc_html($ne_bank)
                                    );
                                    ?>
                                </summary>
                                <pre style="margin:8px 0 0;padding:10px;background:#fff;border:1px solid #fcd34d;border-radius:4px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;font-family:Menlo,Consolas,monospace;font-size:11px;color:#1f2937;line-height:1.4;"><?php echo esc_html($ne_json); ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>

                    <?php
                    // =========================================================
                    // SYSTEM FINTAVA ENDPOINTS — FULL DIAGNOSTIC PANEL
                    // =========================================================
                    // Comprehensive endpoint probe. Exercises every Fintava
                    // endpoint the plugin may call so the operator can confirm
                    // reachability, authentication, and response shape from
                    // a single admin page. Fires live API requests — one per
                    // endpoint — so gated behind its own button.
                    $sys_diag_requested = isset($_POST['matrix_run_system_endpoints'])
                        && check_admin_referer('matrix_fintava_system_endpoints_diag', 'matrix_se_nonce');

                    // Individual endpoint test
                    $single_ep_requested = isset($_POST['matrix_run_single_endpoint'])
                        && check_admin_referer('matrix_fintava_single_endpoint_diag', 'matrix_sep_nonce');
                    $single_ep_key    = isset($_POST['matrix_ep_key']) ? sanitize_text_field(wp_unslash($_POST['matrix_ep_key'])) : '';
                    $single_ep_params = isset($_POST['matrix_ep_params']) && is_array($_POST['matrix_ep_params'])
                        ? array_map('sanitize_text_field', wp_unslash($_POST['matrix_ep_params']))
                        : [];
                    ?>
                    <div style="margin-top: 16px; padding-top: 12px; border-top: 1px dashed #f59e0b;">
                        <h4 style="margin: 0 0 8px; color: #92400e; font-size: 13px;">
                            <?php esc_html_e('System Fintava Endpoints — Full Diagnostic', 'matrix-mlm'); ?>
                        </h4>
                        <p style="margin: 0 0 12px; font-size: 12px; color: #78350f;">
                            <?php esc_html_e('Probe all Fintava API endpoints to verify reachability, authentication, and response format. GET endpoints are called directly; POST endpoints send an empty body to surface required-field validation. Endpoints that require path parameters are skipped in bulk mode — use the individual test form below.', 'matrix-mlm'); ?>
                        </p>

                        <!-- Bulk test all endpoints -->
                        <form method="post" style="margin-bottom: 16px;">
                            <?php wp_nonce_field('matrix_fintava_system_endpoints_diag', 'matrix_se_nonce'); ?>
                            <input type="submit" name="matrix_run_system_endpoints" class="button button-secondary"
                                   value="<?php esc_attr_e('Run All Endpoint Diagnostics', 'matrix-mlm'); ?>">
                        </form>

                        <?php if ($sys_diag_requested): ?>
                            <?php
                            $sys_results = $fintava_for_diag->debug_all_system_endpoints();
                            $all_endpoints = Matrix_MLM_Fintava::get_system_endpoints();

                            // Group by category
                            $grouped = [];
                            foreach ($sys_results as $key => $result) {
                                $category = $all_endpoints[$key]['category'] ?? 'Other';
                                $grouped[$category][$key] = $result;
                            }
                            ?>
                            <?php foreach ($grouped as $category => $endpoints): ?>
                                <details style="margin-top: 10px;">
                                    <summary style="cursor:pointer;font-weight:600;color:#92400e;font-size:13px;">
                                        <?php echo esc_html($category); ?>
                                        <span style="font-weight:normal;color:#78350f;font-size:11px;">
                                            (<?php echo count($endpoints); ?> endpoint<?php echo count($endpoints) > 1 ? 's' : ''; ?>)
                                        </span>
                                    </summary>
                                    <?php foreach ($endpoints as $ep_key => $result): ?>
                                        <?php
                                        $ep_meta   = $all_endpoints[$ep_key] ?? [];
                                        $ep_label  = $ep_meta['label'] ?? $ep_key;
                                        $ep_method = $ep_meta['method'] ?? '?';
                                        $ep_path   = $ep_meta['path'] ?? '';
                                        $http_code = $result['http_code'] ?? null;
                                        $skipped   = !empty($result['skipped']);
                                        $has_error = !empty($result['wp_error']);

                                        // Status indicator
                                        if ($skipped) {
                                            $status_color = '#6b7280';
                                            $status_label = 'SKIPPED';
                                        } elseif ($has_error) {
                                            $status_color = '#dc2626';
                                            $status_label = 'ERROR';
                                        } elseif ($http_code >= 200 && $http_code < 300) {
                                            $status_color = '#059669';
                                            $status_label = $http_code;
                                        } elseif ($http_code >= 400 && $http_code < 500) {
                                            $status_color = '#d97706';
                                            $status_label = $http_code;
                                        } else {
                                            $status_color = '#dc2626';
                                            $status_label = $http_code ?: 'FAIL';
                                        }

                                        $result_json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                                        ?>
                                        <div style="margin: 8px 0 0 16px; padding: 8px 12px; background: #fff; border: 1px solid #e5e7eb; border-radius: 4px;">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <span style="display:inline-block;padding:2px 6px;border-radius:3px;font-size:10px;font-weight:700;color:#fff;background:<?php echo esc_attr($status_color); ?>;">
                                                    <?php echo esc_html($status_label); ?>
                                                </span>
                                                <span style="font-size:11px;font-weight:600;color:#374151;">
                                                    <?php echo esc_html($ep_method); ?>
                                                </span>
                                                <code style="font-size:11px;color:#6b7280;"><?php echo esc_html($ep_path); ?></code>
                                                <span style="font-size:11px;color:#374151;margin-left:auto;">
                                                    <?php echo esc_html($ep_label); ?>
                                                </span>
                                            </div>
                                            <?php if (!$skipped): ?>
                                                <details style="margin-top: 6px;">
                                                    <summary style="cursor:pointer;font-size:11px;color:#6b7280;">
                                                        <?php esc_html_e('View raw response', 'matrix-mlm'); ?>
                                                    </summary>
                                                    <pre style="margin:4px 0 0;padding:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:3px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;font-family:Menlo,Consolas,monospace;font-size:10px;color:#1f2937;line-height:1.3;max-height:300px;overflow-y:auto;"><?php echo esc_html($result_json); ?></pre>
                                                </details>
                                            <?php else: ?>
                                                <p style="margin:4px 0 0;font-size:11px;color:#6b7280;font-style:italic;">
                                                    <?php echo esc_html($result['note'] ?? ''); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </details>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Individual endpoint test with path parameters -->
                        <div style="margin-top: 16px; padding-top: 12px; border-top: 1px dotted #f59e0b;">
                            <h4 style="margin: 0 0 8px; color: #92400e; font-size: 12px;">
                                <?php esc_html_e('Test Individual Endpoint (with parameters)', 'matrix-mlm'); ?>
                            </h4>
                            <form method="post">
                                <?php wp_nonce_field('matrix_fintava_single_endpoint_diag', 'matrix_sep_nonce'); ?>
                                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:8px;">
                                    <div>
                                        <label style="font-size:11px;color:#374151;display:block;margin-bottom:2px;">
                                            <?php esc_html_e('Endpoint', 'matrix-mlm'); ?>
                                        </label>
                                        <select name="matrix_ep_key" style="min-width:280px;" id="matrix-sep-select">
                                            <?php
                                            $all_ep = Matrix_MLM_Fintava::get_system_endpoints();
                                            $prev_cat = '';
                                            foreach ($all_ep as $key => $ep):
                                                if ($ep['category'] !== $prev_cat):
                                                    if ($prev_cat !== '') echo '</optgroup>';
                                                    $prev_cat = $ep['category'];
                                                    echo '<optgroup label="' . esc_attr($ep['category']) . '">';
                                                endif;
                                                $needs_params = !empty($ep['params']);
                                                $param_hint = $needs_params ? ' *' : '';
                                                ?>
                                                <option value="<?php echo esc_attr($key); ?>"
                                                    <?php selected($key, $single_ep_key); ?>
                                                    data-params="<?php echo esc_attr(wp_json_encode($ep['params'])); ?>"
                                                    data-method="<?php echo esc_attr($ep['method']); ?>">
                                                    <?php echo esc_html($ep['method'] . ' ' . $ep['path'] . $param_hint . ' — ' . $ep['label']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if ($prev_cat !== '') echo '</optgroup>'; ?>
                                        </select>
                                    </div>
                                </div>

                                <div id="matrix-sep-params" style="margin-bottom:8px;">
                                    <?php
                                    // Render param inputs for selected endpoint if it has params
                                    if ($single_ep_key && isset($all_ep[$single_ep_key]) && !empty($all_ep[$single_ep_key]['params'])):
                                        foreach ($all_ep[$single_ep_key]['params'] as $param):
                                            ?>
                                            <div style="margin-bottom:4px;">
                                                <label style="font-size:11px;color:#374151;">
                                                    <?php echo esc_html($param['label']); ?>
                                                    <?php if ($param['required']): ?><span style="color:#dc2626;">*</span><?php endif; ?>
                                                </label>
                                                <input type="text" name="matrix_ep_params[<?php echo esc_attr($param['name']); ?>]"
                                                       value="<?php echo esc_attr($single_ep_params[$param['name']] ?? ''); ?>"
                                                       placeholder="<?php echo esc_attr($param['label']); ?>"
                                                       style="width:260px;margin-left:8px;">
                                            </div>
                                        <?php endforeach;
                                    endif;
                                    ?>
                                </div>

                                <input type="submit" name="matrix_run_single_endpoint" class="button button-secondary"
                                       value="<?php esc_attr_e('Test Endpoint', 'matrix-mlm'); ?>">
                            </form>

                            <?php if ($single_ep_requested && $single_ep_key !== ''): ?>
                                <?php
                                $single_result = $fintava_for_diag->debug_system_endpoint($single_ep_key, $single_ep_params);
                                $single_json   = wp_json_encode($single_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                                $single_meta   = $all_ep[$single_ep_key] ?? [];
                                ?>
                                <details open style="margin-top: 8px;">
                                    <summary style="cursor:pointer;font-weight:600;color:#92400e;font-size:12px;">
                                        <?php
                                        printf(
                                            '%s %s — %s',
                                            esc_html($single_meta['method'] ?? '?'),
                                            esc_html($single_meta['path'] ?? $single_ep_key),
                                            esc_html($single_meta['label'] ?? $single_ep_key)
                                        );
                                        ?>
                                    </summary>
                                    <pre style="margin:4px 0 0;padding:8px;background:#fff;border:1px solid #fcd34d;border-radius:3px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;font-family:Menlo,Consolas,monospace;font-size:10px;color:#1f2937;line-height:1.3;max-height:400px;overflow-y:auto;"><?php echo esc_html($single_json); ?></pre>
                                </details>
                            <?php endif; ?>
                        </div>

                        <!-- Inline JS to show/hide param fields based on endpoint selection -->
                        <script>
                        (function() {
                            var select = document.getElementById('matrix-sep-select');
                            if (!select) return;

                            function updateParams() {
                                var opt = select.options[select.selectedIndex];
                                var params = JSON.parse(opt.getAttribute('data-params') || '[]');
                                var container = document.getElementById('matrix-sep-params');
                                container.innerHTML = '';
                                if (!params.length) return;
                                params.forEach(function(p) {
                                    var div = document.createElement('div');
                                    div.style.marginBottom = '4px';
                                    var label = document.createElement('label');
                                    label.style.fontSize = '11px';
                                    label.style.color = '#374151';
                                    label.textContent = p.label + (p.required ? ' *' : '');
                                    var input = document.createElement('input');
                                    input.type = 'text';
                                    input.name = 'matrix_ep_params[' + p.name + ']';
                                    input.placeholder = p.label;
                                    input.style.width = '260px';
                                    input.style.marginLeft = '8px';
                                    div.appendChild(label);
                                    div.appendChild(input);
                                    container.appendChild(div);
                                });
                            }

                            select.addEventListener('change', updateParams);
                        })();
                        </script>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render gateway-specific parameter fields
     */
    private function render_gateway_fields($slug, $params) {
        switch ($slug) {
            case 'paystack':
                ?>
                <tr>
                    <th><?php _e('Public Key', 'matrix-mlm'); ?></th>
                    <td><input type="text" name="params[public_key]" class="regular-text" value="<?php echo esc_attr($params['public_key'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><?php _e('Secret Key', 'matrix-mlm'); ?></th>
                    <td><input type="password" name="params[secret_key]" class="regular-text" value="<?php echo esc_attr($params['secret_key'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><?php _e('Webhook Secret', 'matrix-mlm'); ?></th>
                    <td>
                        <input type="text" name="params[webhook_secret]" class="regular-text" value="<?php echo esc_attr($params['webhook_secret'] ?? ''); ?>">
                        <p class="description"><?php _e('Webhook URL:', 'matrix-mlm'); ?> <code><?php echo rest_url('matrix-mlm/v1/payment/callback/paystack'); ?></code></p>
                    </td>
                </tr>
                <?php
                break;

            case 'flutterwave':
                ?>
                <tr>
                    <th><?php _e('Public Key', 'matrix-mlm'); ?></th>
                    <td><input type="text" name="params[public_key]" class="regular-text" value="<?php echo esc_attr($params['public_key'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><?php _e('Secret Key', 'matrix-mlm'); ?></th>
                    <td><input type="password" name="params[secret_key]" class="regular-text" value="<?php echo esc_attr($params['secret_key'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><?php _e('Encryption Key', 'matrix-mlm'); ?></th>
                    <td><input type="text" name="params[encryption_key]" class="regular-text" value="<?php echo esc_attr($params['encryption_key'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><?php _e('Webhook Hash', 'matrix-mlm'); ?></th>
                    <td>
                        <input type="text" name="params[webhook_hash]" class="regular-text" value="<?php echo esc_attr($params['webhook_hash'] ?? ''); ?>">
                        <p class="description"><?php _e('Webhook URL:', 'matrix-mlm'); ?> <code><?php echo rest_url('matrix-mlm/v1/payment/callback/flutterwave'); ?></code></p>
                    </td>
                </tr>
                <?php
                break;

            default:
                if (!empty($params)) {
                    foreach ($params as $key => $value) {
                        $label = ucwords(str_replace(['_', '-'], ' ', $key));
                        $input_type = (stripos($key, 'secret') !== false || stripos($key, 'password') !== false) ? 'password' : 'text';
                        ?>
                        <tr>
                            <th><?php echo esc_html($label); ?></th>
                            <td><input type="<?php echo $input_type; ?>" name="params[<?php echo esc_attr($key); ?>]" class="regular-text" value="<?php echo esc_attr($value); ?>"></td>
                        </tr>
                        <?php
                    }
                } else {
                    ?>
                    <tr>
                        <td colspan="2">
                            <p class="description"><?php _e('No configurable parameters for this gateway.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <?php
                }
                break;
        }
    }

    /**
     * Save gateway settings (database-stored gateways)
     */
    private function save_gateway() {
        global $wpdb;

        $id = intval($_POST['gateway_id']);

        $currencies_raw = sanitize_text_field($_POST['supported_currencies'] ?? '');
        $currencies = array_filter(array_map('trim', explode(',', $currencies_raw)));
        $currencies = array_map('strtoupper', $currencies);

        $data = [
            'gateway_parameters' => json_encode($_POST['params'] ?? []),
            'supported_currencies' => json_encode(array_values($currencies)),
            'min_amount' => floatval($_POST['min_amount']),
            'max_amount' => floatval($_POST['max_amount']),
            'fixed_charge' => floatval($_POST['fixed_charge']),
            'percent_charge' => floatval($_POST['percent_charge']),
            'status' => intval($_POST['status']),
        ];

        $wpdb->update($wpdb->prefix . 'matrix_gateways', $data, ['id' => $id]);
        echo '<div class="notice notice-success"><p>' . __('Gateway settings saved successfully!', 'matrix-mlm') . '</p></div>';
    }

    /**
     * Save Fintava Pay settings (stored in wp_options).
     * Fields: API Key, Environment (live/dev), Merchant ID, Callback URL,
     * Webhook Secret, Status toggle.
     */
    private function save_fintava_settings() {
        update_option('matrix_mlm_fintava_secret_key', sanitize_text_field($_POST['fintava_secret_key'] ?? ''));
        update_option('matrix_mlm_fintava_merchant_id', sanitize_text_field($_POST['fintava_merchant_id'] ?? ''));
        // Operating account NUBAN — used as senderAccount on the
        // /transaction/wallet-to-wallet endpoint that funds Matrix -> Virtual
        // transfers. Mirrors the legacy Laravel `withdraw_from_account`
        // global setting. Strip whitespace so a paste-with-trailing-space
        // doesn't break the upstream NUBAN match.
        update_option(
            'matrix_mlm_fintava_operating_account',
            preg_replace('/\s+/', '', sanitize_text_field($_POST['fintava_operating_account'] ?? ''))
        );
        update_option('matrix_mlm_fintava_callback_url', esc_url_raw($_POST['fintava_callback_url'] ?? ''));
        update_option('matrix_mlm_fintava_webhook_secret', sanitize_text_field($_POST['fintava_webhook_secret'] ?? ''));
        update_option('matrix_mlm_fintava_status', intval($_POST['fintava_status'] ?? 0));

        // Environment selector — restrict to the two values we render in the
        // dropdown so a hand-crafted POST can't push a garbage value into the
        // option and confuse get_base_url() downstream.
        $submitted_env = strtolower(trim((string) ($_POST['fintava_environment'] ?? 'live')));
        $environment   = in_array($submitted_env, ['live', 'dev'], true) ? $submitted_env : 'live';
        update_option('matrix_mlm_fintava_environment', $environment);

        // Keep the legacy "_enabled" option in sync so any older code paths
        // that still read it stay aligned with the toggle on this page.
        update_option('matrix_mlm_fintava_enabled', intval($_POST['fintava_status'] ?? 0));

        // Dedicated Bank Payouts (Transfer to Bank) toggle. Stored as 0/1
        // so checked()/get_option lookups stay aligned with the rest of
        // the gateway settings. Read the checkbox via isset() — unchecked
        // checkboxes are NOT submitted by browsers, so $_POST['fintava_payouts_enabled']
        // is absent (not '0') when the admin clears the box.
        update_option(
            'matrix_mlm_fintava_payouts_enabled',
            isset($_POST['fintava_payouts_enabled']) ? 1 : 0
        );

        // Bust the cached Fintava /banks list so a key/env change takes
        // effect on the very next page load instead of being shadowed by a
        // 24-hour transient. Without this, an operator who fixes the API
        // key would still see the stale "fallback engaged" note for up to
        // a day. Both versioned keys are deleted so we cover the rollout
        // window where some installs may still hold a v3 transient and
        // others have moved to v4.
        delete_transient('matrix_fintava_banks_list_v3');
        delete_transient('matrix_fintava_banks_list_v4');

        echo '<div class="notice notice-success"><p>' . __('Fintava Pay settings saved successfully!', 'matrix-mlm') . '</p></div>';
    }
}
