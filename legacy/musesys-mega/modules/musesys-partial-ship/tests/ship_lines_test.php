<?php
/**
 * The ship-lines handler and the metabox save against a stand-in for WooCommerce's order object
 * (LEDGER_SPEC.md §15.10, R5). No WordPress, no database.
 *
 * What is real here: this module's index.php and the completion guard in order-fields/muse-order-fields.php,
 * both loaded unchanged. What is a stand-in: WC_Order below. It copies the WooCommerce behaviour that matters,
 * read from the WooCommerce source (abstract-wc-order.php / class-wc-order.php, tag 11.3.1, the version the
 * site runs, 2026-11-23): a callback on woocommerce_before_order_object_save that throws is caught inside
 * save(), nothing is written, save() returns normally, and get_last_save_error() (11.2.2+) holds the exception.
 * The status-change step runs all the same: WC_Order::save() is "parent::save(); $this->status_transition();",
 * so after a REFUSED save the status hooks still fire and the order still gets the note "Order status changed
 * from X to Y." (seen on staging order 240035). WooCommerce trunk skips that step after a refused save; the
 * WC_Order_Trunk stand-in covers it.
 *
 * NOT a substitute for a staging run: the real data store, HPOS, the REST layer and the e-mails are not here.
 *
 * Run: php modules/musesys-partial-ship/tests/ship_lines_test.php
 * PSP_FILE=<path> runs the same cases against another copy of index.php (used to show the cases catch the
 * defect in the copy from before the fix).
 */

define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/lib.php';

/* ---------------- WordPress hooks, just enough ---------------- */

$GLOBALS['__hooks'] = [];

function add_filter($tag, $cb, $priority = 10, $args = 1) {
    $GLOBALS['__hooks'][$tag][$priority][] = [$cb, $args];
    ksort($GLOBALS['__hooks'][$tag]);
    return true;
}
function add_action($tag, $cb, $priority = 10, $args = 1) { return add_filter($tag, $cb, $priority, $args); }
function remove_filter($tag, $cb, $priority = 10) {
    foreach ($GLOBALS['__hooks'][$tag][$priority] ?? [] as $i => $h) {
        if ($h[0] === $cb) { unset($GLOBALS['__hooks'][$tag][$priority][$i]); return true; }
    }
    return false;
}
function apply_filters($tag, $value, ...$rest) {
    foreach ($GLOBALS['__hooks'][$tag] ?? [] as $list) {
        foreach ($list as $h) {
            $value = call_user_func_array($h[0], array_slice(array_merge([$value], $rest), 0, $h[1]));
        }
    }
    return $value;
}
function do_action($tag, ...$args) {
    foreach ($GLOBALS['__hooks'][$tag] ?? [] as $list) {
        foreach ($list as $h) call_user_func_array($h[0], array_slice($args, 0, $h[1]));
    }
}
function listeners($tag): int {
    $n = 0;
    foreach ($GLOBALS['__hooks'][$tag] ?? [] as $list) $n += count($list);
    return $n;
}

function wp_json_encode($v) { return json_encode($v); }
function wp_verify_nonce($nonce, $action) { return true; }
function current_user_can(...$a) { return true; }
function sanitize_text_field($s) { return trim((string) $s); }
function current_time($format) { return '2026-11-23 10:00:00'; }
function esc_html($s) { return (string) $s; }
function esc_url($s) { return (string) $s; }
function wc_get_order_status_name($s) { return ucwords(str_replace('-', ' ', preg_replace('/^wc-/', '', (string) $s))); }

/**
 * WooCommerce's mailer, as far as the order-screen box asks it: is the "completed order" customer e-mail on?
 * $GLOBALS['__completed_email'] is 'on' (WooCommerce's default), 'off' (the Wegmans site), or 'missing'
 * (the e-mail is not registered at all).
 */
class Fake_Email {
    public function is_enabled() { return $GLOBALS['__completed_email'] === 'on'; }
}
class Fake_Mailer {
    public function get_emails() {
        return $GLOBALS['__completed_email'] === 'missing' ? [] : ['WC_Email_Customer_Completed_Order' => new Fake_Email()];
    }
}
class Fake_WooCommerce {
    public function mailer() { return new Fake_Mailer(); }
}
function WC() { return new Fake_WooCommerce(); }
$GLOBALS['__completed_email'] = 'on';

class WP_Error {
    public $code; public $message; public $data;
    public function __construct($code = '', $message = '', $data = []) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
class WC_Admin_Notices {
    public static $notices = [];
    public static function add_custom_notice($name, $html) { self::$notices[] = [$name, $html]; }
    public static function remove_notice($name) {
        self::$notices = array_values(array_filter(self::$notices, function ($n) use ($name) { return $n[0] !== $name; }));
    }
}

/* ---------------- The stand-in order ---------------- */

class Fake_Item {
    private $name; private $qty;
    public function __construct($name, $qty) { $this->name = $name; $this->qty = $qty; }
    public function get_name() { return $this->name; }
    public function get_quantity() { return $this->qty; }
}

/** An order as WooCommerce before 11.2.2 presents it: a refused save leaves no trace on the object. */
class WC_Order {
    public static $db = [];          // id => ['status' => , 'meta' => [], 'items' => [item_id => [name, qty]]]
    public static $notes = [];       // [order_id, text, is_customer_note]
    public static $transitions = []; // [order_id, from, to]: status changes ANNOUNCED (hooks + note), stored or not
    public static $writes = 0;

