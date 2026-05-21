<?php
/**
 * Admin Payment Gateways Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Gateways {

    public function render() {
        global $wpdb;

        // Handle form submissions
        if (isset($_POST['save_gateway']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_save_gateway')) {
            $this->save_gateway();
        }

        if (isset($_POST['save_fintava_gateway']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_save_fintava_gateway')) {
            $this->save_fintava_settings();
        }

        if (isset($_POST['matrix_seed_gateways']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_seed_gateways')) {
            $this->seed_gateways();
            // Redirect to reload the page with fresh data
            wp_redirect(admin_url('admin.php?page=matrix-mlm-gateways&seeded=1'));
            exit;
        }

        if (isset($_GET['seeded'])) {
            echo '<div class="notice notice-success"><p>' . __('Default gateways have been created successfully!', 'matrix-mlm') . '</p></div>';
        }

        $gateways = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}matrix_gateways ORDER BY name ASC");
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Payment Gateways', 'matrix-mlm'); ?></h1>

            <?php if (empty($gateways)): ?>
                <div class="matrix-admin-card">
                    <h2><?php _e('No Payment Gateways Found', 'matrix-mlm'); ?></h2>
                    <p><?php _e('No payment gateways have been configured yet. Click the button below to create the default gateways (Paystack, Flutterwave, and Fintava).', 'matrix-mlm'); ?></p>
                    <form method="post">
                        <?php wp_nonce_field('matrix_seed_gateways'); ?>
                        <p><input type="submit" name="matrix_seed_gateways" class="button button-primary" value="<?php _e('Create Default Gateways', 'matrix-mlm'); ?>"></p>
                    </form>
                </div>
            <?php else: ?>

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

            <?php endif; ?>

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
                // Dynamic rendering for any other gateway - render all stored parameters as editable fields
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

        // Parse supported currencies from comma-separated string
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

    /**
     * Seed default gateways into the database
     */
    private function seed_gateways() {
        global $wpdb;
        $gateways_table = $wpdb->prefix . 'matrix_gateways';

        $existing = $wpdb->get_var("SELECT COUNT(*) FROM $gateways_table");
        if ($existing > 0) {
            return; // Already has gateways, don't duplicate
        }

        $wpdb->insert($gateways_table, [
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
            'status' => 0
        ]);

        $wpdb->insert($gateways_table, [
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
            'status' => 0
        ]);
    }
}
