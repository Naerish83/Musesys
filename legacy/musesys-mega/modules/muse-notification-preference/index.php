<?php
/**
 * Plugin Name: Muse Notification Preference
 * Description: Adds "Preferred Update Method" to User Profiles and Checkout/Account forms.
 * Version: 1.0.0
 * Author: MuseSystems
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Muse_Notification_Preference {

    public static function init(): void {
        // Frontend: Account Details
        add_action('woocommerce_edit_account_form', [__CLASS__, 'render_account_field']);
        add_action('woocommerce_save_account_details', [__CLASS__, 'save_account_field']);

        // Backend: User Profile
        add_action('show_user_profile', [__CLASS__, 'render_admin_field']);
        add_action('edit_user_profile', [__CLASS__, 'render_admin_field']);
        add_action('personal_options_update', [__CLASS__, 'save_admin_field']);
        add_action('edit_user_profile_update', [__CLASS__, 'save_admin_field']);

        // Backend: Order Edit Screen
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'render_order_field']);
    }

    /**
     * Render field on My Account > Account Details.
     */
    public static function render_account_field(): void {
        $user_id = get_current_user_id();
        $current = get_user_meta($user_id, '_preferred_contact_method', true) ?: 'email';

        echo '<div class="clear"></div><fieldset style="margin-top:20px;">';
        echo '<legend>' . esc_html__('Communication Preferences', 'musesys') . '</legend>';
        
        // Using standard WC form field structure for standard styling
        echo '<p class="form-row form-row-wide">';
        echo '<label>' . esc_html__('Preferred Update Method', 'musesys') . '</label><br>';
        echo '<label style="margin-right:20px;"><input type="radio" name="preferred_contact_method" value="email" ' . checked($current, 'email', false) . '> Email</label>';
        echo '<label><input type="radio" name="preferred_contact_method" value="phone" ' . checked($current, 'phone', false) . '> Phone/Text</label>';
        echo '</p>';
        echo '</fieldset><div class="clear"></div>';
    }

    /**
     * Save field from My Account.
     */
    public static function save_account_field(int $user_id): void {
        if (isset($_POST['preferred_contact_method'])) {
            $val = sanitize_text_field(wp_unslash($_POST['preferred_contact_method']));
            if (in_array($val, ['email', 'phone'], true)) {
                update_user_meta($user_id, '_preferred_contact_method', $val);
            }
        }
    }

    /**
     * Render field in Admin User Profile.
     */
    public static function render_admin_field(WP_User $user): void {
        $current = get_user_meta($user->ID, '_preferred_contact_method', true) ?: 'email';
        ?>
        <table class="form-table">
            <tr>
                <th><label for="preferred_contact_method">Preferred Update Method</label></th>
                <td>
                    <label style="margin-right:20px;">
                        <input type="radio" name="preferred_contact_method" value="email" <?php checked($current, 'email'); ?>> 
                        Email
                    </label>
                    <label>
                        <input type="radio" name="preferred_contact_method" value="phone" <?php checked($current, 'phone'); ?>> 
                        Phone/Text
                    </label>
                    <p class="description">How does this user prefer to be notified about orders?</p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save field from Admin User Profile.
     */
    public static function save_admin_field(int $user_id): void {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }
        if (isset($_POST['preferred_contact_method'])) {
            $val = sanitize_text_field(wp_unslash($_POST['preferred_contact_method']));
            if (in_array($val, ['email', 'phone'], true)) {
                update_user_meta($user_id, '_preferred_contact_method', $val);
            }
        }
    }

    /**
     * Display selection on Admin Order Edit screen.
     */
    public static function render_order_field(WC_Order $order): void {
        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }

        $pref = get_user_meta($user_id, '_preferred_contact_method', true) ?: 'email';
        
        // Styling matches standard WC admin details
        echo '<div style="margin-top:10px; padding:10px; background:#f9f9f9; border:1px solid #e5e5e5; border-radius:4px;">';
        echo '<strong>Communication Pref:</strong> ';
        
        if ($pref === 'phone') {
            echo '<span class="dashicons dashicons-smartphone" style="vertical-align:text-bottom; color:#d63638;"></span> <strong>Phone/Text</strong>';
        } else {
            echo '<span class="dashicons dashicons-email" style="vertical-align:text-bottom; color:#0073aa;"></span> Email';
        }
        echo '</div>';
    }
}

Muse_Notification_Preference::init();
