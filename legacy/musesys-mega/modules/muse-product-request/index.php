<?php
/**
 * Plugin Name: Muse Product Request Form
 * Description: Adds a product request handler for Wegmans with WooCommerce integration, Multi-item support, and Direct Order workflows.
 * Version: 2.0.8
 * Author: Muse
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SHOW_PR_ORDER_LINK')) {
    define('SHOW_PR_ORDER_LINK', true);
}

/**
 * Main plugin class for Muse Product Request.
 */
class MuseProductRequest {
    private const MAX_TOTAL_UPLOAD_BYTES = 20971520; // 20 MB across all uploaded files.
    private const MAX_UPLOAD_FILES = 10; // Matches the server's max_file_uploads.
    private const PR_ID_PREFIX = 'PR-';

    private static function prefix_request_id(string $request_id): string {
        $request_id = strtoupper(trim($request_id));
        if ($request_id === '') {
            return '';
        }

        if (str_starts_with($request_id, self::PR_ID_PREFIX)) {
            return $request_id;
        }

        return self::PR_ID_PREFIX . $request_id;
    }

    private static function get_request_meta_value(int $post_id): string {
        $custom = strtoupper(trim((string) get_post_meta($post_id, '_muse_pr_id', true)));
        return self::prefix_request_id($custom);
    }

    private static function maybe_sync_request_id_from_tracking(int $post_id, string $fallback = ''): string {
        $tracking_display = strtoupper(trim(self::get_tracking_display($post_id)));
        if ($tracking_display !== '') {
            $request_id = self::prefix_request_id($tracking_display);
            update_post_meta($post_id, '_muse_pr_id', $request_id);
            return $request_id;
        }

        $fallback = strtoupper(trim($fallback));
        if ($fallback !== '') {
            $request_id = self::prefix_request_id($fallback);
            update_post_meta($post_id, '_muse_pr_id', $request_id);
            return $request_id;
        }

        return self::get_request_meta_value($post_id);
    }
    
    private static function parse_work_order_meta(string $tracking_num): array {
        $tracking_num = preg_replace('/\D/', '', trim($tracking_num));
        $tracking_type = 'legacy';
        $tracking_display = '';

        if (preg_match('/^\d{9}$/', $tracking_num)) {
            $tracking_type = 'sc';
            $tracking_display = "SC-{$tracking_num}";
        } elseif (preg_match('/^\d{7}$/', $tracking_num)) {
            $tracking_type = 'eam';
            $tracking_display = "EAM-{$tracking_num}";
        } elseif ($tracking_num !== '') {
            $tracking_display = "WO-{$tracking_num}";
        }

        return [
            'number' => $tracking_num,
            'type' => $tracking_type,
            'display' => $tracking_display,
        ];
    }

    private static function get_tracking_display(int $post_id): string {
        $wos = get_post_meta($post_id, '_work_orders', true);
        if (is_array($wos) && !empty($wos[0]['display'])) {
            return (string) $wos[0]['display'];
        }

        $legacy_display = get_post_meta($post_id, '_tracking_display', true);
        if (!empty($legacy_display)) {
            return (string) $legacy_display;
        }

        $tracking_num = get_post_meta($post_id, '_muse_scwo_number', true);
        return self::parse_work_order_meta((string) $tracking_num)['display'];
    }

    private static function save_tracking_meta(int $post_id, string $tracking_num): void {
        $tracking = self::parse_work_order_meta($tracking_num);

        update_post_meta($post_id, '_muse_scwo_number', $tracking['number']);
        update_post_meta($post_id, '_tracking_number', $tracking['number']);

        if ($tracking['type'] !== '') {
            update_post_meta($post_id, '_tracking_type', $tracking['type']);
        } else {
            delete_post_meta($post_id, '_tracking_type');
        }

        if ($tracking['display'] !== '') {
            update_post_meta($post_id, '_tracking_display', $tracking['display']);
            update_post_meta($post_id, '_primary_work_order', $tracking['display']);
            update_post_meta($post_id, '_work_orders', [[
                'number' => $tracking['number'],
                'type' => $tracking['type'],
                'display' => $tracking['display'],
                'source' => 'product_request',
                'line_item_id' => 0,
            ]]);
            update_post_meta($post_id, '_work_order_count', 1);
        } else {
            delete_post_meta($post_id, '_tracking_display');
            delete_post_meta($post_id, '_primary_work_order');
            delete_post_meta($post_id, '_work_orders');
            update_post_meta($post_id, '_work_order_count', 0);
        }
    }

    private static function extract_item_data_from_link(string $mapped_link): array {
        $result = [
            'eam_num' => '',
            'part_num' => '',
        ];

        if ($mapped_link === '') {
            return $result;
        }

        $path = wp_parse_url($mapped_link, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return $result;
        }

        $slug = trim((string) basename($path), '/');
        if ($slug === '') {
            return $result;
        }

        if (preg_match('/^eam-(\d+)-(.+)$/i', $slug, $matches)) {
            $result['eam_num'] = $matches[1];
            $remainder = $matches[2];

            if (preg_match('/(\d+(?:-\d+)+)$/', $remainder, $part_matches)) {
                $result['part_num'] = strtoupper($part_matches[1]);
            }
        }

        return $result;
    }

    /**
     * Is this a stored row that carries nothing but a description?
     *
     * These are the residue of the removed "+ Add Note" admin feature. They are never
     * products, so they must be excluded from product-ID matching and from cart/order
     * building. Kept (not deleted) so the note text is not silently destroyed.
     */
    private static function is_note_only_row($row): bool {
        if (!is_array($row)) {
            return false;
        }
        if (absint($row['mapped_id'] ?? 0) > 0) {
            return false;
        }
        foreach (['mapped_link', 'direct_link', 'part_num', 'eam_num', 'asset_tag'] as $key) {
            if (!empty($row[$key])) {
                return false;
            }
        }
        return trim((string) ($row['desc'] ?? '')) !== '';
    }

