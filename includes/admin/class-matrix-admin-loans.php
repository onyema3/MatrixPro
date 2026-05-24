<?php
/**
 * Admin — Business loan application triage
 *
 * Lists and manages the rows in wp_matrix_loan_applications submitted
 * by members through the Benefits-tab "Apply for Loan" modal in
 * Matrix_MLM_User_Loan. Operators can filter by status, view full
 * details (40+ fields across four sections + eight uploaded
 * documents), change status, and attach internal notes.
 *
 * Routing mirrors Matrix_MLM_Admin_CUG (?action=view / matrix_loan_update),
 * which is the closest analogue: same UNIQUE-on-user_id table shape,
 * same admin_notes column, same five-value status enum (with
 * under_review extending the CUG four-value set). All form
 * submissions land back on the same admin URL with an inline notice
 * rather than redirecting — matches the rest of the Matrix MLM admin.
 *
 * Capability: manage_matrix_mlm. Same gate as the Benefits CRUD page
 * and the CUG triage page; there is no dedicated manage_matrix_loans
 * capability granted in the activator, so adding one here would only
 * affect fresh installs and silently lock existing operators out.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Loans {

    /** Whitelist of statuses an operator can transition a row to.
     *  Must mirror the ENUM column in matrix_loan_applications.
     *  under_review and cancelled extend the CUG status set. */
    const STATUSES = ['pending', 'under_review', 'approved', 'rejected', 'cancelled'];

    /** Sub-statuses that should fire an email notification on save.
     *  pending → no email (it's the initial state, the user already
     *  saw the in-form success message). under_review → no email (a
     *  transient triage state, not a decision the user needs to be
     *  paged about). The other three are user-visible decisions. */
    const NOTIFIABLE_STATUSES = ['approved', 'rejected', 'cancelled'];

    /**
     * Top-level entry point. Routes to the detail view or the list,
     * processing any submitted form first so the resulting page can
     * render its own success/error notice inline.
     */
    public function render() {
        if (!current_user_can('manage_matrix_mlm')) {
            wp_die(__('You do not have permission to manage loan applications.', 'matrix-mlm'));
        }

        // Process status update before render so the notice appears
        // above the page that follows. Capability check above gates
        // access; the nonce protects against CSRF.
        if (isset($_POST['matrix_loan_update']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'matrix_loan_update')) {
            $this->handle_update();
        }

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        if ($action === 'view' && isset($_GET['id'])) {
            $this->render_detail(intval($_GET['id']));
            return;
        }

        $this->render_list();
    }

    /**
     * Render the paginated list, optionally filtered by status.
     * 25 rows per page matches the WP-list-table convention used
     * elsewhere in this admin.
     */
    private function render_list() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_loan_applications';

        // Schema probe — mirrors the CUG admin: a fresh-install
        // pageload may run before maybe_upgrade() has had a chance
        // to create the table on its first pass.
        if (!self::table_exists()) {
            ?>
            <div class="wrap matrix-admin-wrap">
                <h1><?php _e('Loan Applications', 'matrix-mlm'); ?></h1>
                <div class="notice notice-info">
                    <p><?php _e('Loan application storage is being initialised. Please reload this page in a moment.', 'matrix-mlm'); ?></p>
                </div>
            </div>
            <?php
            return;
        }

        // Status filter. 'all' (or any unrecognised value) shows
        // everything; otherwise the value must be in STATUSES so a
        // tampered query string can't inject SQL via the WHERE
        // clause's parameter.
        $filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        if ($filter !== 'all' && !in_array($filter, self::STATUSES, true)) {
            $filter = 'all';
        }

        $counts = $this->get_status_counts();

        // Pagination.
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

        $base_url = admin_url('admin.php?page=matrix-mlm-loans');
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Loan Applications', 'matrix-mlm'); ?></h1>
            <p class="description">
                <?php _e('Member applications for the Liberty Hub business loan benefit, submitted from the dashboard\'s Benefits tab.', 'matrix-mlm'); ?>
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
                        <th style="width:130px;"><?php _e('Loan Amount', 'matrix-mlm'); ?></th>
                        <th style="width:110px;"><?php _e('Reason', 'matrix-mlm'); ?></th>
                        <th style="width:110px;"><?php _e('Repayment', 'matrix-mlm'); ?></th>
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
                                    _e('No loan applications have been submitted yet.', 'matrix-mlm');
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
                            <td>
                                <?php echo esc_html($name); ?>
                                <?php if (!empty($row->trade_name)): ?>
                                    <br><span style="font-size:11px;color:#6b7280;"><?php echo esc_html($row->trade_name); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo esc_html($currency . number_format((float) $row->loan_amount, 2)); ?></strong></td>
                            <td><?php echo esc_html(self::format_enum($row->loan_reason)); ?></td>
                            <td><?php echo esc_html(ucfirst((string) $row->repayment_plan)); ?></td>
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

    /**
     * Render the detail page for a single application, including the
     * status-change form. Linked from the list table's "View" action.
     *
     * Layout: 2fr/1fr two-column grid — main panel is the full
     * application broken into four sections matching the form
     * (Personal / Account / Project / Guarantor) + a Documents
     * panel for the eight upload URLs. Sidebar is the triage form.
     */
    private function render_detail($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_loan_applications';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, u.user_login, u.user_email, u.display_name
               FROM {$table} r
               LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
              WHERE r.id = %d",
            $id
        ));

        $back_url = admin_url('admin.php?page=matrix-mlm-loans');

        if (!$row) {
            ?>
            <div class="wrap matrix-admin-wrap">
                <h1><?php _e('Loan Application', 'matrix-mlm'); ?></h1>
                <div class="notice notice-error">
                    <p><?php _e('Loan application not found. It may have been deleted.', 'matrix-mlm'); ?></p>
                </div>
                <p><a href="<?php echo esc_url($back_url); ?>" class="button">&larr; <?php _e('Back to list', 'matrix-mlm'); ?></a></p>
            </div>
            <?php
            return;
        }

        // Pull active plans for the eligibility sanity-check, same
        // as the CUG detail page. Helps the operator decide whether
        // an applicant's Liberty Hub membership still qualifies.
        $plans = [];
        if (class_exists('Matrix_MLM_User')) {
            $plans = Matrix_MLM_User::get_active_plans(intval($row->user_id));
        }

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1>
                <?php
                /* translators: %d: application id */
                printf(esc_html__('Loan Application #%d', 'matrix-mlm'), intval($row->id));
                ?>
                <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">
                    <?php _e('Back to list', 'matrix-mlm'); ?>
                </a>
            </h1>

            <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-top:16px;align-items:start;">
                <div>
                    <?php
                    self::render_personal_card($row);
                    self::render_account_card($row);
                    self::render_project_card($row, $currency);
                    self::render_documents_card($row);
                    self::render_guarantor_card($row);
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
                        <?php wp_nonce_field('matrix_loan_update'); ?>
                        <input type="hidden" name="action" value="view">
                        <input type="hidden" name="id" value="<?php echo intval($row->id); ?>">
                        <input type="hidden" name="loan_application_id" value="<?php echo intval($row->id); ?>">

                        <p>
                            <label for="loan_status" style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php _e('Change status to', 'matrix-mlm'); ?>
                            </label>
                            <select id="loan_status" name="status" style="width:100%;">
                                <?php foreach (self::STATUSES as $s): ?>
                                    <option value="<?php echo esc_attr($s); ?>" <?php selected($row->status, $s); ?>>
                                        <?php echo esc_html(self::format_enum($s)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>

                        <p>
                            <label for="loan_admin_notes" style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php _e('Internal notes', 'matrix-mlm'); ?>
                            </label>
                            <textarea id="loan_admin_notes" name="admin_notes" rows="6"
                                      style="width:100%;"
                                      placeholder="<?php esc_attr_e('Visible to admins only.', 'matrix-mlm'); ?>"><?php
                                echo esc_textarea($row->admin_notes ?? '');
                            ?></textarea>
                        </p>

                        <p style="font-size:12px;color:#6b7280;margin:0 0 12px;">
                            <?php _e('Approving, rejecting, or cancelling will email the applicant. Pending and Under Review do not.', 'matrix-mlm'); ?>
                        </p>

                        <p>
                            <button type="submit" name="matrix_loan_update" class="button button-primary">
                                <?php _e('Save changes', 'matrix-mlm'); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Personal Information card. Mirrors the form's first section.
     */
    private static function render_personal_card($row) {
        ?>
        <div class="matrix-admin-card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?php _e('Personal Information', 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><?php _e('First Name', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->first_name); ?></td></tr>
                <tr><th><?php _e('Last Name', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->last_name); ?></td></tr>
                <tr><th><?php _e('Email', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->email); ?></td></tr>
                <tr><th><?php _e('Phone', 'matrix-mlm'); ?></th><td><code><?php echo esc_html($row->phone); ?></code></td></tr>
                <tr><th><?php _e('Address', 'matrix-mlm'); ?></th><td><?php echo esc_html(self::format_address(
                    $row->address_line1, $row->address_line2, $row->city, $row->state, $row->zip_code, $row->country
                )); ?></td></tr>
                <tr><th><?php _e('Date of Birth', 'matrix-mlm'); ?></th><td><?php echo esc_html(self::format_date($row->date_of_birth)); ?></td></tr>
                <tr><th><?php _e('Trade Name', 'matrix-mlm'); ?></th><td><?php echo $row->trade_name !== null && $row->trade_name !== ''
                    ? esc_html($row->trade_name)
                    : '<em style="color:#9ca3af;">' . esc_html__('not provided', 'matrix-mlm') . '</em>'; ?></td></tr>
            </table>
        </div>
        <?php
    }

    /**
     * Customer Account Details card.
     */
    private static function render_account_card($row) {
        ?>
        <div class="matrix-admin-card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?php _e('Customer Account Details', 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><?php _e('Bank', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->bank_name); ?></td></tr>
                <tr><th><?php _e('Account Number', 'matrix-mlm'); ?></th><td><code><?php echo esc_html($row->account_number); ?></code></td></tr>
                <tr><th><?php _e('Account Name', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->account_name); ?></td></tr>
                <tr><th><?php _e('BVN', 'matrix-mlm'); ?></th><td><code><?php echo esc_html($row->bvn); ?></code></td></tr>
            </table>
        </div>
        <?php
    }

    /**
     * Project Details card. Loan amount is formatted with the
     * configured currency symbol so operators see the same Naira
     * figure they see on the Withdrawals/Deposits pages.
     */
    private static function render_project_card($row, $currency) {
        ?>
        <div class="matrix-admin-card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?php _e('Project Details', 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><?php _e('Applying As', 'matrix-mlm'); ?></th><td><?php echo esc_html(self::format_enum($row->applying_as)); ?></td></tr>
                <tr><th><?php _e('Business Address', 'matrix-mlm'); ?></th><td><?php echo esc_html(self::format_address(
                    $row->business_address_line1, $row->business_address_line2,
                    $row->business_city, $row->business_state,
                    $row->business_zip, $row->business_country
                )); ?></td></tr>
                <tr><th><?php _e('Loan Reason', 'matrix-mlm'); ?></th><td><?php echo esc_html(self::format_enum($row->loan_reason)); ?></td></tr>
                <tr><th><?php _e('Project Gross Value', 'matrix-mlm'); ?></th><td><strong><?php echo esc_html($currency . number_format((float) $row->project_gross_value, 2)); ?></strong></td></tr>
                <tr><th><?php _e('Loan Amount', 'matrix-mlm'); ?></th><td><strong><?php echo esc_html($currency . number_format((float) $row->loan_amount, 2)); ?></strong></td></tr>
                <tr><th><?php _e('Repayment Plan', 'matrix-mlm'); ?></th><td><?php echo esc_html(ucfirst((string) $row->repayment_plan)); ?></td></tr>
                <tr><th><?php _e('Has Assets &amp; Liabilities Statement?', 'matrix-mlm'); ?></th><td><?php echo $row->has_assets_statement ? esc_html__('Yes', 'matrix-mlm') : esc_html__('No', 'matrix-mlm'); ?></td></tr>
                <tr><th><?php _e('Previously Financed?', 'matrix-mlm'); ?></th><td><?php echo $row->previously_financed ? esc_html__('Yes', 'matrix-mlm') : esc_html__('No', 'matrix-mlm'); ?></td></tr>
                <tr><th><?php _e('Agreed to T&amp;Cs', 'matrix-mlm'); ?></th><td><?php echo $row->agreed_terms
                    ? '<span class="matrix-badge matrix-badge-approved">' . esc_html__('Yes', 'matrix-mlm') . '</span>'
                    : '<span class="matrix-badge matrix-badge-rejected">' . esc_html__('No', 'matrix-mlm') . '</span>'; ?></td></tr>
            </table>
        </div>
        <?php
    }

    /**
     * Documents card — eight uploads (six required + two optional).
     * Renders inline thumbnails for image MIMEs and "View" links for
     * PDFs. The schema stores absolute URLs from wp_handle_upload, so
     * the URL is also the visible target — no signing or token logic
     * is required here.
     */
    private static function render_documents_card($row) {
        $docs = [
            'nin_file_url'           => __('NIN', 'matrix-mlm'),
            'utility_bill_url'       => __('Utility Bill', 'matrix-mlm'),
            'valid_id_url'           => __('Valid ID', 'matrix-mlm'),
            'passport_photo_url'     => __('Passport Photo', 'matrix-mlm'),
            'guarantor_valid_id_url' => __("Guarantor's Valid ID", 'matrix-mlm'),
            'guarantor_passport_url' => __("Guarantor's Passport Photo", 'matrix-mlm'),
            'marketing_material_url' => __('Project Marketing Material', 'matrix-mlm'),
            'project_info_url'       => __('Project Information', 'matrix-mlm'),
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

    /**
     * Guarantor's Details card.
     */
    private static function render_guarantor_card($row) {
        ?>
        <div class="matrix-admin-card" style="margin-bottom:16px;">
            <h2 style="margin-top:0;"><?php _e("Guarantor's Details", 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><?php _e('First Name', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->guarantor_first_name); ?></td></tr>
                <tr><th><?php _e('Last Name', 'matrix-mlm'); ?></th><td><?php echo esc_html($row->guarantor_last_name); ?></td></tr>
                <tr><th><?php _e('Phone', 'matrix-mlm'); ?></th><td><code><?php echo esc_html($row->guarantor_phone); ?></code></td></tr>
            </table>
        </div>
        <?php
    }

    /**
     * Apply a status / notes update from the detail-page form, with
     * server-side validation that mirrors the column whitelists. A
     * status outside the whitelist is rejected outright rather than
     * silently coerced — the operator sees a real error instead of
     * an apparently-successful save with the wrong value.
     *
     * Fires Matrix_MLM_Notifications::send_loan_notification() when
     * the status changes to one of NOTIFIABLE_STATUSES, so applicants
     * get an email when there's a real decision to communicate.
     */
    private function handle_update() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_loan_applications';

        $id     = isset($_POST['loan_application_id']) ? intval($_POST['loan_application_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $notes  = isset($_POST['admin_notes']) ? sanitize_textarea_field(wp_unslash($_POST['admin_notes'])) : '';

        if ($id <= 0) {
            self::admin_notice('error', __('Invalid application ID.', 'matrix-mlm'));
            return;
        }
        if (!in_array($status, self::STATUSES, true)) {
            self::admin_notice('error', __('Invalid status value.', 'matrix-mlm'));
            return;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, status, loan_amount FROM {$table} WHERE id = %d",
            $id
        ));
        if (!$existing) {
            self::admin_notice('error', __('Loan application not found.', 'matrix-mlm'));
            return;
        }

        $result = $wpdb->update(
            $table,
            [
                'status'      => $status,
                'admin_notes' => $notes,
                'updated_at'  => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            $ref = Matrix_MLM_DB_Error::log_and_token('loans.update', $wpdb->last_error);
            self::admin_notice('error', sprintf(
                /* translators: %s: opaque support reference */
                __('Could not update loan application. Reference: %s.', 'matrix-mlm'),
                esc_html($ref)
            ));
            return;
        }

        $status_changed = $status !== $existing->status;

        // Notify the applicant only when a meaningful decision was
        // actually made. Pending/under_review changes are internal
        // workflow noise from the user's POV.
        if ($status_changed
            && in_array($status, self::NOTIFIABLE_STATUSES, true)
            && class_exists('Matrix_MLM_Notifications')
            && method_exists('Matrix_MLM_Notifications', 'send_loan_notification')
        ) {
            Matrix_MLM_Notifications::send_loan_notification(
                intval($existing->user_id),
                (float) $existing->loan_amount,
                $status
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
            // $result === 0 is normal when the operator submits
            // without changing anything. Treat as a successful save.
            self::admin_notice('success', __('Loan application saved.', 'matrix-mlm'));
        }
    }

    /**
     * Per-status row counts for the filter chips. Returns a
     * status => count map; missing statuses default to 0 at
     * read time.
     */
    private function get_status_counts() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_loan_applications';

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

    /**
     * INFORMATION_SCHEMA probe — same cheap-and-safe pattern used by
     * the user-facing loan class and the CUG admin.
     */
    private static function table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_loan_applications';
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));
        return $exists > 0;
    }

    /**
     * Format a MySQL datetime for the admin tables. Same helper
     * shape as the CUG admin, but kept local so the loan admin
     * has no implicit cross-class dependency at load time.
     */
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

    /**
     * Format a MySQL date column (no time component).
     */
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

    /**
     * Pretty-print a snake_case enum value: "sole_proprietor" →
     * "Sole Proprietor", "under_review" → "Under Review".
     */
    private static function format_enum($value) {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        return ucwords(str_replace('_', ' ', $value));
    }

    /**
     * Compose a human-readable address from the per-line columns,
     * skipping any empty parts so we don't emit ", , Lagos" for a
     * row with no line 2 or zip.
     */
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

    /**
     * Heuristic check for whether a stored upload URL points at an
     * image (so the detail page can render an inline thumbnail) vs
     * a PDF (so it can render a "View document" button instead).
     * Loan form accepts pdf/jpeg/png/webp; anything else falls back
     * to the document button.
     */
    private static function is_image_url($url) {
        $ext = strtolower(pathinfo(parse_url((string) $url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }

    /**
     * Render an inline admin notice. Same-request render — matches
     * the CUG/Benefits admin pattern, no PRG redirect.
     */
    private static function admin_notice($type, $message) {
        $cls = $type === 'error' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>'
            . wp_kses_post($message)
            . '</p></div>';
    }
}
