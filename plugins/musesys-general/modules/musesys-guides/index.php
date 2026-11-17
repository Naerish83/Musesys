<?php
/**
 * MuseSys General — Guides Module
 *
 * Manages per-site Order Guides, billing profiles, payment terms, label formats,
 * feature toggles (Prop 65, hide related products), and site-scoped user assignments.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MuseSys_Guides' ) ) {
	final class MuseSys_Guides {

		const DB_VERSION_OPTION = 'musesys_guides_db_version';
		const DB_VERSION        = '1';
		const CAPABILITY        = 'musesys_manage_locations';

		/**
		 * Bootstrap hooks.
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
			add_action( 'init', array( __CLASS__, 'ensure_capabilities' ), 6 );
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 15 );
			add_action( 'admin_notices', array( __CLASS__, 'billing_profile_admin_notice' ) );
			add_action( 'show_user_profile', array( __CLASS__, 'render_user_guide_field' ) );
			add_action( 'edit_user_profile', array( __CLASS__, 'render_user_guide_field' ) );
			add_action( 'personal_options_update', array( __CLASS__, 'save_user_guide_field' ) );
			add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_guide_field' ) );
		}

		/**
		 * Return the site-scoped guides table name.
		 *
		 * @return string
		 */
		public static function table_name() {
			global $wpdb;
			return $wpdb->prefix . 'musesys_guides';
		}

		/**
		 * Return the site-scoped user meta key for guide assignment.
		 *
		 * @return string
		 */
		public static function user_meta_key() {
			global $wpdb;
			return $wpdb->prefix . 'musesys_guide';
		}

		/**
		 * Create or upgrade the guides table when needed.
		 */
		public static function maybe_install() {
			if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
				return;
			}

			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$table   = self::table_name();
			$charset = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(64) NOT NULL,
				name varchar(191) NOT NULL,
				billing_profile longtext NOT NULL,
				payment_terms text NOT NULL,
				label_format varchar(255) NOT NULL DEFAULT '{label} ({code})',
				shipping_speeds text NOT NULL,
				report_recipients text NOT NULL,
				prop65_enabled tinyint(1) NOT NULL DEFAULT 0,
				hide_related_products tinyint(1) NOT NULL DEFAULT 0,
				active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug)
			) {$charset};";

			dbDelta( $sql );
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}

		/**
		 * Grant musesys_manage_locations to administrator and shop_manager.
		 */
		public static function ensure_capabilities() {
			foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
				$role = get_role( $role_name );
				if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
					$role->add_cap( self::CAPABILITY );
				}
			}
		}

		/**
		 * Register Guides submenu under MuseSys.
		 */
		public static function register_menu() {
			add_submenu_page(
				'musesys',
				__( 'Guides', 'musesys-general' ),
				__( 'Guides', 'musesys-general' ),
				self::CAPABILITY,
				'musesys-guides',
				array( __CLASS__, 'render_admin_page' )
			);
		}

		/**
		 * Decode a guide row's JSON fields into structured arrays.
		 *
		 * @param object|null $row Raw DB row.
		 * @return object|null
		 */
		private static function hydrate_guide( $row ) {
			if ( ! $row ) {
				return null;
			}
			$billing = json_decode( (string) $row->billing_profile, true );
			$row->billing = is_array( $billing ) ? $billing : array();

			$speeds = json_decode( (string) $row->shipping_speeds, true );
			$row->speeds = is_array( $speeds ) ? $speeds : array();

			return $row;
		}

		/**
		 * Fetch a single guide by ID.
		 *
		 * @param int $guide_id Guide ID.
		 * @return object|null
		 */
		public static function get_guide( $guide_id ) {
			global $wpdb;
			$table = self::table_name();
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $guide_id ) );
			return self::hydrate_guide( $row );
		}

		/**
		 * Fetch all guides for the current site.
		 *
		 * @param bool $active_only Only return active guides.
		 * @return object[]
		 */
		public static function get_guides( $active_only = false ) {
			global $wpdb;
			$table = self::table_name();
			$where = $active_only ? 'WHERE active = 1' : '';
			$rows  = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY name ASC" );
			return array_map( array( __CLASS__, 'hydrate_guide' ), is_array( $rows ) ? $rows : array() );
		}

		/**
		 * Get the assigned guide ID for a user on the current site.
		 *
		 * @param int $user_id User ID (defaults to current user).
		 * @return int
		 */
		public static function get_user_guide_id( $user_id = 0 ) {
			$uid = $user_id ? (int) $user_id : get_current_user_id();
			if ( $uid <= 0 ) {
				return 0;
			}
			return (int) get_user_meta( $uid, self::user_meta_key(), true );
		}

		/**
		 * Get the assigned active guide object for a user.
		 *
		 * @param int $user_id User ID (defaults to current user).
		 * @return object|null
		 */
		public static function get_user_guide( $user_id = 0 ) {
			$guide_id = self::get_user_guide_id( $user_id );
			if ( $guide_id <= 0 ) {
				return null;
			}
			$guide = self::get_guide( $guide_id );
			return ( $guide && (int) $guide->active === 1 ) ? $guide : null;
		}

		/**
		 * Check whether a guide has a configured billing profile (company or address_1).
		 *
		 * @param object|null $guide Guide object.
		 * @return bool
		 */
		public static function is_billing_profile_configured( $guide ) {
			if ( ! $guide || empty( $guide->billing ) || ! is_array( $guide->billing ) ) {
				return false;
			}
			$company   = trim( (string) ( $guide->billing['company'] ?? '' ) );
			$address_1 = trim( (string) ( $guide->billing['address_1'] ?? '' ) );
			return ( $company !== '' || $address_1 !== '' );
		}

		/**
		 * Format a location label using the guide's label_format template.
		 *
		 * @param object      $location Location row.
		 * @param object|null $guide    Guide object.
		 * @return string
		 */
		public static function format_location_label( $location, $guide = null ) {
			$template = ( $guide && ! empty( $guide->label_format ) )
				? (string) $guide->label_format
				: '{label} ({code})';

			$replacements = array(
				'{code}'    => (string) ( $location->code ?? '' ),
				'{group}'   => (string) ( $location->location_group ?? '' ),
				'{label}'   => (string) ( $location->label ?? '' ),
				'{company}' => (string) ( $location->company ?? '' ),
				'{city}'    => (string) ( $location->city ?? '' ),
				'{state}'   => (string) ( $location->state ?? '' ),
			);

			$formatted = strtr( $template, $replacements );
			$formatted = preg_replace( '/\s{2,}/', ' ', trim( $formatted ) );
			return trim( $formatted, " \t\n\r\0\x0B–-" );
		}

		/**
		 * Warn admins when an active guide is missing a billing profile.
		 */
		public static function billing_profile_admin_notice() {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				return;
			}
			$guides = self::get_guides( true );
			$missing = array();
			foreach ( $guides as $g ) {
				if ( ! self::is_billing_profile_configured( $g ) ) {
					$missing[] = $g->name;
				}
			}
			if ( empty( $missing ) ) {
				return;
			}
			$url = admin_url( 'admin.php?page=musesys-guides' );
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'MuseSys Billing Profile Not Configured:', 'musesys-general' ),
				esc_html( implode( ', ', $missing ) ),
				esc_url( $url ),
				esc_html__( 'Configure in MuseSys → Guides', 'musesys-general' )
			);
		}

		/**
		 * Render guide assignment field on WP user profile edit screen.
		 *
		 * @param WP_User $user User being edited.
		 */
		public static function render_user_guide_field( $user ) {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				return;
			}
			$guides     = self::get_guides( false );
			$current_id = self::get_user_guide_id( $user->ID );
			?>
			<h3><?php esc_html_e( 'MuseSys Order Guide', 'musesys-general' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><label for="musesys_guide_id"><?php esc_html_e( 'Assigned Guide', 'musesys-general' ); ?></label></th>
					<td>
						<select name="musesys_guide_id" id="musesys_guide_id">
							<option value="0"><?php esc_html_e( '— None —', 'musesys-general' ); ?></option>
							<?php foreach ( $guides as $g ) : ?>
								<option value="<?php echo esc_attr( $g->id ); ?>" <?php selected( $current_id, (int) $g->id ); ?>>
									<?php echo esc_html( $g->name . ( (int) $g->active === 1 ? '' : ' (Inactive)' ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Site-scoped guide assignment for checkout and locations.', 'musesys-general' ); ?></p>
					</td>
				</tr>
			</table>
			<?php
		}

		/**
		 * Save site-scoped user guide assignment.
		 *
		 * @param int $user_id User ID.
		 */
		public static function save_user_guide_field( $user_id ) {
			if ( ! current_user_can( self::CAPABILITY ) || ! current_user_can( 'edit_user', $user_id ) ) {
				return;
			}
			if ( ! isset( $_POST['musesys_guide_id'] ) ) {
				return;
			}
			$guide_id = absint( $_POST['musesys_guide_id'] );
			if ( $guide_id > 0 ) {
				update_user_meta( $user_id, self::user_meta_key(), $guide_id );
			} else {
				delete_user_meta( $user_id, self::user_meta_key() );
			}
		}

		/**
		 * Handle create/update POST submissions on the Guides admin page.
		 *
		 * @return string Status message HTML (if any).
		 */
		private static function handle_admin_post() {
			if ( empty( $_POST['musesys_guide_action'] ) ) {
				return '';
			}
			check_admin_referer( 'musesys_save_guide', 'musesys_guide_nonce' );

			global $wpdb;
			$table    = self::table_name();
			$now      = current_time( 'mysql' );
			$guide_id = absint( $_POST['guide_id'] ?? 0 );
			$name     = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
			$slug     = sanitize_title( wp_unslash( $_POST['slug'] ?? $name ) );

			if ( $name === '' || $slug === '' ) {
				return '<div class="notice notice-error"><p>' . esc_html__( 'Guide name and slug are required.', 'musesys-general' ) . '</p></div>';
			}

			$billing = array(
				'first_name' => sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ?? '' ) ),
				'last_name'  => sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ?? '' ) ),
				'company'    => sanitize_text_field( wp_unslash( $_POST['billing_company'] ?? '' ) ),
				'address_1'  => sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ?? '' ) ),
				'address_2'  => sanitize_text_field( wp_unslash( $_POST['billing_address_2'] ?? '' ) ),
				'city'       => sanitize_text_field( wp_unslash( $_POST['billing_city'] ?? '' ) ),
				'state'      => sanitize_text_field( wp_unslash( $_POST['billing_state'] ?? '' ) ),
				'postcode'   => sanitize_text_field( wp_unslash( $_POST['billing_postcode'] ?? '' ) ),
				'country'    => strtoupper( sanitize_text_field( wp_unslash( $_POST['billing_country'] ?? 'US' ) ) ),
				'email'      => sanitize_email( wp_unslash( $_POST['billing_email'] ?? '' ) ),
				'phone'      => sanitize_text_field( wp_unslash( $_POST['billing_phone'] ?? '' ) ),
			);

			$raw_speeds = explode( "\n", str_replace( "\r", '', (string) wp_unslash( $_POST['shipping_speeds'] ?? '' ) ) );
			$speeds     = array_values( array_filter( array_map( 'trim', array_map( 'sanitize_text_field', $raw_speeds ) ) ) );

			$data = array(
				'slug'                  => $slug,
				'name'                  => $name,
				'billing_profile'       => wp_json_encode( $billing ),
				'payment_terms'         => sanitize_textarea_field( wp_unslash( $_POST['payment_terms'] ?? '' ) ),
				'label_format'          => sanitize_text_field( wp_unslash( $_POST['label_format'] ?? '{label} ({code})' ) ),
				'shipping_speeds'       => wp_json_encode( $speeds ),
				'report_recipients'     => sanitize_textarea_field( wp_unslash( $_POST['report_recipients'] ?? '' ) ),
				'prop65_enabled'        => ! empty( $_POST['prop65_enabled'] ) ? 1 : 0,
				'hide_related_products' => ! empty( $_POST['hide_related_products'] ) ? 1 : 0,
				'active'                => ! empty( $_POST['active'] ) ? 1 : 0,
				'updated_at'            => $now,
			);

			if ( $guide_id > 0 ) {
				$wpdb->update( $table, $data, array( 'id' => $guide_id ) );
				return '<div class="notice notice-success"><p>' . esc_html__( 'Guide updated.', 'musesys-general' ) . '</p></div>';
			}

			$data['created_at'] = $now;
			$wpdb->insert( $table, $data );
			return '<div class="notice notice-success"><p>' . esc_html__( 'Guide created.', 'musesys-general' ) . '</p></div>';
		}

		/**
		 * Render the MuseSys -> Guides admin page.
		 */
		public static function render_admin_page() {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'Insufficient permissions', 'musesys-general' ) );
			}

			$notice   = self::handle_admin_post();
			$edit_id  = absint( $_GET['edit'] ?? 0 );
			$editing  = $edit_id ? self::get_guide( $edit_id ) : null;
			$guides   = self::get_guides( false );
			$billing  = $editing ? $editing->billing : array();
			$speeds   = $editing ? implode( "\n", $editing->speeds ) : '';
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'MuseSys Order Guides', 'musesys-general' ); ?></h1>
				<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<h2><?php echo $editing ? esc_html__( 'Edit Guide', 'musesys-general' ) : esc_html__( 'Add New Guide', 'musesys-general' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'musesys_save_guide', 'musesys_guide_nonce' ); ?>
					<input type="hidden" name="musesys_guide_action" value="save" />
					<input type="hidden" name="guide_id" value="<?php echo esc_attr( $editing->id ?? 0 ); ?>" />
					<table class="form-table">
						<tr>
							<th><label for="guide_name"><?php esc_html_e( 'Guide Name', 'musesys-general' ); ?></label></th>
							<td><input type="text" id="guide_name" name="name" class="regular-text" required value="<?php echo esc_attr( $editing->name ?? '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="guide_slug"><?php esc_html_e( 'Slug', 'musesys-general' ); ?></label></th>
							<td><input type="text" id="guide_slug" name="slug" class="regular-text" value="<?php echo esc_attr( $editing->slug ?? '' ); ?>" placeholder="dunkin" /></td>
						</tr>
						<tr>
							<th><label for="label_format"><?php esc_html_e( 'Location Label Format', 'musesys-general' ); ?></label></th>
							<td>
								<input type="text" id="label_format" name="label_format" class="regular-text" value="<?php echo esc_attr( $editing->label_format ?? '{group} – {label} (PC {code})' ); ?>" />
								<p class="description"><?php esc_html_e( 'Tokens: {code}, {group}, {label}, {company}, {city}, {state}', 'musesys-general' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="payment_terms"><?php esc_html_e( 'Payment Terms', 'musesys-general' ); ?></label></th>
							<td><textarea id="payment_terms" name="payment_terms" rows="3" class="large-text"><?php echo esc_textarea( $editing->payment_terms ?? '' ); ?></textarea></td>
						</tr>
						<tr>
							<th><label for="shipping_speeds"><?php esc_html_e( 'Shipping Speed Options', 'musesys-general' ); ?></label></th>
							<td>
								<textarea id="shipping_speeds" name="shipping_speeds" rows="4" class="regular-text"><?php echo esc_textarea( $speeds ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One option per line. Leave blank to hide shipping speed selector at checkout.', 'musesys-general' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="report_recipients"><?php esc_html_e( 'Report Recipients', 'musesys-general' ); ?></label></th>
							<td><textarea id="report_recipients" name="report_recipients" rows="2" class="large-text" placeholder="ops@example.com, accounting@example.com"><?php echo esc_textarea( $editing->report_recipients ?? '' ); ?></textarea></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Feature Toggles', 'musesys-general' ); ?></th>
							<td>
								<label><input type="checkbox" name="prop65_enabled" value="1" <?php checked( (int) ( $editing->prop65_enabled ?? 0 ), 1 ); ?> /> <?php esc_html_e( 'Enable Prop 65 Notices', 'musesys-general' ); ?></label><br />
								<label><input type="checkbox" name="hide_related_products" value="1" <?php checked( (int) ( $editing->hide_related_products ?? 0 ), 1 ); ?> /> <?php esc_html_e( 'Hide WooCommerce Related Products', 'musesys-general' ); ?></label><br />
								<label><input type="checkbox" name="active" value="1" <?php checked( (int) ( $editing->active ?? 1 ), 1 ); ?> /> <?php esc_html_e( 'Active', 'musesys-general' ); ?></label>
							</td>
						</tr>
						<tr>
							<th colspan="2"><h3><?php esc_html_e( 'Billing Profile (Locked on Checkout)', 'musesys-general' ); ?></h3></th>
						</tr>
						<?php
						$billing_fields = array(
							'billing_company'    => __( 'Company', 'musesys-general' ),
							'billing_first_name' => __( 'First Name', 'musesys-general' ),
							'billing_last_name'  => __( 'Last Name', 'musesys-general' ),
							'billing_address_1'  => __( 'Address Line 1', 'musesys-general' ),
							'billing_address_2'  => __( 'Address Line 2', 'musesys-general' ),
							'billing_city'       => __( 'City', 'musesys-general' ),
							'billing_state'      => __( 'State', 'musesys-general' ),
							'billing_postcode'   => __( 'Postcode', 'musesys-general' ),
							'billing_country'    => __( 'Country (2-letter)', 'musesys-general' ),
							'billing_email'      => __( 'Billing Email', 'musesys-general' ),
							'billing_phone'      => __( 'Billing Phone', 'musesys-general' ),
						);
						foreach ( $billing_fields as $field_key => $field_label ) :
							$short_key = str_replace( 'billing_', '', $field_key );
							$val       = $billing[ $short_key ] ?? ( $short_key === 'country' ? 'US' : '' );
							?>
							<tr>
								<th><label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $field_label ); ?></label></th>
								<td><input type="text" id="<?php echo esc_attr( $field_key ); ?>" name="<?php echo esc_attr( $field_key ); ?>" class="regular-text" value="<?php echo esc_attr( $val ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
					</table>
					<?php submit_button( $editing ? __( 'Update Guide', 'musesys-general' ) : __( 'Create Guide', 'musesys-general' ) ); ?>
				</form>

				<hr />
				<h2><?php esc_html_e( 'Configured Guides', 'musesys-general' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'musesys-general' ); ?></th>
							<th><?php esc_html_e( 'Name', 'musesys-general' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'musesys-general' ); ?></th>
							<th><?php esc_html_e( 'Billing Profile', 'musesys-general' ); ?></th>
							<th><?php esc_html_e( 'Prop 65', 'musesys-general' ); ?></th>
							<th><?php esc_html_e( 'Status', 'musesys-general' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'musesys-general' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $guides ) ) : ?>
							<tr><td colspan="7"><?php esc_html_e( 'No guides configured yet.', 'musesys-general' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $guides as $g ) : ?>
								<tr>
									<td><?php echo (int) $g->id; ?></td>
									<td><strong><?php echo esc_html( $g->name ); ?></strong></td>
									<td><code><?php echo esc_html( $g->slug ); ?></code></td>
									<td><?php echo self::is_billing_profile_configured( $g ) ? esc_html__( 'Configured', 'musesys-general' ) : '<span style="color:#b32d2e;">' . esc_html__( 'Not configured', 'musesys-general' ) . '</span>'; ?></td>
									<td><?php echo (int) $g->prop65_enabled === 1 ? esc_html__( 'On', 'musesys-general' ) : esc_html__( 'Off', 'musesys-general' ); ?></td>
									<td><?php echo (int) $g->active === 1 ? esc_html__( 'Active', 'musesys-general' ) : esc_html__( 'Inactive', 'musesys-general' ); ?></td>
									<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=musesys-guides&edit=' . (int) $g->id ) ); ?>"><?php esc_html_e( 'Edit', 'musesys-general' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php
		}
	}

	MuseSys_Guides::init();
}
