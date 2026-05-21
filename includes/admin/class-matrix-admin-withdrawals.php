<?php
/**
 * Admin Withdrawals Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Withdrawals {

    public function render() {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        $where = "WHERE 1=1";
        $params = [];
        if ($status_filter) {
            $where .= " AND w.status = %s";
            $params[] = $status_filter;
        }
        $params[] = 50;
        $params[] = 0;

        $withdrawals = $wpdb->get_results($wpdb->prepare(
            "SELECT w.*, u.user_login, u.user_email FROM {$wpdb->prefix}matrix_withdrawals w 
             LEFT JOIN {$wpdb->users} u ON w.user_id = u.ID 
             $where ORDER BY w.created_at DESC LIMIT %d OFFSET %d",
            $params
        ));

        $totals = [
            'all' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_withdrawals"),
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'pending'"),
            'approved' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'approved'"),
            'rejected' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'rejected'"),
        ];
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Manage Withdrawals', 'matrix-mlm'); ?></h1>

            <ul class="subsubsub">
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-withdrawals'); ?>" class="<?php echo empty($status_filter) ? 'current' : ''; ?>">All (<?php echo $totals['all']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-withdrawals&status=pending'); ?>" class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>">Pending (<?php echo $totals['pending']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-withdrawals&status=approved'); ?>" class="<?php echo $status_filter === 'approved' ? 'current' : ''; ?>">Approved (<?php echo $totals['approved']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-withdrawals&status=rejected'); ?>" class="<?php echo $status_filter === 'rejected' ? 'current' : ''; ?>">Rejected (<?php echo $totals['rejected']; ?>)</a></li>
            </ul>

            <table class="wp-list-table widefat fixed striped" style="margin-top: 30px;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php _e('User', 'matrix-mlm'); ?></th>
                        <th><?php _e('Method', 'matrix-mlm'); ?></th>
                        <th><?php _e('Amount', 'matrix-mlm'); ?></th>
                        <th><?php _e('Charge', 'matrix-mlm'); ?></th>
                        <th><?php _e('Net', 'matrix-mlm'); ?></th>
                        <th><?php _e('Account Details', 'matrix-mlm'); ?></th>
                        <th><?php _e('Status', 'matrix-mlm'); ?></th>
                        <th><?php _e('Date', 'matrix-mlm'); ?></th>
                        <th><?php _e('Actions', 'matrix-mlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($withdrawals as $w): ?>
                    <tr>
                        <td><?php echo $w->id; ?></td>
                        <td><?php echo esc_html($w->user_login); ?><br><small><?php echo esc_html($w->user_email); ?></small></td>
                        <td><?php echo esc_html(ucfirst($w->method)); ?></td>
                        <td><?php echo $currency . number_format($w->amount, 2); ?></td>
                        <td><?php echo $currency . number_format($w->charge, 2); ?></td>
                        <td><?php echo $currency . number_format($w->net_amount, 2); ?></td>
                        <td><small><?php echo esc_html($w->account_details); ?></small></td>
                        <td><span class="matrix-badge matrix-badge-<?php echo $w->status; ?>"><?php echo ucfirst($w->status); ?></span></td>
                        <td><?php echo date('M d, Y H:i', strtotime($w->created_at)); ?></td>
                        <td>
                            <?php if ($w->status === 'pending'): ?>
                            <button class="button button-small button-primary" onclick="matrixAdminAction('approve_withdrawal', {id: <?php echo $w->id; ?>})"><?php _e('Approve', 'matrix-mlm'); ?></button>
                            <button class="button button-small" onclick="matrixRejectWithdrawal(<?php echo $w->id; ?>)"><?php _e('Reject', 'matrix-mlm'); ?></button>
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