    protected $id; protected $status; protected $meta; protected $last_error = null;
    protected $transition = null;    // set by set_status(), announced and cleared by save()

    public function __construct($id) {
        $this->id = (int) $id;
        $this->status = self::$db[$this->id]['status'];
        $this->meta = self::$db[$this->id]['meta'];
    }
    public function get_id() { return $this->id; }
    public function get_order_number() { return (string) $this->id; }
    public function get_status() { return $this->status; }
    public function set_status($s) {
        $new = preg_replace('/^wc-/', '', (string) $s);
        if ($new !== $this->status) {
            $this->transition = ['from' => $this->transition['from'] ?? $this->status, 'to' => $new];
        }
        $this->status = $new;
    }
    public function has_status($s) { return in_array($this->status, (array) $s, true); }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function get_items() {
        $out = [];
        foreach (self::$db[$this->id]['items'] as $iid => $it) $out[$iid] = new Fake_Item($it[0], $it[1]);
        return $out;
    }
    public function get_item($iid) {
        $it = self::$db[$this->id]['items'][$iid] ?? null;
        return $it ? new Fake_Item($it[0], $it[1]) : false;
    }
    /** Written at once, whatever happens to the order afterwards (wp_insert_comment in WooCommerce). */
    public function add_order_note($note, $is_customer_note = 0) {
        self::$notes[] = [$this->id, (string) $note, (int) $is_customer_note];
    }
    public function save() {
        $this->last_error = null;
        try {
            do_action('woocommerce_before_order_object_save', $this, null);
            self::$db[$this->id]['status'] = $this->status;
            self::$db[$this->id]['meta'] = $this->meta;
            self::$writes++;
            do_action('woocommerce_after_order_object_save', $this, null);
        } catch (Exception $e) {
            $this->last_error = $e;
            apply_filters('woocommerce_logger_log_message', 'Error saving order ID ' . $this->id . '.', 'error', ['order' => $this, 'error' => $e], null);
            $this->add_order_note('Error saving order ID ' . $this->id . '. ' . $e->getMessage());
        }
        if ($this->announces_after_refusal() || $this->last_error === null) {
            $this->status_transition();
        }
        return $this->id;
    }
    /** 11.3.1 and earlier: the status-change step runs after every save, refused or not. */
    protected function announces_after_refusal(): bool { return true; }

