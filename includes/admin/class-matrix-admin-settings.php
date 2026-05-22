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
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=matrix'); ?>" class="nav-tab <?php echo $tab === 'matrix' ? 'nav-tab-active' : ''; ?>"><?php _e('Matrix Config', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=financial'); ?>" class="nav-tab <?php echo $tab === 'financial' ? 'nav-tab-active' : ''; ?>"><?php _e('Financial', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=notifications'); ?>" class="nav-tab <?php echo $tab === 'notifications' ? 'nav-tab-active' : ''; ?>"><?php _e('Notifications', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=security'); ?>" class="nav-tab <?php echo $tab === 'security' ? 'nav-tab-active' : ''; ?>"><?php _e('Security', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=appearance'); ?>" class="nav-tab <?php echo $tab === 'appearance' ? 'nav-tab-active' : ''; ?>"><?php _e('Appearance', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=sms'); ?>" class="nav-tab <?php echo $tab === 'sms' ? 'nav-tab-active' : ''; ?>"><?php _e('SMS', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=language'); ?>" class="nav-tab <?php echo $tab === 'language' ? 'nav-tab-active' : ''; ?>"><?php _e('Language', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=livechat'); ?>" class="nav-tab <?php echo $tab === 'livechat' ? 'nav-tab-active' : ''; ?>"><?php _e('Livechat', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=subscription'); ?>" class="nav-tab <?php echo $tab === 'subscription' ? 'nav-tab-active' : ''; ?>"><?php _e('Subscription', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=fintava'); ?>" class="nav-tab <?php echo $tab === 'fintava' ? 'nav-tab-active' : ''; ?>"><?php _e('Fintava', 'matrix-mlm'); ?></a>
            </nav>

            <form method="post" class="matrix-admin-card">
                <?php wp_nonce_field('matrix_save_settings'); ?>
                <input type="hidden" name="settings_tab" value="<?php echo esc_attr($tab); ?>">

                <?php
                switch ($tab) {
                    case 'general': $this->render_general_tab(); break;
                    case 'matrix': $this->render_matrix_tab(); break;
                    case 'financial': $this->render_financial_tab(); break;
                    case 'notifications': $this->render_notifications_tab(); break;
                    case 'security': $this->render_security_tab(); break;
                    case 'appearance': $this->render_appearance_tab(); break;
                    case 'sms': $this->render_sms_tab(); break;
                    case 'language': $this->render_language_tab(); break;
                    case 'livechat': $this->render_livechat_tab(); break;
                    case 'subscription': $this->render_subscription_tab(); break;
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
            <tr><th><?php _e('Default Referral Code', 'matrix-mlm'); ?></th>
                <td>
                    <code style="font-size: 14px; padding: 4px 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px;"><?php echo esc_html(get_option('matrix_mlm_default_referral_code', 'MATRIX01')); ?></code>
                    <p class="description"><?php _e('Share this referral code with your first users so they can register. This belongs to the root admin account.', 'matrix-mlm'); ?></p>
                </td></tr>
            <tr><th><?php _e('Registration', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_registration_enabled" value="1" <?php checked(get_option('matrix_mlm_registration_enabled', 1)); ?>> <?php _e('Allow new user registration', 'matrix-mlm'); ?></label></td></tr>
            <tr><th><?php _e('GDPR Compliance', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_gdpr_enabled" value="1" <?php checked(get_option('matrix_mlm_gdpr_enabled', 1)); ?>> <?php _e('Enable GDPR cookie consent', 'matrix-mlm'); ?></label></td></tr>
        </table>
    <?php }

    private function render_matrix_tab() {
        $default_width = get_option('matrix_mlm_default_width', 2);
        $default_depth = get_option('matrix_mlm_default_depth', 3);
        $max_members = Matrix_MLM_Plan_Engine::calculate_max_members($default_width, $default_depth);
        $spillover = get_option('matrix_mlm_spillover_type', 'top_down');
        $auto_reentry = get_option('matrix_mlm_auto_reentry', 1);
        ?>
        <h2><?php _e('Matrix Structure Configuration', 'matrix-mlm'); ?></h2>
        <p class="description" style="margin-bottom: 20px;">
            <?php _e('Configure the default matrix structure for new plans. Each plan can override these settings individually.', 'matrix-mlm'); ?>
        </p>

        <table class="form-table">
            <tr>
                <th><?php _e('Default Matrix Width', 'matrix-mlm'); ?></th>
                <td>
                    <input type="number" name="matrix_mlm_default_width" id="settings_matrix_width" min="1" max="20" value="<?php echo esc_attr($default_width); ?>" class="small-text">
                    <p class="description"><?php _e('Number of direct legs per member. Common configurations:', 'matrix-mlm'); ?></p>
                    <ul style="list-style: disc; margin-left: 20px; margin-top: 5px;">
                        <li><strong>1</strong> — <?php _e('Unilevel (single leg, unlimited depth)', 'matrix-mlm'); ?></li>
                        <li><strong>2</strong> — <?php _e('Binary (2 legs per person)', 'matrix-mlm'); ?></li>
                        <li><strong>3</strong> — <?php _e('Ternary / Trinary (3 legs per person)', 'matrix-mlm'); ?></li>
                        <li><strong>4-10</strong> — <?php _e('Wide matrix (4+ legs per person)', 'matrix-mlm'); ?></li>
                    </ul>
                </td>
            </tr>
            <tr>
                <th><?php _e('Default Matrix Depth', 'matrix-mlm'); ?></th>
                <td>
                    <input type="number" name="matrix_mlm_default_depth" id="settings_matrix_depth" min="1" max="20" value="<?php echo esc_attr($default_depth); ?>" class="small-text">
                    <p class="description"><?php _e('Number of levels deep the matrix goes before completing.', 'matrix-mlm'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('Matrix Preview', 'matrix-mlm'); ?></th>
                <td>
                    <div id="settings-matrix-preview" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;">
                        <div style="display: flex; gap: 30px; align-items: flex-start;">
                            <div>
                                <h4 style="margin: 0 0 10px;" id="settings-matrix-label"><?php echo esc_html(Matrix_MLM_Plan_Engine::get_matrix_label($default_width, $default_depth)); ?></h4>
                                <table style="border-collapse: collapse; font-size: 13px;" id="settings-matrix-breakdown">
                                    <thead><tr><th style="padding: 4px 12px; border-bottom: 1px solid #e2e8f0; text-align: left;"><?php _e('Level', 'matrix-mlm'); ?></th><th style="padding: 4px 12px; border-bottom: 1px solid #e2e8f0; text-align: right;"><?php _e('Positions', 'matrix-mlm'); ?></th></tr></thead>
                                    <tbody>
                                    <?php for ($i = 1; $i <= $default_depth; $i++): ?>
                                        <tr><td style="padding: 4px 12px;"><?php printf(__('Level %d', 'matrix-mlm'), $i); ?></td><td style="padding: 4px 12px; text-align: right;"><?php echo number_format(pow($default_width, $i - 1)); ?></td></tr>
                                    <?php endfor; ?>
                                    </tbody>
                                    <tfoot><tr><td style="padding: 4px 12px; border-top: 2px solid #4f46e5; font-weight: bold;"><?php _e('Total', 'matrix-mlm'); ?></td><td style="padding: 4px 12px; border-top: 2px solid #4f46e5; font-weight: bold; text-align: right;" id="settings-matrix-total"><?php echo number_format($max_members); ?></td></tr></tfoot>
                                </table>
                            </div>
                            <div style="flex: 1;">
                                <p style="margin: 0; color: #64748b; font-size: 13px;">
                                    <?php _e('This means each member must recruit', 'matrix-mlm'); ?> <strong id="settings-width-display"><?php echo $default_width; ?></strong> <?php _e('people directly, and the matrix completes after', 'matrix-mlm'); ?> <strong id="settings-depth-display"><?php echo $default_depth; ?></strong> <?php _e('levels are filled.', 'matrix-mlm'); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
            <tr>
                <th><?php _e('Spillover Placement', 'matrix-mlm'); ?></th>
                <td>
                    <select name="matrix_mlm_spillover_type">
                        <option value="top_down" <?php selected($spillover, 'top_down'); ?>><?php _e('Top-Down (BFS) — Fill left to right, top to bottom', 'matrix-mlm'); ?></option>
                        <option value="sponsor_first" <?php selected($spillover, 'sponsor_first'); ?>><?php _e('Sponsor-First — Place under sponsor tree first', 'matrix-mlm'); ?></option>
                    </select>
                    <p class="description"><?php _e('Determines how new members are placed when their direct sponsor position is full.', 'matrix-mlm'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('Auto Re-Entry', 'matrix-mlm'); ?></th>
                <td>
                    <label><input type="checkbox" name="matrix_mlm_auto_reentry" value="1" <?php checked($auto_reentry, 1); ?>> <?php _e('Automatically re-enter members into a new matrix cycle after completion', 'matrix-mlm'); ?></label>
                    <p class="description"><?php _e('When enabled, members who complete a matrix cycle are automatically placed back at the top of a new cycle.', 'matrix-mlm'); ?></p>
                </td>
            </tr>
        </table>

        <h2 style="margin-top: 30px;"><?php _e('Default Commission Structure', 'matrix-mlm'); ?></h2>
        <p class="description" style="margin-bottom: 15px;">
            <?php _e('Set the default commission values for new plans. Each plan can override these individually.', 'matrix-mlm'); ?>
        </p>

        <?php
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $commission_type = get_option('matrix_mlm_default_commission_type', 'fixed');
        $referral_commission = get_option('matrix_mlm_default_referral_commission', 0);
        $completion_bonus = get_option('matrix_mlm_default_completion_bonus', 0);
        $level_commissions = json_decode(get_option('matrix_mlm_default_level_commissions', '{}'), true);
        if (!is_array($level_commissions)) $level_commissions = [];
        ?>

        <table class="form-table">
            <tr>
                <th><?php _e('Commission Type', 'matrix-mlm'); ?></th>
                <td>
                    <select name="matrix_mlm_default_commission_type" id="settings_commission_type">
                        <option value="fixed" <?php selected($commission_type, 'fixed'); ?>><?php printf(__('Fixed Amount (%s)', 'matrix-mlm'), $currency); ?></option>
                        <option value="percentage" <?php selected($commission_type, 'percentage'); ?>><?php _e('Percentage of Plan Price (%)', 'matrix-mlm'); ?></option>
                    </select>
                    <p class="description"><?php _e('Whether commission values are fixed currency amounts or a percentage of the plan price.', 'matrix-mlm'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('Default Referral Commission', 'matrix-mlm'); ?></th>
                <td>
                    <input type="number" name="matrix_mlm_default_referral_commission" step="0.01" min="0" value="<?php echo esc_attr($referral_commission); ?>" class="small-text">
                    <span id="settings-commission-suffix"><?php echo $commission_type === 'percentage' ? '%' : $currency; ?></span>
                    <p class="description"><?php _e('Paid to the direct sponsor when someone joins under them.', 'matrix-mlm'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('Default Completion Bonus', 'matrix-mlm'); ?></th>
                <td>
                    <input type="number" name="matrix_mlm_default_completion_bonus" step="0.01" min="0" value="<?php echo esc_attr($completion_bonus); ?>" class="small-text">
                    <span id="settings-bonus-suffix"><?php echo $commission_type === 'percentage' ? '%' : $currency; ?></span>
                    <p class="description"><?php _e('Bonus paid when a member completes their full matrix cycle.', 'matrix-mlm'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('Default Level Commissions', 'matrix-mlm'); ?></th>
                <td>
                    <div id="settings-level-commissions">
                        <?php for ($i = 1; $i <= $default_depth; $i++): ?>
                        <div class="level-commission-row" style="margin-bottom: 5px;">
                            <label><?php printf(__('Level %d:', 'matrix-mlm'), $i); ?></label>
                            <input type="number" name="matrix_mlm_default_level_commissions[<?php echo $i; ?>]" step="0.01" min="0" value="<?php echo esc_attr($level_commissions[$i] ?? 0); ?>" style="width: 100px;">
                            <span class="settings-level-suffix"><?php echo $commission_type === 'percentage' ? '%' : $currency; ?></span>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <p class="description"><?php _e('Commission paid to upline members at each level when a new member joins. Fields update automatically when you change the depth above.', 'matrix-mlm'); ?></p>
                </td>
            </tr>
        </table>

        <script>
        (function() {
            var widthEl = document.getElementById('settings_matrix_width');
            var depthEl = document.getElementById('settings_matrix_depth');
            var commTypeEl = document.getElementById('settings_commission_type');
            var levelContainer = document.getElementById('settings-level-commissions');
            var currency = '<?php echo esc_js($currency); ?>';

            function getTypeLabel(width) {
                if (width == 1) return 'Unilevel';
                if (width == 2) return 'Binary';
                if (width == 3) return 'Ternary';
                return width + '-Wide';
            }

            function getSuffix() {
                return commTypeEl.value === 'percentage' ? '%' : currency;
            }

            function updateAllSuffixes() {
                var suffix = getSuffix();
                document.getElementById('settings-commission-suffix').textContent = suffix;
                document.getElementById('settings-bonus-suffix').textContent = suffix;
                var spans = levelContainer.querySelectorAll('.settings-level-suffix');
                spans.forEach(function(s) { s.textContent = suffix; });
            }

            function updatePreview() {
                var w = parseInt(widthEl.value) || 2;
                var d = parseInt(depthEl.value) || 3;
                var total = 0;
                var rows = '';
                for (var i = 1; i <= d; i++) {
                    var positions = Math.pow(w, i - 1);
                    total += positions;
                    rows += '<tr><td style="padding:4px 12px;">Level ' + i + '</td><td style="padding:4px 12px;text-align:right;">' + positions.toLocaleString() + '</td></tr>';
                }
                document.getElementById('settings-matrix-label').textContent = getTypeLabel(w) + ' (' + w + 'x' + d + ')';
                document.getElementById('settings-matrix-breakdown').querySelector('tbody').innerHTML = rows;
                document.getElementById('settings-matrix-total').textContent = total.toLocaleString();
                document.getElementById('settings-width-display').textContent = w;
                document.getElementById('settings-depth-display').textContent = d;
            }

            function updateLevelCommissions() {
                var depth = parseInt(depthEl.value) || 3;
                var suffix = getSuffix();
                var existingValues = {};

                var inputs = levelContainer.querySelectorAll('input[type="number"]');
                inputs.forEach(function(input) {
                    var match = input.name.match(/\[(\d+)\]/);
                    if (match) existingValues[match[1]] = input.value;
                });

                var html = '';
                for (var i = 1; i <= depth; i++) {
                    var val = existingValues[i] || '0';
                    html += '<div class="level-commission-row" style="margin-bottom: 5px;">';
                    html += '<label>Level ' + i + ':</label> ';
                    html += '<input type="number" name="matrix_mlm_default_level_commissions[' + i + ']" step="0.01" min="0" value="' + val + '" style="width: 100px;"> ';
                    html += '<span class="settings-level-suffix">' + suffix + '</span>';
                    html += '</div>';
                }
                levelContainer.innerHTML = html;
            }

            widthEl.addEventListener('input', updatePreview);
            depthEl.addEventListener('input', function() {
                updatePreview();
                updateLevelCommissions();
            });
            commTypeEl.addEventListener('change', updateAllSuffixes);
        })();
        </script>
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

    private function render_subscription_tab() {
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <h2><?php _e('Monthly Subscription', 'matrix-mlm'); ?></h2>
        <p class="description" style="margin-bottom: 20px;">
            <?php _e('Configure automatic monthly payments. Users who do not pay within the grace period will be set to inactive.', 'matrix-mlm'); ?>
        </p>

        <table class="form-table">
            <tr>
                <th><?php _e('Enable Monthly Subscription', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_subscription_enabled" value="1" <?php checked(get_option('matrix_mlm_subscription_enabled', 0)); ?>> <?php _e('Charge users a monthly fee to stay active', 'matrix-mlm'); ?></label></td>
            </tr>
            <tr>
                <th><?php _e('Monthly Amount', 'matrix-mlm'); ?> (<?php echo $currency; ?>)</th>
                <td>
                    <input type="number" name="matrix_mlm_subscription_amount" step="0.01" min="0" value="<?php echo esc_attr(get_option('matrix_mlm_subscription_amount', 0)); ?>" class="regular-text">
                    <p class="description"><?php _e('Amount charged from each user\'s Matrix wallet every month.', 'matrix-mlm'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('Billing Day', 'matrix-mlm'); ?></th>
                <td>
                    <input type="number" name="matrix_mlm_subscription_billing_day" min="1" max="28" value="<?php echo esc_attr(get_option('matrix_mlm_subscription_billing_day', 1)); ?>" class="small-text">
                    <p class="description"><?php _e('Day of the month when subscriptions are charged (1-28). Using 28 or lower ensures consistency across all months.', 'matrix-mlm'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('Grace Period (Days)', 'matrix-mlm'); ?></th>
                <td>
                    <input type="number" name="matrix_mlm_subscription_grace_days" min="0" max="15" value="<?php echo esc_attr(get_option('matrix_mlm_subscription_grace_days', 3)); ?>" class="small-text">
                    <p class="description"><?php _e('Number of days after billing day before user is deactivated. Set to 0 for immediate deactivation.', 'matrix-mlm'); ?></p>
                </td>
            </tr>
        </table>

        <?php
        // Show subscription stats
        global $wpdb;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}matrix_subscriptions'");
        if ($table_exists):
            $current_month = date('Y-m');
            $paid_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_subscriptions WHERE billing_month = %s AND status = 'paid'", $current_month));
            $unpaid_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_subscriptions WHERE billing_month = %s AND status IN ('unpaid','overdue')", $current_month));
            $total_collected = $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_subscriptions WHERE billing_month = %s AND status = 'paid'", $current_month));
        ?>
        <h3><?php printf(__('This Month (%s)', 'matrix-mlm'), date('F Y')); ?></h3>
        <div class="matrix-admin-stats" style="margin-bottom: 20px;">
            <div class="stat-card stat-success"><h3><?php echo number_format($paid_count); ?></h3><p><?php _e('Paid', 'matrix-mlm'); ?></p></div>
            <div class="stat-card stat-danger"><h3><?php echo number_format($unpaid_count); ?></h3><p><?php _e('Unpaid/Overdue', 'matrix-mlm'); ?></p></div>
            <div class="stat-card stat-info"><h3><?php echo $currency . number_format($total_collected, 2); ?></h3><p><?php _e('Collected', 'matrix-mlm'); ?></p></div>
        </div>
        <?php endif; ?>
    <?php }

    private function render_fintava_tab() {
        $currency_symbol = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <h2><?php _e('Fintava Pay - Operational Settings', 'matrix-mlm'); ?></h2>
        <p class="description">
            <?php
            printf(
                wp_kses(
                    /* translators: %s: link to Gateways admin page */
                    __('API credentials live on the <a href="%s">Gateways</a> page. This tab only controls payout limits and service charges.', 'matrix-mlm'),
                    ['a' => ['href' => []]]
                ),
                esc_url(admin_url('admin.php?page=matrix-mlm-gateways'))
            );
            ?>
        </p>

        <?php
        // Show merchant balance if Fintava is configured.
        $fintava = new Matrix_MLM_Fintava();
        if ($fintava->is_active()):
            $balance = $fintava->get_merchant_balance();
            if (!is_wp_error($balance)):
                // Defense-in-depth: get_merchant_balance() should always
                // hand back scalar floats, but a legacy/edge response shape
                // could still leave a nested array here. number_format()
                // throws a fatal TypeError on PHP 8+ when handed an array,
                // so coerce to a numeric value before formatting.
                $balance_value = $balance['available_balance'] ?? $balance['balance'] ?? 0;
                if (!is_scalar($balance_value) || !is_numeric($balance_value)) {
                    $balance_value = 0;
                }
        ?>
        <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 16px 20px; margin: 15px 0 25px;">
            <h3 style="margin: 0 0 8px; color: #065f46;"><?php _e('Merchant Wallet Balance', 'matrix-mlm'); ?></h3>
            <p style="font-size: 28px; font-weight: 700; color: #059669; margin: 0;">
                <?php echo esc_html($currency_symbol . number_format((float) $balance_value, 2)); ?>
            </p>
            <small style="color: #065f46;"><?php _e('Available for payouts', 'matrix-mlm'); ?></small>
        </div>
            <?php endif; ?>
        <?php endif; ?>

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

        <h3><?php _e('API Endpoints Used', 'matrix-mlm'); ?></h3>
        <table class="wp-list-table widefat fixed striped" style="max-width: 600px;">
            <thead><tr><th><?php _e('Action', 'matrix-mlm'); ?></th><th><?php _e('Endpoint', 'matrix-mlm'); ?></th></tr></thead>
            <tbody>
                <tr><td><?php _e('Merchant Balance', 'matrix-mlm'); ?></td><td><code>GET /merchant/balance</code></td></tr>
                <tr><td><?php _e('Bank Payout (User Fintava Wallet → Bank)', 'matrix-mlm'); ?></td><td><code>POST /bank/credit</code> @ configured env host</td></tr>
                <tr><td><?php _e('Merchant Bank Credit (legacy / unused on bank-payout path)', 'matrix-mlm'); ?></td><td><code>POST /bank/credit/merchant</code></td></tr>
                <tr><td><?php _e('Generate Virtual Wallet', 'matrix-mlm'); ?></td><td><code>POST /virtual-wallet/generate</code></td></tr>
                <tr><td><?php _e('Request Physical Card', 'matrix-mlm'); ?></td><td><code>POST /cards/physical/request</code></td></tr>
                <tr><td><?php _e('Card Status', 'matrix-mlm'); ?></td><td><code>GET /cards/status</code></td></tr>
                <tr><td><?php _e('Link Card', 'matrix-mlm'); ?></td><td><code>POST /cards/link</code></td></tr>
                <tr><td><?php _e('Activate Card', 'matrix-mlm'); ?></td><td><code>POST /cards/activate</code></td></tr>
                <tr><td><?php _e('View Card', 'matrix-mlm'); ?></td><td><code>GET /cards/fetch/{id}</code></td></tr>
            </tbody>
        </table>
    <?php }

    private function save_settings() {
        $tab = sanitize_text_field($_POST['settings_tab']);

        $settings = [];
        switch ($tab) {
            case 'general':
                $settings = ['matrix_mlm_site_title', 'matrix_mlm_currency', 'matrix_mlm_currency_symbol', 'matrix_mlm_registration_enabled', 'matrix_mlm_gdpr_enabled'];
                break;
            case 'matrix':
                $settings = ['matrix_mlm_default_width', 'matrix_mlm_default_depth', 'matrix_mlm_spillover_type', 'matrix_mlm_auto_reentry', 'matrix_mlm_default_commission_type', 'matrix_mlm_default_referral_commission', 'matrix_mlm_default_completion_bonus'];
                // Handle level commissions as JSON
                if (isset($_POST['matrix_mlm_default_level_commissions']) && is_array($_POST['matrix_mlm_default_level_commissions'])) {
                    $level_comms = array_map('floatval', $_POST['matrix_mlm_default_level_commissions']);
                    update_option('matrix_mlm_default_level_commissions', json_encode($level_comms));
                }
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
            case 'subscription':
                $settings = ['matrix_mlm_subscription_enabled', 'matrix_mlm_subscription_amount', 'matrix_mlm_subscription_billing_day', 'matrix_mlm_subscription_grace_days'];
                // Create subscriptions table if it doesn't exist
                Matrix_MLM_Subscription::create_table();
                // Schedule or unschedule cron based on enabled status
                if (!empty($_POST['matrix_mlm_subscription_enabled'])) {
                    if (!wp_next_scheduled('matrix_mlm_monthly_subscription')) {
                        wp_schedule_event(time(), 'daily', 'matrix_mlm_monthly_subscription');
                    }
                } else {
                    wp_clear_scheduled_hook('matrix_mlm_monthly_subscription');
                }
                break;
            case 'fintava':
                // Credentials live on the Gateways page now; only save
                // operational fields here.
                $settings = ['matrix_mlm_fintava_min_payout', 'matrix_mlm_fintava_max_payout', 'matrix_mlm_fintava_charge_type', 'matrix_mlm_fintava_charge_value'];
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
        $checkboxes = ['matrix_mlm_registration_enabled', 'matrix_mlm_gdpr_enabled', 'matrix_mlm_email_verification', 'matrix_mlm_sms_verification', 'matrix_mlm_2fa_enabled', 'matrix_mlm_captcha_enabled', 'matrix_mlm_livechat_enabled', 'matrix_mlm_auto_reentry', 'matrix_mlm_subscription_enabled'];
        foreach ($checkboxes as $cb) {
            if (in_array($cb, $settings) && !isset($_POST[$cb])) {
                update_option($cb, 0);
            }
        }

        echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'matrix-mlm') . '</p></div>';
    }
}
