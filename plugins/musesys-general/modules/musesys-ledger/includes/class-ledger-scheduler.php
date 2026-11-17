<?php
/**
 * Reconcile, nightly rebuild, backfill and version upgrades on Action Scheduler —
 * LEDGER_SPEC.md §4.3, §4.4, §9.
 */

if (!defined('ABSPATH')) exit;

final class Ledger_Scheduler {
    const GROUP          = 'musesys-ledger';
    const HOOK_RECONCILE = 'musesys_ledger_reconcile';
    const HOOK_REBUILD   = 'musesys_ledger_full_rebuild';
    const HOOK_BATCH     = 'musesys_ledger_project_batch';
    const BATCH_SIZE     = 50;

    const OPT_WATERMARK  = 'musesys_ledger_watermark';
    const OPT_BACKFILL   = 'musesys_ledger_backfill';            // pending | running | done
    const OPT_PROJ_V     = 'musesys_ledger_projector_version';
    const OPT_RES_V      = 'musesys_ledger_resolver_version';
    const OPT_RUN_PREFIX = 'musesys_ledger_run_';

    public static function init(): void {
        add_action(self::HOOK_RECONCILE, [__CLASS__, 'reconcile']);
        add_action(self::HOOK_REBUILD, [__CLASS__, 'full_rebuild']);
        add_action(self::HOOK_BATCH, [__CLASS__, 'project_batch'], 10, 3);
        add_action('init', [__CLASS__, 'ensure_schedules'], 30);
    }

    /** Registers recurring actions and kicks off the first backfill. Checked at most hourly. */
    public static function ensure_schedules(): void {
        if (!function_exists('as_has_scheduled_action')) return;
        if (get_option(self::OPT_BACKFILL) === 'pending') {
            update_option(self::OPT_BACKFILL, 'running', false);
            self::start_run('backfill', ['backfill' => true, 'set_projector_version' => true]);
        }
        if (get_transient('musesys_ledger_sched_ok')) return;

        if (!as_has_scheduled_action(self::HOOK_RECONCILE, [], self::GROUP)) {
            as_schedule_recurring_action(time() + 60, 15 * MINUTE_IN_SECONDS, self::HOOK_RECONCILE, [], self::GROUP);
        }
        if (!as_has_scheduled_action(self::HOOK_REBUILD, [], self::GROUP)) {
            $first = new DateTime('tomorrow 02:00:00', new DateTimeZone(Ledger_State_Resolver::TZ));
            as_schedule_recurring_action($first->getTimestamp(), DAY_IN_SECONDS, self::HOOK_REBUILD, [], self::GROUP);
        }
        set_transient('musesys_ledger_sched_ok', 1, HOUR_IN_SECONDS);
    }

    /** Every 15 minutes (§4.3). */
    public static function reconcile(): void {
        // A backfill whose batches died is restarted (idempotent, §3.3).
        if (get_option(self::OPT_BACKFILL) === 'running' && !self::run_in_progress()) {
            self::start_run('backfill', ['backfill' => true, 'set_projector_version' => true]);
        }

        // Projector version (§4.4): rebuild first; the batch run stores the new version when done.
        if ((int) get_option(self::OPT_PROJ_V, 0) !== Ledger_Projector::VERSION
            && get_option(self::OPT_BACKFILL) === 'done'
            && !self::run_in_progress()) {
            self::start_run('projector', ['set_projector_version' => true]);
        }

        // Resolver version (§4.4): resolve every row incl. CLOSED, then store the version.
        $stored_rv = (int) get_option(self::OPT_RES_V, 0);
        if ($stored_rv !== Ledger_State_Resolver::VERSION) {
            $cause = $stored_rv === 0 ? [] : ['cause' => ['cause' => 'resolver_upgrade', 'from_v' => $stored_rv, 'to_v' => Ledger_State_Resolver::VERSION]];
            Ledger_Projector::resolve_all(true, $cause);
            update_option(self::OPT_RES_V, Ledger_State_Resolver::VERSION, false);
        }

        // Orders modified since the watermark — any status, so missed completions are caught too.
        $start = time();
        $since = (int) get_option(self::OPT_WATERMARK, strtotime(Ledger_Schema::installed_at() . ' UTC'));
        $ids = wc_get_orders([
            'type'          => 'shop_order',
            'status'        => array_keys(wc_get_order_statuses()),
            'date_modified' => '>' . max(0, $since - 60), // overlap: safe because projection is idempotent
            'limit'         => -1,
            'return'        => 'ids',
        ]);
        foreach ((array) $ids as $id) {
            Ledger_Projector::project((int) $id, ['source' => 'projector']);
        }

        // Time alone changes state: resolve all open rows on every run.
        Ledger_Projector::resolve_all(false);
        update_option(self::OPT_WATERMARK, $start, false);
    }

