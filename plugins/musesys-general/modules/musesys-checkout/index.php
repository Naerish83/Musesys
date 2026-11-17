<?php
/**
 * MuseSys General — Checkout & Order Snapshot Module
 *
 * Enforces classic WooCommerce checkout with a locked, server-resolved ship-to
 * location picker, per-guide billing profile, payment terms display, and
 * immutable order-level address/guide snapshots.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MuseSys_Checkout' ) ) {
	final class MuseSys_Checkout {

		/**
		 * Bootstrap hooks.
		 */
		public static function init() {
			add_action( 'admin_notices', array( __CLASS__, 'block_checkout_admin_notice' ) );
			add_action( 'template_redirect', array( __CLASS__, 'gate_checkout_access' ) );

			add_action( 'woocommerce_checkout_before_customer_details', array( __CLASS__, 'render_checkout_header' ) );
			add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'configure_checkout_fields' ), 20 );
			add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_checkout_submission' ) );
			add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'enforce_posted_data' ), 20 );
			add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'sync_customer_shipping' ), 15 );
			add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_order_snapshot' ), 10, 2 );

			add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'render_email_snapshot' ), 10, 4 );
			add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'render_admin_snapshot' ) );
			add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_account_snapshot' ) );

			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout_script' ) );
		}

		/**
		 * Site-scoped user meta key for last-used location ID.
		 *
		 * @return string
		 */
		public static function last_location_meta_key() {
			global $wpdb;
			return $wpdb->prefix . 'musesys_last_location';
		}

		/**
		 * Warn admins if the WooCommerce Checkout page uses Block Checkout.
		 */
		public static function block_checkout_admin_notice() {
			if ( ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'wc_get_page_id' ) ) {
				return;
			}
			$checkout_id = wc_get_page_id( 'checkout' );
			if ( $checkout_id <= 0 ) {
				return;
			}
			$post = get_post( $checkout_id );
			if ( ! $post || strpos( (string) $post->post_content, 'wp:woocommerce/checkout' ) === false ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'MuseSys Checkout Warning:', 'musesys-general' ),
				esc_html__( 'The WooCommerce Checkout page contains the Block Checkout. MuseSys General requires the Classic Checkout shortcode ([woocommerce_checkout]).', 'musesys-general' ),
				esc_url( get_edit_post_link( $checkout_id ) ),
				esc_html__( 'Edit Checkout Page', 'musesys-general' )
			);
		}

		/**
		 * Block checkout for users without site membership, guide, or active locations.
		 */
		public static function gate_checkout_access() {
			if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
				return;
			}
			if ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) || ! empty( $_GET['key'] ) ) {
				return;
			}

			$user_id = get_current_user_id();
			if ( $user_id <= 0 ) {
				self::render_blocked_page( __( 'Please log in to place an order.', 'musesys-general' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id ) ) {
				self::render_blocked_page( __( 'Your account is not enabled for this ordering portal. Please contact us for access.', 'musesys-general' ) );
			}

			$guide = MuseSys_Guides::get_user_guide( $user_id );
			if ( ! $guide ) {
				self::render_blocked_page( __( 'No order guide is assigned to your account. Please contact us to complete setup.', 'musesys-general' ) );
			}

			$locations = MuseSys_Locations::get_guide_locations( $guide->id, true );
			if ( empty( $locations ) ) {
				self::render_blocked_page( __( 'No active ship-to locations are available for your order guide. Please contact us.', 'musesys-general' ) );
			}
		}

		/**
		 * Display a clean blocked-checkout message using wp_die.
		 *
		 * @param string $message User-facing message.
		 */
		private static function render_blocked_page( $message ) {
			wp_die(
				'<p>' . esc_html( $message ) . '</p><p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Return to Home', 'musesys-general' ) . '</a></p>',
				esc_html__( 'Checkout Unavailable', 'musesys-general' ),
				array( 'response' => 403 )
			);
		}

		/**
		 * Resolve the active location for the current user from POST, WC session, last-used, or first available.
		 *
		 * @return object|null
		 */
		public static function get_current_selected_location() {
			$user_id = get_current_user_id();
			$guide   = MuseSys_Guides::get_user_guide( $user_id );
			if ( ! $guide ) {
				return null;
			}

			$candidates = array();
			if ( ! empty( $_POST['musesys_location_id'] ) ) {
				$candidates[] = absint( $_POST['musesys_location_id'] );
			}
			if ( ! empty( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
				parse_str( wp_unslash( $_POST['post_data'] ), $parsed );
				if ( ! empty( $parsed['musesys_location_id'] ) ) {
					$candidates[] = absint( $parsed['musesys_location_id'] );
				}
			}
			if ( function_exists( 'WC' ) && WC()->session ) {
				$sess_id = absint( WC()->session->get( 'musesys_location_id' ) );
				if ( $sess_id > 0 ) {
					$candidates[] = $sess_id;
				}
			}

			$last_id = absint( get_user_meta( $user_id, self::last_location_meta_key(), true ) );
			if ( $last_id > 0 ) {
				$candidates[] = $last_id;
			}

			foreach ( $candidates as $loc_id ) {
				$loc = MuseSys_Locations::resolve_user_location( $loc_id, $user_id );
				if ( $loc ) {
					return $loc;
				}
			}

			$all = MuseSys_Locations::get_guide_locations( $guide->id, true );
			return ! empty( $all ) ? $all[0] : null;
		}

		/**
		 * Render payment terms and the ship-to location selector at the top of checkout.
		 */
		public static function render_checkout_header() {
			$user_id   = get_current_user_id();
			$guide     = MuseSys_Guides::get_user_guide( $user_id );
			if ( ! $guide ) {
				return;
			}

			$locations = MuseSys_Locations::get_guide_locations( $guide->id, true );
			$selected  = self::get_current_selected_location();
			$sel_id    = $selected ? (int) $selected->id : 0;

			if ( trim( (string) $guide->payment_terms ) !== '' ) {
				echo '<div class="musesys-payment-terms" style="padding:12px 16px;border-left:4px solid #2271b1;background:#f0f6fc;margin-bottom:16px;">';
				echo '<strong>' . esc_html__( 'Payment Terms:', 'musesys-general' ) . '</strong> ';
				echo esc_html( $guide->payment_terms );
				echo '</div>';
			}

			echo '<div class="musesys-location-picker" style="padding:14px 16px;border:1px solid #ddd;background:#fafafa;margin-bottom:18px;">';
			echo '<label for="musesys_location_id" style="display:block;font-weight:600;margin-bottom:6px;">' . esc_html__( 'Select Ship-To Location', 'musesys-general' ) . ' <span class="required">*</span></label>';
			echo '<select name="musesys_location_id" id="musesys_location_id" class="select" style="width:100%;max-width:560px;">';
			foreach ( $locations as $loc ) {
				$label = MuseSys_Guides::format_location_label( $loc, $guide );
				$addr  = wp_json_encode(
					array(
						'company'   => $loc->company !== '' ? $loc->company : $loc->label,
						'address_1' => $loc->address_1,
						'address_2' => $loc->address_2,
						'city'      => $loc->city,
						'state'     => $loc->state,
						'postcode'  => $loc->postcode,
						'country'   => $loc->country ?: 'US',
						'phone'     => $loc->phone,
					)
				);
				printf(
					'<option value="%d" data-address="%s"%s>%s</option>',
					(int) $loc->id,
					esc_attr( $addr ),
					selected( $sel_id, (int) $loc->id, false ),
					esc_html( $label )
				);
			}
			echo '</select>';

			if ( $selected ) {
				echo '<div id="musesys-selected-address-preview" style="margin-top:10px;font-size:0.95em;color:#333;">';
				echo esc_html( $selected->address_1 );
				if ( $selected->address_2 !== '' ) {
					echo ', ' . esc_html( $selected->address_2 );
				}
				echo '<br>' . esc_html( trim( "{$selected->city}, {$selected->state} {$selected->postcode}" ) );
				echo '</div>';
			}
			echo '</div>';
		}

		/**
		 * Populate and lock shipping fields; optionally add guide shipping speed selector.
		 *
		 * @param array $fields WooCommerce checkout fields.
		 * @return array
		 */
		public static function configure_checkout_fields( $fields ) {
			$guide = MuseSys_Guides::get_user_guide();
			$loc   = self::get_current_selected_location();

			if ( $loc ) {
				$map = array(
					'shipping_first_name' => $loc->code,
					'shipping_last_name'  => $loc->label,
					'shipping_company'    => $loc->company !== '' ? $loc->company : $loc->label,
					'shipping_address_1'  => $loc->address_1,
					'shipping_address_2'  => $loc->address_2,
					'shipping_city'       => $loc->city,
					'shipping_state'      => $loc->state,
					'shipping_postcode'   => $loc->postcode,
					'shipping_country'    => $loc->country ?: 'US',
					'shipping_phone'      => $loc->phone,
				);

				foreach ( $map as $key => $val ) {
					if ( isset( $fields['shipping'][ $key ] ) ) {
						$fields['shipping'][ $key ]['default']                        = $val;
						$fields['shipping'][ $key ]['custom_attributes']['readonly'] = 'readonly';
					}
				}
			}

			if ( $guide && ! empty( $guide->speeds ) ) {
				$options = array( '' => __( 'Choose shipping speed…', 'musesys-general' ) );
				foreach ( $guide->speeds as $speed ) {
					$options[ $speed ] = $speed;
				}
				$fields['order']['musesys_shipping_speed'] = array(
					'type'     => 'select',
					'label'    => __( 'Shipping Speed', 'musesys-general' ),
					'required' => true,
					'options'  => $options,
					'priority' => 10,
				);
			}

			return $fields;
		}

		/**
		 * Validate submitted location ID (active + belongs to user's guide) and shipping speed.
		 */
		public static function validate_checkout_submission() {
			$loc_id = absint( $_POST['musesys_location_id'] ?? 0 );
			$loc    = MuseSys_Locations::resolve_user_location( $loc_id );

			if ( ! $loc ) {
				wc_add_notice( __( 'The selected ship-to location is invalid or inactive for your order guide.', 'musesys-general' ), 'error' );
				return;
			}

			$guide = MuseSys_Guides::get_user_guide();
			if ( $guide && ! empty( $guide->speeds ) && empty( $_POST['musesys_shipping_speed'] ) ) {
				wc_add_notice( __( 'Please choose a Shipping Speed.', 'musesys-general' ), 'error' );
			}
		}

		/**
		 * Overwrite posted shipping (and configured billing) data from server-side records.
		 *
		 * @param array $data Posted checkout data.
		 * @return array
		 */
		public static function enforce_posted_data( $data ) {
			$loc_id = absint( $_POST['musesys_location_id'] ?? 0 );
			$loc    = MuseSys_Locations::resolve_user_location( $loc_id );

			if ( $loc ) {
				$data['ship_to_different_address'] = 1;
				$data['shipping_first_name']       = $loc->code;
				$data['shipping_last_name']        = $loc->label;
				$data['shipping_company']          = $loc->company !== '' ? $loc->company : $loc->label;
				$data['shipping_address_1']        = $loc->address_1;
				$data['shipping_address_2']        = $loc->address_2;
				$data['shipping_city']             = $loc->city;
				$data['shipping_state']            = $loc->state;
				$data['shipping_postcode']         = $loc->postcode;
				$data['shipping_country']          = $loc->country ?: 'US';
				if ( $loc->phone !== '' ) {
					$data['shipping_phone'] = $loc->phone;
				}
			}

			$guide = MuseSys_Guides::get_user_guide();
			if ( $guide && MuseSys_Guides::is_billing_profile_configured( $guide ) ) {
				$b = $guide->billing;
				foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ) as $k ) {
					if ( isset( $b[ $k ] ) && $b[ $k ] !== '' ) {
						$data[ 'billing_' . $k ] = $b[ $k ];
					}
				}
			}

			return $data;
		}

		/**
		 * Sync WC()->customer shipping address before totals/shipping/tax calculation.
		 */
		public static function sync_customer_shipping() {
			if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
				return;
			}
			$loc = self::get_current_selected_location();
			if ( ! $loc ) {
				return;
			}
			if ( WC()->session ) {
				WC()->session->set( 'musesys_location_id', (int) $loc->id );
			}
			$c = WC()->customer;
			$c->set_shipping_first_name( $loc->code );
			$c->set_shipping_last_name( $loc->label );
			$c->set_shipping_company( $loc->company !== '' ? $loc->company : $loc->label );
			$c->set_shipping_address_1( $loc->address_1 );
			$c->set_shipping_address_2( $loc->address_2 );
			$c->set_shipping_city( $loc->city );
			$c->set_shipping_state( $loc->state );
			$c->set_shipping_postcode( $loc->postcode );
			$c->set_shipping_country( $loc->country ?: 'US' );
		}

		/**
		 * Persist immutable location, guide, payment terms, and address snapshot on order creation.
		 *
		 * @param WC_Order $order WooCommerce order.
		 * @param array    $data  Checkout data.
		 */
		public static function save_order_snapshot( $order, $data ) {
			$user_id = get_current_user_id();
			$guide   = MuseSys_Guides::get_user_guide( $user_id );
			$loc_id  = absint( $_POST['musesys_location_id'] ?? 0 );
			$loc     = MuseSys_Locations::resolve_user_location( $loc_id, $user_id );

			if ( $guide ) {
				$order->update_meta_data( '_musesys_guide_id', (int) $guide->id );
				$order->update_meta_data( '_musesys_guide_name', (string) $guide->name );
				if ( trim( (string) $guide->payment_terms ) !== '' ) {
					$order->update_meta_data( '_musesys_payment_terms', (string) $guide->payment_terms );
				}
			}

			if ( $loc ) {
				update_user_meta( $user_id, self::last_location_meta_key(), (int) $loc->id );

				$order->update_meta_data( '_musesys_location_id', (int) $loc->id );
				$order->update_meta_data( '_musesys_location_code', (string) $loc->code );
				$order->update_meta_data( '_musesys_location_group', (string) $loc->location_group );
				$order->update_meta_data( '_musesys_location_label', (string) $loc->label );
				$order->update_meta_data( '_musesys_address_1', (string) $loc->address_1 );
				$order->update_meta_data( '_musesys_city', (string) $loc->city );
				$order->update_meta_data( '_musesys_state', (string) $loc->state );
				$order->update_meta_data( '_musesys_zip', (string) $loc->postcode );
				$order->update_meta_data( '_musesys_phone', (string) $loc->phone );
			}

			if ( ! empty( $_POST['musesys_shipping_speed'] ) ) {
				$order->update_meta_data( '_musesys_shipping_speed', sanitize_text_field( wp_unslash( $_POST['musesys_shipping_speed'] ) ) );
			}
		}

		/**
		 * Build snapshot display rows for an order, including the order-reference extension seam.
		 *
		 * @param WC_Order $order WooCommerce order.
		 * @return array<string,string>
		 */
		private static function get_snapshot_rows( $order ) {
			$rows = array(
				__( 'Guide', 'musesys-general' )          => (string) $order->get_meta( '_musesys_guide_name' ),
				__( 'Location Code', 'musesys-general' )  => (string) $order->get_meta( '_musesys_location_code' ),
				__( 'Group', 'musesys-general' )          => (string) $order->get_meta( '_musesys_location_group' ),
				__( 'Location', 'musesys-general' )       => (string) $order->get_meta( '_musesys_location_label' ),
				__( 'Address', 'musesys-general' )        => (string) $order->get_meta( '_musesys_address_1' ),
				__( 'City/State/Zip', 'musesys-general' ) => trim( $order->get_meta( '_musesys_city' ) . ' ' . $order->get_meta( '_musesys_state' ) . ' ' . $order->get_meta( '_musesys_zip' ) ),
				__( 'Phone', 'musesys-general' )          => (string) $order->get_meta( '_musesys_phone' ),
				__( 'Shipping Speed', 'musesys-general' ) => (string) $order->get_meta( '_musesys_shipping_speed' ),
				__( 'Payment Terms', 'musesys-general' )  => (string) $order->get_meta( '_musesys_payment_terms' ),
			);

			$ref_val   = (string) apply_filters( 'musesys_order_reference', '', $order );
			$ref_label = (string) apply_filters( 'musesys_order_reference_label', __( 'Order Reference', 'musesys-general' ), $order );
			if ( $ref_val !== '' ) {
				$rows[ $ref_label ] = $ref_val;
			}

			return array_filter(
				$rows,
				function ( $v ) {
					return $v !== '';
				}
			);
		}

		/**
		 * Render snapshot in WooCommerce emails.
		 *
		 * @param WC_Order $order      Order object.
		 * @param bool     $sent_admin Sent to admin flag.
		 * @param bool     $plain_text Plain text email flag.
		 */
		public static function render_email_snapshot( $order, $sent_admin, $plain_text ) {
			$rows = self::get_snapshot_rows( $order );
			if ( empty( $rows ) ) {
				return;
			}
			if ( $plain_text ) {
				echo "\nShipping & Order Information:\n";
				foreach ( $rows as $k => $v ) {
					echo $k . ': ' . $v . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				return;
			}
			echo '<h3>' . esc_html__( 'Shipping & Order Information', 'musesys-general' ) . '</h3>';
			echo '<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #eee" border="1">';
			foreach ( $rows as $k => $v ) {
				printf( '<tr><th style="text-align:left;border:1px solid #eee;">%s</th><td style="border:1px solid #eee;">%s</td></tr>', esc_html( $k ), esc_html( $v ) );
			}
			echo '</table>';
		}

		/**
		 * Render snapshot on the WooCommerce admin order edit screen.
		 *
		 * @param WC_Order $order Order object.
		 */
		public static function render_admin_snapshot( $order ) {
			$rows = self::get_snapshot_rows( $order );
			if ( empty( $rows ) ) {
				return;
			}
			echo '<div class="address"><p><strong>' . esc_html__( 'MuseSys Snapshot', 'musesys-general' ) . '</strong><br>';
			foreach ( $rows as $k => $v ) {
				printf( '<strong>%s:</strong> %s<br>', esc_html( $k ), esc_html( $v ) );
			}
			echo '</p></div>';
		}

		/**
		 * Render snapshot in My Account -> View Order.
		 *
		 * @param WC_Order $order Order object.
		 */
		public static function render_account_snapshot( $order ) {
			$rows = self::get_snapshot_rows( $order );
			if ( empty( $rows ) ) {
				return;
			}
			echo '<section class="woocommerce-order-details musesys-block"><h2 class="woocommerce-order-details__title">' . esc_html__( 'Shipping & Order Information', 'musesys-general' ) . '</h2><table class="woocommerce-table">';
			foreach ( $rows as $k => $v ) {
				printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $k ), esc_html( $v ) );
			}
			echo '</table></section>';
		}

		/**
		 * Inline JS to sync locked shipping fields & trigger update_checkout when location changes.
		 */
		public static function enqueue_checkout_script() {
			if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
				return;
			}
			$js = "jQuery(function($){
				$('#musesys_location_id').on('change', function(){
					var opt = $(this).find('option:selected');
					var data = opt.data('address');
					if (data) {
						$('#shipping_company').val(data.company || '');
						$('#shipping_address_1').val(data.address_1 || '');
						$('#shipping_address_2').val(data.address_2 || '');
						$('#shipping_city').val(data.city || '');
						$('#shipping_state').val(data.state || '').trigger('change');
						$('#shipping_postcode').val(data.postcode || '');
						$('#shipping_country').val(data.country || 'US').trigger('change');
						$('#musesys-selected-address-preview').text((data.address_1 || '') + ', ' + (data.city || '') + ', ' + (data.state || '') + ' ' + (data.postcode || ''));
					}
					$(document.body).trigger('update_checkout');
				});
			});";
			wp_add_inline_script( 'wc-checkout', $js );
		}
	}

	MuseSys_Checkout::init();
}
