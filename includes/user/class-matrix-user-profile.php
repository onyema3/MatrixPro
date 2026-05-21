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
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $avatar_url = get_user_meta($user_id, 'matrix_avatar_url', true);
        ?>
        <h2><?php _e('Profile Settings', 'matrix-mlm'); ?></h2>

        <!-- Profile Header with Avatar -->
        <div class="matrix-form-card" style="text-align:center;padding:30px;">
            <div style="position:relative;display:inline-block;margin-bottom:16px;">
                <img src="<?php echo esc_url($avatar_url ?: get_avatar_url($user_id, ['size' => 120])); ?>" 
                     alt="<?php echo esc_attr($user->display_name); ?>" 
                     style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:4px solid #e5e7eb;" 
                     id="matrix-avatar-preview">
                <label for="matrix-avatar-upload" style="position:absolute;bottom:0;right:0;background:#4f46e5;color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;box-shadow:0 2px 4px rgba(0,0,0,0.2);">
                    &#9998;
                </label>
                <input type="file" id="matrix-avatar-upload" accept="image/*" style="display:none;">
            </div>
            <h3 style="margin:0 0 4px;"><?php echo esc_html($user->display_name); ?></h3>
            <p style="color:#6b7280;margin:0;font-size:14px;">@<?php echo esc_html($user->user_login); ?> &bull; <?php echo esc_html($meta->referral_code ?? ''); ?></p>
        </div>

        <!-- Personal Information -->
        <div class="matrix-form-card">
            <h3 style="margin-top:0;"><?php _e('Personal Information', 'matrix-mlm'); ?></h3>
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
                        <small style="color:#6b7280;"><?php _e('Contact admin to change email', 'matrix-mlm'); ?></small>
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('Phone', 'matrix-mlm'); ?></label>
                        <input type="tel" name="phone" value="<?php echo esc_attr($meta->phone ?? ''); ?>">
                    </div>
                </div>
                <div class="matrix-form-row">
                    <div class="matrix-form-group">
                        <label><?php _e('Date of Birth', 'matrix-mlm'); ?></label>
                        <input type="date" name="date_of_birth" value="<?php echo esc_attr(get_user_meta($user_id, 'matrix_date_of_birth', true)); ?>">
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('Gender', 'matrix-mlm'); ?></label>
                        <select name="gender">
                            <option value=""><?php _e('Prefer not to say', 'matrix-mlm'); ?></option>
                            <option value="male" <?php selected(get_user_meta($user_id, 'matrix_gender', true), 'male'); ?>><?php _e('Male', 'matrix-mlm'); ?></option>
                            <option value="female" <?php selected(get_user_meta($user_id, 'matrix_gender', true), 'female'); ?>><?php _e('Female', 'matrix-mlm'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Bio', 'matrix-mlm'); ?></label>
                    <textarea name="bio" rows="3" placeholder="<?php _e('Tell us a little about yourself...', 'matrix-mlm'); ?>"><?php echo esc_textarea(get_user_meta($user_id, 'matrix_bio', true)); ?></textarea>
                </div>

                <h3><?php _e('Address', 'matrix-mlm'); ?></h3>
                <div class="matrix-form-group">
                    <label><?php _e('Street Address', 'matrix-mlm'); ?></label>
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

                <h3><?php _e('Bank Details', 'matrix-mlm'); ?></h3>
                <p style="color:#6b7280;font-size:13px;margin-top:-8px;"><?php _e('Used for direct bank payouts (optional)', 'matrix-mlm'); ?></p>
                <div class="matrix-form-row">
                    <div class="matrix-form-group">
                        <label><?php _e('Bank Name', 'matrix-mlm'); ?></label>
                        <input type="text" name="bank_name" value="<?php echo esc_attr(get_user_meta($user_id, 'matrix_bank_name', true)); ?>">
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('Account Number', 'matrix-mlm'); ?></label>
                        <input type="text" name="bank_account" value="<?php echo esc_attr(get_user_meta($user_id, 'matrix_bank_account', true)); ?>">
                    </div>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Account Name', 'matrix-mlm'); ?></label>
                    <input type="text" name="bank_account_name" value="<?php echo esc_attr(get_user_meta($user_id, 'matrix_bank_account_name', true)); ?>">
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
                <div class="matrix-form-row">
                    <div class="matrix-form-group">
                        <label><?php _e('New Password', 'matrix-mlm'); ?></label>
                        <input type="password" name="new_password" required minlength="8">
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('Confirm Password', 'matrix-mlm'); ?></label>
                        <input type="password" name="confirm_password" required>
                    </div>
                </div>
                <button type="submit" class="matrix-btn matrix-btn-primary"><?php _e('Change Password', 'matrix-mlm'); ?></button>
            </form>
        </div>

        <script>
        document.getElementById('matrix-avatar-upload').addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) { alert('<?php _e('File must be under 2MB', 'matrix-mlm'); ?>'); return; }
            var formData = new FormData();
            formData.append('action', 'matrix_mlm_action');
            formData.append('matrix_action', 'upload_avatar');
            formData.append('nonce', matrixMLM.nonce);
            formData.append('avatar', file);
            fetch(matrixMLM.ajaxUrl, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(function(res) {
                    if (res.success) {
                        document.getElementById('matrix-avatar-preview').src = res.data.url;
                    } else {
                        alert(res.data.message || '<?php _e('Upload failed', 'matrix-mlm'); ?>');
                    }
                });
        });
        </script>
        <?php
    }
}
