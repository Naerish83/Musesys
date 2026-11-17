<?php
/**
 * MuseSys General — Prop 65 Notices Module
 *
 * Per-product and per-variation California Prop 65 warnings, gated by the
 * active guide's `prop65_enabled` setting (off for Wegmans, available for Dunkin).
 * Preserves legacy `_muse_prop65_*` product and variation meta keys.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MuseSys_Prop65' ) ) {
	final class MuseSys_Prop65 {

		const META_ENABLED  = '_muse_prop65_enabled';
		const META_CHEMS    = '_muse_prop65_chems';
		const META_MESSAGE  = '_muse_prop65_message';

		const VMETA_ENABLED = '_muse_prop65_enabled_var';
		const VMETA_CHEMS   = '_muse_prop65_chems_var';
		const VMETA_MESSAGE = '_muse_prop65_message_var';

		const DEFAULT_MSG   = 'WARNING: This product can expose you to chemicals including {chemicals}, which are known to the State of California to cause cancer and birth defects or other reproductive harm. For more information, visit www.P65Warnings.ca.gov.';

		/**
		 * Bootstrap hooks.
		 */
		public static function init() {
			// Admin UI: product data tab & variations (always available for catalog maintenance).
			add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_tab' ) );
			add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_product_panel' ) );
			add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_meta' ) );

			add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 10, 3 );
			add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_fields' ), 10, 2 );

			// Frontend display (gated per guide).
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );
			add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_single_notice' ), 25 );
			add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'render_cart_line_notice' ), 10, 3 );
			add_action( 'woocommerce_review_order_before_cart_contents', array( __CLASS__, 'render_checkout_notice_block' ), 10 );

			add_shortcode( 'prop65_notice', array( __CLASS__, 'shortcode' ) );
			add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'attach_order_item_meta' ), 10, 4 );
		}

		/**
		 * Check whether Prop 65 notices are enabled for the current user's guide.
		 *
		 * @return bool
		 */
		public static function is_enabled_for_current_guide() {
			$guide = class_exists( 'MuseSys_Guides' ) ? MuseSys_Guides::get_user_guide() : null;
			return (bool) ( $guide && (int) $guide->prop65_enabled === 1 );
		}

		/**
		 * Add Prop 65 tab to WooCommerce product data metabox.
		 *
		 * @param array $tabs Product data tabs.
		 * @return array
		 */
		public static function add_product_tab( $tabs ) {
			$tabs['muse_prop65'] = array(
				'label'    => __( 'Prop 65', 'musesys-general' ),
				'target'   => 'muse_prop65_panel',
				'class'    => array( 'show_if_simple', 'show_if_variable', 'show_if_external', 'show_if_grouped' ),
				'priority' => 80,
			);
			return $tabs;
		}

		/**
		 * Render product-level Prop 65 fields.
		 */
		public static function render_product_panel() {
			global $post;
			$enabled = get_post_meta( $post->ID, self::META_ENABLED, true );
			$chems   = get_post_meta( $post->ID, self::META_CHEMS, true );
			$msg     = get_post_meta( $post->ID, self::META_MESSAGE, true );
			?>
			<div id="muse_prop65_panel" class="panel woocommerce_options_panel">
				<div class="options_group">
					<?php
					woocommerce_wp_checkbox(
						array(
							'id'          => self::META_ENABLED,
							'label'       => __( 'Enable Prop 65 Warning', 'musesys-general' ),
							'value'       => $enabled === 'yes' ? 'yes' : 'no',
							'desc_tip'    => true,
							'description' => __( 'Check to display a Prop 65 warning for this product. Variations can override.', 'musesys-general' ),
						)
					);
					woocommerce_wp_text_input(
						array(
							'id'          => self::META_CHEMS,
							'label'       => __( 'Chemicals (comma-separated)', 'musesys-general' ),
							'value'       => $chems,
							'desc_tip'    => true,
							'description' => __( 'Example: Lead, DEHP. Used in the default warning text.', 'musesys-general' ),
						)
					);
					woocommerce_wp_textarea_input(
						array(
							'id'          => self::META_MESSAGE,
							'label'       => __( 'Custom Message (optional)', 'musesys-general' ),
							'value'       => $msg,
							'placeholder' => self::DEFAULT_MSG,
							'desc_tip'    => true,
							'description' => __( 'Leave blank to use the default template. Use {chemicals} token to inject the list above.', 'musesys-general' ),
							'rows'        => 4,
						)
					);
					?>
				</div>
			</div>
			<?php
		}

		/**
		 * Save product-level Prop 65 meta.
		 *
		 * @param int $post_id Product ID.
		 */
		public static function save_product_meta( $post_id ) {
			$enabled = isset( $_POST[ self::META_ENABLED ] ) ? 'yes' : 'no';
			update_post_meta( $post_id, self::META_ENABLED, $enabled );
			update_post_meta( $post_id, self::META_CHEMS, sanitize_text_field( wp_unslash( $_POST[ self::META_CHEMS ] ?? '' ) ) );
			update_post_meta( $post_id, self::META_MESSAGE, wp_kses_post( wp_unslash( $_POST[ self::META_MESSAGE ] ?? '' ) ) );
		}

		/**
		 * Render variation-level Prop 65 override fields.
		 *
		 * @param int     $loop           Variation loop index.
		 * @param array   $variation_data Variation data.
		 * @param WP_Post $variation      Variation post.
		 */
		public static function variation_fields( $loop, $variation_data, $variation ) {
			$vid = $variation->ID;
			$ven = get_post_meta( $vid, self::VMETA_ENABLED, true );
			$vch = get_post_meta( $vid, self::VMETA_CHEMS, true );
			$vms = get_post_meta( $vid, self::VMETA_MESSAGE, true );
			?>
			<div class="form-row form-row-full">
				<label><strong><?php esc_html_e( 'Prop 65 (variation override)', 'musesys-general' ); ?></strong></label>
			</div>
			<p class="form-row form-row-first">
				<label for="<?php echo esc_attr( self::VMETA_ENABLED . "_{$vid}" ); ?>">
					<input type="checkbox" class="checkbox"
						name="<?php echo esc_attr( self::VMETA_ENABLED . "[{$vid}]" ); ?>"
						id="<?php echo esc_attr( self::VMETA_ENABLED . "_{$vid}" ); ?>"
						<?php checked( $ven, 'yes' ); ?> />
					<?php esc_html_e( 'Enable for this variation', 'musesys-general' ); ?>
				</label>
			</p>
			<p class="form-row form-row-last">
				<input type="text"
					name="<?php echo esc_attr( self::VMETA_CHEMS . "[{$vid}]" ); ?>"
					value="<?php echo esc_attr( $vch ); ?>"
					placeholder="Lead, DEHP"
					style="width:100%;" />
			</p>
			<p class="form-row form-row-full">
				<textarea name="<?php echo esc_attr( self::VMETA_MESSAGE . "[{$vid}]" ); ?>" rows="3" style="width:100%;" placeholder="<?php echo esc_attr( self::DEFAULT_MSG ); ?>"><?php echo esc_textarea( $vms ); ?></textarea>
				<em><?php esc_html_e( 'Leave fields blank to fall back to product-level settings.', 'musesys-general' ); ?></em>
			</p>
			<hr />
			<?php
		}

		/**
		 * Save variation-level Prop 65 meta.
		 *
		 * @param int $variation_id Variation ID.
		 * @param int $i            Loop index.
		 */
		public static function save_variation_fields( $variation_id, $i ) {
			$en = isset( $_POST[ self::VMETA_ENABLED ][ $variation_id ] ) ? 'yes' : '';
			update_post_meta( $variation_id, self::VMETA_ENABLED, $en );

			$ch = wp_unslash( $_POST[ self::VMETA_CHEMS ][ $variation_id ] ?? '' );
			$ms = wp_unslash( $_POST[ self::VMETA_MESSAGE ][ $variation_id ] ?? '' );

			update_post_meta( $variation_id, self::VMETA_CHEMS, sanitize_text_field( $ch ) );
			update_post_meta( $variation_id, self::VMETA_MESSAGE, wp_kses_post( $ms ) );
		}

		/**
		 * Enqueue inline Prop 65 notice styles when enabled for the current guide.
		 */
		public static function styles() {
			if ( ! self::is_enabled_for_current_guide() ) {
				return;
			}
			$css = '.muse-prop65{border:1px solid #f3c991;background:#fff7e6;color:#663c00;padding:12px 14px;border-radius:6px;margin:12px 0;display:flex;gap:10px;align-items:flex-start}
					.muse-prop65 .muse-prop65-ico{font-weight:700}
					.muse-prop65 small{display:block;opacity:.9}';
			wp_register_style( 'muse-prop65-style', false );
			wp_enqueue_style( 'muse-prop65-style' );
			wp_add_inline_style( 'muse-prop65-style', $css );
		}

		/**
		 * Render Prop 65 notice on single product page.
		 */
		public static function render_single_notice() {
			if ( ! self::is_enabled_for_current_guide() ) {
				return;
			}
			global $product;
			if ( ! $product instanceof WC_Product ) {
				return;
			}
			$notice = self::resolve_notice_for_product( $product );
			if ( $notice ) {
				echo self::html_notice( $notice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		/**
		 * Append compact Prop 65 notice under cart item name.
		 *
		 * @param string $name      Item name HTML.
		 * @param array  $cart_item Cart item array.
		 * @param string $cart_key  Cart key.
		 * @return string
		 */
		public static function render_cart_line_notice( $name, $cart_item, $cart_key ) {
			if ( ! self::is_enabled_for_current_guide() ) {
				return $name;
			}
			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				return $name;
			}
			$notice = self::resolve_notice_for_product( $product, $cart_item );
			if ( $notice ) {
				$name .= '<div class="muse-prop65" style="margin:6px 0 0"><span class="muse-prop65-ico">&#9888;&#65039;</span><small>'
					. wp_kses_post( $notice )
					. '</small></div>';
			}
			return $name;
		}

		/**
		 * Render a single Prop 65 block at checkout if any cart items carry a warning.
		 */
		public static function render_checkout_notice_block() {
			if ( ! self::is_enabled_for_current_guide() || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
				return;
			}
			foreach ( WC()->cart->get_cart() as $item ) {
				$product = $item['data'] ?? null;
				if ( $product instanceof WC_Product ) {
					$notice = self::resolve_notice_for_product( $product, $item );
					if ( $notice ) {
						echo self::html_notice( $notice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						break;
					}
				}
			}
		}

		/**
		 * Shortcode [prop65_notice id="123"].
		 *
		 * @param array $atts Shortcode attributes.
		 * @return string
		 */
		public static function shortcode( $atts ) {
			if ( ! self::is_enabled_for_current_guide() ) {
				return '';
			}
			$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'prop65_notice' );
			$product = null;

			if ( ! empty( $atts['id'] ) ) {
				$product = wc_get_product( (int) $atts['id'] );
			} elseif ( function_exists( 'wc_get_product' ) && is_product() ) {
				$product = wc_get_product( get_the_ID() );
			}

			if ( $product instanceof WC_Product ) {
				$notice = self::resolve_notice_for_product( $product );
				return $notice ? self::html_notice( $notice ) : '';
			}
			return '';
		}

		/**
		 * Attach Prop 65 notice to order line item meta when enabled for the guide.
		 *
		 * @param WC_Order_Item_Product $item          Order line item.
		 * @param string                $cart_item_key Cart key.
		 * @param array                 $values        Cart item values.
		 * @param WC_Order              $order         Order object.
		 */
		public static function attach_order_item_meta( $item, $cart_item_key, $values, $order ) {
			if ( ! self::is_enabled_for_current_guide() ) {
				return;
			}
			$product = $values['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				return;
			}
			$notice = self::resolve_notice_for_product( $product, $values );
			if ( $notice ) {
				$item->add_meta_data( 'Prop 65', wp_strip_all_tags( $notice ), true );
			}
		}

		/**
		 * Resolve Prop 65 notice text for a product or variation.
		 *
		 * @param WC_Product $product Product object.
		 * @param array|null $context Optional cart item context.
		 * @return string
		 */
		private static function resolve_notice_for_product( WC_Product $product, $context = null ) {
			$vid = 0;
			if ( ! empty( $context['variation_id'] ) ) {
				$vid = (int) $context['variation_id'];
			} elseif ( $product->is_type( 'variation' ) ) {
				$vid = $product->get_id();
			}

			if ( $vid ) {
				$ven = get_post_meta( $vid, self::VMETA_ENABLED, true );
				if ( $ven === 'yes' ) {
					$vch = trim( (string) get_post_meta( $vid, self::VMETA_CHEMS, true ) );
					$vms = trim( (string) get_post_meta( $vid, self::VMETA_MESSAGE, true ) );
					return self::build_message( $vch, $vms );
				}
			}

			$pid     = $product->get_parent_id() ?: $product->get_id();
			$enabled = get_post_meta( $pid, self::META_ENABLED, true );
			if ( $enabled !== 'yes' ) {
				return '';
			}
			$chem = trim( (string) get_post_meta( $pid, self::META_CHEMS, true ) );
			$msg  = trim( (string) get_post_meta( $pid, self::META_MESSAGE, true ) );
			return self::build_message( $chem, $msg );
		}

		/**
		 * Build formatted warning message.
		 *
		 * @param string $chemicals Comma-separated chemical names.
		 * @param string $custom    Optional custom template.
		 * @return string
		 */
		private static function build_message( $chemicals, $custom ) {
			$tmpl = $custom !== '' ? $custom : self::DEFAULT_MSG;
			$chem = $chemicals !== '' ? $chemicals : 'chemicals';
			return str_replace( '{chemicals}', esc_html( $chem ), wp_kses_post( $tmpl ) );
		}

		/**
		 * Wrap message in Prop 65 notice HTML.
		 *
		 * @param string $message Warning text.
		 * @return string
		 */
		private static function html_notice( $message ) {
			return '<div class="muse-prop65"><span class="muse-prop65-ico">&#9888;&#65039;</span><div>'
				. wp_kses_post( $message )
				. '</div></div>';
		}
	}

	MuseSys_Prop65::init();
}
