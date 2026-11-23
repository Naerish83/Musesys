<?php
/**
 * Unit tests for the pure half of Ledger_Proposals (LEDGER_SPEC.md §15). No WordPress, no database.
 *
 * Run: php modules/musesys-ledger/tests/proposals_test.php
 */

define('ABSPATH', __DIR__ . '/stubs/');
require __DIR__ . '/lib.php';
require __DIR__ . '/../includes/class-ledger-proposals.php';

function base(array $over = []): array {
    return $over + [
        'source' => 'cairn', 'source_ref' => 'pkg-0001', 'order_id' => 501, 'kind' => 'shipment',
        'carrier' => 'ups', 'tracking_numbers' => ['1Z 999 AA1 01 2345 6784'],
    ];
}
function rows(): array {
    return [
        ['order_item_id' => 11, 'part_label' => 'Gasket', 'qty_ordered' => 5, 'qty_shipped' => 2],
        ['order_item_id' => 12, 'part_label' => 'Motor',  'qty_ordered' => 1, 'qty_shipped' => 0],
        ['order_item_id' => 13, 'part_label' => 'Fan',    'qty_ordered' => 2, 'qty_shipped' => 2],
    ];
}

echo "normalize\n";

t('a minimal shipment proposal is accepted and normalised', function () {
    $p = Ledger_Proposals::normalize(base());
    ok(is_array($p), is_string($p) ? $p : '');
    eq('cairn', $p['source']);
    eq(501, $p['order_id']);
    eq('UPS', $p['payload']['carrier']);
    eq(['1Z 999 AA1 01 2345 6784'], $p['payload']['tracking_numbers']);
    eq([], $p['payload']['lines']);
    eq('', $p['payload']['other_carrier']);
});

t('source, source_ref, order_id and kind are required', function () {
    ok(is_string(Ledger_Proposals::normalize(base(['source' => '']))), 'empty source');
    ok(is_string(Ledger_Proposals::normalize(base(['source' => 'Cairn Two']))), 'source with a space');
    ok(is_string(Ledger_Proposals::normalize(base(['source_ref' => '']))), 'empty ref');
    ok(is_string(Ledger_Proposals::normalize(base(['source_ref' => 'has space']))), 'ref with a space');
    ok(is_string(Ledger_Proposals::normalize(base(['source_ref' => str_repeat('x', 151)]))), 'ref too long');
    ok(is_string(Ledger_Proposals::normalize(base(['order_id' => 0]))), 'order 0');
    ok(is_string(Ledger_Proposals::normalize(base(['kind' => 'eta']))), 'unsupported kind');
});

t('carrier must be a known code; OTHER needs a name', function () {
    ok(is_string(Ledger_Proposals::normalize(base(['carrier' => 'PONY']))), 'unknown carrier');
    ok(is_string(Ledger_Proposals::normalize(base(['carrier' => 'OTHER']))), 'OTHER without a name');
    $p = Ledger_Proposals::normalize(base(['carrier' => 'other', 'other_carrier' => 'SAIA']));
    eq('SAIA', $p['payload']['other_carrier']);
    $p = Ledger_Proposals::normalize(base(['carrier' => 'FEDEX', 'other_carrier' => 'ignored']));
    eq('', $p['payload']['other_carrier'], 'other_carrier is kept only for OTHER');
});

t('internal hand-over carriers cannot be proposed', function () {
    ok(is_string(Ledger_Proposals::normalize(base(['carrier' => 'TECH_DELIVERY']))));
    ok(is_string(Ledger_Proposals::normalize(base(['carrier' => 'CUSTOMER_PICKUP']))));
});

t('tracking numbers: at least one, de-duplicated by letters and digits, capped', function () {
    ok(is_string(Ledger_Proposals::normalize(base(['tracking_numbers' => []]))), 'none');
    ok(is_string(Ledger_Proposals::normalize(base(['tracking_numbers' => ['  ', '']]))), 'blank only');
    ok(is_string(Ledger_Proposals::normalize(base(['tracking_numbers' => 'abc']))), 'not a list');
    ok(is_string(Ledger_Proposals::normalize(base(['tracking_numbers' => [['x']]]))), 'nested');
    ok(is_string(Ledger_Proposals::normalize(base(['tracking_numbers' => ['---']]))), 'no letters or digits');
    $p = Ledger_Proposals::normalize(base(['tracking_numbers' => ['1z999aa10123456784', '1Z 999 AA1 01 2345 6784', '7712 3456 7890']]));
    eq(['1z999aa10123456784', '7712 3456 7890'], $p['payload']['tracking_numbers']);
    ok(is_string(Ledger_Proposals::normalize(base(['tracking_numbers' => array_map(fn($i) => "T$i", range(1, 11))]))), 'eleven numbers');
});

t('lines are optional, accept either naming, and merge repeats', function () {
    $p = Ledger_Proposals::normalize(base(['lines' => [
        ['order_item_id' => 11, 'qty' => 1], ['line_item_id' => 11, 'ship_qty' => 2], ['order_item_id' => 12, 'qty' => 1],
    ]]));
    eq([['order_item_id' => 11, 'qty' => 3], ['order_item_id' => 12, 'qty' => 1]], $p['payload']['lines']);
    ok(is_string(Ledger_Proposals::normalize(base(['lines' => [['order_item_id' => 11, 'qty' => 0]]]))), 'zero quantity');
    ok(is_string(Ledger_Proposals::normalize(base(['lines' => 'all']))), 'lines not a list');
});

