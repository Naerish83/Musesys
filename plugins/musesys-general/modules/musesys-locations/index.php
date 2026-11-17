<?php
/**
 * MuseSys General — Locations Module
 *
 * Per-guide ship-to locations table, admin list/add/edit/deactivate UI,
 * and CSV import keyed on `code` with dry-run preview.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MuseSys_Locations' ) ) {
	final class MuseSys_Locations {

		const DB_VERSION_OPTION = 'musesys_locations_db_version';
		const DB_VERSION        = '1';

		/**
		 * Bootstrap hooks.
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 16 );
		}

		/**
		 * Return the site-scoped locations table name.
		 *
		 * @return string
		 */
		public static function table_name() {
			global $wpdb;
			return $wpdb->prefix . 'musesys_locations';
		}

		/**
		 * Create or upgrade the locations table when needed.
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
				guide_id bigint(20) unsigned NOT NULL,
				code varchar(64) NOT NULL,
				location_group varchar(191) NOT NULL DEFAULT '',
				label varchar(255) NOT NULL DEFAULT '',
				company varchar(255) NOT NULL DEFAULT '',
				address_1 varchar(255) NOT NULL DEFAULT '',
				address_2 varchar(255) NOT NULL DEFAULT '',
				city varchar(100) NOT NULL DEFAULT '',
				state varchar(100) NOT NULL DEFAULT '',
				postcode varchar(32) NOT NULL DEFAULT '',
				country char(2) NOT NULL DEFAULT 'US',
				phone varchar(64) NOT NULL DEFAULT '',
				active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY guide_code (guide_id, code),
				KEY guide_active (guide_id, active)
			) {$charset};";

			dbDelta( $sql );
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}

		/**
		 * Register Locations submenu under MuseSys.
		 */
		public static function register_menu() {
			add_submenu_page(
				'musesys',
				__( 'Locations', 'musesys-general' ),
				__( 'Locations', 'musesys-general' ),
				MuseSys_Guides::CAPABILITY,
				'musesys-locations',
				array( __CLASS__, 'render_admin_page' )
			);
		}

		/**
		 * Fetch a single location by primary key ID.
		 *
		 * @param int $location_id Location ID.
		 * @return object|null
		 */
		public static function get_location( $location_id ) {
			global $wpdb;
			$table = self::table_name();
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $location_id ) );
		}

		/**
		 * Fetch locations for a guide.
		 *
		 * @param int  $guide_id    Guide ID.
		 * @param bool $active_only Only return active locations.
		 * @param string $search    Optional search term.
		 * @return object[]
		 */
		public static function get_guide_locations( $guide_id, $active_only = true, $search = '' ) {
			global $wpdb;
			$table  = self::table_name();
			$where  = array( 'guide_id = %d' );
			$params = array( (int) $guide_id );

			if ( $active_only ) {
				$where[] = 'active = 1';
			}

			if ( $search !== '' ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where[]  = '(code LIKE %s OR label LIKE %s OR location_group LIKE %s OR city LIKE %s OR address_1 LIKE %s)';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}

			$sql  = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY location_group ASC, label ASC, code ASC';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Resolve a location ID for a user, ensuring it is active and belongs to the user's guide.
		 *
		 * @param int $location_id Location ID.
		 * @param int $user_id     User ID (defaults to current user).
		 * @return object|null
		 */
		public static function resolve_user_location( $location_id, $user_id = 0 ) {
			$guide = MuseSys_Guides::get_user_guide( $user_id );
			if ( ! $guide ) {
				return null;
			}
			$loc = self::get_location( $location_id );
			if ( ! $loc || (int) $loc->active !== 1 || (int) $loc->guide_id !== (int) $guide->id ) {
				return null;
			}
			return $loc;
		}

		/**
		 * Normalize a location payload from admin form or CSV row.
		 *
		 * @param array $raw Raw associative array.
		 * @return array
		 */
		private static function normalize_row( array $raw ) {
			return array(
				'code'           => sanitize_text_field( (string) ( $raw['code'] ?? '' ) ),
				'location_group' => sanitize_text_field( (string) ( $raw['location_group'] ?? ( $raw['group'] ?? '' ) ) ),
				'label'          => sanitize_text_field( (string) ( $raw['label'] ?? '' ) ),
				'company'        => sanitize_text_field( (string) ( $raw['company'] ?? '' ) ),
				'address_1'      => sanitize_text_field( (string) ( $raw['address_1'] ?? '' ) ),
				'address_2'      => sanitize_text_field( (string) ( $raw['address_2'] ?? '' ) ),
				'city'           => sanitize_text_field( (string) ( $raw['city'] ?? '' ) ),
				'state'          => sanitize_text_field( (string) ( $raw['state'] ?? '' ) ),
				'postcode'       => sanitize_text_field( (string) ( $raw['postcode'] ?? ( $raw['zip'] ?? '' ) ) ),
				'country'        => strtoupper( sanitize_text_field( (string) ( $raw['country'] ?? 'US' ) ) ) ?: 'US',
				'phone'          => sanitize_text_field( (string) ( $raw['phone'] ?? '' ) ),
				'active'         => isset( $raw['active'] ) ? ( (int) $raw['active'] ? 1 : 0 ) : 1,
			);
		}

		/**
		 * Analyze a CSV upload for a target guide without mutating the database.
		 *
		 * @param int    $guide_id Guide ID.
		 * @param string $tmp_path Path to uploaded CSV file.
		 * @return array Dry-run summary and normalized rows.
		 */
		private static function dry_run_csv( $guide_id, $tmp_path ) {
			global $wpdb;
			$table = self::table_name();

			$existing_rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE guide_id = %d", (int) $guide_id ),
				OBJECT_K
			);
			$by_code = array();
			if ( is_array( $existing_rows ) ) {
				foreach ( $existing_rows as $r ) {
					$by_code[ (string) $r->code ] = $r;
				}
			}

			$handle = fopen( $tmp_path, 'r' );
			if ( ! $handle ) {
				return array( 'error' => __( 'Unable to read uploaded CSV file.', 'musesys-general' ) );
			}

			$header = fgetcsv( $handle );
			if ( ! is_array( $header ) || empty( $header ) ) {
				fclose( $handle );
				return array( 'error' => __( 'CSV file is empty or missing headers.', 'musesys-general' ) );
			}

			$header = array_map(
				function ( $col ) {
					$col = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $col );
					return strtolower( trim( $col ) );
				},
				$header
			);

			$plan = array(
				'insert'    => array(),
				'update'    => array(),
				'unchanged' => array(),
				'rejected'  => array(),
			);

			$compare_keys = array( 'location_group', 'label', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'active' );
			$line_num     = 1;

			while ( ( $cells = fgetcsv( $handle ) ) !== false ) {
				$line_num++;
				if ( count( $cells ) === 1 && trim( (string) $cells[0] ) === '' ) {
					continue;
				}

				$assoc = array();
				foreach ( $header as $idx => $col_name ) {
					$assoc[ $col_name ] = $cells[ $idx ] ?? '';
				}
				$row = self::normalize_row( $assoc );

				if ( $row['code'] === '' || $row['address_1'] === '' || $row['city'] === '' || $row['state'] === '' || $row['postcode'] === '' ) {
					$plan['rejected'][] = array(
						'line'   => $line_num,
						'code'   => $row['code'],
						'reason' => __( 'Missing required field (code, address_1, city, state, or postcode).', 'musesys-general' ),
					);
					continue;
				}

				if ( ! isset( $by_code[ $row['code'] ] ) ) {
					$plan['insert'][] = $row;
					continue;
				}

				$cur     = $by_code[ $row['code'] ];
				$changed = false;
				foreach ( $compare_keys as $k ) {
					if ( (string) $cur->$k !== (string) $row[ $k ] ) {
						$changed = true;
						break;
					}
				}

				if ( $changed ) {
					$row['existing_id'] = (int) $cur->id;
					$plan['update'][]   = $row;
				} else {
					$plan['unchanged'][] = $row;
				}
			}

			fclose( $handle );
			return $plan;
		}

		/**
		 * Apply a previously previewed CSV import plan.
		 *
		 * @param int   $guide_id Guide ID.
		 * @param array $plan     Dry-run plan array.
		 * @return array Counts of inserted and updated rows.
		 */
		private static function apply_csv_plan( $guide_id, array $plan ) {
			global $wpdb;
			$table    = self::table_name();
			$now      = current_time( 'mysql' );
			$inserted = 0;
			$updated  = 0;

			foreach ( $plan['insert'] ?? array() as $row ) {
				$row['guide_id']   = (int) $guide_id;
				$row['created_at'] = $now;
				$row['updated_at'] = $now;
				if ( $wpdb->insert( $table, $row ) ) {
					$inserted++;
				}
			}

			foreach ( $plan['update'] ?? array() as $row ) {
				$existing_id = (int) ( $row['existing_id'] ?? 0 );
				unset( $row['existing_id'] );
				$row['updated_at'] = $now;
				if ( $existing_id > 0 && $wpdb->update( $table, $row, array( 'id' => $existing_id ) ) !== false ) {
					$updated++;
				}
			}

			return array(
				'inserted' => $inserted,
				'updated'  => $updated,
			);
		}

		/**
		 * Render the MuseSys -> Locations admin page.
		 */
		public static function render_admin_page() {
			if ( ! current_user_can( MuseSys_Guides::CAPABILITY ) ) {
				wp_die( esc_html__( 'Insufficient permissions', 'musesys-general' ) );
			}

			global $wpdb;
			$table  = self::table_name();
			$guides = MuseSys_Guides::get_guides( false );
			$notice = '';
			$dry_run_plan = null;

			$selected_guide = absint( $_REQUEST['guide_id'] ?? ( $guides[0]->id ?? 0 ) );
			$show_inactive  = ! empty( $_GET['show_inactive'] );
			$search         = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );

			// Handle single location save.
			if ( ! empty( $_POST['musesys_location_action'] ) && $_POST['musesys_location_action'] === 'save' ) {
				check_admin_referer( 'musesys_save_location', 'musesys_location_nonce' );
				$loc_id = absint( $_POST['location_id'] ?? 0 );
				$row    = self::normalize_row( wp_unslash( $_POST ) );
				$now    = current_time( 'mysql' );

				if ( $selected_guide <= 0 || $row['code'] === '' || $row['address_1'] === '' ) {
					$notice = '<div class="notice notice-error"><p>' . esc_html__( 'Guide, Code, and Address Line 1 are required.', 'musesys-general' ) . '</p></div>';
				} elseif ( $loc_id > 0 ) {
					$row['updated_at'] = $now;
					$wpdb->update( $table, $row, array( 'id' => $loc_id ) );
					$notice = '<div class="notice notice-success"><p>' . esc_html__( 'Location updated.', 'musesys-general' ) . '</p></div>';
				} else {
					$row['guide_id']   = $selected_guide;
					$row['created_at'] = $now;
					$row['updated_at'] = $now;
					$wpdb->insert( $table, $row );
					$notice = '<div class="notice notice-success"><p>' . esc_html__( 'Location added.', 'musesys-general' ) . '</p></div>';
				}
			}

			// Handle deactivate / reactivate (never delete).
			if ( ! empty( $_GET['toggle_active'] ) && ! empty( $_GET['_wpnonce'] ) ) {
				$toggle_id = absint( $_GET['toggle_active'] );
				if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'musesys_toggle_location_' . $toggle_id ) ) {
					$cur = self::get_location( $toggle_id );
					if ( $cur ) {
						$new_state = (int) $cur->active === 1 ? 0 : 1;
						$wpdb->update(
							$table,
							array( 'active' => $new_state, 'updated_at' => current_time( 'mysql' ) ),
							array( 'id' => $toggle_id )
						);
						$notice = '<div class="notice notice-success"><p>' . esc_html( $new_state ? __( 'Location reactivated.', 'musesys-general' ) : __( 'Location deactivated.', 'musesys-general' ) ) . '</p></div>';
					}
				}
			}

			// Handle CSV dry-run or confirm.
			$transient_key = 'musesys_csv_plan_' . get_current_user_id() . '_' . $selected_guide;
			if ( ! empty( $_POST['musesys_csv_action'] ) && $_POST['musesys_csv_action'] === 'preview' ) {
				check_admin_referer( 'musesys_csv_import', 'musesys_csv_nonce' );
				if ( ! empty( $_FILES['locations_csv']['tmp_name'] ) ) {
					$dry_run_plan = self::dry_run_csv( $selected_guide, $_FILES['locations_csv']['tmp_name'] );
					if ( isset( $dry_run_plan['error'] ) ) {
						$notice       = '<div class="notice notice-error"><p>' . esc_html( $dry_run_plan['error'] ) . '</p></div>';
						$dry_run_plan = null;
					} else {
						set_transient( $transient_key, $dry_run_plan, 15 * MINUTE_IN_SECONDS );
					}
				}
			} elseif ( ! empty( $_POST['musesys_csv_action'] ) && $_POST['musesys_csv_action'] === 'confirm' ) {
				check_admin_referer( 'musesys_csv_confirm', 'musesys_csv_confirm_nonce' );
				$saved_plan = get_transient( $transient_key );
				if ( is_array( $saved_plan ) ) {
					$result = self::apply_csv_plan( $selected_guide, $saved_plan );
					delete_transient( $transient_key );
					$notice = sprintf(
						'<div class="notice notice-success"><p>%s</p></div>',
						esc_html( sprintf( __( 'Import complete: %d inserted, %d updated.', 'musesys-general' ), $result['inserted'], $result['updated'] ) )
					);
				} else {
					$notice = '<div class="notice notice-error"><p>' . esc_html__( 'Import preview expired. Please upload the CSV again.', 'musesys-general' ) . '</p></div>';
				}
			}

			$edit_id   = absint( $_GET['edit'] ?? 0 );
			$editing   = $edit_id ? self::get_location( $edit_id ) : null;
			$locations = $selected_guide ? self::get_guide_locations( $selected_guide, ! $show_inactive, $search ) : array();
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'MuseSys Locations', 'musesys-general' ); ?></h1>
				<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<form method="get" style="margin:12px 0;">
					<input type="hidden" name="page" value="musesys-locations" />
					<label for="filter_guide_id"><strong><?php esc_html_e( 'Guide:', 'musesys-general' ); ?></strong></label>
					<select name="guide_id" id="filter_guide_id">
						<?php foreach ( $guides as $g ) : ?>
							<option value="<?php echo (int) $g->id; ?>" <?php selected( $selected_guide, (int) $g->id ); ?>><?php echo esc_html( $g->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search code, label, city…', 'musesys-general' ); ?>" />
					<label><input type="checkbox" name="show_inactive" value="1" <?php checked( $show_inactive ); ?> /> <?php esc_html_e( 'Show inactive', 'musesys-general' ); ?></label>
					<?php submit_button( __( 'Filter', 'musesys-general' ), 'secondary', '', false ); ?>
				</form>

				<?php if ( $dry_run_plan ) : ?>
					<div class="card" style="max-width:900px;padding:16px;margin-bottom:20px;">
						<h2><?php esc_html_e( 'CSV Import Dry-Run Preview', 'musesys-general' ); ?></h2>
						<p>
							<strong><?php esc_html_e( 'Insert:', 'musesys-general' ); ?></strong> <?php echo count( $dry_run_plan['insert'] ); ?> |
							<strong><?php esc_html_e( 'Update:', 'musesys-general' ); ?></strong> <?php echo count( $dry_run_plan['update'] ); ?> |
							<strong><?php esc_html_e( 'Unchanged:', 'musesys-general' ); ?></strong> <?php echo count( $dry_run_plan['unchanged'] ); ?> |
							<strong><?php esc_html_e( 'Rejected:', 'musesys-general' ); ?></strong> <?php echo count( $dry_run_plan['rejected'] ); ?>
						</p>
						<?php if ( ! empty( $dry_run_plan['rejected'] ) ) : ?>
							<ul>
								<?php foreach ( $dry_run_plan['rejected'] as $rej ) : ?>
									<li><?php echo esc_html( "Line {$rej['line']} ({$rej['code']}): {$rej['reason']}" ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<form method="post">
							<?php wp_nonce_field( 'musesys_csv_confirm', 'musesys_csv_confirm_nonce' ); ?>
							<input type="hidden" name="musesys_csv_action" value="confirm" />
							<input type="hidden" name="guide_id" value="<?php echo (int) $selected_guide; ?>" />
							<?php submit_button( __( 'Confirm Import', 'musesys-general' ), 'primary', 'submit', false ); ?>
						</form>
					</div>
				<?php endif; ?>

				<div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:24px;">
					<div class="card" style="flex:1;min-width:340px;padding:16px;">
						<h2><?php echo $editing ? esc_html__( 'Edit Location', 'musesys-general' ) : esc_html__( 'Add Location', 'musesys-general' ); ?></h2>
						<form method="post">
							<?php wp_nonce_field( 'musesys_save_location', 'musesys_location_nonce' ); ?>
							<input type="hidden" name="musesys_location_action" value="save" />
							<input type="hidden" name="location_id" value="<?php echo esc_attr( $editing->id ?? 0 ); ?>" />
							<input type="hidden" name="guide_id" value="<?php echo (int) ( $editing->guide_id ?? $selected_guide ); ?>" />
							<table class="form-table">
								<?php
								$loc_fields = array(
									'code'           => __( 'Code (PC # / Store #)*', 'musesys-general' ),
									'location_group' => __( 'Group / Franchise', 'musesys-general' ),
									'label'          => __( 'Label', 'musesys-general' ),
									'company'        => __( 'Company', 'musesys-general' ),
									'address_1'      => __( 'Address 1*', 'musesys-general' ),
									'address_2'      => __( 'Address 2', 'musesys-general' ),
									'city'           => __( 'City*', 'musesys-general' ),
									'state'          => __( 'State*', 'musesys-general' ),
									'postcode'       => __( 'Postcode*', 'musesys-general' ),
									'country'        => __( 'Country', 'musesys-general' ),
									'phone'          => __( 'Phone', 'musesys-general' ),
								);
								foreach ( $loc_fields as $k => $lbl ) :
									$val = $editing ? ( $editing->$k ?? '' ) : ( $k === 'country' ? 'US' : '' );
									?>
									<tr>
										<th><label for="loc_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $lbl ); ?></label></th>
										<td><input type="text" id="loc_<?php echo esc_attr( $k ); ?>" name="<?php echo esc_attr( $k ); ?>" class="regular-text" value="<?php echo esc_attr( $val ); ?>" /></td>
									</tr>
								<?php endforeach; ?>
							</table>
							<?php submit_button( $editing ? __( 'Update Location', 'musesys-general' ) : __( 'Add Location', 'musesys-general' ) ); ?>
						</form>
					</div>

					<div class="card" style="flex:1;min-width:300px;padding:16px;">
						<h2><?php esc_html_e( 'CSV Import (Dry-Run Preview)', 'musesys-general' ); ?></h2>
						<p><?php esc_html_e( 'Columns: code, group (or location_group), label, company, address_1, address_2, city, state, postcode, country, phone, active.', 'musesys-general' ); ?></p>
						<form method="post" enctype="multipart/form-data">
							<?php wp_nonce_field( 'musesys_csv_import', 'musesys_csv_nonce' ); ?>
							<input type="hidden" name="musesys_csv_action" value="preview" />
							<input type="hidden" name="guide_id" value="<?php echo (int) $selected_guide; ?>" />
							<p><input type="file" name="locations_csv" accept=".csv,text/csv" required /></p>
							<?php submit_button( __( 'Preview Import', 'musesys-general' ), 'secondary' ); ?>
						</form>
					</div>
				</div>

				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Code', 'musesys-general' ); ?></th>
							<th><?php esc_html_e( 'Group', 'musesys-general' ); ?></th>
							<th><?php esc_html_e( 'Label', 'musesys-general' ); ?></th>
							<th><?php esc_html_e( 'Address', 'musesys-general' ); ?></th>
							<th><?php esc_html_e( 'Status', 'musesys-general' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'musesys-general' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $locations ) ) : ?>
							<tr><td colspan="6"><?php esc_html_e( 'No locations found.', 'musesys-general' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $locations as $loc ) : ?>
								<?php
								$toggle_url = wp_nonce_url(
									admin_url( 'admin.php?page=musesys-locations&guide_id=' . (int) $selected_guide . '&toggle_active=' . (int) $loc->id ),
									'musesys_toggle_location_' . (int) $loc->id
								);
								?>
								<tr>
									<td><code><?php echo esc_html( $loc->code ); ?></code></td>
									<td><?php echo esc_html( $loc->location_group ); ?></td>
									<td><?php echo esc_html( $loc->label ); ?></td>
									<td><?php echo esc_html( trim( "{$loc->address_1}, {$loc->city}, {$loc->state} {$loc->postcode}" ) ); ?></td>
									<td><?php echo (int) $loc->active === 1 ? esc_html__( 'Active', 'musesys-general' ) : esc_html__( 'Inactive', 'musesys-general' ); ?></td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=musesys-locations&guide_id=' . (int) $selected_guide . '&edit=' . (int) $loc->id ) ); ?>"><?php esc_html_e( 'Edit', 'musesys-general' ); ?></a>
										<a class="button button-small" href="<?php echo esc_url( $toggle_url ); ?>"><?php echo (int) $loc->active === 1 ? esc_html__( 'Deactivate', 'musesys-general' ) : esc_html__( 'Reactivate', 'musesys-general' ); ?></a>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php
		}
	}

	MuseSys_Locations::init();
}
