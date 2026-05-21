<?php
/**
 * User Profile
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Profile {

    public function render($user_id) {
        $user = get_userdata($user_id);
        $meta = Matrix_MLM_User::get_meta($user_id);
        ?>
        <h2><?php _e('Profile Settings', 'matrix-mlm'); ?></h2>
        <div class="matrix-form-card">
            <form id="matrix-profile-form" class="matrix-form">
                <div class="matrix-form-row">
                    <div class="matrix-form-group">
                        <label><?php _e('First Name', 'matrix-mlm'); ?></label>
                        <input type="text" name="first_name" value="<?php echo esc_attr(get_user_meta($user_id, 'first_name', true)); ?>">
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('Last Name', 'matrix-mlm'); ?></label>
                        <input type="text" name="last_name" value="<?php echo esc_attr(get_user_meta($user_id, 'last_name', true)); ?>">
                    </div>
                </div>
                <div class="matrix-form-row">
                    <div class="matrix-form-group">
                        <label><?php _e('Email', 'matrix-mlm'); ?></label>
                        <input type="email" value="<?php echo esc_attr($user->user_email); ?>" disabled>
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('Phone', 'matrix-mlm'); ?></label>
                        <input type="tel" name="phone" value="<?php echo esc_attr($meta->phone ?? ''); ?>">
                    </div>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Address', 'matrix-mlm'); ?></label>
                    <textarea name="address" rows="2"><?php echo esc_textarea($meta->address ?? ''); ?></textarea>
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
                <div class="matrix-form-row">
                    <div class="matrix-form-group">
                        <label><?php _e('Country', 'matrix-mlm'); ?></label>
                        <input type="text" name="country" value="<?php echo esc_attr($meta->country ?? ''); ?>">
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('Zip Code', 'matrix-mlm'); ?></label>
                        <input type="text" name="zip_code" value="<?php echo esc_attr($meta->zip_code ?? ''); ?>">
                    </div>
                </div>
                <button type="submit" class="matrix-btn matrix-btn-primary"><?php _e('Update Profile', 'matrix-mlm'); ?></button>
            </form>
        </div>

        <h3><?php _e('Change Password', 'matrix-mlm'); ?></h3>
        <div class="matrix-form-card">
            <form id="matrix-password-form" class="matrix-form">
                <div class="matrix-form-group">
                    <label><?php _e('Current Password', 'matrix-mlm'); ?></label>
                    <input type="password" name="current_password" required>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('New Password', 'matrix-mlm'); ?></label>
                    <input type="password" name="new_password" required minlength="8">
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Confirm Password', 'matrix-mlm'); ?></label>
                    <input type="password" name="confirm_password" required>
                </div>
                <button type="submit" class="matrix-btn matrix-btn-primary"><?php _e('Change Password', 'matrix-mlm'); ?></button>
            </form>
        </div>
        <?php
    }
}
