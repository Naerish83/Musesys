<?php
/**
 * Module Name: MuseSys API
 * Description: REST API endpoints for the Unified Admin Control Panel (UACP).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MuseSys_API {

    const NAMESPACE = 'musesys/api/v1';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        // Field Inventory
        register_rest_route( self::NAMESPACE, '/fields', [
            'methods'  => 'GET',
            'callback' => [ __CLASS__, 'get_fields' ],
            'permission_callback' => [ __CLASS__, 'check_permission' ],
        ] );

        // Generic Meta (User)
        register_rest_route( self::NAMESPACE, '/meta/user/(?P<id>\d+)', [
            'methods'  => ['GET', 'POST'],
            'callback' => [ __CLASS__, 'handle_user_meta' ],
            'permission_callback' => [ __CLASS__, 'check_permission' ],
        ] );

        // Generic Meta (Post/Order/Product)
        register_rest_route( self::NAMESPACE, '/meta/post/(?P<id>\d+)', [
            'methods'  => ['GET', 'POST'],
            'callback' => [ __CLASS__, 'handle_post_meta' ],
            'permission_callback' => [ __CLASS__, 'check_permission' ],
        ] );
    }

    public static function check_permission() {
        return current_user_can( 'manage_woocommerce' );
    }

    public static function get_fields() {
        // Return the field inventory (hardcoded for now based on scan)
        $fields = [
            'user_meta' => [
                'musesys_favorites', 'muse_favorite_parts', 'musesys_favorite_products', 'contact_phone'
            ],
            'order_meta' => [
                '_trx_order_number', '_is_drop_ship', '_purchase_order_number', '_musesys_guide_id', '_musesys_location_id', '_musesys_location_code', '_musesys_location_group',
                'additional_install_store_number', '_musesys_technician_phone',
                '_muse_split_from_order_id', '_muse_split_to_order_id'
            ],
            'product_meta' => [
                'muse_part_number', '_muse_manual_file', '_muse_manual_url', '_muse_manual_model_raw',
                '_muse_prop65_enabled', '_muse_prop65_chems', '_muse_prop65_message'
            ],
            'request_meta' => [
                'brand', 'model', 'eam', '_muse_pr_status', '_musesys_customer_user_id', '_musesys_customer_email'
            ]
        ];
        return apply_filters( 'musesys_api_fields', $fields );
    }

    public static function handle_user_meta( $request ) {
        $user_id = $request['id'];
        $method  = $request->get_method();
        $params  = $request->get_json_params();

        if ( $method === 'GET' ) {
            $meta = [];
            $keys = self::get_fields()['user_meta'];
            foreach ( $keys as $key ) {
                $meta[$key] = get_user_meta( $user_id, $key, true );
            }
            return rest_ensure_response( $meta );
        }

        if ( $method === 'POST' ) {
            foreach ( $params as $key => $value ) {
                // Basic sanitization, should be improved based on type
                if ( is_array( $value ) ) {
                    $value = array_map( 'sanitize_text_field', $value );
                } else {
                    $value = sanitize_text_field( $value );
                }
                update_user_meta( $user_id, $key, $value );
            }
            return rest_ensure_response( ['success' => true] );
        }
    }

    public static function handle_post_meta( $request ) {
        $post_id = $request['id'];
        $method  = $request->get_method();
        $params  = $request->get_json_params();

        if ( $method === 'GET' ) {
            $meta = [];
            // Determine if it's an order or product to filter keys (optional, but cleaner)
            // For now, just check all known post meta keys
            $order_keys   = self::get_fields()['order_meta'];
            $product_keys = self::get_fields()['product_meta'];
            $request_keys = self::get_fields()['request_meta'];
            $all_keys     = array_merge( $order_keys, $product_keys, $request_keys );

            foreach ( $all_keys as $key ) {
                $val = get_post_meta( $post_id, $key, true );
                if ( $val !== '' ) {
                    $meta[$key] = $val;
                }
            }
            return rest_ensure_response( $meta );
        }

        if ( $method === 'POST' ) {
            foreach ( $params as $key => $value ) {
                if ( is_array( $value ) ) {
                    $value = array_map( 'sanitize_text_field', $value );
                } else {
                    $value = sanitize_text_field( $value );
                }
                update_post_meta( $post_id, $key, $value );
            }
            return rest_ensure_response( ['success' => true] );
        }
    }
}

MuseSys_API::init();
