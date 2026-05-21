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

            <!-- Fintava Settings (stored in wp_options) -->
            <div class="matrix-admin-card" style="margin-bottom: 20px;">
                <h2>
                    <?php _e('Fintava Pay (Payouts & Virtual Wallet)', 'matrix-mlm'); ?>
                    <span class="matrix-badge matrix-badge-<?php echo get_option('matrix_mlm_fintava_status', 0) ? 'active' : 'inactive'; ?>" style="font-size: 12px;">
                        <?php echo get_option('matrix_mlm_fintava_status', 0) ? __('Active', 'matrix-mlm') : __('Inactive', 'matrix-mlm'); ?>
                    </span>
                </h2>
                <p class="description" style="margin-bottom: 15px;">
                    <?php _e('Fintava Pay is used for bank payouts (withdrawals to bank accounts) and virtual wallet generation. Configure your Fintava merchant credentials below.', 'matrix-mlm'); ?>
                </p>
                <form method="post">
                    <?php wp_nonce_field('matrix_save_fintava_gateway'); ?>
                    <table class="form-table">
                        <tr>
                            <th><?php _e('Environment', 'matrix-mlm'); ?></th>
                            <td>
                                <select name="fintava_environment">
                                    <option value="sandbox" <?php selected(get_option('matrix_mlm_fintava_environment', 'sandbox'), 'sandbox'); ?>><?php _e('Sandbox (Test)', 'matrix-mlm'); ?></option>
                                    <option value="live" <?php selected(get_option('matrix_mlm_fintava_environment', 'sandbox'), 'live'); ?>><?php _e('Live (Production)', 'matrix-mlm'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Public Key', 'matrix-mlm'); ?></th>
                            <td><input type="text" name="fintava_public_key" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_public_key', '')); ?>"></td>
                        </tr>
                        <tr>
                            <th><?php _e('Secret Key', 'matrix-mlm'); ?></th>
                            <td><input type="password" name="fintava_secret_key" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_secret_key', '')); ?>"></td>
                        </tr>
                        <tr>
                            <th><?php _e('Base URL', 'matrix-mlm'); ?></th>
                            <td>
                                <input type="url" name="fintava_base_url" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_base_url', '')); ?>" placeholder="https://dev.fintavapay.com/api/dev">
                                <p class="description"><?php _e('Leave blank to use the default Fintava API URL.', 'matrix-mlm'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Webhook Secret', 'matrix-mlm'); ?></th>
                            <td>
                                <input type="text" name="fintava_webhook_secret" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_webhook_secret', '')); ?>">
                                <p class="description"><?php _e('Webhook URL:', 'matrix-mlm'); ?> <code><?php echo rest_url('matrix-mlm/v1/payment/callback/fintava'); ?></code></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Status', 'matrix-mlm'); ?></th>
                            <td>
                                <select name="fintava_status">
                                    <option value="1" <?php selected(get_option('matrix_mlm_fintava_status', 0), 1); ?>><?php _e('Active', 'matrix-mlm'); ?></option>
                                    <option value="0" <?php selected(get_option('matrix_mlm_fintava_status', 0), 0); ?>><?php _e('Inactive', 'matrix-mlm'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p><input type="submit" name="save_fintava_gateway" class="button button-primary" value="<?php _e('Save Fintava Settings', 'matrix-mlm'); ?>"></p>
                </form>
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
     * Save Fintava Pay settings (stored in wp_options)
     */
    private function save_fintava_settings() {
        update_option('matrix_mlm_fintava_environment', sanitize_text_field($_POST['fintava_environment'] ?? 'sandbox'));
        update_option('matrix_mlm_fintava_public_key', sanitize_text_field($_POST['fintava_public_key'] ?? ''));
        update_option('matrix_mlm_fintava_secret_key', sanitize_text_field($_POST['fintava_secret_key'] ?? ''));
        update_option('matrix_mlm_fintava_base_url', esc_url_raw($_POST['fintava_base_url'] ?? ''));
        update_option('matrix_mlm_fintava_webhook_secret', sanitize_text_field($_POST['fintava_webhook_secret'] ?? ''));
        update_option('matrix_mlm_fintava_status', intval($_POST['fintava_status'] ?? 0));

        echo '<div class="notice notice-success"><p>' . __('Fintava Pay settings saved successfully!', 'matrix-mlm') . '</p></div>';
    }
}
