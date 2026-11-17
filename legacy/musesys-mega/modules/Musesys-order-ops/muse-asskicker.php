<?php
/**
 * Plugin Name: MuseSys – Order Ops Pack (Wegmans)
 * Description: Fixes ZIP/name in notifications, converts Install Store # text?dropdown (000–200) at your existing additional field, adds Technician Phone, hides cart-line comments (cart view only), redirects Shop?Parts, importer part-number mapping + duplicate prevention, catalog cleanup tools, removes Manuals, favorites star, shortcut bar sizing + Requests/Orders. (Billing lock moved to MU-plugin).
 * Author: MuseSystems
 * Version: 2.1.1
 */

if (!defined('ABSPATH')) { exit; }

final class MuseSys_Order_Ops {
    const VERSION = '2.1.1';

    // Config
    private static $PARTS_SLUG   = 'parts';
    private static $PARTNO_META  = 'muse_part_number';
    private static $REQUESTS_URL = '/my-account/musesys-favorites/requests/';
    private static $ORDERS_URL   = '/my-account/orders/';

    // Your existing custom field key (from screenshot/markup)
    private static $INSTALL_STORE_KEY = 'additional_install_store_number';
    private static $INSTALL_MAX       = 200; // inclusive upper bound

    public static function boot(): void {
        // Frontend assets
        add_action('wp_enqueue_scripts', [__CLASS__, 'inline_css']);

        // Convert the existing Install Store # field to a <select> (late to beat field editors)
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'ensure_install_store_dropdown'], 999);

        // Coerce args in case a field editor stomps types
        add_filter('woocommerce_form_field_args', [__CLASS__, 'coerce_install_store_args'], 9999, 3);

        // Final hammer: if it still renders as text, output a select
        add_filter('woocommerce_form_field_text', [__CLASS__, 'override_text_to_select'], 9999, 4);

        // Technician phone field
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'checkout_add_technician_phone'], 30);

        // Save & show custom fields
        add_action('woocommerce_checkout_update_order_meta', [__CLASS__, 'save_checkout_custom_fields'], 10, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'admin_show_custom_fields']);
        add_filter('woocommerce_email_order_meta_fields', [__CLASS__, 'email_meta_custom_fields'], 10, 3);

        // Email address formatting fixes
        add_filter('woocommerce_formatted_address_replacements', [__CLASS__, 'fix_email_address_name_zip'], 10, 2);

        // Cart-only: hide per-line item comments
        add_filter('woocommerce_get_item_data', [__CLASS__, 'strip_cart_line_comments'], 10, 2);

        // Shop ? parts
        add_action('template_redirect', [__CLASS__, 'redirect_shop_to_parts']);

        // Menus / shortcuts
        add_filter('wp_nav_menu_objects', [__CLASS__, 'remove_manuals_menu_item'], 10, 2);
        add_shortcode('musesys_shortcuts', [__CLASS__, 'shortcode_shortcuts']);

        // Importer dedupe by part number
        add_filter('woocommerce_product_importer_mapping_options', [__CLASS__, 'importer_map_option']);
        add_filter('woocommerce_product_importer_mapping_default_columns', [__CLASS__, 'importer_default_columns']);
        add_filter('woocommerce_product_importer_parsed_data', [__CLASS__, 'importer_capture_partno'], 10, 2);
        add_filter('woocommerce_product_import_pre_insert_product_object', [__CLASS__, 'import_prevent_duplicate_partno'], 10, 2);

        // Catalog tools
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_post_musesys_catalog_export', [__CLASS__, 'handle_export']);
        add_action('admin_post_musesys_catalog_dedupe', [__CLASS__, 'handle_dedupe']);
    }

    /* ===== CSS ===== */
    public static function inline_css(): void {
        $css = "
        .muse-fav-toggle, .musesys-favorite-toggle, .favorite-toggle {
            width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center;
            border:1px solid #ddd; border-radius:50%; background:#fff; cursor:pointer;
        }
        .muse-fav-toggle::before, .musesys-favorite-toggle::before, .favorite-toggle::before { content:'?'; font-size:18px; line-height:1; }
        .muse-fav-toggle.active::before, .musesys-favorite-toggle.active::before, .favorite-toggle.active::before { content:'?'; }
        .musesys-shortcut-bar { display:flex; gap:.5rem; flex-wrap:wrap; margin:.5rem 0 1rem; }
        .musesys-shortcut-bar a { padding:.5rem .75rem; font-weight:600; border:1px solid #ddd; border-radius:6px; background:#fff; text-decoration:none; }
        .menu-item a[href*='/manual'], .menu-item a[href*='/manuals'] { display:none !important; }
        ";
        wp_register_style('musesys-order-ops', false, [], self::VERSION);
        wp_enqueue_style('musesys-order-ops');
        wp_add_inline_style('musesys-order-ops', $css);
    }

    /* ===== Install Store # dropdown: 000–200 ===== */

    private static function options_install_store(): array {
        $opts = ['' => __('Select Store #','musesys')];
        $opts['000'] = '000';
        for ($i = 1; $i <= (int) self::$INSTALL_MAX; $i++) {
            $opts[sprintf('%03d',$i)] = sprintf('%03d',$i);
        }
        return $opts;
    }

    public static function ensure_install_store_dropdown(array $fields): array {
        $k = self::$INSTALL_STORE_KEY;
        foreach (['additional','order','billing','shipping','account'] as $group) {
            if (isset($fields[$group][$k])) {
                $f = $fields[$group][$k];
                $f['type']        = 'select';
                $f['options']     = self::options_install_store();
                $f['required']    = true;
                $f['class']       = ['form-row-wide'];
                
                // Fixed array handling
                $existing = is_array($f['input_class'] ?? null) ? $f['input_class'] : [];
                $f['input_class'] = array_unique(array_merge($existing, ['musesys-store-number']));
                
                $f['id']          = $k;
                $f['label']       = $f['label'] ?? __('Install Store #','musesys');
                $f['placeholder'] = __('Install Store #','musesys');
                $fields[$group][$k] = $f;
                break;
            }
        }
        return $fields;
    }

    public static function coerce_install_store_args(array $args, string $key, $value = null): array {
        if ($key === self::$INSTALL_STORE_KEY) {
            $args['type'] = 'select';
            $args['options'] = self::options_install_store();
        }
        return $args;
    }

    public static function override_text_to_select(string $field, string $key, array $args, $value = null): string {
        if ($key === self::$INSTALL_STORE_KEY) {
            $args['type'] = 'select'; // Ensure type is forced
            $args['options'] = self::options_install_store();
            // Re-render as select
            return woocommerce_form_field($key, $args, $value);
        }
        return $field;
    }

    public static function checkout_add_technician_phone(array $fields): array {
        $fields['billing']['_musesys_technician_phone'] = [
            'label'       => __('Technician phone', 'musesys'),
            'placeholder' => _x('Phone', 'placeholder', 'musesys'),
            'required'    => false,
            'class'       => ['form-row-wide'],
            'clear'       => true,
            'priority'    => 35,
        ];
        return $fields;
    }

    public static function save_checkout_custom_fields(int $order_id, $data = null): void {
        $order = wc_get_order($order_id);
        if (!$order) return;

        if (isset($_POST[self::$INSTALL_STORE_KEY])) {
             $order->update_meta_data(self::$INSTALL_STORE_KEY, sanitize_text_field($_POST[self::$INSTALL_STORE_KEY]));
        }
        if (isset($_POST['_musesys_technician_phone'])) {
             $order->update_meta_data('_musesys_technician_phone', sanitize_text_field($_POST['_musesys_technician_phone']));
        }
        $order->save();
    }

    public static function admin_show_custom_fields(\WC_Order $order): void {
        $store = $order->get_meta(self::$INSTALL_STORE_KEY);
        $phone = $order->get_meta('_musesys_technician_phone');
        echo '<p><strong>' . __('Install Store #') . ':</strong> ' . esc_html((string)$store) . '</p>';
        echo '<p><strong>' . __('Technician Phone') . ':</strong> ' . esc_html((string)$phone) . '</p>';
    }

    public static function email_meta_custom_fields(array $fields, bool $sent_to_admin, $order): array {
        $fields[self::$INSTALL_STORE_KEY] = [
            'label' => __('Install Store #','musesys'),
            'value' => $order->get_meta(self::$INSTALL_STORE_KEY),
        ];
        $fields['_musesys_technician_phone'] = [
            'label' => __('Technician phone','musesys'),
            'value' => $order->get_meta('_musesys_technician_phone'),
        ];
        return $fields;
    }

    /* ===== Email address formatting ===== */
    public static function fix_email_address_name_zip(array $replacements, array $args): array {
        $first_name = $args['first_name'] ?? '';
        $last_name = $args['last_name'] ?? '';
        
        if (!empty($first_name) && !empty($last_name) && preg_match('/(\d{3})/', (string) $last_name, $m)) {
            $replacements['{name}'] = trim((string)$first_name) . ' (#' . $m[1] . ')';
        }
        
        if (!empty($args['country']) && strtoupper((string)($args['country'] ?? '')) === 'US') {
            $replacements['{city}']     = trim((string)($args['city'] ?? ''));
            $replacements['{state}']    = strtoupper(trim((string)($args['state'] ?? '')));
            $replacements['{postcode}'] = trim((string)($args['postcode'] ?? ''));
        }
        return $replacements;
    }

    /* ===== Cart-only comment stripping ===== */
    public static function strip_cart_line_comments(array $item_data, array $cart_item): array {
        if (!is_cart()) return $item_data;
        
        $filtered = [];
        foreach ($item_data as $row) {
            $label = isset($row['key']) ? mb_strtolower((string)$row['key']) : '';
            if (in_array($label, ['comment','item comment','line comment','note'], true)) {
                continue;
            }
            $filtered[] = $row;
        }
        return $filtered;
    }

    /* ===== Shop ? Parts ===== */
    public static function redirect_shop_to_parts(): void {
        if (function_exists('is_shop') && is_shop()) {
            $term = get_term_by('slug', self::$PARTS_SLUG, 'product_cat');
            if ($term instanceof \WP_Term) {
                $link = get_term_link($term);
                if (!is_wp_error($link)) {
                    wp_safe_redirect($link, 302);
                    exit;
                }
            }
        }
    }

    /* ===== Menus / Shortcuts ===== */
    public static function remove_manuals_menu_item(array $items, $args): array {
        foreach ($items as $i => $item) {
            if (isset($item->title) && strcasecmp(trim((string)$item->title), 'Manuals') === 0) {
                unset($items[$i]);
            }
        }
        return array_values($items);
    }

    public static function shortcode_shortcuts(array $atts = []): string {
        $req = esc_url(self::$REQUESTS_URL);
        $ord = esc_url(self::$ORDERS_URL);
        return '<nav class="musesys-shortcut-bar"><a href="'.$req.'">'.esc_html__('Requests','musesys').'</a><a href="'.$ord.'">'.esc_html__('Orders','musesys').'</a></nav>';
    }

    /* ===== Importer / Dedupe ===== */
    public static function importer_map_option(array $options): array { 
        $options['part_number'] = __('Part number','musesys'); 
        return $options; 
    }
    
    public static function importer_default_columns(array $columns): array { 
        $columns['part_number'] = 'part_number'; 
        return $columns; 
    }
    
    public static function importer_capture_partno(array $parsed_data, array $raw_data): array {
        if (!empty($parsed_data['part_number'])) {
            $pn = trim((string)$parsed_data['part_number']);
            if (!isset($parsed_data['meta_data'])) {
                $parsed_data['meta_data'] = [];
            }
            $parsed_data['meta_data'][] = ['key' => self::$PARTNO_META, 'value' => $pn];
        }
        return $parsed_data;
    }
    
    public static function import_prevent_duplicate_partno($product, array $data) {
        $existing_id = 0; 
        $partno = '';
        
        if (!empty($data['meta_data'])) {
            foreach ($data['meta_data'] as $md) {
                if (($md['key'] ?? '') === self::$PARTNO_META) { 
                    $partno = trim((string)$md['value']); 
                    break; 
                }
            }
        }
        
        if ($partno !== '') {
            $existing_id = self::find_product_id_by_meta(self::$PARTNO_META, $partno);
        }
        
        if (!$existing_id && !empty($data['sku'])) {
            $existing_id = wc_get_product_id_by_sku($data['sku']);
        }
        
        if ($existing_id) {
            $product->set_id($existing_id);
        }
        
        return $product;
    }
    
    private static function find_product_id_by_meta(string $key, string $value): int {
        $q = new \WP_Query([
            'post_type' => 'product',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => $key,
                    'value' => $value,
                    'compare' => '='
                ]
            ]
        ]);
        return $q->posts[0] ?? 0;
    }

    /* ===== Catalog tools ===== */
    public static function admin_menu(): void {
        add_submenu_page(
            'musesys',
            'MuseSys Catalog Tools',
            'Catalog Tools',
            'manage_woocommerce',
            'musesys-catalog-tools',
            [__CLASS__,'render_tools_page']
        );
    }
    
    public static function render_tools_page(): void {
        if (!current_user_can('manage_woocommerce')) return;
        
        $export_url = wp_nonce_url(admin_url('admin-post.php?action=musesys_catalog_export'), 'musesys_export');
        $dedupe_url = wp_nonce_url(admin_url('admin-post.php?action=musesys_catalog_dedupe'), 'musesys_dedupe');
        
        echo '<div class="wrap"><h1>MuseSys Catalog Tools</h1><p>Export products to CSV (sorted by Part Number, then SKU), then optionally dedupe the catalog by Part Number.</p><p><a class="button button-primary" href="'.esc_url($export_url).'">Export CSV</a> <a class="button" href="'.esc_url($dedupe_url).'" onclick="return confirm(\'This will trash duplicate products that share the same Part Number. Continue?\')">Dedupe by Part Number</a></p></div>';
    }
    
    public static function handle_export(): void {
        if (!current_user_can('manage_woocommerce') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'musesys_export')) {
            wp_die('Nope.');
        }
        
        $rows = [];
        $ids = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => ['publish','draft','pending','private']
        ]);
        
        foreach ($ids as $id) {
            $p = wc_get_product($id); 
            if (!$p) continue;
            
            $rows[] = [
                'id' => $id,
                'name' => $p->get_name(),
                'sku' => $p->get_sku(),
                'part_number' => get_post_meta($id, self::$PARTNO_META, true),
                'status' => $p->get_status(),
                'type' => $p->get_type()
            ];
        }
        
        usort($rows, function($a,$b){
            $pa = strtoupper((string)($a['part_number'] ?? '')); 
            $pb = strtoupper((string)($b['part_number'] ?? ''));
            if ($pa === $pb) {
                return strnatcasecmp((string)($a['sku'] ?? ''), (string)($b['sku'] ?? ''));
            }
            return strnatcasecmp($pa, $pb);
        });
        
        $upload_dir = wp_upload_dir(); 
        $file = trailingslashit($upload_dir['basedir']) . 'musesys_catalog_export.csv';
        $fh = fopen($file, 'w');
        
        $header = ['id','name','sku','part_number','status','type'];
        fputcsv($fh, $header, ',', '"', '\\');
        
        foreach ($rows as $r) {
            $row = [$r['id'], $r['name'], $r['sku'], $r['part_number'], $r['status'], $r['type']];
            fputcsv($fh, $row, ',', '"', '\\');
        }
        
        fclose($fh);
        wp_safe_redirect($upload_dir['baseurl'] . '/musesys_catalog_export.csv'); 
        exit;
    }
    
    public static function handle_dedupe(): void {
        if (!current_user_can('manage_woocommerce') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'musesys_dedupe')) {
            wp_die('Nope.');
        }
        
        $seen = []; 
        $trashed = 0;
        $ids = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => ['publish','draft','pending','private']
        ]);
        
        foreach ($ids as $id) {
            $pn = trim((string)get_post_meta($id, self::$PARTNO_META, true));
            if ($pn === '') continue;
            
            if (!isset($seen[$pn])) { 
                $seen[$pn] = $id; 
                continue; 
            }
            
            wp_trash_post($id); 
            $trashed++;
        }
        
        wp_safe_redirect(add_query_arg([
            'page' => 'musesys-catalog-tools',
            'musesys_dedupe_done' => $trashed
        ], admin_url('admin.php'))); 
        exit;
    }
}

// Initialize on plugins_loaded to ensure WooCommerce is available
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        MuseSys_Order_Ops::boot();
    }
}, 10);

// HPOS Compatibility (Must run before plugins_loaded)
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});