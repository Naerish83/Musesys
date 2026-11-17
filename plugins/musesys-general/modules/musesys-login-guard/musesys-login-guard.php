<?php
/**
 * Plugin Name: MuseSys Login Guard
 * Plugin URI:  https://gerharzequipment.com
 * Description: Brute-force protection, IP controls, and suspicious login monitoring for the private WooCommerce portal. Part of the MuseSys Mega suite.
 * Version:     2.0.1
 * Author:      MuseSystems
 * License:     Private
 *
 * @package MuseSys
 */

defined( 'ABSPATH' ) || exit;

define( 'MUSE_GUARD_VERSION', '2.0.1' );
define( 'MUSE_GUARD_MAX_ATTEMPTS', 3 );
define( 'MUSE_GUARD_MIN_LOGIN_SECONDS', 2 );

define( 'MUSE_GUARD_OPTION_VERSION', 'muse_guard_version' );
define( 'MUSE_GUARD_OPTION_USERNAME_BLOCKLIST', 'muse_guard_username_blocklist' );
define( 'MUSE_GUARD_OPTION_WHITELIST', 'muse_guard_ip_whitelist' );
define( 'MUSE_GUARD_OPTION_MANUAL_BLACKLIST', 'muse_guard_ip_blacklist' );

define( 'MUSE_GUARD_TRANSIENT_FAIL_PREFIX', 'muse_guard_fail_' );
define( 'MUSE_GUARD_TRANSIENT_SUSPICION_PREFIX', 'muse_guard_suspicion_' );
define( 'MUSE_GUARD_TRANSIENT_FORM_PREFIX', 'muse_guard_form_' );
define( 'MUSE_GUARD_TRANSIENT_ALERT_PREFIX', 'muse_guard_alert_' );

register_activation_hook( __FILE__, 'muse_guard_activate' );

muse_guard_init();
muse_guard_bootstrap();

/**
 * Bootstrap plugin hooks.
 *
 * @return void
 */
function muse_guard_init() {
	add_action( 'plugins_loaded', 'muse_guard_maybe_upgrade' );

	add_filter( 'xmlrpc_enabled', '__return_false' );
	add_action( 'init', 'muse_guard_block_xmlrpc_requests', 0 );

	add_action( 'login_init', 'muse_guard_handle_login_request', 1 );
	add_action( 'login_form', 'muse_guard_render_login_traps' );

	add_filter( 'authenticate', 'muse_guard_reject_blocked_ip_auth', 1, 3 );
	add_filter( 'login_errors', 'muse_guard_filter_login_errors' );

	add_action( 'wp_login_failed', 'muse_guard_record_failed_login', 10, 1 );
	add_action( 'wp_login', 'muse_guard_handle_successful_login', 10, 2 );

	if ( is_admin() ) {
		add_action( 'admin_menu', 'muse_guard_admin_menu' );
		add_action( 'admin_post_muse_guard_save_settings', 'muse_guard_handle_save_settings' );
		add_action( 'admin_post_muse_guard_unblock_ip', 'muse_guard_handle_unblock_ip' );
		add_action( 'admin_notices', 'muse_guard_admin_notices' );
	}
}

function muse_guard_bootstrap() {
    global $wpdb;

    $attempts = muse_guard_attempts_table();
    $blocks   = muse_guard_blocks_table();
    $known    = muse_guard_known_ips_table();

    // Check if ONE core table exists (cheap check)
    $table_exists = $wpdb->get_var(
        $wpdb->prepare("SHOW TABLES LIKE %s", $attempts)
    );

    if ($table_exists !== $attempts) {
        muse_guard_activate();
    }
}

/**
 * Upgrade schema/options when plugin version changes.
 *
 * @return void
 */
function muse_guard_maybe_upgrade() {
	$stored_version = get_option( MUSE_GUARD_OPTION_VERSION );

	if ( MUSE_GUARD_VERSION !== $stored_version ) {
		muse_guard_activate();
	}
}

/**
 * Activation and schema creation.
 *
 * Tables created:
 * - wp_muse_login_attempts: failed attempts, suspicious events, monitored activity
 * - wp_muse_login_blocks: active IP blocks and manual blacklist entries
 * - wp_muse_user_known_ips: known-good IP history per WordPress user
 *
 * @return void
 */
function muse_guard_activate() {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$attempts_table  = muse_guard_attempts_table();
	$blocks_table    = muse_guard_blocks_table();
	$known_ips_table = muse_guard_known_ips_table();

	$sql = "
	CREATE TABLE {$attempts_table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		ip_address VARCHAR(45) NOT NULL,
		username VARCHAR(191) NOT NULL DEFAULT '',
		user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		event_type VARCHAR(50) NOT NULL DEFAULT 'failed',
		reason VARCHAR(191) NOT NULL DEFAULT '',
		attempt_count SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
		username_exists TINYINT(1) NOT NULL DEFAULT 0,
		is_known_ip TINYINT(1) NOT NULL DEFAULT 0,
		user_agent VARCHAR(255) NOT NULL DEFAULT '',
		metadata LONGTEXT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY ip_address (ip_address),
		KEY username (username),
		KEY event_type (event_type),
		KEY created_at (created_at)
	) {$charset_collate};

	CREATE TABLE {$blocks_table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		ip_address VARCHAR(45) NOT NULL,
		block_type VARCHAR(50) NOT NULL DEFAULT 'threshold',
		reason VARCHAR(191) NOT NULL DEFAULT '',
		username VARCHAR(191) NOT NULL DEFAULT '',
		is_active TINYINT(1) NOT NULL DEFAULT 1,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY ip_address (ip_address),
		KEY block_type (block_type),
		KEY is_active (is_active)
	) {$charset_collate};

	CREATE TABLE {$known_ips_table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT(20) UNSIGNED NOT NULL,
		ip_address VARCHAR(45) NOT NULL,
		first_seen DATETIME NOT NULL,
		last_seen DATETIME NOT NULL,
		login_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
		PRIMARY KEY  (id),
		UNIQUE KEY user_ip (user_id, ip_address),
		KEY ip_address (ip_address),
		KEY last_seen (last_seen)
	) {$charset_collate};
	";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	if ( false === get_option( MUSE_GUARD_OPTION_USERNAME_BLOCKLIST, false ) ) {
		update_option(
			MUSE_GUARD_OPTION_USERNAME_BLOCKLIST,
			implode(
				"\n",
				array(
					'admin',
					'administrator',
					'root',
					'test',
					'user',
					'webmaster',
					'wordpress',
					'demo',
					'guest',
					'support',
					'info',
					'mail',
					'postmaster',
					'hostmaster',
					'wp',
					'wpadmin',
					'sysadmin',
				)
			)
		);
	}

	if ( false === get_option( MUSE_GUARD_OPTION_WHITELIST, false ) ) {
		update_option( MUSE_GUARD_OPTION_WHITELIST, '' );
	}

	if ( false === get_option( MUSE_GUARD_OPTION_MANUAL_BLACKLIST, false ) ) {
		update_option( MUSE_GUARD_OPTION_MANUAL_BLACKLIST, '' );
	}

	update_option( MUSE_GUARD_OPTION_VERSION, MUSE_GUARD_VERSION );

	muse_guard_sync_manual_blacklist( muse_guard_get_ip_list_option( MUSE_GUARD_OPTION_MANUAL_BLACKLIST ) );
}

