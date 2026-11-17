<?php
/**
 * MuseSys General — User Profile Module
 *
 * Single owner of the `contact_phone` user profile field and checkout prefill/save.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MuseSys_User_Profile' ) ) {
	final class MuseSys_User_Profile {

		const META_KEY = 'contact_phone';

		/**
		 * Bootstrap hooks.
		 */
		public static function init() {
			add_action( 'show_user_profile', array( __CLASS__, 'render_field' ) );
			add_action( 'edit_user_profile', array( __CLASS__, 'render_field' ) );
			add_action( 'personal_options_update', array( __CLASS__, 'save_field' ) );
			add_action( 'edit_user_profile_update', array( __CLASS__, 'save_field' ) );
			add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill_checkout_phone' ), 10, 2 );
		}

		/**
		 * Render contact_phone field on user profile edit screen.
		 *
		 * @param WP_User $user User object.
		 */
		public static function render_field( $user ) {
			?>
			<h3><?php esc_html_e( 'Contact Information', 'musesys-general' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><label for="contact_phone"><?php esc_html_e( 'Contact Phone', 'musesys-general' ); ?></label></th>
					<td>
						<input type="text" name="contact_phone" id="contact_phone" value="<?php echo esc_attr( get_user_meta( $user->ID, self::META_KEY, true ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Primary contact phone number for order notifications.', 'musesys-general' ); ?></p>
					</td>
				</tr>
			</table>
			<?php
		}

		/**
		 * Save contact_phone field from user profile edit screen.
		 *
		 * @param int $user_id User ID.
		 */
		public static function save_field( $user_id ) {
			if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['contact_phone'] ) ) {
				return;
			}
			update_user_meta( $user_id, self::META_KEY, sanitize_text_field( wp_unslash( $_POST['contact_phone'] ) ) );
		}

		/**
		 * Prefill billing_phone on checkout from contact_phone when empty.
		 *
		 * @param mixed  $value Current field value.
		 * @param string $input Field key.
		 * @return mixed
		 */
		public static function prefill_checkout_phone( $value, $input ) {
			if ( $input !== 'billing_phone' || ! empty( $value ) || ! is_user_logged_in() ) {
				return $value;
			}
			$phone = get_user_meta( get_current_user_id(), self::META_KEY, true );
			return $phone !== '' ? $phone : $value;
		}
	}

	MuseSys_User_Profile::init();
}
