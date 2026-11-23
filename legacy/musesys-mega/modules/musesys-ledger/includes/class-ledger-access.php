<?php
/**
 * Roles, capabilities and the agent lockdown — LEDGER_SPEC.md §7.
 *
 * Lockdown is keyed on the agent ROLE (not the capability pair) and never applies to
 * anyone with manage_options. Every lockdown hook goes through is_locked_agent().
 */

if (!defined('ABSPATH')) exit;

final class Ledger_Access {
    const CAP_VIEW     = 'musesys_ledger_view';
    const CAP_MANAGE   = 'musesys_ledger_manage';
    const CAP_PROPOSE  = 'musesys_ledger_propose';   // file proposals over REST (an outside system's account)
    const CAP_APPROVE  = 'musesys_ledger_approve';   // approve / reject proposals on the ledger (§15.4)
    const ROLE_AGENT   = 'musesys_ledger_agent';
    const ROLE_VIEWER  = 'musesys_ledger_viewer';
    const ROLE_SERVICE = 'musesys_ledger_service';
    const ROLES_VER    = '2';
    const OPT_ROLES    = 'musesys_ledger_roles_version';
    const PAGE         = 'musesys-ledger';

    const AJAX_WHITELIST = [
        'musesys_ledger_list', 'musesys_ledger_history', 'musesys_ledger_log_email',
        'musesys_ledger_log_call', 'musesys_ledger_note', 'musesys_ledger_set_eta', 'heartbeat',
        'musesys_ledger_proposals',   // read-only list; approve / reject are not whitelisted for the agent
    ];

    public static function init(): void {
        add_action('init', [__CLASS__, 'maybe_register_roles'], 5);

        // WooCommerce bounces users without edit_posts / manage_woocommerce / view_admin_dashboard
        // out of wp-admin. Ledger users need wp-admin for the ledger page.
        add_filter('woocommerce_prevent_admin_access', [__CLASS__, 'allow_ledger_admin_access'], 20);

        add_filter('login_redirect', [__CLASS__, 'login_redirect'], 99, 3);
        add_filter('woocommerce_login_redirect', [__CLASS__, 'wc_login_redirect'], 99, 2);
        add_action('admin_init', [__CLASS__, 'guard_admin'], 1);
        add_action('template_redirect', [__CLASS__, 'guard_frontend'], 0);
        add_filter('rest_authentication_errors', [__CLASS__, 'guard_rest'], 20);
        add_filter('show_admin_bar', [__CLASS__, 'filter_admin_bar'], 99);
        add_action('admin_menu', [__CLASS__, 'strip_menu'], 9999);
        add_action('admin_bar_menu', [__CLASS__, 'strip_admin_bar'], 9999);
    }

    public static function is_locked_agent($user = null): bool {
        $user = $user instanceof WP_User ? $user : wp_get_current_user();
        if (!$user || !$user->exists()) return false;
        return in_array(self::ROLE_AGENT, (array) $user->roles, true) && !user_can($user, 'manage_options');
    }

    public static function page_url(): string {
        return admin_url('admin.php?page=' . self::PAGE);
    }

    public static function maybe_register_roles(): void {
        if (get_option(self::OPT_ROLES) === self::ROLES_VER) return;

        $roles = [
            self::ROLE_AGENT  => ['Ledger Agent',  ['read' => true, self::CAP_VIEW => true, self::CAP_MANAGE => true]],
            self::ROLE_VIEWER => ['Ledger Viewer', ['read' => true, self::CAP_VIEW => true]],
            // An outside system's account: may file and read proposals, nothing else. No Woo capabilities.
            self::ROLE_SERVICE => ['Ledger Integration', ['read' => true, self::CAP_PROPOSE => true]],
        ];
        foreach ($roles as $slug => [$label, $caps]) {
            $role = get_role($slug);
            if (!$role) {
                add_role($slug, $label, $caps);
            } else {
                foreach ($caps as $cap => $grant) $role->add_cap($cap, $grant);
            }
        }
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(self::CAP_VIEW);
            $admin->add_cap(self::CAP_MANAGE);
            $admin->add_cap(self::CAP_PROPOSE);
            $admin->add_cap(self::CAP_APPROVE);
        }
        update_option(self::OPT_ROLES, self::ROLES_VER, false);
    }

    public static function allow_ledger_admin_access($prevent) {
        return current_user_can(self::CAP_VIEW) ? false : $prevent;
    }

    public static function login_redirect($redirect_to, $requested, $user) {
        return ($user instanceof WP_User && self::is_locked_agent($user)) ? self::page_url() : $redirect_to;
    }

    public static function wc_login_redirect($redirect, $user) {
        return ($user instanceof WP_User && self::is_locked_agent($user)) ? self::page_url() : $redirect;
    }

    public static function guard_admin(): void {
        if (!self::is_locked_agent()) return;

        if (wp_doing_ajax()) {
            $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
            if (!in_array($action, self::AJAX_WHITELIST, true)) {
                wp_die('Forbidden', 'Forbidden', ['response' => 403]);
            }
            return;
        }

        global $pagenow;
        if ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === self::PAGE) return;

        wp_safe_redirect(self::page_url());
        exit;
    }

    public static function guard_frontend(): void {
        if (!self::is_locked_agent()) return;
        wp_safe_redirect(self::page_url());
        exit;
    }

    public static function guard_rest($result) {
        if (!empty($result)) return $result;
        if (self::is_locked_agent()) {
            return new WP_Error('musesys_ledger_rest_forbidden', 'Forbidden', ['status' => 403]);
        }
        return $result;
    }

    public static function filter_admin_bar($show) {
        return self::is_locked_agent() ? false : $show;
    }

    public static function strip_menu(): void {
        if (!self::is_locked_agent()) return;
        global $menu;
        foreach ((array) $menu as $item) {
            if (isset($item[2]) && $item[2] !== self::PAGE) remove_menu_page($item[2]);
        }
    }

    /** wp-admin always renders the toolbar; keep only the account/logout node. */
    public static function strip_admin_bar($bar): void {
        if (!self::is_locked_agent()) return;
        $keep = ['top-secondary', 'my-account', 'user-actions', 'user-info', 'logout', 'menu-toggle'];
        foreach ((array) $bar->get_nodes() as $node) {
            if (!in_array($node->id, $keep, true)) $bar->remove_node($node->id);
        }
    }
}