    /** WC_Order::status_transition(): the hooks and the note, from what set_status() recorded, not from storage. */
    protected function status_transition() {
        $t = $this->transition;
        $this->transition = null;
        if (!$t) return;
        self::$transitions[] = [$this->id, $t['from'], $t['to']];
        do_action('woocommerce_order_status_' . $t['to'], $this->id, $this);
        $this->add_order_note('Order status changed from ' . wc_get_order_status_name($t['from']) . ' to ' . wc_get_order_status_name($t['to']) . '.');
        do_action('woocommerce_order_status_changed', $this->id, $t['from'], $t['to'], $this);
    }
}

/** WooCommerce 11.2.2 to 11.3.1 (the site): the refused save is readable from the object. */
class WC_Order_Modern extends WC_Order {
    public function get_last_save_error() { return $this->last_error; }
}

/** WooCommerce trunk (2026-11-23): no status-change step after a refused save. */
class WC_Order_Trunk extends WC_Order_Modern {
    protected function announces_after_refusal(): bool { return false; }
}

/** A save that writes nothing and reports nothing: only the read-back can catch it. */
class WC_Order_Mute extends WC_Order_Modern {
    public function save() { $this->last_error = null; return $this->id; }
}

$GLOBALS['__order_class'] = 'WC_Order_Modern';
$GLOBALS['__object_cache'] = null;   // array => wc_get_order hands back the same object every time

function wc_get_order($id) {
    $id = (int) $id;
    if (!isset(WC_Order::$db[$id])) return false;
    if (is_array($GLOBALS['__object_cache'])) {
        if (!isset($GLOBALS['__object_cache'][$id])) $GLOBALS['__object_cache'][$id] = new $GLOBALS['__order_class']($id);
        return $GLOBALS['__object_cache'][$id];
    }
    return new $GLOBALS['__order_class']($id);
}

/* ---------------- Load the real code ---------------- */

$modules = dirname(__DIR__, 2);
require $modules . '/order-fields/muse-order-fields.php';      // the completion guard, unchanged
require getenv('PSP_FILE') ?: dirname(__DIR__) . '/index.php';

/** The module's own object, taken from the hook it registered. */
$psp = $GLOBALS['__hooks']['rest_api_init'][10][0][0][0];

$GLOBALS['__sc'] = [];
add_action('muse_send_sc_automation', function ($order_id, $event, $data) {
    $GLOBALS['__sc'][] = [$order_id, $event, $data['shipment']['tracking_numbers'] ?? null];
}, 10, 3);

/* ---------------- Helpers ---------------- */

function reset_world(string $class = 'WC_Order_Modern', bool $object_cache = false): void {
    WC_Order::$db = [];
    WC_Order::$notes = [];
    WC_Order::$transitions = [];
    WC_Order::$writes = 0;
    WC_Admin_Notices::$notices = [];
    $GLOBALS['__sc'] = [];
    $GLOBALS['__order_class'] = $class;
    $GLOBALS['__object_cache'] = $object_cache ? [] : null;
    $GLOBALS['__completed_email'] = 'on';
}

/** Two lines: 2 gaskets (item 11) and 1 motor (item 12). */
function order(int $id, string $status, array $meta): void {
    WC_Order::$db[$id] = ['status' => $status, 'meta' => $meta, 'items' => [11 => ['Gasket', 2], 12 => ['Motor', 1]]];
}
function everything(): array {
    return [['line_item_id' => 11, 'ship_qty' => 2], ['line_item_id' => 12, 'ship_qty' => 1]];
}
function stored_shipments(int $id): array {
    return json_decode(WC_Order::$db[$id]['meta']['_muse_shipments'] ?? '[]', true) ?: [];
}
function notes_like(string $needle): int {
    $n = 0;
    foreach (WC_Order::$notes as $note) if (strpos($note[1], $needle) !== false) $n++;
    return $n;
}
function ship($psp, int $id, array $body) {
    return $psp->rest_ship_lines(['id' => $id] + $body);
}

const LEGACY_KEYS = ['order_id', 'status', 'shipped', 'total', 'shipments', 'flat_status'];

/* ==================== orders that may complete: unchanged ==================== */

echo "ship-lines: orders that complete normally\n";

t('the last quantity with a TRX # completes the order, as before', function () use ($psp) {
    reset_world();
    order(1, 'processing', ['_trx_order_number' => '12345678']);
    $r = ship($psp, 1, ['lines' => everything(), 'carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST1'], 'notify' => true]);

    ok(is_array($r), 'expected a success body');
    eq(LEGACY_KEYS, array_keys($r), 'the body has exactly the keys it always had');
    eq('completed', $r['status']);
    eq(3, $r['shipped']);
    eq(3, $r['total']);
    eq('Shipped', $r['flat_status']);
    eq([['carrier' => 'UPS', 'other_carrier' => '', 'tracking_numbers' => ['1ZTEST1'], 'items' => [11 => 2, 12 => 1]]], $r['shipments']);

    eq('completed', WC_Order::$db[1]['status'], 'stored status');
    eq(1, count(stored_shipments(1)), 'stored shipment');
    eq('1ZTEST1', WC_Order::$db[1]['meta']['_muse_ship_track']);
    eq([[1, 'processing', 'completed']], WC_Order::$transitions, 'one status change, straight to completed');
    eq([[1, 'parts_shipped', ['1ZTEST1']]], $GLOBALS['__sc'], 'Service Channel told once');
    eq(1, notes_like('[Multi Shipment/API] New shipment added'), 'one shipment note');
    eq(1, WC_Order::$notes[0][2], 'the shipment note is the customer-visible one');
    eq(0, notes_like('was NOT changed to'), 'no correcting note on an order that completed');
    eq(2, count(WC_Order::$notes), 'the shipment note and WooCommerce\'s status note, nothing else');
});

t('closeout with a TRX # completes even when quantity is left', function () use ($psp) {
    reset_world();
    order(2, 'processing', ['_trx_order_number' => '12345678']);
    $r = ship($psp, 2, ['lines' => [['line_item_id' => 11, 'ship_qty' => 1]], 'carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST2'], 'closeout' => true]);
    eq(LEGACY_KEYS, array_keys($r));
    eq('completed', $r['status']);
    eq(1, $r['shipped']);
    eq('completed', WC_Order::$db[2]['status']);
    eq([[2, 'processing', 'completed']], WC_Order::$transitions);
});

t('a partial shipment sets partially-shipped; no TRX # is needed for that', function () use ($psp) {
    reset_world();
    order(3, 'processing', []);
    $r = ship($psp, 3, ['lines' => [['line_item_id' => 11, 'ship_qty' => 1]], 'carrier' => 'FEDEX', 'tracking_numbers' => ['123456789012']]);
    eq(LEGACY_KEYS, array_keys($r));
    eq('partially-shipped', $r['status']);
    eq('Partial', $r['flat_status']);
    eq('partially-shipped', WC_Order::$db[3]['status']);
    eq(1, count(stored_shipments(3)));
    eq(1, count($GLOBALS['__sc']));
});

