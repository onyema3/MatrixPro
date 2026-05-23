<?php
/**
 * Admin — Hospital list management
 *
 * CRUD UI for the wp_matrix_hospitals table that backs the user
 * healthcare form's "Choice of Hospital" dropdown. Mirrors the
 * action-based routing pattern used by Matrix_MLM_Admin_Benefits
 * and Matrix_MLM_Admin_Plans (?action=add / edit / delete) so
 * operators have a consistent experience across the admin.
 *
 * Each hospital is a small content object — name, state, optional
 * address/notes, display order, status — read at form-render time
 * by Matrix_MLM_User_Healthcare::get_hospitals_grouped() and
 * filtered live in JS by the applicant's selected Hospital State.
 *
 * Capability: manage_matrix_mlm — same gate as the Healthcare
 * triage page so reviewer permissions stay in lockstep across
 * the benefit's two admin surfaces.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Hospitals {

    const STATUSES = ['active', 'inactive'];

    /**
     * Top-level entry point. Routes to add/edit/delete sub-views or
     * renders the list table. Form submissions and delete confirms
     * are handled inline before any output so notices can render at
     * the top of the resulting page.
     */
    public function render() {
        if (!current_user_can('manage_matrix_mlm')) {
            wp_die(__('You do not have permission to manage hospitals.', 'matrix-mlm'));
        }

        if (!self::table_exists()) {
            if (class_exists('Matrix_MLM_Database')) {
                Matrix_MLM_Database::maybe_upgrade();
            }
        }

        // Save (create or update) — must run before render so the
        // success/error notice appears above the page that follows.
        if (isset($_POST['save_hospital']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'matrix_save_hospital')) {
            $this->save_hospital();
        }

        // Delete — idempotent: a stale link to a row that's already
        // been removed surfaces a "not found" notice rather than a
        // 500.
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])
            && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'matrix_delete_hospital')) {
            $this->delete_hospital(intval($_GET['id']));
        }

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        if ($action === 'edit' && isset($_GET['id'])) {
            $this->render_form(intval($_GET['id']));
            return;
        }
        if ($action === 'add') {
            $this->render_form(0);
            return;
        }

        $this->render_list();
    }

    /**
     * List view — admin table with optional state filter.
     */
    private function render_list() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_hospitals';

        if (!self::table_exists()) {
            ?>
            <div class="wrap matrix-admin-wrap">
                <h1><?php _e('Hospitals', 'matrix-mlm'); ?></h1>
                <div class="notice notice-info">
                    <p><?php _e('Hospital storage is being initialised. Please reload this page in a moment.', 'matrix-mlm'); ?></p>
                </div>
            </div>
            <?php
            return;
        }

        $states = self::nigerian_states();

        $filter_state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
        if ($filter_state !== '' && !in_array($filter_state, $states, true)) {
            $filter_state = '';
        }

        $per_page = 50;
        $paged    = max(1, isset($_GET['paged']) ? intval($_GET['paged']) : 1);
        $offset   = ($paged - 1) * $per_page;

        if ($filter_state !== '') {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE state = %s",
                $filter_state
            ));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table}
                  WHERE state = %s
                  ORDER BY display_order ASC, name ASC
                  LIMIT %d OFFSET %d",
                $filter_state, $per_page, $offset
            ));
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $rows  = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table}
                  ORDER BY state ASC, display_order ASC, name ASC
                  LIMIT %d OFFSET %d",
                $per_page, $offset
            ));
        }
        $total_pages = max(1, (int) ceil($total / $per_page));

        $base_url = admin_url('admin.php?page=matrix-mlm-hospitals');
        $add_url  = add_query_arg('action', 'add', $base_url);
        $counts_by_state = self::counts_by_state();
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1>
                <?php _e('Hospitals', 'matrix-mlm'); ?>
                <a href="<?php echo esc_url($add_url); ?>" class="page-title-action"><?php _e('Add Hospital', 'matrix-mlm'); ?></a>
            </h1>
            <p class="description">
                <?php _e('Hospitals listed here populate the "Choice of Hospital" dropdown on the member-facing Healthcare enrolment form. Only active hospitals are shown to members. Filtering on the form is by Hospital State.', 'matrix-mlm'); ?>
            </p>

            <form method="get" action="" style="margin:16px 0 0;">
                <input type="hidden" name="page" value="matrix-mlm-hospitals">
                <label for="matrix-hospitals-state-filter" style="font-weight:600;margin-right:6px;">
                    <?php _e('Filter by state:', 'matrix-mlm'); ?>
                </label>
                <select id="matrix-hospitals-state-filter" name="state" onchange="this.form.submit()">
                    <option value=""><?php
                        /* translators: %d: total hospital count */
                        printf(esc_html__('All states (%d)', 'matrix-mlm'), $total);
                    ?></option>
                    <?php foreach ($states as $state):
                        $count = (int) ($counts_by_state[$state] ?? 0);
                    ?>
                        <option value="<?php echo esc_attr($state); ?>" <?php selected($filter_state, $state); ?>>
                            <?php echo esc_html($state); ?> (<?php echo $count; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($filter_state !== ''): ?>
                    <a href="<?php echo esc_url($base_url); ?>" class="button button-small" style="margin-left:6px;">
                        <?php _e('Clear', 'matrix-mlm'); ?>
                    </a>
                <?php endif; ?>
            </form>

            <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th style="width:60px;"><?php _e('ID', 'matrix-mlm'); ?></th>
                        <th><?php _e('Name', 'matrix-mlm'); ?></th>
                        <th style="width:160px;"><?php _e('State', 'matrix-mlm'); ?></th>
                        <th><?php _e('Address', 'matrix-mlm'); ?></th>
                        <th style="width:80px;"><?php _e('Order', 'matrix-mlm'); ?></th>
                        <th style="width:90px;"><?php _e('Status', 'matrix-mlm'); ?></th>
                        <th style="width:160px;"><?php _e('Actions', 'matrix-mlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="7" style="padding:24px;text-align:center;color:#6b7280;">
                                <?php if ($filter_state !== ''): ?>
                                    <?php
                                    /* translators: %s: state name */
                                    printf(esc_html__('No hospitals listed for %s yet. Click "Add Hospital" to create one.', 'matrix-mlm'), esc_html($filter_state));
                                    ?>
                                <?php else: ?>
                                    <?php _e('No hospitals defined yet. Click "Add Hospital" to create one — the member-facing healthcare form needs at least one hospital per state members may live in.', 'matrix-mlm'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row):
                            $edit_url   = add_query_arg(['action' => 'edit', 'id' => $row->id], $base_url);
                            $delete_url = wp_nonce_url(
                                add_query_arg(['action' => 'delete', 'id' => $row->id], $base_url),
                                'matrix_delete_hospital'
                            );
                        ?>
                        <tr>
                            <td><?php echo intval($row->id); ?></td>
                            <td>
                                <strong><?php echo esc_html($row->name); ?></strong>
                                <?php if (!empty($row->notes)): ?>
                                    <br><span style="font-size:11px;color:#6b7280;"><?php echo esc_html(wp_trim_words($row->notes, 12)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($row->state); ?></td>
                            <td>
                                <?php if (!empty($row->address)): ?>
                                    <span style="font-size:12px;color:#374151;"><?php echo esc_html($row->address); ?></span>
                                <?php else: ?>
                                    <em style="color:#9ca3af;">—</em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo intval($row->display_order); ?></td>
                            <td>
                                <span class="matrix-badge matrix-badge-<?php echo esc_attr($row->status); ?>">
                                    <?php echo esc_html(ucfirst($row->status)); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small"><?php _e('Edit', 'matrix-mlm'); ?></a>
                                <a href="<?php echo esc_url($delete_url); ?>" class="button button-small" style="color:#dc2626;"
                                   onclick="return confirm('<?php echo esc_js(__('Delete this hospital? Members who picked it on a pending application will need to choose another.', 'matrix-mlm')); ?>')">
                                    <?php _e('Delete', 'matrix-mlm'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom" style="margin-top:12px;">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php
                        /* translators: %s: number of items */
                        printf(_n('%s item', '%s items', $total, 'matrix-mlm'), number_format_i18n($total));
                        ?>
                    </span>
                    <span class="pagination-links">
                        <?php
                        $pagination_args = ['paged' => '%#%'];
                        if ($filter_state !== '') {
                            $pagination_args['state'] = $filter_state;
                        }
                        echo paginate_links([
                            'base'      => add_query_arg($pagination_args, $base_url),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $paged,
                        ]);
                        ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Add/Edit form. $id of 0 means "create new".
     */
    private function render_form($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_hospitals';

        $is_edit = $id > 0;
        $row = null;
        if ($is_edit) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
            if (!$row) {
                ?>
                <div class="wrap matrix-admin-wrap">
                    <h1><?php _e('Hospital', 'matrix-mlm'); ?></h1>
                    <div class="notice notice-error">
                        <p><?php _e('Hospital not found. It may have been deleted.', 'matrix-mlm'); ?></p>
                    </div>
                    <p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=matrix-mlm-hospitals')); ?>" class="button">
                            &larr; <?php _e('Back to list', 'matrix-mlm'); ?>
                        </a>
                    </p>
                </div>
                <?php
                return;
            }
        }

        $name          = $row ? (string) $row->name : '';
        $state         = $row ? (string) $row->state : '';
        $address       = $row ? (string) ($row->address ?? '') : '';
        $notes         = $row ? (string) ($row->notes ?? '') : '';
        $display_order = $row ? (int) $row->display_order : 0;
        $status        = $row ? (string) $row->status : 'active';

        $back_url = admin_url('admin.php?page=matrix-mlm-hospitals');
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1>
                <?php echo $is_edit
                    ? esc_html__('Edit Hospital', 'matrix-mlm')
                    : esc_html__('Add Hospital', 'matrix-mlm'); ?>
                <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">
                    <?php _e('Back to list', 'matrix-mlm'); ?>
                </a>
            </h1>

            <form method="post" action="<?php echo esc_url($back_url); ?>">
                <?php wp_nonce_field('matrix_save_hospital'); ?>
                <input type="hidden" name="hospital_id" value="<?php echo intval($id); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="matrix-hospital-name"><?php _e('Hospital Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="matrix-hospital-name" name="name"
                                   value="<?php echo esc_attr($name); ?>" maxlength="200"
                                   class="regular-text" required>
                            <p class="description"><?php _e('How the hospital appears in the member-facing dropdown.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="matrix-hospital-state"><?php _e('State', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                        </th>
                        <td>
                            <select id="matrix-hospital-state" name="state" required>
                                <option value=""><?php esc_html_e('- Select State -', 'matrix-mlm'); ?></option>
                                <?php foreach (self::nigerian_states() as $st): ?>
                                    <option value="<?php echo esc_attr($st); ?>" <?php selected($state, $st); ?>>
                                        <?php echo esc_html($st); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('The Nigerian state the hospital is in. Members who choose this state on the form will see this hospital.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="matrix-hospital-address"><?php _e('Address', 'matrix-mlm'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="matrix-hospital-address" name="address"
                                   value="<?php echo esc_attr($address); ?>" maxlength="500"
                                   class="large-text"
                                   placeholder="<?php esc_attr_e('e.g. 12 Idejo Street, Victoria Island, Lagos', 'matrix-mlm'); ?>">
                            <p class="description"><?php _e('Optional. Surfaced in the admin triage view; not shown on the member dropdown.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="matrix-hospital-notes"><?php _e('Internal notes', 'matrix-mlm'); ?></label>
                        </th>
                        <td>
                            <textarea id="matrix-hospital-notes" name="notes" rows="3"
                                      class="large-text"><?php echo esc_textarea($notes); ?></textarea>
                            <p class="description"><?php _e('Optional. Visible to admins only.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="matrix-hospital-order"><?php _e('Display Order', 'matrix-mlm'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="matrix-hospital-order" name="display_order"
                                   value="<?php echo esc_attr($display_order); ?>" min="0" max="9999"
                                   class="small-text">
                            <p class="description"><?php _e('Within the same state, lower numbers appear higher in the dropdown.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="matrix-hospital-status"><?php _e('Status', 'matrix-mlm'); ?></label>
                        </th>
                        <td>
                            <select id="matrix-hospital-status" name="status">
                                <?php foreach (self::STATUSES as $s): ?>
                                    <option value="<?php echo esc_attr($s); ?>" <?php selected($status, $s); ?>>
                                        <?php echo esc_html(ucfirst($s)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Inactive hospitals are hidden from the member-facing dropdown but kept on existing applications.', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="save_hospital" class="button button-primary">
                        <?php echo $is_edit
                            ? esc_html__('Save Changes', 'matrix-mlm')
                            : esc_html__('Add Hospital', 'matrix-mlm'); ?>
                    </button>
                    <a href="<?php echo esc_url($back_url); ?>" class="button"><?php _e('Cancel', 'matrix-mlm'); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Persist the add/edit form. UPSERT semantics — id=0 inserts,
     * id>0 updates the existing row. Validation rejects unknown
     * states (whitelist) and over-length names so the dropdown
     * never has surprise content.
     */
    private function save_hospital() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_hospitals';

        $id            = isset($_POST['hospital_id']) ? intval($_POST['hospital_id']) : 0;
        $name          = isset($_POST['name']) ? trim(sanitize_text_field(wp_unslash($_POST['name']))) : '';
        $state         = isset($_POST['state']) ? trim(sanitize_text_field(wp_unslash($_POST['state']))) : '';
        $address       = isset($_POST['address']) ? trim(sanitize_text_field(wp_unslash($_POST['address']))) : '';
        $notes         = isset($_POST['notes']) ? trim(sanitize_textarea_field(wp_unslash($_POST['notes']))) : '';
        $display_order = isset($_POST['display_order']) ? max(0, min(9999, (int) $_POST['display_order'])) : 0;
        $status        = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'active';

        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 200) {
            self::admin_notice('error', __('Hospital name must be between 2 and 200 characters.', 'matrix-mlm'));
            return;
        }
        if (!in_array($state, self::nigerian_states(), true)) {
            self::admin_notice('error', __('Choose a valid Nigerian state.', 'matrix-mlm'));
            return;
        }
        if (mb_strlen($address) > 500) {
            self::admin_notice('error', __('Address is too long (max 500 characters).', 'matrix-mlm'));
            return;
        }
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'active';
        }

        $payload = [
            'name'          => $name,
            'state'         => $state,
            'address'       => $address !== '' ? $address : null,
            'notes'         => $notes !== '' ? $notes : null,
            'display_order' => $display_order,
            'status'        => $status,
            'updated_at'    => current_time('mysql'),
        ];

        if ($id > 0) {
            $result = $wpdb->update($table, $payload, ['id' => $id]);
            if ($result === false) {
                self::admin_notice('error', __('Could not update hospital.', 'matrix-mlm') . ' ' . esc_html($wpdb->last_error));
                return;
            }
            self::admin_notice('success', __('Hospital updated.', 'matrix-mlm'));
            return;
        }

        $payload['created_at'] = current_time('mysql');
        $result = $wpdb->insert($table, $payload);
        if ($result === false) {
            self::admin_notice('error', __('Could not add hospital.', 'matrix-mlm') . ' ' . esc_html($wpdb->last_error));
            return;
        }
        self::admin_notice('success', __('Hospital added.', 'matrix-mlm'));
    }

    /**
     * Hard delete. Hospital rows aren't FK-linked from
     * matrix_healthcare_applications (we store hospital_id without
     * a constraint, plus a snapshotted hospital name in
     * preferred_hospital), so deleting a hospital does not corrupt
     * historical applications — the snapshot survives. We do warn
     * the operator at the click via JS confirm.
     */
    private function delete_hospital($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_hospitals';

        if ($id <= 0) {
            self::admin_notice('error', __('Invalid hospital ID.', 'matrix-mlm'));
            return;
        }
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id = %d",
            $id
        ));
        if ($existing === 0) {
            self::admin_notice('error', __('Hospital not found.', 'matrix-mlm'));
            return;
        }

        $result = $wpdb->delete($table, ['id' => $id], ['%d']);
        if ($result === false) {
            self::admin_notice('error', __('Could not delete hospital.', 'matrix-mlm') . ' ' . esc_html($wpdb->last_error));
            return;
        }
        self::admin_notice('success', __('Hospital deleted.', 'matrix-mlm'));
    }

    /**
     * Per-state row counts for the filter dropdown labels.
     */
    private function counts_by_state() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_hospitals';
        $rows = $wpdb->get_results("SELECT state, COUNT(*) AS n FROM {$table} GROUP BY state");
        $out = [];
        foreach ((array) $rows as $r) {
            $out[(string) $r->state] = (int) $r->n;
        }
        return $out;
    }

    /**
     * Reuse the user-facing form's curated Nigerian states list as
     * the single source of truth so a state added or renamed there
     * propagates to this admin page automatically. Falls back to a
     * minimal stub if the user class is somehow unavailable on a
     * partial deploy.
     */
    private static function nigerian_states() {
        if (defined('Matrix_MLM_User_Healthcare::NIGERIAN_STATES')) {
            return Matrix_MLM_User_Healthcare::NIGERIAN_STATES;
        }
        return ['Lagos', 'FCT - Abuja'];
    }

    private static function table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_hospitals';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        )) > 0;
    }

    private static function admin_notice($type, $message) {
        $cls = $type === 'error' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>'
            . wp_kses_post($message)
            . '</p></div>';
    }
}
