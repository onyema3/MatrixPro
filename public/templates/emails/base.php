<?php
/**
 * Base Email Template
 * All email templates extend this layout.
 * Variables: $site_name, $content (inner HTML), $footer_text (optional)
 */
if (!defined('ABSPATH')) exit;

$site_name = $site_name ?? get_bloginfo('name');
$primary_color = get_option('matrix_mlm_primary_color', '#4f46e5');
$footer_text = $footer_text ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html($site_name); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f3f4f6;padding:40px 20px;">
<tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;">
    <!-- Header -->
    <tr>
        <td style="background:linear-gradient(135deg,<?php echo esc_attr($primary_color); ?>,#7c3aed);padding:30px 40px;text-align:center;border-radius:12px 12px 0 0;">
            <h1 style="color:#ffffff;margin:0;font-size:24px;font-weight:700;letter-spacing:-0.5px;"><?php echo esc_html($site_name); ?></h1>
        </td>
    </tr>
    <!-- Body -->
    <tr>
        <td style="background:#ffffff;padding:40px;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;">
            <?php echo $content; ?>
        </td>
    </tr>
    <!-- Footer -->
    <tr>
        <td style="background:#f9fafb;padding:24px 40px;text-align:center;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px;">
            <?php if ($footer_text): ?>
                <p style="color:#6b7280;font-size:13px;margin:0 0 8px;"><?php echo $footer_text; ?></p>
            <?php endif; ?>
            <p style="color:#9ca3af;font-size:12px;margin:0;">&copy; <?php echo date('Y'); ?> <?php echo esc_html($site_name); ?>. <?php _e('All rights reserved.', 'matrix-mlm'); ?></p>
            <p style="color:#9ca3af;font-size:11px;margin:8px 0 0;"><?php _e('This is an automated message. Please do not reply directly to this email.', 'matrix-mlm'); ?></p>
        </td>
    </tr>
</table>
</td></tr>
</table>
</body>
</html>
