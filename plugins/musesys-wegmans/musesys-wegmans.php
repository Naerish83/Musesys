<?php
/**
 * Plugin Name: MuseSys Wegmans Extension
 * Description: Wegmans-specific extension pack for MuseSys General (Work Order #, Install Store #, transfer items, brand filter, custom gaskets, legacy history).
 * Version: 0.0.2
 * Author: MuseSystems
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MUSESYS_WEGMANS_VERSION', '0.0.2' );
define( 'MUSESYS_WEGMANS_PATH', plugin_dir_path( __FILE__ ) );
define( 'MUSESYS_WEGMANS_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'MUSESYS_WEGMANS_WO_REGEX' ) ) {
	define( 'MUSESYS_WEGMANS_WO_REGEX', '/^(\d{7}|\d{9})$/' );
}

if ( ! function_exists( 'musesys_wegmans_discover_modules' ) ) {
	/**
	 * Discover Wegmans-specific module entrypoints under modules/.
	 *
	 * @return string[]
	 */
	function musesys_wegmans_discover_modules() {
		$modules_dir = trailingslashit( MUSESYS_WEGMANS_PATH ) . 'modules';
		$files       = array();

		if ( is_dir( $modules_dir ) ) {
			foreach ( glob( $modules_dir . '/*/index.php' ) as $file ) {
				$files[] = $file;
			}
			foreach ( glob( $modules_dir . '/*/*.php' ) as $file ) {
				if ( basename( $file ) !== 'index.php' ) {
					$files[] = $file;
				}
			}
		}

		return $files;
	}
}

add_action(
	'plugins_loaded',
	function () {
		foreach ( musesys_wegmans_discover_modules() as $php ) {
			include_once $php;
		}
	},
	20
);

/**
 * Order-reference seam: supply Wegmans Work Order # to MuseSys General.
 */
add_filter(
	'musesys_order_reference_label',
	function ( $label, $order ) {
		return __( 'Work Order #', 'musesys-wegmans' );
	},
	10,
	2
);

add_filter(
	'musesys_order_reference',
	function ( $value, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $value;
		}
		$wo = (string) $order->get_meta( '_muse_wo_global', true );
		if ( $wo === '' ) {
			$wo = (string) $order->get_meta( '_muse_workorder', true );
		}
		return $wo !== '' ? $wo : $value;
	},
	10,
	2
);

add_filter(
	'musesys_order_references',
	function ( $refs, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $refs;
		}
		$refs = is_array( $refs ) ? $refs : array();

		$global = (string) $order->get_meta( '_muse_wo_global', true );
		if ( $global === '' ) {
			$global = (string) $order->get_meta( '_muse_workorder', true );
		}
		if ( $global !== '' ) {
			$refs[] = $global;
		}

		foreach ( $order->get_items() as $item ) {
			$line_wo = (string) $item->get_meta( '_muse_wo_line' );
			if ( $line_wo !== '' ) {
				$refs[] = $line_wo;
			}
		}

		return array_values( array_unique( $refs ) );
	},
	10,
	2
);

/**
 * Register Wegmans-specific meta keys in MuseSys API field inventory.
 */
add_filter(
	'musesys_api_fields',
	function ( $fields ) {
		if ( isset( $fields['order_meta'] ) && is_array( $fields['order_meta'] ) ) {
			$fields['order_meta'][] = '_muse_wo_global';
			$fields['order_meta'][] = 'additional_install_store_number';
		}
		if ( isset( $fields['product_meta'] ) && is_array( $fields['product_meta'] ) ) {
			$fields['product_meta'][] = '_muse_wegmans_transfer_item';
		}
		return $fields;
	}
);
