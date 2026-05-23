<?php
/**
 * Admin — Healthcare (HMO) application triage
 *
 * Lists and manages rows in wp_matrix_healthcare_applications
 * submitted via Matrix_MLM_User_Healthcare. Sister page to CUG
 * Requests and Loan Applications: same routing, capability,
 * pagination, status filter chips, and inline-notice pattern.
 *
 * Adds one healthcare-specific knob to the triage sidebar — a
 * `policy_number` text input the operator stamps when approving
 * the row, so the user-facing "Healthcare Application Approved"
 * email can quote the issued policy ID.
 *
 * Capability: manage_matrix_mlm. Same gate as CUG/Loans — keeps
 * reviewer permissions consistent across all three application
 * surfaces. There is no dedicated manage_matrix_healthcare cap in
 * the activator and adding one would only affect fresh installs.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Healthcare {

    /** Whitelist of statuses an operator can transition a row to. */
    const STATUSES = ['pending', 'under_review', 'approved', 'rejected', 'cancelled'];

    /** Statuses that fire a user-facing email notification on save. */
    const NOTIFIABLE_STATUSES = ['approved', 'rejected', 'cancelled'];

    public function render() {
        if (!current_user_can('manage_matrix_mlm')) {
            wp_die(__('You do not have permission to manage healthcare applications.', 'matrix-mlm'));
        }

        if (isset($_POST['matrix_healthcare_update']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'matrix_healthcare_update')) {
            $this->handle_update();
        }

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        if ($action === 'view' && isset($_GET['id'])) {
            $this->render_detail(intval($_GET['id']));
            return;
        }
        $this->render_list();
    }

    private function render_list() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_healthcare_applications';

        if (!self::table_exists()) {
            ?>
            <div class="wrap matrix-admin-wrap">
                <h1><?php _e('Healthcare Applications', 'matrix-mlm'); ?></h1>
                <div class="notice notice-info">
                    <p><?php _e('Healthcare application storage is being initialised. Please reload this page in a moment.', 'matrix-mlm'); ?></p>
                </div>
            </div>
            <?php
            return;
        }

        $filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        if ($filter !== 'all' && !in_array($filter, self::STATUSES, true)) {
            $filter = 'all';
        }

        $counts = $this->get_status_counts();

        $per_page = 25;
        $paged    = max(1, isset($_GET['paged']) ? intval($_GET['paged']) : 1);
        $offset   = ($paged - 1) * $per_page;

        if ($filter === 'all') {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $rows  = $wpdb->get_results($wpdb->prepare(
                "SELECT r.*, u.user_login, u.user_email
                   FROM {$table} r
                   LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
                  ORDER BY r.created_at DESC
                  LIMIT %d OFFSET %d",
                $per_page, $offset
            ));
        } else {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                $filter
            ));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT r.*, u.user_login, u.user_email
                   FROM {$table} r
                   LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
                  WHERE r.status = %s
                  ORDER BY r.created_at DESC
                  LIMIT %d OFFSET %d",
                $filter, $per_page, $offset
            ));
        }
        $total_pages = max(1, (int) ceil($total / $per_page));

        $base_url = admin_url('admin.php?page=matrix-mlm-healthcare');
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Healthcare Applications', 'matrix-mlm'); ?></h1>
            <p class="description">
                <?php _e('Member applications for the HMO healthcare benefit, submitted from the dashboard\'s Benefits tab.', 'matrix-mlm'); ?>
            </p>

            <ul class="subsubsub">
                <?php
                $links = [
                    'all'          => __('All', 'matrix-mlm'),
                    'pending'      => __('Pending', 'matrix-mlm'),
                    'under_review' => __('Under Review', 'matrix-mlm'),
                    'approved'     => __('Approved', 'matrix-mlm'),
                    'rejected'     => __('Rejected', 'matrix-mlm'),
                    'cancelled'    => __('Cancelled', 'matrix-mlm'),
                ];
                $i = 0;
                foreach ($links as $key => $label):
                    $url    = $key === 'all' ? $base_url : add_query_arg('status', $key, $base_url);
                    $count  = $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0);
                    $active = $filter === $key ? ' class="current"' : '';
                    $sep    = (++$i < count($links)) ? ' | ' : '';
                ?>
                    <li>
                        <a href="<?php echo esc_url($url); ?>"<?php echo $active; ?>>
                            <?php echo esc_html($label); ?>
                            <span class="count">(<?php echo intval($count); ?>)</span>
                        </a><?php echo $sep; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th style="width:60px;"><?php _e('ID', 'matrix-mlm'); ?></th>
                        <th><?php _e('User', 'matrix-mlm'); ?></th>
                        <th><?php _e('Applicant', 'matrix-mlm'); ?></th>
                        <th style="width:100px;"><?php _e('Type', 'matrix-mlm'); ?></th>
                        <th style="width:130px;"><?php _e('Hospital State', 'matrix-mlm'); ?></th>
                        <th><?php _e('Hospital', 'matrix-mlm'); ?></th>
                        <th style="width:120px;"><?php _e('Status', 'matrix-mlm'); ?></th>
                        <th style="width:160px;"><?php _e('Submitted', 'matrix-mlm'); ?></th>
                        <th style="width:90px;"><?php _e('Actions', 'matrix-mlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="9" style="padding:24px;text-align:center;color:#6b7280;">
                                <?php
                                if ($filter === 'all') {
                                    _e('No healthcare applications have been submitted yet.', 'matrix-mlm');
                                } else {
                                    /* translators: %s: status label */
                                    printf(esc_html__('No %s applications.', 'matrix-mlm'), esc_html($links[$filter] ?? $filter));
                                }
                                ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row):
                            $view_url = add_query_arg(['action' => 'view', 'id' => $row->id], $base_url);
                            $name     = trim((string) $row->first_name . ' ' . (string) $row->last_name);
                            $type     = isset($row->applicant_type) && $row->applicant_type !== ''
                                ? (string) $row->applicant_type
                                : 'adult';
                            $hospital = (string) ($row->preferred_hospital ?? '');
                            $hstate   = (string) ($row->hospital_state ?? '');
                        ?>
                        <tr>
                            <td><?php echo intval($row->id); ?></td>
                            <td>
                                <?php if ($row->user_login): ?>
                                    <strong><?php echo esc_html($row->user_login); ?></strong><br>
                                    <span style="font-size:11px;color:#6b7280;"><?php echo esc_html($row->user_email); ?></span>
                                <?php else: ?>
                                    <em style="color:#9ca3af;"><?php
                                        /* translators: %d: user id */
                                        printf(esc_html__('user #%d (deleted)', 'matrix-mlm'), intval($row->user_id));
                                    ?></em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($name); ?></td>
                            <td>
                                <span class="matrix-badge matrix-badge-<?php echo esc_attr($type); ?>">
                                    <?php echo esc_html(self::format_enum($type)); ?>
                                </span>
                            </td>
                            <td><?php echo $hstate !== '' ? esc_html($hstate) : '<em style="color:#9ca3af;">—</em>'; ?></td>
                            <td><?php echo $hospital !== '' ? esc_html($hospital) : '<em style="color:#9ca3af;">—</em>'; ?></td>
                            <td>
                                <span class="matrix-badge matrix-badge-<?php echo esc_attr($row->status); ?>">
                                    <?php echo esc_html(self::format_enum($row->status)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(self::format_datetime($row->created_at)); ?></td>
                            <td>
                                <a href="<?php echo esc_url($view_url); ?>" class="button button-small">
                                    <?php _e('View', 'matrix-mlm'); ?>
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
                        if ($filter !== 'all') {
                            $pagination_args['status'] = $filter;
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

    private function render_detail($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_healthcare_applications';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, u.user_login, u.user_email, u.display_name
               FROM {$table} r
               LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
              WHERE r.id = %d",
            $id
        ));

        $back_url = admin_url('admin.php?page=matrix-mlm-healthcare');

        if (!$row) {
            ?>
            <div class="wrap matrix-admin-wrap">
                <h1><?php _e('Healthcare Application', 'matrix-mlm'); ?></h1>
                <div class="notice notice-error">
                    <p><?php _e('Healthcare application not found. It may have been deleted.', 'matrix-mlm'); ?></p>
                </div>
                <p><a href="<?php echo esc_url($back_url); ?>" class="button">&larr; <?php _e('Back to list', 'matrix-mlm'); ?></a></p>
            </div>
            <?php
            return;
        }

        $plans = [];
        if (class_exists('Matrix_MLM_User')) {
            $plans = Matrix_MLM_User::get_active_plans(intval($row->user_id));
        }
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1>
                <?php
                /* translators: %d: application id */
                printf(esc_html__('Healthcare Application #%d', 'matrix-mlm'), intval($row->id));
                ?>
                <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">
                    <?php _e('Back to list', 'matrix-mlm'); ?>
                </a>
            </h1>

            <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-top:16px;align-items:start;">
                <div>
                    <?php
                    self::render_applicant_type_card($row);
                    self::render_personal_card($row);
                    if (self::has_parent_info($row)) {
                        self::render_parent_card($row);
                    }
                    self::render_hospital_card($row);
                    if (self::has_identification($row)) {
                        self::render_identification_card($row);
                    }
                    if (self::has_plan_info($row)) {
                        self::render_plan_card($row);
                    }
                    if (self::has_medical_info($row)) {
                        self::render_medical_card($row);
                    }
                    if (self::has_nok_info($row)) {
                        self::render_nok_card($row);
                    }
                    if (self::has_documents($row)) {
                        self::render_documents_card($row);
                    }
                    ?>
                </div>

                <div class="matrix-admin-card">
                    <h2 style="margin-top:0;"><?php _e('Triage', 'matrix-mlm'); ?></h2>

                    <p>
                        <?php _e('Member:', 'matrix-mlm'); ?><br>
                        <?php if ($row->user_login): ?>
                            <strong><?php echo esc_html($row->display_name ?: $row->user_login); ?></strong><br>
                            <code style="font-size:11px;"><?php echo esc_html($row->user_login); ?></code>
                            &mdash; <?php echo esc_html($row->user_email); ?>
                        <?php else: ?>
                            <em style="color:#9ca3af;"><?php
                                /* translators: %d: user id */
                                printf(esc_html__('user #%d (deleted)', 'matrix-mlm'), intval($row->user_id));
                            ?></em>
                        <?php endif; ?>
                    </p>

                    <p>
                        <?php _e('Active plans:', 'matrix-mlm'); ?><br>
                        <?php if (!empty($plans)): ?>
                            <?php foreach ($plans as $plan): ?>
                                <span class="matrix-badge matrix-badge-active" style="margin-right:6px;"><?php
                                    echo esc_html($plan->name ?? sprintf('plan #%d', intval($plan->id ?? 0)));
                                ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <em style="color:#b91c1c;"><?php _e('No active plan — eligibility may have lapsed.', 'matrix-mlm'); ?></em>
                        <?php endif; ?>
                    </p>

                    <p>
                        <?php _e('Current status:', 'matrix-mlm'); ?>
                        <span class="matrix-badge matrix-badge-<?php echo esc_attr($row->status); ?>">
                            <?php echo esc_html(self::format_enum($row->status)); ?>
                        </span>
                    </p>

                    <p>
                        <?php _e('Submitted:', 'matrix-mlm'); ?>
                        <?php echo esc_html(self::format_datetime($row->created_at)); ?><br>
                        <?php _e('Last updated:', 'matrix-mlm'); ?>
                        <?php echo esc_html(self::format_datetime($row->updated_at)); ?>
                    </p>

                    <hr style="margin:16px 0;border:0;border-top:1px solid #e5e7eb;">

                    <form method="post" action="<?php echo esc_url($back_url); ?>">
                        <?php wp_nonce_field('matrix_healthcare_update'); ?>
                        <input type="hidden" name="action" value="view">
                        <input type="hidden" name="id" value="<?php echo intval($row->id); ?>">
                        <input type="hidden" name="healthcare_application_id" value="<?php echo intval($row->id); ?>">

                        <p>
                            <label for="healthcare_status" style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php _e('Change status to', 'matrix-mlm'); ?>
                            </label>
                            <select id="healthcare_status" name="status" style="width:100%;">
                                <?php foreach (self::STATUSES as $s): ?>
                                    <option value="<?php echo esc_attr($s); ?>" <?php selected($row->status, $s); ?>>
                                        <?php echo esc_html(self::format_enum($s)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>

                        <p>
                            <label for="healthcare_policy_number" style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php _e('Policy Number', 'matrix-mlm'); ?>
                            </label>
                            <input type="text" id="healthcare_policy_number" name="policy_number"
                                   value="<?php echo esc_attr((string) $row->policy_number); ?>"
                                   maxlength="50" style="width:100%;"
                                   placeholder="<?php esc_attr_e('e.g. HMO-2026-00012', 'matrix-mlm'); ?>">
                            <span style="font-size:12px;color:#6b7280;">
                                <?php _e('Stamp here when issuing the policy. Quoted in the approval email.', 'matrix-mlm'); ?>
                            </span>
                        </p>

                        <p>
                            <label for="healthcare_admin_notes" style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php _e('Internal notes', 'matrix-mlm'); ?>
                            </label>
                            <textarea id="healthcare_admin_notes" name="admin_notes" rows="6"
                                      style="width:100%;"
                                      placeholder="<?php esc_attr_e('Visible to admins only.', 'matrix-mlm'); ?>"><?php
                                echo esc_textarea($row->admin_notes ?? '');
                            ?></textarea>
                        </p>

                        <p style="font-size:12px;color:#6b7280;margin:0 0 12px;">
                            <?php _e('Approving, rejecting, or cancelling will email the applicant. Pending and Under Review do not.', 'matrix-mlm'); ?>
                        </p>

                        <p>
                            <button type="submit" name="matrix_healthcare_update" class="button button-primary">
                                <?php _e('Save changes', 'matrix-mlm'); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_personal_card($row) {
        $type = isset($row->applicant_type) && $row->applicant_type !== ''
            ? (string) $row->applicant_type
            : 'adult';
        $is_dependant = $type === 'dependant';
        ?>
        <div class="matrix-admin-card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?php
                echo $is_dependant
                    ? esc_html__("Child's Information", 'matrix-mlm')
                    : esc_html__('Personal Information', 'matrix-mlm');
            ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><?php echo $is_dependant ? esc_html__('Child First Name', 'matrix-mlm') : esc_html__('First Name', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->first_name); ?></td></tr>
                <tr><th><?php echo $is_dependant ? esc_html__('Child Last Name', 'matrix-mlm') : esc_html__('Last Name', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->last_name); ?></td></tr>
                <?php if (!empty($row->middle_name)): ?>
                <tr><th><?php _e('Middle Name', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->middle_name); ?></td></tr>
                <?php endif; ?>
                <tr><th><?php _e('Email', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->email); ?></td></tr>
                <tr><th><?php _e('Phone', 'matrix-mlm'); ?></th><td><?php
                    echo !empty($row->phone)
                        ? '<code>' . esc_html($row->phone) . '</code>'
                        : '<em style="color:#9ca3af;">' . esc_html__('not provided', 'matrix-mlm') . '</em>';
                ?></td></tr>
                <tr><th><?php _e('WhatsApp', 'matrix-mlm'); ?></th><td><?php
                    echo !empty($row->whatsapp)
                        ? '<code>' . esc_html($row->whatsapp) . '</code>'
                        : '<em style="color:#9ca3af;">' . esc_html__('not provided', 'matrix-mlm') . '</em>';
                ?></td></tr>
                <tr><th><?php _e('Date of Birth', 'matrix-mlm'); ?></th><td><?php echo esc_html(self::format_date($row->date_of_birth)); ?></td></tr>
                <tr><th><?php echo $is_dependant ? esc_html__('Sex', 'matrix-mlm') : esc_html__('Gender', 'matrix-mlm'); ?></th><td><?php echo esc_html(self::format_enum($row->gender)); ?></td></tr>
                <?php if (!empty($row->marital_status)): ?>
                <tr><th><?php _e('Marital Status', 'matrix-mlm'); ?></th><td><?php echo esc_html(self::format_enum($row->marital_status)); ?></td></tr>
                <?php endif; ?>
                <tr><th><?php _e('Address', 'matrix-mlm'); ?></th><td><?php echo esc_html(self::format_address(
                    $row->address_line1, $row->address_line2, $row->city, $row->state, $row->zip_code, $row->country
                )); ?></td></tr>
            </table>
        </div>
        <?php
    }

    /**
     * Top-of-detail summary card showing whether the application
     * is for the member themselves (Adult) or for a dependant.
     * Always rendered — every row has an applicant_type, defaulting
     * to 'adult' for legacy pre-1.0.7 rows.
     */
    private static function render_applicant_type_card($row) {
        $type = isset($row->applicant_type) && $row->applicant_type !== ''
            ? (string) $row->applicant_type
            : 'adult';
        ?>
        <div class="matrix-admin-card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?php _e('Application Type', 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><?php _e('Applying as', 'matrix-mlm'); ?></th>
                    <td>
                        <span class="matrix-badge matrix-badge-<?php echo esc_attr($type); ?>">
                            <?php echo esc_html(self::format_enum($type)); ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Parent's Information card — only shown for dependant
     * applications and only when at least one parent field is
     * populated (dependant applications submitted via the new
     * 1.0.7 form will always have all four set).
     */
    private static function render_parent_card($row) {
        ?>
        <div class="matrix-admin-card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?php _e("Parent's Information", 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><?php _e('First Name', 'matrix-mlm'); ?></th><td><?php echo esc_html((string) ($row->parent_first_name ?? '')); ?></td></tr>
                <tr><th><?php _e('Last Name', 'matrix-mlm'); ?></th><td><?php echo esc_html((string) ($row->parent_last_name ?? '')); ?></td></tr>
                <tr><th><?php _e('Phone', 'matrix-mlm'); ?></th><td><?php
                    echo !empty($row->parent_phone)
                        ? '<code>' . esc_html($row->parent_phone) . '</code>'
                        : '<em style="color:#9ca3af;">' . esc_html__('not provided', 'matrix-mlm') . '</em>';
                ?></td></tr>
                <tr><th><?php _e('WhatsApp', 'matrix-mlm'); ?></th><td><?php
                    echo !empty($row->parent_whatsapp)
                        ? '<code>' . esc_html($row->parent_whatsapp) . '</code>'
                        : '<em style="color:#9ca3af;">' . esc_html__('not provided', 'matrix-mlm') . '</em>';
                ?></td></tr>
            </table>
        </div>
        <?php
    }

    /**
     * Hospital Selection card — shows the state and the hospital
     * name (snapshotted into preferred_hospital at submit-time so
     * a later admin-side delete of the hospital row doesn't blank
     * the application).
     */
    private static function render_hospital_card($row) {
        $hospital_state = (string) ($row->hospital_state ?? '');
        $hospital_name  = (string) ($row->preferred_hospital ?? '');
        $hospital_id    = (int) ($row->hospital_id ?? 0);
        ?>
        <div class="matrix-admin-card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?php _e('Hospital Selection', 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><?php _e('Hospital State', 'matrix-mlm'); ?></th><td><?php
                    echo $hospital_state !== ''
                        ? esc_html($hospital_state)
                        : '<em style="color:#9ca3af;">' . esc_html__('not provided', 'matrix-mlm') . '</em>';
                ?></td></tr>
                <tr><th><?php _e('Hospital of Choice', 'matrix-mlm'); ?></th><td><?php
                    if ($hospital_name !== '') {
                        echo esc_html($hospital_name);
                        if ($hospital_id > 0) {
                            echo ' <span style="color:#6b7280;font-size:11px;">(#' . intval($hospital_id) . ')</span>';
                        }
                    } else {
                        echo '<em style="color:#9ca3af;">' . esc_html__('not provided', 'matrix-mlm') . '</em>';
                    }
                ?></td></tr>
                <?php if (!empty($row->policy_number)): ?>
                <tr><th><?php _e('Policy Number', 'matrix-mlm'); ?></th><td><code><?php echo esc_html($row->policy_number); ?></code></td></tr>
                <?php endif; ?>
            </table>
        </div>
        <?php
    }

    // ============================================================
    // Conditional-render guards for legacy cards. Pre-1.0.7 rows
    // had a wider form (medical profile, NOK, plan tiers, document
    // uploads) — those columns are still on the table so historical
    // applications continue to render correctly, but we hide the
    // empty cards on rows submitted by the new Adult/Dependant form.
    // ============================================================

    private static function has_parent_info($row) {
        return !empty($row->parent_first_name)
            || !empty($row->parent_last_name)
            || !empty($row->parent_phone)
            || !empty($row->parent_whatsapp);
    }

    private static function has_identification($row) {
        return !empty($row->nin) || !empty($row->occupation);
    }

    private static function has_plan_info($row) {
        return !empty($row->plan_tier)
            || !empty($row->coverage_type)
            || (int) ($row->dependants_count ?? 0) > 0;
    }

    private static function has_medical_info($row) {
        $blood = (string) ($row->blood_group ?? 'unknown');
        $geno  = (string) ($row->genotype ?? 'unknown');
        return ($blood !== '' && $blood !== 'unknown')
            || ($geno !== '' && $geno !== 'unknown')
            || !empty($row->height_cm)
            || !empty($row->weight_kg)
            || !empty($row->pre_existing_conditions)
            || !empty($row->allergies)
            || !empty($row->current_medications)
            || (int) ($row->is_smoker ?? 0) === 1
            || (int) ($row->is_pregnant ?? 0) === 1;
    }

    private static function has_nok_info($row) {
        return !empty($row->nok_name) || !empty($row->nok_relationship) || !empty($row->nok_phone);
    }

    private static function has_documents($row) {
        return !empty($row->passport_photo_url)
            || !empty($row->nin_slip_url)
            || !empty($row->utility_bill_url)
            || !empty($row->medical_history_url);
    }

    private static function render_identification_card($row) {
        ?>
        <div class="matrix-admin-card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?php _e('Identification', 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><?php _e('NIN', 'matrix-mlm'); ?></th><td><code><?php echo esc_html($row->nin); ?></code></td></tr>
                <tr><th><?php _e('Occupation', 'matrix-mlm'); ?></th><td><?php echo $row->occupation ? esc_html($row->occupation) : '<em style="color:#9ca3af;">' . esc_html__('not provided', 'matrix-mlm') . '</em>'; ?></td></tr>
            </table>
        </div>
        <?php
    }

    private static function render_plan_card($row) {
        ?>
        <div class="matrix-admin-card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?php _e('Plan &amp; Coverage', 'matrix-mlm'); ?> <span style="font-size:11px;color:#6b7280;font-weight:400;">(<?php esc_html_e('legacy', 'matrix-mlm'); ?>)</span></h2>
            <table class="form-table" role="presentation">
                <?php if (!empty($row->plan_tier)): ?>
                <tr><th><?php _e('Plan Tier', 'matrix-mlm'); ?></th><td><strong><?php echo esc_html(self::format_enum($row->plan_tier)); ?></strong></td></tr>
                <?php endif; ?>
                <?php if (!empty($row->coverage_type)): ?>
                <tr><th><?php _e('Coverage Type', 'matrix-mlm'); ?></th><td><?php echo esc_html(self::format_enum($row->coverage_type)); ?></td></tr>
                <?php endif; ?>
                <?php if ((int) ($row->dependants_count ?? 0) > 0): ?>
                <tr><th><?php _e('Dependants', 'matrix-mlm'); ?></th><td><?php echo intval($row->dependants_count); ?></td></tr>
                <?php endif; ?>
            </table>
            <p style="margin:8px 0 0;font-size:12px;color:#6b7280;">
                <?php _e('These fields were collected by the pre-1.0.7 healthcare form and are kept here so historical applications still display in full.', 'matrix-mlm'); ?>
            </p>
        </div>
        <?php
    }

    private static function render_medical_card($row) {
        ?>
        <div class="matrix-admin-card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?php _e('Medical Profile', 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><?php _e('Blood Group', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->blood_group); ?></td></tr>
                <tr><th><?php _e('Genotype', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->genotype); ?></td></tr>
                <tr><th><?php _e('Height', 'matrix-mlm'); ?></th><td><?php echo $row->height_cm ? esc_html($row->height_cm . ' cm') : '<em style="color:#9ca3af;">—</em>'; ?></td></tr>
                <tr><th><?php _e('Weight', 'matrix-mlm'); ?></th><td><?php echo $row->weight_kg ? esc_html($row->weight_kg . ' kg') : '<em style="color:#9ca3af;">—</em>'; ?></td></tr>
                <tr><th><?php _e('Pre-existing Conditions', 'matrix-mlm'); ?></th><td><?php echo $row->pre_existing_conditions ? nl2br(esc_html($row->pre_existing_conditions)) : '<em style="color:#9ca3af;">' . esc_html__('none reported', 'matrix-mlm') . '</em>'; ?></td></tr>
                <tr><th><?php _e('Allergies', 'matrix-mlm'); ?></th><td><?php echo $row->allergies ? nl2br(esc_html($row->allergies)) : '<em style="color:#9ca3af;">' . esc_html__('none reported', 'matrix-mlm') . '</em>'; ?></td></tr>
                <tr><th><?php _e('Current Medications', 'matrix-mlm'); ?></th><td><?php echo $row->current_medications ? nl2br(esc_html($row->current_medications)) : '<em style="color:#9ca3af;">' . esc_html__('none reported', 'matrix-mlm') . '</em>'; ?></td></tr>
                <tr><th><?php _e('Smoker', 'matrix-mlm'); ?></th><td><?php echo $row->is_smoker ? esc_html__('Yes', 'matrix-mlm') : esc_html__('No', 'matrix-mlm'); ?></td></tr>
                <tr><th><?php _e('Pregnant', 'matrix-mlm'); ?></th><td><?php echo $row->is_pregnant ? esc_html__('Yes', 'matrix-mlm') : esc_html__('No', 'matrix-mlm'); ?></td></tr>
            </table>
        </div>
        <?php
    }

    private static function render_nok_card($row) {
        ?>
        <div class="matrix-admin-card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?php _e('Next of Kin', 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><?php _e('Name', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->nok_name); ?></td></tr>
                <tr><th><?php _e('Relationship', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->nok_relationship); ?></td></tr>
                <tr><th><?php _e('Phone', 'matrix-mlm'); ?></th><td><code><?php echo esc_html($row->nok_phone); ?></code></td></tr>
            </table>
        </div>
        <?php
    }

    private static function render_documents_card($row) {
        $docs = [
            'passport_photo_url'  => __('Passport Photo', 'matrix-mlm'),
            'nin_slip_url'        => __('NIN Slip / Card', 'matrix-mlm'),
            'utility_bill_url'    => __('Utility Bill', 'matrix-mlm'),
            'medical_history_url' => __('Medical History', 'matrix-mlm'),
        ];
        ?>
        <div class="matrix-admin-card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?php _e('Documents', 'matrix-mlm'); ?></h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">
                <?php foreach ($docs as $col => $label):
                    $url = isset($row->{$col}) ? (string) $row->{$col} : '';
                ?>
                    <div style="border:1px solid #e5e7eb;border-radius:6px;padding:10px;background:#fafafa;">
                        <div style="font-weight:600;font-size:12px;color:#374151;margin-bottom:6px;">
                            <?php echo esc_html($label); ?>
                        </div>
                        <?php if ($url !== ''):
                            $is_image = self::is_image_url($url);
                        ?>
                            <?php if ($is_image): ?>
                                <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer">
                                    <img src="<?php echo esc_url($url); ?>" alt="<?php echo esc_attr($label); ?>"
                                         style="width:100%;max-height:120px;object-fit:cover;border-radius:4px;display:block;">
                                </a>
                                <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"
                                   style="font-size:11px;display:inline-block;margin-top:6px;">
                                    <?php _e('Open full size', 'matrix-mlm'); ?> &rarr;
                                </a>
                            <?php else: ?>
                                <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"
                                   class="button button-small" style="display:inline-block;">
                                    <?php _e('View document', 'matrix-mlm'); ?>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <em style="color:#9ca3af;font-size:12px;"><?php _e('not uploaded', 'matrix-mlm'); ?></em>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function handle_update() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_healthcare_applications';

        $id     = isset($_POST['healthcare_application_id']) ? intval($_POST['healthcare_application_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $notes  = isset($_POST['admin_notes']) ? sanitize_textarea_field(wp_unslash($_POST['admin_notes'])) : '';
        $policy = isset($_POST['policy_number']) ? sanitize_text_field(wp_unslash($_POST['policy_number'])) : '';

        if ($id <= 0) {
            self::admin_notice('error', __('Invalid application ID.', 'matrix-mlm'));
            return;
        }
        if (!in_array($status, self::STATUSES, true)) {
            self::admin_notice('error', __('Invalid status value.', 'matrix-mlm'));
            return;
        }
        if (mb_strlen($policy) > 50) {
            self::admin_notice('error', __('Policy number is too long (max 50 characters).', 'matrix-mlm'));
            return;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, status, policy_number FROM {$table} WHERE id = %d",
            $id
        ));
        if (!$existing) {
            self::admin_notice('error', __('Healthcare application not found.', 'matrix-mlm'));
            return;
        }

        $update = [
            'status'        => $status,
            'admin_notes'   => $notes,
            'policy_number' => $policy !== '' ? $policy : null,
            'updated_at'    => current_time('mysql'),
        ];

        $result = $wpdb->update(
            $table,
            $update,
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            self::admin_notice('error', __('Could not update healthcare application.', 'matrix-mlm') . ' ' . esc_html($wpdb->last_error));
            return;
        }

        $status_changed = $status !== $existing->status;

        if ($status_changed
            && in_array($status, self::NOTIFIABLE_STATUSES, true)
            && class_exists('Matrix_MLM_Notifications')
            && method_exists('Matrix_MLM_Notifications', 'send_healthcare_notification')
        ) {
            Matrix_MLM_Notifications::send_healthcare_notification(
                intval($existing->user_id),
                $status,
                $policy
            );
        }

        if ($status_changed) {
            self::admin_notice(
                'success',
                sprintf(
                    /* translators: 1: old status, 2: new status */
                    __('Status changed from <strong>%1$s</strong> to <strong>%2$s</strong>.', 'matrix-mlm'),
                    esc_html(self::format_enum($existing->status)),
                    esc_html(self::format_enum($status))
                )
            );
        } else {
            self::admin_notice('success', __('Healthcare application saved.', 'matrix-mlm'));
        }
    }

    private function get_status_counts() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_healthcare_applications';
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status"
        );
        $out = [
            'pending'      => 0,
            'under_review' => 0,
            'approved'     => 0,
            'rejected'     => 0,
            'cancelled'    => 0,
        ];
        foreach ((array) $rows as $r) {
            $out[$r->status] = (int) $r->n;
        }
        return $out;
    }

    private static function table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_healthcare_applications';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        )) > 0;
    }

    private static function format_datetime($mysql_dt) {
        if (empty($mysql_dt) || $mysql_dt === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime($mysql_dt);
        if ($ts === false) {
            return (string) $mysql_dt;
        }
        $fmt = trim(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'));
        return date_i18n($fmt, $ts);
    }

    private static function format_date($mysql_date) {
        if (empty($mysql_date) || $mysql_date === '0000-00-00') {
            return '—';
        }
        $ts = strtotime($mysql_date);
        if ($ts === false) {
            return (string) $mysql_date;
        }
        return date_i18n(get_option('date_format', 'Y-m-d'), $ts);
    }

    private static function format_enum($value) {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        return ucwords(str_replace('_', ' ', $value));
    }

    private static function format_address($line1, $line2, $city, $state, $zip, $country) {
        $parts = array_filter(array_map('trim', [
            (string) $line1,
            (string) $line2,
            (string) $city,
            (string) $state,
            (string) $zip,
            (string) $country,
        ]), function ($p) {
            return $p !== '';
        });
        return implode(', ', $parts);
    }

    private static function is_image_url($url) {
        $ext = strtolower(pathinfo(parse_url((string) $url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }

    private static function admin_notice($type, $message) {
        $cls = $type === 'error' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>'
            . wp_kses_post($message)
            . '</p></div>';
    }
}
