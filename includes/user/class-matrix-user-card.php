<?php
/**
 * User Card Management (Fintava Verve Card)
 * 
 * Allows users to request, link, activate, and view their physical Verve card.
 * Card type: STATIC_NO_ACCOUNT
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Card {

    public function render($user_id) {
        $fintava_card = new Matrix_MLM_Fintava_Card();
        $card = $fintava_card->get_user_card($user_id);
        $fintava = new Matrix_MLM_Fintava();
        $has_wallet = $fintava->user_has_wallet($user_id);
        $is_active = $fintava->is_active();
        ?>
        <h2><?php _e('Verve Card', 'matrix-mlm'); ?></h2>
        <p class="matrix-subtitle"><?php _e('Your physical Verve debit card linked to your Fintava wallet. Use it for POS, ATM, and online payments.', 'matrix-mlm'); ?></p>

        <?php if (!$is_active): ?>
        <div class="matrix-alert matrix-alert-warning"><?php _e('Card service is currently unavailable. Please contact support.', 'matrix-mlm'); ?></div>
        <?php elseif ($card): ?>
            <?php $this->render_card_details($card, $user_id); ?>
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
        .matrix-card-brand { font-size: 20px; font-weight: 700; letter-spacing: 2px; margin-bottom: 30px; }
        .matrix-card-number { font-size: 20px; letter-spacing: 3px; margin-bottom: 20px; }
        .matrix-card-footer { display: flex; justify-content: space-between; align-items: flex-end; }
        .matrix-card-name { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
        .matrix-card-type-badge { font-size: 10px; background: rgba(255,255,255,0.15); padding: 4px 8px; border-radius: 4px; }
        .matrix-card-status-row { display: flex; align-items: center; gap: 16px; margin: 20px 0; }
        .matrix-card-actions { display: flex; gap: 10px; flex-wrap: wrap; margin: 20px 0; }
        .matrix-card-timeline { margin: 24px 0; }
        .matrix-card-timeline-item { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-left: 3px solid #e5e7eb; padding-left: 16px; position: relative; }
        .matrix-card-timeline-item::before { content: ''; width: 12px; height: 12px; border-radius: 50%; background: #d1d5db; position: absolute; left: -7.5px; }
        .matrix-card-timeline-item.completed::before { background: #10b981; }
        .matrix-card-timeline-item.current::before { background: #4f46e5; box-shadow: 0 0 0 4px rgba(79,70,229,0.2); }
        .matrix-card-timeline-item.completed { border-left-color: #10b981; }
        </style>
        <?php
    }

    private function render_card_details($card, $user_id) {
        $last_four = $card->last_four ?: '****';
        $statuses_order = ['pending', 'processing', 'shipped', 'delivered', 'linked', 'active'];
        $current_index = array_search($card->status, $statuses_order);
        ?>

        <!-- Card Visual -->
        <div class="matrix-card-visual">
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
            <span class="matrix-badge matrix-badge-<?php echo esc_attr($card->status); ?>"><?php echo esc_html(ucfirst($card->status)); ?></span>
            <?php if ($card->card_id): ?>
            <small style="color: #6b7280;">ID: <?php echo esc_html($card->card_id); ?></small>
            <?php endif; ?>
        </div>

        <!-- Timeline -->
        <div class="matrix-card-timeline">
            <?php
            $steps = [
                'pending' => __('Card Requested', 'matrix-mlm'),
                'processing' => __('Processing', 'matrix-mlm'),
                'shipped' => __('Shipped', 'matrix-mlm'),
                'delivered' => __('Delivered', 'matrix-mlm'),
                'linked' => __('Linked to Wallet', 'matrix-mlm'),
                'active' => __('Active', 'matrix-mlm'),
            ];
            foreach ($steps as $key => $label):
                $step_index = array_search($key, $statuses_order);
                $class = '';
                if ($step_index < $current_index) $class = 'completed';
                elseif ($step_index === $current_index) $class = 'current';
            ?>
            <div class="matrix-card-timeline-item <?php echo $class; ?>">
                <span><?php echo esc_html($label); ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Actions based on status -->
        <div class="matrix-card-actions">
            <?php if (in_array($card->status, ['pending', 'processing', 'shipped'])): ?>
            <button class="matrix-btn matrix-btn-primary" onclick="matrixCheckCardStatus()"><?php _e('Refresh Status', 'matrix-mlm'); ?></button>
            <?php endif; ?>

            <?php if ($card->status === 'delivered'): ?>
            <button class="matrix-btn matrix-btn-primary" onclick="matrixLinkCard()"><?php _e('Link to Wallet', 'matrix-mlm'); ?></button>
            <?php endif; ?>

            <?php if ($card->status === 'linked'): ?>
            <button class="matrix-btn matrix-btn-primary" onclick="matrixActivateCard()"><?php _e('Activate Card', 'matrix-mlm'); ?></button>
            <?php endif; ?>

            <?php if ($card->status === 'active'): ?>
            <button class="matrix-btn matrix-btn-primary" onclick="matrixViewCardDetails()"><?php _e('View Card Details', 'matrix-mlm'); ?></button>
            <?php endif; ?>
        </div>

        <!-- Activation form (hidden by default) -->
        <div id="matrix-card-activate-form" style="display:none;" class="matrix-form-card">
            <h3><?php _e('Activate Your Card', 'matrix-mlm'); ?></h3>
            <p><?php _e('Enter the details from your physical card to activate it.', 'matrix-mlm'); ?></p>
            <form class="matrix-form" onsubmit="matrixSubmitActivation(event)">
                <div class="matrix-form-row">
                    <div class="matrix-form-group">
                        <label><?php _e('Card PIN (last 6 digits on card)', 'matrix-mlm'); ?></label>
                        <input type="password" name="pin" maxlength="6" required>
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('CVV (3 digits on back)', 'matrix-mlm'); ?></label>
                        <input type="password" name="cvv" maxlength="3" required>
                    </div>
                </div>
                <button type="submit" class="matrix-btn matrix-btn-primary"><?php _e('Activate', 'matrix-mlm'); ?></button>
            </form>
        </div>

        <!-- Card details display (hidden by default) -->
        <div id="matrix-card-details-display" style="display:none;" class="matrix-form-card">
            <h3><?php _e('Card Details', 'matrix-mlm'); ?></h3>
            <div id="matrix-card-details-content"></div>
        </div>

        <div class="matrix-form-card" style="margin-top: 20px;">
            <h3><?php _e('Card Information', 'matrix-mlm'); ?></h3>
            <table class="matrix-table">
                <tbody>
                    <tr><td><strong><?php _e('Card Type', 'matrix-mlm'); ?></strong></td><td>Physical Verve Card (STATIC_NO_ACCOUNT)</td></tr>
                    <tr><td><strong><?php _e('Brand', 'matrix-mlm'); ?></strong></td><td>Verve</td></tr>
                    <tr><td><strong><?php _e('Status', 'matrix-mlm'); ?></strong></td><td><?php echo ucfirst($card->status); ?></td></tr>
                    <tr><td><strong><?php _e('Requested', 'matrix-mlm'); ?></strong></td><td><?php echo date('F d, Y', strtotime($card->created_at)); ?></td></tr>
                    <?php if ($card->activated_at): ?>
                    <tr><td><strong><?php _e('Activated', 'matrix-mlm'); ?></strong></td><td><?php echo date('F d, Y', strtotime($card->activated_at)); ?></td></tr>
                    <?php endif; ?>
                    <?php if ($card->delivery_address): ?>
                    <tr><td><strong><?php _e('Delivery Address', 'matrix-mlm'); ?></strong></td><td><?php echo esc_html($card->delivery_address); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
        (function($) {
            'use strict';

            window.matrixCheckCardStatus = function() {
                $.ajax({
                    url: matrixMLM.ajaxUrl, type: 'POST',
                    data: { action: 'matrix_fintava_card_status', nonce: matrixMLM.nonce },
                    success: function(r) { if (r.success) { alert('Status: ' + r.data.card.status); location.reload(); } else { alert(r.data.message); } }
                });
            };

            window.matrixLinkCard = function() {
                if (!confirm('<?php _e("Link this card to your Fintava wallet?", "matrix-mlm"); ?>')) return;
                $.ajax({
                    url: matrixMLM.ajaxUrl, type: 'POST',
                    data: { action: 'matrix_fintava_link_card', nonce: matrixMLM.nonce },
                    success: function(r) { if (r.success) { alert(r.data.message); location.reload(); } else { alert(r.data.message); } }
                });
            };

            window.matrixActivateCard = function() {
                $('#matrix-card-activate-form').toggle();
            };

            window.matrixSubmitActivation = function(e) {
                e.preventDefault();
                var form = $(e.target);
                $.ajax({
                    url: matrixMLM.ajaxUrl, type: 'POST',
                    data: { action: 'matrix_fintava_activate_card', nonce: matrixMLM.nonce, pin: form.find('[name="pin"]').val(), cvv: form.find('[name="cvv"]').val() },
                    success: function(r) { if (r.success) { alert(r.data.message); location.reload(); } else { alert(r.data.message); } }
                });
            };

            window.matrixViewCardDetails = function() {
                $.ajax({
                    url: matrixMLM.ajaxUrl, type: 'POST',
                    data: { action: 'matrix_fintava_fetch_card', nonce: matrixMLM.nonce },
                    success: function(r) {
                        if (r.success) {
                            var card = r.data.card;
                            var html = '<table class="matrix-table"><tbody>';
                            for (var key in card) { html += '<tr><td><strong>' + key + '</strong></td><td>' + card[key] + '</td></tr>'; }
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

    private function render_request_form($user_id) {
        $user = get_userdata($user_id);
        $meta = Matrix_MLM_User::get_meta($user_id);
        ?>
        <div class="matrix-create-wallet-intro" style="background: #f5f3ff; border-color: #c4b5fd;">
            <h3 style="color: #5b21b6;"><?php _e('Request Your Physical Verve Card', 'matrix-mlm'); ?></h3>
            <p style="color: #6d28d9;"><?php _e('Get a physical Verve debit card linked to your Fintava wallet. Use it at ATMs, POS terminals, and for online payments anywhere Verve is accepted.', 'matrix-mlm'); ?></p>
            <ul style="margin: 8px 0; padding-left: 20px; font-size: 13px; color: #6d28d9;">
                <li><?php _e('Card Type: STATIC_NO_ACCOUNT (Verve)', 'matrix-mlm'); ?></li>
                <li><?php _e('Linked directly to your Fintava wallet balance', 'matrix-mlm'); ?></li>
                <li><?php _e('Physical card delivered to your address', 'matrix-mlm'); ?></li>
                <li><?php _e('Works at all ATMs and POS terminals in Nigeria', 'matrix-mlm'); ?></li>
            </ul>
        </div>

        <div class="matrix-form-card">
            <h3><?php _e('Delivery Details', 'matrix-mlm'); ?></h3>
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
                <div class="matrix-form-group">
                    <label><?php _e('Delivery Address', 'matrix-mlm'); ?> *</label>
                    <textarea name="address" rows="2" required placeholder="<?php _e('Full street address for card delivery', 'matrix-mlm'); ?>"><?php echo esc_textarea($meta->address ?? ''); ?></textarea>
                </div>
                <div class="matrix-form-row">
                    <div class="matrix-form-group">
                        <label><?php _e('City', 'matrix-mlm'); ?></label>
                        <input type="text" name="city" value="<?php echo esc_attr($meta->city ?? ''); ?>">
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('State', 'matrix-mlm'); ?></label>
                        <input type="text" name="state" value="<?php echo esc_attr($meta->state ?? ''); ?>">
                    </div>
                </div>
                <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block" id="request-card-btn">
                    <?php _e('Request Verve Card', 'matrix-mlm'); ?>
                </button>
            </form>
        </div>

        <script>
        (function($) {
            'use strict';
            $('#matrix-request-card-form').on('submit', function(e) {
                e.preventDefault();
                var form = $(this), btn = $('#request-card-btn');
                if (!confirm('<?php _e("Request a physical Verve card? It will be delivered to the address provided.", "matrix-mlm"); ?>')) return;
                btn.prop('disabled', true).text('<?php _e("Requesting...", "matrix-mlm"); ?>');
                $.ajax({
                    url: matrixMLM.ajaxUrl, type: 'POST',
                    data: {
                        action: 'matrix_fintava_request_card', nonce: matrixMLM.nonce,
                        first_name: form.find('[name="first_name"]').val(),
                        last_name: form.find('[name="last_name"]').val(),
                        address: form.find('[name="address"]').val(),
                        city: form.find('[name="city"]').val(),
                        state: form.find('[name="state"]').val()
                    },
                    success: function(r) {
                        if (r.success) { alert(r.data.message); location.reload(); }
                        else { alert(r.data.message); btn.prop('disabled', false).text('<?php _e("Request Verve Card", "matrix-mlm"); ?>'); }
                    },
                    error: function() { alert('<?php _e("Network error", "matrix-mlm"); ?>'); btn.prop('disabled', false).text('<?php _e("Request Verve Card", "matrix-mlm"); ?>'); }
                });
            });
        })(jQuery);
        </script>
        <?php
    }
}
