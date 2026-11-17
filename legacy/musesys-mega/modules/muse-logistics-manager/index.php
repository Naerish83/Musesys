<?php
/**
 * Plugin Name: Muse Logistics Manager
 * Description: Custom Order Statuses, Logistics tracking, and smart customer notifications.
 * Version: 1.1.0
 * Author: MuseSystems
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Muse_Logistics_Manager {

    public static function init(): void {
        // --- 1. Logistics Meta Box Hooks ---
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('add_meta_boxes_woocommerce_page_wc-orders', [__CLASS__, 'add_meta_box_hpos']);
        
        // Universal Save Hook (Works for Legacy & HPOS)
        add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'save_logistics_data'], 10, 2);
        
        // Email Injection
        add_action('woocommerce_email_order_meta', [__CLASS__, 'add_email_meta'], 20, 4);

        // --- 2. Custom "Order Placed" Status Hooks ---
        add_action('init', [__CLASS__, 'register_order_placed_status']);
        add_filter('wc_order_statuses', [__CLASS__, 'add_order_placed_to_statuses']);
        add_action('admin_head', [__CLASS__, 'style_order_placed_badge']);
    }

    /* -------------------------------------------------------------------------
     * SECTION 1: CUSTOM ORDER STATUS ("Order Placed")
     * ------------------------------------------------------------------------- */

    /**
     * Register the new status in WordPress
     */
    public static function register_order_placed_status(): void {
        register_post_status('wc-order-placed', array(
            'label'                     => 'Order Placed',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Order Placed <span class="count">(%s)</span>', 'Order Placed <span class="count">(%s)</span>')
        ));
    }

    /**
     * Add to WooCommerce Dropdown (After 'Processing')
     */
    public static function add_order_placed_to_statuses($order_statuses): array {
        $new_order_statuses = array();

        foreach ($order_statuses as $key => $status) {
            $new_order_statuses[$key] = $status;
            if ('wc-processing' === $key) {
                $new_order_statuses['wc-order-placed'] = 'Order Placed';
            }
        }
        return $new_order_statuses;
    }

    /**
     * Style the badge purple
     */
    public static function style_order_placed_badge(): void {
        echo '<style>
            .wc-order-status.status-order-placed {
                background: #8e44ad; 
                color: #fff;
            }
            .order-status.status-order-placed::before {
                background-color: #8e44ad; 
            }
        </style>';
    }

    /* -------------------------------------------------------------------------
     * SECTION 2: LOGISTICS META BOX
     * ------------------------------------------------------------------------- */

    public static function add_meta_box(): void {
        add_meta_box('muse_logistics_meta', 'Logistics & Status', [__CLASS__, 'render_meta_box'], 'shop_order', 'side', 'high');
    }

    public static function add_meta_box_hpos(): void {
         add_meta_box('muse_logistics_meta', 'Logistics & Status', [__CLASS__, 'render_meta_box'], wc_get_page_screen_id('shop_order'), 'side', 'high');
    }

    public static function render_meta_box($post_or_order_object): void {
        $order = ($post_or_order_object instanceof WC_Order) ? $post_or_order_object : wc_get_order($post_or_order_object);
        if (!$order) return;

        $current_status = $order->get_meta('_muse_logistics_status') ?: 'in_stock';
        $current_date = $order->get_meta('_muse_logistics_date');
        
        wp_nonce_field('muse_logistics_save', 'muse_logistics_nonce');
        ?>
        <div class="muse-logistics-panel">
            <p>
                <label><strong>Order Status Note:</strong></label><br>
                <select name="muse_logistics_status" style="width:100%;">
                    <?php
                    // UPDATED STATUS LIST
                    $statuses = [
                        'in_stock'    => 'In Stock',
                        'backordered' => 'Backordered',
                        'replaced'    => 'Replaced', 
                        'obsolete'    => 'Obsolete',
                    ];
                    foreach ($statuses as $k => $label) {
                        printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($current_status, $k, false), esc_html($label));
                    }
                    ?>
                </select>
            </p>
            <p>
                <label><strong>Estimated Date:</strong></label><br>
                <input type="date" name="muse_logistics_date" value="<?php echo esc_attr($current_date); ?>" style="width:100%;">
            </p>
            <p style="padding-top:10px; border-top:1px solid #eee;">
                <label>
                    <input type="checkbox" name="muse_logistics_notify" value="yes"> 
                    <strong>Notify Customer of Update</strong>
                </label>
            </p>
        </div>
        <?php
    }

    public static function save_logistics_data($post_id, $post_object = null): void {
        if (!isset($_POST['muse_logistics_nonce']) || !wp_verify_nonce($_POST['muse_logistics_nonce'], 'muse_logistics_save')) return;

        $order = wc_get_order($post_id);
        if (!$order) return;

        $new_status = sanitize_text_field($_POST['muse_logistics_status'] ?? '');
        $new_date   = sanitize_text_field($_POST['muse_logistics_date'] ?? '');
        $notify     = isset($_POST['muse_logistics_notify']) && $_POST['muse_logistics_notify'] === 'yes';

        self::apply_logistics($order, $new_status, $new_date, $notify);
    }

    /**
     * The logistics mutation: persists status + estimated date and fires the existing side effects
     * (optional customer email; Service Channel 'backordered' automation on change).
     * Shared by the metabox save and the MuseSys Ledger "Set ETA" action.
     *
     * @return array{0:string,1:string} [old_status, old_date]
     */
    public static function apply_logistics(WC_Order $order, string $new_status, string $new_date, bool $notify): array {
        $old_status = $order->get_meta('_muse_logistics_status') ?: 'in_stock';
        $old_date   = (string) $order->get_meta('_muse_logistics_date');

        $order->update_meta_data('_muse_logistics_status', $new_status);
        $order->update_meta_data('_muse_logistics_date', $new_date);

        // Silent save to avoid recursion
        $order->save_meta_data();

        if ($notify && ($new_status !== $old_status || $new_date !== $old_date)) {
            self::handle_notification($order, $new_status, $new_date);
        }

        if ($new_status === 'backordered' && ($new_status !== $old_status || $new_date !== $old_date)) {
            do_action('muse_send_sc_automation', $order->get_id(), 'backordered', array(
                'logistics_status' => $new_status,
                'logistics_date'   => $new_date,
            ));
        }

        return [$old_status, $old_date];
    }

    private static function handle_notification(WC_Order $order, string $status, string $date): void {
        $status_label   = ucwords(str_replace('_', ' ', $status));
        $date_formatted = $date ? date_i18n(get_option('date_format'), strtotime($date)) : 'TBD';
        $to             = $order->get_billing_email();
        
        if (!$to) return; 

        $subject = sprintf('Update on Order #%s: %s', $order->get_id(), $status_label);
        
        $message = sprintf(
            "Hello,\n\nWe have an update regarding your order #%s.\n\nNew Status: %s\nEstimated Date: %s\n\nThank you for your patience.",
            $order->get_id(),
            $status_label,
            $date_formatted
        );
        
        $mailer = WC()->mailer();
        $mailer->send($to, $subject, $mailer->wrap_message($subject, $message));
        
        $order->add_order_note(sprintf("Logistics Notification Sent to %s.\nStatus: %s\nDate: %s", $to, $status_label, $date_formatted));
    }

    public static function add_email_meta($order, $sent_to_admin, $plain_text, $email): void {
        $date = $order->get_meta('_muse_logistics_date');
        if ($date) {
            $formatted = date_i18n(get_option('date_format'), strtotime($date));
            if ($plain_text) {
                echo "\nEstimated Delivery Date: " . $formatted . "\n";
            } else {
                echo '<p style="margin-top: 10px; padding: 10px; background-color: #f7f7f7; border: 1px solid #e5e5e5;"><strong>Estimated Delivery Date:</strong> ' . esc_html($formatted) . '</p>';
            }
        }
    }
}

Muse_Logistics_Manager::init();
