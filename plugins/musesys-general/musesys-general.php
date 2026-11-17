<?php
/**
 * Plugin Name: MuseSys General
 * Description: Customer-agnostic order guide core (guides, locations, checkout, order tracking, ledger, and ops).
 * Version: 0.0.6
 * Author: MuseSystems
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MUSESYS_GENERAL_VERSION', '0.0.6' );
define( 'MUSESYS_GENERAL_PATH', plugin_dir_path( __FILE__ ) );
define( 'MUSESYS_GENERAL_URL', plugin_dir_url( __FILE__ ) );

if ( ! function_exists( 'musesys_general_discover_modules' ) ) {
	/**
	 * Discover module entrypoints under modules/.
	 *
	 * @return string[]
	 */
	function musesys_general_discover_modules() {
		$modules_dir = trailingslashit( MUSESYS_GENERAL_PATH ) . 'modules';
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
		foreach ( musesys_general_discover_modules() as $php ) {
			include_once $php;
		}
	}
);

add_action(
	'admin_menu',
	function () {
		add_menu_page(
			'MuseSys',
			'MuseSys',
			'manage_options',
			'musesys',
			'musesys_general_dashboard_page',
			'dashicons-admin-generic',
			2
		);
		add_submenu_page(
			'musesys',
			'Dashboard',
			'Dashboard',
			'manage_options',
			'musesys',
			'musesys_general_dashboard_page'
		);
	},
	5
);

if ( ! function_exists( 'musesys_general_dashboard_page' ) ) {
	/**
	 * Render the MuseSys Dashboard admin page.
	 */
	function musesys_general_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'musesys-general' ) );
		}
		echo '<div class="wrap"><h1>MuseSys Dashboard</h1><p>MuseSys General v' . esc_html( MUSESYS_GENERAL_VERSION ) . ' — Select a module from the MuseSys menu.</p></div>';
	}
}
