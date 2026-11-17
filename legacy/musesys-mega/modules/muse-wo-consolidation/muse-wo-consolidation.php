<?php
/**
 * Plugin Name: Muse WO Consolidation Tool
 * Description: Backfill tool to consolidate Work Order and Install Store data into unified meta arrays.
 * Version: 0.1.0
 * Author: MuseSys
 */

if (!defined('ABSPATH')) exit;

/**
 * Add Admin Menu
 */
add_action('admin_menu', 'muse_wo_consolidation_menu');
function muse_wo_consolidation_menu() {
    // Attempt to find MuseSys parent, fallback to Tools if not found
    $parent = 'musesys'; // Assuming 'musesys' is the slug for the main menu
    
    // Check if parent exists? For now assume user instruction is correct regarding location.
    // If 'musesys' menu doesn't exist, this might land in top-level or need a check.
    // Spec says: Submenu under "MuseSys" (parent slug: 'musesys')
    
    add_submenu_page(
        'musesys',
        'WO Consolidation',
        'WO Consolidation',
        'manage_options',
        'muse-wo-consolidation',
        'muse_wo_consolidation_page'
    );
}

/**
 * Admin Page Render
 */
function muse_wo_consolidation_page() {
    ?>
    <div class="wrap">
        <h1>Work Order & Install Store Consolidation</h1>
        <p>This tool scans all WooCommerce orders and consolidates Work Order and Install Store data from various meta keys into unified <code>_work_orders</code> and <code>_muse_store_global</code> arrays.</p>
        
        <div class="card" style="max-width: 600px; padding: 20px;">
            <h3>Run Consolidation</h3>
            <p id="status-text">Click the button below to start.</p>
            
            <button id="run-btn" class="button button-primary button-large">Start Consolidation Process</button>
            <div id="progress-bar-wrap" style="margin-top: 20px; background: #f0f0f1; border: 1px solid #ccc; height: 20px; display: none;">
                <div id="progress-bar" style="background: #2271b1; height: 100%; width: 0%;"></div>
            </div>
            <p id="progress-stats" style="margin-top: 10px; font-weight: bold;"></p>
        </div>

        <div id="final-report" style="margin-top: 20px; display: none;">
            <h3>Consolidation Report</h3>
            <textarea id="report-log" style="width: 100%; height: 300px; font-family: monospace;" readonly></textarea>
        </div>
    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var nonce = <?php echo wp_json_encode(wp_create_nonce('muse_wo_consolidation')); ?>;
        var offset = 0;
        var limit = 20; 
        var totalOrders = 0;
        var processed = 0;
        var stats = {
            total: 0,
            updated: 0,
            has_global_wo: 0,
            has_line_wo: 0,
            has_both_wo: 0,
            has_install_store: 0
        };

        $('#run-btn').click(function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Initializing...');
            $('#progress-bar-wrap').show();
            $('#progress-stats').text('Fetching order count...');
            
            // Step 1: Get Total Count
            $.post(ajaxurl, {
                action: 'muse_wo_get_count',
                nonce: nonce
            }, function(response) {
                console.log("Count response:", response);
                if(response.success) {
                    totalOrders = parseInt(response.data.count);
                    stats.total = totalOrders;
                    
                    if (totalOrders === 0) {
                        finishProcess();
                        return;
                    }

                    $('#progress-stats').text('Found ' + totalOrders + ' orders. Starting batch 1...');
                    console.log("Starting batch processing. Total:", totalOrders);
                    
                    // Small delay to ensure UI renders
                    setTimeout(processBatch, 500);
                } else {
                    alert('Error getting order count: ' + (response.data || 'Unknown error'));
                    $btn.prop('disabled', false).text('Start Consolidation Process');
                }
            }).fail(function(xhr, status, error) {
                console.error("Count failed:", error);
                alert('Ajax Request Failed: ' + error);
                $btn.prop('disabled', false).text('Start Consolidation Process');
            });
        });

        function processBatch() {
            console.log("Processing batch. Offset:", offset, "Limit:", limit);
            $.post(ajaxurl, {
                action: 'muse_wo_process_batch',
                nonce: nonce,
                offset: offset,
                limit: limit
            }, function(response) {
                console.log("Batch response:", response);
                if(response.success) {
                    var batchData = response.data;
                    var batchCount = parseInt(batchData.processed_count);
                    
                    if (batchCount === 0 && processed < totalOrders) {
                        // Safety valve: we expected more but got 0. Stop to prevent infinite loops.
                        console.warn("Received 0 items but not finished. Stopping.");
                        finishProcess();
                        return;
                    }

                    processed += batchCount;
                    offset += limit;
                    
                    // Update Stats
                    stats.updated += batchData.stats.updated;
                    stats.has_global_wo += batchData.stats.has_global_wo;
                    stats.has_line_wo += batchData.stats.has_line_wo;
                    stats.has_both_wo += batchData.stats.has_both_wo;
                    stats.has_install_store += batchData.stats.has_install_store;
                    
                    // Update UI
                    var percent = (totalOrders > 0) ? Math.min(100, Math.round((processed / totalOrders) * 100)) : 100;
                    $('#progress-bar').css('width', percent + '%');
                    $('#progress-stats').text('Processed ' + processed + ' / ' + totalOrders + ' orders (' + percent + '%)');

                    // Log to report area (append)
                    var logText = $('#report-log').val();
                    logText += batchData.log;
                    $('#report-log').val(logText);
                    
                    // Auto-scroll textarea
                    var textarea = document.getElementById('report-log');
                    textarea.scrollTop = textarea.scrollHeight;

                    $('#final-report').show();
                    
                    // Recursive Step
                    if (processed < totalOrders) {
                        setTimeout(processBatch, 200); // Slight throttle
                    } else {
                        finishProcess();
                    }
                } else {
                    console.error("Batch error response:", response);
                    $('#report-log').val($('#report-log').val() + "\nError processing batch: " + (response.data || 'Unknown') + "\n");
                    alert('Error processing batch. See console for details.');
                }
            }).fail(function(xhr, status, error) {
                console.error("Batch Ajax failed:", error);
                console.log("Response Text:", xhr.responseText);
                $('#report-log').val($('#report-log').val() + "\nCRITICAL ERROR: " + error + "\nSee console for raw output.\n");
                alert('Critical Error during batch processing. Check console.');
            });
        }

        function finishProcess() {
            $('#run-btn').text('Completed');
            $('#progress-stats').text('Complete! Processed ' + processed + ' orders.');
            $('#progress-bar').css('width', '100%');
            
            var summary = "\n--- FINAL SUMMARY ---\n" +
                          "Total Orders: " + stats.total + "\n" +
                          "Updated: " + stats.updated + "\n" +
                          "With Global WO: " + stats.has_global_wo + "\n" +
                          "With Line WOs: " + stats.has_line_wo + "\n" +
                          "With BOTH: " + stats.has_both_wo + "\n" +
                          "With Install Store: " + stats.has_install_store + "\n";
            
            $('#report-log').val($('#report-log').val() + summary);
            var textarea = document.getElementById('report-log');
            textarea.scrollTop = textarea.scrollHeight;
        }
    });
    </script>
    <?php
}