    /**
     * Is this a raw $_POST row produced by the removed "+ Add Note" button?
     *
     * That button rendered a single [desc] textarea and no other inputs, so the absence
     * of every product key is a reliable fingerprint. Real item rows always post the
     * hidden part_num/qty fields, and admin-added product rows always post mapped_*.
     */
    private static function is_posted_note_row(array $row): bool {
        if (trim((string) ($row['desc'] ?? '')) === '') {
            return false;
        }
        foreach (['mapped_id', 'mapped_qty', 'mapped_link', 'part_num', 'eam_num', 'asset_tag', 'qty'] as $key) {
            if (array_key_exists($key, $row)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Log an item whose product post ID could not be resolved.
     *
     * These used to fail silently: the row was dropped from the add-to-cart link and
     * skipped by place_order_from_request(), with no trace anywhere.
     */
    private static function log_unresolved_product(int $post_id, $raw_id, string $context): void {
        error_log(sprintf(
            '[muse-pr] PR #%d: unresolved product post ID (raw: "%s") in %s — item is not addable to cart and will be skipped at order time.',
            $post_id,
            is_scalar($raw_id) ? (string) $raw_id : gettype($raw_id),
            $context
        ));
    }

    private static function normalize_product_item(array $item, int $post_id = 0): array {
        // Direct Link resolution
        $mapped_link = isset($item['mapped_link']) ? esc_url_raw($item['mapped_link']) : '';
        if ($mapped_link === '' && !empty($item['direct_link'])) {
            $mapped_link = esc_url_raw($item['direct_link']);
        }
        if ($mapped_link === '' && !empty($item['link'])) {
            $mapped_link = esc_url_raw($item['link']);
        }

        $parsed_link_data = self::extract_item_data_from_link($mapped_link);

        // NOTE: `item_id` is deliberately NOT a part_num source. In this system it is
        // always the WP post ID (see mapped_id resolution below), never a SKU/part number.
        $part_num = sanitize_text_field($item['part_num'] ?? ($item['part_number'] ?? ''));
        $eam_num = sanitize_text_field($item['eam_num'] ?? ($item['sku'] ?? ''));
        $asset_tag = sanitize_text_field($item['asset_tag'] ?? '');
        $wo_number = sanitize_text_field($item['wo_number'] ?? '');
        $store_number = sanitize_text_field($item['store_number'] ?? '');
        $desc = sanitize_textarea_field($item['desc'] ?? '');
        
        // Resolve mapped ID / Product ID consistently.
        // All three keys carry the same thing: the WP post ID of the product.
        // `item_id` is what UACP/Teammate sends; it MUST be honoured here, otherwise the
        // append path in complete_request_api() falls back to guessing the ID from the
        // link and silently yields mapped_id = 0.
        $mapped_id = absint($item['mapped_id'] ?? ($item['product_id'] ?? ($item['item_id'] ?? 0)));
        
        // Quantities normalization
        $raw_qty = isset($item['qty']) ? intval($item['qty']) : 0;
        $mapped_qty = isset($item['mapped_qty']) ? intval($item['mapped_qty']) : 0;
        
        if ($raw_qty <= 0) {
            $qty = $mapped_qty > 0 ? $mapped_qty : 1;
        } else {
            $qty = $raw_qty;
        }
        
        if ($mapped_qty <= 0) {
            $mapped_qty = $qty;
        }

        if ($eam_num === '' && $parsed_link_data['eam_num'] !== '') {
            $eam_num = $parsed_link_data['eam_num'];
        }

        // The all-digits test is a legacy scrub: older rows were written before item_id
        // stopped feeding part_num, so some stored part_num values are actually post IDs.
        // Prefer the part number parsed from the product link in that case.
        if (($part_num === '' || preg_match('/^\d+$/', $part_num)) && $parsed_link_data['part_num'] !== '') {
            $part_num = $parsed_link_data['part_num'];
        }

        // If product ID is missing but direct link exists, attempt to resolve it
        if ($mapped_id === 0 && $mapped_link !== '') {
            $resolved_id = url_to_postid($mapped_link);
            if ($resolved_id > 0) {
                $mapped_id = absint($resolved_id);
            } else {
                // Product slug fallback
                $path = parse_url($mapped_link, PHP_URL_PATH);
                if ($path) {
                    $path_parts = array_filter(explode('/', $path));
                    if (!empty($path_parts)) {
                        $slug = end($path_parts);
                        $product_post = get_page_by_path($slug, OBJECT, 'product');
                        if ($product_post) {
                            $mapped_id = absint($product_post->ID);
                        }
                    }
                }
            }
        }

        // Look up product metadata for missing fields if mapped_id is valid
        if ($mapped_id > 0) {
            $product = wc_get_product($mapped_id);
            if ($product) {
                if ($part_num === '') {
                    $part_num = $product->get_name();
                }
                if ($eam_num === '') {
                    $eam_num = $product->get_sku();
                }
            }
        }

        // Explicit fallback logic for item-level store_number
        if ($store_number === '') {
            $posted_items = $_POST['muse_request_items'] ?? ($_POST['items'] ?? null);
            if (is_array($posted_items)) {
                foreach ($posted_items as $p_item) {
                    if (is_array($p_item) && !empty($p_item['store_number'])) {
                        $store_number = sanitize_text_field($p_item['store_number']);
                        break;
                    }
                }
            }
        }
        if ($store_number === '' && $post_id > 0) {
            $existing_items = get_post_meta($post_id, '_musesys_product_items', true);
            if (is_array($existing_items)) {
                foreach ($existing_items as $existing_item) {
                    if (is_array($existing_item) && !empty($existing_item['store_number'])) {
                        $store_number = sanitize_text_field($existing_item['store_number']);
                        break;
                    }
                }
            }
        }

        // Explicit fallback logic for item-level wo_number
        if ($wo_number === '') {
            $posted_items = $_POST['muse_request_items'] ?? ($_POST['items'] ?? null);
            if (is_array($posted_items)) {
                foreach ($posted_items as $p_item) {
                    if (is_array($p_item) && !empty($p_item['wo_number'])) {
                        $wo_number = sanitize_text_field($p_item['wo_number']);
                        break;
                    }
                }
            }
        }
        if ($wo_number === '' && $post_id > 0) {
            $existing_items = get_post_meta($post_id, '_musesys_product_items', true);
            if (is_array($existing_items)) {
                foreach ($existing_items as $existing_item) {
                    if (is_array($existing_item) && !empty($existing_item['wo_number'])) {
                        $wo_number = sanitize_text_field($existing_item['wo_number']);
                        break;
                    }
                }
            }
        }
        if ($wo_number === '' && $post_id > 0) {
            $wo_number = get_post_meta($post_id, '_muse_scwo_number', true);
            if ($wo_number === '') {
                $wo_number = get_post_meta($post_id, '_tracking_number', true);
            }
        }

        $images = [];
        foreach ((array) ($item['images'] ?? []) as $image_url) {
            $clean_url = esc_url_raw((string) $image_url);
            if ($clean_url !== '') {
                $images[] = $clean_url;
            }
        }

        return [
            'asset_tag' => $asset_tag,
            'part_num' => $part_num,
            'wo_number' => $wo_number,
            'store_number' => $store_number,
            'eam_num' => $eam_num,
            'qty' => $qty,
            'mapped_qty' => $mapped_qty,
            'desc' => $desc,
            'images' => $images,
            'mapped_id' => $mapped_id,
            'product_id' => $mapped_id,
            'mapped_link' => $mapped_link,
            'direct_link' => $mapped_link,
        ];
    }
    /**
     * Get Unified Display ID.
     * 
     * Priority:
     * 1. Work Order (SC/EAM) from _work_orders
     * 2. Custom PR ID (_muse_pr_id)
     * 3. Parsed from Title (Legacy)
     * 4. Fallback Generated
     * 
     * @param int $post_id
     * @return string
     */
    public static function get_display_id(int $post_id): string {
        $tracking_display = strtoupper(trim(self::get_tracking_display($post_id)));
        if ($tracking_display !== '') {
            return self::prefix_request_id($tracking_display);
        }

        $custom = self::get_request_meta_value($post_id);
        if ($custom !== '') {
            return $custom;
        }

        $title = get_the_title($post_id);
        if (preg_match('/Product Request ([A-Z0-9-]+)/i', (string) $title, $m)) {
            return self::prefix_request_id($m[1]);
        }

        $date = get_the_date('mdy', $post_id) ?: date('mdy');
        return self::prefix_request_id($date . '-' . str_pad((string)$post_id, 5, '0', STR_PAD_LEFT));
    }

    /**
     * Initialize the plugin.
     */
    /**
     * Status Constants
     */
    const PR_STATUS_PENDING = 'pending';
    const PR_STATUS_PROCESSING = 'processing';
    const PR_STATUS_AWAITING_CONFIRM = 'awaiting_confirm';
    const PR_STATUS_COMPLETED = 'completed';
    const PR_STATUS_FULFILLED = 'fulfilled';
    const PR_STATUS_REJECTED = 'rejected';

    /**
     * Get allowed statuses.
     * 
     * @return array
     */
    public static function get_allowed_statuses(): array {
        return [
            self::PR_STATUS_PENDING => 'Pending',
            self::PR_STATUS_PROCESSING => 'Processing',
            self::PR_STATUS_AWAITING_CONFIRM => 'Awaiting Confirmation',
            self::PR_STATUS_COMPLETED => 'Completed',
            self::PR_STATUS_FULFILLED => 'Fulfilled',
            self::PR_STATUS_REJECTED => 'Rejected',
        ];
    }

    /**
     * Initialize the plugin.
     */
    public static function init(): void {
        // Legacy multisite check removed


        // Register hooks
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action('init', [__CLASS__, 'handle_form_submission']);
        add_action('init', [__CLASS__, 'handle_order_confirmation']);
        add_action('init', [__CLASS__, 'register_endpoints']);
        // add_action('init', [__CLASS__, 'register_cron']);
        
        add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'add_pr_link_to_order_details'], 10, 1);
        add_action('woocommerce_email_customer_details', [__CLASS__, 'add_ship_to_to_order_email'], 10, 4);
        
        // AJAX Handlers
        add_action('wp_ajax_submit_product_request', [__CLASS__, 'handle_form_submission']);
        
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('save_post_musesys_pr', [__CLASS__, 'save_meta_box'], 10, 3);
        
        add_filter('manage_musesys_pr_posts_columns', [__CLASS__, 'set_custom_columns']);
        add_action('manage_musesys_pr_posts_custom_column', [__CLASS__, 'render_custom_column'], 10, 2);
        
        add_filter('bulk_actions-edit-musesys_pr', [__CLASS__, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-musesys_pr', [__CLASS__, 'handle_bulk_actions'], 10, 3);
        
        add_action('admin_notices', [__CLASS__, 'render_admin_notices']);
        
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'add_account_menu_item']);
        add_action('woocommerce_account_musesys-requests_endpoint', [__CLASS__, 'render_account_endpoint']);

        // Priority 25 so WooCommerce has already initialised the session/cart on wp_loaded.
        add_action('wp_loaded', [__CLASS__, 'handle_add_request_items_to_cart'], 25);
        
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        
        add_shortcode('muse_product_request_form', [__CLASS__, 'render_form']);

        // Reports
        // Reports
        // add_action('musesys_morning_report', [__CLASS__, 'generate_status_email']);
        // add_action('musesys_afternoon_report', [__CLASS__, 'generate_status_email']);

        // Manual Trigger
        // if (isset($_GET['force_muse_report']) && current_user_can('manage_options')) {
        //     add_action('init', function() { self::generate_status_email(); die('Manual Report Triggered.'); });
        // }
    }

    /**
     * Register the custom post type.
     */
    public static function register_cpt(): void {
        register_post_type('musesys_pr', [
            'labels' => [
                'name' => 'Product Requests',
                'singular_name' => 'Product Request',
                'menu_name' => 'Product Requests',
                'add_new' => 'Add New',
                'add_new_item' => 'Add New Request',
                'edit_item' => 'Edit Request',
                'new_item' => 'New Request',
                'view_item' => 'View Request',
                'search_items' => 'Search Requests',
                'not_found' => 'No requests found',
                'not_found_in_trash' => 'No requests found in Trash',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => ['title', 'author'],
            'capability_type' => 'post',
            'has_archive' => false,
            'rewrite' => false,
        ]);
    }

    /**
     * Add admin submenu.
     */
    public static function add_admin_menu(): void {
        add_submenu_page(
            'musesys',
            'Product Requests',
            'Product Requests',
            'manage_woocommerce',
            'edit.php?post_type=musesys_pr'
        );
    }

    /**
     * Register Cron Schedules.
     */
    public static function register_cron(): void {
        /*
        if (!wp_next_scheduled('musesys_morning_report')) {
            wp_schedule_event(strtotime('tomorrow 07:00:00'), 'daily', 'musesys_morning_report');
        }
        if (!wp_next_scheduled('musesys_afternoon_report')) {
            wp_schedule_event(strtotime('tomorrow 16:30:00'), 'daily', 'musesys_afternoon_report');
        }
        */
    }

    /**
     * Get and increment sequential request number.
     */
    private static function get_next_request_number(): string {
        $key = 'musesys_pr_sequence';
        $current_number = (int) get_option($key, 0);
        $new_number = $current_number + 1;
        update_option($key, $new_number);
        return str_pad((string)$new_number, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Render the multi-item form.
     */
    public static function render_form($atts = [], $content = null): string {
        // PHP 8.4 Compliance: Normalize atts
        $atts = shortcode_atts([], $atts, 'muse_product_request_form');

        if (!is_user_logged_in()) {
            return '<p>Please log in to submit a request.</p>';
        }

        // Check for preserved data / errors
        $user_id = get_current_user_id();
        $transient_key = 'musesys_pr_err_' . $user_id;
        $preserved = get_transient($transient_key);
        
        $errors = [];
        $input_data = [];

        if ($preserved) {
             $errors = $preserved['errors'] ?? [];
             $input_data = $preserved['input'] ?? [];
             // Clear it once read so it doesn't persist forever
             delete_transient($transient_key);
        }

        $prefilled_brand = $input_data['brand'] ?? (isset($_GET['part_brand']) ? sanitize_text_field($_GET['part_brand']) : '');
        $prefilled_model = $input_data['model'] ?? (isset($_GET['part_model']) ? sanitize_text_field($_GET['part_model']) : '');
        $items_data = $input_data['items'] ?? []; // Array of items if preserved

        ob_start();
        
        if (!empty($errors)) {
            echo '<div style="background:#ffebee; border:1px solid #ef9a9a; color:#c62828; padding:12px; margin-bottom:16px; border-radius:4px;">';
            echo '<strong>Please correct the following errors:</strong><ul style="margin:8px 0 0 20px;">';
            foreach ($errors as $err) {
                echo '<li>' . esc_html($err) . '</li>';
            }
            echo '</ul></div>';
        }
        ?>
        <style>
            /* Mobile First Styles (Default) */
            .muse-product-request-form {
                max-width: 100%;
                overflow-x: hidden;
                margin: 0 auto;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            }

            .muse-product-request-form,
            .muse-product-request-form * {
                box-sizing: border-box;
                -webkit-box-sizing: border-box;
            }

            /* Container padding */
            .muse-pr-container {
                padding: 16px;
                background: #fff; /* Optional: distinct background */
            }

            /* Inputs and Labels */
            .muse-label {
                display: block;
                font-weight: 600;
                margin-bottom: 8px;
                font-size: 14px;
                color: #333;
            }

            .muse-input-full, 
            .muse-product-request-form input[type="text"],
            .muse-product-request-form input[type="number"],
            .muse-product-request-form textarea,
            .muse-product-request-form select {
                width: 100%;
                height: 48px; /* Touch target size */
                padding: 10px 12px;
                font-size: 16px; /* Prevent iOS zoom */
                border: 1px solid #ccc;
                border-radius: 4px;
                margin-bottom: 0; /* Managed by grid/gap */
            }

            .muse-product-request-form textarea {
                height: 100px;
                resize: vertical;
            }

            /* Field Groups (Containers) */
            .muse-field-group {
                display: flex;
                flex-direction: column;
                gap: 16px;
                margin-bottom: 24px;
            }

            .muse-item-row {
                border: 1px solid #e0e0e0;
                background: #fafafa;
                padding: 16px;
                border-radius: 8px;
                margin-bottom: 24px;
                position: relative;
                display: flex;
                flex-direction: column;
                gap: 16px;
            }

            /* Remove Button */
            .muse-remove {
                position: absolute;
                top: 8px;
                right: 8px;
                color: #d32f2f;
                background: none;
                border: none;
                font-size: 24px;
                line-height: 1;
                cursor: pointer;
                padding: 4px;
                z-index: 10;
            }
            
            /* Add Item Button */
            .muse-add-item-btn {
                width: 100%;
                padding: 12px;
                font-size: 16px;
                margin-bottom: 32px;
                text-align: center;
                cursor: pointer;
            }

            /* Action Buttons */
            .muse-actions {
                display: flex;
                flex-direction: column;
                gap: 16px;
                align-items: center;
                margin-top: 20px;
            }

            .muse-action-btn {
                width: 100%;
                height: 54px; /* Larger hit area for primary actions */
                font-size: 16px;
                font-weight: bold;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                text-transform: uppercase;
            }

            .muse-btn-quote {
                background-color: #f7f7f7;
                border: 2px solid #333;
                color: #333;
            }

            .muse-btn-order {
                background-color: #0073aa;
                color: #fff;
            }
            
            .muse-or-divider {
                font-weight: bold;
                color: #666;
                text-transform: uppercase;
                font-size: 14px;
            }
            
            /* Helper for split cells on Mobile */
            .muse-split-2, .muse-split-qty {
                display: flex;
                flex-direction: column;
                gap: 16px;
            }

            /* Toast */
            .muse-toast {
                visibility: hidden; 
                min-width: 300px; 
                margin-left: -150px; 
                background-color: #323232; 
                color: #fff;
                text-align: center; 
                border-radius: 6px; 
                padding: 16px; 
                position: fixed; 
                z-index: 9999;
                left: 50%; 
                bottom: 30px; 
                font-size: 16px; 
                opacity: 0; 
                transition: opacity 0.5s ease-in-out, visibility 0s linear 0.5s;
            }
            .muse-toast.show { visibility: visible; opacity: 1; transition: opacity 0.5s ease-in-out; }

            /* Desktop / Tablet Breakpoint */
            
            /* iOS / Mobile Specific Fixes */
            @media screen and (max-width: 767px) {
                .muse-product-request-form input[type="text"],
                .muse-product-request-form input[type="email"],
                .muse-product-request-form input[type="tel"],
                .muse-product-request-form input[type="number"],
                .muse-product-request-form select,
                .muse-product-request-form textarea {
                    width: 100%;
                    max-width: 100%;
                    box-sizing: border-box;
                    -webkit-appearance: none;
                    margin: 0;
                    font-size: 16px; /* Prevent iOS zoom */
                }
                
                .muse-product-request-form .muse-item-row,
                .muse-product-request-form .form-row-wide {
                    width: 100%;
                    max-width: 100%;
                    padding-left: 12px;
                    padding-right: 12px;
                    margin-left: 0;
                    margin-right: 0;
                }
                
                .muse-product-request-form .muse-split-2, 
                .muse-product-request-form .muse-split-qty {
                    display: flex;
                    flex-direction: column;
                    width: 100%;
                }
            }

            /* Prevent iOS Safari default styling issues */
            @supports (-webkit-touch-callout: none) {
                .muse-product-request-form input,
                .muse-product-request-form select,
                .muse-product-request-form textarea {
                    -webkit-appearance: none;
                    border-radius: 4px; /* Ensure consistency */
                }
            }

            @media (min-width: 768px) {
                .muse-pr-container {
                    padding: 24px;
                }

                /* Brand/Model Side-by-Side */
                .muse-brand-model-row {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 24px;
                }

                /* Items Grid */
                .muse-item-grid-row {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 16px; /* Horizontal gap */
                }

                .muse-item-full-width {
                    grid-column: 1 / -1;
                }
                
                /* Layout specific for item fields */
                /* Part Number | Qty (Small) | WO # */
                /* Actually user said: Part Number/WO #: side-by-side row. 
                   I will do:
                   Row 1: Asset Tag (Full)
                   Row 2: Part Number (with Qty tucked in?) | WO #
                   Row 3: Store # | EAM #
                */
                
                /* Helper for split cells */
                .muse-split-2 {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 24px;
                }

                .muse-split-qty {
                    display: grid;
                    grid-template-columns: 3fr 1fr; /* Part number takes more space */
                    gap: 12px;
                }

                /* Action Buttons Inline */
                .muse-actions {
                    flex-direction: row;
                    justify-content: flex-start;
                }

                .muse-action-btn {
                    width: auto;
                    min-width: 200px;
                }
            }
        </style>

        <div class="muse-product-request-form">
            <form method="post" enctype="multipart/form-data" id="muse-pr-form">
                <?php wp_nonce_field('muse_product_request_submit', 'muse_nonce'); ?>
                
                <div class="muse-pr-container">
                    <!-- Work Order Tracking -->
                    <div class="muse-field-group" style="margin-bottom: 24px;">
                        <label class="muse-label">Work Order / Service Channel Number <span class="required">*</span>
                            <span style="font-size:11px; color:#666; display:block; margin-bottom:4px;">(numbers only)</span>
                            <input type="text" name="tracking_number" id="muse_muse_scwo_number" class="muse-input-full" required placeholder="9-digit Service Channel # or 7-digit EAM #">
                        </label>
                    </div>

                    <!-- Brand / Model -->
                    <div class="muse-field-group muse-brand-model-row">
                        <label class="muse-label">Brand <span class="required">*</span>
                            <input type="text" name="brand" value="<?php echo esc_attr($prefilled_brand); ?>" placeholder="Brand">
                        </label>
                        <label class="muse-label">Model <span class="required">*</span>
                            <input type="text" name="model" value="<?php echo esc_attr($prefilled_model); ?>" placeholder="Model">
                        </label>
                    </div>

                    <!-- Items Container -->
                    <div id="muse-items-container">
                        <?php 
                        // If we have preserved items, loop them. Otherwise 1 default.
                        $loop_items = !empty($items_data) ? $items_data : [0 => []]; 
                        
                        foreach ($loop_items as $k => $item_vals):
                            $idx = $k; 
                            $v_asset = esc_attr($item_vals['asset_tag'] ?? '');
                            $v_part  = esc_attr($item_vals['part_num'] ?? '');
                            $v_qty   = esc_attr($item_vals['qty'] ?? '1');
                            $v_wo    = esc_attr($item_vals['wo_number'] ?? '');
                            $v_store = esc_attr($item_vals['store_number'] ?? '');
                            $v_eam   = esc_attr($item_vals['eam_num'] ?? '');
                            $v_desc  = esc_textarea($item_vals['desc'] ?? '');
                        ?>
                        <div class="muse-item-row">
                            <?php if ($idx > 0): ?>
                                <span class="muse-remove" onclick="this.parentElement.remove()">&times;</span>
                            <?php endif; ?>

                            <!-- Desktop Split Row 1: Store# | Asset Tag -->
                            <div class="muse-split-2">
                                <div>
                                    <label class="muse-label">Install Store #
                                        <input type="text" name="items[<?php echo $idx; ?>][store_number]" value="<?php echo $v_store; ?>" placeholder="Store #">
                                    </label>
                                </div>
                                <div>
                                    <label class="muse-label">Asset Tag / Equipment ID
                                        <!--
                                        <select name="items[<?php echo $idx; ?>][asset_tag]" class="muse-asset-tag-select2">
                                            <?php if ($v_asset): ?>
                                                <option value="<?php echo esc_attr($v_asset); ?>" selected><?php echo esc_html($v_asset); ?></option>
                                            <?php else: ?>
                                                <option value="">Select Asset (Requires Store)</option>
                                            <?php endif; ?>
                                        </select>
                                        -->
                                        <input type="text" name="items[<?php echo $idx; ?>][asset_tag]" value="<?php echo $v_asset; ?>" placeholder="Asset Tag">
                                    </label>
                                </div>
                            </div>

                            <!-- Desktop Split Row 2: Part#+Qty | EAM# -->
                            <div class="muse-split-2">
                                <div class="muse-split-qty">
                                     <label class="muse-label">Part Number <span class="required">*</span>
                                        <input type="text" name="items[<?php echo $idx; ?>][part_num]" value="<?php echo $v_part; ?>" placeholder="Part #">
                                     </label>
                                     <label class="muse-label">Qty
                                        <input type="number" name="items[<?php echo $idx; ?>][qty]" value="<?php echo $v_qty; ?>" min="1">
                                     </label>
                                </div>
                                <div>
                                    <label class="muse-label">EAM #
                                        <input type="text" name="items[<?php echo $idx; ?>][eam_num]" value="<?php echo $v_eam; ?>" placeholder="EAM #">
                                    </label>
                                </div>
                            </div>

                            <label class="muse-label">Description / Notes
                                <textarea name="items[<?php echo $idx; ?>][desc]" placeholder="Describe issue or part details..."><?php echo $v_desc; ?></textarea>
                            </label>

                            <div class="muse-input-file">
                                <label class="muse-label">Images + PDFs (Hold Ctrl/Long Press to select multiple):</label>
                                <input type="file" name="item_images_<?php echo $idx; ?>[]" accept="image/*,.pdf,application/pdf" multiple="multiple">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" id="add-row" class="button muse-add-item-btn">+ Add Another Item</button>

                    <div class="muse-actions">
                        <button type="submit" name="action_type" value="request" class="muse-action-btn muse-btn-quote">Request Quote</button>
                        <span class="muse-or-divider">- OR -</span>
                        <button type="submit" name="action_type" value="direct_order" class="muse-action-btn muse-btn-order">Place Direct Order</button>
                    </div>
                </div>
                <input type="hidden" name="ship_store" id="muse_ship_store_input" value="">
            </form>
        </div>

        <div id="museToast" class="muse-toast">Request submitted successfully!</div>
        
        <!-- EAM Warning Modal -->
        <div id="muse-eam-warning-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
            <div style="background:#fff; padding:25px; max-width:500px; width:90%; border-radius:5px; box-shadow:0 4px 10px rgba(0,0,0,0.2);">
                <h3 style="margin-top:0; color:#d63638;"><span style="color: #f0ad4e; font-size: 1.2em;">&#9888;</span> EAM Work Order Detected</h3>
                <p>You entered an EAM Work Order number (7 digits).</p>
                <ul style="background:#fff8e5; padding:10px 20px; border:1px solid #f0c33c; list-style:disc; margin-left:15px; margin-bottom: 15px;">
                    <li>Status updates will NOT automatically sync to Service Channel.</li>
                    <li>You'll need to manually track order status.</li>
                </ul>
                <p><strong>Recommendation:</strong> Use Service Channel numbers (9 digits) for automatic updates.</p>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                    <button type="button" class="muse-action-btn" style="width: auto; background: #eee; color: #333;" onclick="document.getElementById('muse-eam-warning-modal').style.display='none'">Cancel</button>
                    <button type="button" class="muse-action-btn" style="width: auto; background: #0073aa; color: #fff;" id="muse-confirm-eam">Continue with EAM#</button>
                </div>
            </div>
        </div>

        <!-- Ship Store Modal (Direct Order) -->
        <div id="muse-ship-store-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
            <div style="background:#fff; padding:25px; max-width:500px; width:90%; border-radius:5px; box-shadow:0 4px 10px rgba(0,0,0,0.2);">
                <h3 style="margin-top:0; color:#0073aa;">Select Ship-To Location</h3>
                <p>Where should this order be shipped? Select a store or a warehouse — not both.</p>

                <label style="font-weight:600; display:block; margin-bottom:6px;">Store</label>
                <select id="muse-ship-store-select" style="width:100%; height:44px; font-size:16px; padding:8px; border:1px solid #ccc; border-radius:4px; margin-bottom:16px;">
                    <option value="">— Select a store —</option>
                    <?php
                    $stores = musesys_get_store_addresses();
                    foreach ($stores as $addr) {
                        $num  = trim($addr['first_name'] ?? '');
                        $name = trim($addr['last_name'] ?? '');
                        $city = trim($addr['city'] ?? '');
                        $num_padded = str_pad(preg_replace('/\D/', '', $num), 3, '0', STR_PAD_LEFT);
                        printf(
                            '<option value="store:%s">%s — %s (%s)</option>',
                            esc_attr($num_padded),
                            esc_html($num_padded),
                            esc_html($name),
                            esc_html($city)
                        );
                    }
                    ?>
                </select>

                <label style="font-weight:600; display:block; margin-bottom:6px;">— OR — Warehouse</label>
                <select id="muse-ship-warehouse-select" style="width:100%; height:44px; font-size:16px; padding:8px; border:1px solid #ccc; border-radius:4px; margin-bottom:16px;">
                    <option value="">— Select a warehouse —</option>
                    <?php
                    $warehouses = musesys_get_warehouse_addresses();
                    foreach ($warehouses as $addr) {
                        $num  = trim($addr['first_name'] ?? '');
                        $name = trim($addr['last_name'] ?? '');
                        $city = trim($addr['city'] ?? '');
                        $num_padded = str_pad(preg_replace('/\D/', '', $num), 3, '0', STR_PAD_LEFT);
                        printf(
                            '<option value="warehouse:%s">%s — %s (%s)</option>',
                            esc_attr($num_padded),
                            esc_html($num_padded),
                            esc_html($name),
                            esc_html($city)
                        );
                    }
                    ?>
                </select>

                <label style="font-weight:600; display:block; margin-bottom:6px;">Shipping Speed <span class="required" style="color:red;">*</span></label>
                <select id="muse-shipping-speed-select" style="width:100%; height:44px; font-size:16px; padding:8px; border:1px solid #ccc; border-radius:4px; margin-bottom:16px;">
                    <option value="">— Select shipping speed —</option>
                    <option value="Ground">Ground</option>
                    <option value="2nd Day Air">2nd Day Air</option>
                    <option value="Next Day Air">Next Day Air</option>
                    <option value="Saturday Delivery">Saturday Delivery</option>
                </select>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:8px;">
                    <button type="button" class="muse-action-btn" style="width:auto; background:#eee; color:#333;" id="muse-ship-store-cancel">Cancel</button>
                    <button type="button" class="muse-action-btn" style="width:auto; background:#0073aa; color:#fff;" id="muse-ship-store-confirm">Confirm & Submit</button>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tracking Validation
            const form = document.getElementById('muse-pr-form');
            const trackingInput = document.getElementById('muse_muse_scwo_number');
            const modal = document.getElementById('muse-eam-warning-modal');
            const confirmBtn = document.getElementById('muse-confirm-eam');
            let pendingAction = null;

            if (form && trackingInput) {
                // Intercept submits via buttons
                const submitBtns = form.querySelectorAll('button[type="submit"]');
                submitBtns.forEach(btn => {
                    btn.addEventListener('click', function(e) {
                         // Remove non-digits
                         const rawVal = trackingInput.value.trim();
                         const val = rawVal.replace(/\D/g, ''); 
                         
                         // If empty or non-numeric present (if regex failed), let HTML5 validation fire first if possible.
                         // But we want to catch the specific lengths.
                         
                         if (val === '') return; // Let HTML5 validation fire

                         if (val.length === 9) {
                             // Service Channel - OK, allow submit
                             return; 
                         } else if (val.length === 7) {
                             // EAM - Warn (Silenced for now)
                             // e.preventDefault();
                             // pendingAction = btn.value; // Store action (request/direct_order)
                             // modal.style.display = 'flex';
                             return; // Allow submit immediately
                         } else {
                             // Invalid length
                             e.preventDefault();
                             alert('Invalid Work Order Number.\nMust be 7-digit EAM# or 9-digit Service Channel#.');
                         }
                    });
                });

                confirmBtn.addEventListener('click', function() {
                    modal.style.display = 'none';
                    if (pendingAction) {
                        // We need to trigger the submit with the correct action name/value
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'action_type';
                        hidden.value = pendingAction;
                        form.appendChild(hidden);
                        form.submit();
                    }
                });
            }

            // Ship Store Modal — intercept Direct Order submit
            const shipStoreModal = document.getElementById('muse-ship-store-modal');
            const shipStoreSelect = document.getElementById('muse-ship-store-select');
            const shipWarehouseSelect = document.getElementById('muse-ship-warehouse-select');
            const shipStoreInput = document.getElementById('muse_ship_store_input');
            const shipStoreConfirm = document.getElementById('muse-ship-store-confirm');
            const shipStoreCancel = document.getElementById('muse-ship-store-cancel');
            const shippingSpeedSelect = document.getElementById('muse-shipping-speed-select');
            const directOrderBtn = form.querySelector('button[value="direct_order"]');

            // Either/or clearing — selecting one clears the other
            if (shipStoreSelect && shipWarehouseSelect) {
                shipStoreSelect.addEventListener('change', function() {
                    if (this.value) shipWarehouseSelect.value = '';
                });
                shipWarehouseSelect.addEventListener('change', function() {
                    if (this.value) shipStoreSelect.value = '';
                });
            }

            if (directOrderBtn && shipStoreModal) {
                directOrderBtn.addEventListener('click', function(e) {
                    // Only intercept if ship store not already chosen
                    if (shipStoreInput.value === '') {
                        e.preventDefault();
                        e.stopImmediatePropagation(); // Don't let EAM modal fire simultaneously
                        shipStoreModal.style.display = 'flex';
                    }
                    // If already populated (e.g. re-submit after EAM confirm), let it pass through
                });
            }

            if (shipStoreConfirm) {
                shipStoreConfirm.addEventListener('click', function() {
                    const selected = shipStoreSelect.value || shipWarehouseSelect.value;
                    if (!selected) {
                        alert('Please select a store or warehouse.');
                        return;
                    }
                    
                    const speed = shippingSpeedSelect ? shippingSpeedSelect.value : '';
                    if (!speed) {
                        alert('Please select a shipping speed.');
                        return;
                    }
                    
                    // Strip the type prefix before storing — just the number
                    shipStoreInput.value = selected.replace(/^(store|warehouse):/, '');
                    shipStoreModal.style.display = 'none';

                    // Also store the raw selection with prefix in a new hidden field
                    const existingShipTo = form.querySelector('input[name="ship_to"][type="hidden"]');
                    if (existingShipTo) existingShipTo.remove();
                    const shipToHidden = document.createElement('input');
                    shipToHidden.type = 'hidden';
                    shipToHidden.name = 'ship_to';
                    shipToHidden.value = selected;
                    form.appendChild(shipToHidden);

                    const existingSpeed = form.querySelector('input[name="shipping_speed"][type="hidden"]');
                    if (existingSpeed) existingSpeed.remove();

                    const speedHidden = document.createElement('input');
                    speedHidden.type = 'hidden';
                    speedHidden.name = 'shipping_speed';
                    speedHidden.value = speed;
                    form.appendChild(speedHidden);

                    const existing = form.querySelector('input[name="action_type"][type="hidden"]');
                    if (existing) existing.remove();

                    const actionHidden = document.createElement('input');
                    actionHidden.type = 'hidden';
                    actionHidden.name = 'action_type';
                    actionHidden.value = 'direct_order';
                    form.appendChild(actionHidden);

                    jQuery('#muse-pr-form').trigger('submit');
                });
            }

            if (shipStoreCancel) {
                shipStoreCancel.addEventListener('click', function() {
                    shipStoreModal.style.display = 'none';
                    shipStoreInput.value = '';
                });
            }

            // Toast Logic
            const toast = document.getElementById('museToast');
            if (window.location.search.includes('request_sent=1')) {
                toast.classList.add('show');
                setTimeout(() => { toast.classList.remove('show'); }, 4000);
            }

            // Pre-fill Asset Tag from URL
            const urlParams = new URLSearchParams(window.location.search);
            const assetTag = urlParams.get('asset_tag');
            if (assetTag) {
                const firstInput = document.querySelector('.muse-asset-tag-select2');
                if (firstInput) {
                     // Since it's a select, we might need to add the option if it doesn't exist
                     if (!jQuery(firstInput).find("option[value='" + assetTag + "']").length) {
                         var newOption = new Option(assetTag, assetTag, true, true);
                         jQuery(firstInput).append(newOption);
                     }
                     jQuery(firstInput).val(assetTag).trigger('change');
                }
            }

            initSelect2();
        });

        function initSelect2(context) {
            if (typeof jQuery === 'undefined' || typeof jQuery.fn.selectWoo === 'undefined') return;

            var $context = context ? jQuery(context) : jQuery('.muse-product-request-form');
            
            $context.find('.muse-item-row').each(function() {
                var row = jQuery(this);
                var assetSelect = row.find('.muse-asset-tag-select2');
                var storeInput = row.find('input[name*="[store_number]"]');

                if (assetSelect.length && storeInput.length) {
                    assetSelect.selectWoo({
                        placeholder: 'Select Asset',
                        allowClear: true,
                        tags: true, // Allow custom if needed
                        ajax: {
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            dataType: 'json',
                            delay: 250,
                            data: function (params) {
                                return {
                                    action: 'muse_search_assets',
                                    store_id: storeInput.val(),
                                    search_term: params.term
                                };
                            },
                            processResults: function (data) {
                                return { results: data.data.results };
                            }
                        }
                    });

                    function updateAssetStatus() {
                        if (!storeInput.val() || storeInput.val().trim() === '') {
                            assetSelect.prop('disabled', true);
                            if (assetSelect.val()) {
                                assetSelect.val(null).trigger('change');
                            }
                        } else {
                            assetSelect.prop('disabled', false);
                        }
                    }
                    storeInput.on('input change', updateAssetStatus);
                    updateAssetStatus();
                }
            });
        }

        document.getElementById('add-row').addEventListener('click', function() {
            var container = document.getElementById('muse-items-container');
            var index = container.querySelectorAll('.muse-item-row').length;
            
            // Note: We use the same structure as above, just with updated index
            var html = `
                <div class="muse-item-row">
                    <span class="muse-remove" onclick="this.parentElement.remove()">&times;</span>
                    
                    <div class="muse-split-2">
                        <div>
                            <label class="muse-label">Install Store #
                                <input type="text" name="items[${index}][store_number]" placeholder="Store #">
                            </label>
                        </div>
                        <div>
                            <label class="muse-label">Asset Tag / Equipment ID
                                <!--
                                <select name="items[${index}][asset_tag]" class="muse-asset-tag-select2">
                                    <option value="">Select Asset (Requires Store)</option>
                                </select>
                                -->
                                <input type="text" name="items[${index}][asset_tag]" placeholder="Asset Tag">
                            </label>
                        </div>
                    </div>

                    <div class="muse-split-2">
                        <div class="muse-split-qty">
                             <label class="muse-label">Part Number <span class="required">*</span>
                                <input type="text" name="items[${index}][part_num]" placeholder="Part #">
                             </label>
                             <label class="muse-label">Qty
                                <input type="number" name="items[${index}][qty]" value="1" min="1">
                             </label>
                        </div>
                        <div>
                            <label class="muse-label">EAM #
                                <input type="text" name="items[${index}][eam_num]" placeholder="EAM #">
                            </label>
                        </div>
                    </div>

                    <label class="muse-label">Description / Notes
                        <textarea name="items[${index}][desc]" placeholder="Describe issue or part details..."></textarea>
                    </label>

                    <div class="muse-input-file">
                        <label class="muse-label">Images + PDFs (Hold Ctrl/Long Press to select multiple):</label>
                        <input type="file" name="item_images_${index}[]" accept="image/*,.pdf,application/pdf" multiple="multiple">
                    </div>
                </div>`;
            container.insertAdjacentHTML('beforeend', html);
            initSelect2(container.lastElementChild);
        });
        </script>
        <?php
        // Enqueue Compressor.js and Custom Compression Script
        ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/compressorjs/1.2.1/compressor.min.js"></script>
        <script>
        jQuery(document).ready(function($) {
            $('#muse-pr-form').on('submit', function(e) {
                e.preventDefault(); // Stop the truck. We need to inspect the cargo.

                var $form = $(this);
                // Create FormData. This automatically includes the nonce field present in the form.
                var formData = new FormData($form[0]); 
                var fileInputs = $form.find('input[type="file"]');
                var compressionQueue = [];
                var totalUploadBytes = 0;
                var maxUploadBytes = <?php echo (int) self::MAX_TOTAL_UPLOAD_BYTES; ?>;

                // 1. Loop through every file input found in the form
                fileInputs.each(function(index, input) {
                    var inputName = $(input).attr('name'); // e.g., 'item_images_0[]'
                    var files = input.files;

                    // Remove the original uncompressed files from FormData 
                    // (We will add the compressed versions back in a moment)
                    formData.delete(inputName);

                    // 2. Loop through each file in this specific input
                    Array.from(files).forEach(file => {
                        totalUploadBytes += file.size || 0;
                        var promise = new Promise((resolve, reject) => {
                            if (file.type === 'application/pdf' || /\.pdf$/i.test(file.name)) {
                                resolve({
                                    blob: file,
                                    name: inputName,
                                    filename: file.name
                                });
                                return;
                            }

                            new Compressor(file, {
                                quality: 0.6, // The magic number. 60% quality is usually visually identical.
                                maxWidth: 1600, // Resize wide shots down
                                success(result) {
                                    // Resolve with the compressed blob and the ORIGINAL input name
                                    resolve({
                                        blob: result,
                                        name: inputName,
                                        filename: file.name
                                    });
                                },
                                error(err) {
                                    // If compression fails, send the original
                                    console.warn('Compression failed for ' + file.name, err);
                                    resolve({
                                        blob: file,
                                        name: inputName,
                                        filename: file.name
                                    });
                                },
                            });
                        });
                        compressionQueue.push(promise);
                    });
                });

                if (totalUploadBytes > maxUploadBytes) {
                    alert('Total upload size cannot exceed 20 MB. Please remove some files and try again.');
                    $('.muse-action-btn').prop('disabled', false).text('Try Again');
                    return;
                }

                // 3. Wait for the queue to finish, then ship it.
                Promise.all(compressionQueue).then((processedFiles) => {
                    
                    // Re-stack the FormData with the new lightweight files
                    processedFiles.forEach(item => {
                        formData.append(item.name, item.blob, item.filename);
                    });

                    // Add AJAX action
                    formData.append('action', 'submit_product_request'); 

                    // 4. Send to PHP handler
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        beforeSend: function() {
                            // Good place to show a spinner. 
                            // Reuse existing toast or button state if possible.
                            $('.muse-action-btn').prop('disabled', true).text('Compressing & Uploading...');
                        },
                        success: function(response) {
                            console.log('Done.', response);
                            if (response.success) {
                                // Reload to show success message or handle UI
                                window.location.href = window.location.pathname + '?request_sent=1';
                            } else {
                                alert('Error: ' + (response.data.errors ? response.data.errors.join('\\n') : 'Unknown error'));
                                $('.muse-action-btn').prop('disabled', false).text('Try Again');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Upload error:', error);
                            alert('Upload error: ' + error);
                            $('.muse-action-btn').prop('disabled', false).text('Try Again');
                        }
                    });
                });
            });
        });
        </script>
        <?php
        return ob_get_clean() ?: '';
    }

    /**
     * Flatten one $_FILES field into single-file arrays. Malformed or nested fields are ignored.
     */
    private static function get_item_files(string $file_key): array {
        if (!isset($_FILES[$file_key]) || !is_array($_FILES[$file_key])) {
            return [];
        }

        $field = $_FILES[$file_key];
        $files = [];
        foreach ((array) ($field['name'] ?? []) as $j => $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $pick = fn(string $key) => ((array) ($field[$key] ?? []))[$j] ?? null;
            $tmp_name = $pick('tmp_name');
            if (!is_string($tmp_name)) {
                continue;
            }
            $files[] = [
                'name'     => $name,
                'type'     => (string) $pick('type'),
                'tmp_name' => $tmp_name,
                'error'    => (int) $pick('error'),
                'size'     => (int) $pick('size'),
            ];
        }
        return $files;
    }

    /**
     * Handle form submission.
     */
    public static function handle_form_submission(): void {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['muse_nonce']) || !wp_verify_nonce($_POST['muse_nonce'], 'muse_product_request_submit')) {
            return;
        }

