<?php
/**
 * Admin Frontend Manager - SEO, Pages, Sections, Blog, Contact, FAQ, etc.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Frontend {

    public function render() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'seo';

        if (isset($_POST['save_frontend']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_save_frontend')) {
            $this->save_settings($tab);
        }
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Frontend Manager', 'matrix-mlm'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-frontend&tab=seo'); ?>" class="nav-tab <?php echo $tab === 'seo' ? 'nav-tab-active' : ''; ?>"><?php _e('SEO', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-frontend&tab=pages'); ?>" class="nav-tab <?php echo $tab === 'pages' ? 'nav-tab-active' : ''; ?>"><?php _e('Pages', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-frontend&tab=blog'); ?>" class="nav-tab <?php echo $tab === 'blog' ? 'nav-tab-active' : ''; ?>"><?php _e('Blog', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-frontend&tab=faq'); ?>" class="nav-tab <?php echo $tab === 'faq' ? 'nav-tab-active' : ''; ?>"><?php _e('FAQ', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-frontend&tab=contact'); ?>" class="nav-tab <?php echo $tab === 'contact' ? 'nav-tab-active' : ''; ?>"><?php _e('Contact', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-frontend&tab=footer'); ?>" class="nav-tab <?php echo $tab === 'footer' ? 'nav-tab-active' : ''; ?>"><?php _e('Footer', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-frontend&tab=social'); ?>" class="nav-tab <?php echo $tab === 'social' ? 'nav-tab-active' : ''; ?>"><?php _e('Social', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-frontend&tab=policy'); ?>" class="nav-tab <?php echo $tab === 'policy' ? 'nav-tab-active' : ''; ?>"><?php _e('Policy', 'matrix-mlm'); ?></a>
            </nav>

            <form method="post" class="matrix-admin-card">
                <?php wp_nonce_field('matrix_save_frontend'); ?>
                <input type="hidden" name="frontend_tab" value="<?php echo esc_attr($tab); ?>">

                <?php
                switch ($tab) {
                    case 'seo': $this->render_seo_tab(); break;
                    case 'pages': $this->render_pages_tab(); break;
                    case 'blog': $this->render_blog_tab(); break;
                    case 'faq': $this->render_faq_tab(); break;
                    case 'contact': $this->render_contact_tab(); break;
                    case 'footer': $this->render_footer_tab(); break;
                    case 'social': $this->render_social_tab(); break;
                    case 'policy': $this->render_policy_tab(); break;
                }
                ?>

                <p class="submit"><input type="submit" name="save_frontend" class="button button-primary" value="<?php _e('Save Changes', 'matrix-mlm'); ?>"></p>
            </form>
        </div>
        <?php
    }

    private function render_seo_tab() { ?>
        <h2><?php _e('SEO Settings', 'matrix-mlm'); ?></h2>
        <table class="form-table">
            <tr><th><?php _e('SEO Title', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_seo_title" class="large-text" value="<?php echo esc_attr(get_option('matrix_mlm_seo_title', '')); ?>"></td></tr>
            <tr><th><?php _e('Meta Description', 'matrix-mlm'); ?></th>
                <td><textarea name="matrix_mlm_meta_description" rows="3" class="large-text"><?php echo esc_textarea(get_option('matrix_mlm_meta_description', '')); ?></textarea></td></tr>
            <tr><th><?php _e('Meta Keywords', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_meta_keywords" class="large-text" value="<?php echo esc_attr(get_option('matrix_mlm_meta_keywords', '')); ?>">
                    <p class="description"><?php _e('Comma-separated keywords', 'matrix-mlm'); ?></p></td></tr>
            <tr><th><?php _e('OG Image URL', 'matrix-mlm'); ?></th>
                <td><input type="url" name="matrix_mlm_og_image" class="large-text" value="<?php echo esc_attr(get_option('matrix_mlm_og_image', '')); ?>"></td></tr>
            <tr><th><?php _e('Custom Head Code', 'matrix-mlm'); ?></th>
                <td><textarea name="matrix_mlm_custom_head_code" rows="5" class="large-text code"><?php echo esc_textarea(get_option('matrix_mlm_custom_head_code', '')); ?></textarea>
                    <p class="description"><?php _e('Analytics, tracking pixels, etc.', 'matrix-mlm'); ?></p></td></tr>
        </table>
    <?php }

    private function render_pages_tab() { ?>
        <h2><?php _e('Manage Pages', 'matrix-mlm'); ?></h2>
        <p class="description"><?php _e('These are the core pages created by Matrix MLM. Edit them in the WordPress Pages editor.', 'matrix-mlm'); ?></p>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th><?php _e('Page', 'matrix-mlm'); ?></th><th><?php _e('Shortcode', 'matrix-mlm'); ?></th><th><?php _e('Status', 'matrix-mlm'); ?></th><th><?php _e('Action', 'matrix-mlm'); ?></th></tr></thead>
            <tbody>
                <?php
                $pages = ['matrix-dashboard' => '[matrix_dashboard]', 'matrix-login' => '[matrix_login]', 'matrix-register' => '[matrix_register]', 'matrix-plans' => '[matrix_plans]'];
                foreach ($pages as $slug => $shortcode):
                    $page = get_page_by_path($slug);
                ?>
                <tr>
                    <td><?php echo ucwords(str_replace('-', ' ', $slug)); ?></td>
                    <td><code><?php echo $shortcode; ?></code></td>
                    <td><?php echo $page ? '<span class="matrix-badge matrix-badge-active">Published</span>' : '<span class="matrix-badge matrix-badge-inactive">Not Found</span>'; ?></td>
                    <td><?php if ($page): ?><a href="<?php echo get_edit_post_link($page->ID); ?>" class="button button-small"><?php _e('Edit', 'matrix-mlm'); ?></a><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php }

    private function render_blog_tab() { ?>
        <h2><?php _e('Blog Section', 'matrix-mlm'); ?></h2>
        <table class="form-table">
            <tr><th><?php _e('Enable Blog', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_blog_enabled" value="1" <?php checked(get_option('matrix_mlm_blog_enabled', 1)); ?>> <?php _e('Show blog section on frontend', 'matrix-mlm'); ?></label></td></tr>
            <tr><th><?php _e('Blog Title', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_blog_title" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_blog_title', 'Latest News')); ?>"></td></tr>
            <tr><th><?php _e('Posts Per Page', 'matrix-mlm'); ?></th>
                <td><input type="number" name="matrix_mlm_blog_per_page" min="1" max="20" value="<?php echo esc_attr(get_option('matrix_mlm_blog_per_page', 6)); ?>"></td></tr>
        </table>
    <?php }

    private function render_faq_tab() { ?>
        <h2><?php _e('FAQ Section', 'matrix-mlm'); ?></h2>
        <table class="form-table">
            <tr><th><?php _e('Enable FAQ', 'matrix-mlm'); ?></th>
                <td><label><input type="checkbox" name="matrix_mlm_faq_enabled" value="1" <?php checked(get_option('matrix_mlm_faq_enabled', 1)); ?>> <?php _e('Show FAQ section', 'matrix-mlm'); ?></label></td></tr>
            <tr><th><?php _e('FAQ Title', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_faq_title" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_faq_title', 'Frequently Asked Questions')); ?>"></td></tr>
            <tr><th><?php _e('FAQ Items (JSON)', 'matrix-mlm'); ?></th>
                <td><textarea name="matrix_mlm_faq_items" rows="10" class="large-text code"><?php echo esc_textarea(get_option('matrix_mlm_faq_items', '[{"q":"What is Matrix MLM?","a":"Matrix MLM is a multi-level marketing platform with matrix plan structures."},{"q":"How do I earn?","a":"You earn through referral commissions, level commissions, and matrix completion bonuses."}]')); ?></textarea>
                <p class="description"><?php _e('JSON array of objects with "q" (question) and "a" (answer) keys', 'matrix-mlm'); ?></p></td></tr>
        </table>
    <?php }

    private function render_contact_tab() { ?>
        <h2><?php _e('Contact Us', 'matrix-mlm'); ?></h2>
        <table class="form-table">
            <tr><th><?php _e('Contact Email', 'matrix-mlm'); ?></th>
                <td><input type="email" name="matrix_mlm_contact_email" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_contact_email', get_option('admin_email'))); ?>"></td></tr>
            <tr><th><?php _e('Contact Phone', 'matrix-mlm'); ?></th>
                <td><input type="text" name="matrix_mlm_contact_phone" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_contact_phone', '')); ?>"></td></tr>
            <tr><th><?php _e('Contact Address', 'matrix-mlm'); ?></th>
                <td><textarea name="matrix_mlm_contact_address" rows="3" class="large-text"><?php echo esc_textarea(get_option('matrix_mlm_contact_address', '')); ?></textarea></td></tr>
        </table>
    <?php }

    private function render_footer_tab() { ?>
        <h2><?php _e('Footer Section', 'matrix-mlm'); ?></h2>
        <table class="form-table">
            <tr><th><?php _e('Footer Text', 'matrix-mlm'); ?></th>
                <td><textarea name="matrix_mlm_footer_text" rows="3" class="large-text"><?php echo esc_textarea(get_option('matrix_mlm_footer_text', '© 2024 Matrix MLM Pro. All rights reserved.')); ?></textarea></td></tr>
            <tr><th><?php _e('Footer About', 'matrix-mlm'); ?></th>
                <td><textarea name="matrix_mlm_footer_about" rows="3" class="large-text"><?php echo esc_textarea(get_option('matrix_mlm_footer_about', '')); ?></textarea></td></tr>
        </table>
    <?php }

    private function render_social_tab() { ?>
        <h2><?php _e('Social Icons', 'matrix-mlm'); ?></h2>
        <table class="form-table">
            <tr><th>Facebook</th><td><input type="url" name="matrix_mlm_social_facebook" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_social_facebook', '')); ?>"></td></tr>
            <tr><th>Twitter / X</th><td><input type="url" name="matrix_mlm_social_twitter" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_social_twitter', '')); ?>"></td></tr>
            <tr><th>Instagram</th><td><input type="url" name="matrix_mlm_social_instagram" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_social_instagram', '')); ?>"></td></tr>
            <tr><th>LinkedIn</th><td><input type="url" name="matrix_mlm_social_linkedin" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_social_linkedin', '')); ?>"></td></tr>
            <tr><th>YouTube</th><td><input type="url" name="matrix_mlm_social_youtube" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_social_youtube', '')); ?>"></td></tr>
            <tr><th>Telegram</th><td><input type="url" name="matrix_mlm_social_telegram" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_social_telegram', '')); ?>"></td></tr>
            <tr><th>WhatsApp</th><td><input type="url" name="matrix_mlm_social_whatsapp" class="regular-text" value="<?php echo esc_attr(get_option('matrix_mlm_social_whatsapp', '')); ?>"></td></tr>
        </table>
    <?php }

    private function render_policy_tab() { ?>
        <h2><?php _e('Policy Pages', 'matrix-mlm'); ?></h2>
        <table class="form-table">
            <tr><th><?php _e('Privacy Policy', 'matrix-mlm'); ?></th>
                <td><textarea name="matrix_mlm_privacy_policy" rows="10" class="large-text"><?php echo esc_textarea(get_option('matrix_mlm_privacy_policy', '')); ?></textarea></td></tr>
            <tr><th><?php _e('Terms of Service', 'matrix-mlm'); ?></th>
                <td><textarea name="matrix_mlm_terms_of_service" rows="10" class="large-text"><?php echo esc_textarea(get_option('matrix_mlm_terms_of_service', '')); ?></textarea></td></tr>
            <tr><th><?php _e('Refund Policy', 'matrix-mlm'); ?></th>
                <td><textarea name="matrix_mlm_refund_policy" rows="10" class="large-text"><?php echo esc_textarea(get_option('matrix_mlm_refund_policy', '')); ?></textarea></td></tr>
        </table>
    <?php }

    private function save_settings($tab) {
        // Defense-in-depth (H17). The submenu is registered with
        // 'manage_matrix_mlm' — broad reviewer cap, the right level
        // for landing on the page and reading the current values.
        // Persisting changes is a different threat model: this surface
        // includes 'matrix_mlm_custom_head_code', a raw HTML/JS dump
        // injected into <head> on every public page, plus body-text
        // fields that show on every public page (footer_text, policy,
        // contact). Reviewer-tier admins should not be able to ship
        // arbitrary content to public visitors. Save now requires the
        // higher 'manage_matrix_settings' cap, matching the
        // Backup/Settings/E-Pin-export tier established in PR #226 and
        // PR #235.
        if (!current_user_can('manage_matrix_settings')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('You do not have permission to save Frontend Manager settings.', 'matrix-mlm') . '</p></div>';
            return;
        }

        $fields = [];
        switch ($tab) {
            case 'seo': $fields = ['matrix_mlm_seo_title', 'matrix_mlm_meta_description', 'matrix_mlm_meta_keywords', 'matrix_mlm_og_image', 'matrix_mlm_custom_head_code']; break;
            case 'blog': $fields = ['matrix_mlm_blog_enabled', 'matrix_mlm_blog_title', 'matrix_mlm_blog_per_page']; break;
            case 'faq': $fields = ['matrix_mlm_faq_enabled', 'matrix_mlm_faq_title', 'matrix_mlm_faq_items']; break;
            case 'contact': $fields = ['matrix_mlm_contact_email', 'matrix_mlm_contact_phone', 'matrix_mlm_contact_address']; break;
            case 'footer': $fields = ['matrix_mlm_footer_text', 'matrix_mlm_footer_about']; break;
            case 'social': $fields = ['matrix_mlm_social_facebook', 'matrix_mlm_social_twitter', 'matrix_mlm_social_instagram', 'matrix_mlm_social_linkedin', 'matrix_mlm_social_youtube', 'matrix_mlm_social_telegram', 'matrix_mlm_social_whatsapp']; break;
            case 'policy': $fields = ['matrix_mlm_privacy_policy', 'matrix_mlm_terms_of_service', 'matrix_mlm_refund_policy']; break;
        }

        // Fields that intentionally accept raw HTML/JS for injection
        // into the public <head> (analytics, tracking pixels, custom
        // CSS via <style>). These are gated on the WP-native
        // 'unfiltered_html' capability — the same gate WordPress uses
        // for raw <script> / <iframe> in posts and widgets. On
        // multisite, non-super-admins (including a Matrix settings
        // admin who isn't also a network super admin) do NOT have
        // unfiltered_html, so they can save every other field on this
        // tab but cannot persist a script tag to be served to public
        // visitors. On single-site installs every administrator-role
        // user has unfiltered_html, so the legitimate workflow
        // (operator pastes a Google Analytics snippet) is unaffected.
        $raw_code_fields = ['matrix_mlm_custom_head_code'];
        $skipped_raw_fields = [];

        foreach ($fields as $field) {
            $raw = isset($_POST[$field]) ? wp_unslash($_POST[$field]) : '';

            if (in_array($field, $raw_code_fields, true)) {
                if (!current_user_can('unfiltered_html')) {
                    // Skip silently-but-noticeably: leave the existing
                    // value in place (do not overwrite) and remember
                    // the field name so we can surface a single
                    // grouped notice at the bottom. We deliberately do
                    // NOT short-circuit the rest of the save — the
                    // operator's other edits still need to land.
                    $skipped_raw_fields[] = $field;
                    continue;
                }
                $value = $raw;
            } elseif (in_array($field, ['matrix_mlm_og_image', 'matrix_mlm_social_facebook', 'matrix_mlm_social_twitter', 'matrix_mlm_social_instagram', 'matrix_mlm_social_linkedin', 'matrix_mlm_social_youtube', 'matrix_mlm_social_telegram', 'matrix_mlm_social_whatsapp'], true)) {
                $value = esc_url_raw($raw);
            } elseif ($field === 'matrix_mlm_contact_email') {
                $value = sanitize_email($raw);
            } elseif (in_array($field, ['matrix_mlm_meta_description', 'matrix_mlm_contact_address', 'matrix_mlm_footer_text', 'matrix_mlm_footer_about', 'matrix_mlm_privacy_policy', 'matrix_mlm_terms_of_service', 'matrix_mlm_refund_policy'], true)) {
                // Long-form body content shown on public pages. Allow
                // basic post-grade HTML (links, lists, formatting) but
                // strip script tags and event handlers via the WP
                // post-content allow-list.
                $value = wp_kses_post($raw);
            } elseif ($field === 'matrix_mlm_faq_items') {
                // Stored as JSON; keep raw shape but coerce to a
                // string. Render-side already escapes per-field via
                // esc_html, so we don't need to wp_kses here.
                $value = is_string($raw) ? $raw : '';
            } else {
                $value = sanitize_text_field($raw);
            }

            update_option($field, $value);
        }

        if (!empty($skipped_raw_fields)) {
            echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(
                /* translators: %s: comma-separated list of field names */
                __('The following fields were not saved because your role does not have the unfiltered_html capability required to inject raw HTML/JS: %s. Ask a super admin to update these fields.', 'matrix-mlm'),
                implode(', ', $skipped_raw_fields)
            )) . '</p></div>';
        }

        echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved!', 'matrix-mlm') . '</p></div>';
    }
}
