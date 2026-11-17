<?php
/**
 * Ledger audit — ETA-change accountability on top of the immutable event log.
 *
 * Nothing new is stored: every metric is derived from musesys_ledger_events
 * (eta_set / eta_changed / email_logged / call_logged / state_changed / shipment_recorded).
 * The only write-path addition is the ETA basis (source, contact, note) that set_eta now
 * requires and merges into the eta_set payload.
 *
 * Split like the projector:
 *   - Pure part (validate_eta_basis, slip_days, classify, aggregate, chronic) — no WordPress
 *     calls, tested by tests/audit_test.php.
 *   - WordPress part (payload, queries) — loads events, feeds the pure part.
 */

if (!defined('ABSPATH') && !defined('MUSESYS_LEDGER_TESTING')) exit;

final class Ledger_Audit {
    const VERSION = 1;

    /** What an ETA is based on. Order = dialog order. */
    const SOURCES = [
        'vendor_email'  => 'Vendor replied by email',
        'vendor_call'   => 'Vendor told me on the phone',
        'vendor_portal' => 'Vendor website / portal',
        'carrier'       => 'Carrier tracking',
        'estimate'      => 'My estimate (no vendor confirmation)',
    ];
    const EVIDENCED_SOURCES = ['vendor_email', 'vendor_call', 'vendor_portal', 'carrier'];

    const CONTACT_LOOKBACK_S = 86400; // a logged email/call within 24h before the change counts as evidence
    const BURST_WINDOW_S     = 600;   // 10 minutes
    const BURST_MIN          = 5;     // ≥5 ETA changes by one user inside the window
    const CLEAR_WINDOW_S     = 120;   // state_changed this soon after an ETA change is attributed to it
    const CHRONIC_PUSHES     = 3;
    const CHRONIC_DAYS       = 14;
    const CHRONIC_LIMIT      = 25;

    /* =====================================================================
     * PURE PART
     * ===================================================================== */

    /** Server-side rule for the ETA dialog. Returns an error message or null. */
    public static function validate_eta_basis(string $source, string $contact, string $note): ?string {
        if (!isset(self::SOURCES[$source])) return 'Select where this ETA came from.';
        if ($source === 'vendor_call' && trim($contact) === '') return 'Enter the name of the person you spoke with.';
        if ($source === 'estimate' && mb_strlen(trim($note)) < 10) return 'Explain what this estimate is based on.';
        return null;
    }

    /** Calendar days from previous to new ETA (positive = pushed out). Null when either side is missing. */
    public static function slip_days(?string $prev, ?string $new): ?int {
        if (!$prev || !$new) return null;
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $prev, new DateTimeZone('UTC'));
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', $new, new DateTimeZone('UTC'));
        if (!$a || !$b) return null;
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /**
     * Classify each ETA event.
     *
     * @param array $eta      ETA events: id, order_id, user_id, action, created_at (UTC), payload (array)
     * @param array $contacts email_logged/call_logged: order_id, action, created_at
     * @param array $states   state_changed: order_id, created_at, payload ['from','to']
     * @return array same events + slip, push, basis ('declared'|'contact_24h'|'none'), estimate,
     *               burst, cleared_call_required, cleared_needs_eta
     */
    public static function classify(array $eta, array $contacts, array $states): array {
        $ts = fn($s) => (int) strtotime($s . ' UTC');

        $contact_t = [];
        foreach ($contacts as $c) $contact_t[(int) $c['order_id']][] = $ts($c['created_at']);

        $state_by_order = [];
        foreach ($states as $s) $state_by_order[(int) $s['order_id']][] = ['t' => $ts($s['created_at'])] + (array) $s['payload'];

        $user_t = [];
        foreach ($eta as $e) $user_t[(int) $e['user_id']][] = $ts($e['created_at']);
        foreach ($user_t as &$list) sort($list);
        unset($list);

        $out = [];
        foreach ($eta as $e) {
            $p   = (array) $e['payload'];
            $oid = (int) $e['order_id'];
            $t   = $ts($e['created_at']);
            $src = (string) ($p['eta_source'] ?? '');

            $slip = self::slip_days($p['previous_eta'] ?? null, $p['new_eta'] ?? null);

            $basis = 'none';
            if (in_array($src, self::EVIDENCED_SOURCES, true)) {
                $basis = 'declared';
            } else {
                foreach ($contact_t[$oid] ?? [] as $ct) {
                    if ($ct <= $t && $ct >= $t - self::CONTACT_LOOKBACK_S) { $basis = 'contact_24h'; break; }
                }
            }

            $n = 0;
            foreach ($user_t[(int) $e['user_id']] as $ut) {
                if ($ut > $t) break;
                if ($ut >= $t - self::BURST_WINDOW_S) $n++;
            }

            $cleared_call = $cleared_eta = false;
            foreach ($state_by_order[$oid] ?? [] as $s) {
                if ($s['t'] < $t || $s['t'] > $t + self::CLEAR_WINDOW_S || ($s['to'] ?? '') !== 'ON_TRACK') continue;
                if (($s['from'] ?? '') === 'CALL_REQUIRED') $cleared_call = true;
                if (($s['from'] ?? '') === 'NEEDS_ETA') $cleared_eta = true;
            }

            $out[] = $e + [
                'slip'                  => $slip,
                'push'                  => $slip !== null && $slip > 0,
                'basis'                 => $basis,
                'estimate'              => $src === 'estimate',
                'burst'                 => $n >= self::BURST_MIN,
                'cleared_call_required' => $cleared_call,
                'cleared_needs_eta'     => $cleared_eta,
            ];
        }
        return $out;
    }

