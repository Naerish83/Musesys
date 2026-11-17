<?php

/**
 * MuseSys Order Exporter Module
 * Flat CSV format output for Line Items within Orders.
 */

if (! defined('ABSPATH')) {
    exit;
}

const MUSESYS_EXPORT_HEADERS = array(
    'Order Number',
    'Order Date',
    'Customer Note',
    'Customer User Email',
    'Address 1&2 (Shipping)',
    'City, State, Zip (Shipping)',
    'Item #',
    'SKU',
    'Item Name',
    'Quantity',
    'Item Cost',
    'additional_install_store_number',
    'additional_work_order_number',
    'global_install_store',
    'global_work_order',
    'Order Status',
    'Logistics Status',
    'Logistics Date',
    'Primary Tracking',
    'Raw Shipments',
    'Ordered Qty',
    'Shipped Qty',
    'Remaining Qty',
    'Line Item ID',
    'Order Created At',
    'Order Modified At',
    'Shipment Count',
    'Has Tracking',
    'Tracking Source',
);

add_action('rest_api_init', function () {
    register_rest_route('musesys/v1', '/export-orders', array(
        'methods'  => 'GET',
        'callback' => 'musesys_export_orders_api',
        'permission_callback' => function () {
            // Staff only. UACP authenticates with an application password for an admin / shop manager.
            return current_user_can('manage_woocommerce');
        },
    ));
});

function musesys_export_orders_api(WP_REST_Request $request)
{
    $start_date = $request->get_param('start_date');
    $end_date   = $request->get_param('end_date');
    $export_all = $request->get_param('export_all');

    $args = array(
        'limit' => -1, // Get all matching
        'orderby' => 'date',
        'order' => 'DESC',
        'return' => 'objects' // Return WC_Order objects
    );

    if ($export_all !== 'true' && $export_all !== '1') {
        // Date range query
        $date_query = array();
        if (! empty($start_date)) {
            $date_query['after'] = $start_date . ' 00:00:00';
        }
        if (! empty($end_date)) {
            $date_query['before'] = $end_date . ' 23:59:59';
        }
        if (! empty($date_query)) {
            $args['date_created'] = '';
            if (isset($date_query['after']) && isset($date_query['before'])) {
                $args['date_created'] = $date_query['after'] . '...' . $date_query['before'];
            } elseif (isset($date_query['after'])) {
                $args['date_created'] = '>=' . $date_query['after'];
            } elseif (isset($date_query['before'])) {
                $args['date_created'] = '<=' . $date_query['before'];
            }
        }
    }

    $orders = wc_get_orders($args);
    $export_data = array();

    if (empty($orders)) {
        return rest_ensure_response(array());
    }

    foreach ($orders as $order) {
        $order_base = musesys_export_build_order_base($order);
        $line_number = 0;

        $shipments_raw = $order->get_meta('_muse_shipments', true);
        $shipments = json_decode($shipments_raw, true);
        if (!is_array($shipments)) $shipments = array();

        foreach ($order->get_items() as $item_id => $item) {
            $line_number++;
            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : '';
            
            $ordered_qty = (int) $item->get_quantity();
            $shipped_qty = 0;
            foreach ($shipments as $ship) {
                if (isset($ship['items'][$item_id])) {
                    $shipped_qty += (int) $ship['items'][$item_id];
                }
            }
            $remaining_qty = max(0, $ordered_qty - $shipped_qty);

            $export_data[] = musesys_export_build_row(
                $order_base,
                array(
                    'Item #' => $line_number,
                    'SKU' => $sku,
                    'Item Name' => $item->get_name(),
                    'Quantity' => $ordered_qty,
                    'Item Cost' => $item->get_total(),
                    'additional_install_store_number' => musesys_export_resolve_item_install_store($item),
                    'additional_work_order_number' => musesys_export_resolve_item_work_order($item),
                    'Ordered Qty' => $ordered_qty,
                    'Shipped Qty' => $shipped_qty,
                    'Remaining Qty' => $remaining_qty,
                    'Line Item ID' => $item_id,
                )
            );
        }
    }

    return rest_ensure_response($export_data);
}

