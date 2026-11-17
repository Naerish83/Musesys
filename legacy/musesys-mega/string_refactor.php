<?php
$dir = 'c:/Projects/musesys-mega/modules';

$replacements = [
    // 1. Revert any leftover MUSE_META constants we injected earlier to string literals.
    "MUSE_META_WO_GLOBAL"      => "'_muse_wo_global'",
    "MUSE_META_WO_LINE"        => "'_muse_wo_line'",
    "MUSE_META_WO_COLLECTION"  => "'_muse_wo_collection'",
    "MUSE_META_WO_NOTE"        => "'_muse_wo_note'",
    "MUSE_META_STORE_GLOBAL"   => "'_muse_store_global'",
    "MUSE_META_STORE_LINE"     => "'_muse_store_line'",
    "MUSE_META_TRACK_PRIMARY"  => "'_muse_track_primary'",
    "MUSE_META_TRACK_PARTIAL"  => "'_muse_track_partial'",
    "MUSE_META_TRACK_TYPE"     => "'_muse_track_type'",
    "MUSE_META_TRACK_STATUS"   => "'_muse_track_status'",
    "MUSE_META_PR_STATUS"      => "'_muse_pr_status'",
    "MUSE_META_PR_TYPE"        => "'_muse_pr_type'",
    "MUSE_META_PR_ID"          => "'_muse_pr_id'",
    "MUSE_META_PR_ITEMS"       => "'_muse_pr_items'",
    "MUSE_META_CART_DATA"      => "'_musesys_active_cart_data'",
    "MUSE_META_USER_PHONE"     => "'contact_phone'",

    // 2. Perform the exact string replacements commanded by the user (substring safe)
    // Global Work Orders
    '_muse_wo_global' => '_muse_wo_global',
    '_muse_wo_global'     => '_muse_wo_global',
    '_muse_wo_global'         => '_muse_wo_global',

    // Line Item Work Orders
    '_muse_wo_line' => '_muse_wo_line',

    // Work Order Notes
    '_muse_wo_note' => '_muse_wo_note',
    '_muse_wo_note'     => '_muse_wo_note',

    // Install Stores
    '_muse_store_global'            => '_muse_store_global',
    '_muse_store_global'     => '_muse_store_global',
    '_muse_store_global' => '_muse_store_global',

    // Primary Tracking
    '_muse_track_primary'             => '_muse_track_primary',
    '_muse_track_primary' => '_muse_track_primary',

    // Shipping Logic
    '_muse_track_status'        => '_muse_track_status',
    '_muse_track_status'     => '_muse_track_status',
    '_muse_track_status' => '_muse_track_status',

    // Product Requests
    '_muse_pr_status' => '_muse_pr_status',
    '_muse_pr_type'   => '_muse_pr_type',
    '_muse_pr_id'     => '_muse_pr_id',

    // Extra from previous:
    '_musesys_product_items' => '_muse_pr_items',

    // 3. UI Labels
    'Work Order #' => 'Work Order #',
    'TRX ORDER #' => 'WORK ORDER #',
    'TRX Order'   => 'Work Order',
    'TRX ORDER'   => 'WORK ORDER',
];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$updated_count = 0;

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $ext = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
        // We only process PHP and JS files. Ignore disabled or .bak files.
        if (in_array($ext, ['php', 'js'])) {
            $content = file_get_contents($file->getPathname());
            $new_content = strtr($content, $replacements);

            // Also explicitly fix quotes surrounding swapped constants avoiding double quotes issue like `''contact_phone''`
            // if MUSE_META constants were already strings, we might have accidentally created double strings, but PHP's strtr runs once.
            // Let's just fix any instances of double-single quotes just in case.
            $new_content = str_replace("''", "'", $new_content);

            if ($new_content !== $content) {
                file_put_contents($file->getPathname(), $new_content);
                echo "Updated Strings: " . str_replace('c:/Projects/musesys-mega/modules/', '', str_replace('\\', '/', $file->getPathname())) . "\n";
                $updated_count++;
            }
        }
    }
}
echo "Total files updated: $updated_count\n";
