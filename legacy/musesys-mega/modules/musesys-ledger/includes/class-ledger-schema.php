<?php
/**
 * Ledger tables (dbDelta, version-gated) — LEDGER_SPEC.md §3.
 * Same pattern as musesys-reports' snapshot table.
 */

if (!defined('ABSPATH')) exit;

final class Ledger_Schema {
    const VERSION        = '1.0.0';
    const OPT_SCHEMA     = 'musesys_ledger_schema_version';
    const OPT_INSTALLED  = 'musesys_ledger_installed_at';

    public static function ledger_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'musesys_ledger';
    }

    public static function events_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'musesys_ledger_events';
    }

    /** UTC 'Y-m-d H:i:s' of first install — the backfill/observed boundary for confirmed_at (§4.2). */
    public static function installed_at(): string {
        return (string) get_option(self::OPT_INSTALLED, gmdate('Y-m-d H:i:s'));
    }

    public static function maybe_install(): void {
        if (get_option(self::OPT_SCHEMA) === self::VERSION) return;

        $first_install = get_option(self::OPT_INSTALLED) === false;
        if ($first_install) {
            add_option(self::OPT_INSTALLED, gmdate('Y-m-d H:i:s'), '', false);
        }

        self::create_tables();
        update_option(self::OPT_SCHEMA, self::VERSION, false);

        if ($first_install) {
            // Backfill is scheduled by Ledger_Scheduler once Action Scheduler is available (§9).
            update_option(Ledger_Scheduler::OPT_BACKFILL, 'pending', false);
        }
    }

    private static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $lt = self::ledger_table();
        $et = self::events_table();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE $lt (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  order_item_id bigint(20) unsigned NOT NULL,
  order_status varchar(32) NOT NULL,
  trx varchar(64) NULL,
  po_number varchar(64) NULL,
  is_drop_ship tinyint(1) NOT NULL DEFAULT 0,
  store varchar(16) NULL,
  product_id bigint(20) unsigned NULL,
  part_label varchar(255) NULL,
  qty_ordered int(11) NOT NULL,
  qty_shipped int(11) NOT NULL DEFAULT 0,
  tracking_count int(11) NOT NULL DEFAULT 0,
  logistics_status varchar(16) NOT NULL,
  eta date NULL,
  confirmed_at datetime NULL,
  confirmed_at_source varchar(16) NULL,
  state varchar(24) NOT NULL,
  state_reason varchar(48) NULL,
  due_at datetime NULL,
  overdue_bdays int(11) NOT NULL DEFAULT 0,
  data_flags varchar(255) NULL,
  last_email_at datetime NULL,
  last_call_at datetime NULL,
  last_action_at datetime NULL,
  actionable tinyint(1) NOT NULL DEFAULT 0,
  projected_at datetime NOT NULL,
  resolved_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_item (order_item_id),
  KEY order_id (order_id),
  KEY actionable_state (actionable,state),
  KEY trx (trx)
) $charset;");

        dbDelta("CREATE TABLE $et (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  ledger_id bigint(20) unsigned NULL,
  order_item_id bigint(20) unsigned NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source varchar(16) NOT NULL,
  action varchar(32) NOT NULL,
  payload_json longtext NOT NULL,
  dedupe_key varchar(191) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY order_created (order_id,created_at),
  KEY ledger_created (ledger_id,created_at),
  KEY action_created (action,created_at),
  KEY user_created (user_id,created_at)
) $charset;");
    }
}
