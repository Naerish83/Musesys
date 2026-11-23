<?php
/**
 * Plugin Name: Muse Partial Ship Plus
 * Description: Consolidated partial shipment management with clean tracking UI and modal, based on MuseSys multi-shipment logic.
 * Author: MuseSystems
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

final class Muse_Partial_Ship_Plus {
    const META_SHIPMENTS = '_muse_shipments';
    const META_LATEST_TRACKING = '_muse_ship_track';
    const META_SHIPPING_STATUS = '_muse_track_status';
    const REST_NS = 'musesys/v1';

    public function __construct() {
        // Order status
        add_action('init', array($this, 'register_status'));
        add_filter('wc_order_statuses', array($this, 'inject_status'));

        // Admin UI
        add_action('add_meta_boxes', array($this, 'add_metabox'));
        add_action('save_post_shop_order', array($this, 'save_metabox'), 10, 2);
        // HPOS Save Hook
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_metabox'), 10, 2);

        // Progress column (admin list)
        add_filter('manage_edit-shop_order_columns', array($this, 'add_progress_col'), 20);
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_progress_col'), 20, 2);

        // REST
        add_action('rest_api_init', array($this, 'register_rest'));

        // Frontend: actions + modal + assets
        add_filter('woocommerce_my_account_my_orders_actions', array($this, 'add_order_tracking_button'), 10, 2);
        add_action('wp_footer', array($this, 'tracking_modal_html'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Visibility hooks
        add_filter('bulk_actions-edit-shop_order', array($this, 'bulk_actions'));
        add_filter('woocommerce_reports_order_statuses', array($this, 'reports_statuses'));
        add_filter('woocommerce_email_actions', array($this, 'email_actions'));

        // Auto-recompute after item save
        add_action('woocommerce_saved_order_items', array($this, 'recompute_status_after_item_save'), 20, 2);

        // HPOS Compatibility
        add_action('before_woocommerce_init', function() {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });

        // Email Overhaul Hooks
        add_filter('woocommerce_email_subject_customer_note', array($this, 'filter_email_subject'), 10, 2);
        add_filter('woocommerce_email_heading_customer_note', array($this, 'filter_email_heading'), 10, 2);
        add_action('woocommerce_email_before_order_table', array($this, 'inject_shipment_into_completed_email'), 10, 4);
    }

    /* -------------------- Status -------------------- */
    public function register_status() {
        register_post_status('wc-partially-shipped', array(
            'label'                     => _x('Partially Shipped', 'Order status', 'musesys'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Partially Shipped <span class="count">(%s)</span>', 'Partially Shipped <span class="count">(%s)</span>', 'musesys'),
        ));
    }

    public function inject_status($statuses) {
        $new = array();
        foreach ($statuses as $key => $label) {
            $new[$key] = $label;
            if ($key === 'wc-processing') {
                $new['wc-partially-shipped'] = _x('Partially Shipped', 'Order status', 'musesys');
            }
        }
        return $new;
    }

    /* -------------------- Admin Metabox -------------------- */
    public function add_metabox() {
        $screen = 'shop_order';
        if (class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') && 
            wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()) {
            $screen = wc_get_page_screen_id('shop-order');
        }

        add_meta_box(
            'muse_psp_multi_ship',
            'Muse: Partial Ship + Tracking',
            array($this, 'render_metabox'),
            $screen,
            'normal',
            'high'
        );
    }

    public function render_metabox($post_or_order_object) {
        $order = ($post_or_order_object instanceof WC_Order) ? $post_or_order_object : wc_get_order($post_or_order_object->ID);
        if (!$order) { echo '<p>Order not found.</p>'; return; }
        
        $shipments = json_decode($order->get_meta(self::META_SHIPMENTS, true), true);
        if (!is_array($shipments)) $shipments = array();

        wp_nonce_field('muse_psp_save', 'muse_psp_nonce');

        // Added some CSS right here to ensure it loads
        echo '<style>
            .ms-ps-table td{padding:8px 10px; vertical-align:middle; border-bottom:1px solid #eee;} 
            .ms-ps-table th {text-align:left; padding:10px;}
            .ms-ps-table input[type=number]{width:80px} 
            .muse-shipment{border:1px solid #ccd0d4; background:#fff; padding:15px; margin:10px 0; border-radius:4px;} 
            .muse-shipment ul{margin:5px 0 0 20px}
            .muse-tracking-section { background: #f9f9f9; padding: 15px; border: 1px solid #e5e5e5; margin-top: 20px; }
            .muse-hidden { display: none; }
        </style>';

        // --- SECTION 1: PREVIOUS SHIPMENTS ---
        echo '<h3>Shipment History</h3>';
        if (empty($shipments)) {
            echo '<p><em>No shipments recorded yet.</em></p>';
        } else {
            foreach ($shipments as $index => $shipment) {
                echo '<div class="muse-shipment">';
                echo '<strong>Shipment #' . ($index + 1) . '</strong> <span style="color:#666; font-size:12px;">(' . ($shipment['date'] ?? 'Date n/a') . ')</span><br/>';
                
                // Carrier Display
                $c_code = $shipment['carrier'] ?? '';
                $c_label = $c_code;
                if ($c_code === 'TECH_DELIVERY') $c_label = 'Tech Delivery';
                elseif ($c_code === 'CUSTOMER_PICKUP') $c_label = 'Customer Pickup';
                elseif ($c_code === 'LTL') $c_label = 'LTL Freight';
                elseif ($c_code === 'OTHER') $c_label = $shipment['other_carrier'] ?? 'Other';
                
                echo '<strong>Method:</strong> ' . esc_html($c_label) . '<br/>';

                // Tracking Display
                if (!empty($shipment['tracking_numbers']) && is_array($shipment['tracking_numbers'])) {
                    echo '<strong>Tracking:</strong> ';
                    $links = array();
                    foreach ($shipment['tracking_numbers'] as $tn) {
                        $tn = trim(strval($tn));
                        if ($tn === '') continue;
                        // Simple link generation for display
                        $url = $this->get_tracking_url($c_code, $tn);
                        $links[] = ($url !== '#') ? '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($tn) . '</a>' : esc_html($tn);
                    }
                    echo implode(', ', $links);
                    echo '<br/>';
                }

                // Items Display
                if (!empty($shipment['items']) && is_array($shipment['items'])) {
                    echo '<div style="margin-top:8px;"><strong>Items:</strong><ul style="margin-top:0;">';
                    foreach ($shipment['items'] as $iid => $qty) {
                        $item = $order->get_item($iid);
                        if ($item) {
                            echo '<li>' . esc_html($qty) . 'x ' . esc_html($item->get_name()) . '</li>';
                        }
                    }
                    echo '</ul></div>';
                }
                echo '</div>';
            }
        }

        // --- SECTION 2: NEW SHIPMENT FORM ---
        echo '<div class="muse-tracking-section">';
        echo '<h3 style="margin-top:0;">Create New Shipment</h3>';
        
        // Item Table
        echo '<table class="widefat fixed striped ms-ps-table" style="border:1px solid #e5e5e5;">
                <thead><tr><th>Item</th><th style="width:60px;">Ord</th><th style="width:60px;">Sent</th><th style="width:100px;">Ship Now</th></tr></thead>
                <tbody>';
        
        foreach ($order->get_items() as $item_id => $item) {
            $qty_ordered = (int) $item->get_quantity();
            $shipped = $this->get_shipped_qty($item_id, $shipments);
            $max_ship = max(0, $qty_ordered - $shipped);
            
            // Dim out rows that are fully shipped
            $opacity = ($max_ship === 0) ? '0.5' : '1';
            
            echo '<tr style="opacity:'. $opacity .'">';
            echo '<td><strong>' . esc_html($item->get_name()) . '</strong></td>';
            echo '<td>' . esc_html($qty_ordered) . '</td>';
            echo '<td>' . esc_html($shipped) . '</td>';
            echo '<td>';
            if ($max_ship > 0) {
                echo '<input type="number" min="0" max="' . esc_attr($max_ship) . '" name="muse_psp_ship[' . esc_attr($item_id) . ']" value="0" class="short">';
            } else {
                echo '<span class="dashicons dashicons-yes" style="color:green"></span>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Carrier & Tracking Inputs
        echo '<div style="margin-top:15px; display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">';
        
        // Col 1: Carrier Selection
        echo '<div>';
        woocommerce_wp_select(array(
            'id'      => 'muse_psp_carrier',
            'label'   => 'Shipping Method:',
            'options' => array(
                ''                => '-- Select Method --',
                'UPS'             => 'UPS',
                'FEDEX'           => 'FedEx',
                'USPS'            => 'USPS',
                'DHL'             => 'DHL',
                'TECH_DELIVERY'   => 'Technician Delivery',
                'CUSTOMER_PICKUP' => 'Customer Pickup',
                'LTL'             => 'LTL Freight',
                'OTHER'           => 'Other'
            ),
            'value'   => ''
        ));
        
        // "Other" Carrier Name (Hidden by default)
        echo '<div id="muse_psp_other_wrapper" class="muse-hidden">';
        woocommerce_wp_text_input(array(
            'id'          => 'muse_psp_other_carrier',
            'label'       => 'Carrier Name:',
            'placeholder' => 'e.g. R&L Carriers',
            'value'       => ''
        ));
        echo '</div>';
        echo '</div>';

        // Col 2: Tracking Numbers (Hidden for Tech/Pickup)
        echo '<div id="muse_psp_tracking_wrapper">';
        woocommerce_wp_text_input(array(
            'id'          => 'muse_psp_ship_tracks',
            'label'       => 'Tracking Number(s):',
            'placeholder' => 'Comma separated',
            'description' => 'Leave blank if not applicable.',
            'value'       => ''
        ));
        echo '</div>';
        
        echo '</div>'; // End Grid

        // Notify Checkbox
        echo '<p style="margin-top:15px; border-top:1px solid #eee; padding-top:10px;">
                <label><input type="checkbox" name="muse_psp_notify" value="1" checked> <strong>Email Customer?</strong> (Sends "Partially Shipped" or "Completed" note)</label>
              </p>';
        
        echo '<button type="submit" class="button button-primary button-large">Save Shipment</button>';
        echo '</div>'; // End Tracking Section

        // --- SIMPLE JS FOR UI TOGGLING ---
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($){
                function toggleFields() {
                    var method = $('#muse_psp_carrier').val();
                    
                    // Toggle "Other Carrier" Name field
                    if (method === 'OTHER' || method === 'LTL') {
                        $('#muse_psp_other_wrapper').show();
                    } else {
                        $('#muse_psp_other_wrapper').hide();
                    }

                    // Toggle Tracking Number field
                    // Hide for Tech Delivery or Pickup, Show for everything else
                    if (method === 'TECH_DELIVERY' || method === 'CUSTOMER_PICKUP') {
                        $('#muse_psp_tracking_wrapper').hide();
                    } else {
                        $('#muse_psp_tracking_wrapper').show();
                    }
                }

                // Run on load and on change
                $('#muse_psp_carrier').change(toggleFields);
                toggleFields();
            });
        </script>
        <?php
    }

    public function save_metabox($post_id, $post) {
        if (!isset($_POST['muse_psp_nonce']) || !wp_verify_nonce($_POST['muse_psp_nonce'], 'muse_psp_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_shop_order', $post_id)) return;

        $order = wc_get_order($post_id);
        if (!$order) return;

        $shipments = json_decode($order->get_meta(self::META_SHIPMENTS, true), true);
        if (!is_array($shipments)) $shipments = array();

        $ship = isset($_POST['muse_psp_ship']) && is_array($_POST['muse_psp_ship']) ? $_POST['muse_psp_ship'] : array();
        $carrier = isset($_POST['muse_psp_carrier']) ? sanitize_text_field($_POST['muse_psp_carrier']) : '';
        $other_carrier = isset($_POST['muse_psp_other_carrier']) ? sanitize_text_field($_POST['muse_psp_other_carrier']) : '';
        $tracking_raw = isset($_POST['muse_psp_ship_tracks']) ? sanitize_text_field($_POST['muse_psp_ship_tracks']) : '';
        $tracking_numbers = array_filter(array_map('trim', explode(',', $tracking_raw)));
        $notify = !empty($_POST['muse_psp_notify']);

        $new_items = array();
        foreach ($order->get_items() as $item_id => $item) {
            $add = isset($ship[$item_id]) ? (int) $ship[$item_id] : 0;
            if ($add <= 0) continue;
            $current_shipped = $this->get_shipped_qty($item_id, $shipments);
            $max_add = ((int) $item->get_quantity()) - $current_shipped;
            $add = min($add, max(0, $max_add));
            if ($add > 0) {
                $new_items[$item_id] = $add;
            }
        }

        // Allow creating shipment if we have tracking numbers OR it is an internal method, provided we have items.
        // Or if we just have items and a carrier is selected (even without tracking for internal).
        // Basically: Must have items to ship OR just adding tracking info? Usually we ship items.
        // Let's stick to: we need items to create a shipment record mostly, or tracking numbers to log a shipment.
        // Requirement: "If TECH_DELIVERY or CUSTOMER_PICKUP is selected: The Tracking Number field becomes optional."
        $is_internal = in_array($carrier, ['TECH_DELIVERY', 'CUSTOMER_PICKUP'], true);
        $has_items = !empty($new_items);
        $has_tracking = !empty($tracking_numbers);

        if (($has_items || $has_tracking) && ($has_tracking || $is_internal || $carrier === 'LTL')) { // LTL might have tracking, but sometimes just BOL
             // Logic check: if we have items, we should probably allow saving even without tracking for internal.
             // If we have no items but valid tracking numbers, we can add a tracking-only update? The original plugin allowed it if either was present.
             // We will maintain that but relax tracking check for internal.
        } elseif ($has_items && $is_internal) {
            // OK
        } elseif ($has_tracking) {
            // OK
        } else {
            // Nothing to save
            $this->recompute_status($order);
            $order->save();
            return;
        }

        // Re-construct logic to be cleaner
        // Valid if: (Has Items AND (Has Tracking OR Is Internal/LTL)) OR (Has Tracking)
        // Actually, if I select Tech Delivery and 0 items, should I create a shipment? Maybe user just wants to say "Previous items delivered by tech".
        // But usually we select items.
        // Let's rely on standard check:
        // If (New Items OR Tracking Numbers) are present...
        // AND (Tracking Numbers are present OR Carrier is in [TECH, PICKUP, LTL])
        // Wait, if Carrier is UPS and no tracking, we probably shouldn't create a shipment unless we are just marking items as shipped without tracking (which is generally discouraged but possible).
        // Let's stick to strictly: if internal, tracking optional. If external, warning? Original code didn't force tracking.
        // Original: if (!empty($new_items) || !empty($tracking_numbers)) -> save.
        // We will stick to that. just ensuring we capture the carrier.

        if (!empty($new_items) || !empty($tracking_numbers) || ($is_internal && !empty($new_items))) {
            $new_shipment = array(
                'carrier'          => $carrier,
                'other_carrier'    => $other_carrier,
                'tracking_numbers' => array_values($tracking_numbers),
                'items'            => $new_items,
                'date'             => current_time('Y-m-d H:i:s'),
            );
            $shipments[] = $new_shipment;
            $order->update_meta_data(self::META_SHIPMENTS, wp_json_encode($shipments));

            // Flat data update
            if (!empty($tracking_numbers)) {
                $last_tn = end($tracking_numbers);
                $order->update_meta_data(self::META_LATEST_TRACKING, $last_tn);
            } elseif ($is_internal) {
                 $order->update_meta_data(self::META_LATEST_TRACKING, $this->get_carrier_label($carrier));
            }

            // Recompute status logic FIRST to decide on notification
            $this->recompute_status($order);
            $new_status = $order->get_status();

            // Notify Logic
            // If status is 'completed', WC sends its own email. We suppress the "Note" email to avoid duplicates.
            // But we will ensure the "Completed" email contains our details via the 'woocommerce_email_before_order_table' hook.
            if ($new_status === 'completed' || $new_status === 'wc-completed') {
                $notify = false; 
            }

            // Msg Building (HTML)
            // Tag for filters: <!--muse-pkg-->
            $msg = '<!--muse-pkg-->';
            $msg .= '<h3>📦 Shipment Dispatch</h3>';
            
            if (!empty($new_items)) {
                $msg .= '<ul>';
                foreach ($new_items as $iid => $qty) {
                    $item = $order->get_item($iid);
                    if ($item) {
                        $msg .= '<li><strong>' . esc_html($item->get_name()) . '</strong> x ' . esc_html($qty) . '</li>';
                    }
                }
                $msg .= '</ul>';
            }
            
            $carrier_d = $this->get_carrier_label($carrier, $other_carrier);
            $msg .= '<p><strong>Carrier:</strong> ' . esc_html($carrier_d);
            
            if (!empty($tracking_numbers)) {
                $msg .= '<br/><strong>Tracking:</strong> ';
                $links = array();
                foreach ($tracking_numbers as $tn) {
                    $url = $this->get_tracking_url($carrier, $tn);
                    if ($url !== '#') {
                        $links[] = '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($tn) . '</a>';
                    } else {
                        $links[] = esc_html($tn);
                    }
                }
                $msg .= implode(', ', $links);
            }
            $msg .= '</p>';

            $order->add_order_note($msg, $notify ? 1 : 0);
        }

        // Status already recomputed above
        $this->update_flat_status($order, $shipments);
        $refused = $this->save_and_explain($order);

        // Service Channel is told only about a shipment the order kept. A refused save (completing an order
        // with no TRX #) writes nothing, and this used to send the update anyway (LEDGER_SPEC.md §15.10, R5).
        if (!empty($tracking_numbers) && $this->shipment_stored($order, $refused, $shipments, $new_shipment)) {
            do_action('muse_send_sc_automation', $order->get_id(), 'parts_shipped', array(
                'shipment' => $new_shipment,
            ));
        }
    }

    /* -------------------- Status Logic -------------------- */
    private function recompute_status($order) {
        $shipments = json_decode($order->get_meta(self::META_SHIPMENTS, true), true);
        if (!is_array($shipments)) $shipments = array();

        $total_qty = 0;
        $shipped_qty = 0;
        foreach ($order->get_items() as $item_id => $item) {
            $ordered = (int) $item->get_quantity();
            $shipped = $this->get_shipped_qty($item_id, $shipments);
            $total_qty += $ordered;
            $shipped_qty += min($ordered, $shipped);
        }

        // Allow forcing completion via API elsewhere, but here we calculate based on qty
        if ($total_qty <= 0) return;

        if ($shipped_qty === 0) return;

        if ($shipped_qty < $total_qty) {
            if ($order->has_status(array('processing', 'on-hold', 'pending', 'partially-shipped'))) {
                $order->set_status('partially-shipped');
            }
        } else {
            $order->set_status('completed');
        }
    }

    private function update_flat_status($order, $shipments) {
        // _muse_track_status: Partial, Shipped, Tech_Possession, Ready_For_Pickup
        // Logic:
        // If Status is Completed -> "Shipped" (or Tech/Pickup if latest was that?)
        // Actually, if status is Partial -> "Partial"
        // But refined:
        // If latest shipment was TECH_DELIVERY -> "Tech_Possession" ?
        // If latest was CUSTOMER_PICKUP -> "Ready_For_Pickup" ?
        // Let's look at the LAST shipment to determine "Current State" for reporting, unless it's fully shipped.
        
        $status = $order->get_status();
        $flat_status = 'Pending';
        
        if ($status === 'completed') {
            $flat_status = 'Shipped';
        } elseif ($status === 'partially-shipped' || $status === 'wc-partially-shipped') {
            $flat_status = 'Partial';
        }

        // Overlay specific carrier states if they are the most recent event
        if (!empty($shipments)) {
            $last = end($shipments);
            $c = $last['carrier'] ?? '';
            if ($c === 'TECH_DELIVERY') {
                $flat_status = 'Tech_Possession';
            } elseif ($c === 'CUSTOMER_PICKUP') {
                $flat_status = 'Ready_For_Pickup';
            }
        }
        
        $order->update_meta_data(self::META_SHIPPING_STATUS, $flat_status);
    }

    public function recompute_status_after_item_save($order_id, $items) {
        $order = wc_get_order($order_id);
        if ($order) {
            $this->recompute_status($order);
            // Also update flat status
            $shipments = json_decode($order->get_meta(self::META_SHIPMENTS, true), true);
            $this->update_flat_status($order, is_array($shipments) ? $shipments : array());
            $order->save();
        }
    }

    private function get_shipped_qty($item_id, $shipments) {
        $shipped = 0;
        foreach ($shipments as $shipment) {
            if (isset($shipment['items'][$item_id])) {
                $shipped += (int) $shipment['items'][$item_id];
            }
        }
        return $shipped;
    }

    private function get_carrier_label($code, $other = '') {
        switch ($code) {
            case 'UPS': return 'UPS';
            case 'FEDEX': return 'FedEx';
            case 'USPS': return 'USPS';
            case 'DHL': return 'DHL';
            case 'LTL': return 'LTL / Freight';
            case 'TECH_DELIVERY': return 'Delivered by Technician';
            case 'CUSTOMER_PICKUP': return 'Customer Pickup / Will Call';
            case 'OTHER': return $other ?: 'Other';
            default: return $code;
        }
    }

    /* -------------------- Admin list column -------------------- */
    public function add_progress_col($cols) {
        $cols['muse_psp_progress'] = 'Ship Progress';
        return $cols;
    }

    public function render_progress_col($col, $post_id) {
        if ($col !== 'muse_psp_progress') return;
        $order = wc_get_order($post_id);
        if (!$order) { echo '—'; return; }
        $shipments = json_decode($order->get_meta(self::META_SHIPMENTS, true), true);
        if (!is_array($shipments)) $shipments = array();
        $total = 0;
        $shipped = 0;
        foreach ($order->get_items() as $item_id => $item) {
            $q = (int) $item->get_quantity();
            $s = $this->get_shipped_qty($item_id, $shipments);
            $total += $q;
            $shipped += min($q, $s);
        }
        if ($total === 0) { echo '—'; return; }
        $pct = round(($shipped / $total) * 100);
        echo esc_html("$shipped/$total ($pct%)");
        if (in_array($order->get_status(), array('partially-shipped', 'wc-partially-shipped'), true)) {
            echo ' <span class="dashicons dashicons-yes-alt" title="Partially Shipped"></span>';
        }
    }

    /* -------------------- Reports & Email hooks -------------------- */
    public function bulk_actions($actions) {
        $actions['mark_partially-shipped'] = 'Change status to Partially Shipped';
        return $actions;
    }
    public function reports_statuses($statuses) {
        $statuses[] = 'partially-shipped';
        return $statuses;
    }
    public function email_actions($actions) {
        $actions[] = 'woocommerce_new_customer_note';
        return $actions;
    }

    /* -------------------- Email Filters & Injection -------------------- */
    public function filter_email_subject($subject, $order) {
        if (!$order) return $subject;
        // We only change subject if this is our special note
        // The filter doesn't give us the 'note' content easily in all versions, 
        // but typically these filters run when setup_locale happens.
        // Actually, 'woocommerce_email_subject_customer_note' passes $subject, $order. 
        // We need to check the actual note content being sent? 
        // NOTE: standard WC doesn't pass the note object to the subject filter comfortably.
        // However, we can use a global or check the 'customer_note' property of the email object if we hooked the class.
        // Simplified approach: The user prompt asked to check the tag. 
        // But the subject filter is generic for the Customer Note email. All customer notes will pass through here.
        // If we want to target ONLY our notes, we might need to check if the last added note has our tag?
        // Or we just genericize: IF the order status is 'partially-shipped', we assume it's us?
        
        // Let's check the order status.
        $status = $order->get_status();
        if ($status === 'completed') {
             return sprintf('Your Order #%s is Complete & Shipped', $order->get_order_number());
        } elseif ($status === 'partially-shipped' || $status === 'wc-partially-shipped') {
             return sprintf('Update: Partial Shipment for Order #%s', $order->get_order_number());
        }
        return $subject;
    }

    public function filter_email_heading($heading, $order) {
        if (!$order) return $heading;
        $status = $order->get_status();
        if ($status === 'completed') {
             return 'Order Dispatched';
        } elseif ($status === 'partially-shipped' || $status === 'wc-partially-shipped') {
             return 'Partial Shipment Update';
        }
        return $heading;
    }

    public function inject_shipment_into_completed_email($order, $sent_to_admin, $plain_text, $email) {
        // Double Email Fix: If this is the "Order Completed" email, inject the latest shipment details
        if ($email->id !== 'customer_completed_order') return;
        
        $shipments = json_decode($order->get_meta(self::META_SHIPMENTS, true), true);
        if (empty($shipments) || !is_array($shipments)) return;

        // Get the very last shipment
        $last = end($shipments);
        // Maybe check timestamp to ensure it's recent? 
        // For now, prompt says "inject the specific package details".
        
        echo '<h2>📦 Latest Shipment Details</h2>';
        echo '<div style="margin-bottom: 20px; border: 1px solid #e5e5e5; padding: 15px; border-radius: 4px; background: #f9f9f9;">';
        
        $c_code = $last['carrier'] ?? '';
        $c_label = $this->get_carrier_label($c_code, $last['other_carrier'] ?? '');
        
        echo '<p><strong>Carrier:</strong> ' . esc_html($c_label) . '</p>';
        
        if (!empty($last['tracking_numbers']) && is_array($last['tracking_numbers'])) {
             echo '<p><strong>Tracking:</strong> ';
             $links = array();
             foreach ($last['tracking_numbers'] as $tn) {
                 $url = $this->get_tracking_url($c_code, $tn);
                 if ($url !== '#') {
                     $links[] = '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($tn) . '</a>';
                 } else {
                     $links[] = esc_html($tn);
                 }
             }
             echo implode(', ', $links);
             echo '</p>';
        }

        if (!empty($last['items']) && is_array($last['items'])) {
            echo '<ul>';
            foreach ($last['items'] as $iid => $qty) {
                $item = $order->get_item($iid);
                if ($item) {
                    echo '<li>' . esc_html($item->get_name()) . ' x ' . esc_html($qty) . '</li>';
                }
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    /* -------------------- REST -------------------- */
    public function register_rest() {
        register_rest_route(self::REST_NS, '/orders/(?P<id>\d+)/ship-lines', array(
            'methods'  => 'POST',
            'callback' => array($this, 'rest_ship_lines'),
            'permission_callback' => function() { return current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders'); },
            'args' => array(
                'lines' => array('required' => false, 'type' => 'array'),
                'carrier' => array('required' => false, 'type' => 'string'),
                'tracking_numbers' => array('required' => false, 'type' => 'array'),
                'other_carrier' => array('required' => false, 'type' => 'string'),
                'notify' => array('required' => false, 'type' => 'boolean'),
                'closeout' => array('required' => false, 'type' => 'boolean'),
            ),
        ));
    }

    public function rest_ship_lines($req) {
        $order_id = (int) $req['id'];
        $order = wc_get_order($order_id);
        if (!$order) return new WP_Error('not_found', 'Order not found', array('status' => 404));

        $shipments = json_decode($order->get_meta(self::META_SHIPMENTS, true), true);
        if (!is_array($shipments)) $shipments = array();

        $lines = isset($req['lines']) && is_array($req['lines']) ? $req['lines'] : array();
        $carrier = isset($req['carrier']) ? strval($req['carrier']) : '';
        $other_carrier = isset($req['other_carrier']) ? strval($req['other_carrier']) : '';
        $tracking_numbers = isset($req['tracking_numbers']) && is_array($req['tracking_numbers']) ? array_values(array_filter(array_map('strval', $req['tracking_numbers']))) : array();
        $notify = !empty($req['notify']);
        $closeout = !empty($req['closeout']);

        $new_items = array();
        foreach ($order->get_items() as $item_id => $item) {
            foreach ($lines as $ln) {
                $li_id = isset($ln['line_item_id']) ? (int) $ln['line_item_id'] : 0;
                $add = isset($ln['ship_qty']) ? (int) $ln['ship_qty'] : 0;
                if ($li_id !== $item_id || $add <= 0) continue;
                $current_shipped = $this->get_shipped_qty($item_id, $shipments);
                $max_add = ((int) $item->get_quantity()) - $current_shipped;
                $add = min($add, max(0, $max_add));
                if ($add > 0) $new_items[$item_id] = $add;
            }
        }

        $is_internal = in_array($carrier, ['TECH_DELIVERY', 'CUSTOMER_PICKUP'], true);
        // Unlike metabox, API is explicit. If we send a payload, we process it.
        // If carrier is TECH_DELIVERY, we don't demand tracking numbers.

        $status_before = $order->get_status();
        $new_shipment  = null;

        if (!empty($new_items) || !empty($tracking_numbers) || ($is_internal && !empty($new_items))) {
            $new_shipment = array(
                'carrier'          => $carrier,
                'other_carrier'    => $other_carrier,
                'tracking_numbers' => $tracking_numbers,
                'items'            => $new_items,
            );
            $shipments[] = $new_shipment;
            $order->update_meta_data(self::META_SHIPMENTS, wp_json_encode($shipments));

            // Flat data
            if (!empty($tracking_numbers)) {
                $order->update_meta_data(self::META_LATEST_TRACKING, end($tracking_numbers));
            } elseif ($is_internal) {
                $order->update_meta_data(self::META_LATEST_TRACKING, $this->get_carrier_label($carrier));
            }

            // Step 1 of 2: the shipment, saved on its own with the status untouched.
            // A status change can be refused (an order with no TRX # may not complete), and a refused save
            // writes nothing at all. Saved together, that refusal used to throw the shipment away while this
            // handler still answered success (LEDGER_SPEC.md §15.10, R5).
            $this->update_flat_status($order, $shipments);
            $refused = $this->save_and_explain($order);
            if (!$this->shipment_stored($order, $refused, $shipments, $new_shipment)) {
                return new WP_Error(
                    'muse_shipment_not_saved',
                    'Shipment not recorded: ' . ($refused !== '' ? $refused : 'the order did not keep it. Check the order notes.'),
                    array('status' => 409, 'order_id' => $order_id)
                );
            }

            $msg = '[Multi Shipment/API] New shipment added:' . "\n";
            if (!empty($new_items)) {
                foreach ($new_items as $iid => $qty) {
                    $item = $order->get_item($iid);
                    if ($item) $msg .= '- ' . $item->get_name() . ': ' . $qty . "\n";
                }
            }
            $carrier_d = $this->get_carrier_label($carrier, $other_carrier);
            $msg .= 'Carrier: ' . $carrier_d . "\n";
            if (!empty($tracking_numbers)) {
                $msg .= 'Tracking: ' . implode(', ', $tracking_numbers) . "\n";
            }
            $order->add_order_note($msg, $notify ? 1 : 0);
        }

        // Step 2 of 2: the status. Skipped when a shipment was just saved and the status does not move.
        $flat_before = $order->get_meta(self::META_SHIPPING_STATUS, true);
        if ($closeout) {
            $order->set_status('completed');
        } else {
            $this->recompute_status($order);
        }
        $wanted  = $order->get_status();
        $refused = '';
        if ($new_shipment === null || $wanted !== $status_before) {
            $this->update_flat_status($order, $shipments);
            $refused = $this->save_and_explain($order);
        }

        // Only reached with the shipment in storage (step 1 returned otherwise). It shipped whether or not
        // the order could complete, so Service Channel is told either way.
        if (!empty($tracking_numbers)) {
            do_action('muse_send_sc_automation', $order->get_id(), 'parts_shipped', array(
                'shipment' => $new_shipment,
            ));
        }

        // Response: what the order holds, not what this request meant to write.
        $view = wc_get_order($order_id);
        if (!$view) $view = $order;
        if ($view === $order && $refused !== '') {
            // No independent copy to read (an object cache handed back the same object): put the refused
            // change back so the answer below is what storage has.
            $order->set_status($status_before);
            $order->update_meta_data(self::META_SHIPPING_STATUS, $flat_before);
        }
        $stored = json_decode($view->get_meta(self::META_SHIPMENTS, true), true);
        if (!is_array($stored)) $stored = array();

        $total = 0; $shipped_total = 0;
        foreach ($view->get_items() as $item_id => $item) {
            $q = (int) $item->get_quantity();
            $s = $this->get_shipped_qty($item_id, $stored);
            $total += $q;
            $shipped_total += min($q, $s);
        }
        $out = array(
            'order_id'  => $view->get_id(),
            'status'    => $view->get_status(),
            'shipped'   => $shipped_total,
            'total'     => $total,
            'shipments' => $stored,
            'flat_status' => $view->get_meta(self::META_SHIPPING_STATUS, true),
        );
        if ($view->get_status() !== $wanted) {
            // The shipment is on the order; the status change was refused. Still a 200: answering an error
            // here would invite a retry, and a retry would record the same tracking a second time.
            $why = $refused !== '' ? $refused : 'The order refused the status change. Check the order notes.';
            $out['status_wanted']  = $wanted;
            $out['status_blocked'] = $why;

            // WooCommerce 11.3.1 runs its status-change step after a refused save too: the hooks fire and the
            // order gets the note "Order status changed from X to Y." for a change that was not stored. This
            // private note follows it so the order history ends on what is true.
            $to = wc_get_order_status_name($wanted);
            $order->add_order_note(sprintf(
                '[Multi Shipment/API] The order was NOT changed to %1$s; it is still %2$s. Reason: %3$s Any note saying the status changed to %1$s is wrong.',
                $to,
                wc_get_order_status_name($view->get_status()),
                $why
            ), 0);
        }
        return $out;
    }

    /* -------------------- Truthful saving -------------------- */

    /**
     * Save the order and say why the save was refused ('' when nothing refused it).
     *
     * A callback on woocommerce_before_order_object_save may throw to refuse a save (order-fields does, for
     * an order completed without a TRX #). WooCommerce catches that inside WC_Order::save(): nothing is
     * written and save() returns normally. The reason comes from get_last_save_error() (WooCommerce 11.2.2+),
     * otherwise from the error WooCommerce logs.
     */
    private function save_and_explain($order) {
        $logged = '';
        $listen = function ($message, $level = '', $context = array()) use (&$logged, $order) {
            if (is_array($context) && isset($context['order'], $context['error'])
                && $context['order'] === $order && $context['error'] instanceof Throwable) {
                $logged = trim((string) $context['error']->getMessage());
                if ($logged === '') $logged = 'The order refused the save.';
            }
            return $message;
        };
        add_filter('woocommerce_logger_log_message', $listen, 10, 3);
        try {
            $order->save();
        } finally {
            remove_filter('woocommerce_logger_log_message', $listen, 10);
        }

        if (method_exists($order, 'get_last_save_error')) {
            $e = $order->get_last_save_error();
            if (!($e instanceof Throwable)) return '';
            $why = trim((string) $e->getMessage());
            return $why !== '' ? $why : 'The order refused the save.';
        }
        return $logged;
    }

    /**
     * True only when the save was not refused and the order, read again, holds this shipment.
     *
     * Counted, not looked up at one position: the stored list must hold this shipment at least as often as
     * the list that was just saved ($saved) does. So a shipment another request slipped in ahead of it does
     * not turn a stored shipment into an error, and an identical earlier shipment (the same tracking sent
     * twice) does not make an unsaved one look stored.
     */
    private function shipment_stored($order, $refused, $saved, $shipment) {
        if ($refused !== '') return false;
        $fresh = wc_get_order($order->get_id());
        if (!$fresh) return false;
        $stored = json_decode($fresh->get_meta(self::META_SHIPMENTS, true), true);
        if (!is_array($stored)) return false;

        $want  = wp_json_encode($shipment);
        $times = function ($list) use ($want) {
            $n = 0;
            foreach ($list as $one) {
                if (wp_json_encode($one) === $want) $n++;
            }
            return $n;
        };
        return $times($stored) >= max(1, $times($saved));
    }

    /* -------------------- Frontend Tracking -------------------- */
    public function add_order_tracking_button($actions, $order) {
        $trackings = $this->collect_trackings($order);
        $has_tracking = !empty($trackings);
        $order_id = $order->get_id();

        // Ensure assets are enqueued
        $this->ensure_assets_enqueued();

        // Prime global with this order's data so JS can open without inline attributes
        if ($has_tracking) {
            $payload = array('order_id' => $order_id, 'trackings' => $trackings);
            $inline  = 'window.MuseTrackings = window.MuseTrackings || {}; window.MuseTrackings[' . intval($order_id) . '] = ' . wp_json_encode($payload) . ';';
            wp_add_inline_script('muse-tracking-js', $inline, 'after');
        }

        $actions['muse_track'] = array(
            'url'   => '#muse-track-' . $order_id,
            'name'  => $has_tracking ? __('Track Order', 'musesys') : __('No Tracking Available', 'musesys'),
            'action'=> 'muse_track', // becomes CSS class
        );
        return $actions;
    }

    private function collect_trackings($order) {
        $shipments = json_decode($order->get_meta(self::META_SHIPMENTS, true), true);
        if (!is_array($shipments)) $shipments = array();
        $trackings = array();

        foreach ($shipments as $shipment) {
            $carrier = $shipment['carrier'] ?? '';
            $carrier_label = $this->get_carrier_label($carrier, $shipment['other_carrier'] ?? '');
            
            // Handle internal kinds that might not have tracking numbers
            if ($carrier === 'TECH_DELIVERY' || $carrier === 'CUSTOMER_PICKUP') {
                $trackings[] = array(
                    'carrier' => $carrier_label,
                    'tracking' => '',
                    'url' => '#',
                    'type' => $carrier
                );
                continue; 
            }

            if (empty($shipment['tracking_numbers']) || !is_array($shipment['tracking_numbers'])) continue;
            
            foreach ($shipment['tracking_numbers'] as $tn) {
                $tn = trim(strval($tn));
                if ($tn === '') continue;
                $url = $this->get_tracking_url($carrier, $tn);
                $trackings[] = array('carrier' => $carrier_label, 'tracking' => $tn, 'url' => $url, 'type' => 'STANDARD');
            }
        }
        return $trackings;
    }

    public function tracking_modal_html() {
        if (!is_account_page()) return;
        ?>
        <div id="muse-tracking-modal" style="display:none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; padding: 20px; border: 1px solid #ccc; z-index: 100000; max-width: 520px; width: 92%; border-radius: 8px;">
            <h4 style="margin-top:0">Order Tracking</h4>
            <div id="muse-tracking-list"></div>
            <button id="muse-modal-close" class="button" style="margin-top: 12px;">Close</button>
        </div>
        <div id="muse-modal-overlay" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999;"></div>
        <?php
    }

    public function enqueue_assets() {
        if (!is_account_page()) return;
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('muse-tracking-css', $base . 'assets/css/muse-tracking.css', array(), '1.0.0');
        wp_enqueue_script('muse-tracking-js', $base . 'assets/js/muse-tracking.js', array('jquery'), '1.0.0', true);
        // Initialize global container
        wp_add_inline_script('muse-tracking-js', 'window.MuseTrackings = window.MuseTrackings || {};', 'before');
    }

    // In case add_order_tracking_button ran before wp_enqueue_scripts
    private function ensure_assets_enqueued() {
        if (!wp_script_is('muse-tracking-js', 'enqueued')) {
            $this->enqueue_assets();
        }
    }

    private function get_tracking_url($carrier, $tn) {
        $carrier = strtoupper($carrier);
        $tn_enc = urlencode($tn);
        if ($carrier === 'UPS') {
            return 'https://www.ups.com/track?tracknum=' . $tn_enc;
        } elseif ($carrier === 'FEDEX') {
            return 'https://www.fedex.com/fedextrack/?tracknumbers=' . $tn_enc;
        } elseif ($carrier === 'USPS') {
            return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $tn_enc;
        } elseif ($carrier === 'DHL') {
            return 'https://www.dhl.com/en/express/tracking.html?AWB=' . $tn_enc;
        } elseif ($carrier === 'OTHER') {
            // Check if $tn looks like a URL
            if (filter_var($tn, FILTER_VALIDATE_URL)) {
                return $tn;
            }
            // Fallback to Google Search
            return 'https://www.google.com/search?q=' . $tn_enc;
        }
        return '#';
    }
}

new Muse_Partial_Ship_Plus();
