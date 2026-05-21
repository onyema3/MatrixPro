<?php if (!defined('ABSPATH')) exit;
$plan_engine = new Matrix_MLM_Plan_Engine();
$plans = $plan_engine->get_plans('active');
$currency = get_option('matrix_mlm_currency_symbol', '₦');
?>
<div class="matrix-plans-section">
    <div class="matrix-section-header">
        <h2><?php _e('Choose Your Matrix Plan', 'matrix-mlm'); ?></h2>
        <p><?php _e('Select a plan that suits your investment goals and start earning today.', 'matrix-mlm'); ?></p>
    </div>
    <div class="matrix-plans-grid">
        <?php foreach ($plans as $plan): 
            $level_commissions = json_decode($plan->level_commission, true);
            $max_members = 0;
            for ($i = 0; $i < $plan->depth; $i++) { $max_members += pow($plan->width, $i); }
        ?>
        <div class="matrix-plan-card">
            <div class="plan-header">
                <h3><?php echo esc_html($plan->name); ?></h3>
                <div class="plan-matrix-badge"><?php echo $plan->width; ?> x <?php echo $plan->depth; ?></div>
            </div>
            <div class="plan-price">
                <span class="price-amount"><?php echo $currency . number_format($plan->price, 0); ?></span>
                <span class="price-period"><?php _e('one-time', 'matrix-mlm'); ?></span>
            </div>
            <ul class="plan-features">
                <li><strong><?php echo $plan->width; ?></strong> <?php _e('direct legs per member', 'matrix-mlm'); ?></li>
                <li><strong><?php echo $plan->depth; ?></strong> <?php _e('levels deep', 'matrix-mlm'); ?></li>
                <li><strong><?php echo number_format($max_members); ?></strong> <?php _e('max members per matrix', 'matrix-mlm'); ?></li>
                <li><?php _e('Referral:', 'matrix-mlm'); ?> <strong><?php echo $currency . number_format($plan->referral_commission, 0); ?></strong></li>
                <li><?php _e('Completion Bonus:', 'matrix-mlm'); ?> <strong><?php echo $currency . number_format($plan->matrix_completion_bonus, 0); ?></strong></li>
                <?php if ($level_commissions): ?>
                <li><?php _e('Level Commissions:', 'matrix-mlm'); ?>
                    <small>
                    <?php foreach ($level_commissions as $level => $amount): ?>
                        L<?php echo $level; ?>: <?php echo $currency . number_format($amount, 0); ?><?php echo $level < count($level_commissions) ? ', ' : ''; ?>
                    <?php endforeach; ?>
                    </small>
                </li>
                <?php endif; ?>
            </ul>
            <div class="plan-actions">
                <?php if (is_user_logged_in()): ?>
                <button class="matrix-btn matrix-btn-primary matrix-btn-block" onclick="matrixJoinPlan(<?php echo $plan->id; ?>)"><?php _e('Join Now', 'matrix-mlm'); ?></button>
                <?php else: ?>
                <a href="<?php echo home_url('/matrix-register'); ?>" class="matrix-btn matrix-btn-primary matrix-btn-block"><?php _e('Register & Join', 'matrix-mlm'); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
