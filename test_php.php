<?php
$new_item = ['item_id' => '12345', 'link' => 'https://wegmansparts.com/product/eam'];
$clean = [
    'item_id' => '',
    'mapped_link' => 'https://wegmansparts.com/product/eam',
    'link' => 'https://wegmansparts.com/product/eam',
    'mapped_id' => 0,
    'part_num' => '12345'
];

$direct_id = absint($new_item['item_id'] ?? 0);
echo "direct_id: $direct_id\n";
$mapped_id = $direct_id ?: $clean['mapped_id'];
echo "mapped_id: $mapped_id\n";

function absint( $maybe_int ) {
    return abs( (int) $maybe_int );
}