/**
 * Attempts table name.
 *
 * @return string
 */
function muse_guard_attempts_table() {
	global $wpdb;
	return $wpdb->prefix . 'muse_login_attempts';
}

/**
 * Blocks table name.
 *
 * @return string
 */
function muse_guard_blocks_table() {
	global $wpdb;
	return $wpdb->prefix . 'muse_login_blocks';
}

/**
 * Known IPs table name.
 *
 * @return string
 */
function muse_guard_known_ips_table() {
	global $wpdb;
	return $wpdb->prefix . 'muse_user_known_ips';
}

/**
 * Hard-disable XML-RPC requests.
 *
 * @return void
 */
function muse_guard_block_xmlrpc_requests() {
	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$request_uri = wp_unslash( $_SERVER['REQUEST_URI'] );

	if ( false !== strpos( $request_uri, 'xmlrpc.php' ) ) {
		status_header( 403 );
		exit;
	}
}

/**
 * Get the client IP with proxy / Cloudflare awareness.
 *
 * @return string
 */
function muse_guard_get_client_ip() {
	$server_keys = array(
		'HTTP_CF_CONNECTING_IP',
		'HTTP_TRUE_CLIENT_IP',
		'HTTP_X_REAL_IP',
		'HTTP_X_FORWARDED_FOR',
		'REMOTE_ADDR',
	);

	$fallback_ip = '';

	foreach ( $server_keys as $server_key ) {
		if ( empty( $_SERVER[ $server_key ] ) ) {
			continue;
		}

		$raw_value  = sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) );
		$candidates = array_map( 'trim', explode( ',', $raw_value ) );

		foreach ( $candidates as $candidate ) {
			if ( ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				continue;
			}

			if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $candidate;
			}

			if ( '' === $fallback_ip ) {
				$fallback_ip = $candidate;
			}
		}
	}

	return $fallback_ip ? $fallback_ip : '0.0.0.0';
}

/**
 * Current user agent.
 *
 * @return string
 */
function muse_guard_get_current_user_agent() {
	if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
		return '';
	}

	return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 );
}

/**
 * Normalize textarea/comma-separated IP input.
 *
 * @param string $raw Raw IP list.
 * @return array
 */
function muse_guard_normalize_ip_list( $raw ) {
	$pieces = preg_split( '/[\r\n,]+/', (string) $raw );
	$ips    = array();

	if ( empty( $pieces ) ) {
		return array();
	}

	foreach ( $pieces as $piece ) {
		$ip = trim( $piece );

		if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ips[] = $ip;
		}
	}

	$ips = array_values( array_unique( $ips ) );
	sort( $ips );

	return $ips;
}

/**
 * Get a normalized IP list option.
 *
 * @param string $option_name Option name.
 * @return array
 */
function muse_guard_get_ip_list_option( $option_name ) {
	return muse_guard_normalize_ip_list( (string) get_option( $option_name, '' ) );
}

/**
 * Curated username trap list.
 *
 * @return array
 */
function muse_guard_get_username_blocklist() {
	$raw   = (string) get_option( MUSE_GUARD_OPTION_USERNAME_BLOCKLIST, '' );
	$lines = preg_split( '/\r\n|\r|\n/', $raw );
	$list  = array();

	if ( empty( $lines ) ) {
		return array();
	}

	foreach ( $lines as $line ) {
		$value = strtolower( trim( $line ) );

		if ( '' !== $value ) {
			$list[] = $value;
		}
	}

	$list = array_values( array_unique( $list ) );
	sort( $list );

	return $list;
}

/**
 * Posted username/login field.
 *
 * @return string
 */
function muse_guard_get_posted_username() {
	if ( ! isset( $_POST['log'] ) ) {
		return '';
	}

	return trim( sanitize_text_field( wp_unslash( $_POST['log'] ) ) );
}

/**
 * Check whether a username/email maps to an existing WP user.
 *
 * @param string $username Username or email.
 * @return bool
 */
function muse_guard_username_exists( $username ) {
	return (bool) muse_guard_get_user_by_identifier( $username );
}

/**
 * Resolve a login identifier to a WP_User.
 *
 * @param string $identifier Username or email.
 * @return WP_User|false
 */
