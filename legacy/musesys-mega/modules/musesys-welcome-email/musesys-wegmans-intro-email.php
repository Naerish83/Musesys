<?php
/**
 * Plugin Name: MuseSys Wegmans Welcome Email
 * Description: Customizes the new account signup email for Wegmans.
 * Author: MuseSystems
 * Version: 1.2.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Override WooCommerce New Account Email Content
 */
add_filter('woocommerce_email_content_customer_new_account', function($content, $email, $user, $password_generated = null) {
    
    $first_name = get_user_meta($user->ID, 'first_name', true);
    $login_url  = wc_get_page_permalink('myaccount');
    
    // Generate a password reset link if this is a new account setup
    $reset_key = get_password_reset_key($user);
    $set_password_url = network_site_url("wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode($user->user_login), 'login');

    ob_start();
    ?>
    <p>Hello <?php echo esc_html($first_name ? $first_name : $user->user_login); ?>,</p>

    <p>Your Wegmans Order Guide account has been successfully created.</p>
    
    <div style="margin: 20px 0; padding: 15px; border: 1px solid #eee; background-color: #f9f9f9;">
        <p><strong>Step 1: Set Your Password</strong><br>
        Before logging in, please set your secure password by clicking the link below:<br>
        <a href="<?php echo esc_url($set_password_url); ?>">Click Here to Set Your Password</a></p>
    </div>

    <p>Once set, you can log in any time here: <a href="<?php echo esc_url($login_url); ?>"><?php echo esc_html($login_url); ?></a></p>

    <p>This platform was designed specifically for Wegmans to streamline your store�s parts ordering with accuracy, speed, and transparency.</p>

    <h3>Need assistance? Your support team is ready:</h3>
    <ul>
        <li><strong>Customer Service:</strong> Dedicated Wegmans Team<br>
            ?? (###) ###-#### | ?? <a href="mailto:partsmaint@gerharzequipment.com">partsmaint@gerharzequipment.com</a></li>

        <li><strong>Account Manager:</strong> Kevin Longley<br>
            ?? (###) ###-#### | ?? <a href="mailto:kevin.l@gerharzequipment.com">Kevin.L@gerharzequipment.com</a></li>

        <li><strong>Technical Support:</strong> Cole Henderson<br>
            ?? (###) ###-#### | ?? <a href="mailto:Cole.h@gerharzequipment.com">Cole.h@Gerharzequipment.com</a></li>
    </ul>

    <h3>What�s inside your Order Guide:</h3>
    <ul>
        <li>Equipment + manuals currently in use </li>
        <li>115,000+ Parts, searchable by brand/part number/EAM #</li>
        <li>A Product Request for any unlisted items</li>
    </ul>

    <p>We�re looking forward to making your ordering process simple and efficient.</p>

    <p>Thank you,<br>
    The Gerharz Team</p>
    <?php

    return ob_get_clean();
}, 10, 4);

/**
 * Override WooCommerce New Account Email Subject
 */
add_filter('woocommerce_email_subject_customer_new_account', function($subject, $email, $user, $password_generated = null) {
    return __('Welcome to Your Wegmans Order Guide');
}, 10, 4);

/* Built by Muse, written by Syl, verified by Cole */