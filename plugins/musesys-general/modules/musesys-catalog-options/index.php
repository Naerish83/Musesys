<?php
/**
 * MuseSys General — Catalog Options Module
 *
 * Per-guide catalog display options (e.g. hiding WooCommerce related products).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MuseSys_Catalog_Options' ) ) {
	final class MuseSys_Catalog_Options {

		/**
		 * Bootstrap hooks.
		 */
		public static function init() {
			add_action( 'wp', array( __CLASS__, 'apply_guide_catalog_options' ) );
		}

		/**
		 * Apply per-guide catalog toggles on frontend requests.
		 */
		public static function apply_guide_catalog_options() {
			if ( is_admin() ) {
				return;
			}
			$guide = MuseSys_Guides::get_user_guide();
			if ( ! $guide ) {
				return;
			}
			if ( (int) $guide->hide_related_products === 1 ) {
				remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
				add_filter( 'woocommerce_related_products', '__return_empty_array', 10 );
			}
		}
	}

	MuseSys_Catalog_Options::init();
}