function muse_guard_get_user_by_identifier( $identifier ) {
	$identifier = trim( (string) $identifier );

	if ( '' === $identifier ) {
		return false;
	}

	$user = get_user_by( 'login', $identifier );

	if ( ! $user && is_email( $identifier ) ) {
		$user = get_user_by( 'email', $identifier );
	}

	return $user instanceof WP_User ? $user : false;
}

/**
 * Check whitelist.
 *
 * @param string $ip IP address.
 * @return bool
 */
function muse_guard_is_ip_whitelisted( $ip ) {
	return in_array( $ip, muse_guard_get_ip_list_option( MUSE_GUARD_OPTION_WHITELIST ), true );
}

/**
 * Check manual blacklist.
 *
 * @param string $ip IP address.
 * @return bool
 */
function muse_guard_is_ip_blacklisted( $ip ) {
	global $wpdb;

	$blocks_table = muse_guard_blocks_table();

	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$blocks_table} WHERE ip_address = %s AND is_active = 1 AND block_type = %s LIMIT 1",
			$ip,
			'manual_blacklist'
		)
	);
}

/**
 * Fetch active IP block.
 *
 * @param string $ip IP address.
 * @return object|null
 */
function muse_guard_get_active_block( $ip ) {
	global $wpdb;

	$blocks_table = muse_guard_blocks_table();

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$blocks_table} WHERE ip_address = %s AND is_active = 1 LIMIT 1",
			$ip
		)
	);
}

/**
 * Check whether IP is currently blocked for any active reason.
 *
 * @param string $ip IP address.
 * @return bool
 */
function muse_guard_is_ip_blocked( $ip ) {
	return (bool) muse_guard_get_active_block( $ip );
}

/**
 * Insert or update an IP block.
 *
 * @param string $ip         IP address.
 * @param string $username   Username tied to block.
 * @param string $block_type Block type.
 * @param string $reason     Human-readable reason.
 * @return int
 */
