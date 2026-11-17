<?php
/**
 * Immutable activity log — LEDGER_SPEC.md §3.2.
 * Append and read only. There is deliberately no update or delete method.
 */

if (!defined('ABSPATH')) exit;

final class Ledger_Events {
    const HUMAN_ACTIONS = ['email_logged', 'call_logged', 'note'];
    const MAX_TEXT      = 2000;

    /**
     * Generated event (projector/resolver/backfill). dedupe_key is required; INSERT IGNORE
     * collapses replays and races to one row (§3.3 layer 2).
     */
    public static function append_generated(array $ev): bool {
        if (empty($ev['dedupe_key'])) {
            error_log('MuseSys Ledger: generated event without dedupe_key refused (' . ($ev['action'] ?? '?') . ')');
            return false;
        }
        return self::insert($ev, true);
    }

    /**
     * Human event from the ledger UI. No dedupe key; the UI disables the button in flight.
     */
    public static function append_human(int $order_id, ?int $ledger_id, string $action, string $text, int $user_id, array $extra = []): bool {
        if (!in_array($action, self::HUMAN_ACTIONS, true)) return false;
        $text = mb_substr(sanitize_textarea_field($text), 0, self::MAX_TEXT);
        return self::insert([
            'order_id'      => $order_id,
            'ledger_id'     => $ledger_id,
            'order_item_id' => null,
            'user_id'       => $user_id,
            'source'        => 'ledger_ui',
            'action'        => $action,
            'payload'       => ['text' => $text] + $extra,
            'dedupe_key'    => null,
        ], false);
    }

    private static function insert(array $ev, bool $ignore): bool {
        global $wpdb;
        $t = Ledger_Schema::events_table();
        $cols = [
            'order_id'      => (int) $ev['order_id'],
            'ledger_id'     => isset($ev['ledger_id']) && $ev['ledger_id'] ? (int) $ev['ledger_id'] : null,
            'order_item_id' => isset($ev['order_item_id']) && $ev['order_item_id'] ? (int) $ev['order_item_id'] : null,
            'user_id'       => (int) ($ev['user_id'] ?? 0),
            'source'        => (string) $ev['source'],
            'action'        => (string) $ev['action'],
            'payload_json'  => wp_json_encode($ev['payload'] ?? new stdClass()),
            'dedupe_key'    => $ev['dedupe_key'] ?? null,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ];
        $names = [];
        $vals  = [];
        $args  = [];
        foreach ($cols as $k => $v) {
            $names[] = "`$k`";
            if ($v === null) { $vals[] = 'NULL'; continue; }
            $vals[] = is_int($v) ? '%d' : '%s';
            $args[] = $v;
        }
        $sql = ($ignore ? 'INSERT IGNORE' : 'INSERT') . " INTO $t (" . implode(',', $names) . ') VALUES (' . implode(',', $vals) . ')';
        return (bool) $wpdb->query($wpdb->prepare($sql, $args));
    }

    /** A row's history: its own events plus order-scoped events (§3.2), newest first. */
    public static function for_row(int $ledger_id, int $order_id, int $limit = 200): array {
        global $wpdb;
        $t = Ledger_Schema::events_table();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE ledger_id = %d OR (ledger_id IS NULL AND order_id = %d) ORDER BY created_at DESC, id DESC LIMIT %d",
            $ledger_id, $order_id, $limit
        ), ARRAY_A);
    }

    /** Total ledger-UI actions (all users) in [from, to) UTC. */
    public static function ui_count_between(string $from_utc, string $to_utc): int {
        global $wpdb;
        $t = Ledger_Schema::events_table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE source = 'ledger_ui' AND created_at >= %s AND created_at < %s",
            $from_utc, $to_utc
        ));
    }

    /** Ledger-UI action counts per user and action since a UTC timestamp. */
    public static function ui_counts_since(string $since_utc): array {
        global $wpdb;
        $t = Ledger_Schema::events_table();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, action, COUNT(*) AS n FROM $t WHERE source = 'ledger_ui' AND created_at >= %s GROUP BY user_id, action",
            $since_utc
        ), ARRAY_A);
    }
}