t('summary and evidence are display text only: tags stripped, depth and size capped', function () {
    $p = Ledger_Proposals::normalize(base([
        'summary'  => "  <b>True</b>   shipment\nnotice  ",
        'evidence' => [
            'vendor' => '<script>x</script>True', 'accepted' => true, 'count' => 3,
            'deep' => ['a' => ['b' => ['c' => 'dropped']]], 'obj' => new stdClass(), 'bad key!' => 'kept',
        ],
    ]));
    eq('True shipment notice', $p['summary']);
    eq('xTrue', $p['evidence']['vendor']);
    eq(true, $p['evidence']['accepted']);
    eq(3, $p['evidence']['count']);
    eq(['a' => []], $p['evidence']['deep'], 'nesting stops two levels down');
    ok(!array_key_exists('obj', $p['evidence']), 'objects dropped');
    eq('kept', $p['evidence']['badkey']);
    ok(is_string(Ledger_Proposals::normalize(base(['evidence' => 'text']))), 'evidence not an object');
    $big = [];
    for ($i = 0; $i < 40; $i++) $big["k$i"] = str_repeat('x', 400);
    ok(is_string(Ledger_Proposals::normalize(base(['evidence' => $big]))), 'oversized evidence');
    eq(300, strlen(Ledger_Proposals::normalize(base(['summary' => str_repeat('y', 900)]))['summary']));
});

echo "\ntracking comparison\n";

t('norm_tracking keeps letters and digits, upper-cased', function () {
    eq('1Z999AA10123456784', Ledger_Proposals::norm_tracking(' 1z 999-aa1 01 2345 6784 '));
    eq('', Ledger_Proposals::norm_tracking(' - '));
});

t('already_recorded finds numbers on the order whatever their spacing or case', function () {
    $ships = [
        ['carrier' => 'UPS', 'tracking_numbers' => ['1Z999AA10123456784'], 'items' => [11 => 2]],
        'junk',
        ['carrier' => 'FEDEX', 'tracking_numbers' => ['', '7712 3456 7890']],
    ];
    eq(['1z 999 aa1 01 2345 6784'], Ledger_Proposals::already_recorded($ships, ['1z 999 aa1 01 2345 6784', 'NEW1']));
    eq(['771234567890'], Ledger_Proposals::already_recorded($ships, ['771234567890']));
    eq([], Ledger_Proposals::already_recorded($ships, ['NEW1', 'NEW2']));
    eq([], Ledger_Proposals::already_recorded([], ['NEW1']));
});

echo "\nlines\n";

t('suggest_lines: no proposed lines pre-fills everything left; shipped lines are left out', function () {
    $s = Ledger_Proposals::suggest_lines(rows(), []);
    eq(2, count($s));
    eq([11, 3, 3], [$s[0]['order_item_id'], $s[0]['remaining'], $s[0]['suggested']]);
    eq([12, 1, 1], [$s[1]['order_item_id'], $s[1]['remaining'], $s[1]['suggested']]);
});

t('suggest_lines: proposed quantities are clamped to what is left; unnamed lines start at zero', function () {
    $s = Ledger_Proposals::suggest_lines(rows(), [['order_item_id' => 11, 'qty' => 9]]);
    eq(3, $s[0]['suggested']);
    eq(0, $s[1]['suggested']);
});

t('confirm_lines builds the ship-lines payload and refuses anything beyond the order', function () {
    eq([['line_item_id' => 11, 'ship_qty' => 3], ['line_item_id' => 12, 'ship_qty' => 1]], Ledger_Proposals::confirm_lines(rows(), [11 => 3, 12 => 1]));
    eq([['line_item_id' => 12, 'ship_qty' => 1]], Ledger_Proposals::confirm_lines(rows(), [11 => 0, 12 => 1]), 'zero is skipped');
    ok(is_string(Ledger_Proposals::confirm_lines(rows(), [11 => 4])), 'more than left');
    ok(is_string(Ledger_Proposals::confirm_lines(rows(), [13 => 1])), 'line already fully shipped');
    ok(is_string(Ledger_Proposals::confirm_lines(rows(), [99 => 1])), 'line of another order');
    ok(is_string(Ledger_Proposals::confirm_lines(rows(), [])), 'nothing chosen');
    ok(is_string(Ledger_Proposals::confirm_lines(rows(), [11 => 0])), 'only zeros');
});

t('ships_everything is true only when every open quantity is covered', function () {
    $all  = Ledger_Proposals::confirm_lines(rows(), [11 => 3, 12 => 1]);
    $some = Ledger_Proposals::confirm_lines(rows(), [11 => 3]);
    eq(true, Ledger_Proposals::ships_everything(rows(), $all));
    eq(false, Ledger_Proposals::ships_everything(rows(), $some));
});

done();