t('a second partial shipment on a partially-shipped order saves once and stays partially-shipped', function () use ($psp) {
    reset_world();
    order(4, 'processing', []);
    ship($psp, 4, ['lines' => [['line_item_id' => 11, 'ship_qty' => 1]], 'carrier' => 'UPS', 'tracking_numbers' => ['1ZA']]);
    $writes = WC_Order::$writes;
    $r = ship($psp, 4, ['lines' => [['line_item_id' => 11, 'ship_qty' => 1]], 'carrier' => 'UPS', 'tracking_numbers' => ['1ZB']]);
    eq('partially-shipped', $r['status']);
    eq(2, $r['shipped']);
    eq(2, count(stored_shipments(4)));
    eq(1, WC_Order::$writes - $writes, 'one write for a shipment that does not move the status');
    eq(1, count(WC_Order::$transitions), 'no second status change');
});

t('technician delivery needs no tracking and tells Service Channel nothing', function () use ($psp) {
    reset_world();
    order(5, 'processing', ['_trx_order_number' => '12345678']);
    $r = ship($psp, 5, ['lines' => everything(), 'carrier' => 'TECH_DELIVERY']);
    eq('completed', $r['status']);
    eq('Tech_Possession', $r['flat_status']);
    eq('Delivered by Technician', WC_Order::$db[5]['meta']['_muse_ship_track']);
    eq([], $GLOBALS['__sc']);
});

t('a call that ships nothing still recomputes and saves, as before', function () use ($psp) {
    reset_world();
    order(6, 'processing', []);
    $r = ship($psp, 6, ['lines' => []]);
    eq(LEGACY_KEYS, array_keys($r));
    eq('processing', $r['status']);
    eq(0, $r['shipped']);
    eq(1, WC_Order::$writes);
    eq([], WC_Order::$notes);
});

/* ==================== completion refused: the shipment survives ==================== */

echo "\nship-lines: completion refused (R5)\n";

t('the last quantity with no TRX #: shipment kept, order not completed, and the answer says so', function () use ($psp) {
    reset_world();
    order(10, 'processing', []);
    $r = ship($psp, 10, ['lines' => everything(), 'carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST10'], 'notify' => true]);

    ok(is_array($r), 'the shipment was kept, so this is not an error');
    eq('processing', $r['status'], 'the status the order really has');
    eq('completed', $r['status_wanted']);
    ok(strpos($r['status_blocked'], 'TRX Order # must be entered') !== false, 'the guard\'s reason: ' . $r['status_blocked']);
    eq(3, $r['shipped']);
    eq(1, count($r['shipments']));

    eq('processing', WC_Order::$db[10]['status'], 'stored status untouched');
    eq(1, count(stored_shipments(10)), 'the shipment is in storage');
    eq('1ZTEST10', WC_Order::$db[10]['meta']['_muse_ship_track']);
    eq($r['flat_status'], WC_Order::$db[10]['meta']['_muse_track_status'], 'flat status answered from storage');
    eq([[10, 'parts_shipped', ['1ZTEST10']]], $GLOBALS['__sc'], 'the parts did ship: Service Channel told once');
    eq(1, notes_like('Failed to mark as complete'), 'the guard\'s own note explains it on the order');
});

t('WooCommerce 11.3.1 still announces the refused completion; the handler\'s note corrects the history', function () use ($psp) {
    reset_world();
    order(16, 'processing', []);
    ship($psp, 16, ['lines' => everything(), 'carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST16'], 'notify' => true]);

    eq('processing', WC_Order::$db[16]['status'], 'not completed in storage');
    eq([[16, 'processing', 'completed']], WC_Order::$transitions, 'WooCommerce announces the change all the same (hooks fired)');
    eq(1, notes_like('Order status changed from Processing to Completed.'), 'and writes its note for a change that was not stored');

    $texts = array_column(WC_Order::$notes, 1);
    $last = end(WC_Order::$notes);
    ok(strpos($last[1], 'The order was NOT changed to Completed; it is still Processing.') !== false, 'the last note says what is true: ' . $last[1]);
    ok(strpos($last[1], 'TRX Order # must be entered') !== false, 'with the reason');
    eq(0, $last[2], 'it is a private note, not a customer e-mail');
    eq(1, notes_like('was NOT changed to'), 'written once');
    ok(array_search('Order status changed from Processing to Completed.', $texts, true) < count($texts) - 1, 'and it comes after WooCommerce\'s note');
});

t('WooCommerce trunk (no announcement after a refused save): same answer, same correcting note', function () use ($psp) {
    reset_world('WC_Order_Trunk');
    order(17, 'processing', []);
    $r = ship($psp, 17, ['lines' => everything(), 'carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST17']]);
    eq('processing', $r['status']);
    eq('completed', $r['status_wanted']);
    eq(1, count(stored_shipments(17)));
    eq([], WC_Order::$transitions, 'nothing announced');
    eq(0, notes_like('Order status changed from'));
    eq(1, notes_like('was NOT changed to Completed'));
});

t('a drop-ship order with a TRX # but no PO # is refused the same way', function () use ($psp) {
    reset_world();
    order(11, 'partially-shipped', ['_trx_order_number' => '12345678', '_is_drop_ship' => 'yes']);
    $r = ship($psp, 11, ['lines' => everything(), 'carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST11']]);
    eq('partially-shipped', $r['status']);
    ok(strpos($r['status_blocked'], 'Purchase Order # is required') !== false, $r['status_blocked']);
    eq(1, count(stored_shipments(11)));
    eq('partially-shipped', WC_Order::$db[11]['status']);
});

