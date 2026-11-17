<?php

/**
 * MuseSys Universal AQ/TRX Exporter
 * Maps WooCommerce Order Data to AutoQuotes JSON format.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('musesys/v1', '/export-aq/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'muse_generate_aq_json',
        'permission_callback' => function () {
            // Staff only (exposes _purchase_price). UACP authenticates with an application password.
            return current_user_can('manage_woocommerce');
        },
    ));
});

function muse_generate_aq_json($data)
{
    $order = wc_get_order($data['id']);
    if (!$order) {
        return new WP_Error('no_order', 'Order not found', array('status' => 404));
    }

    // Pull our Core Meta Standards
    $wo_global = $order->get_meta('_muse_wo_global', true);

    // The AQ Skeleton
    $aq_output = [
        "ProjectId" => wp_generate_uuid4(),
        "ProjectName" => "Wegmans Order #" . $order->get_order_number(),
        "Code" => $wo_global ? $wo_global : $order->get_order_number(),
        "CreateDate" => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d\TH:i:s') : '',
        "JobRef" => $wo_global ? $wo_global : $order->get_order_number(),
        "Status" => "Active",
        "LineItems" => [],
        "AddressRepository" => [
            // Hardcoded Gerharz Identity
            [
                "AddressId" => "124239cc-4319-45f7-a961-dd3b458bf02c",
                "Name" => "Gerharz Restaurant Equipment",
                "Address1" => "220 Teall Ave.",
                "City" => "Syracuse",
                "State" => "NY",
                "Zip" => "13210"
            ]
        ]
    ];

    // Map the Line Items
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        $aq_output['LineItems'][] = [
            "LineItemId" => wp_generate_uuid4(),
            "ItemNumber" => $item_id,
            "SubLineItems" => [[
                "SubLineItemId" => wp_generate_uuid4(),
                "Quantity" => $item->get_quantity(),
                "Model" => $product ? $product->get_sku() : $item->get_name(),
                "Spec" => $item->get_name(),
                "SellPrice" => (float)$order->get_item_total($item, false, true),
                "NetPrice" => (float)($product ? $product->get_meta('_purchase_price', true) : 0), // Using your custom cost field
                "VendorId" => "dc535f1b-8ffd-41c4-a731-315b2317bec3" // Default Salvajor for now
            ]]
        ];
    }

    return new WP_REST_Response($aq_output, 200);
}
