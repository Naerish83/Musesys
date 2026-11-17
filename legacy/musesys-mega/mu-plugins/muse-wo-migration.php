<?php
/**
 * Plugin Name: Muse Work Order Migration & Metrics
 * Description: One-time migration tools and adoption metrics for the new multi-WO system
 * Version: 1.0.1
 */

if (!defined('ABSPATH')) exit;

/**
 * ADOPTION METRICS
 */
function muse_get_wo_adoption_metrics() {
    global $wpdb;
    
    // Total work orders
    $total_query = "
        SELECT COUNT(*) as total
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_searchable_wo'
    ";
    $total = $wpdb->get_var($total_query);
    
    // SC work orders (9 digits)
    $sc_query = "
        SELECT COUNT(*) as sc_count
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_searchable_wo'
        AND LENGTH(meta_value) = 9
    ";
    $sc_count = $wpdb->get_var($sc_query);
    
    // EAM work orders (7 digits)
    $eam_query = "
        SELECT COUNT(*) as eam_count
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_searchable_wo'
        AND LENGTH(meta_value) = 7
    ";
    $eam_count = $wpdb->get_var($eam_query);
    
    return array(
        'total' => (int)$total,
        'sc' => (int)$sc_count,
        'eam' => (int)$eam_count,
        'sc_percentage' => $total > 0 ? round(($sc_count / $total) * 100, 1) : 0
    );
}

add_action('wp_dashboard_setup', 'muse_add_wo_adoption_widget');
function muse_add_wo_adoption_widget() {
    wp_add_dashboard_widget(
        'muse_wo_adoption',
        '📊 Work Order Adoption (SC vs EAM)',
        'muse_wo_adoption_widget_content'
    );
}

function muse_wo_adoption_widget_content() {
    $metrics = muse_get_wo_adoption_metrics();
    ?>
    <div class="wo-adoption-widget">
        <p><strong>Total Work Orders:</strong> <?php echo $metrics['total']; ?></p>
        <p><strong>Service Channel:</strong> <?php echo $metrics['sc']; ?> (<?php echo $metrics['sc_percentage']; ?>%)</p>
        <p><strong>EAM:</strong> <?php echo $metrics['eam']; ?> (<?php echo 100 - $metrics['sc_percentage']; ?>%)</p>
        
        <?php if ($metrics['eam'] > 0): ?>
        <p style="color:#856404; background:#fff3cd; padding:8px; border-radius:3px; margin-top:10px;">
            ⚠️ <strong><?php echo $metrics['eam']; ?> work orders</strong> still using EAM (being phased out 2025)
        </p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * MIGRATION TOOLS
 */
function muse_migrate_existing_orders_to_multi_wo() {
    $args = array(
        'post_type' => 'shop_order',
        'posts_per_page' => -1,
        'post_status' => 'any',
        'fields' => 'ids' // Only need IDs
    );
    
    $orders = get_posts($args);
    $migrated = 0;
    
    foreach ($orders as $order_id) {
        // Skip if already has new structure
        if (get_post_meta($order_id, '_work_orders', true) && !isset($_GET['force'])) {
            continue;
        }
        
        // Trigger the build function (defined in muse-tracking-core.php)
        if (function_exists('muse_build_work_orders_array')) {
            muse_build_work_orders_array($order_id);
            $migrated++;
        }
    }
    
    return "Migrated {$migrated} orders to new work order structure.";
}

add_action('admin_menu', 'muse_add_migration_page');
function muse_add_migration_page() {
    add_submenu_page(
        'musesys',
        'Migrate Work Orders',
        'Migrate Work Orders',
        'manage_options',
        'muse-migrate-wo',
        'muse_migration_page_content'
    );
}

function muse_migration_page_content() {
    if (isset($_POST['run_migration'])) {
        check_admin_referer('muse_migrate_wo');
        $result = muse_migrate_existing_orders_to_multi_wo();
        echo '<div class="notice notice-success"><p>' . esc_html($result) . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Migrate Work Orders to Multi-WO Structure</h1>
        <p>This will convert all existing orders to use the new multi-work-order structure.</p>
        <p><strong>This is safe to run multiple times</strong> (it skips already-migrated orders).</p>
        <form method="post">
            <?php wp_nonce_field('muse_migrate_wo'); ?>
            <button type="submit" name="run_migration" class="button button-primary">Run Migration</button>
        </form>
    </div>
    <?php
}