        $user = wp_get_current_user();
        if (!$user->ID) {
            wp_die('You must be logged in.');
        }

        $brand = sanitize_text_field($_POST['brand'] ?? '');
        $model = sanitize_text_field($_POST['model'] ?? '');
        $tracking_num = sanitize_text_field($_POST['tracking_number'] ?? '');
        $action_type = sanitize_text_field($_POST['action_type'] ?? 'request'); 

        $ship_store_raw = sanitize_text_field($_POST['ship_store'] ?? '');
        $ship_store = substr(preg_replace('/\D/', '', $ship_store_raw), 0, 3);
        $ship_store = $ship_store !== '' ? sprintf('%03d', intval($ship_store)) : '';
        
        $ship_to = sanitize_text_field($_POST['ship_to'] ?? '');

        $shipping_speed = sanitize_text_field($_POST['shipping_speed'] ?? '');

        $tracking_meta = self::parse_work_order_meta($tracking_num);
        
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $items = [];
        $errors = [];
        $has_any_content = false; // For 'Request Quote' loose validation
        $total_upload_bytes = 0;
        $allowed_mime_types = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/heic',
            'image/heif',
            'application/pdf',
        ];

        if (!empty($brand) || !empty($model)) {
            $has_any_content = true;
        }

        // Size and count are checked across every file before anything is written to disk.
        $item_files = [];
        $upload_count = 0;
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach (array_keys($_POST['items']) as $i) {
                $item_files[$i] = self::get_item_files('item_images_' . $i);
                foreach ($item_files[$i] as $file) {
                    $upload_count++;
                    $total_upload_bytes += $file['size'];
                }
            }
        }
        if ($total_upload_bytes > self::MAX_TOTAL_UPLOAD_BYTES) {
            $errors[] = 'Total upload size cannot exceed 20 MB. Please remove some files and try again.';
        }
        if ($upload_count > self::MAX_UPLOAD_FILES) {
            $errors[] = 'You can upload at most ' . self::MAX_UPLOAD_FILES . ' files per request.';
        }
        $pending_uploads = []; // item index => validated files, written only after all validation passes

        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $i => $row) {
                if (!is_array($row)) continue;

                // Determine row content checks
                $r_asset = sanitize_text_field($row['asset_tag'] ?? '');
                $r_part  = sanitize_text_field($row['part_num'] ?? '');
                $r_eam   = sanitize_text_field($row['eam_num'] ?? '');
                $r_qty   = intval($row['qty'] ?? 1);
                $r_desc  = sanitize_textarea_field($row['desc'] ?? '');
                $r_wo    = sanitize_text_field($row['wo_number'] ?? '');
                $r_store = sanitize_text_field($row['store_number'] ?? '');

                // Check for ANY content in this row
                if ($r_asset || $r_part || $r_eam || $r_desc) {
                    $has_any_content = true;
                }

                // File Upload Handling: validate here, write to disk only after all validation passes.
                $row_files = [];
                foreach ($item_files[$i] ?? [] as $file) {
                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        $errors[] = "Error uploading '{$file['name']}' (upload error {$file['error']}).";
                        continue;
                    }

                    // Validate file type by content, not just extension (images and PDFs only)
                    $file_type = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
                    $detected_type = $file_type['type'] ?? '';
                    if (!in_array($detected_type, $allowed_mime_types, true)) {
                        $errors[] = "File '{$file['name']}' must be an image or PDF.";
                        continue;
                    }
                    if (!empty($file_type['proper_filename'])) {
                        $file['name'] = $file_type['proper_filename'];
                    }

                    $row_files[] = $file;
                    $has_any_content = true; // Image counts as content
                }

                // Clean data
                $wo_clean = '';
                if (!empty($tracking_num)) {
                    $wo_clean = trim($tracking_num);
                    $wo_clean = preg_replace('/[^a-zA-Z0-9-]/', '', $wo_clean); 
                    $wo_clean = strtoupper(substr($wo_clean, 0, 20));
                }

                $store_clean = '';
                if (!empty($r_store)) {
                     $s_val = preg_replace('/\D/', '', $r_store);
                     $store_clean = substr($s_val, 0, 3);
                     if ($store_clean !== '') {
                        $s_int = intval($store_clean);
                        if ($s_int >= 0 && $s_int <= 200) {
                             $store_clean = sprintf('%03d', $s_int);
                        }
                     }
                }

                // Validation Logic
                if ($action_type === 'direct_order') {
                    // Direct Order Strict Requirements
                    // Part OR EAM is required
                    if (empty($r_part) && empty($r_eam)) {
                        $errors[] = "For Direct Orders, either Part # or EAM # is required (Row " . ($i+1) . ").";
                    }
                    if (empty($wo_clean)) {
                        $errors[] = "Work Order / Service Channel # is required for Direct Orders.";
                    }
                    if (empty($r_store)) {
                         $errors[] = "Install Store # is required for Direct Orders (Row " . ($i+1) . ").";
                    }
                    if (empty($ship_store)) {
                        $errors[] = "Ship-to Store # is required for Direct Orders.";
                    }
                    
                    // Note: Brand/Model are explicitly NOT required for Direct Order per user request.
                } 

                if (!empty($row_files)) {
                    $pending_uploads[count($items)] = $row_files;
                }

                $items[] = self::normalize_product_item([
                    'asset_tag' => $r_asset,
                    'part_num' => $r_part,
                    'wo_number' => $wo_clean, // Re-use global tracking_number as wo_number for the item
                    'store_number' => $store_clean,
                    'eam_num'  => $r_eam,
                    'qty'      => $r_qty,
                    'desc'     => $r_desc,
                    'images'   => [], // Filled in after validation, once the files are written
                    'mapped_id'=> 0 
                ]);
            }
        }

        // Global Validation
        
        // Strict Regex for Work Order
        if (!preg_match('/^(\d{7}|\d{9})$/', trim($tracking_num))) {
            $errors[] = "Invalid WO # - Please enter either a 7 digit EAM or 9 digit SC Work order #";
        }

        // For Request Quote: At least one piece of info required (Brand OR Model OR Any Item Content)
        if ($action_type === 'request') {
            if (!$has_any_content) {
                 $errors[] = "Please provide at least one piece of information (Brand, Model, or Item details).";
            }
        }
        // Direct Order global checks (if any)
        // If items array is empty, that's handled by the loop (it won't run), so we might need to check if items exist.
        if ($action_type === 'direct_order' && empty($items)) {
             $errors[] = "Please add at least one item for a direct order.";
        }

        // Validation passed: write the queued files. On any failure remove what was written,
        // so a rejected request leaves no orphan files in uploads.
        if (empty($errors)) {
            $written = [];
            foreach ($pending_uploads as $item_idx => $files) {
                foreach ($files as $file) {
                    // Use test_form = false because we are disconnected from normal WP form flow here
                    $uploaded = wp_handle_upload($file, ['test_form' => false]);

                    if (isset($uploaded['url']) && !isset($uploaded['error'])) {
                        $written[] = $uploaded['file'];
                        $items[$item_idx]['images'][] = esc_url_raw($uploaded['url']);
                    } else {
                        $errors[] = "Error uploading '{$file['name']}': " . ($uploaded['error'] ?? 'Unknown error');
                    }
                }
            }
            if (!empty($errors)) {
                foreach ($written as $path) {
                    wp_delete_file($path);
                }
            }
        }

        // If Errors exist
        if (!empty($errors)) {
            if (wp_doing_ajax()) {
                wp_send_json_error(['errors' => $errors]);
            }
            // Standard fallback
            $transient_key = 'musesys_pr_err_' . $user->ID;
            set_transient($transient_key, [
                'errors' => $errors,
                'input' => $_POST // Preserve input
            ], 60); // 1 minute expiry
            
            wp_redirect(add_query_arg('muse_error', '1', wp_get_referer()));
            exit;
        }

        // Success - Create Post
        $request_number = self::get_next_request_number();
        $date_string = date('mdy');
        $pr_code = "{$date_string}-{$request_number}"; // e.g. 012926-00330 (Sequence)
        $title = "Product Request {$pr_code} (" . strtoupper($action_type) . ")";

        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_status' => 'publish',
            'post_type' => 'musesys_pr',
            'post_author' => $user->ID,
        ]);

        if (is_wp_error($post_id)) {
            wp_die('Error creating request.');
        }

        update_post_meta($post_id, 'brand', $brand);
        update_post_meta($post_id, 'model', $model);
        self::save_tracking_meta($post_id, $tracking_meta['number']);
        update_post_meta($post_id, '_muse_pr_status', 'pending');
        update_post_meta($post_id, '_muse_pr_type', $action_type);
        update_post_meta($post_id, '_muse_ship_store', $ship_store);
        update_post_meta($post_id, '_muse_ship_to', $ship_to);
        update_post_meta($post_id, '_muse_shipping_speed', $shipping_speed);
        update_post_meta($post_id, '_musesys_product_items', $items);
        update_post_meta($post_id, '_musesys_customer_email', strtolower(trim($user->user_email)));
        self::maybe_sync_request_id_from_tracking($post_id, $pr_code);

        // Notify Admin
        self::send_admin_notification($post_id, $user, $items, $action_type);

        if (wp_doing_ajax()) {
            wp_send_json_success(['message' => 'Request submitted successfully!', 'post_id' => $post_id]);
        }
        
        wp_redirect(add_query_arg('request_sent', '1', wp_get_referer()));
        exit;
    }

    /**
     * Get human-readable store or warehouse name from ID.
     */
    private static function get_store_or_warehouse_name(string $id): string {
        $clean_id = preg_replace('/^(store:|warehouse:)/', '', $id);
        
        if (strpos($id, 'warehouse:') === 0) {
            $warehouses = function_exists('musesys_get_warehouse_addresses') ? musesys_get_warehouse_addresses() : [];
            foreach ($warehouses as $addr) {
                if (trim($addr['id'] ?? '') === $clean_id) {
                    return trim($addr['first_name'] ?? '') . " - " . trim($addr['last_name'] ?? '');
                }
            }
            return "Warehouse " . $clean_id;
        } else {
            // Assume store
            $clean_id = str_pad(preg_replace('/\D/', '', $clean_id), 3, '0', STR_PAD_LEFT);
            $stores = get_option('musesys_addresses_stores', []);
            foreach ($stores as $addr) {
                $s_num = str_pad(preg_replace('/\D/', '', trim($addr['first_name'] ?? '')), 3, '0', STR_PAD_LEFT);
                if ($s_num === $clean_id) {
                    return "Store " . $s_num . " - " . trim($addr['last_name'] ?? '');
                }
            }
            return "Store " . $clean_id;
        }
    }

    /**
     * Send email notification to admin.
     * 
     * @param int $post_id
     * @param WP_User $user
     * @param array $items
     * @param string $type
     */
    private static function send_admin_notification(int $post_id, WP_User $user, array $items, string $type): void {
        $brand = get_post_meta($post_id, 'brand', true);
        $model = get_post_meta($post_id, 'model', true);
        $ship_to_meta = get_post_meta($post_id, '_muse_ship_to', true);

        $lines = [
            "User: {$user->user_login} ({$user->user_email})",
            "Type: " . strtoupper($type),
            "Tracking: " . self::get_tracking_display($post_id),
            "Ship-To Address: " . esc_html($ship_to_meta ? self::get_store_or_warehouse_name($ship_to_meta) : '—'),
            "Shipping Speed: " . esc_html(get_post_meta($post_id, '_muse_shipping_speed', true) ?: '—'),
            "Brand/Model: " . esc_html($brand) . " / " . esc_html($model),
            "<hr><strong>Items:</strong>"
        ];
        
        foreach ($items as $item) {
            $item = self::normalize_product_item(is_array($item) ? $item : []);
            $imgs = "";
            if (!empty($item['images']) && is_array($item['images'])) {
                $imgs .= "<br><em>Images:</em> ";
                foreach ($item['images'] as $url) {
                    $imgs .= "<a href='" . esc_url($url) . "' target='_blank'>[View]</a> ";
                }
            }
            
            $part = esc_html($item['part_num']);
            $eam = esc_html($item['eam_num']);
            $asset = !empty($item['asset_tag']) ? " [Asset: " . esc_html($item['asset_tag']) . "]" : "";
            $wo = !empty($item['wo_number']) ? " (WO: " . esc_html($item['wo_number']) . ")" : "";
            
            // Add Install Store to Email Output
            $store = !empty($item['store_number']) ? " (Install Store: " . esc_html($item['store_number']) . ")" : "";
            
            $qty = $item['qty'];
            $lines[] = "- {$qty}x <strong>{$part}</strong>{$asset} (EAM: {$eam}){$wo}{$store}{$imgs}";
        }

        $body = self::render_email([
            'title' => "New Product Request: " . self::get_display_id($post_id),
            'intro' => "A new $type has been submitted.",
            'lines' => $lines,
            'button_text' => 'Manage Request',
            'button_url' => admin_url('post.php?post=' . $post_id . '&action=edit'),
            'display_id' => self::get_display_id($post_id),
        ]);

        $recipients = ['partsmaint@gerharzequipment.com', get_option('admin_email')];
        wp_mail($recipients, "[MuseSys] New $type #$post_id", $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    /**
     * Render email template.
     * 
     * @param array $args
     * @return string
     */
    private static function render_email(array $args = []): string {
        $defaults = [
            'title' => 'Product Request Update',
            'intro' => '',
            'lines' => [],
            'button_text' => '',
            'button_url' => '',
            'footer' => 'Thank you, ' . esc_html(get_bloginfo('name')),
        ];
        $args = wp_parse_args($args, $defaults);
        
        // Inject Display ID into all emails based on title context if needed, 
        // but easier to check if we have a Post ID? The render_email args don't pass post_id typically.
        // We'll trust the caller to put ID in title, OR we add it to the header logic.
        // Let's modify the header above to show "Request ID: ...".
        // But we need the ID. The 'title' arg has it now?
        // Let's parse it or pass it.
        // Updated plan: Pass 'display_id' in args. 


        $intro = $args['intro'] ? '<p style="margin:0 0 12px;">' . esc_html($args['intro']) . '</p>' : '';
        $list = '';
        if (!empty($args['lines']) && is_array($args['lines'])) {
            $list .= '<ul style="margin:0 0 16px; padding-left:20px;">';
            foreach (array_filter($args['lines']) as $line) {
                // Allow some HTML in lines
                $list .= '<li>' . wp_kses_post($line) . '</li>';
            }
            $list .= '</ul>';
        }
        $button = '';
        if ($args['button_text'] && $args['button_url']) {
            $button = '<p style="margin:16px 0;"><a href="' . esc_url($args['button_url']) . '" style="display:inline-block;padding:10px 16px;text-decoration:none;border-radius:6px;border:1px solid #222;background:#0073aa;color:#fff;">' . esc_html($args['button_text']) . '</a></p>';
        }

        ob_start();
        ?>
        <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f6f7f8;padding:24px;">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e6e7e8;">
                <tr><td style="padding:20px;">
                    <p style="margin:0 0 10px; font-size:12px; color:#999;">Request ID: <strong><?php echo esc_html(isset($args['display_id']) ? $args['display_id'] : '---'); ?></strong></p>
                    <h2 style="margin:0 0 8px;font-size:18px;"><?php echo esc_html($args['title']); ?></h2>
                    <?php echo $intro; ?>
                    <?php echo $list; ?>
                    <?php echo $button; ?>
                    <p style="margin:16px 0 0;color:#666;font-size:12px;"><?php echo esc_html($args['footer']); ?></p>
                </td></tr>
            </table>
        </div>
        <?php
        return ob_get_clean() ?: '';
    }

    /**
     * Update request status and optionally set product IDs.
     * 
     * @param int $post_id
     * @param string $new_status
     * @param array $items
     * @return bool|WP_Error
     */
    public static function update_status(int $post_id, string $new_status, array $items = []): bool|WP_Error {
        $old_status = get_post_meta($post_id, '_muse_pr_status', true) ?: 'pending';
        
        // Update items if provided
        if (!empty($items)) {
            update_post_meta($post_id, '_musesys_product_items', $items);
        } else {
            $items = get_post_meta($post_id, '_musesys_product_items', true) ?: [];
        }

        update_post_meta($post_id, '_muse_pr_status', $new_status);

        // Lifecycle Closure: Transition to private to remove from UACP default lists
        if (strtolower($new_status) === 'completed' && get_post_status($post_id) !== 'private') {
            remove_action('save_post_musesys_pr', [__CLASS__, 'save_meta_box'], 10);
            wp_update_post(['ID' => $post_id, 'post_status' => 'private']);
            add_action('save_post_musesys_pr', [__CLASS__, 'save_meta_box'], 10, 3);
        }

        // Send Email on validation change (Skip for direct orders)
        $type = get_post_meta($post_id, '_muse_pr_type', true);
        if ($old_status !== $new_status && $type !== 'direct_order') {
            $author_id = (int) get_post_field('post_author', $post_id);
            $user = $author_id ? get_user_by('id', $author_id) : null;
            
            if ($user && $user->user_email) {
                $mapped_ids = [];
                foreach ($items as $item) {
                    if (!is_array($item) || self::is_note_only_row($item)) {
                        continue;
                    }
                    $pid = absint($item['mapped_id'] ?? 0);
                    if ($pid > 0) {
                        $mapped_ids[] = $pid;
                    }
                }

                // Emails always link to the account page, never straight to add-to-cart.
                // add-to-cart is a state-mutating GET: email security scanners and link
                // prefetchers fire it automatically before the customer ever opens the
                // message (see the AWS scanner hits in php-app.access.log). The account
                // page is idempotent and re-clickable, which suits a fire-once record.
                $link = wc_get_account_endpoint_url('musesys-requests');

                $body = self::render_email([
                    'title' => sprintf('Product Request Update: %s (%s)', self::get_display_id($post_id), ucfirst($new_status)),
                    'intro' => 'Your product request has been updated.',
                    'display_id' => self::get_display_id($post_id),
                    'lines' => [
                        'Request: <strong>' . self::get_display_id($post_id) . '</strong>',
                        'Status: <strong>' . esc_html(ucfirst($new_status)) . '</strong>',
                    ],
                    'button_text' => count($mapped_ids) > 0 ? 'View Request & Add to Cart' : 'View Account',
                    'button_url' => $link,
                ]);

                // Trigger Email Hook
                do_action('muse_send_pr_update', $post_id, [
                    'email' => $user->user_email,
                    'status' => $new_status,
                    'link' => $link
                ]);
            }
        }

        return true;
    }

    /**
     * Add meta box for request workflow.
     */
    public static function add_meta_box(): void {
        add_meta_box(
            'musesys_pr_meta',
            'Request Workflow',
            [__CLASS__, 'render_meta_box'],
            'musesys_pr',
            'normal', // Changed to normal for wider view
            'high'
        );
    }

    /**
     * Render meta box.
     * 
     * @param WP_Post $post
     */
    public static function render_meta_box(WP_Post $post): void {
        wp_nonce_field('musesys_pr_meta_save', 'musesys_pr_meta_nonce');
        $status = get_post_meta($post->ID, '_muse_pr_status', true) ?: 'pending';
        $type = get_post_meta($post->ID, '_muse_pr_type', true) ?: 'request';
        $items = get_post_meta($post->ID, '_musesys_product_items', true) ?: [];

        // Fallback for legacy items
        if (empty($items)) {
            $l_pid = get_post_meta($post->ID, '_musesys_pr_product_id', true);
            $l_part = get_post_meta($post->ID, 'model', true) . ' ' . get_post_meta($post->ID, 'part', true);
            if ($l_pid) {
                $items[] = ['part_num' => $l_part, 'qty' => 1, 'mapped_id' => $l_pid];
            }
        }

        $tracking_disp = self::get_tracking_display($post->ID);
        echo "<div style='background:#e5f5fa; padding:10px; margin-bottom:15px; border:1px solid #bde;'><strong>Request Type:</strong> " . strtoupper($type) . " &nbsp;|&nbsp; <strong>Tracking:</strong> " . esc_html($tracking_disp) . "</div>";
        ?>
        <p>
            <label><strong>Status:</strong></label>
            <select name="musesys_pr_status">
                <?php
                foreach (self::get_allowed_statuses() as $k => $label) {
                    printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($status, $k, false), esc_html($label));
                }
                ?>
            </select>
        </p>

        <table class="widefat striped" id="musesys-pr-table">
            <thead><tr><th>Asset / Part Details</th><th>WO / Store</th><th>Qty</th><th>Fulfillment Actions</th></tr></thead>
            <tbody>
                <?php foreach ($items as $i => $item): 
                    $item = self::normalize_product_item(is_array($item) ? $item : [], $post->ID);
                    $mapped_id = $item['mapped_id'] ?? '';
                    $mapped_qty = $item['mapped_qty'] ?? ($item['qty'] ?? 1);
                    $mapped_link = $item['mapped_link'] ?? '';
                ?>
                <tr data-index="<?php echo $i; ?>">
                    <td>
                        <?php if (!empty($item['asset_tag'])): ?>
                            <div style="background:#eee; display:inline-block; padding:2px 5px; border-radius:3px; font-size:11px; margin-bottom:4px;">
                                Tag: <?php echo esc_html($item['asset_tag']); ?>
                            </div><br>
                        <?php endif; ?>
                        <strong><?php echo esc_html($item['part_num'] ?? 'N/A'); ?></strong><br>
                        <small>EAM: <?php echo esc_html($item['eam_num'] ?? '--'); ?></small>
                        <br><em><?php echo esc_html($item['desc'] ?? ''); ?></em>
                        
                        <?php if (!empty($item['images']) && is_array($item['images'])): ?>
                            <div style="margin-top:5px;">
                            <?php foreach ($item['images'] as $url): ?>
                                <a href="<?php echo esc_url($url); ?>" target="_blank" style="text-decoration:none;">??</a> 
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="margin-bottom:5px;">
                            <strong>WO:</strong> <?php echo esc_html($item['wo_number'] ?? '--'); ?>
                        </div>
                        <div>
                            <strong>Store:</strong> <?php echo esc_html($item['store_number'] ?? '--'); ?>
                        </div>
                    </td>
                    <td><?php echo esc_html((string)($item['qty'] ?? 1)); ?></td>
                    <td>
                        <div style="margin-bottom:5px;">
                            <label style="font-size:10px; display:block;">Product ID:</label>
                            <input type="number" name="muse_request_items[<?php echo $i; ?>][mapped_id]" value="<?php echo esc_attr((string)$mapped_id); ?>" style="width:100px;" placeholder="ID">
                        </div>
                        <div style="margin-bottom:5px;">
                            <label style="font-size:10px; display:block;">Qty:</label>
                            <input type="number" name="muse_request_items[<?php echo $i; ?>][mapped_qty]" value="<?php echo esc_attr((string)$mapped_qty); ?>" style="width:100px;" placeholder="Qty">
                        </div>
                        <div style="margin-bottom:5px;">
                            <label style="font-size:10px; display:block;">Direct Link:</label>
                            <input type="text" name="muse_request_items[<?php echo $i; ?>][mapped_link]" value="<?php echo esc_attr($mapped_link); ?>" style="width:100%;" placeholder="https://...">
                        </div>
                        
                        <button type="button" class="button muse-remove-row">Remove</button>

                        <input type="hidden" name="muse_request_items[<?php echo $i; ?>][asset_tag]" value="<?php echo esc_attr($item['asset_tag'] ?? ''); ?>">
                        <input type="hidden" name="muse_request_items[<?php echo $i; ?>][part_num]" value="<?php echo esc_attr($item['part_num'] ?? ''); ?>">
                        <input type="hidden" name="muse_request_items[<?php echo $i; ?>][wo_number]" value="<?php echo esc_attr($item['wo_number'] ?? ''); ?>">
                        <input type="hidden" name="muse_request_items[<?php echo $i; ?>][store_number]" value="<?php echo esc_attr($item['store_number'] ?? ''); ?>">
                        <input type="hidden" name="muse_request_items[<?php echo $i; ?>][eam_num]" value="<?php echo esc_attr($item['eam_num'] ?? ''); ?>">
                        <input type="hidden" name="muse_request_items[<?php echo $i; ?>][qty]" value="<?php echo esc_attr((string)($item['qty'] ?? 1)); ?>">
                        <input type="hidden" name="muse_request_items[<?php echo $i; ?>][desc]" value="<?php echo esc_attr($item['desc'] ?? ''); ?>">
                        <?php 
                        if (!empty($item['images']) && is_array($item['images'])) {
                            foreach ($item['images'] as $k => $url) {
                                echo "<input type='hidden' name='muse_request_items[$i][images][$k]' value='" . esc_attr($url) . "'>";
                            }
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <button type="button" class="button" id="musesys-add-fulfillment-row">+ Add Product</button>
        </p>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Remove Row
            document.querySelectorAll('.muse-remove-row').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (confirm('Are you sure?')) {
                        btn.closest('tr').remove();
                    }
                });
            });

            // Add Row
            document.getElementById('musesys-add-fulfillment-row').addEventListener('click', function() {
                var table = document.getElementById('musesys-pr-table').querySelector('tbody');
                // Calculate next index based on max existing index
                var rows = table.querySelectorAll('tr[data-index]');
                var maxIndex = -1;
                rows.forEach(function(r) {
                    var idx = parseInt(r.getAttribute('data-index'));
                    if (idx > maxIndex) maxIndex = idx;
                });
                var newIndex = maxIndex + 1;

                var html = `
                <tr data-index="${newIndex}">
                    <td><strong>(Admin Added)</strong></td>
                    <td></td>
                    <td></td>
                    <td>
                        <div style="margin-bottom:5px;">
                            <label style="font-size:10px; display:block;">Product ID:</label>
                            <input type="number" name="muse_request_items[${newIndex}][mapped_id]" value="" style="width:100px;" placeholder="ID">
                        </div>
                        <div style="margin-bottom:5px;">
                            <label style="font-size:10px; display:block;">Qty:</label>
                            <input type="number" name="muse_request_items[${newIndex}][mapped_qty]" value="1" style="width:100px;" placeholder="Qty">
                        </div>
                        <div style="margin-bottom:5px;">
                            <label style="font-size:10px; display:block;">Direct Link:</label>
                            <input type="text" name="muse_request_items[${newIndex}][mapped_link]" value="" style="width:100%;" placeholder="https://...">
                        </div>
                        <button type="button" class="button muse-remove-row-dynamic">Remove</button>
                    </td>
                </tr>
                `;
                table.insertAdjacentHTML('beforeend', html);
                
                // Bind new remove button
                 var newRow = table.lastElementChild;
                 newRow.querySelector('.muse-remove-row-dynamic').addEventListener('click', function() {
                     newRow.remove();
                 });
            });

            // The "+ Add Note" row feature was removed: notes were stored as rows in
            // _musesys_product_items, which re-indexed the array on save and broke the
            // positional item mapping in complete_request_api(). Additional request
            // context is handled through direct customer communication instead.
        });
        </script>

        <?php if ($type === 'direct_order'): ?>
        <div style="margin-top:15px; padding:10px; background:#fff8e5; border:1px solid #f0c33c;">
            <label>
                <input type="checkbox" name="trigger_confirm_email" value="yes" id="muse_trigger_confirm_email">
                <strong>Save & Send "Confirm Order" Email</strong>
            </label>
            <p class="description">Emails the user a link to place the order for the mapped IDs above.</p>

            <hr style="margin:10px 0; border-color:#f0c33c;">

            <label>
                <input type="checkbox" name="trigger_place_order" value="yes" id="muse_trigger_place_order">
                <strong>Save & Place Order Directly</strong>
            </label>
            <p class="description">Skips confirmation email — immediately creates the WooCommerce order on save.</p>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var confirmEmail = document.getElementById('muse_trigger_confirm_email');
                var placeOrder = document.getElementById('muse_trigger_place_order');

                if (confirmEmail && placeOrder) {
                    confirmEmail.addEventListener('change', function() {
                        if (this.checked) placeOrder.checked = false;
                    });
                    
                    placeOrder.addEventListener('change', function() {
                        if (this.checked) confirmEmail.checked = false;
                    });
                }
            });
        </script>
        <?php endif; ?>
        <?php

    }

    /**
     * Save meta box data.
     */
    public static function save_meta_box(int $post_id, WP_Post $post, bool $update): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!isset($_POST['musesys_pr_meta_nonce']) || !wp_verify_nonce($_POST['musesys_pr_meta_nonce'], 'musesys_pr_meta_save')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        self::maybe_sync_request_id_from_tracking($post_id);

        $new_status = isset($_POST['musesys_pr_status']) ? sanitize_text_field(wp_unslash($_POST['musesys_pr_status'])) : self::PR_STATUS_PENDING;
        
        // Validate Status
        if (!array_key_exists($new_status, self::get_allowed_statuses())) {
             $new_status = self::PR_STATUS_PENDING; // Fallback or could return error
        }
        
        $items = [];
        $diverted_notes = [];
        $posted_items = $_POST['muse_request_items'] ?? ($_POST['items'] ?? null);
        if (is_array($posted_items)) {
            foreach ($posted_items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                // The "+ Add Note" feature has been removed. Any note row still arriving
                // (e.g. from an admin page cached before the change) is captured as
                // request-level note text rather than persisted as a phantom line item.
                if (self::is_posted_note_row($item)) {
                    $note_text = trim(sanitize_textarea_field($item['desc'] ?? ''));
                    if ($note_text !== '') {
                        $diverted_notes[] = $note_text;
                    }
                    continue;
                }
                $items[] = self::normalize_product_item($item, $post_id);
            }
        }

        if (!empty($diverted_notes)) {
            $stored_notes = get_post_meta($post_id, '_muse_pr_admin_notes', true);
            $stored_notes = is_array($stored_notes) ? $stored_notes : [];
            update_post_meta($post_id, '_muse_pr_admin_notes', array_merge($stored_notes, $diverted_notes));
        }

        // Update status calls internally update items and sends email if changed
        self::update_status($post_id, $new_status, $items);

        // Handle Direct Order Confirmation Trigger
        if (isset($_POST['trigger_confirm_email']) && $_POST['trigger_confirm_email'] === 'yes') {
            self::send_order_confirmation($post_id);
        }

        if (isset($_POST['trigger_place_order']) && $_POST['trigger_place_order'] === 'yes') {
            self::place_order_from_request($post_id);
        }
    }

    /**
     * Direct Order: Send Confirmation Email.
     */
    private static function send_order_confirmation(int $post_id): void {
        $token = bin2hex(random_bytes(32));
        update_post_meta($post_id, '_order_token', $token);
        // Force status to awaiting_confirm
        update_post_meta($post_id, '_muse_pr_status', 'awaiting_confirm');

        $author_id = (int) get_post_field('post_author', $post_id);
        $user = get_user_by('id', $author_id);
        
        if ($user) {
            $link = add_query_arg(['muse_action' => 'confirm_order', 'id' => $post_id, 'token' => $token], site_url('/'));

            $body = self::render_email([
                'title' => "Please Confirm Order: " . self::get_display_id($post_id),
                'intro' => "Your Direct Order request has been reviewed with the attached items. Please click below to confirm and generate the order.",
                'display_id' => self::get_display_id($post_id),
                'button_text' => 'Confirm & Place Order',
                'button_url' => $link
            ]);

            do_action('muse_send_pr_order_link', $post_id, [
                'email' => $user->user_email,
                'link' => $link
            ]);
		}
    }
	
    public static function place_order_from_request(int $post_id): int {
        // Guard against duplicate order creation
        $existing_order_id = get_post_meta($post_id, 'fulfilled_order_id', true);
        if ($existing_order_id) {
            return (int) $existing_order_id;
        }

        $items     = get_post_meta($post_id, '_musesys_product_items', true);
        $author_id = (int) get_post_field('post_author', $post_id);
        $user      = $author_id > 0 ? get_user_by('id', $author_id) : null;

        $order = wc_create_order(['created_via' => 'direct', 'customer_id' => $author_id ?: 0]);
        if (is_wp_error($order)) return 0;

        // Write immediately to prevent double-fire
        update_post_meta($post_id, 'fulfilled_order_id', $order->get_id());

        if ($user) {
            $billing_email = strtolower(trim((string) $user->user_email));
            if ($billing_email !== '') {
                $order->set_billing_email($billing_email);
            }
            $first_name = trim((string) get_user_meta($author_id, 'first_name', true));
            $last_name  = trim((string) get_user_meta($author_id, 'last_name', true));
            if ($first_name !== '') $order->set_billing_first_name($first_name);
            if ($last_name !== '')  $order->set_billing_last_name($last_name);
            if ($first_name === '' && $last_name === '' && $user->display_name) {
                $parts = preg_split('/\s+/', trim((string) $user->display_name)) ?: [];
                if (!empty($parts)) {
                    $order->set_billing_first_name((string) array_shift($parts));
                    if (!empty($parts)) $order->set_billing_last_name(implode(' ', $parts));
                }
            }
        }

        // Auto-populate shipping address from _muse_ship_to
        $ship_to = get_post_meta($post_id, '_muse_ship_to', true);
        if ($ship_to) {
            $is_warehouse = str_starts_with($ship_to, 'warehouse:');
            $clean_id = preg_replace('/^(store:|warehouse:)/', '', $ship_to);
            $clean_id_padded = str_pad(preg_replace('/\D/', '', $clean_id), 3, '0', STR_PAD_LEFT);
            
            $matched = null;
            if ($is_warehouse) {
                $warehouses = function_exists('musesys_get_warehouse_addresses') ? musesys_get_warehouse_addresses() : [];
                foreach ($warehouses as $wh) {
                    if (trim($wh['id'] ?? '') === $clean_id) {
                        $matched = $wh;
                        $matched['is_warehouse'] = true;
                        break;
                    }
                }
            } else {
                $stores = get_option('musesys_addresses_stores', []);
                foreach ($stores as $store) {
                    $store_num = str_pad(preg_replace('/\D/', '', trim($store['first_name'] ?? '')), 3, '0', STR_PAD_LEFT);
                    if ($store_num === $clean_id_padded) {
                        $matched = $store;
                        $matched['is_warehouse'] = false;
                        break;
                    }
                }
            }

            if ($matched) {
                $num_raw = trim($matched['first_name'] ?? '');
                $name    = trim($matched['last_name'] ?? '');
                $order->set_shipping_first_name($name);
                $order->set_shipping_last_name(!empty($matched['is_warehouse']) ? 'Warehouse ' . $clean_id : 'Store #' . $num_raw);
                $order->set_shipping_address_1($matched['address_1'] ?? '');
                $order->set_shipping_address_2($matched['address_2'] ?? '');
                $order->set_shipping_city($matched['city'] ?? '');
                $order->set_shipping_state($matched['state'] ?? '');
                $order->set_shipping_postcode($matched['zip'] ?? '');
                $order->set_shipping_company($matched['company'] ?? '');
                
                // Keep legacy field for backwards compatibility
                if (empty($matched['is_warehouse'])) {
                    $order->update_meta_data('_muse_ship_store', $clean_id_padded);
                }
            }
        }

        if (method_exists($order, 'set_created_via')) {
            $order->set_created_via('direct');
        } else {
            $order->update_meta_data('_created_via', 'direct');
        }
        
        // Save PR cross-references
        $order->update_meta_data('_muse_source_pr_id', $post_id);
        
        $shipping_speed = get_post_meta($post_id, '_muse_shipping_speed', true);
        if ($shipping_speed) {
            $order->update_meta_data('_muse_shipping_speed', $shipping_speed);
        }

        $ship_to = get_post_meta($post_id, '_muse_ship_to', true);
        if ($ship_to) {
            $order->update_meta_data('_muse_ship_to', $ship_to);
        }
        
        // Grab first item's install store if available
        $first_item = !empty($items) && is_array($items) ? $items[0] : null;
        if ($first_item && !empty($first_item['store_number'])) {
            $order->update_meta_data('_muse_install_store', $first_item['store_number']);
        }

        foreach ((array) $items as $item) {
            // Note-only rows are residue of the removed "+ Add Note" feature: never products.
            if (is_array($item) && self::is_note_only_row($item)) {
                continue;
            }

            $pid = intval($item['mapped_id'] ?? 0);
            $qty = intval($item['mapped_qty'] ?? ($item['qty'] ?? 1));

            // Previously these two cases were skipped silently, so a request could place
            // an order that was short one or more lines with no trace anywhere.
            if (!$pid) {
                self::log_unresolved_product($post_id, $item['mapped_id'] ?? '', 'place_order_from_request() (no product ID)');
            } elseif (!wc_get_product($pid)) {
                self::log_unresolved_product($post_id, $pid, 'place_order_from_request() (product not found)');
            }

            if ($pid && ($product = wc_get_product($pid))) {
                $item_id   = $order->add_product($product, $qty);
                $line_item = $order->get_item($item_id);
                if ($line_item) {
                    if (!empty($item['wo_number'])) {
                        $line_item->add_meta_data('_muse_wo_line', $item['wo_number']);
                        $line_item->add_meta_data('WO Number', $item['wo_number']);
                    }
                    if (!empty($item['store_number'])) {
                        $line_item->add_meta_data('_muse_store_global', $item['store_number']);
                        $line_item->add_meta_data('Install Store #', $item['store_number']);
                    }
                    $line_item->save();
                }
            }
        }

        $order->calculate_totals();
        $order->update_status('processing', 'Generated from PR #' . $post_id . ' (direct place)');

        $trk_num  = get_post_meta($post_id, '_muse_scwo_number', true);
        $trk_type = get_post_meta($post_id, '_tracking_type', true);
        $trk_disp = get_post_meta($post_id, '_tracking_display', true);
        if ($trk_num) {
            $order->update_meta_data('_muse_scwo_number', $trk_num);
            $order->update_meta_data('_tracking_number', $trk_num);
            $order->update_meta_data('_tracking_type', $trk_type);
            $order->update_meta_data('_tracking_display', $trk_disp);
            
            if (function_exists('muse_build_work_orders_array')) {
                muse_build_work_orders_array($order->get_id());
            }
        }
        
        $ship_to = get_post_meta($post_id, '_muse_ship_to', true);
        if ($ship_to) {
            $order->update_meta_data('_muse_ship_to', $ship_to);
        }
        
        // Final save before status changes
        $order->save();

        update_post_meta($post_id, '_muse_pr_status', 'fulfilled');
        update_post_meta($post_id, 'fulfilled_order_id', $order->get_id());
        
        return $order->get_id();
    }

    /**
     * Handle Direct Order Confirmation Link.
     */
    public static function handle_order_confirmation(): void {
        if (!isset($_GET['muse_action']) || $_GET['muse_action'] !== 'confirm_order') {
            return;
        }
        
        $post_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $token = isset($_GET['token']) && is_string($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
        $valid = $post_id ? get_post_meta($post_id, '_order_token', true) : '';

        if (!is_string($valid) || $valid === '' || $token === '' || !hash_equals($valid, $token)) {
            wp_die('Invalid or expired confirmation link.');
        }

        $order_id = self::place_order_from_request($post_id);
        if (!$order_id) {
            wp_die('Error creating order.');
        }

        delete_post_meta($post_id, '_order_token');

        $order = wc_get_order($order_id);
        wp_redirect($order ? $order->get_checkout_order_received_url() : site_url());
        exit;
    }

    /**
     * Set custom columns for CPT list table.
     */
    public static function set_custom_columns(array $cols): array {
        $cols['musesys_id'] = 'Request ID';
        $cols['musesys_status'] = 'Status';
        $cols['musesys_type'] = 'Type';
        $cols['musesys_ship_to'] = 'Ship-To';
        $cols['musesys_items'] = 'Items';
        return $cols;
    }

    /**
     * Render custom columns.
     */
    public static function render_custom_column(string $col, int $post_id): void {
        switch ($col) {
            case 'musesys_id':
                echo '<strong>' . esc_html(self::get_display_id($post_id)) . '</strong>';
                break;
            case 'title': // Not custom, but if we hook default... wait. We are hooking manual columns.
                // We should add an ID column or reuse Title? 
                // Currently WP handles Title.
                break;
            case 'musesys_status':
                $status = get_post_meta($post_id, '_muse_pr_status', true) ?: 'pending';
                echo esc_html(ucfirst($status));
                break;
            case 'musesys_type':
                $type = get_post_meta($post_id, '_muse_pr_type', true) ?: 'request';
                echo esc_html(strtoupper($type));
                break;
            case 'musesys_items':
                $items = get_post_meta($post_id, '_musesys_product_items', true);
                if (!empty($items) && is_array($items)) {
                    printf('%d items', count($items));
                } else {
                    echo '—';
                }
                break;
            case 'musesys_ship_to':
                $ship_to = get_post_meta($post_id, '_muse_ship_to', true);
                if ($ship_to) {
                    echo esc_html(self::get_store_or_warehouse_name($ship_to));
                } else {
                    echo '—';
                }
                break;
        }
    }

    /**
     * Add bulk actions.
     */
    public static function add_bulk_actions(array $actions): array {
        $actions['musesys_mark_processing'] = 'Mark as Processing';
        $actions['musesys_mark_completed'] = 'Mark as Completed';
        return $actions;
    }

    /**
     * Handle bulk actions.
     */
    public static function handle_bulk_actions(string $redirect, string $doaction, array $post_ids): string {
        if (!in_array($doaction, ['musesys_mark_processing', 'musesys_mark_completed'], true)) {
            return $redirect;
        }
        $new_status = $doaction === 'musesys_mark_processing' ? 'processing' : 'completed';
        $changed = 0;
        foreach ($post_ids as $pid) {
            self::update_status($pid, $new_status);
            $changed++;
        }
        return add_query_arg(['musesys_bulk_changed' => $changed], $redirect);
    }

    /**
     * Render admin notices.
     */
    public static function render_admin_notices(): void {
        if (isset($_GET['musesys_bulk_changed'])) {
            $c = intval($_GET['musesys_bulk_changed']);
            echo '<div class="notice notice-success is-dismissible"><p>Updated ' . $c . ' request(s).</p></div>';
        }
    }

    /**
     * Register WooCommerce endpoint.
     */
    public static function register_endpoints(): void {
        add_rewrite_endpoint('musesys-requests', EP_ROOT | EP_PAGES);
        add_filter('query_vars', function (array $vars): array {
            $vars[] = 'musesys-requests';
            return $vars;
        });
    }

    /**
     * Add WooCommerce account menu item.
     */
    public static function add_account_menu_item(array $items): array {
        $items['musesys-requests'] = 'Product Requests';
        return $items;
    }

    /**
     * Add every mapped item from a completed product request to the cart.
     *
     * Replaces the previous `?add-to-cart=1,2,3` link. WooCommerce core cannot parse a
     * comma-separated list: WC_Form_Handler::add_to_cart_action() returns early unless
     * the value passes is_numeric(), so any multi-item link silently produced an empty
     * cart with no error. Single-item links happened to work, which is why this looked
     * intermittent rather than broken.
     */
    public static function handle_add_request_items_to_cart(): void {
        if (empty($_GET['muse_add_request'])) {
            return;
        }

        $post_id = absint($_GET['muse_add_request']);
        if (!$post_id || !function_exists('WC') || !WC()->cart) {
            return;
        }

        $account_url = wc_get_account_endpoint_url('musesys-requests');

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'muse_add_request_' . $post_id)) {
            wc_add_notice('That link has expired. Please use the button on this page again.', 'error');
            wp_safe_redirect($account_url);
            exit;
        }

        // Only the requester (or someone who can edit the request) may load its items.
        $author_id = (int) get_post_field('post_author', $post_id);
        if (!is_user_logged_in() || (get_current_user_id() !== $author_id && !current_user_can('edit_post', $post_id))) {
            wc_add_notice('You do not have permission to add those items to a cart.', 'error');
            wp_safe_redirect($account_url);
            exit;
        }

        $items = get_post_meta($post_id, '_musesys_product_items', true);
        $items = is_array($items) ? $items : [];

        $added  = 0;
        $failed = 0;

        foreach ($items as $item) {
            if (!is_array($item) || self::is_note_only_row($item)) {
                continue;
            }

            $pid = absint($item['mapped_id'] ?? 0);
            if ($pid === 0) {
                $failed++;
                self::log_unresolved_product($post_id, $item['mapped_id'] ?? '', 'add-to-cart handler');
                continue;
            }

            $qty     = max(1, absint($item['mapped_qty'] ?? ($item['qty'] ?? 1)));
            $product = wc_get_product($pid);

            // Deliberately not calling is_purchasable() here — this catalogue hides
            // prices for some roles, and that check would reject legitimate items.
            if (!$product || $product->get_status() === 'trash') {
                $failed++;
                self::log_unresolved_product($post_id, $pid, 'add-to-cart handler (no such product)');
                continue;
            }

            try {
                if (WC()->cart->add_to_cart($pid, $qty)) {
                    $added++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
                error_log(sprintf(
                    '[muse-pr] PR #%d: add_to_cart(%d) threw: %s',
                    $post_id,
                    $pid,
                    $e->getMessage()
                ));
            }
        }

        if ($added > 0) {
            wc_add_notice(sprintf(
                '%d %s added to your cart.',
                $added,
                $added === 1 ? 'item' : 'items'
            ), 'success');
        }

        if ($failed > 0) {
            wc_add_notice(sprintf(
                '%d %s from %s could not be added and may no longer be available. Please contact us so we can correct the request.',
                $failed,
                $failed === 1 ? 'item' : 'items',
                self::get_display_id($post_id)
            ), 'error');
        }

        if ($added === 0) {
            if ($failed === 0) {
                wc_add_notice('There are no items on that request that can be added to the cart.', 'notice');
            }
            wp_safe_redirect($account_url);
            exit;
        }

        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    /**
     * Render WooCommerce account endpoint.
     */
    public static function render_account_endpoint(): void {
        $user_id = get_current_user_id();
        $filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $args = [
            'post_type'                => 'musesys_pr',
            'post_status'              => ['publish', 'pending', 'private', 'draft'],
            'author'                   => $user_id,
            'posts_per_page'           => 50,
            'orderby'                  => 'date',
            'order'                    => 'DESC',
            'no_found_rows'            => true,      // ← skip COUNT(*) scan
            'update_post_meta_cache'   => true,      // ← prime ALL meta in one query
            'update_post_term_cache'   => false,     // ← skip term cache, not needed here
        ];

        if ($filter && in_array($filter, ['pending', 'processing', 'completed'])) {
            $args['meta_query'] = [
                ['key' => '_muse_pr_status', 'value' => $filter, 'compare' => '=']
            ];
        }

        $requests = get_posts($args);
        ?>
        <h3>Product Requests</h3>
        <p>Filter by Status:
            <?php
            foreach (['' => 'All', 'pending' => 'Pending', 'processing' => 'Processing', 'completed' => 'Completed'] as $slug => $label) {
                $url = esc_url(add_query_arg('status', $slug));
                $current = ($filter === $slug) ? 'style="font-weight:bold"' : '';
                echo "<a href='{$url}' {$current}>{$label}</a> &nbsp; ";
            }
            ?>
        </p>
        <?php if (!$requests): ?>
            <p>No product requests found.</p>
        <?php else: ?>
            <table class="woocommerce-orders-table woocommerce-table--orders shop_table shop_table_responsive">
                <thead><tr><th>Request #</th><th>Brand</th><th>Type</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                    <?php
                    foreach ($requests as $req) {
                        $brand = esc_html(get_post_meta($req->ID, 'brand', true));
                        $type = esc_html(strtoupper(get_post_meta($req->ID, '_muse_pr_type', true) ?: 'REQUEST'));
                        $status = ucfirst(esc_html(get_post_meta($req->ID, '_muse_pr_status', true) ?: 'pending'));
                        $date = esc_html(get_the_date('', $req->ID));
                        
                        // Action Logic
                        $items = get_post_meta($req->ID, '_musesys_product_items', true);
                        $mapped_ids = [];
                        if (!empty($items) && is_array($items)) {
                            foreach ($items as $item) {
                                if (!is_array($item) || self::is_note_only_row($item)) {
                                    continue;
                                }
                                $pid = absint($item['mapped_id'] ?? 0);
                                if ($pid > 0) {
                                    $mapped_ids[] = $pid;
                                }
                            }
                        }
                        
                        $action = '';
                        if (strtolower($status) === 'completed' && !empty($mapped_ids)) {
                            // Points at our own handler, which can add several items.
                            // A comma-separated `add-to-cart` value is ignored by Woo core.
                            $add_url = wp_nonce_url(
                                add_query_arg(['muse_add_request' => $req->ID], wc_get_cart_url()),
                                'muse_add_request_' . $req->ID
                            );
                            $count = count($mapped_ids);
                            $label = sprintf('Add %d %s to Cart', $count, $count === 1 ? 'Item' : 'Items');
                            $action = '<a class="button" href="' . esc_url($add_url) . '">' . esc_html($label) . '</a>';
                        }
                        
                        echo "<tr><td><strong>" . esc_html(self::get_display_id($req->ID)) . "</strong></td><td>{$brand}</td><td>{$type}</td><td>{$status}</td><td>{$date}</td><td>{$action}</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Register REST API routes.
     */
    public static function register_rest_routes(): void {
        register_rest_route('musesys/v1', '/product-requests', [
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'get_requests_api'],
                'permission_callback' => [__CLASS__, 'rest_can_use_requests'],
            ],
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'create_request_api'],
                'permission_callback' => [__CLASS__, 'rest_can_use_requests'],
            ]
        ]);

        register_rest_route('musesys/v1', '/product-requests/(?P<id>\d+)', [
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'update_request_api'],
                'permission_callback' => [__CLASS__, 'rest_can_edit_request'],
            ]
        ]);

        // Completing can place an order, so it is staff only.
        register_rest_route('musesys/v1', '/product-requests/(?P<id>\d+)/complete', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'complete_request_api'],
            'permission_callback' => [__CLASS__, 'rest_is_staff'],
        ]);
    }

    /**
     * REST permission: staff (manage_woocommerce).
     */
    public static function rest_is_staff(): bool|WP_Error {
        return current_user_can('manage_woocommerce') ? true : self::rest_forbidden();
    }

    /**
     * REST permission: any logged-in user. Non-staff only ever see or create their own requests.
     */
    public static function rest_can_use_requests(): bool|WP_Error {
        return is_user_logged_in() ? true : self::rest_forbidden();
    }

    /**
     * REST permission: staff, or the customer who owns the request. Others get 403 whether or not
     * the request exists, so IDs cannot be probed.
     */
    public static function rest_can_edit_request(WP_REST_Request $request): bool|WP_Error {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        $user_id = get_current_user_id();
        $post = get_post((int) $request['id']);
        if ($user_id && $post && $post->post_type === 'musesys_pr' && (int) $post->post_author === $user_id) {
            return true;
        }
        return self::rest_forbidden();
    }

    private static function rest_forbidden(): WP_Error {
        // 401 when logged out, 403 when logged in.
        return new WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => rest_authorization_required_code()]);
    }

    /**
     * Helper: Sanitize Product Items
     */
    private static function sanitize_product_items($items): array {
        if (!is_array($items)) {
            return [];
        }

        $clean_items = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $item_id = isset($item['item_id']) ? sanitize_text_field($item['item_id']) : '';
            $link = isset($item['link']) ? esc_url_raw($item['link']) : '';
            $qty = isset($item['qty']) ? intval($item['qty']) : 1;

            if (empty($item_id) && empty($link)) continue;

            $clean_items[] = self::normalize_product_item([
                'part_num' => $item_id, // Map item_id to part_num for UI compatibility
                'mapped_link' => $link, // Store link
                'qty' => $qty,
                // Preserve other fields if passed, sanitized
                'desc' => isset($item['desc']) ? sanitize_textarea_field($item['desc']) : '',
                'images' => [], // API created items likely don't have images yet
            ]);
        }
        return $clean_items;
    }

    /**
     * API Callback: Create Request
     */
    public static function create_request_api($request): WP_REST_Response {
        $params = $request->get_json_params();
        $items_raw = $params['items'] ?? [];
        
        $clean_items = self::sanitize_product_items($items_raw);

        if (empty($clean_items)) {
            return new WP_REST_Response(['error' => 'No valid items provided'], 400);
        }

        $request_number = self::get_next_request_number();
        $pr_code = date('mdy') . "-$request_number";
        $title = "Product Request {$pr_code} (API)";

        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_status' => 'publish',
            'post_type' => 'musesys_pr',
            'post_author' => get_current_user_id() ?: 0, // 0 if unauthenticated
        ]);

        if (is_wp_error($post_id)) {
            return new WP_REST_Response(['error' => 'Failed to create request'], 500);
        }

        update_post_meta($post_id, '_muse_pr_status', self::PR_STATUS_PENDING);
        update_post_meta($post_id, '_muse_pr_type', 'request');
        update_post_meta($post_id, '_musesys_product_items', $clean_items);
        self::maybe_sync_request_id_from_tracking($post_id, $pr_code);

        return new WP_REST_Response(['id' => $post_id, 'message' => 'Request created'], 201);
    }

    /**
     * API Callback: Update Request
     */
    public static function update_request_api($request): WP_REST_Response {
        $post_id = (int) $request['id'];
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'musesys_pr') {
             return new WP_REST_Response(['error' => 'Request not found'], 404);
        }

        $params = $request->get_json_params();
        $items_raw = $params['items'] ?? null;

        if ($items_raw !== null) {
            $clean_items = self::sanitize_product_items($items_raw);
            update_post_meta($post_id, '_musesys_product_items', $clean_items);
        }

        // Allow status update here too if needed?
        // User spec only mentioned items, but good to be flexible.
        // Keeping straightforward to spec.

        return new WP_REST_Response(['id' => $post_id, 'message' => 'Request updated'], 200);
    }

    /**
     * API Callback: Get Requests
     */
    public static function get_requests_api($request): WP_REST_Response {
        // Bounded: at most 200 per page. X-WP-Total / X-WP-TotalPages show when there is more.
        $per_page = isset($request['per_page']) ? min(max(absint($request['per_page']), 1), 200) : 200;
        $page = isset($request['page']) ? max(absint($request['page']), 1) : 1;

        $args = [
            'post_type' => 'musesys_pr',
            'post_status' => ['publish', 'pending'],
            'posts_per_page' => $per_page,
            'paged' => $page,
            'suppress_filters' => true, // as get_posts() did
            'meta_query' => []
        ];
        if (!current_user_can('manage_woocommerce')) {
            $args['author'] = get_current_user_id();
        }

        // Handle Status Filter
        $status_param = isset($request['status']) ? sanitize_text_field($request['status']) : '';
        if ($status_param && array_key_exists($status_param, self::get_allowed_statuses())) {
             $args['meta_query'][] = ['key' => '_muse_pr_status', 'value' => $status_param, 'compare' => '='];
        } else {
             // Default behavior if no specific status requested (or invalid): Show pending for consistency with previous logic? 
             // Original logic was always pending key check: [['key' => '_muse_pr_status', 'value' => 'pending', 'compare' => '=']]
             // User requested "Modify the GET endpoint... to accept a ?status= parameter."
             // If parameter is MISSING, do we default to 'pending' as before? Or ALL? 
             // User said: "It should allow filtering by these specific keys."
             // Assuming default to 'pending' if not specified to maintain backward compatibility with any potential internal calls, 
             // BUT legacy API explicitly hardcoded pending. let's respect the param if present.
             if (empty($status_param)) {
                  // user fix: include both PENDING and PROCESSING
                  $args['meta_query'][] = [
                      'key'     => '_muse_pr_status', 
                      'value'   => ['pending', 'processing'], 
                      'compare' => 'IN'
                  ];
             }
        }

        $query = new WP_Query($args);
        $posts = $query->posts;
        $data = [];

        foreach ($posts as $post) {
            // 1. Top Level Data
            $brand = get_post_meta($post->ID, 'brand', true);
            $model = get_post_meta($post->ID, 'model', true); // Ensure this is mapped!

            // 2. Unpack the Suitcase
            $items = get_post_meta($post->ID, '_musesys_product_items', true);

            // Defaults
            $description = '';
            $wo_number = '';
            $store_number = '';
            $asset_tag = '';
            $images = [];

            // 3. Extract from First Item (Primary logic for App Table)
            if (!empty($items) && is_array($items)) {
                $first_item = $items[0]; // Get the first row
                $description = $first_item['desc'] ?? '';
                $wo_number = $first_item['wo_number'] ?? '';
                $store_number = $first_item['store_number'] ?? '';
                $asset_tag = $first_item['asset_tag'] ?? '';
                $images = $first_item['images'] ?? [];
            }
            
            $req_type = strtoupper(get_post_meta($post->ID, '_muse_pr_type', true) ?: 'REQUEST');

            // 4. Map to Response
            $data[] = [
                'id' => $post->ID,
                'brand' => $brand,
                'model' => $model, // Make sure key is 'model' to match App
                'wo_number' => $wo_number,
                'store_number' => $store_number,
                'asset_tag' => $asset_tag,
                'description' => $description,
                'images' => $images, // Array of URLs
                'items' => is_array($items) ? $items : [], // Full items array for detail pane mapping
                'status' => get_post_meta($post->ID, '_muse_pr_status', true),
                'type'   => $req_type,   // Source of Truth
                'request_type' => $req_type, // Legacy / Backward Compat
                'fulfilled_order_id' => get_post_meta($post->ID, 'fulfilled_order_id', true),
                'user' => get_the_author_meta('display_name', $post->post_author),
                'admin_edit_link' => admin_url('post.php?post=' . $post->ID . '&action=edit'),
                'ship_to' => get_post_meta($post->ID, '_muse_ship_to', true)
            ];
        }

        $response = new WP_REST_Response($data, 200);
        $response->header('X-WP-Total', (string) (int) $query->found_posts);
        $response->header('X-WP-TotalPages', (string) (int) $query->max_num_pages);
        return $response;
    }

    /**
     * API Callback: Complete Request
     *
     * Accepts { items: [{ item_id, link }], status } from UACP.
     * Merges incoming mapped_id/mapped_link into existing product items,
     * then calls update_status() to trigger lifecycle + email.
     */
    public static function complete_request_api($request): WP_REST_Response|WP_Error {
        $id = (int) $request['id'];
        $pr = get_post($id);
        if (!$pr || $pr->post_type !== 'musesys_pr') {
            return new WP_Error('not_found', 'Request not found', ['status' => 404]);
        }
        $params = $request->get_json_params();

        $status = isset($params['status']) ? sanitize_text_field($params['status']) : 'completed';
        $action = isset($params['action']) ? sanitize_text_field($params['action']) : 'send_confirm';

        // Validate status
        if (!array_key_exists($status, self::get_allowed_statuses())) {
            $status = self::PR_STATUS_COMPLETED;
        }

        // Merge incoming mapped product data into existing items.
        //
        // Matching is keyed on the WP post ID first, then an explicitly supplied index,
        // then the next still-unmapped row. It is deliberately NOT purely positional:
        // save_meta_box() re-indexes the stored array on every admin save, so a stale
        // position used to push items down the append path, where the post ID was
        // discarded and mapped_id silently became 0.
        $items_raw = $params['items'] ?? null;
        if (!empty($items_raw) && is_array($items_raw)) {
            $existing = get_post_meta($id, '_musesys_product_items', true);
            $existing = is_array($existing) ? array_values($existing) : [];

            $claimed = [];

            foreach ($items_raw as $i => $new_item) {
                if (!is_array($new_item)) continue;

                // Sanitize and normalize the incoming item. normalize_product_item() now
                // honours item_id as the post ID, so $clean['mapped_id'] is authoritative.
                $clean = self::normalize_product_item([
                    'item_id'     => $new_item['item_id'] ?? '',
                    'mapped_link' => $new_item['link'] ?? '',
                    'link'        => $new_item['link'] ?? '',
                    'desc'        => $new_item['desc'] ?? ($new_item['note'] ?? ''),
                ], $id);

                $incoming_id = absint($new_item['item_id'] ?? 0) ?: absint($clean['mapped_id']);
                $target_idx  = null;

                // 1) Already mapped to this exact product — update in place (idempotent
                //    re-completion, and immune to any re-indexing that has happened).
                if ($incoming_id > 0) {
                    foreach ($existing as $idx => $row) {
                        if (is_array($row) && absint($row['mapped_id'] ?? 0) === $incoming_id) {
                            $target_idx = $idx;
                            break;
                        }
                    }
                }

                // 2) Explicit index from the payload, but only if it still points at a
                //    usable, unclaimed row.
                if ($target_idx === null && isset($new_item['index'])) {
                    $idx = (int) $new_item['index'];
                    if (isset($existing[$idx]) && !isset($claimed[$idx]) && !self::is_note_only_row($existing[$idx])) {
                        $target_idx = $idx;
                    }
                }

                // 3) Next row still awaiting a product ID. Survives re-indexing, which a
                //    stale positional index does not.
                if ($target_idx === null) {
                    foreach ($existing as $idx => $row) {
                        if (isset($claimed[$idx]) || !is_array($row)) continue;
                        if (absint($row['mapped_id'] ?? 0) > 0) continue;
                        if (self::is_note_only_row($row)) continue;
                        $target_idx = $idx;
                        break;
                    }
                }

                // 4) Genuinely new row added by UACP — append it.
                if ($target_idx === null) {
                    $existing[] = $clean;
                    $claimed[array_key_last($existing)] = true;
                    if (absint($clean['mapped_id']) === 0) {
                        self::log_unresolved_product($id, $new_item['item_id'] ?? '', 'complete_request_api() append');
                    }
                    continue;
                }

                $claimed[$target_idx] = true;

                // Overlay mapped fields onto the original request row.
                if ($incoming_id > 0) {
                    $existing[$target_idx]['mapped_id']  = $incoming_id;
                    $existing[$target_idx]['product_id'] = $incoming_id;
                } else {
                    self::log_unresolved_product($id, $new_item['item_id'] ?? '', 'complete_request_api() overlay');
                }

                if (!empty($clean['mapped_link'])) {
                    $existing[$target_idx]['mapped_link'] = $clean['mapped_link'];
                    $existing[$target_idx]['direct_link'] = $clean['mapped_link'];
                }

                // Use the parsed part number if the original row is blank
                if (empty($existing[$target_idx]['part_num']) && !empty($clean['part_num'])) {
                    $existing[$target_idx]['part_num'] = $clean['part_num'];
                }
            }

            update_post_meta($id, '_musesys_product_items', $existing);
        }

        $type = get_post_meta($id, '_muse_pr_type', true);
        
        // Execute order placement BEFORE marking PR as completed
        if ($type === 'direct_order') {
            if ($action === 'send' || $action === 'send_confirm') {
                self::place_order_from_request($id);
                if ($action === 'send_confirm') {
                    self::send_order_confirmation($id);
                }
            }
        } else {
            // For standard REQUEST types
            if ($action === 'place_direct') {
                self::place_order_from_request($id);
            }
        }

        $result = self::update_status($id, $status);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'success'    => true,
            'request_id' => $id,
            'new_status' => $status,
        ], 200);
    }

    /**
     * Generate Status Report Email.
     */
    public static function generate_status_email(): void {
        /*
        $trx_key = '_trx_number'; 
        $tracking_key = '_wc_shipment_tracking_items'; 
        
        $processing_orders = wc_get_orders(['status' => 'processing', 'limit' => -1]);
        
        $untouched = []; 
        $no_tracking = []; 
        $stalled = [];

        foreach ($processing_orders as $order) {
            $trx = $order->get_meta($trx_key);
            $track = $order->get_meta($tracking_key);
            $date = $order->get_date_created();
            
            if (empty($trx) && $trx !== '0') { 
                $untouched[] = $order; 
                continue; 
            }
            if (empty($track)) {
                $no_tracking[] = $order;
                if ($date->diff(new DateTime())->days > 10) {
                    $stalled[] = $order;
                }
            }
        }

        // Pending Product Requests
        $open_prs = get_posts(['post_type' => 'musesys_pr', 'posts_per_page' => -1, 'post_status' => ['pending', 'draft', 'publish']]);
        $final_prs = [];
        foreach ($open_prs as $pr) {
            $status = get_post_meta($pr->ID, '_muse_pr_status', true);
            if (in_array($status, ['pending', 'processing']) || $pr->post_status === 'pending') {
                $final_prs[] = $pr;
            }
        }

        if (empty($untouched) && empty($no_tracking) && empty($stalled) && empty($final_prs)) {
            return;
        }

        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; padding: 20px; border: 1px solid #e5e5e5; background: #f9f9f9;">
            <h2 style="color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 10px;">?? MuseSys Status Report</h2>
            <p><strong>Time:</strong> <?php echo date('F j, Y g:i a'); ?></p>

            <?php if (!empty($untouched)) : ?>
            <div style="background: #fff; padding: 15px; border-left: 5px solid #0073aa; margin-bottom: 20px;">
                <h3 style="color: #0073aa; margin-top: 0;">?? Untouched Orders (No TRX)</h3>
                <ul><?php foreach ($untouched as $o) echo "<li><a href='" . $o->get_edit_order_url() . "'>Order #" . $o->get_id() . "</a> - " . $o->get_date_created()->date('m/d') . "</li>"; ?></ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($no_tracking)) : ?>
            <div style="background: #fff; padding: 15px; border-left: 5px solid #46b450; margin-bottom: 20px;">
                <h3 style="color: #46b450; margin-top: 0;">?? Needs Tracking (Has TRX)</h3>
                <ul><?php foreach ($no_tracking as $o) echo "<li><a href='" . $o->get_edit_order_url() . "'>Order #" . $o->get_id() . "</a> (TRX: " . $o->get_meta($trx_key) . ")</li>"; ?></ul>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($stalled)) : ?>
            <div style="background: #fff; padding: 15px; border-left: 5px solid #dba617; margin-bottom: 20px;">
                <h3 style="color: #dba617; margin-top: 0;">?? STALLED (>10 Days)</h3>
                <ul><?php foreach ($stalled as $o) echo "<li><a href='" . $o->get_edit_order_url() . "'>Order #" . $o->get_id() . "</a></li>"; ?></ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($final_prs)) : ?>
            <div style="background: #fff; padding: 15px; border-left: 5px solid #d63638; margin-bottom: 20px;">
                <h3 style="color: #d63638; margin-top: 0;">?? Open Product Requests</h3>
                <ul><?php foreach ($final_prs as $p) {
                    $s = get_post_meta($p->ID, '_muse_pr_status', true) ?: 'pending';
                    echo "<li><a href='" . get_edit_post_link($p->ID) . "'>Request #" . $p->ID . "</a> (" . ucfirst($s) . ")</li>";
                } ?></ul>
            </div>
            <?php endif; ?>
        </div>
        <?php
        $msg = ob_get_clean();
        $to = ['cole.h@gerharzequipment.com', 'kevin.L@gerharzequipment.com', 'Chris.MC@gerharzequipment.com', 'James.K@gerharzequipment.com', 'Janet.L@gerharzequipment.com'];
        wp_mail($to, '[MuseSys] ?? Status Report', $msg, ['Content-Type: text/html; charset=UTF-8']);
        */
    }

    public static function add_pr_link_to_order_details( $order ) {
        if ( ! SHOW_PR_ORDER_LINK ) return;
        
        $pr_id = $order->get_meta('_muse_source_pr_id');
        if ( ! $pr_id ) return;
        
        $wo = $order->get_meta('_muse_wo_global');
        $label = $wo ? 'PR-' . $pr_id . ' / WO-' . $wo : 'PR-' . $pr_id;
        
        echo '<p class="muse-source-pr"><strong>Originated From:</strong> ' 
            . esc_html($label) . '</p>';
    }

    /**
     * Add Ship-To Location to WC Emails
     */
    public static function add_ship_to_to_order_email($order, $sent_to_admin, $plain_text, $email) {
        if (!$order) return;
        
        $ship_to = $order->get_meta('_muse_ship_to');
        if (!$ship_to) return;

        $label = self::get_store_or_warehouse_name($ship_to);
        
        if ($plain_text) {
            echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
            echo "Ship-To Location: " . $label . "\n";
            echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
        } else {
            echo '<h2>Ship-To Location</h2>';
            echo '<p><strong>Destination:</strong> ' . esc_html($label) . '</p>';
        }
    }
}

// Programmatic Hook for Direct Order Placement
add_action('muse_process_direct_order', ['MuseProductRequest', 'place_order_from_request']);

// Initialize plugin
MuseProductRequest::init();
