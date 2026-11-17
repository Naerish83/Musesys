<?php
/**
 * Muse Hide Prices Module
 * Removes prices for non-logged-in users
 * Fixed: Parameter signatures, email logic, and hook priorities
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MuseHidePrices {

    /** @var MuseHidePrices */
    private static $instance = null;

    /**
     * Get instance (singleton)
     */
    public static function init() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor — registers module hooks
     */
    private function __construct() {

        // Hide prices on product catalog & product pages
        add_filter( 'woocommerce_get_price_html', [ $this, 'hide_price_html' ], 9999, 2 );
        
        // Hide prices in cart (correct parameter counts)
        add_filter( 'woocommerce_cart_item_price', [ $this, 'hide_cart_price' ], 9999, 3 );
        add_filter( 'woocommerce_cart_item_subtotal', [ $this, 'hide_cart_subtotal' ], 9999, 3 );

        // Hide "Add to Cart" button for non-logged-in users
        add_filter( 'woocommerce_is_purchasable', [ $this, 'hide_purchasability' ], 10, 2 );
    }

    /**
     * Hide price HTML on storefront
     */
    public function hide_price_html( $price, $product ) {

        // Show prices for logged-in users
        if ( is_user_logged_in() ) {
            return $price;
        }

        return '<span class="muse-price-hidden">Login to view price</span>';
    }

    /**
     * Hide cart item price (accepts 3 params per WC spec)
     */
    public function hide_cart_price( $price, $cart_item, $cart_item_key ) {

        if ( is_user_logged_in() ) {
            return $price;
        }

        return '<span class="muse-price-hidden">—</span>';
    }

    /**
     * Hide cart item subtotal (accepts 3 params per WC spec)
     */
    public function hide_cart_subtotal( $subtotal, $cart_item, $cart_item_key ) {

        if ( is_user_logged_in() ) {
            return $subtotal;
        }

        return '<span class="muse-price-hidden">—</span>';
    }

    /**
     * Prevent non-logged-in users from purchasing
     */
    public function hide_purchasability( $is_purchasable, $product ) {

        if ( ! is_user_logged_in() ) {
            return false;
        }

        return $is_purchasable;
    }
}

// Boot module
MuseHidePrices::init();