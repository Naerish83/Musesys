<?php
defined('ABSPATH') || exit;

/** Redirect to selector if no chosen address */
add_action('template_redirect', function(){
    if (is_admin() || !function_exists('WC')) return;
    if (is_checkout() && empty($_GET['key'])) {
        $sel = WC()->session ? WC()->session->get('musesys_shipping') : null;
        if (empty($sel) || !is_array($sel)) {
            $selector_url = home_url('/167249-2/');
            wp_safe_redirect($selector_url);
            exit;
        }
    }
});

/** Lock and populate checkout fields from session */
add_filter('woocommerce_checkout_fields', function($fields){
    if (!function_exists('WC') || !WC()->session) return $fields;
    $sel = WC()->session->get('musesys_shipping');
    if (!is_array($sel)) return $fields;

    $map = [
        'shipping_first_name' => isset($sel['first_name']) ? $sel['first_name'] : '',
        'shipping_last_name'  => isset($sel['last_name']) ? $sel['last_name'] : '',
        'shipping_company'    => isset($sel['company']) ? $sel['company'] : '',
        'shipping_address_1'  => isset($sel['address_1']) ? $sel['address_1'] : '',
        'shipping_address_2'  => isset($sel['address_2']) ? $sel['address_2'] : '',
        'shipping_city'       => isset($sel['city']) ? $sel['city'] : '',
        'shipping_state'      => isset($sel['state']) ? $sel['state'] : '',
        'shipping_postcode'   => isset($sel['zip']) ? $sel['zip'] : '',
        'shipping_phone'      => isset($sel['phone']) ? $sel['phone'] : '',
        'shipping_country'    => 'US',
    ];
    foreach ($map as $key=>$val){
        if (isset($fields['shipping'][$key])) {
            $fields['shipping'][$key]['default'] = $val;
            $fields['shipping'][$key]['custom_attributes']['readonly'] = 'readonly';
        }
    }

    // Shipping Speed (required) in order notes section area
    $fields['order']['musesys_shipping_speed'] = [
        'type' => 'select',
        'label' => __('Shipping Speed','musesys'),
        'required' => true,
        'options' => [
            '' => __('Choose shipping speed…','musesys'),
            'Pick Up at Gerharz' => 'Pick up at Gerharz',
			'Ground' => 'Ground',
            '2nd Day' => '2nd Day',
            'Next Day Air' => 'Next Day Air',
            'Saturday Delivery' => 'Saturday Delivery'
        ],
        'priority' => 10,
    ];

    return $fields;
}, 20);

/** Server-side: force shipping fields to selection & require speed */
add_action('woocommerce_checkout_process', function(){
    if (!function_exists('WC') || !WC()->session) return;
    $sel = WC()->session->get('musesys_shipping');
    if (!is_array($sel)) {
        wc_add_notice(__('Please select a shipping address before checkout.','musesys'), 'error');
        return;
    }
    if (empty($_POST['musesys_shipping_speed'])) {
        wc_add_notice(__('Please choose a Shipping Speed.','musesys'), 'error');
    }
});

/** Override posted data with session values */
add_filter('woocommerce_checkout_posted_data', function($data){
    if (!function_exists('WC') || !WC()->session) return $data;
    $sel = WC()->session->get('musesys_shipping');
    if (!is_array($sel)) return $data;
    $data['ship_to_different_address'] = 1;
    $data['shipping_first_name'] = isset($sel['first_name']) ? $sel['first_name'] : '';
    $data['shipping_last_name']  = isset($sel['last_name']) ? $sel['last_name'] : '';
    $data['shipping_company']    = isset($sel['company']) ? $sel['company'] : '';
    $data['shipping_address_1']  = isset($sel['address_1']) ? $sel['address_1'] : '';
    $data['shipping_address_2']  = isset($sel['address_2']) ? $sel['address_2'] : '';
    $data['shipping_city']       = isset($sel['city']) ? $sel['city'] : '';
    $data['shipping_state']      = isset($sel['state']) ? $sel['state'] : '';
    $data['shipping_postcode']   = isset($sel['zip']) ? $sel['zip'] : '';
    $data['shipping_country']    = 'US';
    if (isset($sel['phone'])) $data['billing_phone'] = $sel['phone'];
    return $data;
}, 20);

/** Before totals, ensure WC customer shipping matches */
add_action('woocommerce_before_calculate_totals', function($cart){
    if (!function_exists('WC') || !WC()->session) return;
    $sel = WC()->session->get('musesys_shipping');
    if (!is_array($sel)) return;
    $c = WC()->customer;
    if (!$c) return;
    $c->set_shipping_first_name( isset($sel['first_name']) ? $sel['first_name'] : '' );
    $c->set_shipping_last_name( isset($sel['last_name']) ? $sel['last_name'] : '' );
    $c->set_shipping_address_1( isset($sel['address_1']) ? $sel['address_1'] : '' );
    $c->set_shipping_address_2( isset($sel['address_2']) ? $sel['address_2'] : '' );
    $c->set_shipping_city( isset($sel['city']) ? $sel['city'] : '' );
    $c->set_shipping_state( isset($sel['state']) ? $sel['state'] : '' );
    $c->set_shipping_postcode( isset($sel['zip']) ? $sel['zip'] : '' );
    $c->set_shipping_country( 'US' );
}, 15);

