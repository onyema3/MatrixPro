<?php
/**
 * Admin Fintava Migration Tool
 * Handles CSV import/export for migrating users with existing Fintava accounts
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Migration {

    public function __construct() {
        add_action('admin_init', [$this, 'handle_import']);
        add_action('admin_init', [$this, 'handle_export']);
    }

    public function render() {
        $tab = isset($_GET['mtab']) ? sanitize_text_field($_GET['mtab']) : 'import';
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Fintava Migration', 'matrix-mlm'); ?></h1>
            <p class="description"><?php _e('Import existing Fintava wallet and card details for users migrating from another system, or export current data.', 'matrix-mlm'); ?></p>

            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-migration&mtab=import'); ?>" class="nav-tab <?php echo $tab === 'import' ? 'nav-tab-active' : ''; ?>"><?php _e('Import', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-migration&mtab=export'); ?>" class="nav-tab <?php echo $tab === 'export' ? 'nav-tab-active' : ''; ?>"><?php _e('Export', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-migration&mtab=link'); ?>" class="nav-tab <?php echo $tab === 'link' ? 'nav-tab-active' : ''; ?>"><?php _e('Link Single User', 'matrix-mlm'); ?></a>
            </nav>

            <?php
            switch ($tab) {
                case 'import': $this->render_import_tab(); break;
                case 'export': $this->render_export_tab(); break;
                case 'link': $this->render_link_tab(); break;
            }
            ?>
        </div>
        <?php
    }

    private function render_import_tab() {
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <div class="matrix-admin-card">
            <h2><?php _e('Bulk Import Fintava Accounts', 'matrix-mlm'); ?></h2>
            <p><?php _e('Upload a CSV file to link existing Fintava wallets and cards to users. The system will match users by username or email.', 'matrix-mlm'); ?></p>

            <div style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:16px 20px;margin:16px 0;">
                <h4 style="margin:0 0 8px;color:#4338ca;"><?php _e('CSV Format', 'matrix-mlm'); ?></h4>
                <p style="margin:0 0 8px;font-size:13px;color:#4b5563;"><?php _e('Your CSV should have the following columns (header row required):', 'matrix-mlm'); ?></p>
                <code style="display:block;background:#fff;padding:10px 14px;border-radius:4px;font-size:12px;overflow-x:auto;">username,email,wallet_id,account_number,account_name,bank_name,customer_email,customer_phone,card_id,card_type,card_brand,last_four,card_status</code>
                <p style="margin:10px 0 0;font-size:12px;color:#6b7280;">
                    <?php _e('Required: <strong>username</strong> OR <strong>email</strong> (to match the user). All other fields are optional — include only what you have.', 'matrix-mlm'); ?>
                </p>
            </div>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('matrix_fintava_import'); ?>
                <table class="form-table">
                    <tr>
                        <th><?php _e('CSV File', 'matrix-mlm'); ?></th>
                        <td>
                            <input type="file" name="import_csv" accept=".csv" required>
                            <p class="description"><?php _e('Maximum 5MB. UTF-8 encoded.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('If user already has wallet', 'matrix-mlm'); ?></th>
                        <td>
                            <select name="conflict_mode">
                                <option value="skip"><?php _e('Skip (keep existing)', 'matrix-mlm'); ?></option>
                                <option value="overwrite"><?php _e('Overwrite with imported data', 'matrix-mlm'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Default wallet status', 'matrix-mlm'); ?></th>
                        <td>
                            <select name="default_wallet_status">
                                <option value="active"><?php _e('Active', 'matrix-mlm'); ?></option>
                                <option value="inactive"><?php _e('Inactive', 'matrix-mlm'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p><input type="submit" name="matrix_import_fintava" class="button button-primary" value="<?php _e('Import Fintava Accounts', 'matrix-mlm'); ?>"></p>
            </form>
        </div>

        <div class="matrix-admin-card">
            <h2><?php _e('Download Sample CSV', 'matrix-mlm'); ?></h2>
            <p><?php _e('Download a sample CSV template pre-filled with example data to see the expected format.', 'matrix-mlm'); ?></p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-migration&download_sample=1'), 'matrix_fintava_sample'); ?>" class="button"><?php _e('Download Sample Template', 'matrix-mlm'); ?></a>
        </div>
        <?php
    }

    private function render_export_tab() {
        global $wpdb;
        $wallet_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_fintava_wallets");
        $card_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_fintava_cards");
        ?>
        <div class="matrix-admin-card">
            <h2><?php _e('Export Fintava Data', 'matrix-mlm'); ?></h2>
            <p><?php _e('Export all linked Fintava wallet and card data for backup or migration to another system.', 'matrix-mlm'); ?></p>

            <div class="matrix-admin-stats" style="margin-bottom:20px;">
                <div class="stat-card stat-primary"><h3><?php echo number_format($wallet_count); ?></h3><p><?php _e('Linked Wallets', 'matrix-mlm'); ?></p></div>
                <div class="stat-card stat-info"><h3><?php echo number_format($card_count); ?></h3><p><?php _e('Linked Cards', 'matrix-mlm'); ?></p></div>
            </div>

            <h3><?php _e('Export Wallets', 'matrix-mlm'); ?></h3>
            <div style="display:flex;gap:8px;margin-bottom:20px;">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-migration&export_fintava=wallets&format=csv'), 'matrix_fintava_export'); ?>" class="button">CSV</a>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-migration&export_fintava=wallets&format=json'), 'matrix_fintava_export'); ?>" class="button">JSON</a>
            </div>

            <h3><?php _e('Export Cards', 'matrix-mlm'); ?></h3>
            <div style="display:flex;gap:8px;margin-bottom:20px;">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-migration&export_fintava=cards&format=csv'), 'matrix_fintava_export'); ?>" class="button">CSV</a>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-migration&export_fintava=cards&format=json'), 'matrix_fintava_export'); ?>" class="button">JSON</a>
            </div>

            <h3><?php _e('Export All (Wallets + Cards combined)', 'matrix-mlm'); ?></h3>
            <div style="display:flex;gap:8px;">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-migration&export_fintava=all&format=csv'), 'matrix_fintava_export'); ?>" class="button button-primary">CSV</a>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-migration&export_fintava=all&format=json'), 'matrix_fintava_export'); ?>" class="button button-primary">JSON</a>
            </div>
        </div>
        <?php
    }

    private function render_link_tab() {
        ?>
        <div class="matrix-admin-card">
            <h2><?php _e('Link Fintava Account to Single User', 'matrix-mlm'); ?></h2>
            <p><?php _e('Manually link a Fintava wallet and/or card to a specific user. Useful for individual migrations or corrections.', 'matrix-mlm'); ?></p>

            <table class="form-table" id="fintava-link-form">
                <tr>
                    <th><?php _e('Username', 'matrix-mlm'); ?></th>
                    <td><input type="text" id="link_username" class="regular-text" placeholder="<?php _e('Enter username', 'matrix-mlm'); ?>"></td>
                </tr>
                <tr><td colspan="2"><hr><h3 style="margin:0;"><?php _e('Wallet Details', 'matrix-mlm'); ?></h3></td></tr>
                <tr>
                    <th><?php _e('Wallet ID', 'matrix-mlm'); ?></th>
                    <td><input type="text" id="link_wallet_id" class="regular-text" placeholder="<?php _e('Fintava wallet ID', 'matrix-mlm'); ?>"></td>
                </tr>
                <tr>
                    <th><?php _e('Account Number', 'matrix-mlm'); ?></th>
                    <td><input type="text" id="link_account_number" class="regular-text" placeholder="<?php _e('Virtual account number', 'matrix-mlm'); ?>"></td>
                </tr>
                <tr>
                    <th><?php _e('Account Name', 'matrix-mlm'); ?></th>
                    <td><input type="text" id="link_account_name" class="regular-text" placeholder="<?php _e('Account holder name', 'matrix-mlm'); ?>"></td>
                </tr>
                <tr>
                    <th><?php _e('Bank Name', 'matrix-mlm'); ?></th>
                    <td><input type="text" id="link_bank_name" class="regular-text" value="Fintava"></td>
                </tr>
                <tr><td colspan="2"><hr><h3 style="margin:0;"><?php _e('Card Details (optional)', 'matrix-mlm'); ?></h3></td></tr>
                <tr>
                    <th><?php _e('Card ID', 'matrix-mlm'); ?></th>
                    <td><input type="text" id="link_card_id" class="regular-text" placeholder="<?php _e('Fintava card ID (optional)', 'matrix-mlm'); ?>"></td>
                </tr>
                <tr>
                    <th><?php _e('Last 4 Digits', 'matrix-mlm'); ?></th>
                    <td><input type="text" id="link_last_four" class="small-text" maxlength="4" placeholder="1234"></td>
                </tr>
                <tr>
                    <th><?php _e('Card Status', 'matrix-mlm'); ?></th>
                    <td>
                        <select id="link_card_status">
                            <option value="active"><?php _e('Active', 'matrix-mlm'); ?></option>
                            <option value="linked"><?php _e('Linked', 'matrix-mlm'); ?></option>
                            <option value="delivered"><?php _e('Delivered', 'matrix-mlm'); ?></option>
                            <option value="pending"><?php _e('Pending', 'matrix-mlm'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
            <p>
                <button class="button button-primary" onclick="matrixLinkFintavaAccount()"><?php _e('Link Account', 'matrix-mlm'); ?></button>
            </p>
        </div>
        <script>
        function matrixLinkFintavaAccount() {
            var username = document.getElementById('link_username').value.trim();
            if (!username) { alert('<?php _e('Please enter a username', 'matrix-mlm'); ?>'); return; }

            jQuery.post(matrixMLMAdmin.ajaxUrl, {
                action: 'matrix_admin_action',
                nonce: matrixMLMAdmin.nonce,
                matrix_action: 'link_fintava_account',
                username: username,
                wallet_id: document.getElementById('link_wallet_id').value.trim(),
                account_number: document.getElementById('link_account_number').value.trim(),
                account_name: document.getElementById('link_account_name').value.trim(),
                bank_name: document.getElementById('link_bank_name').value.trim(),
                card_id: document.getElementById('link_card_id').value.trim(),
                last_four: document.getElementById('link_last_four').value.trim(),
                card_status: document.getElementById('link_card_status').value
            }, function(res) {
                alert(res.success ? res.data.message : (res.data.message || 'Error'));
            });
        }
        </script>
        <?php
    }

    /**
     * Handle CSV import
     */
    public function handle_import() {
        if (!isset($_POST['matrix_import_fintava']) || !isset($_POST['_wpnonce'])) return;
        if (!wp_verify_nonce($_POST['_wpnonce'], 'matrix_fintava_import')) return;
        if (!current_user_can('manage_matrix_mlm')) return;

        if (empty($_FILES['import_csv']['tmp_name'])) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . __('No file uploaded.', 'matrix-mlm') . '</p></div>';
            });
            return;
        }

        $file = $_FILES['import_csv']['tmp_name'];
        $conflict_mode = sanitize_text_field($_POST['conflict_mode'] ?? 'skip');
        $default_status = sanitize_text_field($_POST['default_wallet_status'] ?? 'active');

        $handle = fopen($file, 'r');
        if (!$handle) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . __('Failed to read file.', 'matrix-mlm') . '</p></div>';
            });
            return;
        }

        global $wpdb;
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return;
        }

        // Normalize headers
        $headers = array_map(function($h) { return strtolower(trim($h)); }, $headers);

        $imported = 0;
        $skipped = 0;
        $errors = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, array_pad($row, count($headers), ''));

            // Find user
            $user = null;
            if (!empty($data['username'])) {
                $user = get_user_by('login', trim($data['username']));
            }
            if (!$user && !empty($data['email'])) {
                $user = get_user_by('email', trim($data['email']));
            }

            if (!$user) {
                $errors++;
                continue;
            }

            $user_id = $user->ID;

            // Import wallet
            if (!empty($data['account_number']) || !empty($data['wallet_id'])) {
                $existing_wallet = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}matrix_fintava_wallets WHERE user_id = %d",
                    $user_id
                ));

                if ($existing_wallet && $conflict_mode === 'skip') {
                    $skipped++;
                } else {
                    $wallet_data = [
                        'user_id' => $user_id,
                        'wallet_id' => sanitize_text_field($data['wallet_id'] ?? ''),
                        'account_number' => sanitize_text_field($data['account_number'] ?? ''),
                        'account_name' => sanitize_text_field($data['account_name'] ?? $user->display_name),
                        'bank_name' => sanitize_text_field($data['bank_name'] ?? 'Fintava'),
                        'bank_code' => sanitize_text_field($data['bank_code'] ?? ''),
                        'currency' => 'NGN',
                        'customer_email' => sanitize_email($data['customer_email'] ?? $user->user_email),
                        'customer_phone' => sanitize_text_field($data['customer_phone'] ?? ''),
                        'bvn' => sanitize_text_field($data['bvn'] ?? ''),
                        'status' => $default_status,
                    ];

                    if ($existing_wallet) {
                        $wpdb->update($wpdb->prefix . 'matrix_fintava_wallets', $wallet_data, ['id' => $existing_wallet]);
                    } else {
                        $wpdb->insert($wpdb->prefix . 'matrix_fintava_wallets', $wallet_data);
                    }
                    $imported++;
                }
            }

            // Import card
            if (!empty($data['card_id'])) {
                $existing_card = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}matrix_fintava_cards WHERE user_id = %d",
                    $user_id
                ));

                if ($existing_card && $conflict_mode === 'skip') {
                    // Already counted in skipped
                } else {
                    $card_data = [
                        'user_id' => $user_id,
                        'card_id' => sanitize_text_field($data['card_id']),
                        'wallet_id' => sanitize_text_field($data['wallet_id'] ?? ''),
                        'card_type' => sanitize_text_field($data['card_type'] ?? 'STATIC_NO_ACCOUNT'),
                        'card_brand' => sanitize_text_field($data['card_brand'] ?? 'VERVE'),
                        'last_four' => sanitize_text_field($data['last_four'] ?? ''),
                        'status' => sanitize_text_field($data['card_status'] ?? 'active'),
                    ];

                    if ($existing_card) {
                        $wpdb->update($wpdb->prefix . 'matrix_fintava_cards', $card_data, ['id' => $existing_card]);
                    } else {
                        $wpdb->insert($wpdb->prefix . 'matrix_fintava_cards', $card_data);
                    }
                }
            }
        }

        fclose($handle);

        $msg = sprintf(__('Import complete: %d imported, %d skipped, %d errors (user not found).', 'matrix-mlm'), $imported, $skipped, $errors);
        add_action('admin_notices', function() use ($msg) {
            echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>';
        });
    }

    /**
     * Handle export
     */
    public function handle_export() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'matrix-mlm-migration') return;
        if (!current_user_can('manage_matrix_mlm')) return;

        // Handle sample download
        if (isset($_GET['download_sample']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'matrix_fintava_sample')) {
            $this->download_sample();
            return;
        }

        if (!isset($_GET['export_fintava'])) return;
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'matrix_fintava_export')) return;

        global $wpdb;
        $type = sanitize_text_field($_GET['export_fintava']);
        $format = sanitize_text_field($_GET['format'] ?? 'csv');
        $filename = 'fintava-' . $type . '-' . date('Y-m-d');

        $data = [];

        if ($type === 'wallets' || $type === 'all') {
            $wallets = $wpdb->get_results(
                "SELECT u.user_login as username, u.user_email as email, w.wallet_id, w.account_number, w.account_name, w.bank_name, w.bank_code, w.customer_email, w.customer_phone, w.bvn, w.status as wallet_status, w.created_at
                 FROM {$wpdb->prefix}matrix_fintava_wallets w
                 LEFT JOIN {$wpdb->users} u ON w.user_id = u.ID
                 ORDER BY w.created_at DESC", ARRAY_A
            );
            if ($type === 'wallets') $data = $wallets;
        }

        if ($type === 'cards' || $type === 'all') {
            $cards = $wpdb->get_results(
                "SELECT u.user_login as username, u.user_email as email, c.card_id, c.wallet_id, c.card_type, c.card_brand, c.last_four, c.status as card_status, c.activated_at, c.created_at
                 FROM {$wpdb->prefix}matrix_fintava_cards c
                 LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
                 ORDER BY c.created_at DESC", ARRAY_A
            );
            if ($type === 'cards') $data = $cards;
        }

        if ($type === 'all') {
            // Merge wallet and card data per user
            $merged = [];
            foreach ($wallets as $w) {
                $merged[$w['username']] = $w;
                $merged[$w['username']]['card_id'] = '';
                $merged[$w['username']]['card_type'] = '';
                $merged[$w['username']]['card_brand'] = '';
                $merged[$w['username']]['last_four'] = '';
                $merged[$w['username']]['card_status'] = '';
            }
            foreach ($cards as $c) {
                $key = $c['username'];
                if (isset($merged[$key])) {
                    $merged[$key]['card_id'] = $c['card_id'];
                    $merged[$key]['card_type'] = $c['card_type'];
                    $merged[$key]['card_brand'] = $c['card_brand'];
                    $merged[$key]['last_four'] = $c['last_four'];
                    $merged[$key]['card_status'] = $c['card_status'];
                } else {
                    $merged[$key] = array_merge(['username' => $c['username'], 'email' => $c['email'], 'wallet_id' => '', 'account_number' => '', 'account_name' => '', 'bank_name' => '', 'bank_code' => '', 'customer_email' => '', 'customer_phone' => '', 'bvn' => '', 'wallet_status' => '', 'created_at' => $c['created_at']], ['card_id' => $c['card_id'], 'card_type' => $c['card_type'], 'card_brand' => $c['card_brand'], 'last_four' => $c['last_four'], 'card_status' => $c['card_status']]);
                }
            }
            $data = array_values($merged);
        }

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            if (!empty($data)) {
                fputcsv($output, array_keys($data[0]));
                foreach ($data as $row) { fputcsv($output, $row); }
            }
            fclose($output);
        } else {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.json"');
            echo json_encode(['exported_at' => current_time('mysql'), 'type' => $type, 'count' => count($data), 'data' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    /**
     * Download sample CSV template
     */
    private function download_sample() {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="fintava-import-template.csv"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($output, ['username', 'email', 'wallet_id', 'account_number', 'account_name', 'bank_name', 'customer_email', 'customer_phone', 'bvn', 'card_id', 'card_type', 'card_brand', 'last_four', 'card_status']);
        fputcsv($output, ['johndoe', 'john@example.com', 'wal_abc123', '0123456789', 'John Doe', 'Fintava', 'john@example.com', '08012345678', '', 'card_xyz789', 'STATIC_NO_ACCOUNT', 'VERVE', '4321', 'active']);
        fputcsv($output, ['janedoe', 'jane@example.com', 'wal_def456', '9876543210', 'Jane Doe', 'Fintava', 'jane@example.com', '08098765432', '', '', '', '', '', '']);
        fclose($output);
        exit;
    }
}
