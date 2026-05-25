<?php
/**
 * User Billing Services (Airtime, Data, Cable TV, Electricity)
 * Powered by Fintava Pay billing endpoints
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Billing {

    public function render($user_id) {
        $wallet = new Matrix_MLM_Wallet();
        $balance = $wallet->get_balance($user_id);
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $billing = new Matrix_MLM_Fintava_Billing();
        $history = $billing->get_user_history($user_id, null, 10);
        $sub_tab = sanitize_text_field($_GET['service'] ?? 'airtime');

        // Per-category visibility — admins can disable individual
        // categories (e.g. pause Electricity during a disco outage)
        // via Settings -> Bill Payments. Build the visible list once
        // here and reuse it for both the sub-tab nav and the
        // current-tab fall-through, so a category that was disabled
        // mid-session can't render an orphan form.
        $categories = [
            'airtime'     => __('Airtime', 'matrix-mlm'),
            'data'        => __('Data', 'matrix-mlm'),
            'cable'       => __('Cable TV', 'matrix-mlm'),
            'electricity' => __('Electricity', 'matrix-mlm'),
        ];
        $enabled_categories = [];
        foreach ($categories as $slug => $label) {
            if (Matrix_MLM_Fintava_Billing::is_category_enabled($slug)) {
                $enabled_categories[$slug] = $label;
            }
        }
        $all_disabled = empty($enabled_categories);
        // If the requested sub_tab is disabled (or unknown), fall
        // through to the first enabled category. Mirrors the
        // existing precedent on dashboard tabs where retired slugs
        // fall through to overview rather than rendering empty.
        if (!isset($enabled_categories[$sub_tab])) {
            $sub_tab = $all_disabled ? '' : array_key_first($enabled_categories);
        }
        // Matrix_MLM_Fintava_Billing::MIN_AMOUNT is the floor every
        // upstream call enforces (see validate_amount), so use it as
        // the threshold for the "your balance is too low" inline
        // warning rather than a magic number that could drift.
        $min_amount = Matrix_MLM_Fintava_Billing::MIN_AMOUNT;
        $low_balance = ($balance < $min_amount);

        // Service fee config (item C). Inlined as a JSON blob on the
        // page so each form can render a client-side fee preview
        // ("Amount / Service fee / Total") without an extra AJAX
        // round-trip. The server still recomputes the fee at submit
        // time via Matrix_MLM_Fintava_Billing::compute_service_fee(),
        // which is the single source of truth — this client value is
        // a UX hint only. Markup is also user-visible by design (we
        // disclose the line item before they confirm), so there's no
        // secrecy concern with putting it in the rendered HTML.
        $markup_config = Matrix_MLM_Fintava_Billing::get_markup_config();
        ?>
        <h2><?php _e('Bill Payments', 'matrix-mlm'); ?></h2>
        <p class="matrix-subtitle"><?php _e('Buy airtime, data bundles, cable TV subscriptions, and pay electricity bills. All payments are debited from your Matrix wallet.', 'matrix-mlm'); ?></p>

        <script>
        // Exposed once for all four bill forms on this page. Each
        // form's render method binds its preview update to this map.
        window.matrixBillingConfig = {
            markup: <?php echo wp_json_encode($markup_config); ?>,
            currencySymbol: <?php echo wp_json_encode($currency); ?>
        };
        // Pure-function fee calculator. Mirrors
        // Matrix_MLM_Fintava_Billing::compute_service_fee() in PHP —
        // any algorithm change MUST be made in both places. The PHP
        // copy is authoritative for billing; this copy is for the
        // preview UI only.
        window.matrixBillingComputeFee = function(type, nominal) {
            var n = parseFloat(nominal);
            if (!isFinite(n) || n <= 0) return 0;
            var cfg = (window.matrixBillingConfig.markup || {})[type];
            if (!cfg) return 0;
            var flat = Math.max(0, parseFloat(cfg.flat || 0));
            var pct  = Math.max(0, Math.min(100, parseFloat(cfg.percent || 0)));
            return Math.round((flat + n * pct / 100) * 100) / 100;
        };
        // Format helper kept here so the four forms render the
        // breakdown identically (and so a future locale swap is
        // a one-line change).
        window.matrixBillingFormatMoney = function(v) {
            var n = parseFloat(v);
            if (!isFinite(n)) n = 0;
            return window.matrixBillingConfig.currencySymbol +
                n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        };
        // Update the .matrix-fee-preview block inside `formSelector`
        // based on the current value of its `[name=amount]` field
        // and `dataType`. Always shows the breakdown when the user
        // has entered a positive amount, even when the configured
        // service fee is zero — the previous behaviour hid the
        // whole preview when fee = 0, which left the user with no
        // on-form confirmation that the wallet debit equals the
        // bill amount with no extra fee added. That ambiguity led
        // to the user-reported "service fees is 0 and yet on the
        // form i see service fee being computed" complaint: the
        // user couldn't see a definitive "Total to debit" line, so
        // the absence of the preview was being read either as the
        // form silently adding a fee (when reading the history
        // table at the bottom of the page) or as the form being
        // broken.
        //
        // When fee = 0 we hide just the "Service fee" row, leaving
        // a clean two-line Amount / Total breakdown that makes the
        // no-fee case unambiguous. When fee > 0 all three rows
        // display.
        window.matrixBillingUpdatePreview = function(formSelector, dataType) {
            var $form = jQuery(formSelector);
            if (!$form.length) return;
            var nominal = parseFloat($form.find('[name=amount]').val());
            var preview = $form.find('.matrix-fee-preview');
            if (!isFinite(nominal) || nominal <= 0) {
                preview.hide();
                return;
            }
            var fee = window.matrixBillingComputeFee(dataType, nominal);
            var total = Math.round((nominal + fee) * 100) / 100;
            preview.find('.matrix-fee-nominal').text(window.matrixBillingFormatMoney(nominal));
            preview.find('.matrix-fee-amount').text(window.matrixBillingFormatMoney(fee));
            preview.find('.matrix-fee-total').text(window.matrixBillingFormatMoney(total));
            // Toggle just the Service fee row on the fee value, not
            // the entire preview. Targets the dedicated semantic
            // class added in render_fee_preview_block() rather than
            // an `.eq(1)` index so a future row reorder doesn't
            // silently move the toggle to the wrong row.
            if (fee > 0) {
                preview.find('.matrix-fee-row-service-fee').show();
            } else {
                preview.find('.matrix-fee-row-service-fee').hide();
            }
            preview.show();
        };
        // Defence-in-depth: every bill-payment AJAX handler used to
        // do `alert(r.data.message)` directly. If a future PHP
        // regression (or an upstream gateway shape change) ever put
        // a non-string into `data.message`, JS would coerce it via
        // implicit toString and the user would see a literal
        // "[object Object]". This helper guards every alert site
        // with a typeof-string check and a sane fallback so that
        // can't happen again.
        window.matrixBillingExtractMessage = function(r, fallback) {
            if (r && r.data && typeof r.data.message === 'string' && r.data.message.length) {
                return r.data.message;
            }
            return fallback || 'An unexpected error occurred. Please try again.';
        };
        // Render Fintava's meter-verify payload (`r.data.meter`) as
        // plain text. Replaces the previous one-liner that did
        // `info += k + ': ' + m[k]` and rendered nested objects
        // (e.g. `customer`, `tariff`, `address`) as the literal
        // string "[object Object]" — which was the user-visible
        // "bills payment error [object Object]" bug. Strategy:
        //   - skip null/undefined
        //   - render scalars directly
        //   - JSON.stringify nested objects/arrays so the user
        //     sees actual content instead of "[object Object]"
        // We build a textContent string (not HTML), so jQuery's
        // .text() handles escaping — Fintava is a third-party
        // upstream and the previous code injected its response
        // straight into .html(), which was also an XSS foot-gun.
        window.matrixBillingFormatMeterInfo = function(meter) {
            if (!meter || typeof meter !== 'object') {
                return '';
            }
            var parts = [];
            for (var k in meter) {
                if (!Object.prototype.hasOwnProperty.call(meter, k)) continue;
                var v = meter[k];
                if (v === null || typeof v === 'undefined' || v === '') continue;
                if (typeof v === 'object') {
                    try { v = JSON.stringify(v); } catch (e) { v = String(v); }
                }
                parts.push(k + ': ' + v);
            }
            return parts.join(' | ');
        };
        // Inline status banner shown above the bill form on submit
        // outcome. Replaces the previous "alert() + location.reload()"
        // pattern, which — once PR #276 made airtime actually
        // succeed — caused the form to disappear in a full-page
        // refresh on every successful purchase. Users described it as
        // "page refreshes and loads again" and read it as the form
        // silently resetting without confirmation, when in fact the
        // purchase had completed.
        //
        // The notice <div> is created on first call and reused on
        // subsequent calls (kind / message are reset each time), so
        // the form HTML stays untouched until first interaction.
        // Placement: prepended to the form so it sits above the first
        // field, visible without scrolling. .text() (not .html())
        // avoids any XSS risk if a future caller passes a non-
        // sanitised PHP message through.
        //
        // kind: 'success' | 'error'   (anything else falls back to
        //                              'error' styling — fail visible
        //                              rather than silent).
        window.matrixBillingShowNotice = function(formSelector, kind, message) {
            var $form = jQuery(formSelector);
            if (!$form.length) return;
            if (kind !== 'success') { kind = 'error'; }
            var $notice = $form.find('.matrix-billing-notice').first();
            if (!$notice.length) {
                $notice = jQuery('<div class="matrix-billing-notice" role="status" aria-live="polite" tabindex="-1"></div>');
                $form.prepend($notice);
            }
            $notice
                .removeClass('matrix-billing-notice-success matrix-billing-notice-error')
                .addClass('matrix-billing-notice-' + kind)
                .text(typeof message === 'string' && message.length ? message : '')
                .show();
            // Bring the notice into view and focus it so screen-reader
            // users hear the announcement and sighted users on small
            // screens (where the notice may be above the fold of the
            // form scroll position) actually see it.
            try { $notice[0].scrollIntoView({behavior: 'smooth', block: 'nearest'}); } catch (e) {}
            try { $notice.focus(); } catch (e) {}
        };
        </script>

        <?php
        // Note: bill payments do NOT require a Fintava virtual wallet.
        // Fintava charges the merchant master account when the billing
        // endpoints are called; we reimburse the merchant by debiting
        // the user's matrix_user_meta.balance up-front (see the
        // class-level docblock on Matrix_MLM_Fintava_Billing). An
        // earlier revision of this template gated the whole page on
        // user_has_wallet() and told users they needed a Fintava
        // virtual wallet first — that gate was incorrect and blocked
        // legitimate users from a feature they could otherwise use.
        ?>

        <div class="matrix-info-box" style="margin-bottom:16px;">
            <p style="margin:0;"><strong><?php _e('Payment Source:', 'matrix-mlm'); ?></strong> <?php _e('Matrix Wallet', 'matrix-mlm'); ?></p>
            <p style="margin:6px 0 0;font-size:13px;color:#374151;">
                <?php _e('Available balance:', 'matrix-mlm'); ?>
                <strong><?php echo esc_html($currency . number_format($balance, 2)); ?></strong>
                <a href="<?php echo esc_url(Matrix_MLM_User_Dashboard::tab_url('deposits')); ?>" style="margin-left:10px;font-size:12px;"><?php _e('Add funds &rarr;', 'matrix-mlm'); ?></a>
            </p>
            <?php if ($low_balance): ?>
            <p style="margin:6px 0 0;font-size:12px;color:#b91c1c;">
                <?php
                printf(
                    /* translators: %1$s: currency symbol, %2$s: minimum amount, formatted */
                    esc_html__('Your balance is below the minimum bill amount of %1$s%2$s. Please fund your wallet before submitting a purchase.', 'matrix-mlm'),
                    esc_html($currency),
                    esc_html(number_format($min_amount, 2))
                );
                ?>
            </p>
            <?php endif; ?>
        </div>

        <!-- Service Tabs -->
        <?php if ($all_disabled): ?>
            <div class="matrix-alert matrix-alert-warning" style="margin-top:8px;">
                <?php _e('Bill payments are temporarily unavailable. Please check back later or contact support.', 'matrix-mlm'); ?>
            </div>
        <?php else: ?>
            <div class="matrix-billing-tabs">
                <?php foreach ($enabled_categories as $slug => $label): ?>
                    <a href="<?php echo esc_url(home_url('/matrix-dashboard/?tab=billing&service=' . $slug)); ?>" class="<?php echo $sub_tab === $slug ? 'active' : ''; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </div>

            <div class="matrix-form-card">
            <?php
            switch ($sub_tab) {
                case 'data': $this->render_data(); break;
                case 'cable': $this->render_cable(); break;
                case 'electricity': $this->render_electricity(); break;
                case 'airtime':
                default:
                    $this->render_airtime();
                    break;
            }
            ?>
            </div>
        <?php endif; ?>

        <!-- Transaction History -->
        <?php if (!empty($history)): ?>
        <h3 style="margin-top:24px;"><?php _e('Recent Bill Payments', 'matrix-mlm'); ?></h3>
        <table class="matrix-table">
            <thead><tr><th><?php _e('Date', 'matrix-mlm'); ?></th><th><?php _e('Type', 'matrix-mlm'); ?></th><th><?php _e('Amount', 'matrix-mlm'); ?></th><th><?php _e('Details', 'matrix-mlm'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($history as $tx):
                    $details = json_decode($tx->details, true);
                    // After item C, transactions carry a separate
                    // service_fee + total_charged. Legacy rows (pre-C)
                    // have these as 0 with `amount` populated, and the
                    // create_table() backfill seeds nominal_amount /
                    // total_charged from `amount` so post-migration
                    // every row displays consistently. Belt-and-braces:
                    // fall back to `amount` if total_charged is 0/null,
                    // which only happens before the migration runs once.
                    $display_total = isset($tx->total_charged) && floatval($tx->total_charged) > 0
                        ? floatval($tx->total_charged)
                        : floatval($tx->amount);
                    $display_fee = isset($tx->service_fee) ? floatval($tx->service_fee) : 0.0;
                    // Item D: surface partial / full refunds. Legacy
                    // rows pre-D have refunded_amount = 0 (column
                    // default), so the subtitle is hidden for any row
                    // that has not been touched by an admin refund.
                    $display_refunded = isset($tx->refunded_amount) ? floatval($tx->refunded_amount) : 0.0;
                    $tx_status        = isset($tx->status) ? (string) $tx->status : '';
                ?>
                <tr>
                    <td><?php echo date('M d, Y H:i', strtotime($tx->created_at)); ?></td>
                    <td>
                        <span class="matrix-badge"><?php echo ucfirst($tx->type); ?></span>
                        <?php if ($tx_status === 'pending'): ?>
                            <span class="matrix-badge matrix-badge-pending" style="margin-left:4px;font-size:10px;">
                                <?php esc_html_e('Verifying', 'matrix-mlm'); ?>
                            </span>
                        <?php elseif ($tx_status === 'failed'): ?>
                            <span class="matrix-badge matrix-badge-failed" style="margin-left:4px;font-size:10px;">
                                <?php esc_html_e('Failed', 'matrix-mlm'); ?>
                            </span>
                        <?php elseif ($tx_status === 'refunded' || $tx_status === 'partial_refund'): ?>
                            <span class="matrix-badge matrix-badge-<?php echo esc_attr($tx_status); ?>" style="margin-left:4px;font-size:10px;">
                                <?php echo $tx_status === 'refunded' ? esc_html__('Refunded', 'matrix-mlm') : esc_html__('Partial Refund', 'matrix-mlm'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html($currency . number_format($display_total, 2)); ?>
                        <?php if ($display_fee > 0): ?>
                            <small style="display:block;color:#6b7280;font-size:11px;">
                                <?php
                                printf(
                                    /* translators: %s: currency-formatted service fee */
                                    esc_html__('inc. %s service fee', 'matrix-mlm'),
                                    esc_html($currency . number_format($display_fee, 2))
                                );
                                ?>
                            </small>
                        <?php endif; ?>
                        <?php if ($display_refunded > 0): ?>
                            <small style="display:block;color:#059669;font-size:11px;font-weight:600;">
                                <?php
                                printf(
                                    /* translators: %s: currency-formatted refund amount */
                                    esc_html__('refunded %s', 'matrix-mlm'),
                                    esc_html($currency . number_format($display_refunded, 2))
                                );
                                ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td><small><?php echo $this->render_history_details($tx->type, $details); ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <style>
        .matrix-subtitle { color: #6b7280; margin: -10px 0 20px; font-size: 14px; }
        .matrix-billing-tabs { display: flex; gap: 0; margin-bottom: 0; border-bottom: 2px solid #e5e7eb; }
        .matrix-billing-tabs a { padding: 10px 20px; text-decoration: none; color: #6b7280; font-weight: 500; font-size: 14px; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all .2s; }
        .matrix-billing-tabs a.active { color: #4f46e5; border-bottom-color: #4f46e5; }
        .matrix-billing-tabs a:hover { color: #4f46e5; }
        /* Fee preview block — shown on each bill form once the user
           enters / picks an amount and the configured markup yields
           a non-zero fee. Hidden by default and when fee = 0 so the
           pre-C single-amount UX is preserved when no markup is
           configured. */
        .matrix-fee-preview { display:none; margin:12px 0; padding:12px 14px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; font-size:13px; color:#374151; }
        .matrix-fee-preview .matrix-fee-row { display:flex; justify-content:space-between; padding:2px 0; }
        .matrix-fee-preview .matrix-fee-row.matrix-fee-row-total { margin-top:6px; padding-top:8px; border-top:1px solid #e5e7eb; font-weight:600; color:#111827; }
        .matrix-fee-preview .matrix-fee-label { color:#6b7280; }
        /* Static caption rendered below the amount/plan field on
           each bill form. States in plain language what platform
           service fee is currently configured for the category, so
           the user can verify before typing an amount that no
           hidden fee is being applied. Reads off the same markup
           option the dynamic preview reads, so caption and preview
           cannot disagree. */
        .matrix-fee-caption { display:block; margin-top:6px; font-size:12px; color:#6b7280; }
        /* Inline status banner injected by matrixBillingShowNotice
           above the first form field on submit outcome. Replaces the
           old alert()+location.reload() pattern that disappeared the
           form on success and looked like a silent page refresh. */
        .matrix-billing-notice { padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:13px; line-height:1.45; outline:none; }
        .matrix-billing-notice-success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
        .matrix-billing-notice-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        </style>
        <?php
    }

    /**
     * Render the details cell of a row in the user-facing bill-history
     * table as a compact list of "Label: value" pairs scoped to fields
     * the user actually cares about, separated by a middle-dot.
     *
     * Example outputs:
     *   airtime     -> "Phone: 08012345678 · Network: MTN"
     *   data        -> "Phone: 08012345678 · Network: MTN · Plan: P-1GB-30D"
     *   cable       -> "Smartcard: 1234567890 · Provider: DSTV · Plan: COMPACT"
     *   electricity -> "Meter: 04123456789 · Disco: EKEDC · Type: prepaid · Token: 1234-5678-9012"
     *
     * The internal accounting field `debit_ref` is intentionally NOT
     * surfaced — it lives in matrix_wallet alongside the BILL-… and
     * REFUND-BILL-… auditor rows, which is where ops reconcile from.
     * Showing it in the user history was just visual noise.
     *
     * Per-type schemas declare both the key in the JSON `details`
     * payload and the user-facing label, in display order. Adding a
     * new bill type or a new surfaceable field is a one-row change
     * here. Unknown types fall back to a generic "show every scalar
     * value, exclude debit_ref" rendering so a partially-rolled-out
     * type doesn't render an empty cell.
     *
     * @param string $type    One of airtime|data|cable|electricity.
     * @param mixed  $details Decoded JSON from matrix_billing_transactions.details.
     * @return string Safe-to-echo HTML (only <strong> from this method;
     *                values pass through esc_html).
     */
    private function render_history_details($type, $details) {
        if (!is_array($details) || empty($details)) {
            return '';
        }

        // Per-type field schema: key-in-JSON => user-facing label.
        // Order is the display order. __() runs at request time so
        // translations are honoured.
        $schemas = [
            'airtime' => [
                'phone'   => __('Phone', 'matrix-mlm'),
                'network' => __('Network', 'matrix-mlm'),
            ],
            'data' => [
                'phone'   => __('Phone', 'matrix-mlm'),
                'network' => __('Network', 'matrix-mlm'),
                'plan_id' => __('Plan', 'matrix-mlm'),
            ],
            'cable' => [
                'smartcard' => __('Smartcard', 'matrix-mlm'),
                'provider'  => __('Provider', 'matrix-mlm'),
                'plan_id'   => __('Plan', 'matrix-mlm'),
            ],
            'electricity' => [
                'meter' => __('Meter', 'matrix-mlm'),
                'disco' => __('Disco', 'matrix-mlm'),
                'type'  => __('Type', 'matrix-mlm'),
                'token' => __('Token', 'matrix-mlm'),
            ],
        ];

        $schema = $schemas[$type] ?? null;
        $rows = [];

        if (is_array($schema)) {
            foreach ($schema as $key => $label) {
                $val = $details[$key] ?? null;
                if ($val === null || $val === '') {
                    continue;
                }
                $rows[] = '<strong>' . esc_html($label) . ':</strong> ' . esc_html((string) $val);
            }
        } else {
            // Defensive fallback: unknown type. Show every scalar
            // entry except the internal debit_ref / api_response so
            // a future bill category that ships before this method
            // is taught about it still renders something useful.
            foreach ($details as $key => $val) {
                if ($key === 'debit_ref' || $key === 'api_response') {
                    continue;
                }
                if (!is_scalar($val) || $val === '') {
                    continue;
                }
                $rows[] = '<strong>' . esc_html(ucfirst(str_replace('_', ' ', (string) $key))) . ':</strong> ' . esc_html((string) $val);
            }
        }

        return implode(' &middot; ', $rows);
    }

    /**
     * Render the inline fee preview block used by all four bill
     * forms. Hidden by default; the per-form JS calls
     * window.matrixBillingUpdatePreview() to compute the current
     * fee + total based on the form's amount field and the markup
     * config blob shared at the top of render(), and shows the
     * block only when fee > 0.
     *
     * Mirrored across all forms so a markup-config tweak (e.g.
     * adding a new line item, changing wording) is a single edit
     * here rather than four duplicate copies. Only the binding
     * (input vs. plan-select) differs per form.
     *
     * The block lives between the last form field and the submit
     * button so the user sees the breakdown right before the
     * commit click.
     */
    private function render_fee_preview_block() { ?>
        <div class="matrix-fee-preview" aria-live="polite">
            <div class="matrix-fee-row">
                <span class="matrix-fee-label"><?php _e('Amount', 'matrix-mlm'); ?></span>
                <span class="matrix-fee-nominal">&mdash;</span>
            </div>
            <div class="matrix-fee-row matrix-fee-row-service-fee">
                <span class="matrix-fee-label"><?php _e('Service fee', 'matrix-mlm'); ?></span>
                <span class="matrix-fee-amount">&mdash;</span>
            </div>
            <div class="matrix-fee-row matrix-fee-row-total">
                <span class="matrix-fee-label"><?php _e('Total to debit', 'matrix-mlm'); ?></span>
                <span class="matrix-fee-total">&mdash;</span>
            </div>
        </div>
    <?php }

    /**
     * Render a one-line caption that states, in plain language, what
     * platform service fee is currently configured for `$category`.
     * Sits below the amount field on each bill form so the user can
     * verify exactly what they will be charged BEFORE typing a
     * value, independent of the dynamic preview block (which only
     * reacts once an amount is entered).
     *
     * Outputs are deliberately blunt:
     *   - flat == 0 && percent == 0  -> "No service fee for this category."
     *   - flat > 0  && percent == 0  -> "Service fee: ₦20.00 per transaction."
     *   - flat == 0 && percent > 0   -> "Service fee: 1.50% of the amount."
     *   - flat > 0  && percent > 0   -> "Service fee: ₦20.00 + 1.50% of the amount."
     *
     * The flat / percent values come from the same
     * Matrix_MLM_Fintava_Billing::get_markup_config() the JS preview
     * reads, so a discrepancy between this caption and the preview
     * block is impossible — both render off the same option value
     * in the same request. That makes this caption a faithful
     * "what is currently saved" indicator: if the user reads "No
     * service fee" here but the preview later adds a fee, the
     * option is genuinely non-zero (i.e. the saved value is not
     * what the admin thinks it is) and the caption + preview will
     * agree on the truth, surfacing the misconfiguration.
     *
     * @param string $category One of Matrix_MLM_Fintava_Billing::BILL_CATEGORIES.
     * @return string Safe-to-echo HTML (a single <small> with esc_html'd content).
     */
    private function format_markup_caption($category) {
        // Fetch the full normalised config and pluck the requested
        // category. get_markup_config() always returns an entry for
        // every BILL_CATEGORIES slug with both fields populated, so
        // the dereferences below cannot trip on a missing key.
        $config   = Matrix_MLM_Fintava_Billing::get_markup_config();
        $cfg      = isset($config[$category]) && is_array($config[$category])
            ? $config[$category]
            : ['flat' => 0.0, 'percent' => 0.0];
        $flat     = (float) ($cfg['flat']    ?? 0);
        $percent  = (float) ($cfg['percent'] ?? 0);
        $currency = get_option('matrix_mlm_currency_symbol', '₦');

        // Derive the human caption first so the wrapper markup
        // below stays a single esc_html'd echo. printf-style format
        // strings are used so the translator sees the full sentence
        // — concatenating fragments is a known WP-i18n footgun.
        if ($flat <= 0 && $percent <= 0) {
            $caption = __('No service fee for this category.', 'matrix-mlm');
        } elseif ($percent <= 0) {
            $caption = sprintf(
                /* translators: %s: currency-formatted flat fee, e.g. "₦20.00" */
                __('Service fee: %s per transaction.', 'matrix-mlm'),
                $currency . number_format($flat, 2)
            );
        } elseif ($flat <= 0) {
            $caption = sprintf(
                /* translators: %s: percent figure formatted to 2dp, e.g. "1.50" */
                __('Service fee: %s%% of the amount.', 'matrix-mlm'),
                number_format($percent, 2)
            );
        } else {
            $caption = sprintf(
                /* translators: 1: currency-formatted flat fee, 2: percent figure 2dp */
                __('Service fee: %1$s + %2$s%% of the amount.', 'matrix-mlm'),
                $currency . number_format($flat, 2),
                number_format($percent, 2)
            );
        }

        return '<small class="matrix-fee-caption" data-bill-category="'
             . esc_attr($category) . '">' . esc_html($caption) . '</small>';
    }

    // =========================================================================
    // AIRTIME
    // =========================================================================
    private function render_airtime() { ?>
        <h3><?php _e('Buy Airtime', 'matrix-mlm'); ?></h3>
        <form id="matrix-billing-airtime" class="matrix-form" data-bill-type="airtime">
            <div class="matrix-form-group">
                <label><?php _e('Network', 'matrix-mlm'); ?></label>
                <select name="network" required>
                    <option value=""><?php _e('-- Select --', 'matrix-mlm'); ?></option>
                    <option value="MTN">MTN</option>
                    <option value="GLO">GLO</option>
                    <option value="AIRTEL">Airtel</option>
                    <option value="9MOBILE">9mobile</option>
                </select>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Phone Number', 'matrix-mlm'); ?></label>
                <input type="tel" name="phone" required placeholder="08012345678" maxlength="11">
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Amount (₦)', 'matrix-mlm'); ?></label>
                <input type="number" name="amount" min="50" max="50000" required placeholder="100">
            </div>
            <?php
            // Static "what is currently configured" caption. The
            // dynamic .matrix-fee-preview below only renders the
            // breakdown after the user types an amount; this
            // caption gives an upfront answer so a no-fee setup is
            // unambiguously communicated. See format_markup_caption()
            // for the full motivation and the user report that
            // drove it.
            echo $this->format_markup_caption('airtime');
            ?>
            <?php $this->render_fee_preview_block(); ?>
            <?php
            // Transaction PIN field (PR 2). All four bill forms
            // share the 'bills' path key (matrix_mlm_pin_required_
            // for_bills admin toggle); the helper short-circuits to
            // '' when the toggle is off or the user has no PIN, so
            // installs that haven't opted in see no UI change. Use
            // get_current_user_id() rather than threading $user_id
            // through render_*() — these renderers run inside the
            // user's authenticated session so the call is always
            // consistent with the require_pin_for_request() gate
            // in the matching ajax_buy_airtime handler.
            echo Matrix_MLM_Transaction_Pin::render_field(get_current_user_id(), 'bills');
            ?>
            <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block"><?php _e('Buy Airtime', 'matrix-mlm'); ?></button>
        </form>
        <script>
        (function($){
            // Recompute the fee preview as the user types.
            // matrixBillingUpdatePreview is defined once in render()
            // and shared across all four bill forms.
            $('#matrix-billing-airtime [name=amount]').on('input change', function(){
                window.matrixBillingUpdatePreview('#matrix-billing-airtime', 'airtime');
            });
            $('#matrix-billing-airtime').on('submit', function(e){
                e.preventDefault(); var f=$(this), b=f.find('button[type=submit]');
                b.prop('disabled',true).text('Processing...');
                $.post(matrixMLM.ajaxUrl, {action:'matrix_fintava_buy_airtime',nonce:matrixMLM.nonce,phone:f.find('[name=phone]').val(),amount:f.find('[name=amount]').val(),network:f.find('[name=network]').val(),transaction_pin:(f.find('[name=transaction_pin]').val()||'')}, function(r){
                    var msg = window.matrixBillingExtractMessage(r);
                    if (r.success) {
                        // Show success inline INSTEAD of alert() +
                        // location.reload(). The reload looked like
                        // a silent page refresh once airtime actually
                        // started succeeding (post PR #276) — the
                        // user's "page refreshes and loads again"
                        // report. The new transaction WILL appear in
                        // "Recent Bill Payments" the next time the
                        // user refreshes the dashboard manually; the
                        // inline notice contains all the per-purchase
                        // details (amount + phone + fee disclosure)
                        // so they don't NEED to refresh to confirm it
                        // worked.
                        window.matrixBillingShowNotice('#matrix-billing-airtime', 'success', msg);
                        // Reset the form so a second purchase starts
                        // from a clean slate. Leave network as the
                        // user picked — they're likely to buy more
                        // airtime on the same network — but clear
                        // phone, amount, and PIN. Hide the fee
                        // preview so it doesn't show stale numbers
                        // for an empty amount.
                        f.find('[name=phone]').val('');
                        f.find('[name=amount]').val('');
                        f.find('[name=transaction_pin]').val('');
                        f.find('.matrix-fee-preview').hide();
                    } else {
                        window.matrixBillingShowNotice('#matrix-billing-airtime', 'error', msg);
                    }
                    b.prop('disabled',false).text('Buy Airtime');
                }).fail(function(){
                    window.matrixBillingShowNotice('#matrix-billing-airtime', 'error', 'Network error. Please check your connection and try again.');
                    b.prop('disabled',false).text('Buy Airtime');
                });
            });
        })(jQuery);
        </script>
    <?php }

    // =========================================================================
    // DATA
    // =========================================================================
    private function render_data() { ?>
        <h3><?php _e('Buy Data Bundle', 'matrix-mlm'); ?></h3>
        <form id="matrix-billing-data" class="matrix-form" data-bill-type="data">
            <div class="matrix-form-group">
                <label><?php _e('Network', 'matrix-mlm'); ?></label>
                <select name="network" id="data-network" required>
                    <option value=""><?php _e('-- Select --', 'matrix-mlm'); ?></option>
                    <option value="MTN">MTN</option>
                    <option value="GLO">GLO</option>
                    <option value="AIRTEL">Airtel</option>
                    <option value="9MOBILE">9mobile</option>
                </select>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Phone Number', 'matrix-mlm'); ?></label>
                <input type="tel" name="phone" required placeholder="08012345678" maxlength="11">
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Data Plan', 'matrix-mlm'); ?></label>
                <select name="plan_id" id="data-plan" required disabled>
                    <option value=""><?php _e('Select network first', 'matrix-mlm'); ?></option>
                </select>
            </div>
            <input type="hidden" name="amount" id="data-amount" value="0">
            <?php
            // Static "what is currently configured" caption — see
            // render_airtime() for the full rationale.
            echo $this->format_markup_caption('data');
            ?>
            <?php $this->render_fee_preview_block(); ?>
            <?php
            // Transaction PIN field (PR 2). See render_airtime() for
            // the full rationale on the shared 'bills' path key.
            echo Matrix_MLM_Transaction_Pin::render_field(get_current_user_id(), 'bills');
            ?>
            <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block" disabled><?php _e('Buy Data', 'matrix-mlm'); ?></button>
        </form>
        <script>
        (function($){
            $('#data-network').on('change', function(){
                var net=$(this).val(); if(!net) return;
                var sel=$('#data-plan'); sel.html('<option>Loading...</option>').prop('disabled',true);
                $.post(matrixMLM.ajaxUrl,{action:'matrix_fintava_list_data_bundles',nonce:matrixMLM.nonce,network:net},function(r){
                    sel.empty().append('<option value="">-- Select Plan --</option>');
                    if(r.success && r.data.bundles){
                        (Array.isArray(r.data.bundles)?r.data.bundles:Object.values(r.data.bundles)).forEach(function(b){
                            sel.append('<option value="'+b.plan_id+'" data-amount="'+(b.amount||b.price||0)+'">'+b.name+' - ₦'+(b.amount||b.price||0)+'</option>');
                        });
                    }
                    sel.prop('disabled',false);
                });
            });
            $('#data-plan').on('change',function(){
                var a=$(this).find(':selected').data('amount')||0;
                $('#data-amount').val(a);
                $('button[type=submit]').prop('disabled',!a);
                // Plan selection IS the amount picker for data —
                // recompute the fee preview every time the plan
                // changes. Airtime/electricity bind on `input`
                // because the user types directly; data and cable
                // bind here because the amount field is hidden.
                window.matrixBillingUpdatePreview('#matrix-billing-data', 'data');
            });
            $('#matrix-billing-data').on('submit',function(e){
                e.preventDefault(); var f=$(this),b=f.find('button'); b.prop('disabled',true).text('Processing...');
                $.post(matrixMLM.ajaxUrl,{action:'matrix_fintava_buy_data',nonce:matrixMLM.nonce,phone:f.find('[name=phone]').val(),plan_id:f.find('[name=plan_id]').val(),network:f.find('[name=network]').val(),amount:f.find('[name=amount]').val(),transaction_pin:(f.find('[name=transaction_pin]').val()||'')},function(r){
                    alert(window.matrixBillingExtractMessage(r));
                    if(r.success) location.reload(); else b.prop('disabled',false).text('Buy Data');
                }).fail(function(){
                    alert('Network error. Please check your connection and try again.');
                    b.prop('disabled',false).text('Buy Data');
                });
            });
        })(jQuery);
        </script>
    <?php }

    // =========================================================================
    // CABLE TV
    // =========================================================================
    private function render_cable() { ?>
        <h3><?php _e('Cable TV Subscription', 'matrix-mlm'); ?></h3>
        <form id="matrix-billing-cable" class="matrix-form" data-bill-type="cable">
            <div class="matrix-form-group">
                <label><?php _e('Provider', 'matrix-mlm'); ?></label>
                <select name="provider" id="cable-provider" required>
                    <option value=""><?php _e('Loading providers...', 'matrix-mlm'); ?></option>
                </select>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Smartcard / IUC Number', 'matrix-mlm'); ?></label>
                <input type="text" name="smartcard_number" required placeholder="e.g. 1234567890">
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Subscription Plan', 'matrix-mlm'); ?></label>
                <select name="plan_id" id="cable-plan" required disabled>
                    <option value=""><?php _e('Select provider first', 'matrix-mlm'); ?></option>
                </select>
            </div>
            <input type="hidden" name="amount" id="cable-amount" value="0">
            <?php
            // Static "what is currently configured" caption — see
            // render_airtime() for the full rationale.
            echo $this->format_markup_caption('cable');
            ?>
            <?php $this->render_fee_preview_block(); ?>
            <?php
            // Transaction PIN field (PR 2). See render_airtime() for
            // the full rationale on the shared 'bills' path key.
            echo Matrix_MLM_Transaction_Pin::render_field(get_current_user_id(), 'bills');
            ?>
            <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block" disabled><?php _e('Subscribe', 'matrix-mlm'); ?></button>
        </form>
        <script>
        (function($){
            // Load providers
            $.post(matrixMLM.ajaxUrl,{action:'matrix_fintava_list_cable_providers',nonce:matrixMLM.nonce},function(r){
                var sel=$('#cable-provider'); sel.empty().append('<option value="">-- Select Provider --</option>');
                if(r.success && r.data.providers){
                    (Array.isArray(r.data.providers)?r.data.providers:Object.values(r.data.providers)).forEach(function(p){
                        var name = typeof p==='string'?p:(p.name||p.provider||p);
                        var val = typeof p==='string'?p:(p.id||p.code||p.name||p);
                        sel.append('<option value="'+val+'">'+name+'</option>');
                    });
                }
            });
            $('#cable-provider').on('change',function(){
                var prov=$(this).val(); if(!prov) return;
                var sel=$('#cable-plan'); sel.html('<option>Loading...</option>').prop('disabled',true);
                $.post(matrixMLM.ajaxUrl,{action:'matrix_fintava_list_cable_plans',nonce:matrixMLM.nonce,provider:prov},function(r){
                    sel.empty().append('<option value="">-- Select Plan --</option>');
                    if(r.success && r.data.plans){
                        (Array.isArray(r.data.plans)?r.data.plans:Object.values(r.data.plans)).forEach(function(p){
                            sel.append('<option value="'+(p.plan_id||p.id)+'" data-amount="'+(p.amount||p.price||0)+'">'+(p.name||p.plan_name)+' - ₦'+(p.amount||p.price||0)+'</option>');
                        });
                    }
                    sel.prop('disabled',false);
                });
            });
            $('#cable-plan').on('change',function(){
                var a=$(this).find(':selected').data('amount')||0;
                $('#cable-amount').val(a);
                $('button[type=submit]').prop('disabled',!a);
                // Plan selection IS the amount picker for cable —
                // see render_data() for the rationale.
                window.matrixBillingUpdatePreview('#matrix-billing-cable', 'cable');
            });
            $('#matrix-billing-cable').on('submit',function(e){
                e.preventDefault(); var f=$(this),b=f.find('button'); b.prop('disabled',true).text('Processing...');
                $.post(matrixMLM.ajaxUrl,{action:'matrix_fintava_buy_cable',nonce:matrixMLM.nonce,smartcard_number:f.find('[name=smartcard_number]').val(),plan_id:f.find('[name=plan_id]').val(),provider:f.find('[name=provider]').val(),amount:f.find('[name=amount]').val(),transaction_pin:(f.find('[name=transaction_pin]').val()||'')},function(r){
                    alert(window.matrixBillingExtractMessage(r));
                    if(r.success) location.reload(); else b.prop('disabled',false).text('Subscribe');
                }).fail(function(){
                    alert('Network error. Please check your connection and try again.');
                    b.prop('disabled',false).text('Subscribe');
                });
            });
        })(jQuery);
        </script>
    <?php }

    // =========================================================================
    // ELECTRICITY
    // =========================================================================
    private function render_electricity() { ?>
        <h3><?php _e('Pay Electricity Bill', 'matrix-mlm'); ?></h3>
        <form id="matrix-billing-electricity" class="matrix-form" data-bill-type="electricity">
            <div class="matrix-form-group">
                <label><?php _e('Disco (Provider)', 'matrix-mlm'); ?></label>
                <select name="disco" id="elec-disco" required>
                    <option value=""><?php _e('Loading discos...', 'matrix-mlm'); ?></option>
                </select>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Meter Type', 'matrix-mlm'); ?></label>
                <select name="meter_type" required>
                    <option value="prepaid"><?php _e('Prepaid', 'matrix-mlm'); ?></option>
                    <option value="postpaid"><?php _e('Postpaid', 'matrix-mlm'); ?></option>
                </select>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Meter Number', 'matrix-mlm'); ?></label>
                <input type="text" name="meter_number" id="elec-meter" required placeholder="Enter meter number">
                <button type="button" class="matrix-btn matrix-btn-sm" id="verify-meter-btn" style="margin-top:6px;"><?php _e('Verify Meter', 'matrix-mlm'); ?></button>
                <div id="meter-info" style="display:none;margin-top:8px;padding:8px 12px;background:#ecfdf5;border-radius:6px;font-size:13px;color:#065f46;"></div>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Amount (₦)', 'matrix-mlm'); ?></label>
                <input type="number" name="amount" min="500" required placeholder="1000">
            </div>
            <?php
            // Static "what is currently configured" caption — see
            // render_airtime() for the full rationale.
            echo $this->format_markup_caption('electricity');
            ?>
            <?php $this->render_fee_preview_block(); ?>
            <?php
            // Transaction PIN field (PR 2). See render_airtime() for
            // the full rationale on the shared 'bills' path key.
            echo Matrix_MLM_Transaction_Pin::render_field(get_current_user_id(), 'bills');
            ?>
            <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block"><?php _e('Pay Electricity', 'matrix-mlm'); ?></button>
        </form>
        <script>
        (function($){
            $.post(matrixMLM.ajaxUrl,{action:'matrix_fintava_list_discos',nonce:matrixMLM.nonce},function(r){
                var sel=$('#elec-disco'); sel.empty().append('<option value="">-- Select Disco --</option>');
                if(r.success && r.data.discos){
                    (Array.isArray(r.data.discos)?r.data.discos:Object.values(r.data.discos)).forEach(function(d){
                        var name=typeof d==='string'?d:(d.name||d.disco||d);
                        var val=typeof d==='string'?d:(d.id||d.code||d.name||d);
                        sel.append('<option value="'+val+'">'+name+'</option>');
                    });
                }
            });
            $('#verify-meter-btn').on('click',function(){
                var btn=$(this); btn.prop('disabled',true).text('Verifying...');
                $.post(matrixMLM.ajaxUrl,{action:'matrix_fintava_verify_meter',nonce:matrixMLM.nonce,meter_number:$('#elec-meter').val(),disco:$('#elec-disco').val(),meter_type:$('[name=meter_type]').val()},function(r){
                    btn.prop('disabled',false).text('Verify Meter');
                    if(r.success){
                        // Use .text() (not .html()) plus the safe
                        // formatter — see matrixBillingFormatMeterInfo
                        // in render() for the rationale on why the
                        // previous implementation showed "[object
                        // Object]" for nested fields.
                        var info = window.matrixBillingFormatMeterInfo(r.data && r.data.meter);
                        if (info) { $('#meter-info').text(info).show(); }
                        else { $('#meter-info').text('Meter verified.').show(); }
                    } else { alert(window.matrixBillingExtractMessage(r, 'Could not verify meter. Please try again.')); }
                }).fail(function(){
                    btn.prop('disabled',false).text('Verify Meter');
                    alert('Network error verifying meter. Please check your connection and try again.');
                });
            });
            // Recompute the fee preview as the user types the amount.
            $('#matrix-billing-electricity [name=amount]').on('input change', function(){
                window.matrixBillingUpdatePreview('#matrix-billing-electricity', 'electricity');
            });
            $('#matrix-billing-electricity').on('submit',function(e){
                e.preventDefault(); var f=$(this),b=f.find('button[type=submit]'); b.prop('disabled',true).text('Processing...');
                $.post(matrixMLM.ajaxUrl,{action:'matrix_fintava_buy_electricity',nonce:matrixMLM.nonce,meter_number:f.find('[name=meter_number]').val(),amount:f.find('[name=amount]').val(),disco:f.find('[name=disco]').val(),meter_type:f.find('[name=meter_type]').val(),transaction_pin:(f.find('[name=transaction_pin]').val()||'')},function(r){
                    var msg = window.matrixBillingExtractMessage(r);
                    if(r.success){ alert(msg); location.reload(); } else { alert(msg); b.prop('disabled',false).text('Pay Electricity'); }
                }).fail(function(){
                    alert('Network error. Please check your connection and try again.');
                    b.prop('disabled',false).text('Pay Electricity');
                });
            });
        })(jQuery);
        </script>
    <?php }
}
