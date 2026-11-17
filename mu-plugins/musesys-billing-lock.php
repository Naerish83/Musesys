<?php
/**
 * Plugin Name: MuseSys – Per-Guide Billing Lock
 * Description: Locks corporate billing address from the active Order Guide profile and keeps only billing email/phone editable at checkout. MU-plugin for early load order.
 * Version: 1.1.0
 * Author: MuseSystems
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MuseSys_Billing_Lock {

	/**
	 * Register WooCommerce checkout hooks.
	 */
	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'inline_css' ) );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'lock_corp_billing' ), 999 );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'force_billing_defaults' ), 25 );
	}

	/**
	 * Resolve the billing profile for the current user's active guide.
	 *
	 * @return array<string,string>
	 */
	public static function get_active_billing_profile(): array {
		$profile = array();

		if ( class_exists( 'MuseSys_Guides' ) ) {
			$guide = MuseSys_Guides::get_user_guide();
			if ( $guide && MuseSys_Guides::is_billing_profile_configured( $guide ) ) {
				$profile = $guide->billing;
			}
		}

		/**
		 * Filter the corporate billing profile applied at checkout.
		 *
		 * @param array $profile Associative array of billing_* field values (without `billing_` prefix).
		 */
		$profile = apply_filters( 'musesys_billing_lock_profile', $profile );
		return is_array( $profile ) ? $profile : array();
	}

	/**
	 * Hide locked billing fields via CSS when a guide billing profile is active.
	 */
	public static function inline_css(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || empty( self::get_active_billing_profile() ) ) {
			return;
		}
		$css = '
		.woocommerce-billing-fields h3,
		.woocommerce-billing-fields .form-row,
		.woocommerce-billing-fields__field-wrapper {
			display: none !important;
		}
		.woocommerce-billing-fields .form-row#billing_email_field,
		.woocommerce-billing-fields .form-row#billing_phone_field {
			display: block !important;
		}
		#billing_country_field,
		#billing_state_field,
		.woocommerce-billing-fields__field-wrapper .form-row.billing_country_field,
		.woocommerce-billing-fields__field-wrapper .form-row.billing_state_field {
			display: none !important;
		}
		';
		wp_register_style( 'musesys-billing-lock', false );
		wp_enqueue_style( 'musesys-billing-lock' );
		wp_add_inline_style( 'musesys-billing-lock', $css );
	}

	/**
	 * Remove all billing fields except email and phone when a guide billing profile is active.
	 *
	 * @param array $fields WooCommerce checkout fields.
	 * @return array
	 */
	public static function lock_corp_billing( array $fields ): array {
		if ( empty( self::get_active_billing_profile() ) ) {
			return $fields;
		}

		$allow_list = array( 'billing_email', 'billing_phone' );

		if ( isset( $fields['billing'] ) && is_array( $fields['billing'] ) ) {
			foreach ( array_keys( $fields['billing'] ) as $field_key ) {
				if ( ! in_array( $field_key, $allow_list, true ) ) {
					unset( $fields['billing'][ $field_key ] );
				}
			}
		}

		if ( isset( $fields['billing']['billing_email'] ) ) {
			$fields['billing']['billing_email']['required'] = true;
			$fields['billing']['billing_email']['class'][]  = 'form-row-wide';
		}
		if ( isset( $fields['billing']['billing_phone'] ) ) {
			$fields['billing']['billing_phone']['required'] = true;
			$fields['billing']['billing_phone']['class'][]  = 'form-row-wide';
		}

		return $fields;
	}

	/**
	 * Force corporate billing defaults from the active guide profile on checkout submission.
	 *
	 * @param array $data Posted checkout data.
	 * @return array
	 */
	public static function force_billing_defaults( array $data ): array {
		$profile = self::get_active_billing_profile();
		if ( empty( $profile ) ) {
			return $data;
		}

		$allowed_keys = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
		foreach ( $allowed_keys as $key ) {
			if ( isset( $profile[ $key ] ) && $profile[ $key ] !== '' ) {
				$data[ 'billing_' . $key ] = $profile[ $key ];
			}
		}

		return $data;
	}
}

add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			MuseSys_Billing_Lock::boot();
		}
	},
	5
);
