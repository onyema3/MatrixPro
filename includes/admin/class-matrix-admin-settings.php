<?php
/**
 * Admin Settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Settings {

    public function render() {
        if (isset($_POST['matrix_repair_billing_schema']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'matrix_save_settings')) {
            // Click on the Repair Bill Payments Schema button at the
            // bottom of the Bill Payments tab. Routed BEFORE the
            // save_settings() branch because (a) browsers only
            // submit the clicked submit button's name+value, so
            // when the operator clicks Repair the save_settings
            // input is absent from POST and save_settings() would
            // never run anyway, but (b) keeping repair as a
            // sibling elseif makes the dispatch contract explicit
            // for any future button added to the same form.
            $this->repair_billing_schema();
        } elseif (isset($_POST['save_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_save_settings')) {
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
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=dashboard_menus'); ?>" class="nav-tab <?php echo $tab === 'dashboard_menus' ? 'nav-tab-active' : ''; ?>"><?php _e('Dashboard Menus', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=sms'); ?>" class="nav-tab <?php echo $tab === 'sms' ? 'nav-tab-active' : ''; ?>"><?php _e('SMS', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=language'); ?>" class="nav-tab <?php echo $tab === 'language' ? 'nav-tab-active' : ''; ?>"><?php _e('Language', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=livechat'); ?>" class="nav-tab <?php echo $tab === 'livechat' ? 'nav-tab-active' : ''; ?>"><?php _e('Livechat', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=subscription'); ?>" class="nav-tab <?php echo $tab === 'subscription' ? 'nav-tab-active' : ''; ?>"><?php _e('Subscription', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=fintava'); ?>" class="nav-tab <?php echo $tab === 'fintava' ? 'nav-tab-active' : ''; ?>"><?php _e('Fintava', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-settings&tab=bill_payments'); ?>" class="nav-tab <?php echo $tab === 'bill_payments' ? 'nav-tab-active' : ''; ?>"><?php _e('Bill Payments', 'matrix-mlm'); ?></a>
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
                    case 'dashboard_menus': $this->render_dashboard_menus_tab(); break;
                    case 'sms': $this->render_sms_tab(); break;
                    case 'language': $this->render_language_tab(); break;
                    case 'livechat': $this->render_livechat_tab(); break;
                    case 'subscription': $this->render_subscription_tab(); break;
                    case 'fintava': $this->render_fintava_tab(); break;
                    case 'bill_payments': $this->render_bill_payments_tab(); break;
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

    private function render_financial_tab() {
        // Required-plans gate is rendered as a checklist of active
        // plans. Stored as a CSV string of plan IDs (see save_settings)
        // because the option-save loop runs sanitize_text_field on
        // every value, which would mangle an array. CSV survives that
        // and matches the parsing contract in
        // Matrix_MLM_User::can_move_funds().
        global $wpdb;
        $active_plans = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}matrix_plans WHERE status = 'active' ORDER BY price ASC"
        );
        $required_plans_csv = (string) get_option('matrix_mlm_withdraw_required_plans', '');
        $required_plan_ids  = array_filter(array_map('intval', preg_split('/[,\s]+/', $required_plans_csv)));
        ?>
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

            <tr><td colspan="2" style="padding-top:24px;border-bottom:1px solid #e5e7eb;padding-bottom:8px;">
                <h3 style="margin:0;color:#1f2937;"><?php _e('Withdrawal Controls', 'matrix-mlm'); ?></h3>
                <p class="description" style="margin-top:4px;">
                    <?php _e('Five separated toggles that gate every fund-movement surface on the platform. All are evaluated by <code>Matrix_MLM_User::can_move_funds($user_id, $path)</code>; changes here take effect immediately on the next request, no cache to bust.', 'matrix-mlm'); ?>
                </p>
                <ul class="description" style="margin:8px 0 0 18px;list-style:disc;color:#4b5563;">
                    <li><?php _e('<strong>Master kill switch</strong> &mdash; blocks every path below.', 'matrix-mlm'); ?></li>
                    <li><?php _e('<strong>Active-account</strong> and <strong>Plan-tier</strong> &mdash; user-eligibility gates that apply uniformly to every path so they cannot be circumvented through a peer transfer.', 'matrix-mlm'); ?></li>
                    <li><?php _e('<strong>Matrix Transfers</strong> &mdash; gates Matrix-wallet-sourced flows (Wallet to Wallet + Transfer to Own Wallet).', 'matrix-mlm'); ?></li>
                    <li><?php _e('<strong>Bank Transfers</strong> &mdash; gates the Fintava virtual &rarr; external bank flow (Transfer to Bank).', 'matrix-mlm'); ?></li>
                </ul>
            </td></tr>

            <tr><th><?php _e('Master Kill Switch', 'matrix-mlm'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="matrix_mlm_withdrawals_enabled" value="1" <?php checked(get_option('matrix_mlm_withdrawals_enabled', 1)); ?>>
                        <?php _e('Allow users to move funds', 'matrix-mlm'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Master kill switch. Uncheck to pause every fund-movement flow site-wide &mdash; useful during reconciliation, an incident with the payment gateway, or a freeze window before a re-import. Users see a friendly "Withdrawals are temporarily disabled" message; deposits and admin actions are unaffected.', 'matrix-mlm'); ?>
                    </p>
                </td></tr>

            <tr><th><?php _e('Require Active Account', 'matrix-mlm'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="matrix_mlm_withdraw_require_active_user" value="1" <?php checked(get_option('matrix_mlm_withdraw_require_active_user', 1)); ?>>
                        <?php _e('Block fund movement from banned or inactive accounts', 'matrix-mlm'); ?>
                    </label>
                    <p class="description">
                        <?php _e('When on, only users whose status is "active" in matrix_user_meta can move funds &mdash; on every path including peer wallet-to-wallet, so a banned user cannot relay through an accomplice. Defaults on. Uncheck only if you need to let a banned user drain their balance as part of a controlled close-out.', 'matrix-mlm'); ?>
                    </p>
                </td></tr>

            <tr><th><?php _e('Restrict to Specific Plans', 'matrix-mlm'); ?></th>
                <td>
                    <?php if (empty($active_plans)): ?>
                        <p style="color:#6b7280;font-style:italic;">
                            <?php _e('No active plans defined yet. When you add plans on the Plans page, they will appear here as checkboxes.', 'matrix-mlm'); ?>
                        </p>
                    <?php else: ?>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;max-width:600px;">
                            <?php foreach ($active_plans as $plan):
                                $pid = (int) $plan->id;
                                ?>
                                <label style="display:flex;align-items:center;gap:6px;padding:6px 8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;">
                                    <input type="checkbox" name="matrix_mlm_withdraw_required_plans[]" value="<?php echo $pid; ?>" <?php checked(in_array($pid, $required_plan_ids, true)); ?>>
                                    <span><?php echo esc_html($plan->name); ?> <small style="color:#9ca3af;">#<?php echo $pid; ?></small></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description" style="margin-top:10px;">
                            <?php _e('When any plans are checked above, only users who have at least one ACTIVE position on one of the selected plans can move funds &mdash; on every path including peer wallet-to-wallet, to close the relay loophole where a non-qualifying user transfers their balance to a qualifying accomplice. Leave everything unchecked to allow fund movement from users on any plan (the default).', 'matrix-mlm'); ?>
                        </p>
                    <?php endif; ?>
                </td></tr>

            <tr><th><?php _e('Matrix Transfers', 'matrix-mlm'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="matrix_mlm_matrix_transfers_enabled" value="1" <?php checked(get_option('matrix_mlm_matrix_transfers_enabled', 1)); ?>>
                        <?php _e('Allow Matrix wallet transfers', 'matrix-mlm'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Path-specific toggle. Gates the two Matrix-wallet-sourced flows in the Internal Transfers group on the Wallet page: <strong>Wallet to Wallet</strong> (peer Matrix &rarr; Matrix) and <strong>Transfer to Own Wallet</strong> (Matrix &rarr; user\'s Fintava virtual). Defaults on. Uncheck to disable Matrix-side movement without affecting the Fintava &rarr; external bank flow below.', 'matrix-mlm'); ?>
                    </p>
                </td></tr>

            <tr><th><?php _e('Bank Transfers', 'matrix-mlm'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="matrix_mlm_bank_transfers_enabled" value="1" <?php
                            // Read with the legacy fallback so an admin who hasn't re-saved
                            // Financial since this toggle moved here from Gateways → Fintava
                            // sees their previous setting reflected in the checkbox state.
                            $bank_raw = get_option('matrix_mlm_bank_transfers_enabled', null);
                            if ($bank_raw === null || $bank_raw === false || $bank_raw === '') {
                                $bank_raw = get_option('matrix_mlm_fintava_payouts_enabled', 1);
                            }
                            checked((int) $bank_raw, 1);
                        ?>>
                        <?php _e('Allow Fintava virtual &rarr; external bank transfers', 'matrix-mlm'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Path-specific toggle. Gates the <strong>Transfer to Bank</strong> button + pane in the External Transfers group on the Wallet page (Fintava virtual wallet &rarr; external Nigerian bank, instant via Fintava\'s /bank/credit endpoint). Defaults on. Uncheck to keep the Fintava integration available (virtual wallet, Matrix &rarr; Virtual transfer, bills) while blocking only the external cash-out pane &mdash; useful when you want to freeze the highest-risk surface without breaking the rest of the platform. This option moved here from Gateways &rarr; Fintava in refactor/withdrawal-controls-five-toggles; the legacy <code>matrix_mlm_fintava_payouts_enabled</code> key is still read as a fallback so existing installs don\'t lose their setting until they re-save here.', 'matrix-mlm'); ?>
                    </p>
                </td></tr>
        </table>
    <?php }

    private function render_notifications_tab() {
        $admin_fallback = (string) get_option('admin_email');
        $cug_recipients        = (string) get_option('matrix_mlm_cug_notification_email', '');
        $loan_recipients       = (string) get_option('matrix_mlm_loan_notification_email', '');
        $healthcare_recipients = (string) get_option('matrix_mlm_healthcare_notification_email', '');
        $shared_recipients     = (string) get_option('matrix_mlm_application_notification_email', '');
        ?>
        <table class="form-table">
            <tr><th><?php _e('Email Verification', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_email_verification" value="1" <?php checked(get_option('matrix_mlm_email_verification', 1)); ?>> <?php _e('Require email verification on registration', 'matrix-mlm'); ?></label></td></tr>
            <tr><th><?php _e('SMS Verification', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_sms_verification" value="1" <?php checked(get_option('matrix_mlm_sms_verification', 0)); ?>> <?php _e('Require SMS verification', 'matrix-mlm'); ?></label></td></tr>

            <tr><td colspan="2" style="padding-top:24px;border-bottom:1px solid #e5e7eb;padding-bottom:8px;">
                <h3 style="margin:0;color:#1f2937;"><?php _e('Application Review Notifications', 'matrix-mlm'); ?></h3>
                <p class="description" style="margin-top:4px;">
                    <?php _e('Each benefit can have its own reviewer email so different teams triage different applications. Leave a benefit-specific field blank to fall through to the Shared fallback below; leave that blank too and the WordPress admin email is used. Multiple addresses per field — separate with commas.', 'matrix-mlm'); ?>
                </p>
            </td></tr>

            <tr><th><?php _e('CUG Reviewer', 'matrix-mlm'); ?></th>
                <td>
                    <input type="text" name="matrix_mlm_cug_notification_email" class="regular-text"
                           value="<?php echo esc_attr($cug_recipients); ?>"
                           placeholder="<?php echo esc_attr($shared_recipients !== '' ? $shared_recipients : $admin_fallback); ?>">
                    <p class="description">
                        <?php _e('Receives a full copy of every CUG application as soon as it is submitted.', 'matrix-mlm'); ?>
                    </p>
                </td></tr>

            <tr><th><?php _e('Loan Reviewer', 'matrix-mlm'); ?></th>
                <td>
                    <input type="text" name="matrix_mlm_loan_notification_email" class="regular-text"
                           value="<?php echo esc_attr($loan_recipients); ?>"
                           placeholder="<?php echo esc_attr($shared_recipients !== '' ? $shared_recipients : $admin_fallback); ?>">
                    <p class="description">
                        <?php _e('Receives a full copy of every business-loan application as soon as it is submitted.', 'matrix-mlm'); ?>
                    </p>
                </td></tr>

            <tr><th><?php _e('Healthcare Reviewer', 'matrix-mlm'); ?></th>
                <td>
                    <input type="text" name="matrix_mlm_healthcare_notification_email" class="regular-text"
                           value="<?php echo esc_attr($healthcare_recipients); ?>"
                           placeholder="<?php echo esc_attr($shared_recipients !== '' ? $shared_recipients : $admin_fallback); ?>">
                    <p class="description">
                        <?php _e('Receives a full copy of every Healthcare (HMO) enrolment application as soon as it is submitted.', 'matrix-mlm'); ?>
                    </p>
                </td></tr>

            <tr><th><?php _e('Shared fallback', 'matrix-mlm'); ?></th>
                <td>
                    <input type="text" name="matrix_mlm_application_notification_email" class="regular-text"
                           value="<?php echo esc_attr($shared_recipients); ?>"
                           placeholder="<?php echo esc_attr($admin_fallback); ?>">
                    <p class="description">
                        <?php _e('Used for any benefit whose own field above is blank. Useful when one team handles multiple benefits, or as a safety net during the migration from the old single-recipient setup. Leave blank to fall through to the WordPress admin email.', 'matrix-mlm'); ?>
                    </p>
                </td></tr>
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

    private function render_appearance_tab() {
        $login_logo_url = get_option('matrix_mlm_login_logo_url', '');
        $login_logo_id  = get_option('matrix_mlm_login_logo_id', '');
        ?>
        <table class="form-table">
            <tr><th><?php _e('Login Page Logo', 'matrix-mlm'); ?></th>
                <td>
                    <div class="matrix-media-uploader" data-target="matrix_mlm_login_logo">
                        <div class="matrix-media-preview" style="margin-bottom:10px;<?php echo $login_logo_url ? '' : 'display:none;'; ?>">
                            <img src="<?php echo esc_url($login_logo_url); ?>" alt="" style="max-height:80px;max-width:240px;border:1px solid #ddd;padding:6px;background:#fff;border-radius:4px;">
                        </div>
                        <input type="hidden" name="matrix_mlm_login_logo_url" class="matrix-media-url" value="<?php echo esc_attr($login_logo_url); ?>">
                        <input type="hidden" name="matrix_mlm_login_logo_id"  class="matrix-media-id"  value="<?php echo esc_attr($login_logo_id); ?>">
                        <button type="button" class="button matrix-media-upload"><?php _e('Upload / Select Logo', 'matrix-mlm'); ?></button>
                        <button type="button" class="button matrix-media-remove" style="<?php echo $login_logo_url ? '' : 'display:none;'; ?>"><?php _e('Remove', 'matrix-mlm'); ?></button>
                    </div>
                    <p class="description"><?php _e('Logo shown above the login form. Recommended height: 80px.', 'matrix-mlm'); ?></p>
                </td></tr>
            <tr><th><?php _e('Primary Color', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_primary_color" class="matrix-color-picker" value="<?php echo esc_attr(get_option('matrix_mlm_primary_color', '#4f46e5')); ?>"></td></tr>
            <tr><th><?php _e('Secondary Color', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_secondary_color" class="matrix-color-picker" value="<?php echo esc_attr(get_option('matrix_mlm_secondary_color', '#7c3aed')); ?>"></td></tr>
            <tr><th><?php _e('Custom CSS', 'matrix-mlm'); ?></th>
                <td><textarea name="matrix_mlm_custom_css" rows="10" class="large-text code"><?php echo esc_textarea(get_option('matrix_mlm_custom_css', '')); ?></textarea></td></tr>
        </table>
    <?php }

    /**
     * Dashboard Menus tab — per-item visibility toggles for the user
     * dashboard sidebar. Storage: matrix_mlm_dashboard_menu_visibility
     * (JSON-encoded slug => 0|1 map). Read at render time by
     * Matrix_MLM_User_Dashboard::is_menu_visible(); see that helper for
     * the resolution semantics.
     *
     * Three slugs (overview, profile, security) are LOCKED on the user
     * side via Matrix_MLM_User_Dashboard::LOCKED_MENU_SLUGS — hiding
     * them would either strand users on a dashboard with no landing
     * tab or remove the only place a user can edit KYC / manage 2FA.
     * They render here as disabled-and-checked checkboxes with a "Locked"
     * pill so the admin can see the full menu and understand why those
     * three can't be turned off.
     */
    private function render_dashboard_menus_tab() {
        $items     = Matrix_MLM_User_Dashboard::dashboard_menu_definition();
        $locked    = Matrix_MLM_User_Dashboard::LOCKED_MENU_SLUGS;
        $stored    = get_option('matrix_mlm_dashboard_menu_visibility', '');
        $stored    = is_string($stored) && $stored !== '' ? json_decode($stored, true) : [];
        if (!is_array($stored)) {
            $stored = [];
        }
        ?>
        <p class="description" style="margin-bottom:14px;">
            <?php _e('Toggle individual menu items in the user dashboard sidebar. Disabled items are hidden from the navigation and direct URLs to those tabs fall through to the Dashboard overview. Three core items (Dashboard, Profile, 2FA Security) are locked and cannot be hidden because users would otherwise have no landing screen, no way to update their profile, or no way to manage their 2FA.', 'matrix-mlm'); ?>
        </p>
        <table class="form-table">
            <?php foreach ($items as $item):
                $slug      = $item['slug'];
                $is_locked = in_array($slug, $locked, true);
                // Default to ON when no per-slug preference has been
                // saved yet — matches is_menu_visible()'s missing-key
                // default and gives fresh installs an opt-OUT model.
                $checked   = $is_locked
                    ? true
                    : (array_key_exists($slug, $stored) ? (bool) $stored[$slug] : true);
                ?>
                <tr>
                    <th scope="row">
                        <span class="dashicons <?php echo esc_attr($item['icon']); ?>" style="vertical-align:middle;color:#6b7280;"></span>
                        <?php echo esc_html($item['label']); ?>
                        <?php if ($is_locked): ?>
                            <span style="display:inline-block;margin-left:6px;padding:1px 8px;font-size:11px;font-weight:600;background:#fef3c7;color:#92400e;border-radius:9999px;vertical-align:middle;"><?php _e('Locked', 'matrix-mlm'); ?></span>
                        <?php endif; ?>
                    </th>
                    <td>
                        <label>
                            <?php if ($is_locked): ?>
                                <input type="checkbox" checked disabled>
                                <?php
                                /* translators: tooltip explaining why a core menu item can't be hidden */
                                _e('Always visible (required by the dashboard).', 'matrix-mlm'); ?>
                            <?php else: ?>
                                <input type="checkbox"
                                       name="matrix_mlm_dashboard_menu_visibility[<?php echo esc_attr($slug); ?>]"
                                       value="1"
                                       <?php checked($checked); ?>>
                                <?php _e('Show this menu item in the user dashboard sidebar.', 'matrix-mlm'); ?>
                            <?php endif; ?>
                        </label>
                        <p class="description" style="margin-top:4px;">
                            <?php
                            /* translators: %s is a URL slug for one of the user-dashboard tabs */
                            printf(esc_html__('Slug: %s', 'matrix-mlm'), '<code>' . esc_html($slug) . '</code>');
                            ?>
                        </p>
                    </td>
                </tr>
            <?php endforeach; ?>
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
                <tr><td><?php _e('Create Physical Card (Verve, STATIC_NO_ACCOUNT)', 'matrix-mlm'); ?></td><td><code>POST /cards/physical</code></td></tr>
                <tr><td><?php _e('Link Card to Wallet', 'matrix-mlm'); ?></td><td><code>POST /cards/link</code></td></tr>
                <tr><td><?php _e('View Card', 'matrix-mlm'); ?></td><td><code>GET /cards/fetch/{id}</code></td></tr>
                <tr><td><?php _e('Activate Card', 'matrix-mlm'); ?></td><td><code>POST /cards/activate</code></td></tr>
            </tbody>
        </table>
    <?php }

    /**
     * Bill Payments tab — per-category visibility toggles for the
     * user-facing /matrix-dashboard/billing surface.
     *
     * Storage: matrix_mlm_billing_category_visibility (JSON-encoded
     * category => 0|1 map). Read at render-and-AJAX time by
     * Matrix_MLM_Fintava_Billing::is_category_enabled(); see that
     * helper for the resolution semantics.
     *
     * Use cases:
     *   - Pause Electricity during a disco outage without breaking
     *     Airtime / Data / Cable.
     *   - Disable Cable for a particular tenant whose contract
     *     doesn't include cable resale.
     *   - Soft-launch a new category by leaving it off until the
     *     UI / margin config is ready.
     *
     * Unlike the dashboard-menus toggle (which hides the whole
     * Bill Payments tab from the sidebar), these toggles operate
     * INSIDE the tab. An admin can use either; they compose
     * naturally because the dashboard-menu hide takes effect
     * before this tab even renders.
     */
    private function render_bill_payments_tab() {
        // One-shot notice surfaced after the markup save handler
        // clamped a value the operator entered (typically a flat
        // fee that exceeded resolve_max_flat_fee()'s default of
        // 1000). Saving silently after a clamp would leave the
        // operator confused about why the value they see on
        // reload isn't the value they typed. The transient is
        // keyed on the current user so two admins editing
        // settings at the same time don't see each other's
        // clamp notices.
        $clamped_key = 'matrix_mlm_billing_markup_clamped_' . get_current_user_id();
        $clamped     = get_transient($clamped_key);
        if (is_array($clamped) && !empty($clamped)) {
            delete_transient($clamped_key);
            $currency = get_option('matrix_mlm_currency_symbol', '₦');
            ?>
            <div class="notice notice-warning" style="margin:10px 0 20px;">
                <p style="margin:8px 0;"><strong><?php _e('Some markup values were capped on save:', 'matrix-mlm'); ?></strong></p>
                <ul style="margin:0 0 10px 20px;list-style:disc;">
                    <?php foreach ($clamped as $row):
                        if (!is_array($row)) continue;
                        $slug    = (string) ($row['slug'] ?? '');
                        $field   = (string) ($row['field'] ?? '');
                        $entered = (float) ($row['entered'] ?? 0);
                        $capped  = (float) ($row['capped']  ?? 0);
                        ?>
                        <li>
                            <?php
                            printf(
                                /* translators: 1: category, 2: field name (flat), 3: entered value, 4: capped value */
                                esc_html__('%1$s — %2$s: you entered %3$s, saved as %4$s.', 'matrix-mlm'),
                                '<code>' . esc_html($slug) . '</code>',
                                '<code>' . esc_html($field) . '</code>',
                                '<strong>' . esc_html($currency . number_format($entered, 2)) . '</strong>',
                                '<strong>' . esc_html($currency . number_format($capped,  2)) . '</strong>'
                            );
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin:8px 0;"><?php
                    printf(
                        /* translators: 1: filter name */
                        esc_html__('To raise the cap for a legitimate use case, hook the %1$s filter. Otherwise, this is most likely a typo — please double-check the value before saving again.', 'matrix-mlm'),
                        '<code>matrix_mlm_fintava_billing_max_flat_fee</code>'
                    );
                ?></p>
            </div>
            <?php
        }

        $categories = [
            'airtime'     => [
                'label'       => __('Airtime', 'matrix-mlm'),
                'description' => __('MTN, GLO, Airtel, 9mobile airtime top-ups via Fintava /billing/airtime.', 'matrix-mlm'),
            ],
            'data' => [
                'label'       => __('Data Bundles', 'matrix-mlm'),
                'description' => __('Per-network data plans via Fintava /billing/data-bundles + /billing/data-bundle.', 'matrix-mlm'),
            ],
            'cable' => [
                'label'       => __('Cable TV', 'matrix-mlm'),
                'description' => __('DSTV, GOTV, Startimes etc. subscription renewals via Fintava /billing/cable-subscription.', 'matrix-mlm'),
            ],
            'electricity' => [
                'label'       => __('Electricity', 'matrix-mlm'),
                'description' => __('Prepaid + postpaid bill payments via Fintava /billing/electricity. Disabling this also disables the meter-verify lookup, since it is only useful inside this flow.', 'matrix-mlm'),
            ],
        ];
        $stored = get_option('matrix_mlm_billing_category_visibility', '');
        $stored = is_string($stored) && $stored !== '' ? json_decode($stored, true) : [];
        if (!is_array($stored)) {
            $stored = [];
        }

        // Service fee / markup config (item C). Read via the helper
        // on the gateway class so the admin form, the PHP fee
        // computation, and the JS preview all see the same shape:
        // every BILL_CATEGORIES slug present, flat + percent both
        // numeric, percent capped at 100.
        $markup   = Matrix_MLM_Fintava_Billing::get_markup_config();
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <h2><?php _e('Bill Payments — Category Visibility', 'matrix-mlm'); ?></h2>
        <p class="description" style="margin-bottom:14px;">
            <?php _e('Enable or disable individual bill categories on the user dashboard. A disabled category is hidden from the Bill Payments tab strip and its AJAX endpoints reject incoming purchases server-side &mdash; safe to use for short-notice outages without redeploying. Existing transaction history remains visible regardless of these toggles.', 'matrix-mlm'); ?>
        </p>
        <table class="form-table">
            <?php foreach ($categories as $slug => $meta):
                // Default ON when no per-slug preference has been
                // saved yet — matches is_category_enabled()'s
                // missing-key default.
                $checked = array_key_exists($slug, $stored) ? (bool) $stored[$slug] : true;
                ?>
                <tr>
                    <th scope="row"><?php echo esc_html($meta['label']); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="matrix_mlm_billing_category_visibility[<?php echo esc_attr($slug); ?>]"
                                   value="1"
                                   <?php checked($checked); ?>>
                            <?php
                            printf(
                                /* translators: %s: category label, e.g. "Airtime" */
                                esc_html__('Allow %s purchases on the user dashboard.', 'matrix-mlm'),
                                esc_html($meta['label'])
                            );
                            ?>
                        </label>
                        <p class="description" style="margin-top:4px;">
                            <?php echo esc_html($meta['description']); ?>
                        </p>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2 style="margin-top:32px;"><?php _e('Bill Payments — Service Fees / Markup', 'matrix-mlm'); ?></h2>
        <p class="description" style="margin-bottom:14px;">
            <?php _e('Charge a platform service fee on top of each bill purchase. The user is debited for <em>amount + fee</em> in a single transaction; only the bill amount is sent to Fintava. Fees are recorded separately on each transaction in the <code>service_fee</code> column for revenue reporting. Set both fields to 0 to disable the fee for that category &mdash; that&#39;s the default and matches the pre-fee behaviour exactly.', 'matrix-mlm'); ?>
        </p>
        <p class="description" style="margin-bottom:14px;">
            <?php _e('Fees are disclosed to the user as a line item on the purchase form before they confirm. Hidden fees attract chargebacks; the disclosure is not configurable.', 'matrix-mlm'); ?>
        </p>
        <table class="form-table" style="max-width:760px;">
            <thead>
                <tr>
                    <th scope="col" style="width:30%;"><?php _e('Category', 'matrix-mlm'); ?></th>
                    <th scope="col" style="width:35%;">
                        <?php
                        printf(
                            /* translators: %s: currency symbol */
                            esc_html__('Flat fee (%s)', 'matrix-mlm'),
                            esc_html($currency)
                        );
                        ?>
                    </th>
                    <th scope="col" style="width:35%;"><?php _e('Percentage fee (%)', 'matrix-mlm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $slug => $meta):
                    $flat = $markup[$slug]['flat']    ?? 0;
                    $pct  = $markup[$slug]['percent'] ?? 0;
                    ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($meta['label']); ?></th>
                        <td>
                            <input type="number"
                                   step="0.01"
                                   min="0"
                                   name="matrix_mlm_fintava_billing_markup[<?php echo esc_attr($slug); ?>][flat]"
                                   value="<?php echo esc_attr(number_format((float) $flat, 2, '.', '')); ?>"
                                   style="width:140px;">
                        </td>
                        <td>
                            <input type="number"
                                   step="0.01"
                                   min="0"
                                   max="100"
                                   name="matrix_mlm_fintava_billing_markup[<?php echo esc_attr($slug); ?>][percent]"
                                   value="<?php echo esc_attr(number_format((float) $pct, 2, '.', '')); ?>"
                                   style="width:140px;">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="margin-top:8px;">
            <?php _e('Fee = flat + (amount &times; percent &divide; 100), rounded to 2 decimal places. Negative values are coerced to 0 and percentages above 100 are capped at 100.', 'matrix-mlm'); ?>
        </p>

        <h2 style="margin-top:32px;"><?php _e('Bill Payments &mdash; Schema Repair', 'matrix-mlm'); ?></h2>
        <p class="description" style="margin-bottom:14px;">
            <?php _e('If users see <em>"Internal error. Please try again."</em> when buying airtime / data / cable / electricity, the <code>matrix_billing_transactions</code> table may be out of sync with the current plugin code &mdash; typically a column or status enum value missing after an upgrade that copied plugin files without ever loading a Matrix admin page (so the dbDelta migration never ran). Click below to re-run the schema migration. The migration is idempotent, so this is safe to run repeatedly.', 'matrix-mlm'); ?>
        </p>
        <p class="description" style="margin-bottom:14px;">
            <?php _e('After a successful repair, the next bill purchase that hits the previously-broken code path will succeed. Existing wallet refunds for previously-failed purchases stay in place &mdash; this button does not retry past failures, only unblocks future ones.', 'matrix-mlm'); ?>
        </p>
        <p>
            <button type="submit"
                    name="matrix_repair_billing_schema"
                    value="1"
                    class="button">
                <?php _e('Repair Bill Payments Schema', 'matrix-mlm'); ?>
            </button>
        </p>
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
                $settings = ['matrix_mlm_min_deposit', 'matrix_mlm_max_deposit', 'matrix_mlm_min_withdraw', 'matrix_mlm_max_withdraw', 'matrix_mlm_withdraw_charge_type', 'matrix_mlm_withdraw_charge', 'matrix_mlm_transfer_charge_type', 'matrix_mlm_transfer_charge', 'matrix_mlm_min_transfer', 'matrix_mlm_withdrawals_enabled', 'matrix_mlm_withdraw_require_active_user', 'matrix_mlm_matrix_transfers_enabled', 'matrix_mlm_bank_transfers_enabled'];
                // Required-plans multi-checkbox is the one field on
                // this tab whose POST shape is an array, not a scalar.
                // The generic save loop further down runs every value
                // through sanitize_text_field, which would coerce the
                // array to the string "Array". Handle it explicitly
                // here, store as CSV (matching the parsing contract
                // in Matrix_MLM_User::can_move_funds()).
                $required_plans_raw = isset($_POST['matrix_mlm_withdraw_required_plans']) && is_array($_POST['matrix_mlm_withdraw_required_plans'])
                    ? $_POST['matrix_mlm_withdraw_required_plans']
                    : [];
                $required_plans = array_values(array_filter(array_map('intval', $required_plans_raw)));
                update_option('matrix_mlm_withdraw_required_plans', implode(',', $required_plans));
                break;
            case 'notifications':
                $settings = ['matrix_mlm_email_verification', 'matrix_mlm_sms_verification', 'matrix_mlm_application_notification_email', 'matrix_mlm_cug_notification_email', 'matrix_mlm_loan_notification_email', 'matrix_mlm_healthcare_notification_email'];
                break;
            case 'security':
                $settings = ['matrix_mlm_2fa_enabled', 'matrix_mlm_captcha_enabled', 'matrix_mlm_captcha_site_key', 'matrix_mlm_captcha_secret_key'];
                break;
            case 'appearance':
                $settings = ['matrix_mlm_primary_color', 'matrix_mlm_secondary_color', 'matrix_mlm_custom_css', 'matrix_mlm_login_logo_url', 'matrix_mlm_login_logo_id'];
                break;
            case 'dashboard_menus':
                // Per-item visibility for the user-dashboard sidebar.
                // POST shape is matrix_mlm_dashboard_menu_visibility[<slug>] = "1"
                // for ticked boxes; unchecked boxes are absent from POST,
                // which is how we know to record them as 0. Locked slugs
                // (overview/profile/security) never render an input on
                // this tab, so they never appear in either branch — and
                // is_menu_visible() short-circuits them anyway, so the
                // stored value for those keys is moot.
                $posted_raw = isset($_POST['matrix_mlm_dashboard_menu_visibility']) && is_array($_POST['matrix_mlm_dashboard_menu_visibility'])
                    ? $_POST['matrix_mlm_dashboard_menu_visibility']
                    : [];
                $locked = Matrix_MLM_User_Dashboard::LOCKED_MENU_SLUGS;
                $map = [];
                foreach (Matrix_MLM_User_Dashboard::dashboard_menu_definition() as $item) {
                    $slug = $item['slug'];
                    if (in_array($slug, $locked, true)) {
                        // Skip — never store a preference for a locked
                        // slug; is_menu_visible() forces them to true.
                        continue;
                    }
                    $map[$slug] = !empty($posted_raw[$slug]) ? 1 : 0;
                }
                update_option('matrix_mlm_dashboard_menu_visibility', wp_json_encode($map));
                // Settings array stays empty so the generic save loop
                // below has nothing to do for this tab — the JSON blob
                // above is the only persisted change.
                $settings = [];
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
            case 'bill_payments':
                // Per-category visibility for the user-dashboard
                // Bill Payments tab. POST shape is
                //   matrix_mlm_billing_category_visibility[<slug>] = "1"
                // for ticked boxes; unchecked boxes are absent from
                // POST, which is how we know to record them as 0.
                // Stored as a JSON map so adding a new category in
                // a future version is a one-line BILL_CATEGORIES
                // change without an option-key migration.
                $posted_raw = isset($_POST['matrix_mlm_billing_category_visibility']) && is_array($_POST['matrix_mlm_billing_category_visibility'])
                    ? $_POST['matrix_mlm_billing_category_visibility']
                    : [];
                $map = [];
                foreach (Matrix_MLM_Fintava_Billing::BILL_CATEGORIES as $slug) {
                    $map[$slug] = !empty($posted_raw[$slug]) ? 1 : 0;
                }
                update_option('matrix_mlm_billing_category_visibility', wp_json_encode($map));

                // Service-fee / markup config (item C). POST shape:
                //   matrix_mlm_fintava_billing_markup[<slug>][flat]    = "20.00"
                //   matrix_mlm_fintava_billing_markup[<slug>][percent] = "1.5"
                // Sanitised to a clean per-category {flat, percent}
                // assoc array so the gateway-side computation in
                // Matrix_MLM_Fintava_Billing::compute_service_fee()
                // can trust the shape without re-validating.
                //
                // All values are coerced to non-negative floats and:
                //   - percent is capped at 100
                //   - flat is capped at the per-category max
                //     resolved by Matrix_MLM_Fintava_Billing::resolve_max_flat_fee()
                //     (default 1000, filterable). The flat cap closes
                //     the footgun where a typo (e.g. 14000 in
                //     airtime's flat field) silently broke every
                //     member purchase below ~14k balance with only
                //     a generic "Insufficient wallet balance" error
                //     to debug from. compute_service_fee() also
                //     clamps at compute time as a self-heal for
                //     options stored before this cap landed.
                //
                // When a clamp fires we collect the (slug, raw,
                // clamped) tuples and surface them to the operator
                // via a one-shot transient that the next admin
                // pageload renders as a notice. Saving silently
                // would leave the operator confused why the value
                // they typed isn't the value displayed on reload.
                $markup_raw = isset($_POST['matrix_mlm_fintava_billing_markup']) && is_array($_POST['matrix_mlm_fintava_billing_markup'])
                    ? $_POST['matrix_mlm_fintava_billing_markup']
                    : [];
                $markup_clean = [];
                $clamped_notices = [];
                foreach (Matrix_MLM_Fintava_Billing::BILL_CATEGORIES as $slug) {
                    $entry   = is_array($markup_raw[$slug] ?? null) ? $markup_raw[$slug] : [];
                    $flat    = max(0.0, floatval($entry['flat']    ?? 0));
                    $percent = max(0.0, floatval($entry['percent'] ?? 0));
                    $percent = min(100.0, $percent);

                    $flat_max = Matrix_MLM_Fintava_Billing::resolve_max_flat_fee($slug);
                    if ($flat > $flat_max) {
                        $clamped_notices[] = [
                            'slug'    => $slug,
                            'field'   => 'flat',
                            'entered' => $flat,
                            'capped'  => $flat_max,
                        ];
                        $flat = $flat_max;
                    }

                    $markup_clean[$slug] = [
                        'flat'    => round($flat, 2),
                        'percent' => round($percent, 2),
                    ];
                }
                update_option('matrix_mlm_fintava_billing_markup', $markup_clean);

                if (!empty($clamped_notices)) {
                    // Transient lives 60s — long enough to survive the
                    // POST-redirect-GET that WordPress's settings save
                    // typically does, short enough that a stale notice
                    // never lingers if the operator navigates away
                    // before reading it.
                    set_transient(
                        'matrix_mlm_billing_markup_clamped_' . get_current_user_id(),
                        $clamped_notices,
                        60
                    );
                }

                // Settings stays empty; the JSON blobs above are the
                // only persisted changes for this tab.
                $settings = [];
                break;
        }

        foreach ($settings as $setting) {
            $value = isset($_POST[$setting]) ? $_POST[$setting] : '';
            if (in_array($setting, ['matrix_mlm_custom_css', 'matrix_mlm_livechat_code'])) {
                $value = wp_unslash($value);
            } elseif (in_array($setting, ['matrix_mlm_login_logo_url'])) {
                $value = esc_url_raw(wp_unslash($value));
            } else {
                $value = sanitize_text_field($value);
            }
            update_option($setting, $value);
        }

        // Handle checkboxes that might not be sent
        $checkboxes = ['matrix_mlm_registration_enabled', 'matrix_mlm_gdpr_enabled', 'matrix_mlm_email_verification', 'matrix_mlm_sms_verification', 'matrix_mlm_2fa_enabled', 'matrix_mlm_captcha_enabled', 'matrix_mlm_livechat_enabled', 'matrix_mlm_auto_reentry', 'matrix_mlm_subscription_enabled', 'matrix_mlm_withdrawals_enabled', 'matrix_mlm_withdraw_require_active_user', 'matrix_mlm_matrix_transfers_enabled', 'matrix_mlm_bank_transfers_enabled'];
        foreach ($checkboxes as $cb) {
            if (in_array($cb, $settings) && !isset($_POST[$cb])) {
                update_option($cb, 0);
            }
        }

        echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'matrix-mlm') . '</p></div>';
    }

    /**
     * Re-run the matrix_billing_transactions schema migration on
     * demand (admin -> Settings -> Bill Payments -> Repair Bill
     * Payments Schema).
     *
     * The migration itself lives on the gateway class as
     * Matrix_MLM_Fintava_Billing::create_table() and is already
     * called on every Matrix admin pageload via
     * Matrix_MLM_Database::create_tables(). This handler exists
     * for the cases where that idempotent pageload migration
     * doesn't fire or doesn't pick up a change:
     *
     *   - Plugin files FTP-replaced without ever loading wp-admin
     *     to trigger the pageload migration.
     *   - dbDelta silently failing (charset/collation conflict,
     *     ALTER permission missing on the DB user, table locked).
     *   - An operator who wants to force a known-good schema
     *     before opening a support ticket about the user-visible
     *     "Internal error. Please try again." message.
     *
     * Output flow:
     *   1. Capability check (manage_options) — settings page is
     *      already admin-gated, but defence-in-depth costs us
     *      nothing and matches the rest of the plugin's pattern.
     *   2. Run create_table().
     *   3. Probe INFORMATION_SCHEMA for the post-E columns
     *      (nominal_amount, service_fee, total_charged,
     *      refunded_amount, client_reference, completed_at). If
     *      any are still missing, the migration didn't take —
     *      surface the missing list as an error notice so the
     *      operator can pursue the underlying cause (DB perms,
     *      etc.) rather than seeing a misleading green tick.
     *   4. Echo a success or error notice. Notices echo BEFORE
     *      the .wrap div opens (matches save_settings()'s
     *      pattern); WordPress admin auto-positions them.
     *
     * Does NOT redirect / set transients — the operator just
     * clicked a button on this exact page, so the inline notice
     * is the most direct feedback.
     */
    private function repair_billing_schema() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permission to repair the bill payments schema.', 'matrix-mlm'));
        }

        global $wpdb;

        // Suppress wpdb's "show_errors" output during the
        // migration. dbDelta is loud and the schema-probe
        // ALTER cycle inside create_table() legitimately emits
        // intermediate errors that the migration logic catches
        // and recovers from (e.g. probing for a column that
        // doesn't exist yet). We want a clean success notice
        // when the table ends up healthy, not the dbDelta
        // commentary scrolling past it.
        $previous_show = $wpdb->hide_errors();

        $threw = null;
        try {
            Matrix_MLM_Fintava_Billing::create_table();
        } catch (\Throwable $e) {
            // create_table() doesn't throw under any documented
            // path, but a future refactor or a fatal during
            // dbDelta on an exotic MySQL fork would land here.
            // Capture the message for the error notice rather
            // than letting it bubble up and white-screen the
            // whole settings page.
            $threw = $e->getMessage();
        }

        if ($previous_show) {
            $wpdb->show_errors();
        }

        if ($threw !== null) {
            echo '<div class="notice notice-error"><p>' . sprintf(
                /* translators: %s: exception message from create_table() */
                esc_html__('Bill Payments schema repair failed: %s', 'matrix-mlm'),
                esc_html($threw)
            ) . '</p></div>';
            return;
        }

        // Verify the post-E critical columns are actually present
        // after the migration. This is the only way to tell the
        // difference between "create_table() ran clean and the
        // schema is up to date" and "create_table() ran but
        // dbDelta silently dropped an ALTER" (the latter happens
        // when, say, the DB user has SELECT/INSERT/UPDATE/DELETE
        // but lacks ALTER, which dbDelta swallows without
        // returning an error).
        $table = $wpdb->prefix . 'matrix_billing_transactions';
        $required_cols = [
            'nominal_amount',
            'service_fee',
            'total_charged',
            'refunded_amount',
            'client_reference',
            'completed_at',
        ];
        $missing = [];
        foreach ($required_cols as $col) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME, $table, $col
            ));
            if ($exists === 0) {
                $missing[] = $col;
            }
        }

        if (!empty($missing)) {
            echo '<div class="notice notice-error"><p>' . sprintf(
                /* translators: %s: comma-separated list of column names that are still missing after the migration */
                esc_html__('Bill Payments schema repair ran, but these columns are still missing: %s. The most likely cause is that the database user lacks ALTER permission on the matrix_billing_transactions table. Check the PHP error log for dbDelta output and grant the missing permission, then click Repair again.', 'matrix-mlm'),
                '<code>' . esc_html(implode(', ', $missing)) . '</code>'
            ) . '</p></div>';
            return;
        }

        echo '<div class="notice notice-success"><p>' . esc_html__('Bill Payments schema is up to date. New bill purchases will use the current schema; previously-failed purchases were already wallet-refunded.', 'matrix-mlm') . '</p></div>';
    }
}