/**
 * Ajax: Get Order Count
 */
add_action('wp_ajax_muse_wo_get_count', 'muse_wo_get_count_handler');
function muse_wo_get_count_handler() {
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Forbidden', 403);
    }
    check_ajax_referer('muse_wo_consolidation', 'nonce');

    ob_start(); // Buffer any unexpected output
    try {
        // Paginated query returns the total without loading every order ID.
        $result = wc_get_orders(['limit' => 1, 'paginate' => true, 'return' => 'ids']);
    } catch (Exception $e) {
        ob_end_clean();
        wp_send_json_error($e->getMessage());
        return;
    }
    ob_end_clean(); // Discard any buffered output
    wp_send_json_success(['count' => (int) $result->total]);
}

/**
 * Ajax: Process Batch
 */
add_action('wp_ajax_muse_wo_process_batch', 'muse_wo_process_batch_handler');
function muse_wo_process_batch_handler() {
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Forbidden', 403);
    }
    check_ajax_referer('muse_wo_consolidation', 'nonce');

    ob_start(); // Buffer output

    $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
    $limit = isset($_POST['limit']) ? min(absint($_POST['limit']), 50) : 20;
    if ($limit < 1) $limit = 20;
    
    $args = [
        'limit' => $limit,
        'offset' => $offset,
        'orderby' => 'ID',
        'order' => 'ASC', 
        'return' => 'ids'
    ];
    
    $order_ids = wc_get_orders($args);
    
    $batch_log = "";
    $batch_stats = [
        'updated' => 0,
        'has_global_wo' => 0,
        'has_line_wo' => 0,
        'has_both_wo' => 0,
        'has_install_store' => 0
    ];
    
    if (!empty($order_ids)) {
        foreach ($order_ids as $order_id) {
            // Run the consolidation function
            if (function_exists('muse_build_work_orders_array')) {
                muse_build_work_orders_array($order_id);
                $batch_stats['updated']++;
            }
            
            // Inspect results
            $wos = get_post_meta($order_id, '_work_orders', true);
            $stores = get_post_meta($order_id, '_muse_store_global', true);
            
            $has_global = false;
            $has_line = false;
            
            if (!empty($wos) && is_array($wos)) {
                foreach ($wos as $wo) {
                    if (isset($wo['source']) && $wo['source'] === 'global') $has_global = true;
                    if (isset($wo['source']) && ($wo['source'] === 'line_item' || $wo['source'] === 'line')) $has_line = true;
                }
            }
            
            if ($has_global) $batch_stats['has_global_wo']++;
            if ($has_line) $batch_stats['has_line_wo']++;
            if ($has_global && $has_line) $batch_stats['has_both_wo']++;
            
            if (!empty($stores)) $batch_stats['has_install_store']++;
            
            // Brief Log
            $wo_str = ($has_global ? 'G' : '-') . ($has_line ? 'L' : '-');
            $st_str = !empty($stores) ? count($stores) : '0';
            $batch_log .= "Ord #{$order_id}: WO[{$wo_str}] St[{$st_str}]\n";
        }
    }
    
    ob_end_clean(); // Discard junk, keep JSON clean
    
    wp_send_json_success([
        'processed_count' => count($order_ids),
        'stats' => $batch_stats,
        'log' => $batch_log
    ]);
}