function musesys_export_build_order_base($order)
{
    $address_1 = $order->get_shipping_address_1();
    $address_2 = $order->get_shipping_address_2();

    $shipments_raw = $order->get_meta('_muse_shipments', true);
    $shipments = json_decode($shipments_raw, true);
    if (!is_array($shipments)) $shipments = array();

    $has_tracking = false;
    $tracking_sources = array();
    foreach ($shipments as $ship) {
        if (!empty($ship['tracking_numbers']) && is_array($ship['tracking_numbers'])) {
            foreach ($ship['tracking_numbers'] as $tn) {
                if (!empty(trim($tn))) {
                    $has_tracking = true;
                    $c = isset($ship['carrier']) ? $ship['carrier'] : '';
                    if (!in_array($c, $tracking_sources)) {
                        $tracking_sources[] = $c;
                    }
                }
            }
        }
    }

    $base = array(
        'Order Number' => $order->get_id(),
        'Order Date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
        'Customer Note' => $order->get_customer_note(),
        'Customer User Email' => musesys_export_resolve_customer_email($order),
        'Address 1&2 (Shipping)' => trim($address_1 . ' ' . $address_2),
        'City, State, Zip (Shipping)' => sprintf(
            '%s, %s, %s',
            $order->get_shipping_city(),
            $order->get_shipping_state(),
            $order->get_shipping_postcode()
        ),
        'Item #' => '',
        'SKU' => '',
        'Item Name' => '',
        'Quantity' => '',
        'Item Cost' => '',
        'additional_install_store_number' => '',
        'additional_work_order_number' => '',
        'global_install_store' => musesys_export_resolve_global_install_store($order),
        'global_work_order' => musesys_export_resolve_global_work_order($order),
        'Order Status' => $order->get_status(),
        'Logistics Status' => current(array_filter([$order->get_meta('_muse_logistics_status', true), 'in_stock'])),
        'Logistics Date' => $order->get_meta('_muse_logistics_date', true),
        'Primary Tracking' => $order->get_meta('_muse_track_primary', true),
        'Raw Shipments' => $shipments_raw,
        'Ordered Qty' => '',
        'Shipped Qty' => '',
        'Remaining Qty' => '',
        'Line Item ID' => '',
        'Order Created At' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
        'Order Modified At' => $order->get_date_modified() ? $order->get_date_modified()->date('Y-m-d H:i:s') : '',
        'Shipment Count' => count($shipments),
        'Has Tracking' => $has_tracking ? 'true' : 'false',
        'Tracking Source' => implode(', ', $tracking_sources),
    );

    return musesys_export_build_row(array(), $base);
}

function musesys_export_build_row($base, $overrides)
{
    $row = array();
    foreach (MUSESYS_EXPORT_HEADERS as $header) {
        $value = array_key_exists($header, $overrides)
            ? $overrides[$header]
            : (array_key_exists($header, $base) ? $base[$header] : '');
        $row[$header] = musesys_export_stringify_value($value);
    }

    return $row;
}

function musesys_export_resolve_customer_email($order)
{
    $email = $order->get_billing_email();

    if (empty($email) && $order->get_user_id()) {
        $user = get_user_by('id', $order->get_user_id());
        if ($user && ! empty($user->user_email)) {
            $email = $user->user_email;
        }
    }

    return $email;
}

function musesys_export_resolve_global_install_store($order)
{
    return musesys_export_format_store_value(musesys_export_pick_first_value(array(
        $order->get_meta('_muse_store_global', true),
        $order->get_meta('_install_stores', true),
        $order->get_meta('_musesys_install_store', true),
        $order->get_meta('additional_install_store_number', true),
        $order->get_meta('_muse_global_install_store', true),
    )));
}

function musesys_export_resolve_item_install_store($item)
{
    return musesys_export_format_store_value(musesys_export_pick_first_value(array(
        $item->get_meta('_muse_store_line', true),
        $item->get_meta('_muse_store_global', true),
        $item->get_meta('Install Store #', true),
        $item->get_meta('additional_install_store_number', true),
        $item->get_meta('_muse_install_store', true),
    )));
}

function musesys_export_resolve_global_work_order($order)
{
    return musesys_export_format_work_order_value(musesys_export_pick_first_value(array(
        $order->get_meta('_muse_wo_global', true),
        $order->get_meta('_muse_global_work_order', true),
        $order->get_meta('_primary_work_order', true),
        $order->get_meta('_muse_workorder', true),
        $order->get_meta('muse_global_work_order', true),
        $order->get_meta('_tracking_number', true),
        $order->get_meta('_muse_track_primary', true),
        $order->get_meta('_work_orders', true),
    )));
}

function musesys_export_resolve_item_work_order($item)
{
    return musesys_export_format_work_order_value(musesys_export_pick_first_value(array(
        $item->get_meta('_muse_wo_line', true),
        $item->get_meta('WO Number', true),
        $item->get_meta('_muse_lineitem_workorder', true),
    )));
}

function musesys_export_pick_first_value($values)
{
    foreach ($values as $value) {
        $string = musesys_export_stringify_value($value);
        if ($string !== '') {
            return $string;
        }
    }

    return '';
}

function musesys_export_stringify_value($value)
{
    if (is_array($value)) {
        $parts = array();

        foreach ($value as $item) {
            $string = '';

            if (is_array($item)) {
                if (isset($item['display']) && $item['display'] !== '') {
                    $string = musesys_export_stringify_value($item['display']);
                } elseif (isset($item['number']) && $item['number'] !== '') {
                    $string = musesys_export_stringify_value($item['number']);
                }
            } else {
                $string = musesys_export_stringify_value($item);
            }

            if ($string !== '' && ! in_array($string, $parts, true)) {
                $parts[] = $string;
            }
        }

        return implode(', ', $parts);
    }

    if (is_object($value)) {
        return method_exists($value, '__toString') ? (string) $value : '';
    }

    if ($value === null) {
        return '';
    }

    return (string) $value;
}

