<?php
/**
 * Plugin Name: MuseSys – Billing Lock (Wegmans)
 * Description: Forces corporate billing address and hides billing fields from checkout. MU-plugin for early load order.
 * Version: 1.0.0
 * Author: MuseSystems
 */

if (!defined('ABSPATH')) { exit; }

final class MuseSys_Billing_Lock {
    
    // Corporate billing (Wegmans HQ)
    private static $CORP = [
        'first_name' => 'Wegmans ',
        'last_name'  => 'Technician',
        'company'    => 'Wegmans Food Markets, Inc.',
        'address_1'  => '1500 Brooks Avenue',
        'address_2'  => 'PO Box 30844',
        'city'       => 'Rochester',
        'state'      => 'NY',
        'postcode'   => '14603-0844',
        'country'    => 'US',
    ];

    public static function boot(): void {
        // Frontend assets (Strict CSS)
        add_action('wp_enqueue_scripts', [__CLASS__, 'inline_css']);

        // Lock corporate billing (leave email/phone editable)
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'lock_corp_billing'], 999);

        // Force corporate billing data on submission
        add_filter('woocommerce_checkout_posted_data', [__CLASS__, 'force_billing_defaults']);
    }

    public static function inline_css(): void {
        $css = "
        /* STRICTLY HIDE USER BILLING REMNANTS */
        .woocommerce-billing-fields h3,
        .woocommerce-billing-fields .form-row,
        .woocommerce-billing-fields__field-wrapper {
            display: none !important;
        }
        /* Reveal ONLY the specific fields we allow (Phone/Email) if they happen to end up inside that wrapper */
        .woocommerce-billing-fields .form-row#billing_email_field,
        .woocommerce-billing-fields .form-row#billing_phone_field {
            display: block !important;
        }
        /* Force hide country/state fields since they're locked to US/NY */
        #billing_country_field, 
        #billing_state_field,
        .woocommerce-billing-fields__field-wrapper .form-row.billing_country_field,
        .woocommerce-billing-fields__field-wrapper .form-row.billing_state_field {
            display: none !important;
        }
        ";
        wp_register_style('musesys-billing-lock', false);
        wp_enqueue_style('musesys-billing-lock');
        wp_add_inline_style('musesys-billing-lock', $css);
    }

    public static function lock_corp_billing(array $fields): array {
        // We only want Email and Phone. Eliminate everything else from the 'billing' group.
        // This effectively removes them from the rendered output.
        $allow_list = ['billing_email', 'billing_phone'];
        
        if (isset($fields['billing']) && is_array($fields['billing'])) {
            foreach (array_keys($fields['billing']) as $field_key) {
                if (!in_array($field_key, $allow_list, true)) {
                    unset($fields['billing'][$field_key]);
                }
            }
        }
        
        // Ensure Email/Phone are required
        if (isset($fields['billing']['billing_email'])) {
            $fields['billing']['billing_email']['required'] = true;
            $fields['billing']['billing_email']['class'][] = 'form-row-wide';
        }
        if (isset($fields['billing']['billing_phone'])) {
            $fields['billing']['billing_phone']['required'] = true;
            $fields['billing']['billing_phone']['class'][] = 'form-row-wide';
        }

        return $fields;
    }

    public static function force_billing_defaults(array $data): array {
        foreach (self::$CORP as $key => $value) {
            $data['billing_' . $key] = $value;
        }
        return $data;
    }
}

// Initialize
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        MuseSys_Billing_Lock::boot();
    }
}, 5); 
