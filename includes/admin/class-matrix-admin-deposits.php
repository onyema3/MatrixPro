<?php
/**
 * Admin Deposits Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Deposits {

    public function render() {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        $where = "WHERE 1=1";
        $params = [];
        if ($status_filter) {
            $where .= " AND d.status = %s";
            $params[] = $status_filter;
        }
        $params[] = 50;
        $params[] = 0;

        $deposits = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, u.user_login, u.user_email FROM {$wpdb->prefix}matrix_deposits d 
             LEFT JOIN {$wpdb->users} u ON d.user_id = u.ID 
             $where ORDER BY d.created_at DESC LIMIT %d OFFSET %d",
            $params
        ));

        $totals = [
            'all' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_deposits"),
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_deposits WHERE status = 'pending'"),
            'pending_capture' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_deposits WHERE status = 'pending_capture'"),
            'completed' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_deposits WHERE status = 'completed'"),
            'rejected' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_deposits WHERE status = 'rejected'"),
        ];
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Manage Deposits', 'matrix-mlm'); ?></h1>

            <ul class="subsubsub">
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-deposits'); ?>" class="<?php echo empty($status_filter) ? 'current' : ''; ?>">All (<?php echo $totals['all']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-deposits&status=pending'); ?>" class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>">Pending (<?php echo $totals['pending']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-deposits&status=pending_capture'); ?>" class="<?php echo $status_filter === 'pending_capture' ? 'current' : ''; ?>"><?php _e('Pending Capture', 'matrix-mlm'); ?> (<?php echo $totals['pending_capture']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-deposits&status=completed'); ?>" class="<?php echo $status_filter === 'completed' ? 'current' : ''; ?>">Completed (<?php echo $totals['completed']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-deposits&status=rejected'); ?>" class="<?php echo $status_filter === 'rejected' ? 'current' : ''; ?>">Rejected (<?php echo $totals['rejected']; ?>)</a></li>
            </ul>

            <table class="wp-list-table widefat fixed striped" style="margin-top: 30px;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php _e('User', 'matrix-mlm'); ?></th>
                        <th><?php _e('Gateway', 'matrix-mlm'); ?></th>
                        <th><?php _e('Amount', 'matrix-mlm'); ?></th>
                        <th><?php _e('Charge', 'matrix-mlm'); ?></th>
                        <th><?php _e('Net', 'matrix-mlm'); ?></th>
                        <th><?php _e('Reference', 'matrix-mlm'); ?></th>
                        <th><?php _e('Status', 'matrix-mlm'); ?></th>
                        <th><?php _e('Date', 'matrix-mlm'); ?></th>
                        <th><?php _e('Actions', 'matrix-mlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deposits as $deposit): ?>
                    <tr>
                        <td><?php echo $deposit->id; ?></td>
                        <td><?php echo esc_html($deposit->user_login); ?><br><small><?php echo esc_html($deposit->user_email); ?></small></td>
                        <td><?php echo esc_html(ucfirst($deposit->gateway)); ?></td>
                        <td><?php echo $currency . number_format($deposit->amount, 2); ?></td>
                        <td><?php echo $currency . number_format($deposit->charge, 2); ?></td>
                        <td><?php echo $currency . number_format($deposit->net_amount, 2); ?></td>
                        <td><code><?php echo esc_html($deposit->transaction_id ?? '-'); ?></code></td>
                        <td><span class="matrix-badge matrix-badge-<?php echo $deposit->status; ?>"><?php echo esc_html(ucwords(str_replace('_', ' ', $deposit->status))); ?></span></td>
                        <td><?php echo date('M d, Y H:i', strtotime($deposit->created_at)); ?></td>
                        <td>
                            <?php if ($deposit->status === 'pending'): ?>
                            <button class="button button-small button-primary" onclick="matrixAdminAction('approve_deposit', {id: <?php echo $deposit->id; ?>})"><?php _e('Approve', 'matrix-mlm'); ?></button>
                            <?php elseif ($deposit->status === 'pending_capture' && $deposit->gateway === 'zebra'): ?>
                            <button class="button button-small button-primary" onclick="matrixCaptureZebraDeposit(<?php echo $deposit->id; ?>)"><?php _e('Capture', 'matrix-mlm'); ?></button>
                            <button class="button button-small" onclick="matrixCancelZebraDeposit(<?php echo $deposit->id; ?>)"><?php _e('Cancel', 'matrix-mlm'); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
