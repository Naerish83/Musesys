<?php
/**
 * Shared stand-ins for the SQL-level tests: a mysqli-backed $wpdb, option storage, stub WC_Order
 * objects and the scratch database connection. Moved here unchanged from integration_test.php.
 *
 * Database: MUSESYS_LEDGER_DB="host:port:user:pass:dbname" (default 127.0.0.1:3399:root::musesys_ledger_scratch).
 */

define('MUSESYS_LEDGER_TESTING', true);
define('ABSPATH', __DIR__ . '/stubs/');
define('ARRAY_A', 'ARRAY_A');
require __DIR__ . '/lib.php';

/* ---------------- WordPress / Woo stand-ins ---------------- */

final class Test_WPDB {
    public $prefix = 'mlt_';
    private $m;
    public function __construct(mysqli $m) { $this->m = $m; }
    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'; }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[sd]/', function ($mt) use (&$i, $args) {
            $v = $args[$i++];
            return $mt[0] === '%d' ? (string) (int) $v : "'" . $this->m->real_escape_string((string) $v) . "'";
        }, $sql);
    }
    public function query($sql) {
        $r = $this->m->query($sql);
        if ($r === false) throw new RuntimeException("SQL error: {$this->m->error}\n$sql");
        return $r === true ? $this->m->affected_rows : $r->num_rows;
    }
    public function get_results($sql, $mode = null) {
        $r = $this->m->query($sql);
        if ($r === false) throw new RuntimeException("SQL error: {$this->m->error}\n$sql");
        return $r->fetch_all(MYSQLI_ASSOC);
    }
    public function get_row($sql, $mode = null) { $rows = $this->get_results($sql); return $rows[0] ?? null; }
    public function get_col($sql) { return array_map(fn($r) => reset($r), $this->get_results($sql)); }
    public function get_var($sql) { $r = $this->get_row($sql); return $r ? reset($r) : null; }
    public function update($table, $data, $where) {
        $set = [];
        foreach ($data as $k => $v) $set[] = "`$k` = " . ($v === null ? 'NULL' : "'" . $this->m->real_escape_string((string) $v) . "'");
        $w = [];
        foreach ($where as $k => $v) $w[] = "`$k` = '" . $this->m->real_escape_string((string) $v) . "'";
        return $this->query("UPDATE $table SET " . implode(', ', $set) . ' WHERE ' . implode(' AND ', $w));
    }
}

$GLOBALS['__opts'] = [];
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function add_option($k, $v, $x = '', $a = null) { if (!array_key_exists($k, $GLOBALS['__opts'])) $GLOBALS['__opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__opts'][$k]); return true; }
function wp_json_encode($v) { return json_encode($v); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function get_post_meta($id, $k, $s = true) { return ''; }
function dbDelta($sql) { $GLOBALS['wpdb']->query($sql); }

class WC_Order_Refund {}
final class WC_Order_Item_Stub {
    public function __construct(private int $pid, private string $name, private int $qty) {}
    public function get_quantity() { return $this->qty; }
    public function get_product_id() { return $this->pid; }
    public function get_name() { return $this->name; }
}
class WC_Order {
    public $status = 'processing';
    public $meta = [];
    public $items = [];
    public $created;
    public function __construct(public int $id) { $this->created = new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('UTC')); }
    public function get_id() { return $this->id; }
    public function get_status() { return $this->status; }
    public function get_meta($k, $single = true) { return $this->meta[$k] ?? ''; }
    public function get_items($type = 'line_item') { return $this->items; }
    public function get_date_created() { return $this->created; }
}
$GLOBALS['__orders'] = [];
function wc_get_order($id) { return $GLOBALS['__orders'][(int) $id] ?? false; }

/* ---------------- connect + load ledger code ---------------- */

[$host, $port, $user, $pass, $db] = explode(':', getenv('MUSESYS_LEDGER_DB') ?: '127.0.0.1:3399:root::musesys_ledger_scratch');
$m = new mysqli($host, $user, $pass, '', (int) $port);
$m->query("CREATE DATABASE IF NOT EXISTS `$db`");
$m->select_db($db);
$m->query('SET time_zone = "+00:00"');
$GLOBALS['wpdb'] = $wpdb = new Test_WPDB($m);

