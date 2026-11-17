<?php
/**
 * Plugin Name: MuseSys Wegmans Transfer Items
 * Description: Flags Wegmans-internal-transfer parts on the portal (product notice + dismissible add-to-cart toast) and, at order time, records them as internal transfers — flagging TRX # 99999 and splitting them out of mixed orders with a full audit trail on both sides.
 * Version: 1.0.1
 * Author: MuseSystems
 * License: GPL-2.0-or-later
 *
 * Business rules (see wegmans-transfer-item-tagging-plan.md):
 *   Wegmans pre-buys certain parts, stocks them at their own warehouse, and issues
 *   them to techs directly instead of through this portal. Those parts stay orderable
 *   here (informational, NOT a purchase block), but an order containing one must never
 *   be treated as a genuine purchase:
 *     - every line tagged  -> flag the order TRX # 99999, auto-complete it
 *     - mixed order        -> move the tagged lines into their own secondary order,
 *                             flag + auto-complete that, leave the original order to
 *                             normal fulfillment with only genuine purchases on it
 *   No Gerharz team action is required either way. The audit trail exists so the team
 *   can answer "what happened to this order" later. Wegmans routes these internally
 *   (ServiceChannel status -> MOC -> their buyer); nothing on our side notifies them.
 *
 * Portability note: all business rules live in this one class. partition_items() is the
 * decision logic and is free of side effects; everything WooCommerce-specific is confined
 * to the apply_* / create_* methods, so this can be re-implemented against Cairn's order
 * model without re-deriving the rules from WooCommerce internals.
 *
 * Single-site app: no get_current_blog_id() / site-lock gating anywhere by design.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// HPOS (custom order tables) compatibility — this module touches orders via CRUD only.
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

if ( ! class_exists( 'MuseSys_Wegmans_Transfer_Items' ) ) {
final class MuseSys_Wegmans_Transfer_Items {

    /** Product meta: 'yes' when this part is a Wegmans internal-transfer item. */
    const META_TAG = '_muse_wegmans_transfer_item';

    /** Gerharz-internal TRX # (CORE_META_STANDARDS.md §4) and the reserved transfer value. */
    const META_TRX  = '_trx_order_number';
    const TRX_VALUE = '99999';

    /** Cross-references between an original order and its split-off transfer order. */
    const META_SPLIT_FROM = '_muse_split_from_order_id';
    const META_SPLIT_TO   = '_muse_split_to_order_id';

    /** Idempotence marker: 'flagged' | 'split' | 'split-child'. Only written once we act. */
    const META_HANDLED = '_muse_wti_handled';

    const SESSION_KEY    = 'muse_wti_pending';
    const OPTION_MESSAGE = 'muse_wti_message';
    const CRON_HOOK      = 'muse_wti_process_order';
    const FRAGMENT_KEY   = 'div.muse-wti-flag';

    /**
     * Customer-facing copy. Identical in the product notice and the toast, on purpose.
     * Overridable without a code edit via the muse_wti_message option (Nora may still
     * revise this wording — see plan doc §8b housekeeping).
     */
    const DEFAULT_MESSAGE = 'This item is an inventory transfer. Please contact Wegmans Store Maintenance — this item will not be fulfilled by Gerharz.';

    /** Order IDs seen this request, processed on shutdown. */
    private static $queue = [];

    /** Guards against re-entry while we are building our own secondary order. */
    private static $busy = false;

    public static function init() {
        // --- Admin: tag a product -------------------------------------------------
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'render_product_field' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_product_field' ] );

        // --- Admin: one-shot SKU tagging helper (no hardcoded part list) ----------
        add_action( 'admin_init', [ __CLASS__, 'handle_tag_request' ] );
        add_action( 'admin_init', [ __CLASS__, 'handle_list_request' ] );

        // --- Frontend: always-visible flag ---------------------------------------
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'styles' ] );
        add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_single_notice' ], 25 );
        add_filter( 'woocommerce_short_description', [ __CLASS__, 'append_short_description' ] );
        add_filter( 'woocommerce_cart_item_name', [ __CLASS__, 'render_cart_line_notice' ], 10, 3 );
        add_action( 'woocommerce_review_order_before_cart_contents', [ __CLASS__, 'render_checkout_notice_block' ], 10 );

        // --- Frontend: dismissible add-to-cart toast (all entry points) -----------
        add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'flag_add_to_cart' ], 10, 6 );
        add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'add_cart_fragment' ] );
        add_action( 'wp_footer', [ __CLASS__, 'render_toast' ], 20 );

        // --- Order time: flag / split --------------------------------------------
        add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'queue_order' ], 20, 3 );
        add_action( 'woocommerce_new_order', [ __CLASS__, 'queue_order' ], 20, 2 );
        add_action( 'shutdown', [ __CLASS__, 'run_queue' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'process_order' ] );

        // Auto-completed transfer orders are back-office records, not fulfilment
        // promises — the "your order is complete" email would contradict the notice
        // the tech already saw. Admin/new-order emails are untouched.
        add_filter( 'woocommerce_email_recipient_customer_completed_order', [ __CLASS__, 'filter_completed_recipient' ], 10, 2 );

        // --- Admin: make the record legible on the order screen -------------------
        add_action( 'woocommerce_admin_order_data_after_order_details', [ __CLASS__, 'render_admin_order_banner' ] );

        // --- Admin: manual trigger -------------------------------------------------
        add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_order_action' ] );
        add_action( 'woocommerce_order_action_muse_wti_process', [ __CLASS__, 'process_order_now' ] );
    }

    /* =====================================================================
     * Tagging
     * ===================================================================== */

    /**
     * True when this product (or its parent, for a variation) is a transfer item.
     */
    public static function is_tagged( $product ) {
        $product_id = 0;

        if ( $product instanceof WC_Product ) {
            $product_id = $product->get_parent_id() ?: $product->get_id();
        } elseif ( is_numeric( $product ) ) {
            $product_id = (int) $product;
            $parent     = (int) wp_get_post_parent_id( $product_id );
            if ( $parent && get_post_type( $product_id ) === 'product_variation' ) {
                $product_id = $parent;
            }
        }

        if ( ! $product_id ) {
            return false;
        }

        return get_post_meta( $product_id, self::META_TAG, true ) === 'yes';
    }

    public static function render_product_field() {
        global $post;

        echo '<div class="options_group">';
        woocommerce_wp_checkbox( [
            'id'          => self::META_TAG,
            'label'       => __( 'Wegmans transfer item', 'muse-wegmans-transfer' ),
            'value'       => get_post_meta( $post->ID, self::META_TAG, true ) === 'yes' ? 'yes' : 'no',
            'cbvalue'     => 'yes',
            'desc_tip'    => true,
            'description' => __( 'Wegmans stocks this part at their own warehouse and issues it to techs directly. Tagging it shows the transfer notice on the portal and records any order containing it as an internal transfer (TRX # 99999) instead of a purchase.', 'muse-wegmans-transfer' ),
        ] );
        echo '</div>';
    }

    public static function save_product_field( $post_id ) {
        update_post_meta( $post_id, self::META_TAG, isset( $_POST[ self::META_TAG ] ) ? 'yes' : '' );
    }

    /**
     * Resolve one Wegmans part identifier to candidate products.
     *
     * A 6-digit EAM # is NOT reliably the WooCommerce SKU on this catalog — confirmed
     * 2026-07-27: the product titled "EAM # 153551 / HEATCRAFT 25318801" carries SKU
     * "HEAT25318801", with the EAM # only in the title. So a bare SKU lookup can miss
     * the intended product, or match a different product that happens to use the EAM #
     * as its SKU. Three lookups are tried and every distinct hit is returned, so the
     * caller can refuse to guess when there is more than one.
     *
     * @return array<int, string> product ID => how it matched
     */
    private static function resolve_products( $identifier ) {
        global $wpdb;

        $found    = [];
        $statuses = "('publish','private','draft','pending')";

        $by_sku = wc_get_product_id_by_sku( $identifier );
        if ( $by_sku ) {
            $found[ (int) $by_sku ] = 'SKU';
        }

        $by_meta = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type = 'product' AND p.post_status IN {$statuses}
               AND m.meta_key = 'muse_part_number' AND m.meta_value = %s
             LIMIT 20",
            $identifier
        ) );
        foreach ( $by_meta as $id ) {
            if ( ! isset( $found[ (int) $id ] ) ) {
                $found[ (int) $id ] = 'muse_part_number';
            }
        }

        $by_title = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status IN {$statuses}
               AND post_title LIKE %s
             LIMIT 20",
            '%' . $wpdb->esc_like( $identifier ) . '%'
        ) );
        foreach ( $by_title as $id ) {
            // Only count it if the identifier stands alone — "153551" must not match
            // inside "1535510" or a longer manufacturer number.
            if ( ! preg_match( '/(?<!\d)' . preg_quote( $identifier, '/' ) . '(?!\d)/', (string) get_the_title( $id ) ) ) {
                continue;
            }
            if ( ! isset( $found[ (int) $id ] ) ) {
                $found[ (int) $id ] = 'title';
            }
        }

        return $found;
    }

    private static function describe_product( $product_id, $how ) {
        $product = wc_get_product( $product_id );
        return sprintf(
            '#%1$d "%2$s" (SKU %3$s, matched on %4$s)',
            $product_id,
            get_the_title( $product_id ),
            ( $product && $product->get_sku() !== '' ) ? $product->get_sku() : 'none',
            $how
        );
    }

    /**
     * Tag/untag parts from the admin, e.g.
     *   /wp-admin/?muse_wti_tag=153551,164501,187327,188911,191208
     *   /wp-admin/?muse_wti_untag=164501
     *   /wp-admin/?muse_wti_tag=id:12345          (explicit product ID)
     *
     * Deliberately takes the identifiers from the request rather than a hardcoded list —
     * more parts arrive by email over time, and enforcement must stay data-driven.
     *
     * Never guesses: an identifier that resolves to more than one product is reported
     * with every candidate and left untouched, because silently tagging the wrong one
     * looks identical to success.
     */
    /**
     * Audit: /wp-admin/?muse_wti_list=1 — every product currently tagged.
     * The only reliable way to spot a stray tag left by a mis-resolved identifier.
     */
    public static function handle_list_request() {
        if ( empty( $_GET['muse_wti_list'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        global $wpdb;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type IN ('product','product_variation')
               AND p.post_status IN ('publish','private','draft','pending')
               AND m.meta_key = %s AND m.meta_value = 'yes'
             ORDER BY p.post_title
             LIMIT 200",
            self::META_TAG
        ) );

        add_action( 'admin_notices', function () use ( $ids ) {
            echo '<div class="notice notice-info"><p><strong>Wegmans transfer items — ' . count( $ids ) . ' product(s) currently tagged</strong></p>';
            if ( empty( $ids ) ) {
                echo '<p>Nothing is tagged.</p></div>';
                return;
            }
            echo '<ul style="list-style:disc;margin-left:20px">';
            foreach ( $ids as $id ) {
                $product = wc_get_product( $id );
                printf(
                    '<li>#%1$d "%2$s" — SKU %3$s (<a href="%4$s">edit</a>)</li>',
                    (int) $id,
                    esc_html( get_the_title( $id ) ),
                    esc_html( ( $product && $product->get_sku() !== '' ) ? $product->get_sku() : 'none' ),
                    esc_url( get_edit_post_link( $id ) )
                );
            }
            echo '</ul></div>';
        } );
    }

    public static function handle_tag_request() {
        $tag   = isset( $_GET['muse_wti_tag'] ) ? sanitize_text_field( wp_unslash( $_GET['muse_wti_tag'] ) ) : '';
        $untag = isset( $_GET['muse_wti_untag'] ) ? sanitize_text_field( wp_unslash( $_GET['muse_wti_untag'] ) ) : '';

        if ( $tag === '' && $untag === '' ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $done    = [];
        $skipped = [];

        foreach ( [ 'yes' => $tag, '' => $untag ] as $value => $raw ) {
            if ( $raw === '' ) {
                continue;
            }

            $verb = $value === 'yes' ? 'Tagged' : 'Untagged';

            foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $identifier ) {
                // Explicit product ID escape hatch for ambiguous parts.
                if ( stripos( $identifier, 'id:' ) === 0 ) {
                    $product_id = (int) substr( $identifier, 3 );
                    if ( $product_id && get_post_type( $product_id ) === 'product' ) {
                        update_post_meta( $product_id, self::META_TAG, $value );
                        $done[] = $verb . ' ' . self::describe_product( $product_id, 'explicit ID' );
                    } else {
                        $skipped[] = sprintf( '%s — not a product ID.', $identifier );
                    }
                    continue;
                }

                $candidates = self::resolve_products( $identifier );

                if ( empty( $candidates ) ) {
                    $skipped[] = sprintf( '%s — no product found by SKU, muse_part_number, or title. Nothing changed.', $identifier );
                    continue;
                }

                if ( count( $candidates ) > 1 ) {
                    $list = [];
                    foreach ( $candidates as $id => $how ) {
                        $list[] = self::describe_product( $id, $how );
                    }
                    $skipped[] = sprintf(
                        '%1$s — AMBIGUOUS, matched %2$d products, nothing changed. Re-run with the one you want, e.g. ?muse_wti_tag=id:%3$d. Candidates: %4$s',
                        $identifier,
                        count( $candidates ),
                        (int) array_key_first( $candidates ),
                        implode( ' | ', $list )
                    );
                    continue;
                }

                $product_id = (int) array_key_first( $candidates );
                update_post_meta( $product_id, self::META_TAG, $value );
                $done[] = $verb . ' ' . self::describe_product( $product_id, $candidates[ $product_id ] );
            }
        }

        add_action( 'admin_notices', function () use ( $done, $skipped ) {
            if ( ! empty( $done ) ) {
                echo '<div class="notice notice-success"><p><strong>Wegmans transfer items — ' . count( $done ) . ' product(s) changed</strong></p><ul style="list-style:disc;margin-left:20px">';
                foreach ( $done as $line ) {
                    echo '<li>' . esc_html( $line ) . '</li>';
                }
                echo '</ul><p>Confirm each line is the product you meant — an EAM # is not always the SKU on this catalog.</p></div>';
            }
            if ( ! empty( $skipped ) ) {
                echo '<div class="notice notice-error"><p><strong>Wegmans transfer items — ' . count( $skipped ) . ' NOT changed</strong></p><ul style="list-style:disc;margin-left:20px">';
                foreach ( $skipped as $line ) {
                    echo '<li>' . esc_html( $line ) . '</li>';
                }
                echo '</ul></div>';
            }
        } );
    }

    /* =====================================================================
     * Frontend flag + toast
     * ===================================================================== */

    public static function message() {
        $message = trim( (string) get_option( self::OPTION_MESSAGE, '' ) );
        if ( $message === '' ) {
            $message = self::DEFAULT_MESSAGE;
        }
        return (string) apply_filters( 'muse_wti_message', $message );
    }

    public static function styles() {
        $css = '.muse-wti-notice{border:1px solid #f3c991;background:#fff7e6;color:#663c00;padding:12px 14px;border-radius:6px;margin:12px 0;display:flex;gap:10px;align-items:flex-start}
                .muse-wti-notice .muse-wti-ico{font-weight:700}
                .muse-wti-notice small{display:block;opacity:.9}
                #muse-wti-toast{visibility:hidden;opacity:0;min-width:280px;max-width:440px;background:#663c00;color:#fff;border-radius:6px;padding:14px 40px 14px 16px;position:fixed;left:50%;bottom:30px;transform:translateX(-50%);font-size:14px;line-height:1.4;z-index:99999;transition:opacity .4s ease-in-out;box-shadow:0 2px 10px rgba(0,0,0,.25)}
                #muse-wti-toast.show{visibility:visible;opacity:1}
                #muse-wti-toast .muse-wti-close{position:absolute;top:6px;right:10px;background:none;border:0;color:#fff;font-size:18px;line-height:1;cursor:pointer;padding:4px}';

        wp_register_style( 'muse-wti-style', false );
        wp_enqueue_style( 'muse-wti-style' );
        wp_add_inline_style( 'muse-wti-style', $css );
    }

    private static function html_notice( $message ) {
        return '<div class="muse-wti-notice"><span class="muse-wti-ico">⚠️</span><div>' . esc_html( $message ) . '</div></div>';
    }

    public static function render_single_notice() {
        global $product;
        if ( $product instanceof WC_Product && self::is_tagged( $product ) ) {
            echo self::html_notice( self::message() );
        }
    }

    /**
     * Covers listing/quick-view contexts where the short description renders. Skipped on
     * the product page itself — render_single_notice() already sits under the excerpt there.
     */
    public static function append_short_description( $description ) {
        if ( is_admin() || ( function_exists( 'is_product' ) && is_product() ) ) {
            return $description;
        }

        global $product;
        if ( ! $product instanceof WC_Product || ! self::is_tagged( $product ) ) {
            return $description;
        }

        return $description . '<p class="muse-wti-inline"><strong>' . esc_html( self::message() ) . '</strong></p>';
    }

    public static function render_cart_line_notice( $name, $cart_item, $cart_key ) {
        $product = $cart_item['data'] ?? null;
        if ( $product instanceof WC_Product && self::is_tagged( $product ) ) {
            $name .= '<div class="muse-wti-notice" style="margin:6px 0 0"><span class="muse-wti-ico">⚠️</span><small>'
                   . esc_html( self::message() ) . '</small></div>';
        }
        return $name;
    }

    public static function render_checkout_notice_block() {
        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'] ?? null;
            if ( $product instanceof WC_Product && self::is_tagged( $product ) ) {
                echo self::html_notice( self::message() );
                return;
            }
        }
    }

    /**
     * Fires server-side for every add-to-cart path (AJAX quick-add or classic form post),
     * so the toast cannot be missed by entering from the shop listing instead of the
     * product page. Informational only — the add itself always succeeds.
     */
    public static function flag_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        if ( ! self::is_tagged( $variation_id ? $variation_id : $product_id ) ) {
            return;
        }
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( self::SESSION_KEY, true );
        }
    }

    /**
     * Consume the pending flag. Only an actual add-to-cart AJAX call may consume it —
     * a routine cart-fragment refresh would otherwise swallow the notice before the
     * toast ever renders.
     */
    private static function consume_pending( $allow ) {
        if ( ! $allow || ! function_exists( 'WC' ) || ! WC()->session ) {
            return false;
        }
        if ( ! WC()->session->get( self::SESSION_KEY ) ) {
            return false;
        }
        WC()->session->set( self::SESSION_KEY, null );
        return true;
    }

    public static function add_cart_fragment( $fragments ) {
        // Both AJAX add-to-cart routes WooCommerce offers: the wc-ajax endpoint and the
        // admin-ajax action. Anything else reaching this filter is a fragment refresh.
        $is_add = ( isset( $_REQUEST['wc-ajax'] ) && $_REQUEST['wc-ajax'] === 'add_to_cart' )
               || ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'woocommerce_add_to_cart' );

        $flag = self::consume_pending( $is_add ) ? '1' : '0';

        $fragments[ self::FRAGMENT_KEY ] = '<div class="muse-wti-flag" data-transfer="' . $flag . '"></div>';

        return $fragments;
    }

    public static function render_toast() {
        if ( is_admin() ) {
            return;
        }

        // A classic (non-AJAX) add redirects before any fragment response exists, so the
        // flag survives in session to here and the toast fires on the next page render.
        $pending  = self::consume_pending( true );
        $message  = self::message();
        $fragment = self::FRAGMENT_KEY;
        ?>
        <div class="muse-wti-flag" data-transfer="0" style="display:none"></div>
        <div id="muse-wti-toast" role="status" aria-live="polite"></div>
        <script>
        (function () {
            var MSG      = <?php echo wp_json_encode( $message ); ?>;
            var PENDING  = <?php echo $pending ? 'true' : 'false'; ?>;
            var FRAGMENT = <?php echo wp_json_encode( $fragment ); ?>;
            var timer    = null;

            function showToast() {
                var toast = document.getElementById('muse-wti-toast');
                if (!toast) { return; }
                toast.innerHTML = '<button type="button" class="muse-wti-close" aria-label="Dismiss">&times;</button><span></span>';
                toast.querySelector('span').textContent = MSG;
                toast.querySelector('.muse-wti-close').addEventListener('click', hideToast);
                toast.classList.add('show');
                if (timer) { clearTimeout(timer); }
                timer = setTimeout(hideToast, 9000);
            }

            function hideToast() {
                var toast = document.getElementById('muse-wti-toast');
                if (toast) { toast.classList.remove('show'); }
            }

            document.addEventListener('DOMContentLoaded', function () {
                if (PENDING) { showToast(); }

                if (window.jQuery) {
                    jQuery(document.body).on('added_to_cart', function (event, fragments) {
                        if (fragments && fragments[FRAGMENT] && String(fragments[FRAGMENT]).indexOf('data-transfer="1"') !== -1) {
                            showToast();
                        }
                    });
                }
            });
        })();
        </script>
        <?php
    }

    /* =====================================================================
     * Order-time handling
     * ===================================================================== */

    /**
     * Note the order and do the work on shutdown rather than inline. During checkout
     * WooCommerce still holds its own instance of this order and saves it again after
     * woocommerce_checkout_order_processed (payment_complete etc.); mutating line items
     * from a second instance mid-flight risks that stale object writing them back.
     */
    public static function queue_order( $order_id, $arg = null, $order = null ) {
        if ( $order instanceof WC_Order ) {
            $order_id = $order->get_id();
        } elseif ( $arg instanceof WC_Order ) {
            $order_id = $arg->get_id();
        }

        $order_id = (int) $order_id;
        if ( ! $order_id || self::$busy || isset( self::$queue[ $order_id ] ) ) {
            return;
        }

        self::$queue[ $order_id ] = true;

        // Backstop in case this request dies before shutdown runs.
        if ( ! wp_next_scheduled( self::CRON_HOOK, [ $order_id ] ) ) {
            wp_schedule_single_event( time() + 120, self::CRON_HOOK, [ $order_id ] );
        }
    }

    public static function run_queue() {
        $queued      = array_keys( self::$queue );
        self::$queue = [];

        foreach ( $queued as $order_id ) {
            self::process_order( $order_id );
        }
    }

    /**
     * Idempotent entry point. Safe to call repeatedly for the same order.
     */
    public static function process_order( $order_id ) {
        if ( self::$busy ) {
            return;
        }

        $order = wc_get_order( (int) $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        // Already handled, or it is a secondary order we created ourselves.
        if ( (string) $order->get_meta( self::META_HANDLED ) !== '' ) {
            self::clear_backstop( $order->get_id() );
            return;
        }

        $partition = self::partition_items( $order );
        if ( empty( $partition['tagged'] ) ) {
            // Deliberately not marked handled: an order can gain a tagged line later
            // (admin edit), and re-partitioning on the next trigger costs nothing.
            self::clear_backstop( $order->get_id() );
            return;
        }

        self::$busy = true;
        try {
            if ( empty( $partition['normal'] ) ) {
                self::apply_whole_order_flag( $order, $partition['tagged'] );
            } else {
                self::apply_split( $order, $partition['tagged'] );
            }
        } catch ( Exception $e ) {
            error_log( sprintf( '[muse-wti] Order #%d transfer handling failed: %s', $order->get_id(), $e->getMessage() ) );
            $order->add_order_note( 'Wegmans transfer handling failed — needs manual review. Error: ' . $e->getMessage() );
        }
        self::$busy = false;

        self::clear_backstop( $order->get_id() );
    }

    private static function clear_backstop( $order_id ) {
        wp_clear_scheduled_hook( self::CRON_HOOK, [ (int) $order_id ] );
    }

    /**
     * Pure decision logic: split an order's lines into transfer items vs genuine purchases.
     * No side effects — this is the piece Cairn re-implements.
     *
     * @return array{tagged: array<int, array>, normal: array<int, array>}
     */
    public static function partition_items( WC_Order $order ) {
        $tagged = [];
        $normal = [];

        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $product    = $item->get_product();
            $product_id = $item->get_variation_id() ?: $item->get_product_id();

            $row = [
                'item_id' => (int) $item_id,
                'item'    => $item,
                'name'    => $item->get_name(),
                'qty'     => $item->get_quantity(),
                'sku'     => $product instanceof WC_Product ? $product->get_sku() : '',
            ];

            if ( self::is_tagged( $product instanceof WC_Product ? $product : $product_id ) ) {
                $tagged[] = $row;
            } else {
                $normal[] = $row;
            }
        }

        return [ 'tagged' => $tagged, 'normal' => $normal ];
    }

    /**
     * Every line is a transfer item — no split needed. Flag the order as-is and complete it.
     */
    private static function apply_whole_order_flag( WC_Order $order, array $tagged ) {
        $order->update_meta_data( self::META_TRX, self::TRX_VALUE );
        $order->update_meta_data( self::META_HANDLED, 'flagged' );
        $order->save();

        $order->add_order_note( sprintf(
            'Wegmans internal transfer (automatic, %1$s). Every line on this order is a tagged transfer item (%2$s), so nothing was split off. TRX # set to %3$s and the order auto-completed — Gerharz is not fulfilling these parts; Wegmans issues them from their own stock. Recorded for the audit trail only.',
            current_time( 'mysql' ),
            self::describe_items( $tagged ),
            self::TRX_VALUE
        ) );

        self::complete( $order, 'Wegmans internal transfer — auto-completed, no Gerharz fulfilment.' );
    }

    /**
     * Mixed order: move the tagged lines onto their own order so the original proceeds
     * through normal fulfilment with only genuine purchases on it.
     */
    private static function apply_split( WC_Order $order, array $tagged ) {
        // Duplicate-creation guard, mirroring place_order_from_request()'s fulfilled_order_id.
        if ( (string) $order->get_meta( self::META_SPLIT_TO ) !== '' ) {
            return;
        }

        $child = self::create_transfer_order( $order, $tagged );

        foreach ( $tagged as $row ) {
            $order->remove_item( $row['item_id'] );
        }

        $order->calculate_totals();
        $order->update_meta_data( self::META_SPLIT_TO, $child->get_id() );
        $order->update_meta_data( self::META_HANDLED, 'split' );
        $order->save();

        $order->add_order_note( sprintf(
            'Wegmans internal transfer split (automatic, %1$s). Removed %2$s from this order and moved it to order #%3$d, which was flagged TRX # %4$s and auto-completed. Reason: Wegmans stocks these parts at their own warehouse and issues them to techs directly, so they are not a Gerharz purchase. Order totals were recalculated; the remaining lines proceed through normal fulfilment.',
            current_time( 'mysql' ),
            self::describe_items( $tagged ),
            $child->get_id(),
            self::TRX_VALUE
        ) );

        $child->add_order_note( sprintf(
            'Wegmans internal transfer record (automatic, %1$s). Contains %2$s, split out of order #%3$d because that order also held genuine purchase items. TRX # %4$s; auto-completed — Gerharz is not fulfilling these parts. Wegmans routes them internally (ServiceChannel status → MOC → their buyer). Recorded for the audit trail only.',
            current_time( 'mysql' ),
            self::describe_items( $tagged ),
            $order->get_id(),
            self::TRX_VALUE
        ) );

        self::complete( $child, sprintf( 'Wegmans internal transfer — auto-completed. Split from order #%d.', $order->get_id() ) );
    }

    /**
     * Build the secondary order. Follows muse-product-request/index.php's
     * place_order_from_request() shape; the source here is an existing order's line items.
     *
     * No shipping lines and no pricing adjustments: portal prices are non-authoritative
     * and nothing ships from Gerharz for a transfer item.
     */
    private static function create_transfer_order( WC_Order $order, array $tagged ) {
        $child = wc_create_order( [
            'created_via' => 'muse-transfer-split',
            'customer_id' => $order->get_customer_id(),
            'status'      => 'pending',
        ] );

        if ( is_wp_error( $child ) || ! $child instanceof WC_Order ) {
            throw new Exception( 'wc_create_order() failed for the transfer split order.' );
        }

        // Written first so a retry can never build a second copy.
        $child->update_meta_data( self::META_SPLIT_FROM, $order->get_id() );
        $child->update_meta_data( self::META_HANDLED, 'split-child' );
        $child->update_meta_data( self::META_TRX, self::TRX_VALUE );
        $child->save();

        $child->set_address( $order->get_address( 'billing' ), 'billing' );
        $child->set_address( $order->get_address( 'shipping' ), 'shipping' );
        $child->set_currency( $order->get_currency() );
        $child->set_customer_ip_address( $order->get_customer_ip_address() );
        $child->set_payment_method( $order->get_payment_method() );
        $child->set_payment_method_title( $order->get_payment_method_title() );

        foreach ( self::carried_order_meta() as $key ) {
            $value = $order->get_meta( $key );
            if ( $value !== '' && $value !== null && $value !== [] ) {
                $child->update_meta_data( $key, $value );
            }
        }

        foreach ( $tagged as $row ) {
            /** @var WC_Order_Item_Product $item */
            $item    = $row['item'];
            $product = $item->get_product();

            if ( ! $product instanceof WC_Product ) {
                throw new Exception( sprintf( 'Line item %d has no resolvable product; refusing to split.', $row['item_id'] ) );
            }

            $new_item_id = $child->add_product( $product, $item->get_quantity(), [
                'subtotal' => $item->get_subtotal(),
                'total'    => $item->get_total(),
            ] );

            $new_item = $child->get_item( $new_item_id );
            if ( $new_item ) {
                // Carry the line context across verbatim — Work Order #, Install Store #,
                // line notes, gasket specs and anything else a module attached at checkout.
                foreach ( $item->get_meta_data() as $meta ) {
                    $data = $meta->get_data();
                    if ( empty( $data['key'] ) ) {
                        continue;
                    }
                    $new_item->add_meta_data( $data['key'], $data['value'], true );
                }
                $new_item->save();
            }
        }

        $child->calculate_totals();
        $child->save();

        return $child;
    }

    /**
     * Order-level context copied onto the secondary order so it reads as a complete record.
     */
    private static function carried_order_meta() {
        return (array) apply_filters( 'muse_wti_carried_order_meta', [
            '_muse_wo_global',
            '_muse_store_global',
            '_muse_ship_to',
            '_muse_ship_store',
            '_muse_contact_phone',
            '_muse_shipping_speed',
            '_purchase_order_number',
            '_musesys_technician_phone',
        ] );
    }

    /**
     * order-fields/muse-order-fields.php throws on a "completed" transition when
     * _trx_order_number is empty. We set it first, so this passes — but the throw is
     * caught here regardless so a gate failure can never take down the request.
     */
    private static function complete( WC_Order $order, $note ) {
        if ( (string) $order->get_meta( self::META_TRX ) === '' ) {
            $order->update_meta_data( self::META_TRX, self::TRX_VALUE );
            $order->save();
        }

        if ( $order->get_status() === 'completed' ) {
            return;
        }

        try {
            $order->update_status( 'completed', $note );
        } catch ( Exception $e ) {
            error_log( sprintf( '[muse-wti] Order #%d could not be auto-completed: %s', $order->get_id(), $e->getMessage() ) );
            $order->add_order_note( 'Automatic completion of this Wegmans transfer order was blocked: ' . $e->getMessage() );
        }
    }

    private static function describe_items( array $rows ) {
        $parts = [];
        foreach ( $rows as $row ) {
            $parts[] = sprintf(
                '%1$s (SKU %2$s) × %3$s',
                $row['name'],
                $row['sku'] !== '' ? $row['sku'] : 'n/a',
                $row['qty']
            );
        }
        return implode( '; ', $parts );
    }

    public static function filter_completed_recipient( $recipient, $order ) {
        if ( ! $order instanceof WC_Order ) {
            return $recipient;
        }
        // Only the orders this module auto-completed. The surviving half of a split
        // ('split') is a genuine purchase that a teammate completes normally — its
        // completion email must go out as usual.
        if ( ! in_array( (string) $order->get_meta( self::META_HANDLED ), [ 'flagged', 'split-child' ], true ) ) {
            return $recipient;
        }
        if ( ! apply_filters( 'muse_wti_suppress_customer_completed_email', true, $order ) ) {
            return $recipient;
        }
        return '';
    }

    /**
     * Orders built in wp-admin never reach the automatic path: WooCommerce fires
     * woocommerce_new_order for the empty draft, before any line items exist, and the
     * later "Create" save fires no order-creation hook. Rather than hook every admin
     * save — which would retroactively split historical orders the moment someone
     * opened one for an unrelated edit — this is an explicit, deliberate trigger.
     * Also the cleanest way to exercise the split on staging.
     */
    public static function add_order_action( $actions ) {
        $actions['muse_wti_process'] = __( 'Process Wegmans transfer items', 'muse-wegmans-transfer' );
        return $actions;
    }

    public static function process_order_now( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $before = $order->get_meta( self::META_HANDLED );
        self::process_order( $order->get_id() );

        // Re-read: process_order() worked on its own instance.
        $after = wc_get_order( $order->get_id() );
        if ( $after instanceof WC_Order && (string) $after->get_meta( self::META_HANDLED ) === (string) $before ) {
            $after->add_order_note( 'Checked for Wegmans transfer items on request — none found on this order, nothing changed.' );
        }
    }

    public static function render_admin_order_banner( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $handled = (string) $order->get_meta( self::META_HANDLED );
        if ( $handled === '' ) {
            return;
        }

        $lines = [];
        if ( $handled === 'flagged' ) {
            $lines[] = 'Every line is a Wegmans internal-transfer item. Flagged TRX # ' . self::TRX_VALUE . ' and auto-completed.';
        } elseif ( $handled === 'split' ) {
            $lines[] = 'Transfer items were split off this order into order #' . (int) $order->get_meta( self::META_SPLIT_TO ) . '. The lines remaining here are genuine purchases.';
        } elseif ( $handled === 'split-child' ) {
            $lines[] = 'Wegmans internal-transfer record, split out of order #' . (int) $order->get_meta( self::META_SPLIT_FROM ) . '. Flagged TRX # ' . self::TRX_VALUE . ' and auto-completed. Not fulfilled by Gerharz.';
        }

        echo '<div class="muse-wti-admin" style="clear:both;padding:10px 12px;margin:10px 0;border-left:4px solid #f3c991;background:#fff7e6"><p style="margin:0"><strong>Wegmans Transfer</strong><br>'
           . esc_html( implode( ' ', $lines ) )
           . '</p></div>';
    }
}
MuseSys_Wegmans_Transfer_Items::init();
}
