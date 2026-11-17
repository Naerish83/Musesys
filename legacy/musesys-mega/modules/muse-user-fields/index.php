<?php
/**
 * Plugin Name: Muse Custom User Fields
 * Description: Adds custom contact fields to the user profile.
 * Version: 1.1
 * Author: Muse
 */

// Add the custom field to user profiles
function muse_contact_phone_field($user) {
    ?>
    <h3>Contact Information</h3>
    <table class="form-table">
        <tr>
            <th><label for="contact_phone">Contact Phone</label></th>
            <td>
                <input type="text" name="contact_phone" id="contact_phone" value="<?php echo esc_attr(get_the_author_meta('contact_phone', $user->ID)); ?>" class="regular-text" /><br />
                <span class="description">Please enter the technician's contact phone number.</span>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'muse_contact_phone_field');
add_action('edit_user_profile', 'muse_contact_phone_field');

// Save the custom field data
function muse_save_contact_phone_field($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }
    // Update the data, sanitizing it as a text field.
    if (isset($_POST['contact_phone'])) {
        update_user_meta(
            $user_id,
            'contact_phone',
            sanitize_text_field(wp_unslash($_POST['contact_phone']))
        );
    }
}
add_action('personal_options_update', 'muse_save_contact_phone_field');
add_action('edit_user_profile_update', 'muse_save_contact_phone_field'); ?>
