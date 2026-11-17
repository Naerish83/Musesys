<?php

/**
 * Plugin Name: MuseSys Account Dashboard
 * Description: Custom card-based account dashboard for Wegmans with enhanced navigation and mobile-responsive design
 * Version: 1.2.1
 * Author: MuseSystems
 */
defined('ABSPATH') || exit;

class MuseSys_Account_Dashboard
{

    private static $initialized = false;

    public static function init()
    {
        if (self::$initialized) return;
        self::$initialized = true;

        add_shortcode('musesys_account_dashboard', [__CLASS__, 'render_shortcode']);
        add_action('wp_head', [__CLASS__, 'add_css']);
        add_action('wp_footer', [__CLASS__, 'add_js_and_modal']);

        // SYLVA ADDITION: Enqueue Dashicons for the frontend
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // --- DASHBOARD FILTERS (STATUS & TYPE) ---
        add_action('woocommerce_before_account_orders', [__CLASS__, 'render_combined_filter_ui']);
        add_filter('woocommerce_my_account_my_orders_query', [__CLASS__, 'filter_dashboard_query']);
        add_filter('woocommerce_account_orders_columns', [__CLASS__, 'update_dashboard_columns']);
        add_action('woocommerce_my_account_my_orders_column_muse-wo-details', [__CLASS__, 'render_wo_details_column']);

        add_action('init', [__CLASS__, 'register_custom_endpoints']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'add_custom_menu_items'], 40);

        add_action('woocommerce_account_product-requests_endpoint', [__CLASS__, 'product_requests_content']);
        add_action('woocommerce_account_favorite-locations_endpoint', [__CLASS__, 'favorite_locations_content']);
        add_action('woocommerce_account_favorite-products_endpoint', [__CLASS__, 'favorite_products_content']);

        // Multi-WO Support
        // Note: Using add_action because standard WC templates (or overrides) typically use do_action for columns. 
        // If this was a filter, it would have crashed due to args mismatch.
        add_action('woocommerce_my_account_my_orders_column_order-number', [__CLASS__, 'custom_order_column_content'], 10, 1);
        add_action('wp_ajax_get_work_orders', [__CLASS__, 'ajax_get_work_orders']);

