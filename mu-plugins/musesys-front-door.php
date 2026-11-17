<?php
/**
 * Plugin Name: MuseSys Members-Only Front Door
 * Description: Locks each site behind login and multisite site-membership while preserving password reset, activation flows, and securing REST access.
 * Version: 1.4.0
 * Author: MuseSystems
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'musesys_members_only_gate', 1 );

/**
 * Redirect non-logged-in users to login and block non-members on multisite.
 */
function musesys_members_only_gate() {
	if ( isset( $GLOBALS['pagenow'] ) && in_array( $GLOBALS['pagenow'], array( 'wp-login.php', 'wp-register.php' ), true ) ) {
		return;
	}

	if (
		isset( $_GET['action'] ) &&
		in_array(
			$_GET['action'],
			array( 'lostpassword', 'rp', 'resetpass', 'enter_recovery_mode', 'newaccount' ),
			true
		)
	) {
		return;
	}

	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'lost-password' ) ) {
		return;
	}

	if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		$redirect_to = $_SERVER['REQUEST_URI'] ?? home_url();
		wp_safe_redirect( wp_login_url( $redirect_to ) );
		exit;
	}

	if ( is_multisite() && ! is_super_admin() && ! is_user_member_of_blog( get_current_user_id() ) ) {
		wp_die(
			esc_html__( 'Your account does not have access to this ordering portal. Please contact support.', 'musesys-front-door' ),
			esc_html__( 'Access Denied', 'musesys-front-door' ),
			array( 'response' => 403 )
		);
	}
}

add_filter( 'xmlrpc_enabled', '__return_false' );

add_filter(
	'login_errors',
	function () {
		return __( 'Invalid credentials. Please try again.', 'musesys-front-door' );
	}
);

add_filter(
	'rest_authentication_errors',
	function ( $result ) {
		if ( ! empty( $result ) ) {
			return $result;
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				'You must be logged in to access this endpoint.',
				array( 'status' => 401 )
			);
		}

		if ( is_multisite() && ! is_super_admin() && ! is_user_member_of_blog( get_current_user_id() ) ) {
			return new WP_Error(
				'rest_not_site_member',
				'You are not a member of this site.',
				array( 'status' => 403 )
			);
		}

		return $result;
	}
);