    /** Per-user totals from classify() output. */
    public static function aggregate(array $classified): array {
        $by = [];
        foreach ($classified as $e) {
            $uid = (int) $e['user_id'];
            $u = $by[$uid] ?? [
                'user_id' => $uid, 'eta_changes' => 0, 'orders' => [], 'pushes' => 0, 'slip_total' => 0, 'slip_max' => 0,
                'unevidenced_pushes' => 0, 'estimates' => 0, 'burst_changes' => 0, 'push_orders' => [],
                'cleared_call_required' => 0, 'cleared_needs_eta' => 0,
            ];
            $u['eta_changes']++;
            $u['orders'][(int) $e['order_id']] = true;
            if ($e['push']) {
                $u['pushes']++;
                $u['slip_total'] += $e['slip'];
                $u['slip_max']    = max($u['slip_max'], $e['slip']);
                $u['push_orders'][(int) $e['order_id']] = ($u['push_orders'][(int) $e['order_id']] ?? 0) + 1;
                if ($e['basis'] === 'none') $u['unevidenced_pushes']++;
            }
            if ($e['estimate'])              $u['estimates']++;
            if ($e['burst'])                 $u['burst_changes']++;
            if ($e['cleared_call_required']) $u['cleared_call_required']++;
            if ($e['cleared_needs_eta'])     $u['cleared_needs_eta']++;
            $by[$uid] = $u;
        }
        $out = [];
        foreach ($by as $u) {
            $u['orders_touched']  = count($u['orders']);
            $u['repeat_pushed']   = count(array_filter($u['push_orders'], fn($n) => $n >= 2));
            $u['avg_slip']        = $u['pushes'] ? round($u['slip_total'] / $u['pushes'], 1) : 0;
            unset($u['orders'], $u['push_orders'], $u['slip_total']);
            $out[] = $u;
        }
        usort($out, fn($a, $b) => $b['eta_changes'] <=> $a['eta_changes']);
        return $out;
    }

    /**
     * Chronic open orders: pushed ≥ CHRONIC_PUSHES times, or > CHRONIC_DAYS past the first ETA on record.
     *
     * @param array  $open     order_id => ['trx' => ?, 'eta' => ?, 'state' => worst state]
     * @param array  $eta      all ETA events for those orders (any source, oldest first)
     * @param string $today    Y-m-d (ET)
     */
    public static function chronic(array $open, array $eta, string $today): array {
        $first = $pushes = [];
        foreach ($eta as $e) {
            $oid = (int) $e['order_id'];
            $p   = (array) $e['payload'];
            if (!isset($first[$oid]) && !empty($p['new_eta'])) $first[$oid] = $p['new_eta'];
            $s = self::slip_days($p['previous_eta'] ?? null, $p['new_eta'] ?? null);
            if ($s !== null && $s > 0) $pushes[$oid] = ($pushes[$oid] ?? 0) + 1;
        }
        $out = [];
        foreach ($open as $oid => $r) {
            $base = $first[$oid] ?? null;
            $past = $base ? (int) self::slip_days($base, $today) : 0;
            $n    = $pushes[$oid] ?? 0;
            if ($n < self::CHRONIC_PUSHES && $past <= self::CHRONIC_DAYS) continue;
            $out[] = [
                'order_id'           => (int) $oid,
                'trx'                => $r['trx'] ?? null,
                'state'              => $r['state'] ?? null,
                'first_eta'          => $base,
                'eta'                => $r['eta'] ?? null,
                'pushes'             => $n,
                'days_past_first'    => max(0, $past),
            ];
        }
        usort($out, fn($a, $b) => [$b['days_past_first'], $b['pushes']] <=> [$a['days_past_first'], $a['pushes']]);
        return $out;
    }

    /* =====================================================================
     * WORDPRESS PART
     * ===================================================================== */