t('closeout with no TRX # is refused; the answer does not claim completed', function () use ($psp) {
    reset_world();
    order(12, 'processing', []);
    $r = ship($psp, 12, ['lines' => [['line_item_id' => 11, 'ship_qty' => 1]], 'carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST12'], 'closeout' => true]);
    eq('processing', $r['status']);
    eq('completed', $r['status_wanted']);
    eq(1, count(stored_shipments(12)));
    eq('processing', WC_Order::$db[12]['status']);
});

t('once the TRX # is on the order, the next call completes it', function () use ($psp) {
    reset_world();
    order(13, 'processing', []);
    ship($psp, 13, ['lines' => everything(), 'carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST13']]);
    WC_Order::$db[13]['meta']['_trx_order_number'] = '12345678';
    $r = ship($psp, 13, ['lines' => []]);
    eq(LEGACY_KEYS, array_keys($r));
    eq('completed', $r['status']);
    eq('completed', WC_Order::$db[13]['status']);
    eq(1, count(stored_shipments(13)), 'no second shipment');
});

t('WooCommerce before 11.2.2 (no get_last_save_error): the reason still comes through', function () use ($psp) {
    reset_world('WC_Order');
    order(14, 'processing', []);
    $r = ship($psp, 14, ['lines' => everything(), 'carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST14']]);
    eq('processing', $r['status']);
    ok(strpos($r['status_blocked'], 'TRX Order # must be entered') !== false, $r['status_blocked']);
    eq(1, count(stored_shipments(14)));
    eq(0, listeners('woocommerce_logger_log_message'), 'the temporary log listener is removed');
});

t('an object cache that hands back the same object does not turn the refusal into "completed"', function () use ($psp) {
    reset_world('WC_Order_Modern', true);
    order(15, 'processing', []);
    $r = ship($psp, 15, ['lines' => everything(), 'carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST15']]);
    eq('processing', $r['status']);
    eq('completed', $r['status_wanted']);
    eq('processing', WC_Order::$db[15]['status']);
    eq(1, count(stored_shipments(15)));
});

/* ==================== the shipment itself refused: an error, and silence ==================== */

echo "\nship-lines: the shipment itself is refused\n";

t('an order that refuses every save (completed, no TRX #): 409, nothing stored, nobody told', function () use ($psp) {
    reset_world();
    order(20, 'completed', []);   // completed before the rule existed; the guard now refuses any save of it
    $r = ship($psp, 20, ['carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST20'], 'notify' => true]);

    ok($r instanceof WP_Error, 'expected an error, got ' . json_encode($r));
    eq(409, $r->data['status']);
    eq('muse_shipment_not_saved', $r->code);
    ok(strpos($r->message, 'TRX Order # must be entered') !== false, 'the reason is in the message: ' . $r->message);
    eq([], stored_shipments(20), 'nothing stored');
    eq([], $GLOBALS['__sc'], 'Service Channel not told');
    eq(0, notes_like('[Multi Shipment/API] New shipment added'), 'no note claiming a shipment');
    eq(0, count(array_filter(WC_Order::$notes, function ($n) { return $n[2] === 1; })), 'no customer e-mail note');
});

t('the same on WooCommerce before 11.2.2', function () use ($psp) {
    reset_world('WC_Order');
    order(21, 'completed', []);
    $r = ship($psp, 21, ['carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST21']]);
    ok($r instanceof WP_Error, 'expected an error');
    eq(409, $r->data['status']);
    ok(strpos($r->message, 'TRX Order # must be entered') !== false, $r->message);
    eq([], $GLOBALS['__sc']);
});

t('the same when an object cache hands back the same object', function () use ($psp) {
    reset_world('WC_Order_Modern', true);
    order(22, 'completed', []);
    $r = ship($psp, 22, ['carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST22']]);
    ok($r instanceof WP_Error, 'expected an error');
    eq([], stored_shipments(22));
    eq([], $GLOBALS['__sc']);
});

t('a save that writes nothing and reports nothing: 409, even with an identical earlier shipment on the order', function () use ($psp) {
    reset_world('WC_Order_Mute');
    $same = ['carrier' => 'UPS', 'other_carrier' => '', 'tracking_numbers' => ['1ZDUP'], 'items' => []];
    order(23, 'partially-shipped', ['_muse_shipments' => json_encode([$same])]);
    $r = ship($psp, 23, ['carrier' => 'UPS', 'tracking_numbers' => ['1ZDUP']]);
    ok($r instanceof WP_Error, 'the earlier copy must not pass for the one that was not saved');
    eq(409, $r->data['status']);
    eq(1, count(stored_shipments(23)));
    eq([], $GLOBALS['__sc']);
    eq(0, notes_like('New shipment added'));
});

