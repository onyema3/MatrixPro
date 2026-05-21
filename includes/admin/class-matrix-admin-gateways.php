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

        if (isset($_POST['save_gateway']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_save_gateway')) {
            $this->save_gateway();
        }

        $gateways = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}matrix_gateways ORDER BY name ASC");
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Payment Gateways', 'matrix-mlm'); ?></h1>

            <?php foreach ($gateways as $gateway): 
                $params = json_decode($gateway->gateway_parameters, true);
                $currencies = json_decode($gateway->supported_currencies, true);
            ?>
            <div class="matrix-admin-card">
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
                        <?php if ($gateway->slug === 'paystack'): ?>
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
                        <?php elseif ($gateway->slug === 'flutterwave'): ?>
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
                        <?php endif; ?>
                        <tr>
                            <th><?php _e('Min Amount', 'matrix-mlm'); ?></th>
                            <td><input type="number" name="min_amount" step="0.01" value="<?php echo esc_attr($gateway->min_amount); ?>"></td>
                        </tr>
                        <tr>
                            <th><?php _e('Max Amount', 'matrix-mlm'); ?></th>
                            <td><input type="number" name="max_amount" step="0.01" value="<?php echo esc_attr($gateway->max_amount); ?>"></td>
                        </tr>
                        <tr>
                            <th><?php _e('Fixed Charge', 'matrix-mlm'); ?></th>
                            <td><input type="number" name="fixed_charge" step="0.01" value="<?php echo esc_attr($gateway->fixed_charge); ?>"></td>
                        </tr>
                        <tr>
                            <th><?php _e('Percent Charge (%)', 'matrix-mlm'); ?></th>
                            <td><input type="number" name="percent_charge" step="0.01" value="<?php echo esc_attr($gateway->percent_charge); ?>"></td>
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
        </div>
        <?php
    }

    private function save_gateway() {
        global $wpdb;

        $id = intval($_POST['gateway_id']);
        $data = [
            'gateway_parameters' => json_encode($_POST['params'] ?? []),
            'min_amount' => floatval($_POST['min_amount']),
            'max_amount' => floatval($_POST['max_amount']),
            'fixed_charge' => floatval($_POST['fixed_charge']),
            'percent_charge' => floatval($_POST['percent_charge']),
            'status' => intval($_POST['status']),
        ];

        $wpdb->update($wpdb->prefix . 'matrix_gateways', $data, ['id' => $id]);
        echo '<div class="notice notice-success"><p>' . __('Gateway settings saved!', 'matrix-mlm') . '</p></div>';
    }
}