    /** Admin/viewer payload block. Never sent to the locked agent view. */
    public static function payload(): array {
        $tz    = new DateTimeZone(Ledger_State_Resolver::TZ);
        $utc   = new DateTimeZone('UTC');
        $today = (new DateTimeImmutable('today', $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
        $week  = (new DateTimeImmutable('today', $tz))->modify('-7 days')->setTimezone($utc)->format('Y-m-d H:i:s');
        $since = Ledger_Schema::installed_at();

        $chronic = self::chronic_rows();
        return [
            'today'           => self::window($today),
            'week'            => self::window($week),
            'period'          => self::window($since),
            'parked'          => self::parked_count(),
            'shipments_week'  => self::shipments_since($week),
            'chronic_total'   => count($chronic),
            'chronic'         => array_slice($chronic, 0, self::CHRONIC_LIMIT),
            'since'           => $since,
        ];
    }

    /** Per-user audit for ETA changes at/after $since_utc. */
    public static function window(string $since_utc): array {
        global $wpdb;
        $et  = Ledger_Schema::events_table();
        $eta = self::decode((array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, order_id, user_id, action, source, payload_json, created_at FROM $et
              WHERE action IN ('eta_set','eta_changed') AND source <> 'backfill' AND created_at >= %s
              ORDER BY created_at ASC, id ASC", $since_utc
        ), ARRAY_A));
        if (!$eta) return [];

        $oids = implode(',', array_unique(array_map(fn($e) => (int) $e['order_id'], $eta)));
        $from = gmdate('Y-m-d H:i:s', strtotime($since_utc . ' UTC') - self::CONTACT_LOOKBACK_S);

        $contacts = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT order_id, action, created_at FROM $et
              WHERE order_id IN ($oids) AND action IN ('email_logged','call_logged') AND created_at >= %s", $from
        ), ARRAY_A);
        $states = self::decode((array) $wpdb->get_results($wpdb->prepare(
            "SELECT order_id, payload_json, created_at FROM $et
              WHERE order_id IN ($oids) AND action = 'state_changed' AND created_at >= %s", $since_utc
        ), ARRAY_A));

        $rows = self::aggregate(self::classify($eta, $contacts, $states));
        foreach ($rows as &$r) {
            $u = $r['user_id'] ? get_userdata($r['user_id']) : null;
            $r['name'] = $r['user_id'] === 0 ? 'System / unattributed' : ($u ? $u->display_name : 'User #' . $r['user_id']);
        }
        return $rows;
    }

    /**
     * Rows that are ON_TRACK only because the latest ETA change had no basis — hidden from the
     * agent's work queue, but not actually confirmed by anyone.
     */
    public static function parked_count(): int {
        global $wpdb;
        $lt = Ledger_Schema::ledger_table();
        $et = Ledger_Schema::events_table();
        $oids = array_map('intval', (array) $wpdb->get_col("SELECT DISTINCT order_id FROM $lt WHERE state = 'ON_TRACK'"));
        if (!$oids) return 0;
        $in = implode(',', $oids);

        $latest = self::decode((array) $wpdb->get_results(
            "SELECT e.id, e.order_id, e.user_id, e.action, e.source, e.payload_json, e.created_at FROM $et e
               JOIN (SELECT order_id, MAX(id) AS id FROM $et WHERE order_id IN ($in) AND action IN ('eta_set','eta_changed') GROUP BY order_id) m
                 ON m.id = e.id", ARRAY_A
        ));
        // Only human ETA changes can be "parked"; vendor/system-fed changes are not the agent's claim.
        $latest = array_values(array_filter($latest, fn($e) => $e['action'] === 'eta_set'));
        if (!$latest) return 0;

        $contacts = (array) $wpdb->get_results(
            "SELECT order_id, action, created_at FROM $et WHERE order_id IN ($in) AND action IN ('email_logged','call_logged')", ARRAY_A
        );
        $n = 0;
        foreach (self::classify($latest, $contacts, []) as $e) {
            if ($e['basis'] === 'none') $n++;
        }
        return $n;
    }

    public static function chronic_rows(): array {
        global $wpdb;
        $lt = Ledger_Schema::ledger_table();
        $et = Ledger_Schema::events_table();
        $rank = array_flip(['CALL_REQUIRED', 'NEEDS_ETA', 'SHIPPED_NOT_CLOSED', 'EXCEPTION', 'MISSING_TRX', 'ON_TRACK']);

        $open = [];
        foreach ((array) $wpdb->get_results("SELECT order_id, trx, eta, state FROM $lt WHERE state NOT IN ('CLOSED','')", ARRAY_A) as $r) {
            $oid = (int) $r['order_id'];
            if (!isset($open[$oid]) || ($rank[$r['state']] ?? 9) < ($rank[$open[$oid]['state']] ?? 9)) $open[$oid] = $r;
        }
        if (!$open) return [];
        $in  = implode(',', array_keys($open));
        $eta = self::decode((array) $wpdb->get_results(
            "SELECT order_id, payload_json FROM $et WHERE order_id IN ($in) AND action IN ('eta_set','eta_changed') ORDER BY created_at ASC, id ASC", ARRAY_A
        ));
        $today = (new DateTimeImmutable('today', new DateTimeZone(Ledger_State_Resolver::TZ)))->format('Y-m-d');
        return self::chronic($open, $eta, $today);
    }

    public static function shipments_since(string $since_utc): int {
        global $wpdb;
        $et = Ledger_Schema::events_table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT order_id) FROM $et WHERE action = 'shipment_recorded' AND source <> 'backfill' AND created_at >= %s", $since_utc
        ));
    }

    private static function decode(array $rows): array {
        foreach ($rows as &$r) {
            $r['payload'] = json_decode((string) ($r['payload_json'] ?? ''), true) ?: [];
            unset($r['payload_json']);
        }
        return $rows;
    }
}