/** Save meta on order */
add_action('woocommerce_checkout_create_order', function($order, $data){
    if (!function_exists('WC') || !WC()->session) return;
    $sel = WC()->session->get('musesys_shipping');
    if (is_array($sel)) {
        $order->update_meta_data('_musesys_source', isset($sel['_type'])?$sel['_type']:'');
        $order->update_meta_data('_musesys_address_id', isset($sel['_id'])?$sel['_id']:'');
        $order->update_meta_data('_musesys_store_number', isset($sel['last_name'])?$sel['last_name']:'');
        $order->update_meta_data('_musesys_store_name', isset($sel['first_name'])?$sel['first_name']:'');
        $order->update_meta_data('_musesys_phone', isset($sel['phone'])?$sel['phone']:'');
        $order->update_meta_data('_musesys_address_1', isset($sel['address_1'])?$sel['address_1']:'');
        $order->update_meta_data('_musesys_city', isset($sel['city'])?$sel['city']:'');
        $order->update_meta_data('_musesys_state', isset($sel['state'])?$sel['state']:'');
        $order->update_meta_data('_musesys_zip', isset($sel['zip'])?$sel['zip']:'');
    }
    // Shipping speed
    if (!empty($_POST['musesys_shipping_speed'])) {
        $order->update_meta_data('_musesys_shipping_speed', sanitize_text_field($_POST['musesys_shipping_speed']));
    }
    // Work Order & Install Store # (if present in POST)
    if (!empty($_POST['work_order'])) {
        $order->update_meta_data('_musesys_work_order', sanitize_text_field($_POST['work_order']));
    }
    if (!empty($_POST['install_store'])) {
        $order->update_meta_data('_musesys_install_store', sanitize_text_field($_POST['install_store']));
    }
}, 10, 2);

/** Show selected address block on checkout */
add_action('woocommerce_checkout_before_customer_details', function(){
    if (!function_exists('WC') || !WC()->session) return;
    $sel = WC()->session->get('musesys_shipping');
    if (!is_array($sel)) return;
    $src = isset($sel['_type']) ? ucfirst($sel['_type']) : '';
    echo '<div class="musesys-selected-address" style="padding:12px;border:1px solid #ddd;margin-bottom:15px;">';
    echo '<strong>Ship-To ('.$src.')</strong><br>';
    $line = esc_html( (isset($sel['first_name'])?$sel['first_name']:'') . ' ' . (isset($sel['last_name']) ? ' ('.$sel['last_name'].')' : '') );
    echo $line . '<br>';
    echo esc_html( (isset($sel['address_1'])?$sel['address_1']:'') ) . '<br>';
    echo esc_html( (isset($sel['city'])?$sel['city']:'') . ', ' . (isset($sel['state'])?$sel['state']:'') . ' ' . (isset($sel['zip'])?$sel['zip']:'') );
    echo '</div>';
});

/** Render block in emails */
add_action('woocommerce_email_after_order_table', function($order, $sent_to_admin, $plain_text, $email){
    $meta = [
        'Source' => $order->get_meta('_musesys_source'),
        'Store #' => $order->get_meta('_musesys_store_number'),
        'Store Name' => $order->get_meta('_musesys_store_name'),
        'Address' => $order->get_meta('_musesys_address_1'),
        'City/State/Zip' => trim($order->get_meta('_musesys_city').' '.$order->get_meta('_musesys_state').' '.$order->get_meta('_musesys_zip')),
        'Phone' => $order->get_meta('_musesys_phone'),
        'Shipping Speed' => $order->get_meta('_musesys_shipping_speed'),
        'Work Order #' => $order->get_meta('_musesys_work_order'),
        'Install Store #' => $order->get_meta('_musesys_install_store'),
    ];
    $has = array_filter($meta, function($v){ return !empty($v); });
    if (!$has) return;
    if ($plain_text) {
        echo "\nShipping Information:\n";
        foreach ($meta as $k=>$v){ if($v) echo $k.': '.$v."\n"; }
    } else {
        echo '<h3>Shipping Information</h3><table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #eee" border="1">';
        foreach ($meta as $k=>$v){ if($v) printf('<tr><th style="text-align:left;border:1px solid #eee;">%s</th><td style="border:1px solid #eee;">%s</td></tr>', esc_html($k), esc_html($v)); }
        echo '</table>';
    }
}, 10, 4);

/** Admin order page block */
add_action('woocommerce_admin_order_data_after_shipping_address', function($order){
    echo '<div class="address"><p><strong>Shipping Information</strong><br>';
    $fields = ['_musesys_source'=>'Source','_musesys_store_number'=>'Store #','_musesys_store_name'=>'Store Name','_musesys_address_1'=>'Address','_musesys_city'=>'City','_musesys_state'=>'State','_musesys_zip'=>'Zip','_musesys_phone'=>'Phone','_musesys_shipping_speed'=>'Shipping Speed','_musesys_work_order'=>'Work Order #','_musesys_install_store'=>'Install Store #'];
    foreach ($fields as $key=>$label){
        $val = $order->get_meta($key);
        if ($val) printf('<strong>%s:</strong> %s<br>', esc_html($label), esc_html($val));
    }
    echo '</p></div>';
});

/** My Account order view */
add_action('woocommerce_order_details_after_order_table', function($order){
    echo '<section class="woocommerce-order-details musesys-block"><h2 class="woocommerce-order-details__title">Shipping Information</h2><table class="woocommerce-table">';
    $fields = ['_musesys_source'=>'Source','_musesys_store_number'=>'Store #','_musesys_store_name'=>'Store Name','_musesys_address_1'=>'Address','_musesys_city'=>'City','_musesys_state'=>'State','_musesys_zip'=>'Zip','_musesys_phone'=>'Phone','_musesys_shipping_speed'=>'Shipping Speed','_musesys_work_order'=>'Work Order #','_musesys_install_store'=>'Install Store #'];
    foreach ($fields as $key=>$label){
        $val = $order->get_meta($key);
        if ($val) printf('<tr><th>%s</th><td>%s</td></tr>', esc_html($label), esc_html($val));
    }
    echo '</table></section>';
});
 ?>