function musesys_export_format_store_value($value)
{
    $parts = array_filter(array_map('trim', explode(',', (string) $value)), 'strlen');

    if (empty($parts)) {
        return '';
    }

    $formatted = array();
    foreach ($parts as $part) {
        $digits = preg_replace('/\D+/', '', $part);
        if ($digits !== '' && strlen($digits) <= 3) {
            $part = sprintf('%03d', (int) $digits);
        }
        $formatted[] = $part;
    }

    return implode(', ', array_unique($formatted));
}

function musesys_export_format_work_order_value($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/(\d{7}|\d{9})/', $value, $matches)) {
        return $matches[1];
    }

    return $value;
}

// Register WP Admin Menu under "MuseSys"
add_action('admin_menu', function () {
    add_submenu_page(
        'musesys',             // Parent slug
        'Order Exporter',      // Page title
        'Order Exporter',      // Menu title
        'manage_options',      // Capability
        'musesys-exporter',    // Menu slug
        'musesys_exporter_admin_page' // Function
    );
});

function musesys_exporter_admin_page()
{
    if (! current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions', 'musesys'));
    }
?>
    <div class="wrap">
        <h1>MuseSys Order Exporter</h1>
        <p>Select a date range to export WooCommerce orders to a detailed CSV, matching standard MuseSys metadata key constraints.</p>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="export_all">Export All</label></th>
                <td>
                    <input type="checkbox" id="export_all" value="1">
                    <span class="description">Ignore dates and export everything.</span>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="start_date">Start Date</label></th>
                <td><input type="date" id="start_date" class="regular-text"></td>
            </tr>
            <tr>
                <th scope="row"><label for="end_date">End Date</label></th>
                <td><input type="date" id="end_date" class="regular-text"></td>
            </tr>
        </table>

        <p class="submit">
            <button type="button" class="button button-primary" id="do_export_btn">Export to CSV</button>
            <span id="export_status" style="margin-left:10px; font-weight:bold;"></span>
        </p>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            document.getElementById('do_export_btn').addEventListener('click', function() {
                var btn = this;
                var status = document.getElementById('export_status');
                var isAll = document.getElementById('export_all').checked;
                var start = document.getElementById('start_date').value;
                var end = document.getElementById('end_date').value;

                if (!isAll && !start && !end) {
                    alert("Please select dates or check 'Export All'.");
                    return;
                }

                btn.disabled = true;
                status.innerText = "Fetching orders, please wait...";
                status.style.color = "#000";

                var url = '/wp-json/musesys/v1/export-orders?';
                if (isAll) {
                    url += 'export_all=true';
                } else {
                    if (start) url += 'start_date=' + encodeURIComponent(start) + '&';
                    if (end) url += 'end_date=' + encodeURIComponent(end);
                }

                fetch(url, {
                        credentials: 'same-origin',
                        headers: { 'X-WP-Nonce': <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?> }
                    })
                    .then(function(res) {
                        if (!res.ok) throw new Error("API responded with status: " + res.status);
                        return res.json();
                    })
                    .then(function(data) {
                        if (!data || data.length === 0) {
                            status.innerText = "No orders found for this criteria.";
                            status.style.color = "#d63638";
                            btn.disabled = false;
                            return;
                        }

                        status.innerText = "Generating CSV payload...";

                        var csvRows = [];
                        var keys = Object.keys(data[0]);

                        // Headers
                        csvRows.push(keys.map(function(k) {
                            return '"' + String(k).replace(/"/g, '""') + '"';
                        }).join(","));

                        // Rows
                        data.forEach(function(row) {
                            csvRows.push(keys.map(function(k) {
                                var val = row[k] === null || row[k] === undefined ? "" : String(row[k]);
                                return '"' + val.replace(/"/g, '""') + '"';
                            }).join(","));
                        });

                        var csvString = csvRows.join("\n");
                        var blob = new Blob([csvString], {
                            type: 'text/csv;charset=utf-8;'
                        });
                        var urlObj = URL.createObjectURL(blob);

                        var link = document.createElement("a");
                        link.setAttribute("href", urlObj);
                        var dateStr = new Date().toISOString().slice(0, 10);
                        link.setAttribute("download", "MuseSys_Orders_Export_" + dateStr + ".csv");
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);

                        status.innerText = "✅ Export successful (" + data.length + " rows).";
                        status.style.color = "#00a32a";
                        btn.disabled = false;
                    })
                    .catch(function(err) {
                        status.innerText = "Error: " + err.message;
                        status.style.color = "#d63638";
                        btn.disabled = false;
                    });
            });
        });
    </script>
<?php
}
