<?php
/**
 * New Message Notification Template (offline push).
 *
 * Fired by Matrix_MLM_Notifications::send_message_notification() when
 * a member receives a direct message or team-room post and is NOT
 * currently on the dashboard (no recent presence pulse — see
 * Matrix_MLM_Messaging::is_online()).
 *
 * Variables: $username, $sender, $thread_label, $is_dm, $preview,
 *            $dashboard_url, $site_name.
 *
 * Privacy posture: the body preview is intentionally short (clipped
 * to ~280 chars at the caller) and rendered inside a clearly
 * bracketed blockquote so a recipient skim-reading their inbox
 * realises this is a notification, not the full conversation. The
 * "Open Conversation" CTA lands them straight on the thread inside
 * the dashboard — the canonical, signed-URL view of any attachments.
 */
if (!defined('ABSPATH')) exit;

ob_start();
?>
<h2 style="color:#1f2937;font-size:20px;margin:0 0 16px;font-weight:600;">
    <?php if (!empty($is_dm)): ?>
        <?php printf(
            /* translators: %s: sender display name */
            esc_html__('New message from %s', 'matrix-mlm'),
            esc_html($sender)
        ); ?>
    <?php else: ?>
        <?php printf(
            /* translators: %s: thread/team-room title */
            esc_html__('New message in %s', 'matrix-mlm'),
            esc_html($thread_label)
        ); ?>
    <?php endif; ?>
</h2>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 16px;">
    <?php printf(esc_html__('Hello %s,', 'matrix-mlm'), '<strong>' . esc_html($username) . '</strong>'); ?>
</p>
<?php if (!empty($is_dm)): ?>
    <p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 16px;">
        <?php printf(
            /* translators: %s: sender display name */
            wp_kses(__('You have a new direct message from <strong>%s</strong>.', 'matrix-mlm'), ['strong' => []]),
            esc_html($sender)
        ); ?>
    </p>
<?php else: ?>
    <p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 16px;">
        <?php printf(
            /* translators: 1: sender display name, 2: team-room title */
            wp_kses(__('<strong>%1$s</strong> posted in <strong>%2$s</strong>.', 'matrix-mlm'), ['strong' => []]),
            esc_html($sender),
            esc_html($thread_label)
        ); ?>
    </p>
<?php endif; ?>

<?php if (!empty($preview)): ?>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 24px;">
        <tr>
            <td style="background:#f9fafb;border-left:3px solid #4f46e5;border-radius:0 6px 6px 0;padding:14px 18px;color:#374151;font-size:14px;line-height:1.55;font-style:italic;">
                <?php echo nl2br(esc_html($preview)); ?>
            </td>
        </tr>
    </table>
<?php endif; ?>

<p style="text-align:center;margin:0 0 24px;">
    <a href="<?php echo esc_url($dashboard_url); ?>" style="background:#4f46e5;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block;">
        <?php esc_html_e('Open Conversation', 'matrix-mlm'); ?>
    </a>
</p>

<p style="color:#6b7280;font-size:12px;line-height:1.5;margin:0;">
    <?php esc_html_e('You are receiving this email because you were not online when the message arrived. Sign in to your dashboard to reply, mute the conversation, or block the sender.', 'matrix-mlm'); ?>
</p>
<?php
$content = ob_get_clean();
$footer_text = '';
include __DIR__ . '/base.php';
