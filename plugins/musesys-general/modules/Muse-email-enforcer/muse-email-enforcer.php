<?php
/**
 * Plugin Name: Muse Email Enforcer
 * Description: Forces WooCommerce HTML email output, unlocks admin dropdown, and logs final content.
 * Version: 1.0
 * Author: MuseSystems
 */

add_filter('wp_mail_content_type', function($content_type) {
    return 'text/html';
}, 99);

add_filter('woocommerce_email_content_type', function($content_type) {
    return 'text/html';
}, 99);

/**
 * Unlock "Email Type" dropdown in WooCommerce email settings
 */
add_filter('woocommerce_email_get_options', function($options) {
    foreach ($options as $key => &$value) {
        if (isset($value['id']) && strpos($value['id'], 'email_type') !== false) {
            $value['type'] = 'select';
            $value['options'] = array(
                'plain' => 'Plain Text',
                'html' => 'HTML',
                'multipart' => 'Multipart',
            );
        }
    }
    return $options;
});

/**
 * Log outgoing email message body
 */
add_filter('wp_mail', function($args) {
    $log = "\n=== Muse Email Log ===\n";
    $log .= "To: " . print_r($args['to'], true) . "\n";
    $log .= "Subject: " . $args['subject'] . "\n";
    $log .= "Headers: " . print_r($args['headers'], true) . "\n";
    $log .= "Message:\n" . $args['message'] . "\n\n";

    error_log($log);

    return $args;
});
