<?php
/**
 * Plugin Name: MuseSys Wegmans Extension
 * Description: Wegmans-specific extension pack for MuseSys General (Work Order #, Install Store #, transfer items, brand filter, custom gaskets, legacy history).
 * Version: 0.0.1
 * Author: MuseSystems
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MUSESYS_WEGMANS_VERSION', '0.0.1' );
define( 'MUSESYS_WEGMANS_PATH', plugin_dir_path( __FILE__ ) );
define( 'MUSESYS_WEGMANS_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'MUSESYS_WEGMANS_WO_REGEX' ) ) {
	define( 'MUSESYS_WEGMANS_WO_REGEX', '/^(\d{7}|\d{9})$/' );
}