t('another request\'s shipment landing ahead of this one does not turn a stored shipment into a 409', function () use ($psp) {
    reset_world();
    order(24, 'processing', []);
    ship($psp, 24, ['lines' => [['line_item_id' => 11, 'ship_qty' => 1]], 'carrier' => 'UPS', 'tracking_numbers' => ['1ZFIRST']]);

    // Between this request's save and its read-back, the stored list gains an entry in front.
    $other = ['carrier' => 'FEDEX', 'other_carrier' => '', 'tracking_numbers' => ['999999999999'], 'items' => []];
    add_action('woocommerce_after_order_object_save', function ($order) use ($other) {
        $list = stored_shipments(24);
        array_unshift($list, $other);
        WC_Order::$db[24]['meta']['_muse_shipments'] = json_encode($list);
    }, 10, 1);
    $r = ship($psp, 24, ['lines' => [['line_item_id' => 11, 'ship_qty' => 1]], 'carrier' => 'UPS', 'tracking_numbers' => ['1ZSECOND']]);
    unset($GLOBALS['__hooks']['woocommerce_after_order_object_save']);

    ok(is_array($r), 'expected success, got ' . json_encode($r));
    eq(3, count($r['shipments']), 'answered from storage: both requests\' shipments');
    eq(['1ZSECOND'], $r['shipments'][2]['tracking_numbers'], 'this one is there, one place later than it was saved');
    eq(2, count($GLOBALS['__sc']), 'Service Channel told once per shipment');
});

t('an unknown order is still a 404', function () use ($psp) {
    reset_world();
    $r = ship($psp, 99, ['lines' => []]);
    ok($r instanceof WP_Error);
    eq(404, $r->data['status']);
});

/* ==================== the metabox ==================== */

echo "\nmetabox save\n";

/** The form as the order screen posts it. "Notify customer" is ticked unless $notify is false. */
function metabox_post(array $ship, string $tracks, bool $notify = true): void {
    $_POST = ['muse_psp_nonce' => 'x', 'muse_psp_ship' => $ship, 'muse_psp_carrier' => 'UPS', 'muse_psp_ship_tracks' => $tracks];
    if ($notify) $_POST['muse_psp_notify'] = '1';
}
function notices_named(string $name): array {
    return array_values(array_filter(WC_Admin_Notices::$notices, function ($n) use ($name) { return $n[0] === $name; }));
}
/** Position of the first note containing $needle, or -1. */
function note_at(string $needle): int {
    foreach (WC_Order::$notes as $i => $note) if (strpos($note[1], $needle) !== false) return $i;
    return -1;
}
function customer_notes(): int {
    return count(array_filter(WC_Order::$notes, function ($n) { return $n[2] === 1; }));
}

t('part of an order: saved, partially-shipped, customer told by the note, Service Channel told (unchanged)', function () use ($psp) {
    reset_world();
    order(31, 'processing', []);
    metabox_post([11 => 1], '1ZTEST31');
    $psp->save_metabox(31, null);

    eq('partially-shipped', WC_Order::$db[31]['status']);
    eq('Partial', WC_Order::$db[31]['meta']['_muse_track_status']);
    eq(1, count(stored_shipments(31)));
    eq([11 => 1], stored_shipments(31)[0]['items']);
    eq([[31, 'parts_shipped', ['1ZTEST31']]], $GLOBALS['__sc']);
    eq([[31, 'processing', 'partially-shipped']], WC_Order::$transitions);
    eq(2, count(WC_Order::$notes), 'the dispatch note and WooCommerce\'s status note, nothing else');
    eq(0, note_at('Shipment Dispatch'), 'the dispatch note comes first, as before');
    eq(1, WC_Order::$notes[0][2], '"notify" ticked and the order not completing: the note goes to the customer');
    eq([], WC_Admin_Notices::$notices);
});

t('a shipment that completes the order, completed e-mail ON: saved, completed, the note held back, Service Channel told (unchanged)', function () use ($psp) {
    reset_world();
    order(30, 'processing', ['_trx_order_number' => '12345678']);
    metabox_post([11 => 2, 12 => 1], '1ZTEST30');
    $psp->save_metabox(30, null);

    eq('completed', WC_Order::$db[30]['status']);
    eq('Shipped', WC_Order::$db[30]['meta']['_muse_track_status']);
    eq(1, count(stored_shipments(30)));
    eq([[30, 'parts_shipped', ['1ZTEST30']]], $GLOBALS['__sc']);
    eq([[30, 'processing', 'completed']], WC_Order::$transitions, 'one status change, straight to completed');
    eq(2, count(WC_Order::$notes), 'the dispatch note and WooCommerce\'s status note, nothing else');
    eq(0, note_at('Shipment Dispatch'));
    eq(0, customer_notes(), '"notify" ticked, but the order completes: WooCommerce\'s completed e-mail tells the customer');
    eq([], WC_Admin_Notices::$notices);
});

t('a further part on a partially-shipped order, "notify" not ticked: one save, private note', function () use ($psp) {
    reset_world();
    order(34, 'partially-shipped', ['_muse_shipments' => json_encode([['carrier' => 'UPS', 'other_carrier' => '', 'tracking_numbers' => ['1ZOLD'], 'items' => [12 => 1]]])]);
    metabox_post([11 => 1], '1ZTEST34', false);
    $psp->save_metabox(34, null);
    eq(2, count(stored_shipments(34)));
    eq('partially-shipped', WC_Order::$db[34]['status']);
    eq(1, WC_Order::$writes, 'the status does not move, so there is no second save');
    eq(1, count(WC_Order::$notes));
    eq(0, customer_notes());
    eq(1, count($GLOBALS['__sc']));
});

