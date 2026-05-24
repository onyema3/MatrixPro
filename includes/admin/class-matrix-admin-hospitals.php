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

        // CSV bulk import — runs before render so the import-results
        // notice appears at the top of the list page.
        if (isset($_POST['import_hospitals']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'matrix_import_hospitals')) {
            $this->handle_import();
        }

        // CSV template download — short-circuits with a file
        // response so operators have a starting point that already
        // has the right column headers and a couple of example rows.
        if (isset($_GET['download_template']) && $_GET['download_template'] === '1') {
            $this->stream_csv_template();
            return;
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

            <?php $this->render_import_panel(); ?>

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
                $ref = Matrix_MLM_DB_Error::log_and_token('hospitals.update', $wpdb->last_error);
                self::admin_notice('error', sprintf(
                    /* translators: %s: opaque support reference */
                    __('Could not update hospital. Reference: %s.', 'matrix-mlm'),
                    esc_html($ref)
                ));
                return;
            }
            self::admin_notice('success', __('Hospital updated.', 'matrix-mlm'));
            return;
        }

        $payload['created_at'] = current_time('mysql');
        $result = $wpdb->insert($table, $payload);
        if ($result === false) {
            $ref = Matrix_MLM_DB_Error::log_and_token('hospitals.insert', $wpdb->last_error);
            self::admin_notice('error', sprintf(
                /* translators: %s: opaque support reference */
                __('Could not add hospital. Reference: %s.', 'matrix-mlm'),
                esc_html($ref)
            ));
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
            $ref = Matrix_MLM_DB_Error::log_and_token('hospitals.delete', $wpdb->last_error);
            self::admin_notice('error', sprintf(
                /* translators: %s: opaque support reference */
                __('Could not delete hospital. Reference: %s.', 'matrix-mlm'),
                esc_html($ref)
            ));
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

    /**
     * Render the bulk-import panel — collapsed accordion with a
     * file upload, a quick how-to, and a CSV-template download.
     *
     * Hidden by default to keep the list page clean; clicking the
     * "Bulk Import" disclosure expands it. The form submits to the
     * same admin URL and is handled by handle_import() before any
     * output, so the result notice renders at the top of the
     * resulting page next to any other notices.
     */
    private function render_import_panel() {
        $template_url = wp_nonce_url(
            add_query_arg('download_template', '1', admin_url('admin.php?page=matrix-mlm-hospitals')),
            'matrix_hospital_template'
        );
        ?>
        <details style="margin:16px 0 0;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
            <summary style="padding:12px 14px;cursor:pointer;font-weight:600;color:#1f2937;list-style:none;">
                <span class="dashicons dashicons-upload" style="vertical-align:middle;margin-right:6px;color:#4f46e5;"></span>
                <?php _e('Bulk Import Hospitals (CSV)', 'matrix-mlm'); ?>
                <span style="float:right;font-weight:400;font-size:12px;color:#6b7280;">
                    <?php _e('Click to expand', 'matrix-mlm'); ?>
                </span>
            </summary>
            <div style="padding:14px 16px;border-top:1px solid #e5e7eb;background:#ffffff;border-radius:0 0 6px 6px;">
                <p style="margin:0 0 10px;font-size:13px;color:#374151;line-height:1.5;">
                    <?php _e('Upload a CSV file to add many hospitals at once. The first row must be a header row with column names — extra columns are ignored, missing optional columns are filled in with sensible defaults.', 'matrix-mlm'); ?>
                </p>

                <p style="margin:0 0 10px;font-size:13px;color:#374151;">
                    <strong><?php _e('Required columns:', 'matrix-mlm'); ?></strong>
                    <code>name</code>, <code>state</code><br>
                    <strong><?php _e('Optional columns:', 'matrix-mlm'); ?></strong>
                    <code>address</code>, <code>notes</code>, <code>display_order</code>, <code>status</code>
                </p>

                <p style="margin:0 0 12px;padding:8px 12px;background:#fffbeb;border-left:3px solid #f59e0b;border-radius:4px;font-size:12px;color:#78350f;line-height:1.5;">
                    <strong><?php _e('Excel users:', 'matrix-mlm'); ?></strong>
                    <?php _e('Save your spreadsheet as CSV UTF-8 (Comma delimited) before uploading. .xls and .xlsx files are not supported directly because parsing them reliably requires a library that is not bundled with this plugin.', 'matrix-mlm'); ?>
                </p>

                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin.php?page=matrix-mlm-hospitals')); ?>"
                      style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <?php wp_nonce_field('matrix_import_hospitals'); ?>
                    <input type="file" name="hospitals_csv" accept=".csv,text/csv,text/plain" required>
                    <label style="font-size:13px;display:inline-flex;align-items:center;gap:6px;">
                        <input type="checkbox" name="update_existing" value="1">
                        <?php _e('Update rows where (name, state) already exists', 'matrix-mlm'); ?>
                    </label>
                    <button type="submit" name="import_hospitals" class="button button-primary">
                        <?php _e('Import CSV', 'matrix-mlm'); ?>
                    </button>
                    <a href="<?php echo esc_url($template_url); ?>" class="button">
                        <span class="dashicons dashicons-download" style="vertical-align:text-bottom;"></span>
                        <?php _e('Download template', 'matrix-mlm'); ?>
                    </a>
                </form>

                <p style="margin:10px 0 0;font-size:11px;color:#6b7280;">
                    <?php
                    /* translators: %s: max upload size in MB, server-defined */
                    printf(esc_html__('Max upload size: %s. Each row must include a non-empty name (2-200 chars) and a valid Nigerian state.', 'matrix-mlm'), esc_html(size_format(wp_max_upload_size())));
                    ?>
                </p>
            </div>
        </details>
        <?php
    }

    /**
     * Handle a CSV bulk-import POST. Reads the uploaded file,
     * parses each row by column name (so column order doesn't
     * matter), validates against the same rules as the Add/Edit
     * form, then inserts (or updates, when the operator opted in)
     * row-by-row in a single transaction-shaped loop. Renders an
     * admin notice with per-row results.
     *
     * The (name, state) tuple is treated as the natural key for
     * de-duplication. There is no DB-level UNIQUE on those columns
     * because we want to allow legitimate duplicates (e.g., a
     * hospital chain with separate branches sharing a name in two
     * states is fine, two listings of the exact same branch are
     * not). The operator-driven duplicate handling is therefore
     * the right level of strictness here: skip-by-default,
     * update-when-asked.
     */
    private function handle_import() {
        if (empty($_FILES['hospitals_csv']) || !isset($_FILES['hospitals_csv']['tmp_name'])) {
            self::admin_notice('error', __('No CSV file was uploaded. Pick a file and try again.', 'matrix-mlm'));
            return;
        }

        $file = $_FILES['hospitals_csv'];
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            self::admin_notice('error', __('Upload failed. Please try again or check your hosting upload limits.', 'matrix-mlm'));
            return;
        }

        $update_existing = !empty($_POST['update_existing']);
        $report = $this->import_hospitals_from_csv($file['tmp_name'], $update_existing);

        $this->render_import_results($report);
    }

    /**
     * Parse a CSV into rows keyed by header name. Returns an
     * { rows, errors } structure rather than throwing so the caller
     * can present a partial-success result to the operator.
     *
     * Handles UTF-8 BOM (Excel exports leave one) and accepts
     * either comma or tab as a delimiter (auto-detected from the
     * first line) so a TSV mistakenly named .csv still imports.
     */
    private function read_csv_file($path) {
        $errors = [];
        $rows   = [];

        $handle = @fopen($path, 'r');
        if (!$handle) {
            return ['rows' => [], 'errors' => [__('Could not read the uploaded file.', 'matrix-mlm')]];
        }

        // Strip a UTF-8 BOM if Excel left one — fgetcsv would
        // otherwise quote-include it in the first header column,
        // turning 'name' into "\xEF\xBB\xBFname" and breaking
        // the column-name lookup.
        $first_bytes = fread($handle, 3);
        if ($first_bytes !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Auto-detect delimiter: peek the first line and count
        // commas vs tabs, pick whichever is more frequent (with a
        // tie-break in favour of comma since the form labels say
        // "CSV" explicitly).
        $peek_pos = ftell($handle);
        $first_line = fgets($handle);
        fseek($handle, $peek_pos);
        $delim = ',';
        if ($first_line !== false && substr_count($first_line, "\t") > substr_count($first_line, ',')) {
            $delim = "\t";
        }

        $headers = fgetcsv($handle, 0, $delim, '"', '');
        if (!$headers) {
            fclose($handle);
            return ['rows' => [], 'errors' => [__('The file is empty or unreadable.', 'matrix-mlm')]];
        }
        $headers = array_map(function ($h) {
            return strtolower(trim((string) $h));
        }, $headers);

        $required = ['name', 'state'];
        foreach ($required as $col) {
            if (!in_array($col, $headers, true)) {
                fclose($handle);
                return [
                    'rows'   => [],
                    'errors' => [sprintf(
                        /* translators: %s: column name */
                        __('Missing required column: %s. The first row must contain a header with at least name and state columns.', 'matrix-mlm'),
                        $col
                    )],
                ];
            }
        }

        $line_no = 1;  // counting from the header row
        while (($cells = fgetcsv($handle, 0, $delim, '"', '')) !== false) {
            $line_no++;
            // Skip completely-empty lines silently — common at the
            // end of CSVs Excel exports.
            $non_empty = array_filter($cells, function ($c) {
                return trim((string) $c) !== '';
            });
            if (empty($non_empty)) {
                continue;
            }
            $row = [];
            foreach ($headers as $idx => $col) {
                $row[$col] = isset($cells[$idx]) ? (string) $cells[$idx] : '';
            }
            $row['__line__'] = $line_no;
            $rows[] = $row;
        }
        fclose($handle);

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Drive the import: parse, validate row-by-row, insert or
     * update. Returns a structured report the caller renders into
     * an admin notice.
     */
    private function import_hospitals_from_csv($path, $update_existing) {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_hospitals';

        $report = [
            'imported'        => 0,
            'updated'         => 0,
            'skipped'         => 0,  // duplicate, update_existing was off
            'errored'         => 0,
            'errors'          => [],  // ['line' => int, 'message' => str]
            'parse_errors'    => [],
        ];

        $parsed = $this->read_csv_file($path);
        if (!empty($parsed['errors'])) {
            $report['parse_errors'] = $parsed['errors'];
            return $report;
        }
        if (empty($parsed['rows'])) {
            $report['parse_errors'][] = __('No data rows found after the header.', 'matrix-mlm');
            return $report;
        }

        $valid_states = self::nigerian_states();
        $now          = current_time('mysql');

        foreach ($parsed['rows'] as $row) {
            $line = (int) $row['__line__'];
            $name = trim((string) ($row['name'] ?? ''));
            $state = trim((string) ($row['state'] ?? ''));

            if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 200) {
                $report['errored']++;
                $report['errors'][] = ['line' => $line, 'message' => sprintf(
                    /* translators: %s: hospital name as supplied */
                    __('Invalid name "%s" — must be 2-200 characters.', 'matrix-mlm'),
                    $name
                )];
                continue;
            }
            $state = $this->normalise_state_name($state, $valid_states);
            if ($state === null) {
                $report['errored']++;
                $report['errors'][] = ['line' => $line, 'message' => sprintf(
                    /* translators: %s: state value as supplied */
                    __('Unknown state "%s" — must match one of the 36 Nigerian states or "FCT - Abuja".', 'matrix-mlm'),
                    trim((string) ($row['state'] ?? ''))
                )];
                continue;
            }

            $address = trim((string) ($row['address'] ?? ''));
            if (mb_strlen($address) > 500) {
                $report['errored']++;
                $report['errors'][] = ['line' => $line, 'message' => __('Address is too long (max 500 characters).', 'matrix-mlm')];
                continue;
            }
            $notes = trim((string) ($row['notes'] ?? ''));
            $display_order = isset($row['display_order']) ? (int) $row['display_order'] : 0;
            $display_order = max(0, min(9999, $display_order));
            $status = strtolower(trim((string) ($row['status'] ?? 'active')));
            if (!in_array($status, self::STATUSES, true)) {
                $status = 'active';
            }

            $payload = [
                'name'          => sanitize_text_field($name),
                'state'         => $state,
                'address'       => $address !== '' ? sanitize_text_field($address) : null,
                'notes'         => $notes !== '' ? sanitize_textarea_field($notes) : null,
                'display_order' => $display_order,
                'status'        => $status,
                'updated_at'    => $now,
            ];

            // Natural-key dedupe on (name, state). Case-insensitive
            // because Excel exports are wildly inconsistent on
            // capitalisation ("Reddington Hospital" vs "REDDINGTON
            // HOSPITAL" vs "reddington hospital" should all be the
            // same row).
            $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE LOWER(name) = LOWER(%s) AND state = %s LIMIT 1",
                $payload['name'],
                $payload['state']
            ));

            if ($existing_id > 0) {
                if (!$update_existing) {
                    $report['skipped']++;
                    continue;
                }
                $result = $wpdb->update($table, $payload, ['id' => $existing_id]);
                if ($result === false) {
                    $report['errored']++;
                    $report['errors'][] = ['line' => $line, 'message' => sprintf(
                        /* translators: %s: hospital name */
                        __('Database update failed for "%s".', 'matrix-mlm'),
                        $payload['name']
                    )];
                    continue;
                }
                $report['updated']++;
                continue;
            }

            $payload['created_at'] = $now;
            $result = $wpdb->insert($table, $payload);
            if ($result === false) {
                $report['errored']++;
                $report['errors'][] = ['line' => $line, 'message' => sprintf(
                    /* translators: %s: hospital name */
                    __('Database insert failed for "%s".', 'matrix-mlm'),
                    $payload['name']
                )];
                continue;
            }
            $report['imported']++;
        }

        return $report;
    }

    /**
     * Tolerant state-name matcher. Accepts the canonical strings
     * from NIGERIAN_STATES exactly, and also forgives common minor
     * variants — "FCT", "Abuja", "FCT-Abuja" all resolve to
     * "FCT - Abuja"; case differences are ignored.
     *
     * Returns the canonical string or null when no match.
     */
    private function normalise_state_name($input, array $valid) {
        $needle = strtolower(trim((string) $input));
        if ($needle === '') {
            return null;
        }
        foreach ($valid as $candidate) {
            if (strtolower($candidate) === $needle) {
                return $candidate;
            }
        }
        // FCT aliases — these are the spellings Nigerian admin
        // forms most commonly use and that operators are most
        // likely to type.
        $fct_aliases = ['fct', 'abuja', 'fct-abuja', 'fct abuja', 'federal capital territory'];
        if (in_array($needle, $fct_aliases, true)) {
            return 'FCT - Abuja';
        }
        // "Akwa-Ibom" vs "Akwa Ibom" and similar dash/space typos.
        $deslashed = str_replace(['-', '_'], ' ', $needle);
        $deslashed = preg_replace('/\s+/', ' ', $deslashed);
        foreach ($valid as $candidate) {
            if (strtolower(str_replace(['-', '_'], ' ', $candidate)) === $deslashed) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Render the import report as an admin notice. Success/error
     * styling is decided by the per-row outcome counts: a fully
     * clean import is success, anything with errors is warning,
     * and a parse-time failure is a hard error with no row counts
     * (since we never got past the header).
     */
    private function render_import_results(array $report) {
        if (!empty($report['parse_errors'])) {
            $msg = '<strong>' . esc_html__('Import failed:', 'matrix-mlm') . '</strong> '
                 . esc_html(implode(' ', $report['parse_errors']));
            self::admin_notice('error', $msg);
            return;
        }

        $imported = (int) $report['imported'];
        $updated  = (int) $report['updated'];
        $skipped  = (int) $report['skipped'];
        $errored  = (int) $report['errored'];

        $type = $errored > 0 ? 'error' : 'success';
        $cls  = $type === 'error' ? 'notice-warning' : 'notice-success';

        $summary = sprintf(
            /* translators: 1: imported, 2: updated, 3: skipped duplicates, 4: errored */
            __('Import complete: <strong>%1$d</strong> added, <strong>%2$d</strong> updated, <strong>%3$d</strong> skipped (duplicates), <strong>%4$d</strong> failed.', 'matrix-mlm'),
            $imported, $updated, $skipped, $errored
        );

        echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible" style="padding:14px 16px;">';
        echo '<p style="margin:0 0 8px;">' . wp_kses_post($summary) . '</p>';

        if (!empty($report['errors'])) {
            echo '<details style="margin-top:8px;"><summary style="cursor:pointer;font-weight:600;color:#92400e;">'
                . sprintf(esc_html__('%d row(s) failed — click to expand', 'matrix-mlm'), count($report['errors']))
                . '</summary>';
            echo '<ul style="margin:8px 0 0 22px;color:#7f1d1d;font-size:13px;">';
            // Cap the displayed list so a truly broken file doesn't
            // explode the page; the operator can fix the first batch
            // and re-import to surface the rest.
            $shown = 0;
            $cap   = 50;
            foreach ($report['errors'] as $err) {
                if ($shown++ >= $cap) {
                    echo '<li><em>' . sprintf(
                        esc_html__('… and %d more (re-run after fixing these to see the rest).', 'matrix-mlm'),
                        count($report['errors']) - $cap
                    ) . '</em></li>';
                    break;
                }
                echo '<li>' . sprintf(
                    /* translators: 1: line number in source CSV, 2: error message */
                    esc_html__('Line %1$d: %2$s', 'matrix-mlm'),
                    intval($err['line']),
                    esc_html($err['message'])
                ) . '</li>';
            }
            echo '</ul></details>';
        }
        echo '</div>';
    }

    /**
     * Stream a CSV template back to the operator's browser. Three
     * example rows so they have a working starting point that
     * already satisfies the validator — copy the file, swap in
     * real hospital names, re-upload.
     *
     * Sent with a UTF-8 BOM so Excel opens it as UTF-8 instead of
     * mangling Naira/state-name diacritics into mojibake.
     */
    private function stream_csv_template() {
        if (!current_user_can('manage_matrix_mlm')) {
            wp_die(__('You do not have permission to download this template.', 'matrix-mlm'));
        }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'matrix_hospital_template')) {
            wp_die(__('Invalid or expired link. Reload the Hospitals page and click Download template again.', 'matrix-mlm'));
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="matrix-hospitals-template.csv"');

        // UTF-8 BOM for Excel compatibility.
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        fputcsv($out, ['name', 'state', 'address', 'notes', 'display_order', 'status'], ',', '"', '');
        fputcsv($out, [
            'Reddington Hospital',
            'Lagos',
            '12 Idejo Street, Victoria Island, Lagos',
            'Tier 1 partner',
            '10',
            'active',
        ], ',', '"', '');
        fputcsv($out, [
            'Lagoon Hospitals Ikeja',
            'Lagos',
            '17a Bola Tinubu Way, Ikeja',
            '',
            '20',
            'active',
        ], ',', '"', '');
        fputcsv($out, [
            'National Hospital Abuja',
            'FCT - Abuja',
            'Plot 132 Central Business District, Abuja',
            'HMO partner since 2024',
            '10',
            'active',
        ], ',', '"', '');
        fclose($out);
        exit;
    }

    private static function admin_notice($type, $message) {
        $cls = $type === 'error' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>'
            . wp_kses_post($message)
            . '</p></div>';
    }
}
