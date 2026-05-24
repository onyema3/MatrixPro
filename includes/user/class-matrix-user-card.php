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
                <button type="button" class="matrix-btn matrix-btn-primary" data-pan-form-target="setup">
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
                <button type="button" class="matrix-btn matrix-btn-primary" data-pan-form-target="setup">
                    <?php _e('Activate Card', 'matrix-mlm'); ?>
                </button>
                <button type="button" class="matrix-btn matrix-btn-secondary" onclick="matrixViewCardDetails()">
                    <?php _e('View Card Details', 'matrix-mlm'); ?>
                </button>

            <?php elseif ($effective_status === 'active'): ?>
                <button type="button" class="matrix-btn matrix-btn-primary" onclick="matrixViewCardDetails()">
                    <?php _e('View Card Details', 'matrix-mlm'); ?>
                </button>
                <button type="button" class="matrix-btn matrix-btn-secondary" data-pan-form-target="deactivate">
                    <?php _e('Freeze Card', 'matrix-mlm'); ?>
                </button>

            <?php elseif ($effective_status === 'frozen'): ?>
                <button type="button" class="matrix-btn matrix-btn-primary" data-pan-form-target="activate">
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
            and deactivate. The action is set by the button's
            data-pan-form-target attribute and decides which AJAX endpoint
            and confirm copy is used. The PAN itself is forwarded to Fintava
            and never persisted locally — only the last four digits, which
            the server derives from the entered PAN on success.
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
        (function($) {
            'use strict';

            // Map data-pan-form-target → { ajax action, button label, confirm copy }
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

            // Reveal the PAN form, retargeted to the chosen action.
            $(document).on('click', '[data-pan-form-target]', function() {
                var target = $(this).data('pan-form-target');
                var cfg = ACTIONS[target];
                if (!cfg) return;
                $('#matrix-card-pan-form-title').text(cfg.title);
                $('#matrix-card-pan-form-desc').text(cfg.desc);
                $('#matrix-card-pan-form [name="card_action"]').val(cfg.action);
                $('#matrix-card-pan-form [name="pan"]').val('');
                $('#matrix-card-pan-form').show();
                $('#matrix-card-pan-form [name="pan"]').focus();
            });

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

            window.matrixViewCardDetails = function() {
                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: { action: 'matrix_fintava_fetch_card', nonce: matrixMLM.nonce },
                    success: function(r) {
                        if (r.success) {
                            var card = r.data.card;
                            var html = '<table class="matrix-table"><tbody>';
                            for (var key in card) {
                                if (!Object.prototype.hasOwnProperty.call(card, key)) continue;
                                var val = card[key];
                                if (val !== null && typeof val === 'object') val = JSON.stringify(val);
                                html += '<tr><td><strong>' + key + '</strong></td><td>' + val + '</td></tr>';
                            }
                            html += '</tbody></table>';
                            $('#matrix-card-details-content').html(html);
                            $('#matrix-card-details-display').show();
                        } else { alert(r.data.message); }
                    }
                });
            };
        })(jQuery);
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
        (function($) {
            'use strict';
            $('#matrix-request-card-form').on('submit', function(e) {
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
        })(jQuery);
        </script>
        <?php
    }
}