echo "\nmetabox save: the dispatch note when the shipment completes the order\n";

t('completed e-mail OFF, "notify" ticked: the dispatch note goes to the customer, as for a partial shipment', function () use ($psp) {
    reset_world();
    $GLOBALS['__completed_email'] = 'off';
    order(40, 'processing', ['_trx_order_number' => '12345678']);
    metabox_post([11 => 2, 12 => 1], '1ZTEST40');
    $psp->save_metabox(40, null);

    eq('completed', WC_Order::$db[40]['status']);
    eq(1, count(stored_shipments(40)));
    eq(2, count(WC_Order::$notes), 'the dispatch note and WooCommerce\'s status note, nothing else');
    eq(0, note_at('Shipment Dispatch'), 'the dispatch note still comes first');
    eq(1, WC_Order::$notes[0][2], 'nothing else tells the customer, so the note is a customer note');
    eq(1, customer_notes(), 'mailed once');
    eq([[40, 'parts_shipped', ['1ZTEST40']]], $GLOBALS['__sc']);
    eq([], WC_Admin_Notices::$notices);
});

t('completed e-mail OFF, "notify" not ticked: never a customer note', function () use ($psp) {
    reset_world();
    $GLOBALS['__completed_email'] = 'off';
    order(41, 'processing', ['_trx_order_number' => '12345678']);
    metabox_post([11 => 2, 12 => 1], '1ZTEST41', false);
    $psp->save_metabox(41, null);
    eq('completed', WC_Order::$db[41]['status']);
    eq(1, notes_like('Shipment Dispatch'));
    eq(0, customer_notes());
});

t('completed e-mail ON, "notify" not ticked: never a customer note', function () use ($psp) {
    reset_world();
    order(42, 'processing', ['_trx_order_number' => '12345678']);
    metabox_post([11 => 2, 12 => 1], '1ZTEST42', false);
    $psp->save_metabox(42, null);
    eq('completed', WC_Order::$db[42]['status']);
    eq(1, notes_like('Shipment Dispatch'));
    eq(0, customer_notes());
});

t('the completed e-mail is not registered at all: treated as off, the note goes to the customer', function () use ($psp) {
    reset_world();
    $GLOBALS['__completed_email'] = 'missing';
    order(43, 'processing', ['_trx_order_number' => '12345678']);
    metabox_post([11 => 2, 12 => 1], '1ZTEST43');
    $psp->save_metabox(43, null);
    eq('completed', WC_Order::$db[43]['status']);
    eq(1, customer_notes());
});

t('completed e-mail OFF, completion blocked (no TRX #), "notify" ticked: the customer is told of the dispatch now', function () use ($psp) {
    reset_world();
    $GLOBALS['__completed_email'] = 'off';
    order(44, 'processing', []);
    metabox_post([11 => 2, 12 => 1], '1ZTEST44');
    $psp->save_metabox(44, null);
    eq(1, count(stored_shipments(44)), 'the shipment is in storage, so the note is true');
    eq('processing', WC_Order::$db[44]['status']);
    eq(1, WC_Order::$notes[note_at('Shipment Dispatch')][2], 'the dispatch note is a customer note');
    eq(1, customer_notes(), 'and the only one: the correction stays private');
    eq(1, notes_like('The order was NOT changed to Completed; it is still Processing.'));
});

echo "\nmetabox save: refused (R5)\n";

t('everything left, no TRX #: the shipment is kept, the order is not completed, and the history says so', function () use ($psp) {
    reset_world();
    order(32, 'processing', []);
    metabox_post([11 => 2, 12 => 1], '1ZTEST32');
    $psp->save_metabox(32, null);

    eq(1, count(stored_shipments(32)), 'the shipment is in storage');
    eq([11 => 2, 12 => 1], stored_shipments(32)[0]['items']);
    eq('1ZTEST32', WC_Order::$db[32]['meta']['_muse_ship_track']);
    eq('processing', WC_Order::$db[32]['status'], 'not completed in storage');
    eq('Pending', WC_Order::$db[32]['meta']['_muse_track_status'], 'the flat status goes with the stored status');
    eq([[32, 'parts_shipped', ['1ZTEST32']]], $GLOBALS['__sc'], 'the parts did ship: Service Channel told once');

    // The order history, in order: dispatch (true now), the guard, WooCommerce's error, WooCommerce's false
    // "changed to Completed" (11.3.1 announces a refused change), and last the correction.
    $dispatch = note_at('Shipment Dispatch');
    $guard    = note_at('Failed to mark as complete');
    $error    = note_at('Error saving order ID 32.');
    $false    = note_at('Order status changed from Processing to Completed.');
    $fix      = note_at('The order was NOT changed to Completed; it is still Processing.');
    eq([0, 1, 2, 3, 4], [$dispatch, $guard, $error, $false, $fix], 'the five notes, in this order');
    eq(5, count(WC_Order::$notes), 'and no others');
    ok(strpos(WC_Order::$notes[$fix][1], '[Multi Shipment] The order was NOT changed') === 0, 'the correction is signed as this box\'s, not the API\'s');
    ok(strpos(WC_Order::$notes[$fix][1], 'TRX Order # must be entered') !== false, 'the correction carries the reason');
    eq(0, WC_Order::$notes[$fix][2], 'the correction is a private note');

    $blocked = notices_named('muse_psp_status_blocked');
    eq(1, count($blocked), 'one notice for the admin');
    ok(strpos($blocked[0][1], 'Order #32: the shipment was recorded, but the order was NOT changed to Completed and is still Processing.') === 0, $blocked[0][1]);
    ok(strpos($blocked[0][1], 'TRX Order # must be entered') !== false, 'with the reason');
    eq([], notices_named('muse_psp_shipment_not_saved'));
});