        // Globally swap order numbering on frontend account pages
        add_filter('woocommerce_order_number', [__CLASS__, 'filter_global_order_number'], 10, 2);
    }

    public static function enqueue_assets()
    {
        wp_enqueue_style('dashicons');
        // Localize script for AJAX if we were enqueuing a real script, but we'll use inline JS for simplicity

        // Filter Auto-Submit JS
        if (is_account_page()) {
            wp_add_inline_script('jquery', "
                jQuery(document).ready(function($){
                    $('.muse-dash-filter-group input[type=checkbox]').on('change', function(){
                        $('#muse-dashboard-filter-form').submit();
                    });
                });
            ");
        }
    }

    /* --------------------------------------------------------------
        DASHBOARD FILTERS
    -------------------------------------------------------------- */

    public static function render_combined_filter_ui($has_orders)
    {
        $user_id = get_current_user_id();

        // 1. Determine Selections (GET -> User Meta -> Defaults)
        $saved = get_user_meta($user_id, 'muse_dashboard_filters', true);
        if (is_array($saved)) {
            // Defensively clean/ignore legacy work order type keys
            unset($saved['type'], $saved['eam'], $saved['sc'], $saved['service_channel'], $saved['work_order_type'], $saved['muse_type']);
        } else {
            $saved = [];
        }

        // Check if form was submitted
        if (isset($_GET['muse_filter_nonce']) && wp_verify_nonce($_GET['muse_filter_nonce'], 'muse_filter_update')) {
            // Update mode
            $status_sel = isset($_GET['muse_status']) ? (array)$_GET['muse_status'] : [];

            update_user_meta($user_id, 'muse_dashboard_filters', [
                'status' => $status_sel,
            ]);

            $current_status = $status_sel;
        } else {
            // Load mode
            $current_status = isset($saved['status']) ? $saved['status'] : ['wc-processing', 'wc-pending', 'wc-on-hold']; // Defaults
        }

        // Definitions
        $statuses = [
            'wc-processing'       => 'Processing',
            'wc-pending'          => 'Order Placed (Pending)',
            'wc-on-hold'          => 'On Hold',
            'wc-partially-shipped' => 'Partially Shipped',
            'wc-completed'        => 'Completed'
        ];

?>
        <div class="muse-dashboard-filters-wrapper">
            <form method="get" id="muse-dashboard-filter-form">
                <?php wp_nonce_field('muse_filter_update', 'muse_filter_nonce'); ?>

                <div class="muse-filter-section">
                    <strong>Status:</strong>
                    <div class="muse-dash-filter-group">
                        <?php foreach ($statuses as $slug => $label): ?>
                            <label>
                                <input type="checkbox" name="muse_status[]" value="<?php echo esc_attr($slug); ?>"
                                    <?php checked(in_array($slug, $current_status)); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
        </div>
        <style>
            .muse-dashboard-filters-wrapper {
                background: #f8f9fa;
                border: 1px solid #e9ecef;
                padding: 15px;
                margin-bottom: 20px;
                border-radius: 4px;
            }

            .muse-filter-section {
                margin-bottom: 10px;
            }

            .muse-filter-section strong {
                display: block;
                margin-bottom: 5px;
                color: #495057;
            }

            .muse-dash-filter-group {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
            }

            .muse-dash-filter-group label {
                margin-bottom: 0;
                cursor: pointer;
                font-weight: normal;
            }
        </style>
    <?php
    }

    public static function filter_dashboard_query($args)
    {
        $user_id = get_current_user_id();
        $saved = get_user_meta($user_id, 'muse_dashboard_filters', true);

        // If we are currently submitting, use GET (so we don't lag behind one refresh). 
        // Actually, render_combined_filter_ui runs BEFORE this query hook? 
        // WC 'woocommerce_my_account_my_orders_query' runs inside the shortcode [woocommerce_my_account].
        // 'woocommerce_before_account_orders' runs inside the template `my-orders.php`.
        // The query happens FIRST. So render_ui runs AFTER query.
        // We must process logic HERE too, or rely on the previous save.

        // Handle Submission Logic here cleanly
        $status_sel = [];

        if (isset($_GET['muse_filter_nonce']) && wp_verify_nonce($_GET['muse_filter_nonce'], 'muse_filter_update')) {
            $status_sel = isset($_GET['muse_status']) ? (array)$_GET['muse_status'] : [];
            // Note: We don't save here, UI render handles save. We just use values.
        } elseif (is_array($saved)) {
            $status_sel = isset($saved['status']) ? $saved['status'] : ['wc-processing', 'wc-pending', 'wc-on-hold'];
        } else {
            // Defaults
            $status_sel = ['wc-processing', 'wc-pending', 'wc-on-hold'];
        }

        // Apply Status
        if (!empty($status_sel)) {
            $args['post_status'] = $status_sel;
        }

        return $args;
    }

    private static function build_work_order_type_query(array $type_sel)
    {
        $type_query = ['relation' => 'OR'];

        foreach (array_unique($type_sel) as $type) {
            if ($type === 'sc') {
                $type_query[] = [
                    'relation' => 'OR',
                    [
                        'key'     => '_searchable_wo',
                        'value'   => '^[0-9]{9}$',
                        'compare' => 'REGEXP',
                    ],
                    [
                        'key'     => '_muse_wo_global',
                        'value'   => '^[0-9]{9}$',
                        'compare' => 'REGEXP',
                    ],
                    [
                        'key'     => '_primary_work_order',
                        'value'   => 'SC-',
                        'compare' => 'LIKE',
                    ],
                ];
            } elseif ($type === 'eam') {
                $type_query[] = [
                    'relation' => 'OR',
                    [
                        'key'     => '_searchable_wo',
                        'value'   => '^[0-9]{7}$',
                        'compare' => 'REGEXP',
                    ],
                    [
                        'key'     => '_muse_wo_global',
                        'value'   => '^[0-9]{7}$',
                        'compare' => 'REGEXP',
                    ],
                    [
                        'key'     => '_primary_work_order',
                        'value'   => 'EAM-',
                        'compare' => 'LIKE',
                    ],
                ];
            }
        }

        return count($type_query) > 1 ? $type_query : [];
    }

    public static function update_dashboard_columns($columns)
    {
        // Remove Total, Add Details
        $new_cols = [];
        foreach ($columns as $key => $label) {
            if ($key === 'order-total') {
                $new_cols['muse-wo-details'] = __('Order Details', 'musesys');
            } else {
                $new_cols[$key] = $label;
            }
        }
        return $new_cols;
    }

    public static function render_wo_details_column($order)
    {
        if (!$order) return;

        // Get Data
        $wos = (array) get_post_meta($order->get_id(), '_work_orders', true); // Consolidated array
        $install_stores = get_post_meta($order->get_id(), '_install_stores', true);
        $global_install_store = get_post_meta($order->get_id(), '_muse_store_global', true);

    ?>
        <div class="muse-wo-split-view">
            <div class="muse-wo-left">
                <strong>WOs:</strong>
                <?php if (empty($wos)): ?>
                    <span class="muse-empty">—</span>
                <?php else: ?>
                    <ul>
                        <?php foreach ($wos as $wo): ?>
                            <li>
                                <?php echo esc_html($wo['display']); ?>
                                <span class="muse-micro-badge <?php echo esc_attr($wo['type']); ?>"><?php echo esc_html(strtoupper($wo['type'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="muse-wo-right">
                <strong>Stores:</strong>
                <?php
                $shown = false;
                if (is_array($install_stores) && !empty($install_stores)) {
                    // Extract just the store numbers
                    $store_numbers = array_column($install_stores, 'number');
                    // Remove duplicates
                    $store_numbers = array_unique($store_numbers);

                    if (!empty($store_numbers)) {
                        echo esc_html(implode(', ', $store_numbers));
                        $shown = true;
                    }
                }

                if (!$shown && !empty($global_install_store) && !is_array($global_install_store)) {
                    echo esc_html($global_install_store);
                    $shown = true;
                }

                // Fallbacks for standard cart or PR-sourced orders that didn't go through the consolidator
                if (!$shown) {
                    $fallback = get_post_meta($order->get_id(), '_musesys_install_store', true);
                    if (!$fallback) $fallback = get_post_meta($order->get_id(), '_muse_install_store', true);

                    if (!empty($fallback)) {
                        echo esc_html($fallback);
                        $shown = true;
                    }
                }

                if (!$shown) {
                    echo '<span class="muse-empty">N/A</span>';
                }
                ?>
            </div>
        </div>
        <style>
            .muse-wo-split-view {
                display: flex;
                gap: 10px;
                font-size: 0.9em;
                min-width: 280px;
                /* Ensure space */
            }

            .muse-wo-left,
            .muse-wo-right {
                flex: 1;
            }

            .muse-wo-left ul,
            .muse-wo-right ul {
                margin: 0;
                padding: 0;
                list-style: none;
            }

            .muse-wo-left li,
            .muse-wo-right li {
                margin-bottom: 2px;
                white-space: nowrap;
            }

            .muse-micro-badge {
                font-size: 9px;
                padding: 1px 3px;
                border-radius: 3px;
                margin-left: 3px;
                vertical-align: text-top;
            }

            .muse-micro-badge.sc {
                background: #e6f4ea;
                color: #1e7e34;
            }

            .muse-micro-badge.eam {
                background: #fff3cd;
                color: #856404;
            }

            .muse-empty {
                color: #aaa;
            }
        </style>
    <?php
    }

    /* --------------------------------------------------------------
        MULTI-WORK ORDER UI
    -------------------------------------------------------------- */

    public static function custom_order_column_content($order)
    {
        try {
            // Defensive check: verify order object
            if (! $order || ! is_object($order) || ! method_exists($order, 'get_id')) {
                return;
            }

            $order_id = $order->get_id();
            $view_url = $order->get_view_order_url();

            $primary_wo = get_post_meta($order_id, '_muse_wo_global', true);
            if (empty($primary_wo)) $primary_wo = get_post_meta($order_id, 'muse_global_work_order', true);
            if (empty($primary_wo)) $primary_wo = get_post_meta($order_id, '_musesys_work_order', true);

            // Re-check array if string keys are missing
            $wos = (array) get_post_meta($order_id, '_work_orders', true);
            if (empty($primary_wo) && !empty($wos) && isset($wos[0]['display'])) {
                $primary_wo = $wos[0]['display'];
            }

            $wo_count = (int) get_post_meta($order_id, '_work_order_count', true);
            if ($wo_count === 0 && count($wos) > 0) $wo_count = count($wos);

            if (! empty($primary_wo)) {
                echo '<a href="' . esc_url($view_url) . '">';
                echo '#' . esc_html($primary_wo);

                // Badge for multiple WOs
                if ($wo_count > 1) {
                    echo ' <span class="wo-badge" title="' . ($wo_count - 1) . ' additional work orders">[+' . ($wo_count - 1) . ']</span>';
                }
                echo '</a>';

                // Trigger for modal
                if ($wo_count > 1) {
                    echo ' <button class="wo-details-trigger" data-order-id="' . esc_attr($order_id) . '" title="View all work orders">
                        <span class="dashicons dashicons-list-view"></span>
                    </button>';
                }
            } elseif (function_exists('gerhardt_get_muse_track_status')) {
                // Fallback to legacy function
                echo '<a href="' . esc_url($view_url) . '">';
                echo gerhardt_get_muse_track_status($order_id, 'badge');
                echo '</a>';
            } else {
                // Final Fallback natively altered by `filter_global_order_number` below
                echo '<a href="' . esc_url($view_url) . '">';
                echo '#' . $order->get_order_number();
                echo '</a>';
            }
        } catch (Exception $e) {
            error_log('MuseSys Dashboard Error: ' . $e->getMessage());
            if (is_object($order) && method_exists($order, 'get_order_number')) {
                echo '#' . $order->get_order_number();
            }
        }
    }

    public static function ajax_get_work_orders()
    {
        check_ajax_referer('musesys-ajax-nonce', 'nonce');

        $order_id = intval($_POST['order_id']);

        // Verify user has access to this order
        $order = wc_get_order($order_id);
        if (!$order || $order->get_customer_id() !== get_current_user_id()) {
            wp_send_json_error('Access denied');
            return;
        }

        // Use Consolidated Meta
        $work_orders = get_post_meta($order_id, '_work_orders', true);

        if (empty($work_orders) || !is_array($work_orders)) {
            $work_orders = array();

            // Fallback: try legacy if function exists
            if (function_exists('muse_get_all_work_orders')) {
                $work_orders = muse_get_all_work_orders($order_id);
            }
        }

        wp_send_json_success(array(
            'work_orders' => $work_orders
        ));
    }

    public static function filter_global_order_number($order_id, $order)
    {
        // Enforce only replacing WP post IDs on the frontend account flow for active users
        if (is_admin() || !is_object($order) || (!is_account_page() && !is_wc_endpoint_url('view-order'))) {
            return $order_id;
        }

        $id = $order->get_id();
        $primary_wo = get_post_meta($id, '_muse_wo_global', true);
        if (empty($primary_wo)) $primary_wo = get_post_meta($id, 'muse_global_work_order', true);
        if (empty($primary_wo)) $primary_wo = get_post_meta($id, '_musesys_work_order', true);

        if (empty($primary_wo)) {
            $wos = (array) get_post_meta($id, '_work_orders', true);
            if (!empty($wos) && isset($wos[0]['display'])) {
                $primary_wo = $wos[0]['display'];
            }
        }

        if (!empty($primary_wo)) {
            return $primary_wo;
        }

        return $order_id;
    }

    public static function add_js_and_modal()
    {
        if (!is_account_page()) return;
    ?>
        <!-- Work Orders Detail Modal -->
        <div id="wo-details-modal" class="wo-modal" style="display:none;">
            <div class="wo-modal-content">
                <span class="wo-modal-close">&times;</span>
                <h3>Work Orders for Order <span id="wo-modal-order-id"></span></h3>
                <div id="wo-modal-body">
                    <!-- Populated via JS -->
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Open modal on button click
                // Use delegation since table might update via AJAX in some themes, though usually page reload
                $('body').on('click', '.wo-details-trigger', function(e) {
                    e.preventDefault();
                    const orderId = $(this).data('order-id');
                    const btn = $(this);

                    // Fetch work orders via AJAX
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'get_work_orders',
                            order_id: orderId,
                            nonce: '<?php echo wp_create_nonce('musesys-ajax-nonce'); ?>'
                        },
                        beforeSend: function() {
                            btn.addClass('loading');
                        },
                        success: function(response) {
                            btn.removeClass('loading');
                            if (response.success) {
                                $('#wo-modal-order-id').text(orderId);

                                let html = '<ul class="wo-list">';
                                response.data.work_orders.forEach(function(wo, index) {
                                    html += '<li>';
                                    html += '<strong>' + wo.display + '</strong>';
                                    html += ' <span class="wo-type-badge wo-type-' + wo.type + '">' + wo.type.toUpperCase() + '</span>';
                                    if (wo.line_item_id) {
                                        html += ' <span class="wo-line-ref">(Line item #' + wo.line_item_id + ')</span>';
                                    }
                                    html += '</li>';
                                });
                                html += '</ul>';

                                $('#wo-modal-body').html(html);
                                $('#wo-details-modal').fadeIn(200);
                            }
                        }
                    });
                });

                // Close modal
                $('.wo-modal-close, #wo-details-modal').on('click', function(e) {
                    if (e.target === this || $(e.target).hasClass('wo-modal-close')) {
                        $('#wo-details-modal').fadeOut(200);
                    }
                });
            });
        </script>
    <?php
    }

    /* --------------------------------------------------------------
        ENDPOINTS
    -------------------------------------------------------------- */

    public static function register_custom_endpoints()
    {
        add_rewrite_endpoint('product-requests', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('favorite-locations', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('favorite-products', EP_ROOT | EP_PAGES);

        if (get_option('musesys_endpoints_flushed') !== 'yes') {
            flush_rewrite_rules();
            update_option('musesys_endpoints_flushed', 'yes');
        }
    }

    public static function add_custom_menu_items($items)
    {
        $logout = $items['customer-logout'];
        unset($items['customer-logout']);

        $items['product-requests'] = __('Product Requests', 'musesys');
        $items['favorite-locations'] = __('Favorite Locations', 'musesys');
        $items['favorite-products'] = __('Favorite Products', 'musesys');

        $items['customer-logout'] = $logout;
        return $items;
    }

    /* --------------------------------------------------------------
        CONTENT HANDLERS
    -------------------------------------------------------------- */

    public static function product_requests_content()
    {
        if (class_exists('MuseProductRequest')) {
            MuseProductRequest::render_account_endpoint();
        } else {
            echo do_shortcode('[muse_product_request_form]');
        }
    }

    public static function favorite_locations_content()
    {
        echo do_shortcode('[musesys_favorite_selector]');
    }

    public static function favorite_products_content()
    {
        echo do_shortcode('[musesys_favorite_products]');
    }

    /* --------------------------------------------------------------
        ASSETS
    -------------------------------------------------------------- */

    public static function add_css()
    {
        if (!is_account_page()) return;

        // Multi-WO Styles
    ?>
        <style>
            /* Disabled tabs/cards */
            .musesys-account-tabs a.muse-disabled-tab {
                opacity: 0.5;
                cursor: not-allowed;
                pointer-events: none;
                background: #eaeaea;
                color: #888;
            }
            .musesys-dashboard-card.muse-disabled-card {
                opacity: 0.5;
                cursor: not-allowed;
                pointer-events: none;
                background: #fafafa;
                border-color: #eee;
            }
            .musesys-dashboard-card.muse-disabled-card:hover {
                transform: none !important;
                box-shadow: none !important;
                border-color: #eee !important;
            }

            /* Work order badge */
            .wo-badge {
                display: inline-block;
                background: #0073aa;
                color: white;
                padding: 1px 5px;
                border-radius: 3px;
                font-size: 0.85em;
                margin-left: 4px;
                cursor: help;
                vertical-align: middle;
            }

            /* Details trigger button */
            .wo-details-trigger {
                background: transparent;
                border: none;
                color: #0073aa;
                cursor: pointer;
                padding: 0 4px;
                vertical-align: middle;
                line-height: 1;
            }

            .wo-details-trigger:hover {
                color: #005177;
            }

            .wo-details-trigger .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
            }

            /* Modal styling */
            .wo-modal {
                position: fixed;
                z-index: 99999;
                left: 0;
                top: 0;
                width: 100%;
                height: 100vh;
                background-color: rgba(0, 0, 0, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .wo-modal-content {
                background-color: #fff;
                margin: 10% auto;
                padding: 25px;
                border-radius: 8px;
                width: 90%;
                max-width: 500px;
                position: relative;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            }

            .wo-modal-close {
                position: absolute;
                right: 15px;
                top: 10px;
                font-size: 28px;
                font-weight: bold;
                cursor: pointer;
                color: #aaa;
            }

            .wo-modal-close:hover {
                color: #333;
            }

            .wo-list {
                list-style: none;
                padding: 0;
                margin: 0;
            }

            .wo-list li {
                padding: 12px 0;
                border-bottom: 1px solid #eee;
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px;
            }

            .wo-list li:last-child {
                border-bottom: none;
            }

            .wo-type-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 0.75em;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .wo-type-sc {
                background: #e6f4ea;
                color: #1e7e34;
                border: 1px solid #c3e6cb;
            }

            .wo-type-eam {
                background: #fff3cd;
                color: #856404;
                border: 1px solid #ffeeba;
            }

            .wo-line-ref {
                color: #666;
                font-size: 0.85em;
                font-style: italic;
                margin-left: auto;
            }
        </style>
        <?php

        // SYLVA FIX: Hide the "Order #..." title ONLY on the View Order page
        if (is_wc_endpoint_url('view-order')) {
        ?>
            <style>
                /* Option 1: Hides standard Woo/Theme page titles on this endpoint */
                .woocommerce-account .entry-title,
                .woocommerce-account .page-title,
                .woocommerce-account h1,
                .woocommerce-account h2.woocommerce-order-details__title {
                    display: none !important;
                }

                /* Option 2: Hides the specific mark element if your theme uses mark tags for order numbers */
                .woocommerce-account mark.order-number {
                    display: none !important;
                }

                /* SAFETY: Bring back the "Order details" table header if it got hidden */
                .woocommerce-order-details>h2 {
                    display: block !important;
                    margin-top: 20px;
                }
            </style>
        <?php
        }

        // Standard CSS for your Dashboard Cards
        ?>
        <style>
            /* Tab Navigation */
            .musesys-account-tabs {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
                margin-bottom: 2rem;
                border-bottom: 2px solid #e0e0e0;
                padding-bottom: 1rem;
            }

            .musesys-account-tabs a {
                padding: 0.75rem 1.5rem;
                background: #f5f5f5;
                border: 1px solid #ddd;
                border-radius: 4px 4px 0 0;
                text-decoration: none;
                color: #333;
                font-weight: 600;
                transition: all 0.2s;
            }

            .musesys-account-tabs a:hover {
                background: #e8e8e8;
            }

            .musesys-account-tabs a.active {
                background: #2E5C99;
                /* Wegmans blue */
                color: #fff;
                border-color: #2E5C99;
            }

            /* Dashboard Cards */
            .musesys-dashboard-cards {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 1.5rem;
                margin-top: 2rem;
            }

            .musesys-dashboard-card {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 2rem;
                text-align: center;
                transition: all 0.3s;
                cursor: pointer;
                text-decoration: none;
                display: block;
            }

            .musesys-dashboard-card:hover {
                border-color: #2E5C99;
                box-shadow: 0 4px 12px rgba(46, 92, 153, 0.15);
                transform: translateY(-2px);
            }

            /* Icon Styling */
            .musesys-dashboard-card-icon {
                font-size: 3rem;
                margin-bottom: 1rem;
                display: block;
                color: #2E5C99;
                /* Brand Color Icons */
                width: 100%;
                height: auto;
            }

            .musesys-dashboard-card-icon.dashicons {
                font-size: 3rem;
                width: 3rem;
                height: 3rem;
                text-decoration: none;
            }

            .musesys-dashboard-card-title {
                font-size: 1.25rem;
                font-weight: 700;
                color: #333;
                margin-bottom: 0.5rem;
                display: block;
            }

            .musesys-dashboard-card-desc {
                font-size: 0.9rem;
                color: #666;
                line-height: 1.5;
                display: block;
            }

            /* Mobile Responsive */
            @media screen and (max-width: 767px) {
                .musesys-account-tabs {
                    flex-direction: column;
                }

                .musesys-dashboard-cards {
                    grid-template-columns: 1fr;
                }
            }
        </style>
    <?php
    }

    /* --------------------------------------------------------------
        DASHBOARD LOGIC
    -------------------------------------------------------------- */

    public static function render_shortcode()
    {
        if (!is_user_logged_in()) {
            return '<p>Please <a href="' . esc_url(wc_get_page_permalink('myaccount')) . '">log in</a> to view your dashboard.</p>';
        }

        ob_start();

        $current_user = wp_get_current_user();
        global $wp;

        $endpoints = [
            'dashboard'          => wc_get_account_endpoint_url('dashboard'),
            'orders'             => wc_get_account_endpoint_url('orders'),
            'order-history'      => wc_get_account_endpoint_url('order-history'),
            'product-requests'   => wc_get_account_endpoint_url('product-requests'),
            'favorite-locations' => wc_get_account_endpoint_url('favorite-locations'),
            'favorite-products'  => wc_get_account_endpoint_url('favorite-products'),
            'edit-account'       => wc_get_account_endpoint_url('edit-account'),
            'logout'             => wc_get_endpoint_url('customer-logout', '', wc_get_page_permalink('myaccount')),
        ];

        $active_tab = 'dashboard';

        if (isset($wp->query_vars['product-requests'])) {
            $active_tab = 'product-requests';
        } elseif (isset($wp->query_vars['order-history'])) {
            $active_tab = 'order-history';
        } elseif (isset($wp->query_vars['favorite-locations'])) {
            $active_tab = 'favorite-locations';
        } elseif (isset($wp->query_vars['favorite-products'])) {
            $active_tab = 'favorite-products';
        } elseif (is_wc_endpoint_url('view-order')) {
            $active_tab = 'orders';
        } else {
            foreach ($endpoints as $key => $url) {
                if (in_array($key, ['dashboard', 'logout', 'product-requests', 'favorite-locations', 'favorite-products'])) continue;
                if (is_wc_endpoint_url($key)) {
                    $active_tab = $key;
                    break;
                }
            }
        }

    ?>
        <div class="musesys-account-tabs">
            <a href="<?php echo esc_url($endpoints['dashboard']); ?>" class="<?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
            <a href="<?php echo esc_url($endpoints['orders']); ?>" class="<?php echo $active_tab === 'orders' ? 'active' : ''; ?>">Orders</a>
            <a href="#" class="muse-disabled-tab <?php echo $active_tab === 'order-history' ? 'active' : ''; ?>" onclick="event.preventDefault(); return false;">Original Order History</a>
            <a href="<?php echo esc_url($endpoints['edit-account']); ?>" class="<?php echo $active_tab === 'edit-account' ? 'active' : ''; ?>">Account Details</a>
            <a href="<?php echo esc_url($endpoints['product-requests']); ?>" class="<?php echo $active_tab === 'product-requests' ? 'active' : ''; ?>">Product Requests</a>
            <a href="<?php echo esc_url($endpoints['favorite-locations']); ?>" class="<?php echo $active_tab === 'favorite-locations' ? 'active' : ''; ?>">Favorite Locations</a>
            <a href="<?php echo esc_url($endpoints['favorite-products']); ?>" class="<?php echo $active_tab === 'favorite-products' ? 'active' : ''; ?>">Favorite Products</a>
            <a href="<?php echo esc_url($endpoints['logout']); ?>">Logout</a>
        </div>

        <div class="musesys-account-content">
            <?php
            if ($active_tab === 'dashboard') {
                self::render_dashboard_cards($endpoints, $current_user);
            } elseif ($active_tab === 'order-history') {
                do_action('woocommerce_account_order-history_endpoint');
            } elseif ($active_tab === 'product-requests') {
                do_action('woocommerce_account_product-requests_endpoint');
            } elseif ($active_tab === 'favorite-locations') {
                do_action('woocommerce_account_favorite-locations_endpoint');
            } elseif ($active_tab === 'favorite-products') {
                do_action('woocommerce_account_favorite-products_endpoint');
            } else {
                echo '<div class="woocommerce-MyAccount-content" style="width:100% !important; float:none !important;">';
                do_action('woocommerce_account_content');
                echo '</div>';
            }
            ?>
        </div>
    <?php

        return ob_get_clean();
    }

    public static function render_dashboard_cards($endpoints, $current_user)
    {
    ?>
        <div class="musesys-dashboard-home">
            <h3>Hello <?php echo esc_html($current_user->first_name . ' ' . $current_user->last_name); ?>!</h3>
            <p>Welcome to your account dashboard.</p>

            <div class="musesys-dashboard-cards">
                <a href="<?php echo esc_url($endpoints['orders']); ?>" class="musesys-dashboard-card">
                    <span class="musesys-dashboard-card-icon dashicons dashicons-cart"></span>
                    <span class="musesys-dashboard-card-title">Orders</span>
                    <span class="musesys-dashboard-card-desc">View your order history and track shipments</span>
                </a>

                <a href="#" class="musesys-dashboard-card muse-disabled-card" onclick="event.preventDefault(); return false;">
                    <span class="musesys-dashboard-card-icon dashicons dashicons-backup" style="color:#aaa;"></span>
                    <span class="musesys-dashboard-card-title" style="color:#888;">Original Order History</span>
                    <span class="musesys-dashboard-card-desc">Browse orders placed on the previous portal</span>
                </a>

                <a href="<?php echo esc_url($endpoints['product-requests']); ?>" class="musesys-dashboard-card">
                    <span class="musesys-dashboard-card-icon dashicons dashicons-clipboard"></span>
                    <span class="musesys-dashboard-card-title">Product Requests</span>
                    <span class="musesys-dashboard-card-desc">Submit and manage product requests</span>
                </a>

                <a href="<?php echo esc_url($endpoints['favorite-locations']); ?>" class="musesys-dashboard-card">
                    <span class="musesys-dashboard-card-icon dashicons dashicons-location"></span>
                    <span class="musesys-dashboard-card-title">Favorite Locations</span>
                    <span class="musesys-dashboard-card-desc">Manage your favorite store and warehouse addresses</span>
                </a>

                <a href="<?php echo esc_url($endpoints['favorite-products']); ?>" class="musesys-dashboard-card">
                    <span class="musesys-dashboard-card-icon dashicons dashicons-star-filled"></span>
                    <span class="musesys-dashboard-card-title">Favorite Products</span>
                    <span class="musesys-dashboard-card-desc">View and manage your favorite products</span>
                </a>

                <a href="<?php echo esc_url($endpoints['edit-account']); ?>" class="musesys-dashboard-card">
                    <span class="musesys-dashboard-card-icon dashicons dashicons-id"></span>
                    <span class="musesys-dashboard-card-title">Account Details</span>
                    <span class="musesys-dashboard-card-desc">Update your profile and contact information</span>
                </a>
            </div>
        </div>
<?php
    }
}

MuseSys_Account_Dashboard::init();