function muse_guard_block_ip( $ip, $username = '', $block_type = 'threshold', $reason = '' ) {
	global $wpdb;

	$blocks_table = muse_guard_blocks_table();
	$now          = current_time( 'mysql' );
	$existing     = muse_guard_get_active_block( $ip );

	$data = array(
		'ip_address' => $ip,
		'block_type' => sanitize_key( $block_type ),
		'reason'     => sanitize_text_field( $reason ),
		'username'   => sanitize_text_field( (string) $username ),
		'is_active'  => 1,
		'updated_at' => $now,
	);

	if ( $existing ) {
		$wpdb->update(
			$blocks_table,
			$data,
			array( 'id' => (int) $existing->id ),
			array( '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return (int) $existing->id;
	}

	$data['created_at'] = $now;

	$wpdb->insert(
		$blocks_table,
		$data,
		array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	return (int) $wpdb->insert_id;
}

/**
 * Remove a block by IP.
 *
 * @param string $ip IP address.
 * @return bool
 */
function muse_guard_unblock_ip( $ip ) {
	global $wpdb;

	return (bool) $wpdb->delete(
		muse_guard_blocks_table(),
		array( 'ip_address' => $ip ),
		array( '%s' )
	);
}

/**
 * Generic event logger for attempts/suspicious activity/monitoring.
 *
 * @param array $args Event data.
 * @return void
 */
function muse_guard_record_event( $args ) {
	global $wpdb;

	$defaults = array(
		'ip_address'      => muse_guard_get_client_ip(),
		'username'        => '',
		'user_id'         => 0,
		'event_type'      => 'failed',
		'reason'          => '',
		'attempt_count'   => 0,
		'username_exists' => 0,
		'is_known_ip'     => 0,
		'user_agent'      => muse_guard_get_current_user_agent(),
		'metadata'        => null,
		'created_at'      => current_time( 'mysql' ),
	);

	$data = wp_parse_args( $args, $defaults );

	if ( is_array( $data['metadata'] ) ) {
		$data['metadata'] = wp_json_encode( $data['metadata'] );
	}

	$wpdb->insert(
		muse_guard_attempts_table(),
		array(
			'ip_address'      => sanitize_text_field( $data['ip_address'] ),
			'username'        => sanitize_text_field( (string) $data['username'] ),
			'user_id'         => absint( $data['user_id'] ),
			'event_type'      => sanitize_key( $data['event_type'] ),
			'reason'          => sanitize_text_field( (string) $data['reason'] ),
			'attempt_count'   => absint( $data['attempt_count'] ),
			'username_exists' => ! empty( $data['username_exists'] ) ? 1 : 0,
			'is_known_ip'     => ! empty( $data['is_known_ip'] ) ? 1 : 0,
			'user_agent'      => sanitize_text_field( (string) $data['user_agent'] ),
			'metadata'        => $data['metadata'],
			'created_at'      => $data['created_at'],
		),
		array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
	);
}

/**
 * Failed-attempt transient key.
 *
 * @param string $ip IP address.
 * @return string
 */
function muse_guard_get_fail_counter_key( $ip ) {
	return MUSE_GUARD_TRANSIENT_FAIL_PREFIX . md5( $ip );
}

/**
 * Suspicion transient key.
 *
 * @param string $ip IP address.
 * @return string
 */
function muse_guard_get_suspicion_key( $ip ) {
	return MUSE_GUARD_TRANSIENT_SUSPICION_PREFIX . md5( $ip );
}

/**
 * Login form transient key.
 *
 * @param string $token Form token.
 * @return string
 */
function muse_guard_get_form_key( $token ) {
	return MUSE_GUARD_TRANSIENT_FORM_PREFIX . sanitize_key( $token );
}

/**
 * Alert suppression transient key.
 *
 * @param string $username Username.
 * @param string $ip       IP address.
 * @return string
 */
function muse_guard_get_alert_key( $username, $ip ) {
	return MUSE_GUARD_TRANSIENT_ALERT_PREFIX . md5( strtolower( $username ) . '|' . $ip );
}

/**
 * Read current failed-attempt count for IP.
 *
 * @param string $ip IP address.
 * @return int
 */
function muse_guard_get_failed_attempt_count( $ip ) {
	return (int) get_transient( muse_guard_get_fail_counter_key( $ip ) );
}

/**
 * Increment failed-attempt counter for IP.
 *
 * @param string $ip IP address.
 * @return int
 */
function muse_guard_increment_failed_attempt_counter( $ip ) {
	$count = muse_guard_get_failed_attempt_count( $ip ) + 1;
	set_transient( muse_guard_get_fail_counter_key( $ip ), $count, 12 * HOUR_IN_SECONDS );
	return $count;
}

/**
 * Clear transient counters on successful login.
 *
 * @param string $ip IP address.
 * @return void
 */
function muse_guard_clear_failed_attempt_counter( $ip ) {
	delete_transient( muse_guard_get_fail_counter_key( $ip ) );
}

/**
 * Get suspicion state for an IP.
 *
 * @param string $ip IP address.
 * @return array
 */
function muse_guard_get_suspicion_state( $ip ) {
	$state = get_transient( muse_guard_get_suspicion_key( $ip ) );

	if ( ! is_array( $state ) ) {
		$state = array(
			'reasons'     => array(),
			'fastest_sec' => null,
			'last_seen'   => null,
		);
	}

	if ( empty( $state['reasons'] ) || ! is_array( $state['reasons'] ) ) {
		$state['reasons'] = array();
	}

	return $state;
}

/**
 * Add a suspicion marker to an IP.
 *
 * @param string $ip      IP address.
 * @param string $reason  Suspicion reason.
 * @param array  $context Extra context.
 * @return void
 */
function muse_guard_add_suspicion_reason( $ip, $reason, $context = array() ) {
	$state              = muse_guard_get_suspicion_state( $ip );
	$state['reasons'][] = sanitize_key( $reason );
	$state['reasons']   = array_values( array_unique( $state['reasons'] ) );
	$state['last_seen'] = time();

	if ( isset( $context['elapsed'] ) && is_numeric( $context['elapsed'] ) ) {
		$elapsed = (float) $context['elapsed'];

		if ( null === $state['fastest_sec'] || $elapsed < $state['fastest_sec'] ) {
			$state['fastest_sec'] = $elapsed;
		}
	}

	set_transient( muse_guard_get_suspicion_key( $ip ), $state, 12 * HOUR_IN_SECONDS );
}

/**
 * Clear suspicion state after good login.
 *
 * @param string $ip IP address.
 * @return void
 */
function muse_guard_clear_suspicion_state( $ip ) {
	delete_transient( muse_guard_get_suspicion_key( $ip ) );
}

/**
 * Randomized hostile-request delay.
 *
 * @return void
 */
function muse_guard_randomized_delay() {
	sleep( wp_rand( 2, 5 ) );
}

/**
 * Keep login errors vague.
 *
 * @return string
 */
function muse_guard_filter_login_errors() {
	return __( 'Invalid username or password.', 'musesys-login-guard' );
}

/**
 * Kill a hostile login request with a vague response.
 *
 * @return void
 */
function muse_guard_deny_login_request() {
	muse_guard_randomized_delay();
	nocache_headers();
	status_header( 403 );

	wp_die(
		esc_html__( 'Invalid username or password.', 'musesys-login-guard' ),
		esc_html__( 'Access denied', 'musesys-login-guard' ),
		array( 'response' => 403 )
	);
}

/**
 * Render honeypot and timing token on wp-login.php.
 *
 * @return void
 */
function muse_guard_render_login_traps() {
	$token = wp_generate_password( 20, false, false );
	set_transient( muse_guard_get_form_key( $token ), time(), 10 * MINUTE_IN_SECONDS );
	?>
	<p class="muse-guard-login-trap" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
		<label for="muse_guard_website"><?php esc_html_e( 'Leave this field empty', 'musesys-login-guard' ); ?></label>
		<input type="text" name="muse_guard_website" id="muse_guard_website" value="" tabindex="-1" autocomplete="off">
	</p>
	<input type="hidden" name="muse_guard_token" value="<?php echo esc_attr( $token ); ?>">
	<?php
}

/**
 * Validate login form timing.
 *
 * @param string $token Login form token.
 * @return array
 */
function muse_guard_check_login_form_timing( $token ) {
	$token = sanitize_key( (string) $token );

	if ( '' === $token ) {
		return array(
			'is_suspicious' => true,
			'elapsed'       => 0,
			'reason'        => 'missing_form_token',
		);
	}

	$transient_key = muse_guard_get_form_key( $token );
	$rendered_at   = get_transient( $transient_key );
	delete_transient( $transient_key );

	if ( ! $rendered_at ) {
		return array(
			'is_suspicious' => true,
			'elapsed'       => 0,
			'reason'        => 'expired_form_token',
		);
	}

	$elapsed = max( 0, time() - (int) $rendered_at );

	return array(
		'is_suspicious' => $elapsed < MUSE_GUARD_MIN_LOGIN_SECONDS,
		'elapsed'       => $elapsed,
		'reason'        => $elapsed < MUSE_GUARD_MIN_LOGIN_SECONDS ? 'fast_submit' : '',
	);
}

/**
 * Early login handling before auth finishes.
 *
 * Important: this does not record failed attempts.
 * Actual failed attempts are only recorded on wp_login_failed.
 *
 * @return void
 */
function muse_guard_handle_login_request() {
	$ip          = muse_guard_get_client_ip();
	$whitelisted = muse_guard_is_ip_whitelisted( $ip );

	if ( muse_guard_is_ip_blacklisted( $ip ) ) {
		muse_guard_deny_login_request();
	}

	if ( muse_guard_is_ip_blocked( $ip ) && ! $whitelisted ) {
		muse_guard_deny_login_request();
	}

	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

	if ( 'POST' !== $request_method ) {
		return;
	}

	$username   = muse_guard_get_posted_username();
	$honeypot   = isset( $_POST['muse_guard_website'] ) ? trim( (string) wp_unslash( $_POST['muse_guard_website'] ) ) : '';
	$form_token = isset( $_POST['muse_guard_token'] ) ? wp_unslash( $_POST['muse_guard_token'] ) : '';

	if ( '' !== $honeypot ) {
		muse_guard_record_event(
			array(
				'ip_address'      => $ip,
				'username'        => $username,
				'event_type'      => 'suspicious',
				'reason'          => 'honeypot_triggered',
				'username_exists' => muse_guard_username_exists( $username ),
				'metadata'        => array(
					'source' => 'login_form',
				),
			)
		);

		muse_guard_block_ip( $ip, $username, 'honeypot', 'Login honeypot triggered' );
		muse_guard_deny_login_request();
	}

	if ( $username && in_array( strtolower( $username ), muse_guard_get_username_blocklist(), true ) ) {
		muse_guard_record_event(
			array(
				'ip_address'      => $ip,
				'username'        => $username,
				'event_type'      => 'suspicious',
				'reason'          => 'curated_bad_username',
				'username_exists' => muse_guard_username_exists( $username ),
				'metadata'        => array(
					'source' => 'username_blocklist',
				),
			)
		);

		muse_guard_block_ip( $ip, $username, 'curated_username', 'Curated username trap' );
		muse_guard_deny_login_request();
	}

	$timing_check = muse_guard_check_login_form_timing( $form_token );

	if ( $timing_check['is_suspicious'] ) {
		muse_guard_add_suspicion_reason(
			$ip,
			$timing_check['reason'],
			array(
				'elapsed' => $timing_check['elapsed'],
			)
		);

		muse_guard_record_event(
			array(
				'ip_address'      => $ip,
				'username'        => $username,
				'event_type'      => 'suspicious',
				'reason'          => $timing_check['reason'],
				'username_exists' => muse_guard_username_exists( $username ),
				'metadata'        => array(
					'elapsed_seconds' => $timing_check['elapsed'],
					'minimum_seconds' => MUSE_GUARD_MIN_LOGIN_SECONDS,
				),
			)
		);

		if ( ! $whitelisted ) {
			muse_guard_randomized_delay();
		}
	}
}

/**
 * Reject blocked IPs during auth.
 *
 * @param WP_User|WP_Error|null $user     Current auth result.
 * @param string                $username Submitted username.
 * @param string                $password Submitted password.
 * @return WP_User|WP_Error|null
 */
function muse_guard_reject_blocked_ip_auth( $user, $username, $password ) {
	$ip = muse_guard_get_client_ip();

	if ( muse_guard_is_ip_blacklisted( $ip ) ) {
		return new WP_Error( 'muse_guard_blocked', __( 'Invalid username or password.', 'musesys-login-guard' ) );
	}

	if ( muse_guard_is_ip_blocked( $ip ) && ! muse_guard_is_ip_whitelisted( $ip ) ) {
		return new WP_Error( 'muse_guard_blocked', __( 'Invalid username or password.', 'musesys-login-guard' ) );
	}

	return $user;
}

/**
 * Check if IP is already known-good for a user.
 *
 * @param int    $user_id User ID.
 * @param string $ip      IP address.
 * @return bool
 */
function muse_guard_is_known_ip_for_user( $user_id, $ip ) {
	global $wpdb;

	if ( $user_id <= 0 ) {
		return false;
	}

	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT id FROM ' . muse_guard_known_ips_table() . ' WHERE user_id = %d AND ip_address = %s LIMIT 1',
			$user_id,
			$ip
		)
	);
}

/**
 * Record a real failed login attempt.
 *
 * @param string $username Submitted username.
 * @return array
 */
function muse_guard_record_failed_attempt( $username ) {
	$ip              = muse_guard_get_client_ip();
	$username        = trim( sanitize_text_field( (string) $username ) );
	$user            = muse_guard_get_user_by_identifier( $username );
	$user_id         = $user instanceof WP_User ? (int) $user->ID : 0;
	$username_exists = $user_id > 0;
	$is_known_ip     = $user_id > 0 ? muse_guard_is_known_ip_for_user( $user_id, $ip ) : false;
	$attempt_count   = muse_guard_increment_failed_attempt_counter( $ip );
	$suspicion       = muse_guard_get_suspicion_state( $ip );
	$reasons         = ! empty( $suspicion['reasons'] ) ? $suspicion['reasons'] : array();

	muse_guard_record_event(
		array(
			'ip_address'      => $ip,
			'username'        => $username,
			'user_id'         => $user_id,
			'event_type'      => 'failed',
			'reason'          => $reasons ? implode( ',', $reasons ) : 'authentication_failed',
			'attempt_count'   => $attempt_count,
			'username_exists' => $username_exists,
			'is_known_ip'     => $is_known_ip,
			'metadata'        => array(
				'suspicion_reasons' => $reasons,
				'fastest_seconds'   => $suspicion['fastest_sec'],
			),
		)
	);

	return array(
		'ip'              => $ip,
		'user'            => $user,
		'user_id'         => $user_id,
		'username_exists' => $username_exists,
		'is_known_ip'     => $is_known_ip,
		'attempt_count'   => $attempt_count,
		'suspicion'       => $suspicion,
	);
}

/**
 * Send alert when an existing user hits the threshold.
 *
 * @param string  $username      Username submitted.
 * @param WP_User $user          Resolved user.
 * @param string  $ip            IP address.
 * @param int     $attempt_count Attempt count.
 * @return void
 */
function muse_guard_maybe_send_threshold_alert( $username, WP_User $user, $ip, $attempt_count ) {
	$alert_key = muse_guard_get_alert_key( $username, $ip );

	if ( get_transient( $alert_key ) ) {
		return;
	}

	$admin_email = get_option( 'admin_email' );

	if ( ! is_email( $admin_email ) ) {
		return;
	}

	$subject = sprintf(
		/* translators: %s: username */
		__( 'MuseSys Login Guard alert for %s', 'musesys-login-guard' ),
		$user->user_login
	);

	$message = array(
		'MuseSys Login Guard detected repeated failed login attempts for an existing WordPress account.',
		'',
		'Username: ' . $user->user_login,
		'User ID: ' . $user->ID,
		'IP Address: ' . $ip,
		'Failed Attempts (current IP window): ' . (int) $attempt_count,
		'Time: ' . current_time( 'mysql' ),
	);

	wp_mail( $admin_email, $subject, implode( "\n", $message ) );
	set_transient( $alert_key, 1, DAY_IN_SECONDS );
}

/**
 * wp_login_failed hook.
 *
 * This is the only place failed attempts are counted.
 *
 * @param string $username Submitted username.
 * @return void
 */
function muse_guard_record_failed_login( $username ) {
	$details       = muse_guard_record_failed_attempt( $username );
	$ip            = $details['ip'];
	$user          = $details['user'];
	$attempt_count = $details['attempt_count'];
	$reasons       = ! empty( $details['suspicion']['reasons'] ) ? $details['suspicion']['reasons'] : array();
	$hostile       = ! empty( $reasons );
	$whitelisted   = muse_guard_is_ip_whitelisted( $ip );

	if ( $details['username_exists'] && $user instanceof WP_User && $attempt_count >= MUSE_GUARD_MAX_ATTEMPTS ) {
		muse_guard_maybe_send_threshold_alert( $username, $user, $ip, $attempt_count );
	}

	if ( $attempt_count < MUSE_GUARD_MAX_ATTEMPTS ) {
		return;
	}

	$should_block = ! $whitelisted;

	if ( $details['username_exists'] && $details['is_known_ip'] && ! $hostile ) {
		$should_block = false;
	}

	if ( $should_block ) {
		muse_guard_block_ip( $ip, $username, 'threshold', 'Failed login threshold reached' );
		return;
	}

	muse_guard_record_event(
		array(
			'ip_address'      => $ip,
			'username'        => $username,
			'user_id'         => $details['user_id'],
			'event_type'      => 'threshold_watch',
			'reason'          => 'threshold_reached_known_or_whitelisted_ip',
			'attempt_count'   => $attempt_count,
			'username_exists' => $details['username_exists'],
			'is_known_ip'     => $details['is_known_ip'],
			'metadata'        => array(
				'suspicion_reasons' => $reasons,
			),
		)
	);
}

/**
 * Upsert successful user/IP pair.
 *
 * @param int    $user_id User ID.
 * @param string $ip      IP address.
 * @return bool True when this is a new IP for the user.
 */
function muse_guard_record_successful_known_ip( $user_id, $ip ) {
	global $wpdb;

	$known_ips_table = muse_guard_known_ips_table();
	$existing        = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$known_ips_table} WHERE user_id = %d AND ip_address = %s LIMIT 1",
			$user_id,
			$ip
		)
	);
	$now             = current_time( 'mysql' );

	if ( $existing ) {
		$wpdb->update(
			$known_ips_table,
			array(
				'last_seen'   => $now,
				'login_count' => (int) $existing->login_count + 1,
			),
			array( 'id' => (int) $existing->id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		return false;
	}

	$wpdb->insert(
		$known_ips_table,
		array(
			'user_id'     => $user_id,
			'ip_address'  => $ip,
			'first_seen'  => $now,
			'last_seen'   => $now,
			'login_count' => 1,
		),
		array( '%d', '%s', '%s', '%s', '%d' )
	);

	return true;
}

/**
 * wp_login hook handler.
 *
 * @param string  $user_login Username.
 * @param WP_User $user       User object.
 * @return void
 */
function muse_guard_handle_successful_login( $user_login, $user ) {
	$ip = muse_guard_get_client_ip();

	muse_guard_clear_failed_attempt_counter( $ip );
	muse_guard_clear_suspicion_state( $ip );

	if ( ! $user instanceof WP_User ) {
		return;
	}

	$is_new_ip = muse_guard_record_successful_known_ip( (int) $user->ID, $ip );

	if ( $is_new_ip ) {
		muse_guard_record_event(
			array(
				'ip_address'      => $ip,
				'username'        => $user->user_login,
				'user_id'         => (int) $user->ID,
				'event_type'      => 'new_ip_success',
				'reason'          => 'new_known_ip_recorded',
				'username_exists' => 1,
				'is_known_ip'     => 0,
				'metadata'        => array(
					'message' => 'New IP observed and stored for this user.',
				),
			)
		);
	}
}

/**
 * Sync admin-managed blacklist option into the blocks table.
 *
 * @param array $ips Manual blacklist IPs.
 * @return void
 */
function muse_guard_sync_manual_blacklist( $ips ) {
	global $wpdb;

	$blocks_table = muse_guard_blocks_table();
	$stored_rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, ip_address FROM {$blocks_table} WHERE block_type = %s",
			'manual_blacklist'
		)
	);
	$existing_map = array();

	foreach ( $stored_rows as $row ) {
		$existing_map[ $row->ip_address ] = (int) $row->id;
	}

	foreach ( $ips as $ip ) {
		muse_guard_block_ip( $ip, '', 'manual_blacklist', 'Admin-managed blacklist' );
		unset( $existing_map[ $ip ] );
	}

	foreach ( $existing_map as $row_id ) {
		$wpdb->delete(
			$blocks_table,
			array(
				'id'         => $row_id,
				'block_type' => 'manual_blacklist',
			),
			array( '%d', '%s' )
		);
	}
}