t('the note rule, completed e-mail ON: "completes" means the shipment asks for completed, so a blocked completion mails no dispatch note either', function () use ($psp) {
    reset_world();
    order(35, 'processing', []);
    metabox_post([11 => 2, 12 => 1], '1ZTEST35');      // "notify" ticked
    $psp->save_metabox(35, null);
    eq(1, notes_like('Shipment Dispatch'), 'the dispatch is on the order');
    eq(0, customer_notes(), 'but not mailed: the completed e-mail carries it once the order completes');

    // The missing field is entered and the order saved again with nothing to ship: it completes.
    WC_Order::$db[35]['meta']['_trx_order_number'] = '12345678';
    metabox_post([], '');
    $psp->save_metabox(35, null);
    eq('completed', WC_Order::$db[35]['status']);
    eq(1, count(stored_shipments(35)), 'no second shipment');
    eq([], notices_named('muse_psp_status_blocked'), 'the notice about the earlier save is gone');
});

t('an order that refuses every save (completed, no TRX #): nothing stored, nothing claims a dispatch, the admin is told', function () use ($psp) {
    reset_world();
    order(33, 'completed', []);   // completed before the rule existed; the guard now refuses any save of it
    metabox_post([11 => 1], '1ZTEST33');
    $psp->save_metabox(33, null);

    eq([], stored_shipments(33), 'nothing stored');
    eq('completed', WC_Order::$db[33]['status']);
    eq(0, notes_like('Shipment Dispatch'), 'no note claiming a dispatch');
    eq(0, customer_notes(), 'no customer e-mail');
    eq([], $GLOBALS['__sc'], 'Service Channel not told');
    eq([], WC_Order::$transitions, 'the status was never touched, so WooCommerce announces nothing');
    eq(0, notes_like('Order status changed from'));
    eq(0, notes_like('was NOT changed to'));

    $lost = notices_named('muse_psp_shipment_not_saved');
    eq(1, count($lost), 'one notice for the admin');
    ok(strpos($lost[0][1], 'Order #33: the shipment was NOT recorded.') === 0, $lost[0][1]);
    ok(strpos($lost[0][1], 'TRX Order # must be entered') !== false, 'with the reason');
    eq([], notices_named('muse_psp_status_blocked'));
});

t('the same on WooCommerce before 11.2.2: the reason still reaches the notice', function () use ($psp) {
    reset_world('WC_Order');
    order(36, 'completed', []);
    metabox_post([11 => 1], '1ZTEST36');
    $psp->save_metabox(36, null);
    eq([], stored_shipments(36));
    eq(0, notes_like('Shipment Dispatch'));
    eq([], $GLOBALS['__sc']);
    $lost = notices_named('muse_psp_shipment_not_saved');
    eq(1, count($lost));
    ok(strpos($lost[0][1], 'TRX Order # must be entered') !== false, $lost[0][1]);
});

t('everything left, no TRX #, when an object cache hands back the same object: still kept, still not completed', function () use ($psp) {
    reset_world('WC_Order_Modern', true);
    order(37, 'processing', []);
    metabox_post([11 => 2, 12 => 1], '1ZTEST37');
    $psp->save_metabox(37, null);
    eq(1, count(stored_shipments(37)));
    eq('processing', WC_Order::$db[37]['status']);
    eq(1, notes_like('The order was NOT changed to Completed; it is still Processing.'));
    eq(1, count(notices_named('muse_psp_status_blocked')));
});

t('everything left, no TRX #, on WooCommerce trunk (no false announcement): same result, same correction', function () use ($psp) {
    reset_world('WC_Order_Trunk');
    order(38, 'processing', []);
    metabox_post([11 => 2, 12 => 1], '1ZTEST38');
    $psp->save_metabox(38, null);
    eq(1, count(stored_shipments(38)));
    eq('processing', WC_Order::$db[38]['status']);
    eq([], WC_Order::$transitions);
    eq(0, notes_like('Order status changed from'));
    eq(1, notes_like('The order was NOT changed to Completed; it is still Processing.'));
    eq(1, count($GLOBALS['__sc']));
});

$_POST = [];
done();
