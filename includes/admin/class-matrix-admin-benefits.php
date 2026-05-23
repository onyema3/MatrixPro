<?php
/**
 * Admin Benefits Management
 *
 * CRUD UI for the user-facing Benefits cards rendered on the
 * dashboard's Benefits tab. Mirrors the action-based routing pattern
 * used by Matrix_MLM_Admin_Plans (?action=add / edit / delete) so
 * operators have a consistent experience across the admin.
 *
 * Each benefit row is a small content object — title, slug, icon,
 * short copy, long copy, optional CTA, display order, status —
 * persisted in wp_matrix_benefits and read by
 * Matrix_MLM_User_Benefits::render() at request time.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Benefits {

    /**
     * Top-level entry point. Routes to add/edit/delete sub-views or
     * renders the list table. Form submissions and delete confirms
     * are handled inline before any output so notices can render at
     * the top of the resulting page.
     */
    public function render() {
        if (!current_user_can('manage_matrix_mlm')) {
            wp_die(__('You do not have permission to manage benefits.', 'matrix-mlm'));
        }

        // Save (create or update) — must run before render so the
        // success/error notice appears above the page that follows.
        if (isset($_POST['save_benefit']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'matrix_save_benefit')) {
            $this->save_benefit();
        }

        // Delete — same reasoning. Idempotent: a stale link to a row
        // that's already been removed surfaces a "not found" notice
        // rather than a 500.
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])
            && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'matrix_delete_benefit')) {
            $this->delete_benefit(intval($_GET['id']));
        }

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        if ($action === 'edit' && isset($_GET['id'])) {
            $this->render_edit_form(intval($_GET['id']));
            return;
        }
        if ($action === 'add') {
            $this->render_form(null);
            return;
        }

        $this->render_list();
    }

    /**
     * Render the benefits list as a WP-style admin table.
     */
    private function render_list() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_benefits';
        $benefits = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY display_order ASC, id ASC"
        );
        $add_url = admin_url('admin.php?page=matrix-mlm-benefits&action=add');
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1>
                <?php _e('Member Benefits', 'matrix-mlm'); ?>
                <a href="<?php echo esc_url($add_url); ?>" class="page-title-action"><?php _e('Add New Benefit', 'matrix-mlm'); ?></a>
            </h1>
            <p class="description">
                <?php _e('Benefits appear as cards on the user dashboard\'s Benefits tab. They are visible only to users with at least one active plan.', 'matrix-mlm'); ?>
            </p>

            <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th style="width:60px;"><?php _e('Icon', 'matrix-mlm'); ?></th>
                        <th><?php _e('Title', 'matrix-mlm'); ?></th>
                        <th><?php _e('Slug', 'matrix-mlm'); ?></th>
                        <th style="width:80px;"><?php _e('Order', 'matrix-mlm'); ?></th>
                        <th style="width:90px;"><?php _e('Status', 'matrix-mlm'); ?></th>
                        <th style="width:160px;"><?php _e('Actions', 'matrix-mlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($benefits)): ?>
                    <tr>
                        <td colspan="6" style="padding:24px;text-align:center;color:#6b7280;">
                            <?php _e('No benefits defined yet. Click "Add New Benefit" to create one.', 'matrix-mlm'); ?>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($benefits as $benefit):
                            $edit_url   = admin_url('admin.php?page=matrix-mlm-benefits&action=edit&id=' . $benefit->id);
                            $delete_url = wp_nonce_url(
                                admin_url('admin.php?page=matrix-mlm-benefits&action=delete&id=' . $benefit->id),
                                'matrix_delete_benefit'
                            );
                            ?>
                        <tr>
                            <td><?php echo self::render_icon_preview($benefit->icon, 32); ?></td>
                            <td><strong><?php echo esc_html($benefit->title); ?></strong></td>
                            <td><code style="font-size:11px;"><?php echo esc_html($benefit->slug); ?></code></td>
                            <td><?php echo intval($benefit->display_order); ?></td>
                            <td>
                                <span class="matrix-badge matrix-badge-<?php echo esc_attr($benefit->status); ?>">
                                    <?php echo esc_html(ucfirst($benefit->status)); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small"><?php _e('Edit', 'matrix-mlm'); ?></a>
                                <a href="<?php echo esc_url($delete_url); ?>" class="button button-small" style="color:#dc2626;"
                                   onclick="return confirm('<?php echo esc_js(__('Delete this benefit? This cannot be undone.', 'matrix-mlm')); ?>')">
                                    <?php _e('Delete', 'matrix-mlm'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Edit-mode wrapper. Loads the row and falls through to render_form.
     */
    private function render_edit_form($id) {
        global $wpdb;
        $benefit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_benefits WHERE id = %d",
            $id
        ));
        if (!$benefit) {
            echo '<div class="wrap matrix-admin-wrap"><div class="notice notice-error"><p>'
                . esc_html__('Benefit not found.', 'matrix-mlm') . '</p></div></div>';
            return;
        }
        $this->render_form($benefit);
    }

    /**
     * Render the add/edit form. Same form for both — when $benefit is
     * null we show empty defaults, otherwise we pre-fill from the row.
     *
     * The icon field accepts either a Dashicon class name (e.g.
     * "dashicons-phone") OR a fully-qualified image URL. Help text
     * documents both, and the live preview shows what the user will
     * actually see.
     */
    private function render_form($benefit) {
        $is_edit = $benefit !== null;
        $back_url = admin_url('admin.php?page=matrix-mlm-benefits');
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1>
                <?php echo $is_edit
                    ? esc_html__('Edit Benefit', 'matrix-mlm')
                    : esc_html__('Add New Benefit', 'matrix-mlm'); ?>
            </h1>

            <form method="post" class="matrix-admin-card" style="max-width:900px;">
                <?php wp_nonce_field('matrix_save_benefit'); ?>
                <?php if ($is_edit): ?>
                <input type="hidden" name="benefit_id" value="<?php echo intval($benefit->id); ?>">
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th><label for="benefit_title"><?php _e('Title', 'matrix-mlm'); ?></label></th>
                        <td>
                            <input type="text" id="benefit_title" name="title" class="regular-text" required
                                   value="<?php echo esc_attr($benefit->title ?? ''); ?>">
                            <p class="description"><?php _e('Shown as the card heading. Keep it short — e.g. "CUG", "Health Insurance".', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="benefit_slug"><?php _e('Slug', 'matrix-mlm'); ?></label></th>
                        <td>
                            <input type="text" id="benefit_slug" name="slug" class="regular-text"
                                   value="<?php echo esc_attr($benefit->slug ?? ''); ?>"
                                   pattern="[a-z0-9\-]*"
                                   placeholder="<?php esc_attr_e('auto-generated from title if blank', 'matrix-mlm'); ?>">
                            <p class="description"><?php _e('Lowercase letters, numbers, and dashes only. Used internally for deep linking. Leave blank to auto-generate from the title.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="benefit_icon"><?php _e('Icon', 'matrix-mlm'); ?></label></th>
                        <td>
                            <input type="text" id="benefit_icon" name="icon" class="regular-text"
                                   value="<?php echo esc_attr($benefit->icon ?? ''); ?>"
                                   placeholder="dashicons-phone or https://...">
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: link to the WordPress Dashicons reference */
                                    wp_kses(
                                        __('Either a <a href="%s" target="_blank">Dashicons</a> class (e.g. <code>dashicons-phone</code>, <code>dashicons-heart</code>, <code>dashicons-shield</code>) OR a fully-qualified image URL.', 'matrix-mlm'),
                                        ['a' => ['href' => [], 'target' => []], 'code' => []]
                                    ),
                                    'https://developer.wordpress.org/resource/dashicons/'
                                );
                                ?>
                            </p>
                            <div id="benefit_icon_preview" style="margin-top:10px;">
                                <?php echo self::render_icon_preview($benefit->icon ?? '', 48); ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="benefit_short"><?php _e('Short Description', 'matrix-mlm'); ?></label></th>
                        <td>
                            <textarea id="benefit_short" name="short_description" rows="2" class="large-text" maxlength="240"><?php echo esc_textarea($benefit->short_description ?? ''); ?></textarea>
                            <p class="description"><?php _e('One sentence shown on the card. Up to 240 characters.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="benefit_long"><?php _e('Long Description', 'matrix-mlm'); ?></label></th>
                        <td>
                            <?php
                            // Use wp_editor for a richer authoring experience —
                            // the user-facing modal renders sanitized HTML so
                            // links, paragraphs, and basic formatting come
                            // through as authored.
                            wp_editor(
                                $benefit->long_description ?? '',
                                'benefit_long',
                                [
                                    'textarea_name' => 'long_description',
                                    'media_buttons' => false,
                                    'textarea_rows' => 8,
                                    'teeny'         => true,
                                    'quicktags'     => true,
                                ]
                            );
                            ?>
                            <p class="description"><?php _e('Shown when the user clicks "Read more" on the card. HTML allowed.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="benefit_cta_label"><?php _e('Button Label (optional)', 'matrix-mlm'); ?></label></th>
                        <td>
                            <input type="text" id="benefit_cta_label" name="cta_label" class="regular-text"
                                   value="<?php echo esc_attr($benefit->cta_label ?? ''); ?>"
                                   placeholder="<?php esc_attr_e('e.g. Learn more, Enrol now', 'matrix-mlm'); ?>">
                            <p class="description"><?php _e('If set, a button with this label appears on the card. Leave blank for no button.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="benefit_cta_url"><?php _e('Button URL (optional)', 'matrix-mlm'); ?></label></th>
                        <td>
                            <input type="url" id="benefit_cta_url" name="cta_url" class="regular-text"
                                   value="<?php echo esc_attr($benefit->cta_url ?? ''); ?>"
                                   placeholder="https://...">
                            <p class="description"><?php _e('Where the button takes the user. External links open in a new tab.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="benefit_order"><?php _e('Display Order', 'matrix-mlm'); ?></label></th>
                        <td>
                            <input type="number" id="benefit_order" name="display_order" min="0" max="9999"
                                   value="<?php echo esc_attr($benefit->display_order ?? 0); ?>">
                            <p class="description"><?php _e('Lower numbers come first in the grid.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="benefit_status"><?php _e('Status', 'matrix-mlm'); ?></label></th>
                        <td>
                            <select id="benefit_status" name="status">
                                <option value="active" <?php selected($benefit->status ?? 'active', 'active'); ?>><?php _e('Active', 'matrix-mlm'); ?></option>
                                <option value="inactive" <?php selected($benefit->status ?? '', 'inactive'); ?>><?php _e('Inactive', 'matrix-mlm'); ?></option>
                            </select>
                            <p class="description"><?php _e('Inactive benefits are hidden from the user dashboard but kept in the database.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="save_benefit" class="button button-primary" value="<?php esc_attr_e('Save Benefit', 'matrix-mlm'); ?>">
                    <a href="<?php echo esc_url($back_url); ?>" class="button"><?php _e('Cancel', 'matrix-mlm'); ?></a>
                </p>
            </form>

            <script>
            (function() {
                // Live icon preview — keeps the rendered output in
                // sync with whatever the operator types so they can
                // tell at a glance whether they got the dashicon
                // class right (or pasted a working URL).
                var input   = document.getElementById('benefit_icon');
                var preview = document.getElementById('benefit_icon_preview');
                if (!input || !preview) return;
                input.addEventListener('input', function() {
                    var v = (input.value || '').trim();
                    if (v === '') {
                        preview.innerHTML = '<span style="color:#9ca3af;font-style:italic;font-size:12px;">(no icon)</span>';
                        return;
                    }
                    if (/^https?:\/\//i.test(v)) {
                        preview.innerHTML = '<img src="' + v.replace(/"/g, '&quot;') + '" alt="" style="width:48px;height:48px;object-fit:contain;border:1px solid #e5e7eb;border-radius:6px;padding:6px;background:#fafafa;">';
                    } else if (/^dashicons-/i.test(v)) {
                        preview.innerHTML = '<span class="dashicons ' + v.replace(/[^a-z0-9\-]/gi, '') + '" style="font-size:48px;width:48px;height:48px;color:#4f46e5;"></span>';
                    } else {
                        preview.innerHTML = '<span style="color:#b91c1c;font-size:12px;">Not a dashicon class or http(s) URL.</span>';
                    }
                });
            })();
            </script>
        </div>
        <?php
    }

    /**
     * Persist a new or edited benefit.
     *
     * Validation:
     *   - Title is required.
     *   - Slug is normalized via sanitize_title(); if the operator left
     *     it blank, we derive it from the title.
     *   - Slug uniqueness is enforced at the DB layer (UNIQUE KEY) — if
     *     the insert/update collides, we surface a friendly error
     *     instead of letting the wpdb error bubble up.
     *   - Status is whitelisted to active|inactive.
     *   - icon, cta_label, cta_url stored as-is after sanitisation; the
     *     icon column accepts either a dashicons class or a URL, so the
     *     renderer (not the saver) does the dispatch.
     */
    private function save_benefit() {
        global $wpdb;

        $id           = isset($_POST['benefit_id']) ? intval($_POST['benefit_id']) : 0;
        $title        = sanitize_text_field($_POST['title'] ?? '');
        $raw_slug     = sanitize_text_field($_POST['slug'] ?? '');
        $icon         = sanitize_text_field($_POST['icon'] ?? '');
        $short        = sanitize_textarea_field($_POST['short_description'] ?? '');
        $long         = wp_kses_post($_POST['long_description'] ?? '');
        $cta_label    = sanitize_text_field($_POST['cta_label'] ?? '');
        $cta_url      = esc_url_raw($_POST['cta_url'] ?? '');
        $order        = isset($_POST['display_order']) ? max(0, intval($_POST['display_order'])) : 0;
        $status_input = sanitize_text_field($_POST['status'] ?? 'active');
        $status       = in_array($status_input, ['active', 'inactive'], true) ? $status_input : 'active';

        if ($title === '') {
            self::admin_notice('error', __('Title is required.', 'matrix-mlm'));
            return;
        }

        // Slug derivation: explicit value wins, otherwise auto-derive
        // from title. sanitize_title() handles unicode, whitespace,
        // and uppercase transforms, so this is safe to feed straight
        // into the UNIQUE column.
        $slug = $raw_slug !== '' ? sanitize_title($raw_slug) : sanitize_title($title);
        if ($slug === '') {
            $slug = 'benefit-' . substr(md5(microtime(true)), 0, 8);
        }

        $data = [
            'title'             => $title,
            'slug'              => $slug,
            'icon'              => $icon,
            'short_description' => $short,
            'long_description'  => $long,
            'cta_label'         => $cta_label,
            'cta_url'           => $cta_url,
            'display_order'     => $order,
            'status'            => $status,
            'updated_at'        => current_time('mysql'),
        ];
        $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'];

        $table = $wpdb->prefix . 'matrix_benefits';

        if ($id > 0) {
            // Slug uniqueness pre-check on edit so we emit a friendly
            // message instead of a raw "Duplicate entry" wpdb error.
            $clash = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE slug = %s AND id <> %d",
                $slug,
                $id
            ));
            if ($clash) {
                self::admin_notice('error', sprintf(
                    /* translators: %s: slug value */
                    __('Slug "%s" is already used by another benefit. Please choose a different slug.', 'matrix-mlm'),
                    $slug
                ));
                return;
            }

            $result = $wpdb->update($table, $data, ['id' => $id], $formats, ['%d']);
            if ($result === false) {
                self::admin_notice('error', __('Could not update benefit.', 'matrix-mlm') . ' ' . esc_html($wpdb->last_error));
                return;
            }
            self::admin_notice('success', __('Benefit updated.', 'matrix-mlm'));
        } else {
            $clash = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE slug = %s",
                $slug
            ));
            if ($clash) {
                self::admin_notice('error', sprintf(
                    __('Slug "%s" is already used by another benefit. Please choose a different slug.', 'matrix-mlm'),
                    $slug
                ));
                return;
            }

            $data['created_at'] = current_time('mysql');
            $formats[] = '%s';
            $result = $wpdb->insert($table, $data, $formats);
            if ($result === false) {
                self::admin_notice('error', __('Could not create benefit.', 'matrix-mlm') . ' ' . esc_html($wpdb->last_error));
                return;
            }
            self::admin_notice('success', __('Benefit created.', 'matrix-mlm'));
        }
    }

    /**
     * Delete a benefit by id. Hard delete — there's no soft-delete
     * column on this table because deactivation already covers the
     * "hide but keep" use case.
     */
    private function delete_benefit($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, title FROM {$wpdb->prefix}matrix_benefits WHERE id = %d",
            $id
        ));
        if (!$row) {
            self::admin_notice('error', __('Benefit not found.', 'matrix-mlm'));
            return;
        }
        $wpdb->delete($wpdb->prefix . 'matrix_benefits', ['id' => $id], ['%d']);
        self::admin_notice('success', sprintf(
            /* translators: %s: benefit title */
            __('Benefit "%s" deleted.', 'matrix-mlm'),
            esc_html($row->title)
        ));
    }

    /**
     * Render an inline icon preview from a stored value. Detects
     * whether the value is a Dashicons class or a URL by looking at
     * the leading characters and falls back to a quiet placeholder
     * when neither matches.
     *
     * Static so list-table rows and the form's preview block can
     * share the same logic without duplicating it.
     *
     * @param string $icon The stored icon value.
     * @param int    $size Pixel size for the preview.
     * @return string HTML.
     */
    public static function render_icon_preview($icon, $size = 32) {
        $icon = (string) $icon;
        if ($icon === '') {
            return '<span style="color:#9ca3af;font-style:italic;font-size:12px;">—</span>';
        }
        if (preg_match('#^https?://#i', $icon)) {
            return sprintf(
                '<img src="%s" alt="" style="width:%dpx;height:%dpx;object-fit:contain;border:1px solid #e5e7eb;border-radius:6px;padding:4px;background:#fafafa;">',
                esc_url($icon),
                intval($size),
                intval($size)
            );
        }
        if (preg_match('/^dashicons-[a-z0-9\-]+$/i', $icon)) {
            return sprintf(
                '<span class="dashicons %s" style="font-size:%dpx;width:%dpx;height:%dpx;color:#4f46e5;"></span>',
                esc_attr($icon),
                intval($size),
                intval($size),
                intval($size)
            );
        }
        return '<span style="color:#b91c1c;font-size:11px;">' . esc_html__('invalid', 'matrix-mlm') . '</span>';
    }

    /**
     * Queue a transient admin notice that renders on the next pageload.
     * Form submissions are POSTed to the same admin URL and we render
     * inline (no redirect), so we can echo the notice immediately.
     */
    private static function admin_notice($type, $message) {
        $cls = $type === 'error' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>'
            . wp_kses_post($message)
            . '</p></div>';
    }
}