/**
 * Choose parent menu slug gracefully.
 *
 * @return string
 */
function muse_guard_get_admin_parent_slug() {
	global $menu;

	if ( is_array( $menu ) ) {
		foreach ( $menu as $menu_item ) {
			if ( ! empty( $menu_item[2] ) && 'musesys' === $menu_item[2] ) {
				return 'musesys';
			}
		}
	}

	return 'musesys';
}

/**
 * Admin menu registration.
 *
 * @return void
 */
function muse_guard_admin_menu() {
	add_submenu_page(
		muse_guard_get_admin_parent_slug(),
		__( 'Login Guard', 'musesys-login-guard' ),
		__( 'Login Guard', 'musesys-login-guard' ),
		'manage_options',
		'muse-login-guard',
		'muse_guard_render_admin_page'
	);
}

/**
 * Admin page.
 *
 * @return void
 */
function muse_guard_render_admin_page() {
	global $wpdb;

	$blocks_table    = muse_guard_blocks_table();
	$attempts_table  = muse_guard_attempts_table();
	$known_ips_table = muse_guard_known_ips_table();

	$blocked_ips = $wpdb->get_results( "SELECT * FROM {$blocks_table} WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 200" );

	$recent_attempts = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$attempts_table} WHERE event_type = %s ORDER BY created_at DESC LIMIT %d",
			'failed',
			50
		)
	);

	$suspicious_events = $wpdb->get_results(
		"SELECT * FROM {$attempts_table} WHERE event_type IN ('suspicious', 'threshold_watch', 'new_ip_success') ORDER BY created_at DESC LIMIT 50"
	);

	$total_blocked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$blocks_table} WHERE is_active = 1" );
	$total_failed  = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$attempts_table} WHERE event_type = %s",
			'failed'
		)
	);
	$total_known_ips   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$known_ips_table}" );
	$manual_blacklists = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$blocks_table} WHERE block_type = %s AND is_active = 1",
			'manual_blacklist'
		)
	);

	$username_blocklist = (string) get_option( MUSE_GUARD_OPTION_USERNAME_BLOCKLIST, '' );
	$whitelist          = (string) get_option( MUSE_GUARD_OPTION_WHITELIST, '' );
	$blacklist          = (string) get_option( MUSE_GUARD_OPTION_MANUAL_BLACKLIST, '' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'MuseSys Login Guard', 'musesys-login-guard' ); ?></h1>
		<p style="color:#666;">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: plugin version */
					__( 'Part of the MuseSys Mega suite. Version %s', 'musesys-login-guard' ),
					MUSE_GUARD_VERSION
				)
			);
			?>
		</p>

		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:24px;margin-top:20px;">
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Guard Settings', 'musesys-login-guard' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'muse_guard_save_settings', 'muse_guard_nonce' ); ?>
					<input type="hidden" name="action" value="muse_guard_save_settings">

					<p>
						<label for="muse_guard_blocklist"><strong><?php esc_html_e( 'Username blocklist', 'musesys-login-guard' ); ?></strong></label><br>
						<textarea id="muse_guard_blocklist" name="muse_guard_blocklist" rows="10" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $username_blocklist ); ?></textarea>
						<span class="description"><?php esc_html_e( 'One username per line. These usernames trigger an immediate permanent IP block when used on wp-login.php.', 'musesys-login-guard' ); ?></span>
					</p>

					<p>
						<label for="muse_guard_whitelist"><strong><?php esc_html_e( 'IP whitelist', 'musesys-login-guard' ); ?></strong></label><br>
						<textarea id="muse_guard_whitelist" name="muse_guard_whitelist" rows="6" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $whitelist ); ?></textarea>
						<span class="description"><?php esc_html_e( 'One IP per line. Whitelisted IPs bypass most automated blocking logic, but not the manual blacklist.', 'musesys-login-guard' ); ?></span>
					</p>

					<p>
						<label for="muse_guard_blacklist"><strong><?php esc_html_e( 'IP blacklist', 'musesys-login-guard' ); ?></strong></label><br>
						<textarea id="muse_guard_blacklist" name="muse_guard_blacklist" rows="6" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $blacklist ); ?></textarea>
						<span class="description"><?php esc_html_e( 'One IP per line. These IPs are denied immediately and stored in the block table as admin-managed blacklist entries.', 'musesys-login-guard' ); ?></span>
					</p>

					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Login Guard Settings', 'musesys-login-guard' ); ?></button></p>
				</form>
			</div>

			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Guard Stats', 'musesys-login-guard' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Active blocked IPs', 'musesys-login-guard' ); ?></strong></td>
							<td><?php echo esc_html( (string) $total_blocked ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Admin blacklisted IPs', 'musesys-login-guard' ); ?></strong></td>
							<td><?php echo esc_html( (string) $manual_blacklists ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Logged failed attempts', 'musesys-login-guard' ); ?></strong></td>
							<td><?php echo esc_html( (string) $total_failed ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Known-good user/IP pairs', 'musesys-login-guard' ); ?></strong></td>
							<td><?php echo esc_html( (string) $total_known_ips ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Failed-attempt threshold', 'musesys-login-guard' ); ?></strong></td>
							<td><?php echo esc_html( (string) MUSE_GUARD_MAX_ATTEMPTS ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Minimum human submit time', 'musesys-login-guard' ); ?></strong></td>
							<td><?php echo esc_html( (string) MUSE_GUARD_MIN_LOGIN_SECONDS . 's' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-top:24px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Blocked IPs', 'musesys-login-guard' ); ?></h2>
			<?php if ( empty( $blocked_ips ) ) : ?>
				<p><?php esc_html_e( 'No blocked IPs are currently stored.', 'musesys-login-guard' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'IP Address', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Type', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Username', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Created', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Updated', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Action', 'musesys-login-guard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $blocked_ips as $row ) : ?>
							<tr>
								<td><code><?php echo esc_html( $row->ip_address ); ?></code></td>
								<td><?php echo esc_html( $row->block_type ); ?></td>
								<td><?php echo esc_html( $row->reason ); ?></td>
								<td><?php echo esc_html( $row->username ? $row->username : '—' ); ?></td>
								<td><?php echo esc_html( $row->created_at ); ?></td>
								<td><?php echo esc_html( $row->updated_at ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( 'muse_guard_unblock_ip_' . $row->id, 'muse_guard_nonce' ); ?>
										<input type="hidden" name="action" value="muse_guard_unblock_ip">
										<input type="hidden" name="record_id" value="<?php echo esc_attr( (string) $row->id ); ?>">
										<button type="submit" class="button button-small"><?php esc_html_e( 'Unblock IP', 'musesys-login-guard' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-top:24px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Recent Failed Attempts', 'musesys-login-guard' ); ?></h2>
			<?php if ( empty( $recent_attempts ) ) : ?>
				<p><?php esc_html_e( 'No failed attempts have been logged yet.', 'musesys-login-guard' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'IP Address', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Username', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Count', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Existing User', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Known IP', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'musesys-login-guard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_attempts as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->created_at ); ?></td>
								<td><code><?php echo esc_html( $row->ip_address ); ?></code></td>
								<td><?php echo esc_html( $row->username ? $row->username : '—' ); ?></td>
								<td><?php echo esc_html( (string) $row->attempt_count ); ?></td>
								<td><?php echo $row->username_exists ? esc_html__( 'Yes', 'musesys-login-guard' ) : esc_html__( 'No', 'musesys-login-guard' ); ?></td>
								<td><?php echo $row->is_known_ip ? esc_html__( 'Yes', 'musesys-login-guard' ) : esc_html__( 'No', 'musesys-login-guard' ); ?></td>
								<td><?php echo esc_html( $row->reason ? $row->reason : 'authentication_failed' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-top:24px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Suspicious And Monitored Activity', 'musesys-login-guard' ); ?></h2>
			<?php if ( empty( $suspicious_events ) ) : ?>
				<p><?php esc_html_e( 'No suspicious or monitored activity is currently logged.', 'musesys-login-guard' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Event', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'IP Address', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Username', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'musesys-login-guard' ); ?></th>
							<th><?php esc_html_e( 'Details', 'musesys-login-guard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $suspicious_events as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->created_at ); ?></td>
								<td><?php echo esc_html( $row->event_type ); ?></td>
								<td><code><?php echo esc_html( $row->ip_address ); ?></code></td>
								<td><?php echo esc_html( $row->username ? $row->username : '—' ); ?></td>
								<td><?php echo esc_html( $row->reason ); ?></td>
								<td><code style="white-space:pre-wrap;"><?php echo esc_html( $row->metadata ? $row->metadata : '—' ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * Save admin settings.
 *
 * @return void
 */
function muse_guard_handle_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Unauthorized request.', 'musesys-login-guard' ) );
	}

	check_admin_referer( 'muse_guard_save_settings', 'muse_guard_nonce' );

	$username_blocklist_raw = isset( $_POST['muse_guard_blocklist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['muse_guard_blocklist'] ) ) : '';
	$whitelist_raw          = isset( $_POST['muse_guard_whitelist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['muse_guard_whitelist'] ) ) : '';
	$blacklist_raw          = isset( $_POST['muse_guard_blacklist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['muse_guard_blacklist'] ) ) : '';

	$username_blocklist = preg_split( '/\r\n|\r|\n/', strtolower( $username_blocklist_raw ) );
	$username_blocklist = array_filter( array_map( 'trim', is_array( $username_blocklist ) ? $username_blocklist : array() ) );
	$username_blocklist = array_values( array_unique( $username_blocklist ) );
	sort( $username_blocklist );

	$whitelist = muse_guard_normalize_ip_list( $whitelist_raw );
	$blacklist = muse_guard_normalize_ip_list( $blacklist_raw );

	update_option( MUSE_GUARD_OPTION_USERNAME_BLOCKLIST, implode( "\n", $username_blocklist ) );
	update_option( MUSE_GUARD_OPTION_WHITELIST, implode( "\n", $whitelist ) );
	update_option( MUSE_GUARD_OPTION_MANUAL_BLACKLIST, implode( "\n", $blacklist ) );

	muse_guard_sync_manual_blacklist( $blacklist );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'       => 'muse-login-guard',
				'muse_saved' => '1',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Unblock an IP from the admin page.
 *
 * @return void
 */
function muse_guard_handle_unblock_ip() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Unauthorized request.', 'musesys-login-guard' ) );
	}

	$record_id = isset( $_POST['record_id'] ) ? absint( $_POST['record_id'] ) : 0;
	check_admin_referer( 'muse_guard_unblock_ip_' . $record_id, 'muse_guard_nonce' );

	global $wpdb;

	$record = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM ' . muse_guard_blocks_table() . ' WHERE id = %d LIMIT 1',
			$record_id
		)
	);

	if ( $record ) {
		muse_guard_unblock_ip( $record->ip_address );

		if ( 'manual_blacklist' === $record->block_type ) {
			$current_blacklist = muse_guard_get_ip_list_option( MUSE_GUARD_OPTION_MANUAL_BLACKLIST );
			$current_blacklist = array_values( array_diff( $current_blacklist, array( $record->ip_address ) ) );
			update_option( MUSE_GUARD_OPTION_MANUAL_BLACKLIST, implode( "\n", $current_blacklist ) );
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'           => 'muse-login-guard',
				'muse_unblocked' => '1',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Admin notices.
 *
 * @return void
 */
function muse_guard_admin_notices() {
	if ( ! isset( $_GET['page'] ) || 'muse-login-guard' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
		return;
	}

	if ( isset( $_GET['muse_saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'MuseSys Login Guard settings saved.', 'musesys-login-guard' ) . '</p></div>';
	}

	if ( isset( $_GET['muse_unblocked'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'IP block removed.', 'musesys-login-guard' ) . '</p></div>';
	}
}
