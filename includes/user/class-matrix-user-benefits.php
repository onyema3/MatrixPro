<?php
/**
 * User Dashboard — Benefits tab
 *
 * Renders the admin-managed wp_matrix_benefits rows as a responsive
 * card grid for users who have at least one active position. Users
 * without an active plan see a friendly upsell pointing them at the
 * Plans tab instead of a blank screen.
 *
 * Design choices:
 *   - The whole panel is server-rendered HTML; the only client-side
 *     code is the lightweight inline modal that expands the long
 *     description on "Read more". No external JS dependencies, so
 *     this works whether or not the dashboard's main JS bundle has
 *     loaded.
 *   - Icon dispatch (Dashicons class vs image URL) is shared with
 *     the admin via Matrix_MLM_Admin_Benefits::render_icon_preview
 *     so the operator sees exactly what users will see.
 *   - The active-plan gate uses Matrix_MLM_User::get_active_plans()
 *     — the same helper that drives the "My Plans" tab — so a row
 *     in matrix_positions with status='active' is the source of
 *     truth, not e.g. matrix_user_meta.status.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Benefits {

    /**
     * Render the Benefits tab for the given user.
     *
     * @param int $user_id Current user.
     */
    public function render($user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return;
        }

        // Active-plan gate. An empty array means the user has no
        // active position in any matrix plan — they get the upsell.
        $active_plans = class_exists('Matrix_MLM_User')
            ? Matrix_MLM_User::get_active_plans($user_id)
            : [];

        ?>
        <div class="matrix-benefits-panel">
            <h2><?php _e('Member Benefits', 'matrix-mlm'); ?></h2>

            <?php if (empty($active_plans)): ?>
                <?php $this->render_upsell(); ?>
            <?php else: ?>
                <?php $this->render_grid($user_id); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the upsell shown to users without an active plan.
     * Links to the Plans tab so they're one click from the fix.
     */
    private function render_upsell() {
        $plans_url = Matrix_MLM_User_Dashboard::tab_url('plans');
        ?>
        <div class="matrix-alert matrix-alert-info">
            <strong><?php _e('Subscribe to a plan to unlock member benefits.', 'matrix-mlm'); ?></strong><br>
            <span style="font-size:14px;">
                <?php _e('Member benefits like CUG calls and Health Insurance are available to members with at least one active matrix plan.', 'matrix-mlm'); ?>
            </span>
            <p style="margin-top:14px;">
                <a href="<?php echo esc_url($plans_url); ?>" class="matrix-btn matrix-btn-primary">
                    <?php _e('View Plans', 'matrix-mlm'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Render the active-benefit card grid + the long-description
     * modal scaffold. Even with zero rows (operator hasn't created
     * any yet) we render the explanatory empty state instead of a
     * blank panel — same pattern as the other dashboard tabs.
     */
    private function render_grid($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_benefits';

        // INFORMATION_SCHEMA probe so a missing-table install (older
        // schema, repair pending) doesn't bomb the dashboard with a
        // SQL error. This mirrors the defensive check used by the
        // bank-code admin notice — the maybe_upgrade() self-heal
        // should have created the table on the previous pageload,
        // but rendering safely is cheap and the test (one cached
        // INFORMATION_SCHEMA query) is effectively free.
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));
        if ($exists === 0) {
            echo '<div class="matrix-alert matrix-alert-info">'
                . esc_html__('Benefits will appear here shortly. The administrator is finalising setup.', 'matrix-mlm')
                . '</div>';
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT id, title, slug, icon, short_description, long_description, cta_label, cta_url
               FROM {$table}
               WHERE status = 'active'
               ORDER BY display_order ASC, id ASC"
        );

        if (empty($rows)) {
            echo '<div class="matrix-alert matrix-alert-info">'
                . esc_html__('No benefits are available right now. Check back soon.', 'matrix-mlm')
                . '</div>';
            return;
        }

        ?>
        <p class="matrix-benefits-intro">
            <?php _e('Here\'s what comes with your active plan. Click any card to read more.', 'matrix-mlm'); ?>
        </p>

        <div class="matrix-benefits-grid">
            <?php
            $cug_card_title  = '';
            $loan_card_title = '';
            $healthcare_card_title = '';
            foreach ($rows as $row) {
                $this->render_card($row);
                // Track which special-case cards are on the page so
                // we know to render their respective modal scaffolds
                // exactly once below the grid. Slug is the canonical
                // identifier; the title fallback is purely cosmetic
                // — passed to each modal so its heading matches
                // whatever the operator named the card in admin.
                if ($cug_card_title === '' && self::is_cug_slug($row->slug ?? '')) {
                    $cug_card_title = (string) ($row->title ?? '');
                }
                if ($loan_card_title === '' && self::is_loan_slug($row->slug ?? '')) {
                    $loan_card_title = (string) ($row->title ?? '');
                }
                if ($healthcare_card_title === '' && self::is_healthcare_slug($row->slug ?? '')) {
                    $healthcare_card_title = (string) ($row->title ?? '');
                }
            }
            ?>
        </div>

        <?php $this->render_modal_scaffold(); ?>

        <?php
        // Emit the CUG application-form modal once if the CUG card is
        // on the page. Done outside the foreach so multiple CUG-like
        // entries (shouldn't happen — slug is UNIQUE — but defensive)
        // don't produce conflicting modal instances.
        if ($cug_card_title !== '' && class_exists('Matrix_MLM_User_CUG')) {
            Matrix_MLM_User_CUG::render_form_modal($user_id, $cug_card_title);
        }
        // Same one-shot pattern for the loan application form.
        if ($loan_card_title !== '' && class_exists('Matrix_MLM_User_Loan')) {
            Matrix_MLM_User_Loan::render_form_modal($user_id, $loan_card_title);
        }
        // And the healthcare application form, identical pattern.
        if ($healthcare_card_title !== '' && class_exists('Matrix_MLM_User_Healthcare')) {
            Matrix_MLM_User_Healthcare::render_form_modal($user_id, $healthcare_card_title);
        }
        ?>
        <?php
    }

    /**
     * Render a single benefit card. The "Read more" link is bound
     * via a data-attribute that the modal JS picks up; no per-card
     * inline event handlers, which keeps the markup compact and
     * works whether or not jQuery is on the page.
     */
    private function render_card($row) {
        $title       = $row->title ?: '';
        $short       = $row->short_description ?: '';
        $long        = $row->long_description ?: '';
        $cta_label   = trim((string) $row->cta_label);
        $cta_url     = trim((string) $row->cta_url);
        $has_cta     = $cta_label !== '' && $cta_url !== '';
        $has_long    = trim(wp_strip_all_tags($long)) !== '';
        $card_id     = 'matrix-benefit-' . intval($row->id);
        $is_cug      = self::is_cug_slug($row->slug ?? '');
        $is_loan     = self::is_loan_slug($row->slug ?? '');
        $is_healthcare = self::is_healthcare_slug($row->slug ?? '');
        ?>
        <article class="matrix-benefit-card<?php echo $is_cug ? ' matrix-benefit-card-cug' : ''; echo $is_loan ? ' matrix-benefit-card-loan' : ''; echo $is_healthcare ? ' matrix-benefit-card-healthcare' : ''; ?>"
                 id="<?php echo esc_attr($card_id); ?>"
                 <?php
                 if ($is_cug) echo 'data-benefit-slug="cug"';
                 elseif ($is_loan) echo 'data-benefit-slug="loan"';
                 elseif ($is_healthcare) echo 'data-benefit-slug="healthcare"';
                 ?>>
            <div class="benefit-icon">
                <?php
                // Reuse the admin's preview helper so the user sees
                // the same icon the operator saw at edit time.
                if (class_exists('Matrix_MLM_Admin_Benefits')) {
                    echo Matrix_MLM_Admin_Benefits::render_icon_preview($row->icon, 56);
                } else {
                    echo self::render_icon_fallback($row->icon, 56);
                }
                ?>
            </div>
            <h3 class="benefit-title"><?php echo esc_html($title); ?></h3>
            <?php if ($short !== ''): ?>
                <p class="benefit-short"><?php echo esc_html($short); ?></p>
            <?php endif; ?>
            <div class="benefit-actions">
                <?php if ($is_cug): ?>
                    <?php
                    // The CUG card gets a primary "Apply" CTA that
                    // opens the application-form modal rendered by
                    // Matrix_MLM_User_CUG::render_form_modal() lower
                    // in the page. data-cug-trigger is the contract
                    // between the card and the modal's JS — any
                    // future entry point (a dashboard quick action,
                    // a footer banner) only needs to add the same
                    // attribute to participate.
                    ?>
                    <button type="button"
                            class="matrix-btn matrix-btn-sm matrix-btn-primary matrix-cug-apply"
                            data-cug-trigger="1">
                        <?php _e('Apply for CUG', 'matrix-mlm'); ?>
                    </button>
                <?php endif; ?>
                <?php if ($is_loan): ?>
                    <button type="button"
                            class="matrix-btn matrix-btn-sm matrix-btn-primary matrix-loan-apply"
                            data-loan-trigger="1">
                        <?php _e('Apply for Loan', 'matrix-mlm'); ?>
                    </button>
                <?php endif; ?>
                <?php if ($is_healthcare): ?>
                    <?php
                    // The healthcare card gets a primary "Apply" CTA
                    // that opens the application-form modal rendered
                    // by Matrix_MLM_User_Healthcare::render_form_modal().
                    // data-healthcare-trigger is the contract between
                    // the card and the modal's JS — same pattern as
                    // the CUG and loan triggers above.
                    ?>
                    <button type="button"
                            class="matrix-btn matrix-btn-sm matrix-btn-primary matrix-healthcare-apply"
                            data-healthcare-trigger="1">
                        <?php _e('Apply for Healthcare', 'matrix-mlm'); ?>
                    </button>
                <?php endif; ?>
                <?php if ($has_long): ?>
                    <button type="button" class="matrix-btn matrix-btn-sm matrix-benefit-readmore"
                            data-benefit-id="<?php echo intval($row->id); ?>"
                            data-benefit-title="<?php echo esc_attr($title); ?>">
                        <?php _e('Read more', 'matrix-mlm'); ?>
                    </button>
                    <template id="matrix-benefit-long-<?php echo intval($row->id); ?>"><?php
                        // wp_kses_post on output so links + paragraphs
                        // come through but unsafe markup (script/style)
                        // is stripped. The operator-side store already
                        // passes through wp_kses_post on save, so this
                        // is belt-and-braces.
                        echo wp_kses_post($long);
                    ?></template>
                <?php endif; ?>
                <?php if ($has_cta): ?>
                    <a href="<?php echo esc_url($cta_url); ?>"
                       class="matrix-btn matrix-btn-sm matrix-btn-primary"
                       target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($cta_label); ?>
                    </a>
                <?php endif; ?>
            </div>
        </article>
        <?php
    }

    /**
     * True if the given slug refers to the CUG benefit. Match is
     * case-insensitive and accepts either the exact slug or a
     * "cug-" prefixed variant so an operator who renames the row to
     * e.g. "cug-airtel" still gets the application form treatment.
     */
    public static function is_cug_slug($slug) {
        $slug = strtolower(trim((string) $slug));
        if ($slug === '') {
            return false;
        }
        return $slug === 'cug' || strpos($slug, 'cug-') === 0;
    }

    /**
     * True if the given slug refers to the Loans benefit. Same
     * matching rules as CUG: bare 'loan'/'loans' or any 'loan-' /
     * 'loans-' prefixed variant the operator might rename to (e.g.
     * 'loan-sme', 'loans-corporate'). Lets the operator rename the
     * card title freely without breaking the form association.
     */
    public static function is_loan_slug($slug) {
        $slug = strtolower(trim((string) $slug));
        if ($slug === '') {
            return false;
        }
        return $slug === 'loan' || $slug === 'loans'
            || strpos($slug, 'loan-') === 0
            || strpos($slug, 'loans-') === 0;
    }

    /**
     * True if the given slug refers to the Healthcare benefit.
     * Same matching rules as CUG/Loan: bare 'healthcare' / 'hmo' or
     * any 'healthcare-' / 'hmo-' prefixed variant the operator might
     * rename to (e.g. 'healthcare-family', 'hmo-premium'). Both the
     * generic and the industry-shorthand prefix work because the
     * Nigerian market uses them interchangeably for the same
     * benefit, and rejecting either would silently strand operator
     * naming choices that work fine elsewhere on the dashboard.
     */
    public static function is_healthcare_slug($slug) {
        $slug = strtolower(trim((string) $slug));
        if ($slug === '') {
            return false;
        }
        return $slug === 'healthcare' || $slug === 'hmo'
            || strpos($slug, 'healthcare-') === 0
            || strpos($slug, 'hmo-') === 0;
    }

    /**
     * Render the modal scaffold + its JS once per page (after the
     * grid). The modal is hidden by default and populated client-side
     * from the matching <template> when a card's "Read more" is
     * clicked. Using <template> rather than data-* attributes keeps
     * the long_description's HTML intact (paragraph breaks, lists,
     * links) without needing to escape and unescape it.
     */
    private function render_modal_scaffold() {
        ?>
        <?php
        // Same belt-and-braces hide pattern used by the CUG modal:
        // hidden attribute + inline display:none + aria-hidden, all
        // toggled together by the JS below. Protects against the
        // exact failure mode the CUG modal hit in the wild — a
        // cached matrix-public.css that predates the CSS rule
        // hiding `.matrix-benefit-modal` and so renders the modal
        // contents inline on the dashboard.
        ?>
        <div class="matrix-benefit-modal" id="matrix-benefit-modal"
             hidden
             style="display:none;"
             aria-hidden="true" role="dialog" aria-modal="true">
            <div class="matrix-benefit-modal-backdrop" data-benefit-modal-close></div>
            <div class="matrix-benefit-modal-dialog" role="document">
                <button type="button" class="matrix-benefit-modal-close" data-benefit-modal-close aria-label="<?php esc_attr_e('Close', 'matrix-mlm'); ?>">&times;</button>
                <h3 class="matrix-benefit-modal-title"></h3>
                <div class="matrix-benefit-modal-body"></div>
            </div>
        </div>

        <script>
        (function() {
            var modal     = document.getElementById('matrix-benefit-modal');
            if (!modal) return;
            var titleEl   = modal.querySelector('.matrix-benefit-modal-title');
            var bodyEl    = modal.querySelector('.matrix-benefit-modal-body');
            var triggers  = document.querySelectorAll('.matrix-benefit-readmore');

            function openModal(id, title) {
                var tpl = document.getElementById('matrix-benefit-long-' + id);
                if (!tpl) return;
                titleEl.textContent = title;
                // Use innerHTML from the <template>'s content so the
                // already-sanitized HTML (paragraphs, links) renders
                // properly — wp_kses_post on the server side stripped
                // anything dangerous, and the template element's
                // contents are inert until copied into the modal.
                bodyEl.innerHTML = tpl.innerHTML;
                // Remove every layer hiding the modal so it works
                // even if the cached CSS predates the hide rule.
                modal.hidden = false;
                modal.style.display = '';
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('matrix-benefit-modal-lock');
            }

            function closeModal() {
                modal.classList.remove('is-open');
                // Reassert every hide layer on close, mirroring the
                // CUG modal so a stale stylesheet can't leave the
                // dialog visible after dismiss.
                modal.hidden = true;
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('matrix-benefit-modal-lock');
                bodyEl.innerHTML = '';
            }

            triggers.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    openModal(btn.getAttribute('data-benefit-id'), btn.getAttribute('data-benefit-title') || '');
                });
            });

            modal.querySelectorAll('[data-benefit-modal-close]').forEach(function(el) {
                el.addEventListener('click', closeModal);
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeModal();
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Last-resort icon renderer in case the admin class is somehow
     * not loaded (shouldn't happen because matrix-mlm.php requires
     * it, but defending against partial deploys is cheap).
     */
    private static function render_icon_fallback($icon, $size) {
        $icon = (string) $icon;
        if ($icon === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $icon)) {
            return sprintf(
                '<img src="%s" alt="" style="width:%dpx;height:%dpx;object-fit:contain;">',
                esc_url($icon),
                intval($size),
                intval($size)
            );
        }
        if (preg_match('/^dashicons-[a-z0-9\-]+$/i', $icon)) {
            return sprintf(
                '<span class="dashicons %s" style="font-size:%dpx;width:%dpx;height:%dpx;"></span>',
                esc_attr($icon),
                intval($size),
                intval($size),
                intval($size)
            );
        }
        return '';
    }
}
