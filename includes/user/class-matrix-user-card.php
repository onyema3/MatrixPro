<?php
/**
 * User Card Management (Fintava Verve Card)
 *
 * Three-step user flow for the pre-produced physical Verve card:
 *
 *   1. Create the card record on Fintava's side
 *      (POST /cards/physical/request).
 *   2. Activate it by entering the 16-digit PAN printed on the physical
 *      card. "Activate" is a server-side composite of PATCH /cards/link
 *      followed by PATCH /cards/activate; the user only types the PAN
 *      once. The PAN is forwarded to Fintava and discarded — only its
 *      last four digits are persisted locally for display.
 *   3. View card details on demand (GET /cards/fetch/{cardMapId}).
 *
 * Once active, the user can freeze the card (PATCH /cards/deactivate)
 * and reactivate a frozen card with the same PAN-entry form.
 *
 * Card type: STATIC_NO_ACCOUNT (Verve).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Card {

    public function render($user_id) {
        $fintava_card = new Matrix_MLM_Fintava_Card();
        $card         = $fintava_card->get_user_card($user_id);
        $fintava      = new Matrix_MLM_Fintava();
        $has_wallet   = $fintava->user_has_wallet($user_id);
        $is_active    = $fintava->is_active();
        ?>
        <h2><?php _e('Verve Card', 'matrix-mlm'); ?></h2>
        <p class="matrix-subtitle"><?php _e('Your physical Verve debit card linked to your Fintava wallet. Cards are pre-produced — create your card record, then enter the PAN printed on your physical card to activate it for ATM, POS, and online use.', 'matrix-mlm'); ?></p>

        <?php if (!$is_active): ?>
            <div class="matrix-alert matrix-alert-warning"><?php _e('Card service is currently unavailable. Please contact support.', 'matrix-mlm'); ?></div>
        <?php elseif ($card): ?>
            <?php $this->render_card_details($card); ?>
        <?php elseif (!$has_wallet): ?>
            <div class="matrix-alert matrix-alert-info">
                <?php _e('You need to create a Fintava wallet before requesting a card.', 'matrix-mlm'); ?>
                <a href="<?php echo Matrix_MLM_User_Dashboard::tab_url('wallet'); ?>" class="matrix-btn matrix-btn-primary" style="margin-left: 12px;"><?php _e('Create Wallet', 'matrix-mlm'); ?></a>
            </div>
        <?php else: ?>
            <?php $this->render_request_form($user_id); ?>
        <?php endif; ?>

        <style>
        .matrix-subtitle { color: #6b7280; margin: -10px 0 20px; font-size: 14px; }
        .matrix-card-visual {
            width: 380px; max-width: 100%; height: 220px;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            border-radius: 16px; padding: 28px; color: #fff;
            position: relative; overflow: hidden;
            box-shadow: 0 20px 40px -10px rgba(0,0,0,0.3);
            margin-bottom: 24px; font-family: 'Courier New', monospace;
        }
        .matrix-card-visual::before {
            content: ''; position: absolute; top: -30%; right: -20%;
            width: 200px; height: 200px; border-radius: 50%;
            background: rgba(255,255,255,0.05);
        }
        .matrix-card-visual::after {
            content: ''; position: absolute; bottom: -20%; left: -10%;
            width: 150px; height: 150px; border-radius: 50%;
            background: rgba(255,255,255,0.03);
        }
        .matrix-card-visual.is-frozen { filter: grayscale(0.6) brightness(0.8); }
        .matrix-card-brand  { font-size: 20px; font-weight: 700; letter-spacing: 2px; margin-bottom: 30px; }
        .matrix-card-number { font-size: 20px; letter-spacing: 3px; margin-bottom: 20px; }
        .matrix-card-footer { display: flex; justify-content: space-between; align-items: flex-end; }
        .matrix-card-name   { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
        .matrix-card-type-badge { font-size: 10px; background: rgba(255,255,255,0.15); padding: 4px 8px; border-radius: 4px; }
        .matrix-card-status-row { display: flex; align-items: center; gap: 16px; margin: 20px 0; }
        .matrix-card-actions { display: flex; gap: 10px; flex-wrap: wrap; margin: 20px 0; }
        .matrix-card-timeline { margin: 24px 0; }
        .matrix-card-timeline-item { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-left: 3px solid #e5e7eb; padding-left: 16px; position: relative; }
        .matrix-card-timeline-item::before { content: ''; width: 12px; height: 12px; border-radius: 50%; background: #d1d5db; position: absolute; left: -7.5px; }
        .matrix-card-timeline-item.completed::before { background: #10b981; }
        .matrix-card-timeline-item.current::before { background: #4f46e5; box-shadow: 0 0 0 4px rgba(79,70,229,0.2); }
        .matrix-card-timeline-item.completed { border-left-color: #10b981; }
        .matrix-pan-form input[type="text"] {
            font-family: 'Courier New', monospace; font-size: 18px; letter-spacing: 2px;
            text-align: center;
        }
        /* "View Card Details" panel — sensitive-data-aware layout. The
           raw Fintava response is mapped to friendly labels here, with
           PAN/CVV starting masked and revealed only on click. See the
           inline comments on matrixViewCardDetails for the threat model
           the auto-hide and visibilitychange listener address. */
        .matrix-cd-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; margin: 16px 0; }
        .matrix-cd-warn { background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 12px; margin-bottom: 16px; font-size: 13px; color: #92400e; line-height: 1.4; }
        .matrix-cd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .matrix-cd-grid > .matrix-cd-row.is-full { grid-column: 1 / -1; }
        .matrix-cd-row { background: #f9fafb; border: 1px solid #f3f4f6; border-radius: 8px; padding: 12px 14px; }
        .matrix-cd-row.is-flex { display: flex; align-items: flex-end; justify-content: space-between; gap: 10px; }
        .matrix-cd-row .matrix-cd-body { flex: 1; min-width: 0; }
        .matrix-cd-label { display: block; font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .matrix-cd-value { font-family: 'Courier New', monospace; font-size: 16px; color: #1f2937; word-break: break-all; line-height: 1.3; }
        .matrix-cd-value.is-pan { letter-spacing: 1.5px; font-size: 17px; }
        .matrix-cd-toggle { background: #eef2ff; color: #4f46e5; border: 1px solid #c7d2fe; cursor: pointer; font-size: 12px; font-weight: 600; padding: 6px 12px; border-radius: 6px; white-space: nowrap; }
        .matrix-cd-toggle:hover { background: #e0e7ff; }
        .matrix-cd-toggle.is-revealed { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        .matrix-cd-toggle.is-revealed:hover { background: #fee2e2; }
        .matrix-cd-extras { margin-top: 16px; }
        .matrix-cd-extras summary { cursor: pointer; color: #6b7280; font-size: 13px; padding: 6px 0; }
        .matrix-cd-extras summary:hover { color: #4f46e5; }
        .matrix-cd-extras-table { width: 100%; margin-top: 8px; border-collapse: collapse; }
        .matrix-cd-extras-table td { padding: 6px 10px; font-size: 12px; border-bottom: 1px solid #f3f4f6; word-break: break-all; }
        .matrix-cd-extras-table td:first-child { font-weight: 600; color: #374151; width: 40%; }
        .matrix-cd-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; padding-top: 16px; border-top: 1px solid #f3f4f6; }
        /* Print suppression: never include the details panel on a
           printed page or PDF export, even if the user accidentally
           hits CTRL+P while it is visible. The card visual at the top
           of the page is still allowed; only the unmasked-on-screen
           PAN/CVV panel is removed. */
        @media print { .matrix-cd-card { display: none !important; } }
        </style>
        <?php
    }

    /**
     * Status-driven UI for an existing card row.
     *
     * Three principal states the user actually sees:
     *   - pending           → "Activate Card"  (runs combined link+activate)
     *   - linked            → "Complete Activation" (recovery: link succeeded
     *                          but activate failed; only activate is retried)
     *   - active            → "View Details" + "Freeze Card"
     *   - frozen            → "Reactivate Card"
     *
     * inactive/failed/expired/blocked are surfaced as informational with a
     * "View Details" affordance and a contact-support nudge.
     */
    private function render_card_details($card) {
        $last_four         = $card->last_four ?: '****';
        $effective_status  = $card->status;
        $statuses_order    = ['pending', 'linked', 'active'];
        $current_index     = array_search(
            in_array($effective_status, $statuses_order, true) ? $effective_status : 'pending',
            $statuses_order,
            true
        );
        $is_frozen_or_term = in_array($effective_status, ['frozen', 'inactive', 'failed', 'expired', 'blocked'], true);
        ?>

        <!-- Card visual -->
        <div class="matrix-card-visual <?php echo $is_frozen_or_term ? 'is-frozen' : ''; ?>">
            <div class="matrix-card-brand">VERVE</div>
            <div class="matrix-card-number">**** **** **** <?php echo esc_html($last_four); ?></div>
            <div class="matrix-card-footer">
                <div>
                    <div class="matrix-card-name"><?php echo esc_html(wp_get_current_user()->display_name); ?></div>
                </div>
                <div class="matrix-card-type-badge">STATIC_NO_ACCOUNT</div>
            </div>
        </div>

        <!-- Status -->
        <div class="matrix-card-status-row">
            <strong><?php _e('Status:', 'matrix-mlm'); ?></strong>
            <span class="matrix-badge matrix-badge-<?php echo esc_attr($effective_status); ?>"><?php echo esc_html(ucfirst($effective_status)); ?></span>
            <?php if ($card->card_id): ?>
                <small style="color: #6b7280;">ID: <?php echo esc_html($card->card_id); ?></small>
            <?php endif; ?>
        </div>

        <!-- Timeline (only meaningful for the create→activate happy path) -->
        <?php if (in_array($effective_status, $statuses_order, true)): ?>
            <div class="matrix-card-timeline">
                <?php
                $steps = [
                    'pending' => __('Card Created', 'matrix-mlm'),
                    'linked'  => __('Linked to Wallet', 'matrix-mlm'),
                    'active'  => __('Active', 'matrix-mlm'),
                ];
                foreach ($steps as $key => $label):
                    $step_index = array_search($key, $statuses_order, true);
                    $class = '';
                    if ($step_index < $current_index) $class = 'completed';
                    elseif ($step_index === $current_index) $class = 'current';
                    ?>
                    <div class="matrix-card-timeline-item <?php echo esc_attr($class); ?>">
                        <span><?php echo esc_html($label); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="matrix-card-actions">
            <?php if ($effective_status === 'pending'): ?>
                <button type="button" class="matrix-btn matrix-btn-primary" onclick="matrixCardOpenPanForm('setup')">
                    <?php _e('Activate Card', 'matrix-mlm'); ?>
                </button>

            <?php elseif ($effective_status === 'linked'): ?>
                <?php /*
                    'linked' is reached two ways:
                      1. setup_card linked successfully but activate failed
                         (the genuine recovery case).
                      2. The local DB row says linked but Fintava doesn't
                         actually have it linked — happens to cards created
                         via the pre-API-rewrite code path which wrote
                         linked state without a real round-trip.

                    We can't tell those cases apart from the local row, so
                    the safe move is to run the full link+activate setup
                    again. setup_card tolerates Fintava's "already linked"
                    responses for case 1, and does the missing link
                    properly for case 2.
                */ ?>
                <button type="button" class="matrix-btn matrix-btn-primary" onclick="matrixCardOpenPanForm('setup')">
                    <?php _e('Activate Card', 'matrix-mlm'); ?>
                </button>
                <button type="button" class="matrix-btn matrix-btn-secondary" onclick="matrixViewCardDetails()">
                    <?php _e('View Card Details', 'matrix-mlm'); ?>
                </button>

            <?php elseif ($effective_status === 'active'): ?>
                <button type="button" class="matrix-btn matrix-btn-primary" onclick="matrixViewCardDetails()">
                    <?php _e('View Card Details', 'matrix-mlm'); ?>
                </button>
                <button type="button" class="matrix-btn matrix-btn-secondary" onclick="matrixCardOpenPanForm('deactivate')">
                    <?php _e('Freeze Card', 'matrix-mlm'); ?>
                </button>

            <?php elseif ($effective_status === 'frozen'): ?>
                <button type="button" class="matrix-btn matrix-btn-primary" onclick="matrixCardOpenPanForm('activate')">
                    <?php _e('Reactivate Card', 'matrix-mlm'); ?>
                </button>
                <button type="button" class="matrix-btn matrix-btn-secondary" onclick="matrixViewCardDetails()">
                    <?php _e('View Card Details', 'matrix-mlm'); ?>
                </button>

            <?php else: /* inactive / failed / expired / blocked */ ?>
                <button type="button" class="matrix-btn matrix-btn-secondary" onclick="matrixViewCardDetails()">
                    <?php _e('View Card Details', 'matrix-mlm'); ?>
                </button>
                <span style="color:#6b7280;font-size:13px;">
                    <?php _e('This card cannot be activated from the dashboard. Please contact support.', 'matrix-mlm'); ?>
                </span>
            <?php endif; ?>
        </div>

        <!--
            PAN-entry form. Re-used for setup (link+activate), activate-only,
            and deactivate. The action is set via matrixCardOpenPanForm()
            and decides which AJAX endpoint and confirm copy is used. The
            PAN itself is forwarded to Fintava and never persisted locally
            — only the last four digits, which the server derives from the
            entered PAN on success.
        -->
        <div id="matrix-card-pan-form" style="display:none;" class="matrix-form-card matrix-pan-form">
            <h3 id="matrix-card-pan-form-title"><?php _e('Enter Card PAN', 'matrix-mlm'); ?></h3>
            <p id="matrix-card-pan-form-desc" style="color: #6b7280;"><?php _e('Type the 16-digit number printed on the front of your physical card.', 'matrix-mlm'); ?></p>
            <form class="matrix-form" onsubmit="matrixSubmitCardPan(event)">
                <input type="hidden" name="card_action" value="">
                <div class="matrix-form-group">
                    <label><?php _e('Card Number (PAN)', 'matrix-mlm'); ?></label>
                    <input type="text" name="pan" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="0000 0000 0000 0000" required>
                </div>
                <button type="submit" class="matrix-btn matrix-btn-primary"><?php _e('Continue', 'matrix-mlm'); ?></button>
                <button type="button" class="matrix-btn matrix-btn-secondary" onclick="matrixHideCardPanForm()"><?php _e('Cancel', 'matrix-mlm'); ?></button>
            </form>
        </div>

        <!-- Card details panel, populated by /cards/fetch/{cardMapId} -->
        <div id="matrix-card-details-display" style="display:none;" class="matrix-form-card">
            <h3><?php _e('Card Details', 'matrix-mlm'); ?></h3>
            <div id="matrix-card-details-content"></div>
        </div>

        <!-- Card information summary -->
        <div class="matrix-form-card" style="margin-top: 20px;">
            <h3><?php _e('Card Information', 'matrix-mlm'); ?></h3>
            <table class="matrix-table">
                <tbody>
                    <tr><td><strong><?php _e('Card Type', 'matrix-mlm'); ?></strong></td><td>Physical Verve Card (STATIC_NO_ACCOUNT)</td></tr>
                    <tr><td><strong><?php _e('Brand', 'matrix-mlm'); ?></strong></td><td>Verve</td></tr>
                    <tr><td><strong><?php _e('Status', 'matrix-mlm'); ?></strong></td><td><?php echo esc_html(ucfirst($effective_status)); ?></td></tr>
                    <tr><td><strong><?php _e('Created', 'matrix-mlm'); ?></strong></td><td><?php echo esc_html(date('F d, Y', strtotime($card->created_at))); ?></td></tr>
                    <?php if ($card->activated_at): ?>
                        <tr><td><strong><?php _e('Activated', 'matrix-mlm'); ?></strong></td><td><?php echo esc_html(date('F d, Y', strtotime($card->activated_at))); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
        // jQuery-footer-race guard. Without this, the inline IIFE
        // throws ReferenceError at parse time on installs where
        // jQuery is deferred to the footer, none of the
        // matrixCardOpenPanForm / matrixSubmitCardPan /
        // matrixViewCardDetails globals get defined, and every
        // action button on this page silently does nothing when
        // clicked (the buttons are wired via inline onclick that
        // calls window.matrixCardOpenPanForm — which doesn't
        // exist if the IIFE never ran). Same polling pattern as
        // class-matrix-user-wallet.php's render_scripts_no_wallet
        // and the airtime form in class-matrix-user-billing.php —
        // see that airtime <script> for the full historical context.
        (function() {
            var attempts = 0;
            var maxAttempts = 200; // 200 * 50ms = 10s ceiling

            function whenJQueryReady(cb) {
                if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn !== 'undefined') {
                    // Synchronous dispatch — see the matching comment
                    // in class-matrix-user-billing.php's airtime
                    // whenJQueryReady for the full rationale.
                    // Particularly important on this file because the
                    // verve-card action buttons use inline onclick=
                    // attributes that call matrixCardOpenPanForm /
                    // matrixViewCardDetails — globals defined inside
                    // cb. Pre-fix, those globals weren't defined
                    // until DOMContentLoaded, so early clicks hit
                    // ReferenceError and silently no-op'd. The
                    // user-visible effect was "buttons don't work
                    // until I refresh".
                    cb(window.jQuery);
                    return;
                }
                if (++attempts > maxAttempts) {
                    if (window.console && console.error) {
                        console.error('[Matrix MLM] jQuery not loaded after 10s; card-management handlers not bound.');
                    }
                    return;
                }
                setTimeout(function() { whenJQueryReady(cb); }, 50);
            }

            whenJQueryReady(function($) {
            'use strict';

            // Map of action keys → {ajax action, dialog copy}. Lookup is by
            // the same key the button's onclick passes in (`setup`,
            // `activate`, `deactivate`).
            var ACTIONS = {
                setup: {
                    action:  'matrix_fintava_setup_card',
                    title:   '<?php echo esc_js(__('Activate Your Card', 'matrix-mlm')); ?>',
                    desc:    '<?php echo esc_js(__('Type the 16-digit number printed on the front of your physical card. We will link it to your wallet and activate it.', 'matrix-mlm')); ?>',
                    confirm: '<?php echo esc_js(__('Activate this card now?', 'matrix-mlm')); ?>'
                },
                activate: {
                    action:  'matrix_fintava_activate_card',
                    title:   '<?php echo esc_js(__('Complete Card Activation', 'matrix-mlm')); ?>',
                    desc:    '<?php echo esc_js(__('Enter your card PAN to finish activating it.', 'matrix-mlm')); ?>',
                    confirm: '<?php echo esc_js(__('Activate this card now?', 'matrix-mlm')); ?>'
                },
                deactivate: {
                    action:  'matrix_fintava_deactivate_card',
                    title:   '<?php echo esc_js(__('Freeze Your Card', 'matrix-mlm')); ?>',
                    desc:    '<?php echo esc_js(__('Enter your card PAN to confirm freezing the card. You can reactivate it later from this page.', 'matrix-mlm')); ?>',
                    confirm: '<?php echo esc_js(__('Freeze this card?', 'matrix-mlm')); ?>'
                }
            };

            // Reveal the PAN form, retargeted to the chosen action. Exposed
            // on `window` and called via inline onclick from the action
            // buttons. The earlier event-delegated form
            // (`$(document).on('click', '[data-pan-form-target]')`) was
            // racey: if any earlier inline script on the dashboard threw,
            // the IIFE never bound the handler and the buttons silently
            // did nothing. Inline onclick → globally-named function is the
            // pattern the rest of the dashboard uses (see
            // matrixViewCardDetails) and it's robust to that class of
            // failure.
            window.matrixCardOpenPanForm = function(target) {
                var cfg = ACTIONS[target];
                if (!cfg) return;
                $('#matrix-card-pan-form-title').text(cfg.title);
                $('#matrix-card-pan-form-desc').text(cfg.desc);
                $('#matrix-card-pan-form [name="card_action"]').val(cfg.action);
                $('#matrix-card-pan-form [name="pan"]').val('');
                $('#matrix-card-pan-form').show();
                $('#matrix-card-pan-form [name="pan"]').focus();
            };

            window.matrixHideCardPanForm = function() {
                $('#matrix-card-pan-form').hide();
            };

            window.matrixSubmitCardPan = function(e) {
                e.preventDefault();
                var form = $(e.target);
                var actionKey = form.find('[name="card_action"]').val();
                var pan = (form.find('[name="pan"]').val() || '').replace(/\D+/g, '');
                var cfg = null;
                Object.keys(ACTIONS).forEach(function(k) {
                    if (ACTIONS[k].action === actionKey) cfg = ACTIONS[k];
                });
                if (!cfg) return;

                if (pan.length < 12 || pan.length > 19) {
                    alert('<?php echo esc_js(__('Please enter a valid 16-digit card PAN.', 'matrix-mlm')); ?>');
                    return;
                }
                if (!confirm(cfg.confirm)) return;

                var btn = form.find('button[type="submit"]');
                btn.prop('disabled', true);

                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: { action: actionKey, nonce: matrixMLM.nonce, pan: pan },
                    success: function(r) {
                        if (r.success) { alert(r.data.message); location.reload(); }
                        else           { alert(r.data.message); btn.prop('disabled', false); }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error', 'matrix-mlm')); ?>');
                        btn.prop('disabled', false);
                    }
                });
            };

            // ============================================================
            // "View Card Details" panel
            // ============================================================
            //
            // Fintava's GET /cards/fetch/{cardMapId} returns a flat object
            // whose keys vary across tiers (cardNumber vs cardPan vs pan;
            // cvv vs cvv2 vs securityCode; expiryMonth+expiryYear vs
            // expiry; embossName vs cardName, etc.). The previous
            // implementation dumped that object verbatim into a <table>
            // with raw key labels, which was both ugly to look at AND
            // — more importantly — left the full PAN and CVV plaintext
            // on screen until the user reloaded the page. That made
            // shoulder-surfing trivial: anyone walking past their
            // monitor could read both card-not-present credentials.
            //
            // This rewrite addresses both:
            //
            //   - Field mapping. FIELD_MAP below maps canonical roles
            //     ("pan", "cvv", "expiry", ...) to ordered lists of
            //     Fintava key variants. cdPick() returns the first
            //     non-empty match, so the layout below stays stable
            //     regardless of which spelling Fintava sends today.
            //   - Friendly layout. PAN/expiry/CVV/holder/brand/type
            //     are rendered as labelled rows in a card-shaped grid,
            //     not a flat key/value table.
            //   - Sensitive-data masking. PAN, CVV, and PIN start
            //     MASKED with a per-field "Show" toggle. Revealing
            //     starts a 20s auto-mask timer (cdAutoHideMs); the
            //     field reverts on timeout, on click of "Hide", on
            //     dismissal of the panel, OR on visibilitychange
            //     (tab/window blur). Unmasked values live only in a
            //     closure-scoped object (cdSecrets), never in DOM
            //     attributes — so DevTools / View-Source can't trivially
            //     leak them either.
            //   - Print suppression. The whole panel is display:none
            //     in @media print so the user can't accidentally
            //     CTRL+P a plaintext PAN to a network printer or PDF.
            //   - Forward-compat. Any Fintava keys we don't have a
            //     mapping for appear in a collapsed "More fields"
            //     expander, so a Fintava-side schema change doesn't
            //     silently drop diagnostic data.

            // 20s is long enough for the user to copy / transcribe a
            // value once, short enough that a momentary distraction
            // doesn't leave the credentials sitting on screen. Tunable
            // here in one place.
            var cdAutoHideMs = 20000;

            // Closure-scoped registry of unmasked values. Populated by
            // cdRender(), cleared by matrixHideCardDetails().
            var cdSecrets = {};

            // Per-field auto-mask timer handles, keyed by element id so
            // re-revealing one field doesn't cancel another's countdown.
            var cdTimers = {};

            // First non-empty key wins on each role. Keep canonical
            // names (Fintava-current camelCase) first so the common
            // case is the fastest match.
            var FIELD_MAP = {
                pan:         ['cardNumber', 'cardPan', 'pan', 'card_number', 'card_pan', 'maskedPan'],
                cvv:         ['cvv', 'cvv2', 'securityCode', 'cvc', 'cardCvv'],
                pin:         ['cardPin', 'pin'],
                expiryMonth: ['expiryMonth', 'expiry_month', 'expMonth'],
                expiryYear:  ['expiryYear', 'expiry_year', 'expYear'],
                expiry:      ['expiry', 'expiryDate', 'expDate', 'expiry_date'],
                holder:      ['embossName', 'cardName', 'cardholder', 'holderName', 'cardHolder', 'card_holder'],
                brand:       ['cardBrand', 'brand'],
                type:        ['cardType', 'type'],
                status:      ['status', 'cardStatus']
            };

            function cdPick(raw, keys) {
                for (var i = 0; i < keys.length; i++) {
                    if (Object.prototype.hasOwnProperty.call(raw, keys[i])) {
                        var v = raw[keys[i]];
                        if (v !== null && v !== '' && typeof v !== 'undefined') {
                            return { key: keys[i], val: String(v) };
                        }
                    }
                }
                return null;
            }

            // Render the PAN with one space every four digits — the
            // standard ISO/IEC 7812 grouping that matches what's
            // embossed on the physical card. Falls back to the raw
            // string if it isn't digit-only, so an already-formatted
            // Fintava response is still readable.
            function cdFormatPan(s) {
                var digits = String(s || '').replace(/\D+/g, '');
                if (digits.length === 0) return String(s || '');
                return digits.replace(/(.{4})/g, '$1 ').trim();
            }

            function cdMaskPan(s) {
                var digits = String(s || '').replace(/\D+/g, '');
                if (digits.length < 4) return '\u2022\u2022\u2022\u2022 \u2022\u2022\u2022\u2022 \u2022\u2022\u2022\u2022 \u2022\u2022\u2022\u2022';
                return '\u2022\u2022\u2022\u2022 \u2022\u2022\u2022\u2022 \u2022\u2022\u2022\u2022 ' + digits.slice(-4);
            }

            function cdMaskShort(s) {
                return String(s || '').replace(/./g, '\u2022');
            }

            // Detect a value Fintava has already masked on the wire
            // (some tiers return "**** **** **** 1234" verbatim on
            // PAN). When that's the case there's nothing to reveal,
            // so we skip the toggle and the warning banner.
            function cdLooksMasked(s) {
                return /[*\u2022]/.test(String(s));
            }

            function cdEsc(s) {
                return String(s).replace(/[&<>"']/g, function(c) {
                    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
                });
            }

            // Mask handler — used by both the manual "Hide" toggle
            // and the auto-mask timer. kind is 'pan' or 'simple'
            // (CVV/PIN).
            function cdMaskField(fieldId, kind) {
                var $val = $('#' + fieldId);
                var raw  = cdSecrets[fieldId] || '';
                $val.text(kind === 'pan' ? cdMaskPan(raw) : cdMaskShort(raw))
                    .attr('data-state', 'masked');
                $('#' + fieldId + '-toggle')
                    .text('<?php echo esc_js(__('Show', 'matrix-mlm')); ?>')
                    .removeClass('is-revealed');
                if (cdTimers[fieldId]) {
                    clearTimeout(cdTimers[fieldId]);
                    delete cdTimers[fieldId];
                }
            }

            window.matrixCdToggleSensitive = function(btnEl, fieldId, kind) {
                var $val = $('#' + fieldId);
                var revealed = $val.attr('data-state') === 'shown';
                if (revealed) {
                    cdMaskField(fieldId, kind);
                    return;
                }
                var raw = cdSecrets[fieldId] || '';
                $val.text(kind === 'pan' ? cdFormatPan(raw) : raw)
                    .attr('data-state', 'shown');
                $(btnEl)
                    .text('<?php echo esc_js(__('Hide', 'matrix-mlm')); ?>')
                    .addClass('is-revealed');
                if (cdTimers[fieldId]) clearTimeout(cdTimers[fieldId]);
                cdTimers[fieldId] = setTimeout(function() {
                    cdMaskField(fieldId, kind);
                }, cdAutoHideMs);
            };

            window.matrixHideCardDetails = function() {
                Object.keys(cdTimers).forEach(function(k) { clearTimeout(cdTimers[k]); });
                cdTimers   = {};
                cdSecrets  = {};
                $('#matrix-card-details-display').hide();
                $('#matrix-card-details-content').empty();
            };

            // visibilitychange listener: re-mask any revealed sensitive
            // field as soon as the tab/window loses focus. This catches
            // the alt-tab and screen-share scenarios — the user looks
            // away briefly, returns, and finds the credentials masked
            // again rather than still on screen for whoever was
            // standing behind them. Namespaced (.matrixCd) so we don't
            // collide with other dashboard handlers on the same event.
            $(document).off('visibilitychange.matrixCd').on('visibilitychange.matrixCd', function() {
                if (!document.hidden) return;
                Object.keys(cdTimers).forEach(function(fieldId) {
                    var $el  = $('#' + fieldId);
                    var kind = $el.hasClass('is-pan') ? 'pan' : 'simple';
                    cdMaskField(fieldId, kind);
                });
            });

            // Build a structured row. opts.sensitive=true wires the
            // masking toggle; sensitive=false renders the value plainly.
            // Returns '' when value is empty so the caller can pass any
            // optional field through and have it skipped.
            function cdRow(label, value, opts) {
                opts = opts || {};
                if (value === null || typeof value === 'undefined' || value === '') return '';
                if (opts.sensitive) {
                    var fieldId = opts.fieldId;
                    var kind    = opts.kind || 'simple';
                    cdSecrets[fieldId] = value;
                    var initialMasked = (kind === 'pan') ? cdMaskPan(value) : cdMaskShort(value);
                    var valueClass    = 'matrix-cd-value' + (kind === 'pan' ? ' is-pan' : '');
                    var rowClass      = 'matrix-cd-row is-flex' + (opts.fullWidth ? ' is-full' : '');
                    return '' +
                        '<div class="' + rowClass + '">' +
                          '<div class="matrix-cd-body">' +
                            '<span class="matrix-cd-label">' + cdEsc(label) + '</span>' +
                            '<span class="' + valueClass + '" id="' + cdEsc(fieldId) + '" data-state="masked">' + cdEsc(initialMasked) + '</span>' +
                          '</div>' +
                          '<button type="button" class="matrix-cd-toggle" id="' + cdEsc(fieldId) + '-toggle" ' +
                            'onclick="matrixCdToggleSensitive(this, \'' + cdEsc(fieldId) + '\', \'' + cdEsc(kind) + '\')">' +
                            '<?php echo esc_js(__('Show', 'matrix-mlm')); ?>' +
                          '</button>' +
                        '</div>';
                }
                var rowClass2 = 'matrix-cd-row' + (opts.fullWidth ? ' is-full' : '');
                return '' +
                    '<div class="' + rowClass2 + '">' +
                      '<span class="matrix-cd-label">' + cdEsc(label) + '</span>' +
                      '<span class="matrix-cd-value">' + cdEsc(value) + '</span>' +
                    '</div>';
            }

            function cdRender(raw) {
                cdSecrets = {};
                Object.keys(cdTimers).forEach(function(k) { clearTimeout(cdTimers[k]); });
                cdTimers = {};

                var pan        = cdPick(raw, FIELD_MAP.pan);
                var cvv        = cdPick(raw, FIELD_MAP.cvv);
                var pin        = cdPick(raw, FIELD_MAP.pin);
                var holder     = cdPick(raw, FIELD_MAP.holder);
                var brand      = cdPick(raw, FIELD_MAP.brand);
                var type       = cdPick(raw, FIELD_MAP.type);
                var status     = cdPick(raw, FIELD_MAP.status);
                var expMM      = cdPick(raw, FIELD_MAP.expiryMonth);
                var expYY      = cdPick(raw, FIELD_MAP.expiryYear);
                var expFull    = cdPick(raw, FIELD_MAP.expiry);

                // Compose MM/YY when Fintava sends month + year split
                // (the common shape on /cards/fetch). Falls through to
                // a single 'expiry' string when the response is
                // pre-composed.
                var expiryStr = '';
                if (expMM && expYY) {
                    var mm = ('0' + String(expMM.val).replace(/\D+/g, '')).slice(-2);
                    var yy = String(expYY.val).replace(/\D+/g, '');
                    if (yy.length === 4) yy = yy.slice(-2);
                    expiryStr = mm + '/' + yy;
                } else if (expFull) {
                    expiryStr = String(expFull.val);
                }

                // Track which raw keys we've consumed so they don't
                // also appear in the "More fields" tail.
                var consumed = {};
                [pan, cvv, pin, holder, brand, type, status, expMM, expYY, expFull].forEach(function(p) {
                    if (p) consumed[p.key] = true;
                });

                var html = '';

                // Show the warning banner only when there's actually
                // something sensitive to reveal. Suppresses the panel
                // looking alarming on tiers where Fintava already
                // masked the PAN server-side.
                var hasSecrets =
                       (pan && !cdLooksMasked(pan.val))
                    || (cvv && !cdLooksMasked(cvv.val))
                    || (pin && !cdLooksMasked(pin.val));
                if (hasSecrets) {
                    html += '<div class="matrix-cd-warn">' +
                              cdEsc('<?php echo esc_js(__('These details are sensitive. Tap Show to reveal each one, then Hide when you are done. Anything you reveal auto-hides after 20 seconds, and any time you switch tabs.', 'matrix-mlm')); ?>') +
                            '</div>';
                }

                html += '<div class="matrix-cd-card">';

                // PAN — full-width, sensitive (unless Fintava already
                // masked it in the response).
                if (pan) {
                    if (cdLooksMasked(pan.val)) {
                        html += cdRow('<?php echo esc_js(__('Card Number', 'matrix-mlm')); ?>', pan.val, { fullWidth: true });
                    } else {
                        html += cdRow('<?php echo esc_js(__('Card Number', 'matrix-mlm')); ?>', pan.val, { sensitive: true, fieldId: 'cd-pan', kind: 'pan', fullWidth: true });
                    }
                }

                html += '<div class="matrix-cd-grid">';

                html += cdRow('<?php echo esc_js(__('Expiry', 'matrix-mlm')); ?>', expiryStr);

                if (cvv) {
                    if (cdLooksMasked(cvv.val)) {
                        html += cdRow('<?php echo esc_js(__('CVV', 'matrix-mlm')); ?>', cvv.val);
                    } else {
                        html += cdRow('<?php echo esc_js(__('CVV', 'matrix-mlm')); ?>', cvv.val, { sensitive: true, fieldId: 'cd-cvv', kind: 'simple' });
                    }
                }

                if (pin) {
                    if (cdLooksMasked(pin.val)) {
                        html += cdRow('<?php echo esc_js(__('PIN', 'matrix-mlm')); ?>', pin.val);
                    } else {
                        html += cdRow('<?php echo esc_js(__('PIN', 'matrix-mlm')); ?>', pin.val, { sensitive: true, fieldId: 'cd-pin', kind: 'simple' });
                    }
                }

                html += cdRow('<?php echo esc_js(__('Cardholder', 'matrix-mlm')); ?>', holder ? holder.val : '');
                html += cdRow('<?php echo esc_js(__('Brand', 'matrix-mlm')); ?>',      brand  ? brand.val  : '');
                html += cdRow('<?php echo esc_js(__('Type', 'matrix-mlm')); ?>',       type   ? type.val   : '');
                html += cdRow('<?php echo esc_js(__('Status', 'matrix-mlm')); ?>',     status ? status.val : '');

                html += '</div>'; // .matrix-cd-grid

                // Forward-compat tail: anything we didn't recognise
                // goes into a collapsed expander so support has the
                // raw payload available without it cluttering the
                // primary view.
                var extras = [];
                Object.keys(raw).forEach(function(key) {
                    if (consumed[key]) return;
                    var val = raw[key];
                    if (val === null || typeof val === 'undefined' || val === '') return;
                    if (typeof val === 'object') val = JSON.stringify(val);
                    extras.push({ key: key, val: String(val) });
                });
                if (extras.length) {
                    html += '<details class="matrix-cd-extras">' +
                              '<summary>' + cdEsc('<?php echo esc_js(__('More fields from Fintava', 'matrix-mlm')); ?>') + '</summary>' +
                              '<table class="matrix-cd-extras-table"><tbody>';
                    extras.forEach(function(e) {
                        html += '<tr><td>' + cdEsc(e.key) + '</td><td>' + cdEsc(e.val) + '</td></tr>';
                    });
                    html += '</tbody></table></details>';
                }

                html += '<div class="matrix-cd-actions">' +
                          '<button type="button" class="matrix-btn matrix-btn-secondary" onclick="matrixHideCardDetails()">' +
                            cdEsc('<?php echo esc_js(__('Done', 'matrix-mlm')); ?>') +
                          '</button>' +
                        '</div>';

                html += '</div>'; // .matrix-cd-card

                $('#matrix-card-details-content').html(html);
                $('#matrix-card-details-display').show();
            }

            window.matrixViewCardDetails = function() {
                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: { action: 'matrix_fintava_fetch_card', nonce: matrixMLM.nonce },
                    success: function(r) {
                        if (r.success) { cdRender(r.data.card || {}); }
                        else           { alert(r.data.message); }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error', 'matrix-mlm')); ?>');
                    }
                });
            };
            }); // whenJQueryReady
        })(); // poll-for-jQuery IIFE
        </script>
        <?php
    }

    /**
     * Form for the first step: create the card record on Fintava's side.
     *
     * We collect cardholder first/last name only — Fintava's
     * /cards/physical/request endpoint takes (cardBrand, cardName,
     * accountNumber, cardType). KYC details (address, BVN, etc.) live on
     * the Fintava customer record, which already exists for any user with
     * a wallet (created via POST /create/customer at wallet-provisioning
     * time). Address fields previously collected here were silently
     * discarded by the API.
     */
    private function render_request_form($user_id) {
        ?>
        <div class="matrix-create-wallet-intro" style="background: #f5f3ff; border-color: #c4b5fd;">
            <h3 style="color: #5b21b6;"><?php _e('Create Your Verve Card', 'matrix-mlm'); ?></h3>
            <p style="color: #6d28d9;"><?php _e('Your physical Verve debit card has already been produced. Create the card record now, then enter the PAN printed on the card to activate it for ATM, POS, and online payments.', 'matrix-mlm'); ?></p>
            <ul style="margin: 8px 0; padding-left: 20px; font-size: 13px; color: #6d28d9;">
                <li><?php _e('Card Type: STATIC_NO_ACCOUNT (Verve)', 'matrix-mlm'); ?></li>
                <li><?php _e('Linked directly to your Fintava wallet balance', 'matrix-mlm'); ?></li>
                <li><?php _e('Two steps: create the card record, then activate with the PAN on your physical card', 'matrix-mlm'); ?></li>
                <li><?php _e('Works at all ATMs and POS terminals in Nigeria', 'matrix-mlm'); ?></li>
            </ul>
        </div>

        <div class="matrix-form-card">
            <h3><?php _e('Cardholder Name', 'matrix-mlm'); ?></h3>
            <p style="color: #6b7280; font-size: 13px; margin-top: -6px;"><?php _e('This is the name that will be embossed on the card record. It should match the name on your physical card.', 'matrix-mlm'); ?></p>
            <form id="matrix-request-card-form" class="matrix-form">
                <div class="matrix-form-row">
                    <div class="matrix-form-group">
                        <label><?php _e('First Name', 'matrix-mlm'); ?> *</label>
                        <input type="text" name="first_name" required value="<?php echo esc_attr(get_user_meta($user_id, 'first_name', true)); ?>">
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('Last Name', 'matrix-mlm'); ?> *</label>
                        <input type="text" name="last_name" required value="<?php echo esc_attr(get_user_meta($user_id, 'last_name', true)); ?>">
                    </div>
                </div>
                <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block" id="request-card-btn">
                    <?php _e('Create Verve Card', 'matrix-mlm'); ?>
                </button>
            </form>
        </div>

        <script>
        // jQuery-footer-race guard. See the corresponding wrapper
        // on the larger render_card_details <script> earlier in
        // this file for the full rationale. Without it, the
        // 'Create Verve Card' submit handler never binds and the
        // form falls back to a native HTML submit (page reload,
        // no card created).
        (function() {
            var attempts = 0;
            var maxAttempts = 200; // 200 * 50ms = 10s ceiling

            function whenJQueryReady(cb) {
                if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn !== 'undefined') {
                    // Synchronous dispatch — see the matching comment
                    // in class-matrix-user-billing.php's airtime
                    // whenJQueryReady for the full rationale.
                    // Particularly important on this file because the
                    // verve-card action buttons use inline onclick=
                    // attributes that call matrixCardOpenPanForm /
                    // matrixViewCardDetails — globals defined inside
                    // cb. Pre-fix, those globals weren't defined
                    // until DOMContentLoaded, so early clicks hit
                    // ReferenceError and silently no-op'd. The
                    // user-visible effect was "buttons don't work
                    // until I refresh".
                    cb(window.jQuery);
                    return;
                }
                if (++attempts > maxAttempts) {
                    if (window.console && console.error) {
                        console.error('[Matrix MLM] jQuery not loaded after 10s; create-card handler not bound.');
                    }
                    return;
                }
                setTimeout(function() { whenJQueryReady(cb); }, 50);
            }

            whenJQueryReady(function($) {
            'use strict';
            // Delegated on document for the same DOM-timing reason
            // documented in class-matrix-user-wallet.php's render_scripts():
            // direct binding races the matched form's DOM arrival on
            // stacks with deferred jQuery / Rocket Loader / WP Rocket /
            // FlyingPress / Astra / GeneratePress / OceanWP, etc., and
            // silently no-op's. Symptom: 'Create Verve Card form
            // doesn't work until I refresh.'
            $(document).on('submit', '#matrix-request-card-form', function(e) {
                e.preventDefault();
                var form = $(this), btn = $('#request-card-btn');
                if (!confirm('<?php echo esc_js(__('Create your Verve card record now? You will activate it with the PAN on your physical card in the next step.', 'matrix-mlm')); ?>')) return;
                btn.prop('disabled', true).text('<?php echo esc_js(__('Creating...', 'matrix-mlm')); ?>');
                $.ajax({
                    url: matrixMLM.ajaxUrl, type: 'POST',
                    data: {
                        action:     'matrix_fintava_request_card',
                        nonce:      matrixMLM.nonce,
                        first_name: form.find('[name="first_name"]').val(),
                        last_name:  form.find('[name="last_name"]').val()
                    },
                    success: function(r) {
                        if (r.success) { alert(r.data.message); location.reload(); }
                        else           { alert(r.data.message); btn.prop('disabled', false).text('<?php echo esc_js(__('Create Verve Card', 'matrix-mlm')); ?>'); }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error', 'matrix-mlm')); ?>');
                        btn.prop('disabled', false).text('<?php echo esc_js(__('Create Verve Card', 'matrix-mlm')); ?>');
                    }
                });
            });
            }); // whenJQueryReady
        })(); // poll-for-jQuery IIFE
        </script>
        <?php
    }
}
