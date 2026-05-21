<?php
/**
 * Admin Support Tickets
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Tickets {

    public function render() {
        if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
            $this->render_ticket_detail(intval($_GET['id']));
            return;
        }

        $support = new Matrix_MLM_Support();
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $tickets = $support->get_all_tickets($status_filter ?: null, 50, 0);

        global $wpdb;
        $totals = [
            'all' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_tickets"),
            'open' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_tickets WHERE status = 'open'"),
            'answered' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_tickets WHERE status = 'answered'"),
            'customer_reply' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_tickets WHERE status = 'customer_reply'"),
            'closed' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_tickets WHERE status = 'closed'"),
        ];
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Support Tickets', 'matrix-mlm'); ?></h1>

            <ul class="subsubsub">
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-tickets'); ?>">All (<?php echo $totals['all']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-tickets&status=open'); ?>">Open (<?php echo $totals['open']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-tickets&status=customer_reply'); ?>">Customer Reply (<?php echo $totals['customer_reply']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-tickets&status=answered'); ?>">Answered (<?php echo $totals['answered']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-tickets&status=closed'); ?>">Closed (<?php echo $totals['closed']; ?>)</a></li>
            </ul>

            <table class="wp-list-table widefat fixed striped" style="margin-top: 30px;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php _e('Subject', 'matrix-mlm'); ?></th>
                        <th><?php _e('User', 'matrix-mlm'); ?></th>
                        <th><?php _e('Priority', 'matrix-mlm'); ?></th>
                        <th><?php _e('Status', 'matrix-mlm'); ?></th>
                        <th><?php _e('Last Updated', 'matrix-mlm'); ?></th>
                        <th><?php _e('Actions', 'matrix-mlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $ticket): ?>
                    <tr>
                        <td><?php echo $ticket->id; ?></td>
                        <td><strong><?php echo esc_html($ticket->subject); ?></strong></td>
                        <td><?php echo esc_html($ticket->user_login); ?></td>
                        <td><span class="matrix-badge matrix-badge-<?php echo $ticket->priority; ?>"><?php echo ucfirst($ticket->priority); ?></span></td>
                        <td><span class="matrix-badge matrix-badge-<?php echo $ticket->status; ?>"><?php echo ucfirst(str_replace('_', ' ', $ticket->status)); ?></span></td>
                        <td><?php echo date('M d, Y H:i', strtotime($ticket->updated_at)); ?></td>
                        <td><a href="<?php echo admin_url('admin.php?page=matrix-mlm-tickets&action=view&id=' . $ticket->id); ?>" class="button button-small"><?php _e('View', 'matrix-mlm'); ?></a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_ticket_detail($ticket_id) {
        $support = new Matrix_MLM_Support();
        $ticket = $support->get_ticket($ticket_id);
        $messages = $support->get_messages($ticket_id);

        if (!$ticket) {
            echo '<div class="notice notice-error"><p>' . __('Ticket not found', 'matrix-mlm') . '</p></div>';
            return;
        }

        // Handle reply
        if (isset($_POST['admin_reply']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_ticket_reply')) {
            $message = sanitize_textarea_field($_POST['message']);
            if ($message) {
                $support->reply($ticket_id, get_current_user_id(), $message, true);
                $messages = $support->get_messages($ticket_id);
                echo '<div class="notice notice-success"><p>' . __('Reply sent!', 'matrix-mlm') . '</p></div>';
            }
        }

        if (isset($_POST['close_ticket']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_ticket_reply')) {
            $support->close_ticket($ticket_id);
            $ticket->status = 'closed';
        }
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1>
                <?php printf(__('Ticket #%d: %s', 'matrix-mlm'), $ticket->id, esc_html($ticket->subject)); ?>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-tickets'); ?>" class="page-title-action"><?php _e('Back to Tickets', 'matrix-mlm'); ?></a>
            </h1>

            <div class="matrix-admin-card">
                <div class="matrix-ticket-meta">
                    <span><strong><?php _e('User:', 'matrix-mlm'); ?></strong> <?php echo esc_html($ticket->user_login); ?></span>
                    <span><strong><?php _e('Priority:', 'matrix-mlm'); ?></strong> <span class="matrix-badge matrix-badge-<?php echo $ticket->priority; ?>"><?php echo ucfirst($ticket->priority); ?></span></span>
                    <span><strong><?php _e('Status:', 'matrix-mlm'); ?></strong> <span class="matrix-badge matrix-badge-<?php echo $ticket->status; ?>"><?php echo ucfirst(str_replace('_', ' ', $ticket->status)); ?></span></span>
                    <span><strong><?php _e('Created:', 'matrix-mlm'); ?></strong> <?php echo date('M d, Y H:i', strtotime($ticket->created_at)); ?></span>
                </div>
            </div>

            <div class="matrix-admin-card matrix-ticket-messages">
                <?php foreach ($messages as $msg): ?>
                <div class="matrix-message <?php echo $msg->is_admin ? 'admin-message' : 'user-message'; ?>">
                    <div class="message-header">
                        <strong><?php echo esc_html($msg->user_login); ?></strong>
                        <?php if ($msg->is_admin): ?><span class="matrix-badge matrix-badge-info">Admin</span><?php endif; ?>
                        <span class="message-time"><?php echo date('M d, Y H:i', strtotime($msg->created_at)); ?></span>
                    </div>
                    <div class="message-body"><?php echo nl2br(esc_html($msg->message)); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($ticket->status !== 'closed'): ?>
            <div class="matrix-admin-card">
                <h2><?php _e('Reply', 'matrix-mlm'); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('matrix_ticket_reply'); ?>
                    <textarea name="message" rows="5" class="large-text" placeholder="<?php _e('Type your reply...', 'matrix-mlm'); ?>" required></textarea>
                    <p>
                        <input type="submit" name="admin_reply" class="button button-primary" value="<?php _e('Send Reply', 'matrix-mlm'); ?>">
                        <input type="submit" name="close_ticket" class="button" value="<?php _e('Close Ticket', 'matrix-mlm'); ?>">
                    </p>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
