<?php
/**
 * Admin Frontend Manager - SEO, Pages, Sections, Blog, Contact, FAQ, etc.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Frontend {

    /**
     * Per-field sanitiser map for save_settings().
     *
     * Anything not in this map (matrix_mlm_custom_head_code) is
     * stored RAW after wp_unslash() because that field's whole
     * point is to inject HTML/script into <head> for analytics or
     * tracking pixels. Letting it through is intentional, but
     * because the byte string is then echoed verbatim into every
     * public page (Matrix_MLM_SEO::output_head_code), the SAVE
     * action is gated behind manage_matrix_settings rather than
     * the menu's broader manage_matrix_mlm cap (audit H17).
     *
     * Sanitiser keys correspond to a small dispatch in save_settings()
     * so we can apply per-field shape without one-off branches.
     */
    const FIELD_SANITIZERS = [
        // SEO
        'matrix_mlm_seo_title'         => 'text',
        'matrix_mlm_meta_description'  => 'textarea',
        'matrix_mlm_meta_keywords'     => 'text',
        'matrix_mlm_og_image'          => 'url',
        // matrix_mlm_custom_head_code intentionally NOT mapped — see docblock.

        // Blog
        'matrix_mlm_blog_enabled'      => 'bool',
        'matrix_mlm_blog_title'        => 'text',
        'matrix_mlm_blog_per_page'     => 'int',

        // FAQ
        'matrix_mlm_faq_enabled'       => 'bool',
        'matrix_mlm_faq_title'         => 'text',
        'matrix_mlm_faq_items'         => 'textarea', // JSON content; preserved as-is, no HTML allowed.

        // Contact
        'matrix_mlm_contact_email'     => 'email',
        'matrix_mlm_contact_phone'     => 'text',
        'matrix_mlm_contact_address'   => 'textarea',

        // Footer
        'matrix_mlm_footer_text'       => 'textarea',
        'matrix_mlm_footer_about'      => 'textarea',

        // Social
        'matrix_mlm_social_facebook'   => 'url',
        'matrix_mlm_social_twitter'    => 'url',
        'matrix_mlm_social_instagram'  => 'url',
        'matrix_mlm_social_linkedin'   => 'url',
        'matrix_mlm_social_youtube'    => 'url',
        'matrix_mlm_social_telegram'   => 'url',
        'matrix_mlm_social_whatsapp'   => 'url',

        // Policy pages — these render as user-visible HTML pages so
        // formatting tags are allowed, but script and event handlers
        // are stripped by wp_kses_post.
        'matrix_mlm_privacy_policy'    => 'kses',
        'matrix_mlm_terms_of_service'  => 'kses',
        'matrix_mlm_refund_policy'     => 'kses',
    ];

    public function render() {
        // Defense-in-depth at page entry. The submenu router enforces
        // manage_matrix_mlm; this re-check covers any future caller
        // that invokes render() outside the menu pipeline (REST hook,
        // CLI, custom admin_init handler) — same belt-and-braces
        // pattern PR #235 used for E-Pin render. Cheap insurance.
        if (!current_user_can('manage_matrix_mlm')) {
            wp_die(__('You do not have permission to access this page.', 'matrix-mlm'), 403);
        }

        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'seo';

        if (isset($_POST['save_frontend']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_save_frontend')) {
            // Privilege gate on the SAVE action. Reviewer-tier admins
            // (manage_matrix_mlm) can land on this page to read SEO
            // and policy copy, but writing here is dangerous because
            // matrix_mlm_custom_head_code is echoed verbatim into the
            // <head> of every public page — a low-tier admin who can
            // write here gets a site-wide stored-XSS lever (audit
            // H17). Saving therefore requires manage_matrix_settings,
            // matching how PR #226 raised gateway/balance saves and
            // PR #235 raised E-Pin export/generate.
            if (!current_user_can('manage_matrix_settings')) {
                wp_die(__('You do not have permission to save these settings.', 'matrix-mlm'), 403);
            }
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
        // Belt-and-braces re-check at the function boundary. render()
        // already gates this with the same cap, but a future caller
        // (REST hook, CLI, admin_init handler) could invoke save_settings()
        // directly. The byte string written through here is echoed
        // verbatim into <head> on every public page (matrix_mlm_custom_head_code
        // path), so this is the line that has to hold under any
        // call-site pattern. (audit H17)
        if (!current_user_can('manage_matrix_settings')) {
            wp_die(__('You do not have permission to save these settings.', 'matrix-mlm'), 403);
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

        foreach ($fields as $field) {
            $raw = isset($_POST[$field]) ? wp_unslash($_POST[$field]) : '';
            $value = $this->sanitize_field($field, $raw);
            update_option($field, $value);
        }

        echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved!', 'matrix-mlm') . '</p></div>';
    }

    /**
     * Apply per-field shape sanitisation.
     *
     * Fields in self::FIELD_SANITIZERS get their declared shape;
     * anything else (today: only matrix_mlm_custom_head_code) is
     * stored raw because the field's purpose is verbatim emission
     * into <head>. The cap gate at the save boundary is what makes
     * that safe.
     */
    private function sanitize_field($field, $raw) {
        $sanitizer = self::FIELD_SANITIZERS[$field] ?? null;
        switch ($sanitizer) {
            case 'text':
                return sanitize_text_field($raw);
            case 'textarea':
                return sanitize_textarea_field($raw);
            case 'email':
                return sanitize_email($raw);
            case 'url':
                return esc_url_raw($raw);
            case 'int':
                return intval($raw);
            case 'bool':
                return empty($raw) ? 0 : 1;
            case 'kses':
                return wp_kses_post($raw);
            default:
                // Intentionally raw — see FIELD_SANITIZERS docblock.
                return $raw;
        }
    }
}
