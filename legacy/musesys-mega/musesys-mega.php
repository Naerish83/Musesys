<?php
/*
Plugin Name: MuseSys Mega Plugin
Description: Consolidated suite of Muse tools (addresses, favorites, checkout glue).
Version: 1.2.11
Author: MuseSystems
License: GPL2
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MUSESYS_MEGA_PATH', plugin_dir_path( __FILE__ ) );
define( 'MUSESYS_MEGA_URL',  plugin_dir_url( __FILE__ ) );

if ( ! function_exists( 'musesys_discover_modules' ) ) {
    function musesys_discover_modules() {
        $modules_dir = trailingslashit( MUSESYS_MEGA_PATH ) . 'modules';
        $files = array();

        if ( is_dir( $modules_dir ) ) {
            // Include any index.php inside each module folder first
            foreach ( glob( $modules_dir . '/*/index.php' ) as $file ) {
                $files[] = $file;
            }
            // Then include any other PHP files inside those module folders
            foreach ( glob( $modules_dir . '/*/*.php' ) as $file ) {
                if ( basename( $file ) !== 'index.php' ) {
                    $files[] = $file;
                }
            }
        }
        return $files;
    }
}

add_action( 'plugins_loaded', function() {
    foreach ( musesys_discover_modules() as $php ) {
        include_once $php;
    }
} );

add_action( 'admin_menu', function () {
    add_menu_page( 'MuseSys', 'MuseSys', 'manage_options', 'musesys', 'musesys_dashboard_page', 'dashicons-admin-generic', 2 );
    add_submenu_page( 'musesys', 'Dashboard', 'Dashboard', 'manage_options', 'musesys', 'musesys_dashboard_page' );
}, 5 );

if ( ! function_exists( 'musesys_dashboard_page' ) ) {
    function musesys_dashboard_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions', 'musesys' ) );
        }
        echo '<div class="wrap"><h1>MuseSys Dashboard</h1><p>Select a module from the sidebar.</p></div>';
    }
}