    /** Nightly: project every open order plus every order with a non-closed row. */
    public static function full_rebuild(): void {
        if (self::run_in_progress()) return;
        self::start_run('projector', []);
    }

    /**
     * Enqueue batched projection of all relevant orders.
     * @param array $flags backfill (bool), set_projector_version (bool)
     */
    private static function start_run(string $source, array $flags): void {
        global $wpdb;
        $ids = wc_get_orders([
            'type'   => 'shop_order',
            'status' => Ledger_State_Resolver::OPEN_STATUSES,
            'limit'  => -1,
            'return' => 'ids',
        ]);
        $lt = Ledger_Schema::ledger_table();
        $tracked = (array) $wpdb->get_col("SELECT DISTINCT order_id FROM $lt WHERE state <> 'CLOSED'");
        $ids = array_values(array_unique(array_map('intval', array_merge((array) $ids, $tracked))));

        $run    = substr(md5(uniqid('', true)), 0, 12);
        $chunks = array_chunk($ids, self::BATCH_SIZE);
        update_option(self::OPT_RUN_PREFIX . $run, ['remaining' => count($chunks), 'started' => time()] + $flags, false);
        update_option(self::OPT_RUN_PREFIX . 'current', $run, false);

        if (!$chunks) {
            self::finish_run($run);
            return;
        }
        foreach ($chunks as $chunk) {
            as_enqueue_async_action(self::HOOK_BATCH, [$chunk, $source, $run], self::GROUP);
        }
    }

    public static function project_batch($ids, $source, $run): void {
        foreach ((array) $ids as $id) {
            Ledger_Projector::project((int) $id, ['source' => (string) $source]);
        }
        $key  = self::OPT_RUN_PREFIX . $run;
        $info = get_option($key);
        if (!is_array($info)) return;
        $info['remaining'] = max(0, (int) $info['remaining'] - 1);
        update_option($key, $info, false);
        if ($info['remaining'] === 0) self::finish_run((string) $run);
    }

    private static function finish_run(string $run): void {
        $key  = self::OPT_RUN_PREFIX . $run;
        $info = (array) get_option($key, []);
        // Versions are stored only after the whole pass completed (§4.4).
        if (!empty($info['set_projector_version'])) update_option(self::OPT_PROJ_V, Ledger_Projector::VERSION, false);
        if (!empty($info['backfill'])) update_option(self::OPT_BACKFILL, 'done', false);
        delete_option($key);
        if (get_option(self::OPT_RUN_PREFIX . 'current') === $run) delete_option(self::OPT_RUN_PREFIX . 'current');
    }

    private static function run_in_progress(): bool {
        $run = get_option(self::OPT_RUN_PREFIX . 'current');
        if (!$run) return false;
        $info = get_option(self::OPT_RUN_PREFIX . $run);
        // A run whose batches died (fatal, timeout) must not block rebuilds forever.
        return is_array($info) && (time() - (int) ($info['started'] ?? 0)) < 6 * HOUR_IN_SECONDS;
    }
}
