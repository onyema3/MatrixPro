<?php
/**
 * Admin Settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Settings {

    public function render() {
        if (isset($_POST['save_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_save_settings')) {
            $this->save_settings();
        }

        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Settings', 'matrix-mlm'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=general'); ?>" class="nav-tab <?php echo $tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php _e('General', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=financial'); ?>" class="nav-tab <?php echo $tab === 'financial' ? 'nav-tab-active' : ''; ?>"><?php _e('Financial', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=notifications'); ?>" class="nav-tab <?php echo $tab === 'notifications' ? 'nav-tab-active' : ''; ?>"><?php _e('Notifications', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=security'); ?>" class="nav-tab <?php echo $tab === 'security' ? 'nav-tab-active' : ''; ?>"><?php _e('Security', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=appearance'); ?>" class="nav-tab <?php echo $tab === 'appearance' ? 'nav-tab-active' : ''; ?>"><?php _e('Appearance', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=sms'); ?>" class="nav-tab <?php echo $tab === 'sms' ? 'nav-tab-active' : ''; ?>"><?php _e('SMS', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=language'); ?>" class="nav-tab <?php echo $tab === 'language' ? 'nav-tab-active' : ''; ?>"><?php _e('Language', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=livechat'); ?>" class="nav-tab <?php echo $tab === 'livechat' ? 'nav-tab-active' : ''; ?>"><?php _e('Livechat', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=fintava'); ?>" class="nav-tab <?php echo $tab === 'fintava' ? 'nav-tab-active' : ''; ?>"><?php _e('Fintava', 'matrix-mlm'); ?></a>
            </nav>

            <form method="post" class="matrix-admin-card">
                <?php wp_nonce_field('matrix_save_settings'); ?>
                <input type="hidden" name="settings_tab" value="<?php echo esc_attr($tab); ?>">

                <?php
                switch ($tab) {
                    case 'general': $this->render_general_tab(); break;
                    case 'financial': $this->render_financial_tab(); break;
                    case 'notifications': $this->render_notifications_tab(); break;
                    case 'security': $this->render_security_tab(); break;
                    case 'appearance': $this->render_appearance_tab(); break;
                    case 'sms': $this->render_sms_tab(); break;
                    case 'language': $this->render_language_tab(); break;
                    case 'livechat': $this->render_livechat_tab(); break;
                    case 'fintava': $this->render_fintava_tab(); break;
                }
                ?>

                <p class="submit">
                    <input type="submit" name="save_settings" class="button button-primary" value="<?php _e('Save Settings', 'matrix-mlm'); ?>">
                </p>
            </form>
        </div>
        <?php
    }

    private function render_general_tab() { ?>
        <table class="form-table">
            <tr><th><?php _e('Site Title', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_site_title" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_site_title', 'Matrix MLM Pro')); ?>"></td></tr>
            <tr><th><?php _e('Currency', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_currency" class="small-text" value="<?php echo esc_attr(get_option('matrix_mlm_currency', 'NGN')); ?>"></td></tr>
            <tr><th><?php _e('Currency Symbol', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_currency_symbol" class="small-text" value="<?php echo esc_attr(get_option('matrix_mlm_currency_symbol', '₦')); ?>"></td></tr>
            <tr><th><?php _e('Registration', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_registration_enabled" value="1" <?php checked(get_option('matrix_mlm_registration_enabled', 1)); ?>> <?php _e('Allow new user registration', 'matrix-mlm'); ?></label></td></tr>
            <tr><th><?php _e('GDPR Compliance', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_gdpr_enabled" value="1" <?php checked(get_option('matrix_mlm_gdpr_enabled', 1)); ?>> <?php _e('Enable GDPR cookie consent', 'matrix-mlm'); ?></label></td></tr>
        </table>
    <?php }

    private function render_financial_tab() { ?>
        <table class="form-table">
            <tr><th><?php _e('Minimum Deposit', 'matrix-mlm'); ?></th>
                <td><input type="number" name="matrix_mlm_min_deposit" step="0.01" value="<?php echo esc_attr(get_option('matrix_mlm_min_deposit', 1000)); ?>"></td></tr>
            <tr><th><?php _e('Maximum Deposit', 'matrix-mlm'); ?></th>
                <td><input type="number" name="matrix_mlm_max_deposit" step="0.01" value="<?php echo esc_attr(get_option('matrix_mlm_max_deposit', 5000000)); ?>"></td></tr>
            <tr><th><?php _e('Minimum Withdrawal', 'matrix-mlm'); ?></th>
                <td><input type="number" name="matrix_mlm_min_withdraw" step="0.01" value="<?php echo esc_attr(get_option('matrix_mlm_min_withdraw', 1000)); ?>"></td></tr>
            <tr><th><?php _e('Maximum Withdrawal', 'matrix-mlm'); ?></th>
                <td><input type="number" name="matrix_mlm_max_withdraw" step="0.01" value="<?php echo esc_attr(get_option('matrix_mlm_max_withdraw', 1000000)); ?>"></td></tr>
            <tr><th><?php _e('Withdrawal Charge Type', 'matrix-mlm'); ?></th>
                <td><select name="matrix_mlm_withdraw_charge_type">
                    <option value="percent" <?php selected(get_option('matrix_mlm_withdraw_charge_type'), 'percent'); ?>><?php _e('Percentage', 'matrix-mlm'); ?></option>
                    <option value="fixed" <?php selected(get_option('matrix_mlm_withdraw_charge_type'), 'fixed'); ?>><?php _e('Fixed', 'matrix-mlm'); ?></option>
                </select></td></tr>
            <tr><th><?php _e('Withdrawal Charge Value', 'matrix-mlm'); ?></th>
                <td><input type="number" name="matrix_mlm_withdraw_charge" step="0.01" value="<?php echo esc_attr(get_option('matrix_mlm_withdraw_charge', 5)); ?>"></td></tr>
            <tr><th><?php _e('Transfer Charge Type', 'matrix-mlm'); ?></th>
                <td><select name="matrix_mlm_transfer_charge_type">
                    <option value="percent" <?php selected(get_option('matrix_mlm_transfer_charge_type'), 'percent'); ?>><?php _e('Percentage', 'matrix-mlm'); ?></option>
                    <option value="fixed" <?php selected(get_option('matrix_mlm_transfer_charge_type'), 'fixed'); ?>><?php _e('Fixed', 'matrix-mlm'); ?></option>
                </select></td></tr>
            <tr><th><?php _e('Transfer Charge Value', 'matrix-mlm'); ?></th>
                <td><input type="number" name="matrix_mlm_transfer_charge" step="0.01" value="<?php echo esc_attr(get_option('matrix_mlm_transfer_charge', 100)); ?>"></td></tr>
            <tr><th><?php _e('Minimum Transfer', 'matrix-mlm'); ?></th>
                <td><input type="number" name="matrix_mlm_min_transfer" step="0.01" value="<?php echo esc_attr(get_option('matrix_mlm_min_transfer', 500)); ?>"></td></tr>
        </table>
    <?php }

    private function render_notifications_tab() { ?>
        <table class="form-table">
            <tr><th><?php _e('Email Verification', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_email_verification" value="1" <?php checked(get_option('matrix_mlm_email_verification', 1)); ?>> <?php _e('Require email verification on registration', 'matrix-mlm'); ?></label></td></tr>
            <tr><th><?php _e('SMS Verification', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_sms_verification" value="1" <?php checked(get_option('matrix_mlm_sms_verification', 0)); ?>> <?php _e('Require SMS verification', 'matrix-mlm'); ?></label></td></tr>
        </table>
    <?php }

    private function render_security_tab() { ?>
        <table class="form-table">
            <tr><th><?php _e('Two-Factor Authentication', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_2fa_enabled" value="1" <?php checked(get_option('matrix_mlm_2fa_enabled', 1)); ?>> <?php _e('Allow users to enable 2FA', 'matrix-mlm'); ?></label></td></tr>
            <tr><th><?php _e('Google reCAPTCHA', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_captcha_enabled" value="1" <?php checked(get_option('matrix_mlm_captcha_enabled', 0)); ?>> <?php _e('Enable captcha on forms', 'matrix-mlm'); ?></label></td></tr>
            <tr><th><?php _e('Captcha Site Key', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_captcha_site_key" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_captcha_site_key', '')); ?>"></td></tr>
            <tr><th><?php _e('Captcha Secret Key', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_captcha_secret_key" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_captcha_secret_key', '')); ?>"></td></tr>
        </table>
    <?php }

    private function render_appearance_tab() { ?>
        <table class="form-table">
            <tr><th><?php _e('Primary Color', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_primary_color" class="matrix-color-picker" value="<?php echo esc_attr(get_option('matrix_mlm_primary_color', '#4f46e5')); ?>"></td></tr>
            <tr><th><?php _e('Secondary Color', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_secondary_color" class="matrix-color-picker" value="<?php echo esc_attr(get_option('matrix_mlm_secondary_color', '#7c3aed')); ?>"></td></tr>
            <tr><th><?php _e('Custom CSS', 'matrix-mlm'); ?></th>
                <td><textarea name="matrix_mlm_custom_css" rows="10" class="large-text code"><?php echo esc_textarea(get_option('matrix_mlm_custom_css', '')); ?></textarea></td></tr>
        </table>
    <?php }

    private function render_sms_tab() { ?>
        <table class="form-table">
            <tr><th><?php _e('SMS Provider', 'matrix-mlm'); ?></th>
                <td><select name="matrix_mlm_sms_provider">
                    <option value="twilio" <?php selected(get_option('matrix_mlm_sms_provider'), 'twilio'); ?>>Twilio</option>
                    <option value="nexmo" <?php selected(get_option('matrix_mlm_sms_provider'), 'nexmo'); ?>>Nexmo/Vonage</option>
                    <option value="termii" <?php selected(get_option('matrix_mlm_sms_provider'), 'termii'); ?>>Termii</option>
                </select></td></tr>
            <tr><th><?php _e('API Key / SID', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_sms_api_key" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_sms_api_key', '')); ?>"></td></tr>
            <tr><th><?php _e('API Secret / Token', 'matrix-mlm'); ?></th>
                <td><input type="password" name="matrix_mlm_sms_api_secret" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_sms_api_secret', '')); ?>"></td></tr>
            <tr><th><?php _e('Sender ID / From Number', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_sms_sender_id" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_sms_sender_id', '')); ?>"></td></tr>
        </table>
    <?php }

    private function render_language_tab() { ?>
        <table class="form-table">
            <tr><th><?php _e('Default Language', 'matrix-mlm'); ?></th>
                <td><select name="matrix_mlm_default_language">
                    <option value="en" <?php selected(get_option('matrix_mlm_default_language'), 'en'); ?>>English</option>
                    <option value="fr" <?php selected(get_option('matrix_mlm_default_language'), 'fr'); ?>>French</option>
                    <option value="es" <?php selected(get_option('matrix_mlm_default_language'), 'es'); ?>>Spanish</option>
                    <option value="pt" <?php selected(get_option('matrix_mlm_default_language'), 'pt'); ?>>Portuguese</option>
                    <option value="ar" <?php selected(get_option('matrix_mlm_default_language'), 'ar'); ?>>Arabic</option>
                    <option value="ha" <?php selected(get_option('matrix_mlm_default_language'), 'ha'); ?>>Hausa</option>
                    <option value="yo" <?php selected(get_option('matrix_mlm_default_language'), 'yo'); ?>>Yoruba</option>
                    <option value="ig" <?php selected(get_option('matrix_mlm_default_language'), 'ig'); ?>>Igbo</option>
                </select></td></tr>
        </table>
    <?php }

    private function render_livechat_tab() { ?>
        <table class="form-table">
            <tr><th><?php _e('Enable Livechat', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_livechat_enabled" value="1" <?php checked(get_option('matrix_mlm_livechat_enabled', 0)); ?>> <?php _e('Enable livechat widget', 'matrix-mlm'); ?></label></td></tr>
            <tr><th><?php _e('Livechat Code', 'matrix-mlm'); ?></th>
                <td><textarea name="matrix_mlm_livechat_code" rows="6" class="large-text code" placeholder="<?php _e('Paste your Tawk.to, Crisp, or other livechat embed code here', 'matrix-mlm'); ?>"><?php echo esc_textarea(get_option('matrix_mlm_livechat_code', '')); ?></textarea>
                <p class="description"><?php _e('Supports Tawk.to, Crisp, LiveChat, Intercom, or any JavaScript widget code.', 'matrix-mlm'); ?></p></td></tr>
        </table>
    <?php }

    private function render_fintava_tab() { ?>
        <h2><?php _e('Fintava Pay - Merchant Settings', 'matrix-mlm'); ?></h2>
        <p class="description"><?php _e('Configure Fintava Pay API for bank payouts. Users transfer from their Matrix wallet to their Fintava wallet (or any bank account) via the merchant credit endpoint.', 'matrix-mlm'); ?></p>
        
        <?php
        // Show merchant balance if configured
        $fintava = new Matrix_MLM_Fintava();
        if ($fintava->is_active()):
            $balance = $fintava->get_merchant_balance();
            if (!is_wp_error($balance)):
        ?>
        <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 16px 20px; margin-bottom: 20px;">
            <h3 style="margin: 0 0 8px; color: #065f46;"><?php _e('Merchant Wallet Balance', 'matrix-mlm'); ?></h3>
            <p style="font-size: 28px; font-weight: 700; color: #059669; margin: 0;">
                <?php echo get_option('matrix_mlm_currency_symbol', '₦') . number_format($balance['available_balance'] ?? $balance['balance'] ?? 0, 2); ?>
            </p>
            <small style="color: #065f46;"><?php _e('Available for payouts', 'matrix-mlm'); ?></small>
        </div>
            <?php endif; ?>
        <?php endif; ?>

        <table class="form-table">
            <tr><th><?php _e('Enable Fintava Payout', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_fintava_enabled" value="1" <?php checked(get_option('matrix_mlm_fintava_enabled', 0)); ?>> <?php _e('Allow users to make bank payouts via Fintava Pay', 'matrix-mlm'); ?></label></td></tr>
            <tr><th><?php _e('Secret Key', 'matrix-mlm'); ?></th>
                <td><input type="password" name="matrix_mlm_fintava_secret_key" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_secret_key', '')); ?>">
                <p class="description"><?php _e('Bearer token for API authentication. Get this from your Fintava Pay dashboard.', 'matrix-mlm'); ?></p></td></tr>
            <tr><th><?php _e('Public Key', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_fintava_public_key" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_public_key', '')); ?>"></td></tr>
            <tr><th><?php _e('Webhook Secret', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_fintava_webhook_secret" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_webhook_secret', '')); ?>">
                <p class="description"><?php _e('Webhook URL:', 'matrix-mlm'); ?> <code><?php echo rest_url('matrix-mlm/v1/fintava/webhook'); ?></code></p></td></tr>
            <tr><th><?php _e('API Base URL', 'matrix-mlm'); ?></th>
                <td><input type="url" name="matrix_mlm_fintava_base_url" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_base_url', '')); ?>" placeholder="https://dev.fintavapay.com/api/dev">
                <p class="description"><?php _e('Leave empty to use default: https://dev.fintavapay.com/api/dev', 'matrix-mlm'); ?></p></td></tr>
        </table>

        <h3><?php _e('API Endpoints Used', 'matrix-mlm'); ?></h3>
        <table class="wp-list-table widefat fixed striped" style="max-width: 600px;">
            <thead><tr><th><?php _e('Action', 'matrix-mlm'); ?></th><th><?php _e('Endpoint', 'matrix-mlm'); ?></th></tr></thead>
            <tbody>
                <tr><td><?php _e('Merchant Balance', 'matrix-mlm'); ?></td><td><code>GET /merchant/balance</code></td></tr>
                <tr><td><?php _e('Bank Credit (Payout)', 'matrix-mlm'); ?></td><td><code>POST /bank/credit/merchant</code></td></tr>
                <tr><td><?php _e('Generate Virtual Wallet', 'matrix-mlm'); ?></td><td><code>POST /virtual-wallet/generate</code></td></tr>
                <tr><td><?php _e('Request Physical Card', 'matrix-mlm'); ?></td><td><code>POST /cards/physical/request</code></td></tr>
                <tr><td><?php _e('Card Status', 'matrix-mlm'); ?></td><td><code>GET /cards/status</code></td></tr>
                <tr><td><?php _e('Link Card', 'matrix-mlm'); ?></td><td><code>POST /cards/link</code></td></tr>
                <tr><td><?php _e('Activate Card', 'matrix-mlm'); ?></td><td><code>POST /cards/activate</code></td></tr>
                <tr><td><?php _e('View Card', 'matrix-mlm'); ?></td><td><code>GET /cards/fetch/{id}</code></td></tr>
            </tbody>
        </table>

        <h3><?php _e('Payout Limits & Charges', 'matrix-mlm'); ?></h3>
        <table class="form-table">
            <tr><th><?php _e('Minimum Payout', 'matrix-mlm'); ?></th>
                <td><input type="number" name="matrix_mlm_fintava_min_payout" step="0.01" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_min_payout', 1000)); ?>"></td></tr>
            <tr><th><?php _e('Maximum Payout', 'matrix-mlm'); ?></th>
                <td><input type="number" name="matrix_mlm_fintava_max_payout" step="0.01" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_max_payout', 5000000)); ?>"></td></tr>
            <tr><th><?php _e('Service Charge Type', 'matrix-mlm'); ?></th>
                <td><select name="matrix_mlm_fintava_charge_type">
                    <option value="fixed" <?php selected(get_option('matrix_mlm_fintava_charge_type', 'fixed'), 'fixed'); ?>><?php _e('Fixed Amount', 'matrix-mlm'); ?></option>
                    <option value="percent" <?php selected(get_option('matrix_mlm_fintava_charge_type'), 'percent'); ?>><?php _e('Percentage', 'matrix-mlm'); ?></option>
                </select></td></tr>
            <tr><th><?php _e('Charge Value', 'matrix-mlm'); ?></th>
                <td><input type="number" name="matrix_mlm_fintava_charge_value" step="0.01" value="<?php echo esc_attr(get_option('matrix_mlm_fintava_charge_value', 50)); ?>">
                <p class="description"><?php _e('Fixed amount in currency or percentage value', 'matrix-mlm'); ?></p></td></tr>
        </table>
    <?php }

    private function save_settings() {
        $tab = sanitize_text_field($_POST['settings_tab']);

        $settings = [];
        switch ($tab) {
            case 'general':
                $settings = ['matrix_mlm_site_title', 'matrix_mlm_currency', 'matrix_mlm_currency_symbol', 'matrix_mlm_registration_enabled', 'matrix_mlm_gdpr_enabled'];
                break;
            case 'financial':
                $settings = ['matrix_mlm_min_deposit', 'matrix_mlm_max_deposit', 'matrix_mlm_min_withdraw', 'matrix_mlm_max_withdraw', 'matrix_mlm_withdraw_charge_type', 'matrix_mlm_withdraw_charge', 'matrix_mlm_transfer_charge_type', 'matrix_mlm_transfer_charge', 'matrix_mlm_min_transfer'];
                break;
            case 'notifications':
                $settings = ['matrix_mlm_email_verification', 'matrix_mlm_sms_verification'];
                break;
            case 'security':
                $settings = ['matrix_mlm_2fa_enabled', 'matrix_mlm_captcha_enabled', 'matrix_mlm_captcha_site_key', 'matrix_mlm_captcha_secret_key'];
                break;
            case 'appearance':
                $settings = ['matrix_mlm_primary_color', 'matrix_mlm_secondary_color', 'matrix_mlm_custom_css'];
                break;
            case 'sms':
                $settings = ['matrix_mlm_sms_provider', 'matrix_mlm_sms_api_key', 'matrix_mlm_sms_api_secret', 'matrix_mlm_sms_sender_id'];
                break;
            case 'language':
                $settings = ['matrix_mlm_default_language'];
                break;
            case 'livechat':
                $settings = ['matrix_mlm_livechat_enabled', 'matrix_mlm_livechat_code'];
                break;
            case 'fintava':
                $settings = ['matrix_mlm_fintava_enabled', 'matrix_mlm_fintava_public_key', 'matrix_mlm_fintava_secret_key', 'matrix_mlm_fintava_webhook_secret', 'matrix_mlm_fintava_base_url', 'matrix_mlm_fintava_min_payout', 'matrix_mlm_fintava_max_payout', 'matrix_mlm_fintava_charge_type', 'matrix_mlm_fintava_charge_value'];
                break;
        }

        foreach ($settings as $setting) {
            $value = isset($_POST[$setting]) ? $_POST[$setting] : '';
            if (in_array($setting, ['matrix_mlm_custom_css', 'matrix_mlm_livechat_code'])) {
                $value = wp_unslash($value);
            } else {
                $value = sanitize_text_field($value);
            }
            update_option($setting, $value);
        }

        // Handle checkboxes that might not be sent
        $checkboxes = ['matrix_mlm_registration_enabled', 'matrix_mlm_gdpr_enabled', 'matrix_mlm_email_verification', 'matrix_mlm_sms_verification', 'matrix_mlm_2fa_enabled', 'matrix_mlm_captcha_enabled', 'matrix_mlm_livechat_enabled', 'matrix_mlm_fintava_enabled'];
        foreach ($checkboxes as $cb) {
            if (in_array($cb, $settings) && !isset($_POST[$cb])) {
                update_option($cb, 0);
            }
        }

        echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'matrix-mlm') . '</p></div>';
    }
}
