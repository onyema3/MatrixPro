<?php
/**
 * User Support Tickets
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Tickets {

    public function render($user_id) {
        $support = new Matrix_MLM_Support();

        // View specific ticket
        if (isset($_GET['ticket_id'])) {
            $this->render_ticket($user_id, intval($_GET['ticket_id']));
            return;
        }

        $tickets = $support->get_user_tickets($user_id, null, 20);
        ?>
        <h2><?php _e('Support Tickets', 'matrix-mlm'); ?></h2>

        <div class="matrix-form-card">
            <h3><?php _e('Create New Ticket', 'matrix-mlm'); ?></h3>
            <form id="matrix-ticket-form" class="matrix-form">
                <div class="matrix-form-row">
                    <div class="matrix-form-group">
                        <label><?php _e('Subject', 'matrix-mlm'); ?></label>
                        <input type="text" name="subject" required>
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('Priority', 'matrix-mlm'); ?></label>
                        <select name="priority">
                            <option value="low"><?php _e('Low', 'matrix-mlm'); ?></option>
                            <option value="medium" selected><?php _e('Medium', 'matrix-mlm'); ?></option>
                            <option value="high"><?php _e('High', 'matrix-mlm'); ?></option>
                            <option value="urgent"><?php _e('Urgent', 'matrix-mlm'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Message', 'matrix-mlm'); ?></label>
                    <textarea name="message" rows="5" required></textarea>
                </div>
                <button type="submit" class="matrix-btn matrix-btn-primary"><?php _e('Submit Ticket', 'matrix-mlm'); ?></button>
            </form>
        </div>

        <h3><?php _e('My Tickets', 'matrix-mlm'); ?></h3>
        <table class="matrix-table">
            <thead><tr><th>#</th><th><?php _e('Subject', 'matrix-mlm'); ?></th><th><?php _e('Priority', 'matrix-mlm'); ?></th><th><?php _e('Status', 'matrix-mlm'); ?></th><th><?php _e('Updated', 'matrix-mlm'); ?></th><th><?php _e('Action', 'matrix-mlm'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($tickets as $ticket): ?>
                <tr>
                    <td>#<?php echo (int) $ticket->id; ?></td>
                    <td><?php echo esc_html($ticket->subject); ?></td>
                    <td><span class="matrix-badge matrix-badge-<?php echo esc_attr($ticket->priority); ?>"><?php echo esc_html(ucfirst($ticket->priority)); ?></span></td>
                    <td><span class="matrix-badge matrix-badge-<?php echo esc_attr($ticket->status); ?>"><?php echo esc_html(ucfirst(str_replace('_', ' ', $ticket->status))); ?></span></td>
                    <td><?php echo date('M d, Y', strtotime($ticket->updated_at)); ?></td>
                    <td><a href="<?php echo home_url('/matrix-dashboard/?tab=tickets&ticket_id=' . $ticket->id); ?>" class="matrix-btn matrix-btn-sm"><?php _e('View', 'matrix-mlm'); ?></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_ticket($user_id, $ticket_id) {
        $support = new Matrix_MLM_Support();
        $ticket = $support->get_ticket($ticket_id);

        if (!$ticket || $ticket->user_id != $user_id) {
            echo '<div class="matrix-alert matrix-alert-danger">' . __('Ticket not found.', 'matrix-mlm') . '</div>';
            return;
        }

        $messages = $support->get_messages($ticket_id);
        ?>
        <h2><?php echo sprintf(__('Ticket #%d: %s', 'matrix-mlm'), $ticket->id, esc_html($ticket->subject)); ?></h2>
        <a href="<?php echo home_url('/matrix-dashboard/?tab=tickets'); ?>" class="matrix-btn matrix-btn-sm"><?php _e('Back to Tickets', 'matrix-mlm'); ?></a>

        <div class="matrix-ticket-messages">
            <?php foreach ($messages as $msg): ?>
            <div class="matrix-message <?php echo $msg->is_admin ? 'admin-reply' : 'user-reply'; ?>">
                <div class="message-header">
                    <strong><?php echo esc_html($msg->user_login); ?></strong>
                    <?php if ($msg->is_admin): ?><span class="matrix-badge matrix-badge-info">Support</span><?php endif; ?>
                    <span><?php echo date('M d, Y H:i', strtotime($msg->created_at)); ?></span>
                </div>
                <div class="message-body"><?php echo nl2br(esc_html($msg->message)); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($ticket->status !== 'closed'): ?>
        <div class="matrix-form-card">
            <form id="matrix-ticket-reply-form" class="matrix-form">
                <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">
                <div class="matrix-form-group">
                    <label><?php _e('Reply', 'matrix-mlm'); ?></label>
                    <textarea name="message" rows="4" required></textarea>
                </div>
                <button type="submit" class="matrix-btn matrix-btn-primary"><?php _e('Send Reply', 'matrix-mlm'); ?></button>
            </form>
        </div>
        <?php endif; ?>
        <?php
    }
}
