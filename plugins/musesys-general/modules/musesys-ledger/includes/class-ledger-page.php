<?php
/**
 * Ledger admin page shell — LEDGER_SPEC.md §8. The table renders client-side from musesys_ledger_list.
 */

if (!defined('ABSPATH')) exit;

final class Ledger_Page {
    private static $hook = '';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function menu(): void {
        // Users with manage_options access Ledger under the MuseSys submenu.
        // Locked agents lack manage_options and cannot access the MuseSys parent, so they get the dedicated menu page.
        if (current_user_can('manage_options')) {
            self::$hook = add_submenu_page('musesys', 'Ledger', 'Ledger', Ledger_Access::CAP_VIEW, Ledger_Access::PAGE, [__CLASS__, 'render']);
        } else {
            self::$hook = add_menu_page('Ledger', 'Ledger', Ledger_Access::CAP_VIEW, Ledger_Access::PAGE, [__CLASS__, 'render'], 'dashicons-list-view', 3);
        }
    }

    public static function assets($hook): void {
        if ($hook !== self::$hook) return;
        $base = plugin_dir_url(dirname(__FILE__)) . 'assets/';
        $ver  = Ledger_Schema::VERSION . '-' . Ledger_State_Resolver::VERSION;
        wp_enqueue_style('musesys-ledger', $base . 'ledger.css', [], $ver);
        wp_enqueue_script('musesys-ledger', $base . 'ledger.js', [], $ver, true);
        wp_localize_script('musesys-ledger', 'MuseLedger', [
            'ajax'       => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce(Ledger_Ajax::NONCE),
            'canManage'  => current_user_can(Ledger_Access::CAP_MANAGE),
            'canApprove' => current_user_can(Ledger_Access::CAP_APPROVE),
            'agentView'  => Ledger_Access::is_locked_agent(),
            'tz'         => Ledger_State_Resolver::TZ,
            'today'      => (new DateTimeImmutable('now', new DateTimeZone(Ledger_State_Resolver::TZ)))->format('Y-m-d'),
            'logoutUrl'  => wp_logout_url(),
        ]);
    }

    public static function render(): void {
        if (!current_user_can(Ledger_Access::CAP_VIEW)) wp_die('Forbidden', 403);
        echo '<div class="wrap muse-ledger" id="muse-ledger-app"><h1>Ledger</h1><p class="ml-loading">Loading…</p></div>';
    }
}
