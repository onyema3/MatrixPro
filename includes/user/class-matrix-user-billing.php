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
        $fintava = new Matrix_MLM_Fintava();
        $has_fintava_wallet = $fintava->user_has_wallet($user_id);
        ?>
        <h2><?php _e('Bill Payments', 'matrix-mlm'); ?></h2>
        <p class="matrix-subtitle"><?php _e('Buy airtime, data bundles, cable TV subscriptions, and pay electricity bills. All payments are debited from your Fintava virtual wallet.', 'matrix-mlm'); ?></p>

        <?php if (!$has_fintava_wallet): ?>
        <div class="matrix-alert matrix-alert-warning">
            <?php _e('You need a Fintava virtual wallet to pay bills. All bill payments are debited directly from your Fintava wallet balance.', 'matrix-mlm'); ?>
            <a href="<?php echo Matrix_MLM_User_Dashboard::tab_url('wallet'); ?>" class="matrix-btn matrix-btn-primary" style="margin-left: 12px;"><?php _e('Create Fintava Wallet', 'matrix-mlm'); ?></a>
        </div>
        <?php else: ?>

        <div class="matrix-info-box" style="margin-bottom:16px;">
            <p><strong><?php _e('Payment Source:', 'matrix-mlm'); ?></strong> <?php _e('Fintava Virtual Wallet', 'matrix-mlm'); ?></p>
            <p style="font-size:12px;color:#6b7280;"><?php _e('All bill payments are charged to your Fintava wallet. Ensure your Fintava wallet has sufficient balance.', 'matrix-mlm'); ?></p>
        </div>

        <!-- Service Tabs -->
        <div class="matrix-billing-tabs">
            <a href="<?php echo home_url('/matrix-dashboard/?tab=billing&service=airtime'); ?>" class="<?php echo $sub_tab === 'airtime' ? 'active' : ''; ?>"><?php _e('Airtime', 'matrix-mlm'); ?></a>
            <a href="<?php echo home_url('/matrix-dashboard/?tab=billing&service=data'); ?>" class="<?php echo $sub_tab === 'data' ? 'active' : ''; ?>"><?php _e('Data', 'matrix-mlm'); ?></a>
            <a href="<?php echo home_url('/matrix-dashboard/?tab=billing&service=cable'); ?>" class="<?php echo $sub_tab === 'cable' ? 'active' : ''; ?>"><?php _e('Cable TV', 'matrix-mlm'); ?></a>
            <a href="<?php echo home_url('/matrix-dashboard/?tab=billing&service=electricity'); ?>" class="<?php echo $sub_tab === 'electricity' ? 'active' : ''; ?>"><?php _e('Electricity', 'matrix-mlm'); ?></a>
        </div>

        <div class="matrix-form-card">
        <?php
        switch ($sub_tab) {
            case 'data': $this->render_data(); break;
            case 'cable': $this->render_cable(); break;
            case 'electricity': $this->render_electricity(); break;
            default: $this->render_airtime(); break;
        }
        ?>
        </div>

        <!-- Transaction History -->
        <?php if (!empty($history)): ?>
        <h3 style="margin-top:24px;"><?php _e('Recent Bill Payments', 'matrix-mlm'); ?></h3>
        <table class="matrix-table">
            <thead><tr><th><?php _e('Date', 'matrix-mlm'); ?></th><th><?php _e('Type', 'matrix-mlm'); ?></th><th><?php _e('Amount', 'matrix-mlm'); ?></th><th><?php _e('Details', 'matrix-mlm'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($history as $tx):
                    $details = json_decode($tx->details, true);
                ?>
                <tr>
                    <td><?php echo date('M d, Y H:i', strtotime($tx->created_at)); ?></td>
                    <td><span class="matrix-badge"><?php echo ucfirst($tx->type); ?></span></td>
                    <td><?php echo $currency . number_format($tx->amount, 2); ?></td>
                    <td><small><?php echo esc_html(implode(' | ', array_filter($details ?? []))); ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php endif; // end has_fintava_wallet check ?>

        <style>
        .matrix-subtitle { color: #6b7280; margin: -10px 0 20px; font-size: 14px; }
        .matrix-billing-tabs { display: flex; gap: 0; margin-bottom: 0; border-bottom: 2px solid #e5e7eb; }
        .matrix-billing-tabs a { padding: 10px 20px; text-decoration: none; color: #6b7280; font-weight: 500; font-size: 14px; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all .2s; }
        .matrix-billing-tabs a.active { color: #4f46e5; border-bottom-color: #4f46e5; }
        .matrix-billing-tabs a:hover { color: #4f46e5; }
        </style>
        <?php
    }

    // =========================================================================
    // AIRTIME
    // =========================================================================
    private function render_airtime() { ?>
        <h3><?php _e('Buy Airtime', 'matrix-mlm'); ?></h3>
        <form id="matrix-billing-airtime" class="matrix-form">
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
            <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block"><?php _e('Buy Airtime', 'matrix-mlm'); ?></button>
        </form>
        <script>
        (function($){
            $('#matrix-billing-airtime').on('submit', function(e){
                e.preventDefault(); var f=$(this), b=f.find('button');
                b.prop('disabled',true).text('Processing...');
                $.post(matrixMLM.ajaxUrl, {action:'matrix_fintava_buy_airtime',nonce:matrixMLM.nonce,phone:f.find('[name=phone]').val(),amount:f.find('[name=amount]').val(),network:f.find('[name=network]').val()}, function(r){
                    alert(r.success?r.data.message:r.data.message); if(r.success) location.reload(); else b.prop('disabled',false).text('Buy Airtime');
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
        <form id="matrix-billing-data" class="matrix-form">
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
            $('#data-plan').on('change',function(){ var a=$(this).find(':selected').data('amount')||0; $('#data-amount').val(a); $('button[type=submit]').prop('disabled',!a); });
            $('#matrix-billing-data').on('submit',function(e){
                e.preventDefault(); var f=$(this),b=f.find('button'); b.prop('disabled',true).text('Processing...');
                $.post(matrixMLM.ajaxUrl,{action:'matrix_fintava_buy_data',nonce:matrixMLM.nonce,phone:f.find('[name=phone]').val(),plan_id:f.find('[name=plan_id]').val(),network:f.find('[name=network]').val(),amount:f.find('[name=amount]').val()},function(r){
                    alert(r.success?r.data.message:r.data.message); if(r.success) location.reload(); else b.prop('disabled',false).text('Buy Data');
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
        <form id="matrix-billing-cable" class="matrix-form">
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
            $('#cable-plan').on('change',function(){ var a=$(this).find(':selected').data('amount')||0; $('#cable-amount').val(a); $('button[type=submit]').prop('disabled',!a); });
            $('#matrix-billing-cable').on('submit',function(e){
                e.preventDefault(); var f=$(this),b=f.find('button'); b.prop('disabled',true).text('Processing...');
                $.post(matrixMLM.ajaxUrl,{action:'matrix_fintava_buy_cable',nonce:matrixMLM.nonce,smartcard_number:f.find('[name=smartcard_number]').val(),plan_id:f.find('[name=plan_id]').val(),provider:f.find('[name=provider]').val(),amount:f.find('[name=amount]').val()},function(r){
                    alert(r.success?r.data.message:r.data.message); if(r.success) location.reload(); else b.prop('disabled',false).text('Subscribe');
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
        <form id="matrix-billing-electricity" class="matrix-form">
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
                        var m=r.data.meter; var info=''; for(var k in m){info+=k+': '+m[k]+' | ';}
                        $('#meter-info').html(info).show();
                    } else { alert(r.data.message); }
                });
            });
            $('#matrix-billing-electricity').on('submit',function(e){
                e.preventDefault(); var f=$(this),b=f.find('button[type=submit]'); b.prop('disabled',true).text('Processing...');
                $.post(matrixMLM.ajaxUrl,{action:'matrix_fintava_buy_electricity',nonce:matrixMLM.nonce,meter_number:f.find('[name=meter_number]').val(),amount:f.find('[name=amount]').val(),disco:f.find('[name=disco]').val(),meter_type:f.find('[name=meter_type]').val()},function(r){
                    if(r.success){ alert(r.data.message); location.reload(); } else { alert(r.data.message); b.prop('disabled',false).text('Pay Electricity'); }
                });
            });
        })(jQuery);
        </script>
    <?php }
}
