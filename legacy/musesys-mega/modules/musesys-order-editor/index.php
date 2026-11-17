<?php
/**
 * Plugin Name: MuseSys Wegmans Order Editor
 * Description: Admin-only order editor for pre-completion Wegmans order line, metadata, and ship-to corrections with WooCommerce order-note audit history.
 * Version: 1.0.0
 * Author: MuseSystems
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class MuseSys_Wegmans_Order_Editor {
    private const VERSION = '1.0.0';
    private const OPTION_KEY = 'musesys_order_editor_settings';
    private const NONCE_ACTION = 'musesys_order_editor_action';
    private const MENU_SLUG = 'musesys-order-editor';

    private const META_WO_GLOBAL = '_muse_wo_global';
    private const META_STORE_GLOBAL = '_muse_store_global';
    private const META_SHIP_STORE = '_muse_ship_store';
    private const META_SHIP_TO = '_muse_ship_to';

    private const DEFAULT_SETTINGS = [
        'enabled' => 'yes',
        'allowed_users' => [],
        'allowed_roles' => ['administrator', 'shop_manager'],
        'customer_note_template' => '',
    ];

    private static bool $booted = false;

    public static function boot(): void {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        add_action('before_woocommerce_init', [__CLASS__, 'declare_hpos_compatibility']);
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_bar_menu', [__CLASS__, 'register_admin_bar'], 90);
        add_action('add_meta_boxes', [__CLASS__, 'add_order_panel']);
        add_action('admin_post_musesys_order_editor_action', [__CLASS__, 'handle_action']);
    }

    public static function declare_hpos_compatibility(): void {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    public static function register_menu(): void {
        add_submenu_page(
            'musesys',
            'Wegmans Order Editor',
            'Wegmans Order Editor',
            'manage_woocommerce',
            self::MENU_SLUG,
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings(): void {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default' => self::DEFAULT_SETTINGS,
        ]);
    }

    public static function sanitize_settings($input): array {
        $input = is_array($input) ? $input : [];
        $roles = isset($input['allowed_roles']) && is_array($input['allowed_roles']) ? $input['allowed_roles'] : [];
        $users = isset($input['allowed_users']) && is_array($input['allowed_users']) ? $input['allowed_users'] : [];

        return [
            'enabled' => empty($input['enabled']) ? 'no' : 'yes',
            'allowed_roles' => array_values(array_filter(array_map('sanitize_key', $roles))),
            'allowed_users' => array_values(array_filter(array_map('absint', $users))),
            'customer_note_template' => sanitize_textarea_field((string)($input['customer_note_template'] ?? '')),
        ];
    }

    public static function register_admin_bar(\WP_Admin_Bar $bar): void {
        if (!is_admin() || !self::current_user_allowed()) {
            return;
        }

        $bar->add_node([
            'id' => 'musesys-order-editor',
            'title' => 'Order Editor',
            'href' => admin_url('admin.php?page=' . self::MENU_SLUG),
            'meta' => ['title' => 'MuseSys Wegmans Order Editor'],
        ]);
    }

    public static function add_order_panel(): void {
        if (!self::current_user_allowed()) {
            return;
        }

        $screen = 'shop_order';
        if (self::hpos_orders_screen_enabled()) {
            $screen = wc_get_page_screen_id('shop-order');
        }

        add_meta_box(
            'musesys_wegmans_order_editor',
            'MuseSys Wegmans Order Editor',
            [__CLASS__, 'render_order_panel'],
            $screen,
            'normal',
            'high'
        );
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Insufficient permissions', 'musesys'));
        }

        $settings = self::settings();
        $saved = isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true';
        $roles = wp_roles()->roles;
        $users = get_users([
            'fields' => ['ID', 'display_name', 'user_email'],
            'orderby' => 'display_name',
            'number' => 500,
        ]);
        $allowed_user_ids = array_map('intval', $settings['allowed_users']);

        echo '<div class="wrap">';
        echo '<h1>MuseSys Wegmans Order Editor</h1>';
        echo '<p class="description">Admin-only utility for order corrections. The panel locks only after the order is completed, has a TRX #, and has shipping-manager tracking.</p>';

        if ($saved) {
            echo '<div class="notice notice-success inline muse-oe-saved"><p><strong>Order editor settings saved.</strong> Active access: ' . esc_html(self::access_summary($settings)) . '</p></div>';
        } else {
            echo '<div class="notice notice-info inline muse-oe-saved"><p><strong>Current access:</strong> ' . esc_html(self::access_summary($settings)) . '</p></div>';
        }

        self::render_settings_styles();

        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_KEY);

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">Global On/Off</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[enabled]" value="yes" ' . checked($settings['enabled'], 'yes', false) . '> Enabled</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Allowed Roles</th><td>';
        foreach ($roles as $role_key => $role) {
            $checked = in_array($role_key, $settings['allowed_roles'], true);
            echo '<label style="display:block;margin:0 0 4px;">';
            echo '<input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[allowed_roles][]" value="' . esc_attr($role_key) . '" ' . checked($checked, true, false) . '> ';
            echo esc_html(translate_user_role($role['name']));
            echo '</label>';
        }
        echo '<p class="description">Users matching any selected role can use the editor.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Allowed Users</th><td>';
        echo '<div class="muse-oe-user-picker">';
        echo '<div class="muse-oe-user-pane">';
        echo '<label for="muse_oe_user_pool"><strong>User list</strong></label>';
        echo '<select id="muse_oe_user_pool" multiple size="12">';
        foreach ($users as $user) {
            if (in_array((int)$user->ID, $allowed_user_ids, true)) {
                continue;
            }
            $label = sprintf('#%d - %s (%s)', (int)$user->ID, (string)$user->display_name, (string)$user->user_email);
            echo '<option value="' . esc_attr((string)$user->ID) . '">' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="muse-oe-user-controls" aria-label="Allowed user controls">';
        echo '<button type="button" class="button" id="muse_oe_allow_user">&gt;</button>';
        echo '<button type="button" class="button" id="muse_oe_remove_user">&lt;</button>';
        echo '</div>';

        echo '<div class="muse-oe-user-pane">';
        echo '<label for="muse_oe_allowed_users"><strong>Allowed Users</strong></label>';
        echo '<select id="muse_oe_allowed_users" multiple size="12" name="' . esc_attr(self::OPTION_KEY) . '[allowed_users][]">';
        foreach ($users as $user) {
            if (!in_array((int)$user->ID, $allowed_user_ids, true)) {
                continue;
            }
            $label = sprintf('#%d - %s (%s)', (int)$user->ID, (string)$user->display_name, (string)$user->user_email);
            echo '<option value="' . esc_attr((string)$user->ID) . '">' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</div>';
        echo '<p class="description">Specific users are allowed even if their role is not selected. Move users into the Allowed Users pane before saving.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Standard Customer Note</th><td>';
        echo '<textarea name="' . esc_attr(self::OPTION_KEY) . '[customer_note_template]" rows="4" class="large-text">' . esc_textarea($settings['customer_note_template']) . '</textarea>';
        echo '<p class="description">Optional internal reminder shown in the editor panel. Manual edits still create standard private WooCommerce order notes.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button('Save Order Editor Settings');
        self::render_settings_script();
        echo '</form></div>';
    }

    public static function render_order_panel($post_or_order_object): void {
        $order = ($post_or_order_object instanceof \WC_Order)
            ? $post_or_order_object
            : wc_get_order($post_or_order_object->ID ?? 0);

        if (!$order instanceof \WC_Order) {
            echo '<p>Order not found.</p>';
            return;
        }

        if (!self::current_user_allowed()) {
            echo '<p>Order editor access is disabled for your account.</p>';
            return;
        }

        $locked_reason = self::locked_reason($order);
        $settings = self::settings();

        self::render_styles();

        if ($settings['customer_note_template'] !== '') {
            echo '<div class="notice notice-info inline"><p>' . esc_html($settings['customer_note_template']) . '</p></div>';
        }

        if ($locked_reason !== '') {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($locked_reason) . '</p></div>';
            self::render_readonly_summary($order);
            return;
        }

        echo '<div class="muse-oe-grid">';
        echo '<div>';
        self::render_line_items($order);
        self::render_add_item_form($order);
        echo '</div>';
        echo '<div>';
        self::render_metadata_form($order);
        echo '</div>';
        echo '</div>';
    }

    public static function handle_action(): void {
        $order_id = absint($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);

        if (!$order instanceof \WC_Order) {
            wp_die(esc_html__('Order not found.', 'musesys'), 404);
        }

        if (!self::current_user_allowed() || !current_user_can('edit_shop_order', $order_id)) {
            wp_die(esc_html__('Insufficient permissions.', 'musesys'), 403);
        }

        check_admin_referer(self::NONCE_ACTION . '_' . $order_id, 'musesys_order_editor_nonce');

        $locked_reason = self::locked_reason($order);
        if ($locked_reason !== '') {
            $order->add_order_note(sprintf('User #%d attempted blocked Wegmans order editor change: %s', get_current_user_id(), $locked_reason), false);
            self::redirect_to_order($order_id, 'locked');
        }

        $action = sanitize_key((string)($_POST['musesys_order_editor_action'] ?? ''));

        switch ($action) {
            case 'add_item':
                self::handle_add_item($order);
                break;
            case 'remove_item':
                self::handle_remove_item($order);
                break;
            case 'update_qty':
                self::handle_update_qty($order);
                break;
            case 'update_metadata':
                self::handle_update_metadata($order);
                break;
            case 'add_customer_note':
                self::handle_add_customer_note($order);
                break;
            default:
                wp_die(esc_html__('Unknown order editor action.', 'musesys'), 400);
        }

        self::redirect_to_order($order_id, 'updated');
    }

    private static function handle_add_item(\WC_Order $order): void {
        $product_id = absint($_POST['product_id'] ?? 0);
        $product = $product_id > 0 ? wc_get_product($product_id) : false;

        if (!$product) {
            $order->add_order_note(sprintf('User #%d attempted to add missing Item #%d through Wegmans order editor.', get_current_user_id(), $product_id), false);
            $order->save();
            return;
        }

        $item_id = $order->add_product($product, 1);
        $order->calculate_totals(false);
        $order->save();

        $order->add_order_note(sprintf(
            'User #%d added Item #%d (%s) as Order Item #%d with Qty 1 through Wegmans order editor.',
            get_current_user_id(),
            $product_id,
            $product->get_name(),
            (int)$item_id
        ), false);
        $order->save();
    }

    private static function handle_remove_item(\WC_Order $order): void {
        $item_id = absint($_POST['item_id'] ?? 0);
        $item = $order->get_item($item_id);

        if (!$item instanceof \WC_Order_Item_Product) {
            $order->add_order_note(sprintf('User #%d attempted to remove missing Order Item #%d through Wegmans order editor.', get_current_user_id(), $item_id), false);
            $order->save();
            return;
        }

        $product_id = $item->get_product_id();
        $name = $item->get_name();
        $qty = $item->get_quantity();

        $order->remove_item($item_id);
        $order->calculate_totals(false);
        $order->save();

        $order->add_order_note(sprintf(
            'User #%d removed Order Item #%d (Item #%d, %s, Qty %s) through Wegmans order editor.',
            get_current_user_id(),
            $item_id,
            $product_id,
            $name,
            wc_format_decimal((float)$qty)
        ), false);
        $order->save();
    }

    private static function handle_update_qty(\WC_Order $order): void {
        $item_id = absint($_POST['item_id'] ?? 0);
        $new_qty = wc_stock_amount(wp_unslash($_POST['quantity'] ?? 0));
        $item = $order->get_item($item_id);

        if (!$item instanceof \WC_Order_Item_Product || $new_qty < 0) {
            $order->add_order_note(sprintf('User #%d attempted invalid Qty update for Order Item #%d through Wegmans order editor.', get_current_user_id(), $item_id), false);
            $order->save();
            return;
        }

        $old_qty = $item->get_quantity();
        if ((float)$old_qty === (float)$new_qty) {
            return;
        }

        $item->set_quantity($new_qty);
        $item->save();
        $order->calculate_totals(false);
        $order->save();

        $order->add_order_note(sprintf(
            'User #%d updated Qty of Order Item #%d (Item #%d, %s) from %s to %s through Wegmans order editor.',
            get_current_user_id(),
            $item_id,
            $item->get_product_id(),
            $item->get_name(),
            wc_format_decimal((float)$old_qty),
            wc_format_decimal((float)$new_qty)
        ), false);
        $order->save();
    }

    private static function handle_update_metadata(\WC_Order $order): void {
        $changes = [];

        self::update_order_meta_if_changed($order, self::META_WO_GLOBAL, 'Global Work Order #', self::clean_text('global_wo'), $changes);
        self::update_order_meta_if_changed($order, self::META_STORE_GLOBAL, 'Global Install Store #', self::clean_store('install_store'), $changes);
        self::update_order_meta_if_changed($order, self::META_SHIP_STORE, 'Ship-To Store #', self::clean_store('ship_store'), $changes);
        self::update_order_meta_if_changed($order, self::META_SHIP_TO, 'Ship-To Location', self::clean_text('ship_to'), $changes);

        $shipping_fields = [
            'shipping_company' => ['Shipping Company', 'set_shipping_company', $order->get_shipping_company()],
            'shipping_address_1' => ['Shipping Address 1', 'set_shipping_address_1', $order->get_shipping_address_1()],
            'shipping_address_2' => ['Shipping Address 2', 'set_shipping_address_2', $order->get_shipping_address_2()],
            'shipping_city' => ['Shipping City', 'set_shipping_city', $order->get_shipping_city()],
            'shipping_state' => ['Shipping State', 'set_shipping_state', $order->get_shipping_state()],
            'shipping_postcode' => ['Shipping ZIP', 'set_shipping_postcode', $order->get_shipping_postcode()],
            'shipping_phone' => ['Shipping Phone', 'set_shipping_phone', method_exists($order, 'get_shipping_phone') ? $order->get_shipping_phone() : ''],
        ];

        foreach ($shipping_fields as $field => $config) {
            [$label, $setter, $old] = $config;
            $new = self::clean_text($field);
            if ((string)$old === $new) {
                continue;
            }
            if (method_exists($order, $setter)) {
                $order->{$setter}($new);
            }
            $changes[] = [$label, (string)$old, $new];
        }

        if (empty($changes)) {
            return;
        }

        $order->calculate_totals(false);
        $order->save();

        foreach ($changes as $change) {
            [$label, $old, $new] = $change;
            $order->add_order_note(sprintf(
                'User #%d updated %s from "%s" to "%s" through Wegmans order editor.',
                get_current_user_id(),
                $label,
                $old === '' ? '(blank)' : $old,
                $new === '' ? '(blank)' : $new
            ), false);
        }
        $order->save();
    }

    private static function handle_add_customer_note(\WC_Order $order): void {
        $note = sanitize_textarea_field((string)wp_unslash($_POST['customer_note'] ?? ''));
        if ($note === '') {
            return;
        }

        $order->add_order_note($note, true);
        $order->add_order_note(sprintf(
            'User #%d attached a standard customer note through Wegmans order editor.',
            get_current_user_id()
        ), false);
        $order->save();
    }

    private static function render_line_items(\WC_Order $order): void {
        echo '<h3>Line Items</h3>';
        echo '<table class="widefat striped muse-oe-lines"><thead><tr>';
        echo '<th>Item</th><th style="width:80px;">Product ID</th><th style="width:150px;">Qty</th><th style="width:110px;">Remove</th>';
        echo '</tr></thead><tbody>';

        foreach ($order->get_items() as $item_id => $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            echo '<tr>';
            echo '<td><strong>' . esc_html($item->get_name()) . '</strong><br><code>Order Item #' . esc_html((string)$item_id) . '</code></td>';
            echo '<td>' . esc_html((string)$item->get_product_id()) . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="muse-oe-inline">';
            self::render_action_fields($order, 'update_qty');
            echo '<input type="hidden" name="item_id" value="' . esc_attr((string)$item_id) . '">';
            echo '<input type="number" min="0" step="1" name="quantity" value="' . esc_attr((string)$item->get_quantity()) . '" class="small-text">';
            echo '<button type="submit" class="button">Update</button>';
            echo '</form>';
            echo '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            self::render_action_fields($order, 'remove_item');
            echo '<input type="hidden" name="item_id" value="' . esc_attr((string)$item_id) . '">';
            echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Remove this item from the order?\')">Remove</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function render_add_item_form(\WC_Order $order): void {
        echo '<h3>Add Item</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="muse-oe-add">';
        self::render_action_fields($order, 'add_item');
        echo '<label for="muse_oe_product_id">Item ID #</label> ';
        echo '<input id="muse_oe_product_id" type="number" min="1" step="1" name="product_id" class="regular-text" required> ';
        echo '<button type="submit" class="button button-primary">Add</button>';
        echo '</form>';
    }

    private static function render_metadata_form(\WC_Order $order): void {
        echo '<h3>Metadata</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="muse-oe-meta">';
        self::render_action_fields($order, 'update_metadata');
        self::render_text_field('global_wo', 'Work Order # / Global WO #', $order->get_meta(self::META_WO_GLOBAL));
        self::render_text_field('install_store', 'Install Store Number', $order->get_meta(self::META_STORE_GLOBAL));
        self::render_text_field('ship_store', 'Ship-To Store Number', $order->get_meta(self::META_SHIP_STORE));
        self::render_text_field('ship_to', 'Ship-To Location', $order->get_meta(self::META_SHIP_TO));

        echo '<h4>Ship-To Details</h4>';
        self::render_text_field('shipping_company', 'Company / Location Name', $order->get_shipping_company());
        self::render_text_field('shipping_address_1', 'Address 1', $order->get_shipping_address_1());
        self::render_text_field('shipping_address_2', 'Address 2', $order->get_shipping_address_2());
        self::render_text_field('shipping_city', 'City', $order->get_shipping_city());
        self::render_text_field('shipping_state', 'State', $order->get_shipping_state());
        self::render_text_field('shipping_postcode', 'ZIP', $order->get_shipping_postcode());
        self::render_text_field('shipping_phone', 'Phone', method_exists($order, 'get_shipping_phone') ? $order->get_shipping_phone() : '');

        echo '<p><button type="submit" class="button button-primary">Update Metadata</button></p>';
        echo '</form>';

        $settings = self::settings();
        echo '<h3>Customer Note</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="muse-oe-meta">';
        self::render_action_fields($order, 'add_customer_note');
        echo '<p><label for="muse_oe_customer_note"><strong>Customer Note</strong></label><br>';
        echo '<textarea id="muse_oe_customer_note" name="customer_note" rows="4" class="widefat">' . esc_textarea($settings['customer_note_template']) . '</textarea></p>';
        echo '<p><button type="submit" class="button">Add Customer Note</button></p>';
        echo '</form>';
    }

    private static function render_readonly_summary(\WC_Order $order): void {
        echo '<p><strong>Status:</strong> ' . esc_html(wc_get_order_status_name($order->get_status())) . '</p>';
        echo '<p><strong>TRX:</strong> ' . esc_html((string)$order->get_meta('_trx_order_number')) . '</p>';
        echo '<p><strong>Shipping Manager Tracking:</strong> ' . esc_html(self::shipping_manager_tracking_display($order)) . '</p>';
    }

    private static function render_action_fields(\WC_Order $order, string $action): void {
        wp_nonce_field(self::NONCE_ACTION . '_' . $order->get_id(), 'musesys_order_editor_nonce');
        echo '<input type="hidden" name="action" value="musesys_order_editor_action">';
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string)$order->get_id()) . '">';
        echo '<input type="hidden" name="musesys_order_editor_action" value="' . esc_attr($action) . '">';
    }

    private static function render_text_field(string $name, string $label, $value): void {
        echo '<p><label for="muse_oe_' . esc_attr($name) . '"><strong>' . esc_html($label) . '</strong></label><br>';
        echo '<input id="muse_oe_' . esc_attr($name) . '" type="text" name="' . esc_attr($name) . '" value="' . esc_attr((string)$value) . '" class="widefat"></p>';
    }

    private static function render_styles(): void {
        echo '<style>
            .muse-oe-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(280px,.9fr);gap:18px;align-items:start}
            .muse-oe-inline{display:flex;gap:6px;align-items:center}
            .muse-oe-lines td{vertical-align:middle}
            .muse-oe-meta h4{margin:16px 0 6px;border-top:1px solid #dcdcde;padding-top:12px}
            @media(max-width:960px){.muse-oe-grid{grid-template-columns:1fr}}
        </style>';
    }

    private static function render_settings_styles(): void {
        echo '<style>
            .muse-oe-saved{max-width:920px}
            .muse-oe-user-picker{display:grid;grid-template-columns:minmax(260px,1fr) 52px minmax(260px,1fr);gap:12px;align-items:center;max-width:920px}
            .muse-oe-user-pane label{display:block;margin-bottom:6px}
            .muse-oe-user-pane select{width:100%;min-height:250px}
            .muse-oe-user-controls{display:flex;flex-direction:column;gap:8px;align-items:stretch}
            .muse-oe-user-controls .button{font-weight:700}
            @media(max-width:782px){
                .muse-oe-user-picker{grid-template-columns:1fr}
                .muse-oe-user-controls{flex-direction:row;justify-content:flex-start}
            }
        </style>';
    }

    private static function render_settings_script(): void {
        ?>
        <script>
        (function(){
            var pool = document.getElementById('muse_oe_user_pool');
            var allowed = document.getElementById('muse_oe_allowed_users');
            var add = document.getElementById('muse_oe_allow_user');
            var remove = document.getElementById('muse_oe_remove_user');
            var form = allowed ? allowed.closest('form') : null;

            function moveSelected(from, to) {
                if (!from || !to) return;
                Array.prototype.slice.call(from.selectedOptions).forEach(function(option){
                    option.selected = false;
                    to.appendChild(option);
                });
                sortOptions(from);
                sortOptions(to);
            }

            function sortOptions(select) {
                var options = Array.prototype.slice.call(select.options);
                options.sort(function(a, b){
                    return a.text.localeCompare(b.text, undefined, {numeric: true, sensitivity: 'base'});
                });
                options.forEach(function(option){ select.appendChild(option); });
            }

            if (add) add.addEventListener('click', function(){ moveSelected(pool, allowed); });
            if (remove) remove.addEventListener('click', function(){ moveSelected(allowed, pool); });
            if (form && allowed) {
                form.addEventListener('submit', function(){
                    Array.prototype.forEach.call(allowed.options, function(option){
                        option.selected = true;
                    });
                });
            }
        })();
        </script>
        <?php
    }

    private static function access_summary(array $settings): string {
        $role_labels = [];
        $roles = wp_roles()->roles;
        foreach ($settings['allowed_roles'] as $role_key) {
            $role_labels[] = isset($roles[$role_key]) ? translate_user_role($roles[$role_key]['name']) : $role_key;
        }

        $role_text = empty($role_labels) ? 'no roles' : implode(', ', $role_labels);
        $user_count = count($settings['allowed_users']);

        return sprintf('%s; %d explicitly allowed user%s.', $role_text, $user_count, $user_count === 1 ? '' : 's');
    }

    private static function update_order_meta_if_changed(\WC_Order $order, string $key, string $label, string $new, array &$changes): void {
        $old = (string)$order->get_meta($key);
        if ($old === $new) {
            return;
        }

        $order->update_meta_data($key, $new);
        $changes[] = [$label, $old, $new];
    }

    private static function locked_reason(\WC_Order $order): string {
        $trx = trim((string)$order->get_meta('_trx_order_number'));
        $has_shipping_tracking = self::has_shipping_manager_tracking($order);

        if ($order->has_status('completed') && $trx !== '' && $has_shipping_tracking) {
            return 'This order is completed, has a TRX Order #, and has shipping-manager tracking; it cannot be edited through the Wegmans order editor.';
        }

        return '';
    }

    private static function current_user_allowed(): bool {
        if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
            return false;
        }

        $settings = self::settings();
        if ($settings['enabled'] !== 'yes') {
            return false;
        }

        $user = wp_get_current_user();
        if (in_array((int)$user->ID, $settings['allowed_users'], true)) {
            return true;
        }

        foreach ((array)$user->roles as $role) {
            if (in_array((string)$role, $settings['allowed_roles'], true)) {
                return true;
            }
        }

        return empty($settings['allowed_users']) && empty($settings['allowed_roles']) && current_user_can('manage_woocommerce');
    }

    private static function settings(): array {
        $stored = get_option(self::OPTION_KEY, []);
        $settings = wp_parse_args(is_array($stored) ? $stored : [], self::DEFAULT_SETTINGS);
        $settings['allowed_users'] = array_values(array_filter(array_map('absint', (array)$settings['allowed_users'])));
        $settings['allowed_roles'] = array_values(array_filter(array_map('sanitize_key', (array)$settings['allowed_roles'])));
        $settings['customer_note_template'] = (string)$settings['customer_note_template'];
        $settings['enabled'] = $settings['enabled'] === 'no' ? 'no' : 'yes';

        return $settings;
    }

    private static function hpos_orders_screen_enabled(): bool {
        return class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')
            && function_exists('wc_get_container')
            && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled();
    }

    private static function has_shipping_manager_tracking(\WC_Order $order): bool {
        return self::shipping_manager_tracking_display($order) !== '';
    }

    private static function shipping_manager_tracking_display(\WC_Order $order): string {
        $shipments = json_decode((string)$order->get_meta('_muse_shipments', true), true);
        if (!is_array($shipments)) {
            return '';
        }

        $tracking_numbers = [];
        foreach ($shipments as $shipment) {
            if (empty($shipment['tracking_numbers']) || !is_array($shipment['tracking_numbers'])) {
                continue;
            }

            foreach ($shipment['tracking_numbers'] as $tracking_number) {
                $tracking_number = trim((string)$tracking_number);
                if ($tracking_number !== '') {
                    $tracking_numbers[] = $tracking_number;
                }
            }
        }

        $tracking_numbers = array_values(array_unique($tracking_numbers));

        return implode(', ', $tracking_numbers);
    }

    private static function clean_text(string $key): string {
        return sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
    }

    private static function clean_store(string $key): string {
        $raw = preg_replace('/\D+/', '', (string)wp_unslash($_POST[$key] ?? ''));
        return $raw === '' ? '' : sprintf('%03d', (int)$raw);
    }

    private static function redirect_to_order(int $order_id, string $result): void {
        $order = wc_get_order($order_id);
        $url = $order instanceof \WC_Order ? $order->get_edit_order_url() : admin_url('post.php?post=' . $order_id . '&action=edit');
        wp_safe_redirect(add_query_arg(['musesys_order_editor' => $result], $url));
        exit;
    }
}

if (class_exists('WooCommerce') && class_exists('WC_Order')) {
    MuseSys_Wegmans_Order_Editor::boot();
} else {
    add_action('plugins_loaded', function (): void {
        if (class_exists('WooCommerce') && class_exists('WC_Order')) {
            MuseSys_Wegmans_Order_Editor::boot();
        }
    }, 20);
}
