<?php
/**
 * Plugin Name: MuseSys Activity Tracker (Legacy 7.4)
 * Description: PHP 7.4–compatible tracker for logins, frontend/woo activity, admin watchdog events, and CSV export.
 * Version: 1.1.3-legacy
 * Author: MuseSystems
 * Requires PHP: 7.4
 * Requires at least: 5.9
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

final class MuseSys_Activity_Tracker_Legacy {
    const VERSION     = '1.1.3-legacy';
    const DB_LOGINS   = 'musesys_login_log';
    const DB_ACTIVITY = 'musesys_activity_log';
    const DB_FLAGS    = 'musesys_login_flags_legacy';
    const OPTION_VER  = 'musesys_activity_tracker_legacy_ver';

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('plugins_loaded', array($this, 'maybe_upgrade'), 1);

        // Core event hooks
        add_action('wp_login',          array($this, 'log_login'), 10, 2);
        add_action('wp_login_failed',   array($this, 'log_login_failed'), 10, 1);
        add_action('clear_auth_cookie', array($this, 'log_logout'));
        add_action('template_redirect', array($this, 'track_page_view'));

        // Woo events (frontend)
        add_action('plugins_loaded', function () {
            if (class_exists('WooCommerce')) {
                add_action('woocommerce_add_to_cart', array($this, 'log_add_to_cart'), 10, 6);
                add_action('woocommerce_thankyou',    array($this, 'log_order_thankyou'), 10, 1);
            }
        }, 20);

        // Menus
        add_action('admin_menu', array($this, 'admin_menu_top'), 20);

        // Admin bar quick link
        add_action('admin_bar_menu', array($this, 'admin_bar_link'), 80);

        // Daily shared-login flag scan
        add_action('musesys_daily_flag_scan_legacy', array($this, 'run_flag_scan'));
        if (!wp_next_scheduled('musesys_daily_flag_scan_legacy')) {
            wp_schedule_event(time() + 300, 'daily', 'musesys_daily_flag_scan_legacy');
        }

        // Capability notice
        add_action('admin_notices', array($this, 'maybe_capability_notice'));

        // Admin watchdog hooks (backend actions)
        add_action('woocommerce_order_status_changed', array($this,'adm_order_status'), 10, 4);
        add_action('woocommerce_new_order_note',      array($this,'adm_order_note'), 10, 2);
        add_action('woocommerce_refund_created',      array($this,'adm_refund_created'), 10, 2);
        add_action('save_post_shop_order',            array($this,'adm_order_saved'), 20, 2);
        add_action('save_post_product',               array($this,'adm_product_saved'), 20, 2);
        add_action('user_register',                   array($this,'adm_user_created'), 10, 1);
        add_action('profile_update',                  array($this,'adm_user_updated'), 10, 2);
        add_action('set_user_role',                   array($this,'adm_user_role'), 10, 3);
        add_action('delete_user',                     array($this,'adm_user_deleted'), 10, 1);
        add_action('activated_plugin',                array($this,'adm_plugin_activated'), 10, 2);
        add_action('deactivated_plugin',              array($this,'adm_plugin_deactivated'), 10, 2);
        add_action('updated_option',                  array($this,'adm_option_updated'), 10, 3);

        // CSV export endpoint
        add_action('admin_post_musesys_export_csv', array($this,'handle_export_csv'));
    }

    /* =========================
       Activation / Upgrade
       ========================= */
    public function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $logins  = $wpdb->prefix . self::DB_LOGINS;
        $activity= $wpdb->prefix . self::DB_ACTIVITY;
        $flags   = $wpdb->prefix . self::DB_FLAGS;

        // LONGTEXT instead of JSON for 7.4-safe MySQL spread
        $sql1 = "CREATE TABLE $logins (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            username VARCHAR(190) NULL,
            event ENUM('login','login_failed','logout') NOT NULL,
            ip VARBINARY(16) NULL,
            user_agent TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_event (event),
            KEY idx_time (created_at)
        ) $charset;";

        $sql2 = "CREATE TABLE $activity (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            event VARCHAR(64) NOT NULL,
            object_type VARCHAR(64) NULL,
            object_id BIGINT UNSIGNED NULL,
            details LONGTEXT NULL,
            ip VARBINARY(16) NULL,
            user_agent TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_event (event),
            KEY idx_obj (object_type, object_id),
            KEY idx_time (created_at)
        ) $charset;";

        $sql3 = "CREATE TABLE $flags (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            window_hours INT NOT NULL DEFAULT 24,
            distinct_ip_count INT NOT NULL,
            threshold INT NOT NULL,
            sample LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            resolved TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_time (created_at)
        ) $charset;";

        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);

        update_option(self::OPTION_VER, self::VERSION);
    }

    public function maybe_upgrade() {
        $ver = get_option(self::OPTION_VER);
        if ($ver !== self::VERSION) {
            $this->activate();
        }
    }

    /* =========================
       Caps & Menus
       ========================= */
    private function admin_cap() {
        return class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
    }

    public function admin_menu_top() {
        $cap = $this->admin_cap();
        add_submenu_page(
            'musesys',
            'MuseSys Activity (Legacy)',
            'Activity (Legacy)',
            $cap,
            'musesys-activity-legacy',
            array($this, 'render_main_page')
        );
        // Hidden subpages with parent = null so direct URLs/bookmarks resolve cleanly without permission errors
        add_submenu_page(null, 'Dashboard',       'Dashboard',       $cap, 'musesys-activity-legacy-dashboard', array($this, 'render_dashboard'));
        add_submenu_page(null, 'Logins',          'Logins',          $cap, 'musesys-activity-legacy-logins',    array($this, 'render_logins'));
        add_submenu_page(null, 'Activity Logs',   'Activity Logs',   $cap, 'musesys-activity-legacy-events',    array($this, 'render_activity'));
        add_submenu_page(null, 'Flags',           'Flags',           $cap, 'musesys-activity-legacy-flags',     array($this, 'render_flags'));
        add_submenu_page(null, 'Admin Activity',  'Admin Activity',  $cap, 'musesys-activity-legacy-admin',     array($this, 'render_admin_activity'));
    }

    public function render_main_page() {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'activity';
        switch ($tab) {
            case 'dashboard':
                $this->render_dashboard();
                break;
            case 'logins':
                $this->render_logins();
                break;
            case 'flags':
                $this->render_flags();
                break;
            case 'admin':
                $this->render_admin_activity();
                break;
            case 'activity':
            default:
                $this->render_activity();
                break;
        }
    }

    public function render_tabs($active = 'activity') {
        $tabs = array(
            'activity'  => 'Activity Logs',
            'dashboard' => 'Dashboard',
            'logins'    => 'Logins',
            'flags'     => 'Flags',
            'admin'     => 'Admin Watchdog',
        );
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:18px;">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(array('page' => 'musesys-activity-legacy', 'tab' => $key), admin_url('admin.php'));
            $class = ($key === $active) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    public function admin_menu_woo() {
        if (!class_exists('WooCommerce')) return;
        $cap = $this->admin_cap();
        // Disabled per MuseSys architecture rule (all menus under MuseSys submenu)
    }

    public function admin_bar_link($bar) {
        if (!is_admin() || !current_user_can($this->admin_cap())) return;
        $bar->add_node(array(
            'id'    => 'musesys-activity-legacy',
            'title' => 'MuseSys Activity (Legacy)',
            'href'  => admin_url('admin.php?page=musesys-activity-legacy'),
            'meta'  => array('class' => 'musesys-activity-legacy-node')
        ));
    }

    public function maybe_capability_notice() {
        if (!is_admin()) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return;
        $pages = array(
            'toplevel_page_musesys-activity-legacy',
            'musesys_page_musesys-activity-legacy',
            'musesys-activity-legacy_page_musesys-activity-legacy-logins',
            'musesys-activity-legacy_page_musesys-activity-legacy-events',
            'musesys-activity-legacy_page_musesys-activity-legacy-flags',
            'musesys-activity-legacy_page_musesys-activity-legacy-admin',
            'admin_page_musesys-activity-legacy-logins',
            'admin_page_musesys-activity-legacy-events',
            'admin_page_musesys-activity-legacy-flags',
            'admin_page_musesys-activity-legacy-admin'
        );
        if (in_array($screen->id, $pages, true) && !current_user_can($this->admin_cap())) {
            echo '<div class="notice notice-error"><p><strong>MuseSys Activity Tracker (Legacy):</strong> insufficient capability. Required: <code>'
                 . esc_html($this->admin_cap()) . '</code>.</p></div>';
        }
    }

    /* =========================
       Utilities
       ========================= */
    private function now() { return current_time('mysql'); }

    private function client_ip_bin() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        if (!$ip) return null;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))  return @inet_pton($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))  return @inet_pton($ip);
        return null;
    }

    private function user_agent() {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
        return substr($ua, 0, 65500);
    }

    private function is_key_page() {
        if (function_exists('is_product') && is_product()) { global $post; return array('view_product', 'product', (int)($post && isset($post->ID) ? $post->ID : 0)); }
        if (function_exists('is_cart') && is_cart())       { return array('view_cart', 'page', 0); }
        if (function_exists('is_checkout') && is_checkout()){ return array('view_checkout', 'page', 0); }
        if (function_exists('is_account_page') && is_account_page()) { return array('view_account', 'page', 0); }
        return array(null, null, 0);
    }

    private function ip_to_text($bin) {
        if (!$bin) return '';
        $txt = @inet_ntop($bin);
        return $txt ? $txt : '';
    }

    private function jenc($value) {
        return wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function current_actor_tuple() {
        $u = wp_get_current_user();
        return array(
            'id' => ($u && $u->ID) ? (int)$u->ID : 0,
            'name' => ($u && $u->user_login) ? $u->user_login : '',
        );
    }

    private function get_screen_id_safe() {
        if (function_exists('get_current_screen')) {
            $s = get_current_screen();
            if ($s && isset($s->id)) return (string)$s->id;
        }
        return '';
    }

    private function log_admin_event($event, $object_type, $object_id, $details_arr) {
        $actor = $this->current_actor_tuple();
        $ref   = isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : '';
        $det   = array_merge(array(
            '_actor'  => $actor['name'],
            '_screen' => $this->get_screen_id_safe(),
            '_referer'=> $ref,
            '_method' => isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '',
        ), is_array($details_arr) ? $details_arr : array());

        global $wpdb;
        $wpdb->insert($wpdb->prefix . self::DB_ACTIVITY, array(
            'user_id'    => $actor['id'],
            'event'      => (string)$event,
            'object_type'=> (string)$object_type,
            'object_id'  => $object_id ? (int)$object_id : null,
            'details'    => $this->jenc($det),
            'ip'         => $this->client_ip_bin(),
            'user_agent' => $this->user_agent(),
            'created_at' => $this->now(),
        ), array('%d','%s','%s','%d','%s','%s','%s','%s'));
    }

    /* =========================
       Event loggers (frontend/auth)
       ========================= */
    public function log_login($user_login, $user) {
        if (!($user instanceof WP_User)) return;
        global $wpdb;
        $wpdb->insert($wpdb->prefix . self::DB_LOGINS, array(
            'user_id'    => $user->ID,
            'username'   => $user_login,
            'event'      => 'login',
            'ip'         => $this->client_ip_bin(),
            'user_agent' => $this->user_agent(),
            'created_at' => $this->now(),
        ), array('%d','%s','%s','%s','%s','%s'));
    }

    public function log_login_failed($username) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . self::DB_LOGINS, array(
            'user_id'    => null,
            'username'   => $username,
            'event'      => 'login_failed',
            'ip'         => $this->client_ip_bin(),
            'user_agent' => $this->user_agent(),
            'created_at' => $this->now(),
        ), array('%d','%s','%s','%s','%s','%s'));
    }

    public function log_logout() {
        if (!is_user_logged_in()) return;
        $user = wp_get_current_user();
        if (!$user || 0 === $user->ID) return;
        global $wpdb;
        $wpdb->insert($wpdb->prefix . self::DB_LOGINS, array(
            'user_id'    => $user->ID,
            'username'   => $user->user_login,
            'event'      => 'logout',
            'ip'         => $this->client_ip_bin(),
            'user_agent' => $this->user_agent(),
            'created_at' => $this->now(),
        ), array('%d','%s','%s','%s','%s','%s'));
    }

    public function track_page_view() {
        if (!is_user_logged_in()) return;
        $tuple = $this->is_key_page();
        $event = $tuple[0];
        if (!$event) return;
        $this->log_activity($event, $tuple[1], (int)$tuple[2], array());
    }

    public function log_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        if (!is_user_logged_in()) return;
        $this->log_activity('add_to_cart', 'product', (int)$product_id, array('qty' => (int)$quantity, 'variation_id' => (int)$variation_id));
    }

    public function log_order_thankyou($order_id) {
        if (!is_user_logged_in()) return;
        $this->log_activity('order_placed', 'order', (int)$order_id, array());
    }

    private function log_activity($event, $object_type, $object_id, $details) {
        $user = wp_get_current_user();
        if (!$user || 0 === $user->ID) return;
        global $wpdb;
        $wpdb->insert($wpdb->prefix . self::DB_ACTIVITY, array(
            'user_id'    => $user->ID,
            'event'      => $event,
            'object_type'=> $object_type,
            'object_id'  => $object_id ?: null,
            'details'    => $this->jenc($details),
            'ip'         => $this->client_ip_bin(),
            'user_agent' => $this->user_agent(),
            'created_at' => $this->now(),
        ), array('%d','%s','%s','%d','%s','%s','%s','%s'));
    }

    /* =========================
       Shared login flagging
       ========================= */
    public function run_flag_scan() {
        $threshold = 3;
        $windowHrs = 24;

        global $wpdb;
        $logins = $wpdb->prefix . self::DB_LOGINS;
        $flags  = $wpdb->prefix . self::DB_FLAGS;

        $since = gmdate('Y-m-d H:i:s', time() - ($windowHrs * 3600));
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT user_id, COUNT(DISTINCT ip) AS ip_count
            FROM $logins
            WHERE event='login' AND created_at >= %s AND user_id IS NOT NULL
            GROUP BY user_id
            HAVING ip_count > %d
        ", $since, $threshold), ARRAY_A);

        if (!$rows) return;

        foreach ($rows as $r) {
            $user_id = (int)$r['user_id'];
            $ip_count = (int)$r['ip_count'];

            $samples = $wpdb->get_results($wpdb->prepare("
                SELECT username, HEX(ip) AS ip_hex, created_at
                FROM $logins
                WHERE user_id=%d AND created_at >= %s AND event='login'
                ORDER BY created_at DESC
                LIMIT 10
            ", $user_id, $since), ARRAY_A);

            $wpdb->insert($flags, array(
                'user_id'           => $user_id,
                'window_hours'      => $windowHrs,
                'distinct_ip_count' => $ip_count,
                'threshold'         => $threshold,
                'sample'            => $this->jenc($samples),
                'created_at'        => current_time('mysql'),
                'resolved'          => 0,
            ), array('%d','%d','%d','%d','%s','%s','%d'));
        }
    }

    /* =========================
       Admin watchdog handlers
       ========================= */
    public function adm_order_status($order_id, $old, $new, $order) {
        $this->log_admin_event('order_status_change', 'order', (int)$order_id, array(
            'from' => (string)$old, 'to' => (string)$new, 'total' => (string)$order->get_total()
        ));
    }

    public function adm_order_note($note_id, $note) {
        if (!($note instanceof WC_Order_Note)) return;
        $order_id = (int)$note->get_order_id();
        $this->log_admin_event('order_note', 'order', $order_id, array(
            'customer_note' => $note->get_customer_note() ? 1 : 0,
            'content'       => $note->get_content()
        ));
    }

    public function adm_refund_created($refund_id, $args) {
        $refund = wc_get_order($refund_id);
        if (!$refund) return;
        $parent = $refund->get_parent_id();
        $this->log_admin_event('refund_created', 'order', (int)$parent, array(
            'refund_id' => (int)$refund_id,
            'amount'    => (string)$refund->get_amount(),
            'reason'    => (string)$refund->get_reason()
        ));
    }

    public function adm_order_saved($post_id, $post) {
        if ($post instanceof WP_Post && $post->post_type === 'shop_order') {
            $this->log_admin_event('order_saved', 'order', (int)$post_id, array(
                'status' => (string)get_post_status($post_id)
            ));
        }
    }

    public function adm_product_saved($post_id, $post) {
        if ($post instanceof WP_Post && $post->post_type === 'product') {
            $prod = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
            $this->log_admin_event('product_saved', 'product', (int)$post_id, array(
                'status' => (string)get_post_status($post_id),
                'sku'    => $prod ? (string)$prod->get_sku() : '',
                'stock'  => ($prod && method_exists($prod,'managing_stock') && $prod->managing_stock()) ? (string)$prod->get_stock_quantity() : ''
            ));
        }
    }

    public function adm_user_created($user_id) {
        $u = get_user_by('id', (int)$user_id);
        $this->log_admin_event('user_created', 'user', (int)$user_id, array(
            'username' => $u ? (string)$u->user_login : ''
        ));
    }

    public function adm_user_updated($user_id, $old_user_data) {
        $u = get_user_by('id', (int)$user_id);
        $this->log_admin_event('user_updated', 'user', (int)$user_id, array(
            'username' => $u ? (string)$u->user_login : ''
        ));
    }

    public function adm_user_role($user_id, $role, $old_roles) {
        $this->log_admin_event('user_role_set', 'user', (int)$user_id, array(
            'role' => (string)$role,
            'old_roles' => array_values((array)$old_roles)
        ));
    }

    public function adm_user_deleted($user_id) {
        $this->log_admin_event('user_deleted', 'user', (int)$user_id, array());
    }

    public function adm_plugin_activated($plugin, $network_wide) {
        $this->log_admin_event('plugin_activated', 'plugin', 0, array(
            'plugin' => (string)$plugin,
            'network' => $network_wide ? 1 : 0
        ));
    }

    public function adm_plugin_deactivated($plugin, $network_wide) {
        $this->log_admin_event('plugin_deactivated', 'plugin', 0, array(
            'plugin' => (string)$plugin,
            'network' => $network_wide ? 1 : 0
        ));
    }

    public function adm_option_updated($option, $old_value, $value) {
        if (strpos($option, 'woocommerce_') === 0 || strpos($option, 'wc_') === 0) {
            $this->log_admin_event('option_updated', 'option', 0, array(
                'option' => (string)$option
            ));
        }
    }

    /* =========================
       CSV export
       ========================= */
    private function csv_out($filename, $header, $rows_iterable) {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.$filename.'"');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, $header, ',', '"', '\\');
        foreach ($rows_iterable as $r) fputcsv($out, $r, ',', '"', '\\');
        fclose($out);
        exit;
    }

    public function handle_export_csv() {
        if (!current_user_can($this->admin_cap())) wp_die('Insufficient capability');
        check_admin_referer('musesys_export_csv');

        global $wpdb;
        $type   = isset($_REQUEST['type']) ? sanitize_key($_REQUEST['type']) : '';
        $start  = isset($_REQUEST['start']) ? sanitize_text_field($_REQUEST['start']) : '';
        $end    = isset($_REQUEST['end']) ? sanitize_text_field($_REQUEST['end']) : '';
        $user   = isset($_REQUEST['user_id']) ? (int)$_REQUEST['user_id'] : 0;
        $event  = isset($_REQUEST['event']) ? sanitize_text_field($_REQUEST['event']) : '';
        $limit  = isset($_REQUEST['limit']) ? max(1, (int)$_REQUEST['limit']) : 5000;

        $where  = array(); $params = array();
        if ($start !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) { $where[] = "created_at >= %s"; $params[] = $start." 00:00:00"; }
        if ($end   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   { $where[] = "created_at <= %s"; $params[] = $end." 23:59:59"; }
        if ($user > 0) { $where[] = "user_id = %d"; $params[] = $user; }

        if ($type === 'logins') {
            $table = $wpdb->prefix . self::DB_LOGINS;
            if ($event !== '') { $where[] = "event = %s"; $params[] = $event; }
            $sql = "SELECT created_at, user_id, username, event, INET6_NTOA(ip) AS ip, user_agent FROM $table";
            if (!empty($where)) $sql .= " WHERE " . implode(" AND ", $where);
            $sql .= " ORDER BY created_at DESC LIMIT %d";
            $params[] = $limit;
            $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
            $this->csv_out('musesys-logins.csv',
                array('created_at','user_id','username','event','ip','user_agent'),
                array_map(function($r){ return array($r['created_at'],$r['user_id'],$r['username'],$r['event'],$r['ip'],$r['user_agent']); }, $rows)
            );
        }

        if ($type === 'activity' || $type === 'admin_activity') {
            $table = $wpdb->prefix . self::DB_ACTIVITY;
            $where2 = $where; $params2 = $params; $filename = 'musesys-activity.csv';

            if ($type === 'admin_activity') {
                $events = array(
                    'order_status_change','order_note','refund_created',
                    'order_saved','product_saved',
                    'user_created','user_updated','user_role_set','user_deleted',
                    'plugin_activated','plugin_deactivated','option_updated'
                );
                if ($event !== '' && in_array($event, $events, true)) {
                    $where2[] = "event = %s"; $params2[] = $event;
                } else {
                    $where2[] = "event IN ('" . implode("','", array_map('esc_sql', $events)) . "')";
                }
                $filename = 'musesys-admin-activity.csv';
            } else {
                if ($event !== '') { $where2[] = "event = %s"; $params2[] = $event; }
            }

            $sql = "SELECT created_at, user_id, event, object_type, object_id, details, INET6_NTOA(ip) AS ip, user_agent FROM $table";
            if (!empty($where2)) $sql .= " WHERE " . implode(" AND ", $where2);
            $sql .= " ORDER BY created_at DESC LIMIT %d";
            $params2[] = $limit;

            $rows = $wpdb->get_results($wpdb->prepare($sql, $params2), ARRAY_A);
            $this->csv_out($filename,
                array('created_at','user_id','event','object_type','object_id','details','ip','user_agent'),
                array_map(function($r){ return array($r['created_at'],$r['user_id'],$r['event'],$r['object_type'],$r['object_id'],$r['details'],$r['ip'],$r['user_agent']); }, $rows)
            );
        }

        wp_die('Unknown export type');
    }

    /* =========================
       Admin UI
       ========================= */
    private function table_head($cols) {
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($cols as $c) echo '<th>' . esc_html($c) . '</th>';
        echo '</tr></thead><tbody>';
    }
    private function table_foot() { echo '</tbody></table>'; }

    public function render_dashboard() {
        if (!current_user_can($this->admin_cap())) return;
        global $wpdb;
        $logins = $wpdb->prefix . self::DB_LOGINS;
        $activity = $wpdb->prefix . self::DB_ACTIVITY;
        $flags = $wpdb->prefix . self::DB_FLAGS;

        $since24 = gmdate('Y-m-d H:i:s', time()-86400);
        $counts = array(
            'logins_24h' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $logins WHERE event='login' AND created_at >= %s", $since24)),
            'failed_24h' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $logins WHERE event='login_failed' AND created_at >= %s", $since24)),
            'events_24h' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $activity WHERE created_at >= %s", $since24)),
            'flags_open' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $flags WHERE resolved=0"),
        );
        echo '<div class="wrap"><h1>MuseSys Activity — Dashboard (Legacy)</h1>';
        $this->render_tabs('dashboard');
        echo '<div style="display:flex;gap:16px;margin:16px 0;flex-wrap:wrap;">';
        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:16px;border-radius:4px;min-width:200px;flex:1;">';
        echo '<h3 style="margin-top:0;">24h Logins</h3>';
        echo '<p style="font-size:26px;font-weight:bold;margin:8px 0;"><a href="' . esc_url(admin_url('admin.php?page=musesys-activity-legacy&tab=logins')) . '" style="text-decoration:none;">' . esc_html($counts['logins_24h']) . '</a></p>';
        echo '<p style="margin:0;color:#666;">Failed attempts: <strong>' . esc_html($counts['failed_24h']) . '</strong></p>';
        echo '</div>';
        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:16px;border-radius:4px;min-width:200px;flex:1;">';
        echo '<h3 style="margin-top:0;">24h Activity Events</h3>';
        echo '<p style="font-size:26px;font-weight:bold;margin:8px 0;"><a href="' . esc_url(admin_url('admin.php?page=musesys-activity-legacy&tab=activity')) . '" style="text-decoration:none;">' . esc_html($counts['events_24h']) . '</a></p>';
        echo '<p style="margin:0;color:#666;">Frontend, cart &amp; checkout actions</p>';
        echo '</div>';
        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:16px;border-radius:4px;min-width:200px;flex:1;">';
        echo '<h3 style="margin-top:0;">Open Flags</h3>';
        echo '<p style="font-size:26px;font-weight:bold;margin:8px 0;"><a href="' . esc_url(admin_url('admin.php?page=musesys-activity-legacy&tab=flags')) . '" style="text-decoration:none;">' . esc_html($counts['flags_open']) . '</a></p>';
        echo '<p style="margin:0;color:#666;">Concurrent IP threshold alerts</p>';
        echo '</div>';
        echo '</div>';
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=musesys-activity-legacy&tab=activity')) . '">View Full Activity Log &rarr;</a></p>';
        echo '</div>';
    }

    public function render_logins() {
        if (!current_user_can($this->admin_cap())) return;
        global $wpdb;
        $logins = $wpdb->prefix . self::DB_LOGINS;
        $rows = $wpdb->get_results("SELECT * FROM $logins ORDER BY created_at DESC LIMIT 200", ARRAY_A);
        echo '<div class="wrap"><h1>MuseSys Activity — Logins (Legacy)</h1>';
        $this->render_tabs('logins');

        // Export form
        echo '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:8px 0;">';
        echo '<input type="hidden" name="action" value="musesys_export_csv">';
        echo '<input type="hidden" name="type" value="logins">';
        wp_nonce_field('musesys_export_csv');
        echo 'Start <input type="date" name="start"> ';
        echo 'End <input type="date" name="end"> ';
        echo 'User ID <input type="number" name="user_id" min="0" style="width:90px"> ';
        echo 'Event <select name="event"><option value="">Any</option><option>login</option><option>login_failed</option><option>logout</option></select> ';
        echo 'Limit <input type="number" name="limit" value="5000" min="1" style="width:100px"> ';
        echo '<button class="button button-primary">Export CSV</button>';
        echo '</form>';

        $this->table_head(array('When', 'User', 'Username', 'Event', 'IP', 'UA'));
        if ($rows) {
            foreach ($rows as $r) {
                $user = $r['user_id'] ? get_user_by('id', (int)$r['user_id']) : null;
                echo '<tr>';
                echo '<td>' . esc_html($r['created_at']) . '</td>';
                echo '<td>' . esc_html($user ? $user->user_login : '') . '</td>';
                echo '<td>' . esc_html($r['username']) . '</td>';
                echo '<td>' . esc_html($r['event']) . '</td>';
                echo '<td>' . esc_html($this->ip_to_text($r['ip'])) . '</td>';
                echo '<td><code style="white-space:nowrap;display:block;overflow:auto;max-width:520px;">' . esc_html($r['user_agent']) . '</code></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6">No rows yet.</td></tr>';
        }
        $this->table_foot();
        echo '</div>';
    }

    public function render_activity() {
        if (!current_user_can($this->admin_cap())) return;
        global $wpdb;
        $activity = $wpdb->prefix . self::DB_ACTIVITY;
        $rows = $wpdb->get_results("SELECT * FROM $activity ORDER BY created_at DESC LIMIT 200", ARRAY_A);
        echo '<div class="wrap"><h1>MuseSys Activity — Activity Logs (Legacy)</h1>';
        $this->render_tabs('activity');

        // Export form
        echo '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:8px 0;">';
        echo '<input type="hidden" name="action" value="musesys_export_csv">';
        echo '<input type="hidden" name="type" value="activity">';
        wp_nonce_field('musesys_export_csv');
        echo 'Start <input type="date" name="start"> ';
        echo 'End <input type="date" name="end"> ';
        echo 'User ID <input type="number" name="user_id" min="0" style="width:90px"> ';
        echo 'Event <input type="text" name="event" placeholder="e.g. add_to_cart"> ';
        echo 'Limit <input type="number" name="limit" value="5000" min="1" style="width:100px"> ';
        echo '<button class="button button-primary">Export CSV</button>';
        echo '</form>';

        $this->table_head(array('When', 'User', 'Event', 'Object', 'Details', 'IP'));
        if ($rows) {
            foreach ($rows as $r) {
                $user = $r['user_id'] ? get_user_by('id', (int)$r['user_id']) : null;
                $obj = trim(($r['object_type'] ? $r['object_type'] : '') . '#' . ($r['object_id'] ? $r['object_id'] : ''));
                echo '<tr>';
                echo '<td>' . esc_html($r['created_at']) . '</td>';
                echo '<td>' . esc_html($user ? $user->user_login : '') . '</td>';
                echo '<td>' . esc_html($r['event']) . '</td>';
                echo '<td>' . esc_html($obj) . '</td>';
                echo '<td><code style="white-space:pre-wrap;display:block;max-height:140px;overflow:auto;">' . esc_html($r['details'] ? $r['details'] : '') . '</code></td>';
                echo '<td>' . esc_html($this->ip_to_text($r['ip'])) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6">No rows yet.</td></tr>';
        }
        $this->table_foot();
        echo '</div>';
    }

    public function render_flags() {
        if (!current_user_can($this->admin_cap())) return;
        global $wpdb;
        $flags = $wpdb->prefix . self::DB_FLAGS;

        if (isset($_POST['musesys_resolve_flag']) && check_admin_referer('musesys_resolve_flag_legacy')) {
            $id = isset($_POST['flag_id']) ? (int)$_POST['flag_id'] : 0;
            if ($id) {
                $wpdb->update($flags, array('resolved' => 1), array('id' => $id), array('%d'), array('%d'));
                echo '<div class="updated"><p>Flag #' . esc_html($id) . ' resolved.</p></div>';
            }
        }

        $rows = $wpdb->get_results("SELECT * FROM $flags ORDER BY created_at DESC LIMIT 200", ARRAY_A);
        echo '<div class="wrap"><h1>MuseSys Activity — Flags (Legacy)</h1>';
        $this->render_tabs('flags');
        echo '<form method="post" style="margin-bottom:10px;">';
        wp_nonce_field('musesys_resolve_flag_legacy');
        echo '<p>Resolve a flag by clicking its button in the table.</p>';
        echo '</form>';

        $this->table_head(array('ID', 'User', 'Distinct IPs', 'Window (h)', 'Threshold', 'Created', 'Resolved', 'Actions'));
        if ($rows) {
            foreach ($rows as $r) {
                $user = get_user_by('id', (int)$r['user_id']);
                echo '<tr>';
                echo '<td>' . (int)$r['id'] . '</td>';
                echo '<td>' . esc_html($user ? $user->user_login : 'User #' . (int)$r['user_id']) . '</td>';
                echo '<td>' . esc_html($r['distinct_ip_count']) . '</td>';
                echo '<td>' . esc_html($r['window_hours']) . '</td>';
                echo '<td>' . esc_html($r['threshold']) . '</td>';
                echo '<td>' . esc_html($r['created_at']) . '</td>';
                echo '<td>' . ($r['resolved'] ? 'Yes' : 'No') . '</td>';
                echo '<td>';
                if (!$r['resolved']) {
                    echo '<form method="post" style="display:inline;">';
                    wp_nonce_field('musesys_resolve_flag_legacy');
                    echo '<input type="hidden" name="flag_id" value="' . (int)$r['id'] . '"/>';
                    echo '<button class="button button-primary" name="musesys_resolve_flag" value="1">Resolve</button>';
                    echo '</form>';
                } else {
                    echo '—';
                }
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="8">No flags.</td></tr>';
        }
        $this->table_foot();
        echo '</div>';
    }

    public function render_admin_activity() {
        if (!current_user_can($this->admin_cap())) return;
        global $wpdb;
        $activity = $wpdb->prefix . self::DB_ACTIVITY;

        $events = array(
            'order_status_change','order_note','refund_created',
            'order_saved','product_saved',
            'user_created','user_updated','user_role_set','user_deleted',
            'plugin_activated','plugin_deactivated','option_updated'
        );
        $in = implode("','", array_map('esc_sql', $events));
        $rows = $wpdb->get_results("SELECT * FROM $activity WHERE event IN ('$in') ORDER BY created_at DESC LIMIT 200", ARRAY_A);

        echo '<div class="wrap"><h1>MuseSys Activity — Admin Watchdog (Legacy)</h1>';
        $this->render_tabs('admin');

        // Export form
        echo '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:8px 0;">';
        echo '<input type="hidden" name="action" value="musesys_export_csv">';
        echo '<input type="hidden" name="type" value="admin_activity">';
        wp_nonce_field('musesys_export_csv');
        echo 'Start <input type="date" name="start"> ';
        echo 'End <input type="date" name="end"> ';
        echo 'User ID <input type="number" name="user_id" min="0" style="width:90px"> ';
        echo 'Event <input type="text" name="event" placeholder="optional filter"> ';
        echo 'Limit <input type="number" name="limit" value="5000" min="1" style="width:100px"> ';
        echo '<button class="button button-primary">Export CSV</button>';
        echo '</form>';

        $this->table_head(array('When','Actor','Event','Object','Details','IP'));
        if ($rows) {
            foreach ($rows as $r) {
                $actor = $r['user_id'] ? get_user_by('id', (int)$r['user_id']) : null;
                $obj = trim(($r['object_type'] ? $r['object_type'] : '') . '#' . ($r['object_id'] ? $r['object_id'] : ''));
                echo '<tr>';
                echo '<td>' . esc_html($r['created_at']) . '</td>';
                echo '<td>' . esc_html($actor ? $actor->user_login : 'system') . '</td>';
                echo '<td>' . esc_html($r['event']) . '</td>';
                echo '<td>' . esc_html($obj) . '</td>';
                echo '<td><code style="white-space:pre-wrap;display:block;max-height:140px;overflow:auto;">' . esc_html($r['details'] ? $r['details'] : '') . '</code></td>';
                echo '<td>' . esc_html($this->ip_to_text($r['ip'])) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6">No admin activity yet.</td></tr>';
        }
        $this->table_foot();
        echo '</div>';
    }
}

new MuseSys_Activity_Tracker_Legacy();
