<?php
/**
 * Plugin Name: Muse Systems - Return Request Form
 * Plugin URI:  https://muse.systems/
 * Description: AJAX-powered return request flow that links immutable WooCommerce orders to MuseSys return records.
 * Version:     2.0.0
 * Author:      Muse Systems
 * Author URI:  https://muse.systems/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Muse_Return_Request_Form {
    const CPT = 'muse_return';
    const NONCE = 'muse_return_ajax';
    const SEARCH_MIN_LENGTH = 5;
    const SEARCH_INDEX_LIMIT = 250;
    const SEARCH_CACHE_TTL = 300;
    const MAX_IMAGE_FILES = 10;
    const MAX_IMAGE_BYTES = 10485760; // 10 MB per image.
    const MAX_TOTAL_IMAGE_BYTES = 20971520; // 20 MB across all images.

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_cpt' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );

        add_shortcode( 'muse_return_request_form', array( __CLASS__, 'render_shortcode' ) );

        add_action( 'wp_ajax_muse_return_search_orders', array( __CLASS__, 'ajax_search_orders' ) );
        add_action( 'wp_ajax_muse_return_get_order', array( __CLASS__, 'ajax_get_order_details' ) );
        add_action( 'wp_ajax_muse_return_submit', array( __CLASS__, 'ajax_submit_return' ) );

        add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'set_columns' ) );
        add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
    }

    public static function register_cpt() {
        register_post_type(
            self::CPT,
            array(
                'labels' => array(
                    'name' => 'Returns',
                    'singular_name' => 'Return Request',
                    'menu_name' => 'Returns',
                    'add_new_item' => 'Add Return Request',
                    'edit_item' => 'Edit Return Request',
                ),
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => false,
                'supports' => array( 'title', 'author' ),
                'capability_type' => 'post',
                'has_archive' => false,
                'rewrite' => false,
            )
        );
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'musesys',
            'Return Authorizations',
            'Returns',
            'manage_woocommerce',
            'edit.php?post_type=' . self::CPT
        );
    }

    private static function get_ajax_nonce() {
        return wp_create_nonce( self::NONCE );
    }

    private static function verify_ajax_request() {
        check_ajax_referer( self::NONCE, 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in.' ), 403 );
        }
    }

    private static function get_user_orders_for_search( $user_id ) {
        $cache_key = 'muse_return_search_index_' . (int) $user_id;
        $cached = get_transient( $cache_key );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $orders = array();
        foreach ( self::get_user_order_ids_for_search( $user_id ) as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $work_orders = self::get_order_work_orders( $order );
            if ( empty( $work_orders ) ) {
                continue;
            }

            $numbers = array();
            foreach ( $work_orders as $wo ) {
                $number = preg_replace( '/\D/', '', (string) ( $wo['number'] ?? '' ) );
                if ( $number !== '' ) {
                    $numbers[] = $number;
                }
            }

            if ( empty( $numbers ) ) {
                continue;
            }

            $orders[] = array(
                'id'           => $order->get_id(),
                'display'      => self::get_order_display( $order ),
                'date'         => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d' ) : '',
                'work_orders'  => array_values( array_unique( $numbers ) ),
            );
        }

        set_transient( $cache_key, $orders, self::SEARCH_CACHE_TTL );

        return $orders;
    }

    private static function get_user_order_ids_for_search( $user_id ) {
        global $wpdb;

        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return array();
        }

        $limit = (int) self::SEARCH_INDEX_LIMIT;
        $ids = array();

        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            $orders_table = $wpdb->prefix . 'wc_orders';
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id
                    FROM {$orders_table}
                    WHERE customer_id = %d
                      AND type = 'shop_order'
                    ORDER BY date_created_gmt DESC
                    LIMIT %d",
                    $user_id,
                    $limit
                )
            );
        } else {
            $posts_table = $wpdb->posts;
            $postmeta_table = $wpdb->postmeta;
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT posts.ID
                    FROM {$posts_table} AS posts
                    INNER JOIN {$postmeta_table} AS customer_meta
                        ON customer_meta.post_id = posts.ID
                       AND customer_meta.meta_key = '_customer_user'
                    WHERE posts.post_type = 'shop_order'
                      AND posts.post_status <> 'trash'
                      AND customer_meta.meta_value = %d
                    ORDER BY posts.post_date DESC
                    LIMIT %d",
                    $user_id,
                    $limit
                )
            );
        }

        return array_map( 'absint', array_filter( (array) $ids ) );
    }

    private static function get_item_eam_number( $item ) {
        $product = $item->get_product();

        if ( $product ) {
            $product_eam = get_post_meta( $product->get_id(), 'Muse_eam', true );
            if ( ! empty( $product_eam ) ) {
                return preg_replace( '/\D/', '', (string) $product_eam );
            }

            $sku = $product->get_sku();
            if ( ! empty( $sku ) ) {
                return (string) $sku;
            }
        }

        $item_eam = $item->get_meta( 'EAM #' );
        if ( empty( $item_eam ) ) {
            $item_eam = $item->get_meta( 'eam_num' );
        }

        return ! empty( $item_eam ) ? preg_replace( '/\D/', '', (string) $item_eam ) : '';
    }

    private static function build_item_payload( $item_id, $item, $return_qty = 0 ) {
        $product = $item->get_product();

        return array(
            'item_id'     => (int) $item_id,
            'product_id'  => $product ? $product->get_id() : 0,
            'name'        => $item->get_name(),
            'sku'         => $product ? $product->get_sku() : '',
            'eam_number'  => self::get_item_eam_number( $item ),
            'ordered_qty' => (int) $item->get_quantity(),
            'return_qty'  => (int) $return_qty,
        );
    }

    private static function get_order_work_orders( $order ) {
        if ( function_exists( 'muse_get_all_work_orders' ) ) {
            $wos = muse_get_all_work_orders( $order->get_id() );
            if ( ! empty( $wos ) ) {
                return $wos;
            }
        }

        $fallback = array();
        $global = $order->get_meta( '_muse_wo_global', true );
        if ( ! empty( $global ) ) {
            $clean = preg_replace( '/\D/', '', (string) $global );
            $fallback[] = array(
                'number' => $clean,
                'type'   => strlen( $clean ) === 9 ? 'sc' : 'eam',
            );
        }

        return $fallback;
    }

    private static function get_order_display( $order ) {
        $display = get_post_meta( $order->get_id(), '_primary_work_order', true );
        if ( ! empty( $display ) ) {
            return $display;
        }

        $wos = self::get_order_work_orders( $order );
        if ( ! empty( $wos[0]['number'] ) ) {
            $prefix = $wos[0]['type'] === 'sc' ? 'SC-' : 'EAM-';
            return $prefix . $wos[0]['number'];
        }

        return '#' . $order->get_order_number();
    }

    public static function ajax_search_orders() {
        self::verify_ajax_request();

        $user_id = get_current_user_id();
        $term = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) ) );

        if ( strlen( $term ) < self::SEARCH_MIN_LENGTH ) {
            wp_send_json_success( array( 'orders' => array() ) );
        }

        $matches = array();
        foreach ( self::get_user_orders_for_search( $user_id ) as $order ) {
            foreach ( $order['work_orders'] as $number ) {
                if ( strpos( $number, $term ) === 0 || $number === $term ) {
                    $matches[] = array(
                        'id' => $order['id'],
                        'label' => $order['display'] . ' / Order #' . $order['id'],
                        'date' => $order['date'],
                    );
                    break;
                }
            }

            if ( count( $matches ) >= 10 ) {
                break;
            }
        }

        wp_send_json_success( array( 'orders' => $matches ) );
    }

    public static function ajax_get_order_details() {
        self::verify_ajax_request();

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order = wc_get_order( $order_id );

        if ( ! $order || (int) $order->get_user_id() !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'Order not found.' ), 404 );
        }

        $items = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            $items[] = self::build_item_payload( $item_id, $item );
        }

        wp_send_json_success(
            array(
                'order' => array(
                    'id' => $order->get_id(),
                    'display' => self::get_order_display( $order ),
                ),
                'items' => $items,
            )
        );
    }

    /**
     * Normalize the muse_return_images field into single-file arrays and enforce the
     * count / per-file / total limits before anything is written to disk.
     *
     * @return array|WP_Error
     */
    private static function get_image_files() {
        $field = $_FILES['muse_return_images'] ?? null;
        if ( ! is_array( $field ) || ! isset( $field['name'] ) || ! is_array( $field['name'] ) ) {
            return array();
        }

        $files = array();
        $total_bytes = 0;
        foreach ( $field['name'] as $i => $name ) {
            if ( ! is_string( $name ) || $name === '' ) {
                continue;
            }
            $error = (int) ( $field['error'][ $i ] ?? UPLOAD_ERR_NO_FILE );
            if ( $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE ) {
                return new WP_Error( 'muse_return_upload', 'Each image must be 10 MB or smaller.' );
            }
            if ( $error !== UPLOAD_ERR_OK || ! is_string( $field['tmp_name'][ $i ] ?? null ) ) {
                continue;
            }

            $size = (int) ( $field['size'][ $i ] ?? 0 );
            if ( $size > self::MAX_IMAGE_BYTES ) {
                return new WP_Error( 'muse_return_upload', 'Each image must be 10 MB or smaller.' );
            }
            $total_bytes += $size;

            $files[] = array(
                'name'     => $name,
                'type'     => (string) ( $field['type'][ $i ] ?? '' ),
                'tmp_name' => $field['tmp_name'][ $i ],
                'error'    => $error,
                'size'     => $size,
            );
        }

        if ( count( $files ) > self::MAX_IMAGE_FILES ) {
            return new WP_Error( 'muse_return_upload', 'You can attach at most ' . self::MAX_IMAGE_FILES . ' images.' );
        }
        if ( $total_bytes > self::MAX_TOTAL_IMAGE_BYTES ) {
            return new WP_Error( 'muse_return_upload', 'Images cannot exceed 20 MB in total.' );
        }

        return $files;
    }

    private static function handle_image_uploads( array $files ) {
        $image_urls = array();

        if ( empty( $files ) ) {
            return $image_urls;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $allowed_extensions = array( 'jpg', 'jpeg', 'png', 'webp', 'gif' );

        foreach ( $files as $file ) {
            $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
            $ext = strtolower( (string) ( $check['ext'] ?? '' ) );

            if ( ! in_array( $ext, $allowed_extensions, true ) ) {
                continue;
            }

            $upload = wp_handle_upload( $file, array( 'test_form' => false ) );
            if ( empty( $upload['error'] ) && ! empty( $upload['url'] ) ) {
                $image_urls[] = $upload['url'];
            }
        }

        return $image_urls;
    }

    public static function ajax_submit_return() {
        self::verify_ajax_request();

        $user_id = get_current_user_id();
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );
        $selected_raw = $_POST['selected_items'] ?? '[]';
        $selected_items = is_string( $selected_raw ) ? json_decode( wp_unslash( $selected_raw ), true ) : null;
        $order = wc_get_order( $order_id );

        if ( ! $order || (int) $order->get_user_id() !== $user_id ) {
            wp_send_json_error( array( 'message' => 'Original order not found.' ), 404 );
        }

        if ( empty( $reason ) ) {
            wp_send_json_error( array( 'message' => 'Reason for return is required.' ), 400 );
        }

        if ( ! is_array( $selected_items ) || empty( $selected_items ) ) {
            wp_send_json_error( array( 'message' => 'Please select at least one item to return.' ), 400 );
        }

        $validated_items = array();
        foreach ( $selected_items as $selection ) {
            if ( ! is_array( $selection ) ) {
                continue;
            }
            $item_id = absint( $selection['item_id'] ?? 0 );
            $return_qty = absint( $selection['return_qty'] ?? 0 );
            $item = $order->get_item( $item_id );

            if ( ! $item ) {
                continue;
            }

            $ordered_qty = (int) $item->get_quantity();
            if ( $return_qty < 1 || $return_qty > $ordered_qty ) {
                continue;
            }

            $validated_items[] = self::build_item_payload( $item_id, $item, $return_qty );
        }

        if ( empty( $validated_items ) ) {
            wp_send_json_error( array( 'message' => 'No valid return items were submitted.' ), 400 );
        }

        $image_files = self::get_image_files();
        if ( is_wp_error( $image_files ) ) {
            wp_send_json_error( array( 'message' => $image_files->get_error_message() ), 400 );
        }

        $image_urls = self::handle_image_uploads( $image_files );
        $timestamp = current_time( 'timestamp' );
        $work_order_display = self::get_order_display( $order );
        $title = sprintf( 'Return Request %s %s', $work_order_display, date_i18n( 'Ymd-His', $timestamp ) );

        $post_id = wp_insert_post(
            array(
                'post_title'  => $title,
                'post_type'   => self::CPT,
                'post_status' => 'publish',
                'post_author' => $user_id,
            ),
            true
        );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array( 'message' => 'Unable to create the return request.' ), 500 );
        }

        update_post_meta( $post_id, '_muse_return_order_id', $order_id );
        update_post_meta( $post_id, '_muse_return_order_number', $order->get_order_number() );
        update_post_meta( $post_id, '_muse_return_user_id', $user_id );
        update_post_meta( $post_id, '_muse_return_primary_work_order', $work_order_display );
        update_post_meta( $post_id, '_muse_return_work_orders', self::get_order_work_orders( $order ) );
        update_post_meta( $post_id, '_muse_return_reason', $reason );
        update_post_meta( $post_id, '_muse_return_items', $validated_items );
        update_post_meta( $post_id, '_muse_return_images', $image_urls );
        update_post_meta( $post_id, '_muse_return_submitted_at', current_time( 'mysql' ) );

        self::send_return_email( $post_id, $order, $validated_items, $reason, $image_urls );

        wp_send_json_success( array( 'message' => 'Return request submitted successfully.' ) );
    }

    private static function send_return_email( $return_id, $order, $items, $reason, $image_urls ) {
        $user = wp_get_current_user();
        $order_link = get_edit_post_link( $order->get_id() );
        $returns_link = admin_url( 'edit.php?post_type=' . self::CPT );
        $to = array( get_option( 'admin_email' ), 'partsmaint@gerharzequipment.com' );
        $subject = 'Return Request Authorized - Order #' . $order->get_id();

        $rows = '';
        foreach ( $items as $item ) {
            $item_detail = $item['eam_number'] ?: ( $item['sku'] ?: 'N/A' );
            $rows .= '<tr>';
            $rows .= '<td style="padding:10px;border-bottom:1px solid #e6e6e6;">' . esc_html( $item['name'] ) . '<br><small>' . esc_html( $item_detail ) . '</small></td>';
            $rows .= '<td style="padding:10px;border-bottom:1px solid #e6e6e6;text-align:center;">' . esc_html( (string) $item['return_qty'] ) . '</td>';
            $rows .= '<td style="padding:10px;border-bottom:1px solid #e6e6e6;text-align:center;">' . esc_html( (string) $item['ordered_qty'] ) . '</td>';
            $rows .= '</tr>';
        }

        $evidence = '';
        if ( ! empty( $image_urls ) ) {
            $evidence .= '<p style="margin:0 0 8px;font-weight:600;">Images</p>';
            foreach ( $image_urls as $url ) {
                $evidence .= '<a href="' . esc_url( $url ) . '" target="_blank" style="display:inline-block;margin-right:8px;margin-bottom:8px;">';
                $evidence .= '<img src="' . esc_url( $url ) . '" alt="Return image" style="width:72px;height:72px;object-fit:cover;border:1px solid #ddd;border-radius:6px;">';
                $evidence .= '</a>';
            }
        }

        $message = '
        <div style="font-family:Arial,sans-serif;background:#f5f7fb;padding:24px;">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:760px;margin:0 auto;background:#fff;border:1px solid #dfe3eb;border-radius:10px;overflow:hidden;">
                <tr>
                    <td style="background:#182433;color:#fff;padding:20px 24px;">
                        <h2 style="margin:0;font-size:22px;">Return Request Authorized</h2>
                        <p style="margin:6px 0 0;font-size:13px;opacity:0.85;">MuseSys Returns Workflow</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:20px;">
                            <tr>
                                <td style="padding:6px 0;"><strong>Tech Name:</strong> ' . esc_html( $user->display_name ) . '</td>
                                <td style="padding:6px 0;"><strong>User ID:</strong> ' . esc_html( (string) $user->ID ) . '</td>
                            </tr>
                            <tr>
                                <td style="padding:6px 0;"><strong>Date:</strong> ' . esc_html( current_time( 'Y-m-d H:i:s' ) ) . '</td>
                                <td style="padding:6px 0;"><strong>Original Order:</strong> <a href="' . esc_url( $order_link ) . '">#' . esc_html( (string) $order->get_id() ) . '</a></td>
                            </tr>
                        </table>

                        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin-bottom:20px;">
                            <thead>
                                <tr style="background:#f1f4f8;">
                                    <th align="left" style="padding:10px;border-bottom:1px solid #dfe3eb;">Part Name / EAM #</th>
                                    <th align="center" style="padding:10px;border-bottom:1px solid #dfe3eb;">Qty Returning</th>
                                    <th align="center" style="padding:10px;border-bottom:1px solid #dfe3eb;">Original Qty</th>
                                </tr>
                            </thead>
                            <tbody>' . $rows . '</tbody>
                        </table>

                        <div style="background:#f8fafc;border-left:4px solid #1f4b7a;padding:14px 16px;margin-bottom:20px;">
                            <div style="font-size:12px;font-weight:700;color:#4a5568;text-transform:uppercase;margin-bottom:6px;">Reason for Return</div>
                            <blockquote style="margin:0;color:#253040;">' . nl2br( esc_html( $reason ) ) . '</blockquote>
                        </div>

                        ' . $evidence . '

                        <p style="margin-top:24px;">
                            <a href="' . esc_url( $returns_link ) . '" style="display:inline-block;padding:12px 18px;background:#0073aa;color:#fff;text-decoration:none;border-radius:6px;font-weight:700;">Open Returns Dashboard</a>
                        </p>
                    </td>
                </tr>
            </table>
        </div>';

        wp_mail( $to, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    public static function render_shortcode() {
        if ( ! is_user_logged_in() ) {
            return '<p>Please log in to submit a return request.</p>';
        }

        ob_start();
        ?>
        <div class="muse-return-app">
            <div class="muse-return-card">
                <div class="muse-form-field">
                    <label for="muse-return-search">Work Order Search</label>
                    <input type="text" id="muse-return-search" placeholder="Type SC or EAM work order #">
                    <div id="muse-return-search-results" class="muse-search-results"></div>
                </div>

                <div id="muse-return-order-summary" class="muse-order-summary" style="display:none;"></div>

                <div id="muse-return-items-wrapper" style="display:none;">
                    <table class="muse-return-table">
                        <thead>
                            <tr>
                                <th>Select</th>
                                <th>Part Name / EAM #</th>
                                <th>Original Qty</th>
                                <th>Qty Returning</th>
                            </tr>
                        </thead>
                        <tbody id="muse-return-items"></tbody>
                    </table>
                </div>

                <div class="muse-form-field">
                    <label for="muse-return-reason">Reason for Return</label>
                    <textarea id="muse-return-reason" rows="5" placeholder="Explain the reason for the return..."></textarea>
                </div>

                <div class="muse-form-field">
                    <label for="muse-return-images">Upload Images (optional)</label>
                    <input type="file" id="muse-return-images" name="muse_return_images[]" accept="image/*" multiple>
                </div>

                <button type="button" id="muse-return-submit">Submit Return Request</button>
                <div id="muse-return-message" class="muse-return-message" style="display:none;"></div>
            </div>
        </div>

        <style>
            .muse-return-app { max-width: 900px; margin: 24px 0; }
            .muse-return-card { background:#f8fafc; border:1px solid #d9e2ec; border-radius:10px; padding:24px; }
            .muse-form-field { margin-bottom:18px; display:flex; flex-direction:column; gap:6px; }
            .muse-form-field label { font-weight:700; color:#243b53; }
            .muse-form-field input[type="text"], .muse-form-field textarea, .muse-form-field input[type="file"] {
                width:100%; padding:10px 12px; border:1px solid #bcccdc; border-radius:6px; box-sizing:border-box;
            }
            .muse-search-results { margin-top:8px; background:#fff; border:1px solid #d9e2ec; border-radius:6px; display:none; }
            .muse-search-results button { display:block; width:100%; text-align:left; padding:10px 12px; border:0; background:#fff; cursor:pointer; }
            .muse-search-results button:hover { background:#f0f4f8; }
            .muse-order-summary { margin-bottom:16px; padding:12px 14px; background:#fff; border:1px solid #d9e2ec; border-radius:6px; font-weight:600; }
            .muse-return-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #d9e2ec; margin-bottom:18px; }
            .muse-return-table th, .muse-return-table td { padding:10px; border-bottom:1px solid #e6edf3; }
            .muse-return-table th { background:#f0f4f8; text-align:left; }
            .muse-return-table input[type="number"] { width:90px; padding:6px 8px; }
            #muse-return-submit { padding:12px 18px; background:#0073aa; color:#fff; border:0; border-radius:6px; cursor:pointer; font-weight:700; }
            #muse-return-submit:hover { background:#005f8d; }
            .muse-return-message { margin-top:16px; padding:12px 14px; border-radius:6px; }
            .muse-return-message.success { background:#e3f9e5; border:1px solid #9fd6a4; color:#1f5130; }
            .muse-return-message.error { background:#fde8e8; border:1px solid #e7a7a7; color:#7a1f1f; }
        </style>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            const nonce = '<?php echo esc_js( self::get_ajax_nonce() ); ?>';
            const searchInput = document.getElementById('muse-return-search');
            const searchResults = document.getElementById('muse-return-search-results');
            const orderSummary = document.getElementById('muse-return-order-summary');
            const itemsWrapper = document.getElementById('muse-return-items-wrapper');
            const itemsBody = document.getElementById('muse-return-items');
            const reasonField = document.getElementById('muse-return-reason');
            const imageField = document.getElementById('muse-return-images');
            const submitBtn = document.getElementById('muse-return-submit');
            const messageBox = document.getElementById('muse-return-message');
            let selectedOrderId = 0;
            let debounceTimer = null;
            let activeSearchController = null;
            let lastSearchTerm = '';

            function showMessage(text, type) {
                messageBox.textContent = text;
                messageBox.className = 'muse-return-message ' + type;
                messageBox.style.display = 'block';
            }

            function clearResults() {
                searchResults.innerHTML = '';
                searchResults.style.display = 'none';
            }

            function renderOrderItems(order, items) {
                selectedOrderId = order.id;
                orderSummary.textContent = 'Selected Order: ' + order.display + ' / Order #' + order.id;
                orderSummary.style.display = 'block';
                itemsBody.innerHTML = '';
                items.forEach(item => {
                    const row = document.createElement('tr');
                    const itemDetail = item.eam_number || item.sku || 'N/A';
                    row.innerHTML = `
                        <td><input type="checkbox" class="muse-return-check" data-item-id="${item.item_id}"></td>
                        <td>${item.name}<br><small>${itemDetail}</small></td>
                        <td>${item.ordered_qty}</td>
                        <td><input type="number" class="muse-return-qty" data-item-id="${item.item_id}" min="1" max="${item.ordered_qty}" value="1" disabled></td>
                    `;
                    itemsBody.appendChild(row);
                });

                itemsWrapper.style.display = 'block';
                itemsBody.querySelectorAll('.muse-return-check').forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        const qtyInput = itemsBody.querySelector('.muse-return-qty[data-item-id="' + this.dataset.itemId + '"]');
                        qtyInput.disabled = !this.checked;
                    });
                });
            }

            searchInput.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                const term = this.value.trim();
                if (term.length < <?php echo (int) self::SEARCH_MIN_LENGTH; ?>) {
                    lastSearchTerm = '';
                    if (activeSearchController) {
                        activeSearchController.abort();
                        activeSearchController = null;
                    }
                    clearResults();
                    return;
                }

                debounceTimer = setTimeout(() => {
                    if (term === lastSearchTerm) {
                        return;
                    }

                    lastSearchTerm = term;

                    if (activeSearchController) {
                        activeSearchController.abort();
                    }

                    activeSearchController = new AbortController();
                    const body = new URLSearchParams({ action: 'muse_return_search_orders', nonce, term });
                    fetch(ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body,
                        signal: activeSearchController.signal
                    })
                        .then(r => r.json())
                        .then(data => {
                            activeSearchController = null;
                            clearResults();
                            if (!data.success || !data.data.orders.length) {
                                return;
                            }

                            data.data.orders.forEach(order => {
                                const btn = document.createElement('button');
                                btn.type = 'button';
                                btn.textContent = order.label + (order.date ? ' / ' + order.date : '');
                                btn.addEventListener('click', function() {
                                    clearResults();
                                    searchInput.value = order.label;
                                    const detailBody = new URLSearchParams({ action: 'muse_return_get_order', nonce, order_id: order.id });
                                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: detailBody })
                                        .then(r => r.json())
                                        .then(detail => {
                                            if (!detail.success) {
                                                showMessage(detail.data.message || 'Unable to load order details.', 'error');
                                                return;
                                            }
                                            renderOrderItems(detail.data.order, detail.data.items);
                                        });
                                });
                                searchResults.appendChild(btn);
                            });

                            searchResults.style.display = 'block';
                        })
                        .catch(error => {
                            activeSearchController = null;
                            if (error.name !== 'AbortError') {
                                clearResults();
                            }
                        });
                }, 500);
            });

            submitBtn.addEventListener('click', function() {
                if (!selectedOrderId) {
                    showMessage('Please select an order first.', 'error');
                    return;
                }

                const selectedItems = [];
                itemsBody.querySelectorAll('.muse-return-check:checked').forEach(checkbox => {
                    const qtyInput = itemsBody.querySelector('.muse-return-qty[data-item-id="' + checkbox.dataset.itemId + '"]');
                    selectedItems.push({
                        item_id: checkbox.dataset.itemId,
                        return_qty: qtyInput.value
                    });
                });

                if (!selectedItems.length) {
                    showMessage('Select at least one line item for return.', 'error');
                    return;
                }

                if (!reasonField.value.trim()) {
                    showMessage('Reason for return is required.', 'error');
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'muse_return_submit');
                formData.append('nonce', nonce);
                formData.append('order_id', selectedOrderId);
                formData.append('reason', reasonField.value.trim());
                formData.append('selected_items', JSON.stringify(selectedItems));

                Array.from(imageField.files).forEach(file => {
                    formData.append('muse_return_images[]', file, file.name);
                });

                submitBtn.disabled = true;
                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        submitBtn.disabled = false;
                        if (!data.success) {
                            showMessage(data.data.message || 'Unable to submit return request.', 'error');
                            return;
                        }

                        showMessage(data.data.message, 'success');
                        reasonField.value = '';
                        imageField.value = '';
                        itemsBody.innerHTML = '';
                        itemsWrapper.style.display = 'none';
                        orderSummary.style.display = 'none';
                        selectedOrderId = 0;
                        searchInput.value = '';
                    })
                    .catch(() => {
                        submitBtn.disabled = false;
                        showMessage('A network error occurred while submitting the return request.', 'error');
                    });
            });
        });
        </script>
        <?php

        return ob_get_clean();
    }

    public static function set_columns( $columns ) {
        return array(
            'cb' => $columns['cb'],
            'title' => 'Return Request',
            'muse_return_work_order' => 'Work Order',
            'muse_return_order' => 'Original Order',
            'author' => 'Tech',
            'muse_return_items' => 'Items',
            'date' => 'Submitted',
        );
    }

    public static function render_column( $column, $post_id ) {
        if ( $column === 'muse_return_work_order' ) {
            $display = get_post_meta( $post_id, '_muse_return_primary_work_order', true );
            echo $display ? esc_html( $display ) : '&mdash;';
        }

        if ( $column === 'muse_return_order' ) {
            $order_id = (int) get_post_meta( $post_id, '_muse_return_order_id', true );
            if ( $order_id ) {
                echo '<a href="' . esc_url( get_edit_post_link( $order_id ) ) . '">#' . esc_html( (string) $order_id ) . '</a>';
            } else {
                echo '—';
            }
        }

        if ( $column === 'muse_return_items' ) {
            $items = get_post_meta( $post_id, '_muse_return_items', true );
            echo is_array( $items ) ? esc_html( (string) count( $items ) ) : '0';
        }
    }

    public static function register_meta_boxes() {
        add_meta_box(
            'muse-return-details',
            'Return Request Details',
            array( __CLASS__, 'render_meta_box' ),
            self::CPT,
            'normal',
            'high'
        );
    }

    public static function render_meta_box( $post ) {
        $order_id = (int) get_post_meta( $post->ID, '_muse_return_order_id', true );
        $user_id = (int) get_post_meta( $post->ID, '_muse_return_user_id', true );
        $reason = (string) get_post_meta( $post->ID, '_muse_return_reason', true );
        $items = get_post_meta( $post->ID, '_muse_return_items', true );
        $images = get_post_meta( $post->ID, '_muse_return_images', true );
        $submitted_at = (string) get_post_meta( $post->ID, '_muse_return_submitted_at', true );
        $work_order = (string) get_post_meta( $post->ID, '_muse_return_primary_work_order', true );
        $user = $user_id ? get_user_by( 'id', $user_id ) : false;

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 18px;margin-bottom:18px;">';
        echo '<div><strong>Work Order:</strong><br>' . esc_html( $work_order ?: 'N/A' ) . '</div>';
        echo '<div><strong>Original Order:</strong><br>';
        if ( $order_id ) {
            echo '<a href="' . esc_url( get_edit_post_link( $order_id ) ) . '">#' . esc_html( (string) $order_id ) . '</a>';
        } else {
            echo 'N/A';
        }
        echo '</div>';
        echo '<div><strong>Tech:</strong><br>' . esc_html( $user ? $user->display_name : 'Unknown' ) . '</div>';
        echo '<div><strong>User ID:</strong><br>' . esc_html( $user_id ? (string) $user_id : 'N/A' ) . '</div>';
        echo '<div><strong>Submitted:</strong><br>' . esc_html( $submitted_at ?: 'N/A' ) . '</div>';
        echo '</div>';

        echo '<h4 style="margin:0 0 8px;">Returned Items</h4>';
        echo '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">';
        echo '<thead><tr style="background:#f1f4f8;">';
        echo '<th style="text-align:left;padding:8px;border:1px solid #dfe3eb;">Part / EAM</th>';
        echo '<th style="text-align:center;padding:8px;border:1px solid #dfe3eb;">Qty Returning</th>';
        echo '<th style="text-align:center;padding:8px;border:1px solid #dfe3eb;">Original Qty</th>';
        echo '</tr></thead><tbody>';

        if ( is_array( $items ) && ! empty( $items ) ) {
            foreach ( $items as $item ) {
                $item_detail = $item['eam_number'] ?? '';
                if ( empty( $item_detail ) ) {
                    $item_detail = $item['sku'] ?? 'N/A';
                }

                echo '<tr>';
                echo '<td style="padding:8px;border:1px solid #dfe3eb;">' . esc_html( $item['name'] ?? 'Item' ) . '<br><small>' . esc_html( $item_detail ) . '</small></td>';
                echo '<td style="padding:8px;border:1px solid #dfe3eb;text-align:center;">' . esc_html( (string) ( $item['return_qty'] ?? 0 ) ) . '</td>';
                echo '<td style="padding:8px;border:1px solid #dfe3eb;text-align:center;">' . esc_html( (string) ( $item['ordered_qty'] ?? 0 ) ) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="3" style="padding:8px;border:1px solid #dfe3eb;">No item data saved.</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h4 style="margin:0 0 8px;">Reason for Return</h4>';
        echo '<blockquote style="margin:0 0 16px;padding:12px 14px;background:#f8fafc;border-left:4px solid #1f4b7a;">' . nl2br( esc_html( $reason ?: 'No reason provided.' ) ) . '</blockquote>';

        if ( is_array( $images ) && ! empty( $images ) ) {
            echo '<h4 style="margin:0 0 8px;">Attached Images</h4>';
            foreach ( $images as $image_url ) {
                echo '<a href="' . esc_url( $image_url ) . '" target="_blank" style="display:inline-block;margin-right:10px;margin-bottom:10px;">';
                echo '<img src="' . esc_url( $image_url ) . '" alt="Return image" style="width:80px;height:80px;object-fit:cover;border:1px solid #dfe3eb;border-radius:6px;">';
                echo '</a>';
            }
        }
    }
}

Muse_Return_Request_Form::init();
