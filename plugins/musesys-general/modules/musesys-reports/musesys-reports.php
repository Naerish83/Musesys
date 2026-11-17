<?php

/**
 * Plugin Name: MuseSys Reports
 * Description: Automated twice-daily reports for Wegmans Orders and Product Requests
 * Version: 1.0.8
 * Author: Cole H & Echo
 * Author URI: https://gerharzequipment.com
 * Text Domain: musesys-reports
 */

if (!defined('ABSPATH')) exit;

define('MUSESYS_REPORTS_VERSION', '1.0.8');
define('MUSESYS_REPORTS_PATH', plugin_dir_path(__FILE__));
define('MUSESYS_REPORTS_URL', plugin_dir_url(__FILE__));
define('MUSESYS_REPORTS_SNAPSHOT_TABLE_VERSION', '1.0.0');

// Sylva Note: Replaced the static constant with dynamic functions for the admin UI.
function musesys_reports_get_recipients() {
    $defaults = [
        get_option('admin_email') => 1,
    ];
    $recipients = get_option('musesys_report_recipients', $defaults);
    return apply_filters('musesys_report_recipients', $recipients);
}

// Boss, use THIS function in your class-email-sender.php instead of the old constant!
function musesys_get_active_recipients_list() {
    $all = musesys_reports_get_recipients();
    $active = [];
    foreach ($all as $email => $status) {
        if ($status == 1) {
            $active[] = $email;
        }
    }
    return $active;
}

// REMOVED: date_default_timezone_set('America/New_York'); 
// Sylva Note: Never change global timezone in WP. It breaks internal clocks.

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'MuseSys_Reports_';
    if (strpos($class, $prefix) !== 0) return;

    $class_name = str_replace($prefix, '', $class);
    $class_name = strtolower(str_replace('_', '-', $class_name));
    $file = MUSESYS_REPORTS_PATH . 'includes/class-' . $class_name . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Check WooCommerce dependency
if (!class_exists('WooCommerce')) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p><strong>MuseSys Reports</strong> requires WooCommerce to be installed and active.</p></div>';
    });
    return;
}

// Hook into WordPress
add_action('init', 'musesys_reports_register_crons');
add_action('init', 'musesys_reports_maybe_create_snapshot_table');
add_action('admin_menu', 'musesys_reports_admin_menu', 999);
add_action('musesys_morning_report', 'musesys_send_automated_reports');
add_action('musesys_afternoon_report', 'musesys_send_automated_reports');

// Activation hook to explicitly set schedule once
register_activation_hook(__FILE__, 'musesys_reports_activation');

function musesys_reports_activation()
{
    musesys_reports_clear_crons();
    musesys_reports_register_crons();
    // Initialize the recipients list on activation if it doesn't exist
    musesys_reports_get_recipients();
    musesys_reports_create_snapshot_table();
}

// Deactivation: Clear scheduled events
register_deactivation_hook(__FILE__, 'musesys_reports_clear_crons');

function musesys_reports_clear_crons()
{
    wp_clear_scheduled_hook('musesys_morning_report');
    wp_clear_scheduled_hook('musesys_afternoon_report');
}

/**
 * Create (or verify) the order flag snapshot table.
 * One row per flagged order per report run — this is the ledger
 * the chaser-effectiveness stats get diffed against.
 */
function musesys_reports_create_snapshot_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'musesys_order_flag_snapshots';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        snapshot_ts DATETIME NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL,
        order_number VARCHAR(64) NOT NULL,
        bucket VARCHAR(16) NOT NULL,
        flag_reason VARCHAR(32) NULL,
        age_days INT NOT NULL,
        trx_number VARCHAR(64) NULL,
        shipment_count INT NOT NULL DEFAULT 0,
        has_tracking TINYINT(1) NOT NULL DEFAULT 0,
        logistics_note TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY order_snapshot (order_id, snapshot_ts),
        KEY snapshot_ts (snapshot_ts)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('musesys_snapshot_table_version', MUSESYS_REPORTS_SNAPSHOT_TABLE_VERSION);
}

/**
 * Runs on 'init' — creates the snapshot table for installs that were
 * already active before this version, since activation hooks only
 * fire on fresh activation.
 */
function musesys_reports_maybe_create_snapshot_table() {
    if (get_option('musesys_snapshot_table_version') !== MUSESYS_REPORTS_SNAPSHOT_TABLE_VERSION) {
        musesys_reports_create_snapshot_table();
    }
}

/**
 * Persist one snapshot row per flagged order (warning + critical).
 * snapshot_ts is the report's own generated timestamp, not wall-clock
 * insert time, so runs stay chronologically ordered even if cron fires late.
 */
function musesys_write_order_flag_snapshot($orders_data) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'musesys_order_flag_snapshots';

    $eastern = new DateTimeZone('America/New_York');
    $snapshot_ts = (new DateTime('now', $eastern))->format('Y-m-d H:i:s');

    $rows = array_merge(
        array_map(fn($o) => $o + ['bucket' => 'critical'], $orders_data['critical']),
        array_map(fn($o) => $o + ['bucket' => 'warning'], $orders_data['warning'])
    );

    foreach ($rows as $order) {
        $wpdb->insert($table_name, [
            'snapshot_ts'    => $snapshot_ts,
            'order_id'       => $order['order_id'],
            'order_number'   => $order['order_number'],
            'bucket'         => $order['bucket'],
            'flag_reason'    => $order['flag_reason'],
            'age_days'       => $order['age_days'],
            'trx_number'     => $order['trx_number'],
            'shipment_count' => $order['shipment_count'],
            'has_tracking'   => $order['has_tracking'] ? 1 : 0,
            'logistics_note' => $order['logistics_note'],
        ], [
            '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s'
        ]);
    }
}

/**
 * Register cron events if they don't exist
 */
function musesys_reports_register_crons()
{
    $eastern = new DateTimeZone('America/New_York');
    $utc = new DateTimeZone('UTC');

    // Morning report: 7:30 AM EST/EDT
    if (!wp_next_scheduled('musesys_morning_report')) {
        $morning_eastern = new DateTime('today 07:30:00', $eastern);
        $now_eastern = new DateTime('now', $eastern);

        if ($morning_eastern <= $now_eastern) {
            $morning_eastern->modify('+1 day');
        }

        $morning_eastern->setTimezone($utc);
        wp_schedule_event($morning_eastern->getTimestamp(), 'daily', 'musesys_morning_report');
    }

    // Afternoon report: 4:00 PM EST/EDT
    if (!wp_next_scheduled('musesys_afternoon_report')) {
        $afternoon_eastern = new DateTime('today 16:00:00', $eastern);
        $now_eastern = new DateTime('now', $eastern);

        if ($afternoon_eastern <= $now_eastern) {
            $afternoon_eastern->modify('+1 day');
        }

        $afternoon_eastern->setTimezone($utc);
        wp_schedule_event($afternoon_eastern->getTimestamp(), 'daily', 'musesys_afternoon_report');
    }
}

/**
 * Send all reports
 */
function musesys_send_all_reports()
{
    try {
        $email_sender = new MuseSys_Reports_Email_Sender();

        // Generate and send Orders Report
        $orders_report = new MuseSys_Reports_Orders_Report();
        $orders_data = $orders_report->get_flagged_orders();
        musesys_write_order_flag_snapshot($orders_data);
        $email_sender->send_orders_report($orders_data);

        // Generate and send Product Requests Report
        $pr_report = new MuseSys_Reports_PR_Report();
        $pr_data = $pr_report->get_flagged_requests();
        $email_sender->send_pr_report($pr_data);
    } catch (Exception $e) {
        error_log('MuseSys Reports Error: ' . $e->getMessage());
    }
}

/**
 * Send automated reports (respects the toggle)
 */
function musesys_send_automated_reports()
{
    if (get_option('musesys_reports_active', 'yes') !== 'yes') {
        error_log('MuseSys Reports: Automated reporting is temporarily disabled.');
        return;
    }
    musesys_send_all_reports();
}

/**
 * Admin menu - add Reports submenu under MuseSys
 */
function musesys_reports_admin_menu()
{
    add_submenu_page(
        'musesys',
        'MuseSys Reports',
        'Reports',
        'manage_options',
        'musesys-reports',
        'musesys_reports_admin_page'
    );
}

/**
 * Admin page with manual trigger, status display, and recipient management
 */
function musesys_reports_admin_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions', 'musesys'));
    }

    // Handle manual trigger
    $report_sent = false;
    if (isset($_POST['send_reports']) && check_admin_referer('musesys_send_reports')) {
        musesys_send_all_reports();
        $report_sent = true;
    }

    // Handle toggle save
    $toggle_saved = false;
    if (isset($_POST['toggle_reports']) && check_admin_referer('musesys_toggle_reports')) {
        $new_status = isset($_POST['reports_active']) ? 'yes' : 'no';
        update_option('musesys_reports_active', $new_status);
        $toggle_saved = true;
    }

    // Handle recipient updates
    $recipients_updated = false;
    if (isset($_POST['update_recipients']) && check_admin_referer('musesys_update_recipients')) {
        $current_recipients = musesys_reports_get_recipients();
        $submitted_active = isset($_POST['active_recipients']) ? (array) $_POST['active_recipients'] : [];
        $updated_recipients = [];

        // Rebuild array: set checked to 1, unchecked to 0
        foreach ($current_recipients as $email => $status) {
            $updated_recipients[$email] = in_array($email, $submitted_active) ? 1 : 0;
        }

        // Add new recipient if provided
        if (!empty($_POST['new_recipient'])) {
            $new_email = sanitize_email($_POST['new_recipient']);
            if ($new_email && is_email($new_email)) {
                $updated_recipients[$new_email] = 1; 
            }
        }

        update_option('musesys_report_recipients', $updated_recipients);
        $recipients_updated = true;
    }

    // Force Reschedule Button
    $rescheduled = false;
    if (isset($_POST['fix_schedule']) && check_admin_referer('musesys_fix_schedule')) {
        musesys_reports_clear_crons();
        musesys_reports_register_crons();
        $rescheduled = true;
    }

    // --- ONE-TIME MIGRATION: _muse_logistics_eta → _muse_logistics_date ---
    $migration_result = null;
    if (isset($_POST['run_logistics_migration']) && check_admin_referer('musesys_logistics_migration')) {
        $migration_result = musesys_migrate_logistics_dates();
    }

    // Get next scheduled times
    $morning_next = wp_next_scheduled('musesys_morning_report');
    $afternoon_next = wp_next_scheduled('musesys_afternoon_report');

    $eastern = new DateTimeZone('America/New_York');

    if ($morning_next) {
        $morning_dt = new DateTime('@' . $morning_next);
        $morning_dt->setTimezone($eastern);
        $morning_display = $morning_dt->format('l, F j, Y \a\t g:i A T');
    } else {
        $morning_display = '<span style="color: #d63301;">Not scheduled</span>';
    }

    if ($afternoon_next) {
        $afternoon_dt = new DateTime('@' . $afternoon_next);
        $afternoon_dt->setTimezone($eastern);
        $afternoon_display = $afternoon_dt->format('l, F j, Y \a\t g:i A T');
    } else {
        $afternoon_display = '<span style="color: #d63301;">Not scheduled</span>';
    }

    // Fetch the latest recipients for rendering
    $all_recipients = musesys_reports_get_recipients();
?>
    <div class="wrap">
        <h1>?? MuseSys Reports</h1>

        <?php if ($report_sent): ?>
            <div class="notice notice-success is-dismissible" style="margin: 20px 0;">
                <p><strong>?? Reports sent successfully!</strong></p>
            </div>
        <?php endif; ?>

        <?php if ($rescheduled): ?>
            <div class="notice notice-success is-dismissible" style="margin: 20px 0;">
                <p><strong>?? Schedule has been reset and fixed.</strong></p>
            </div>
        <?php endif; ?>

        <?php if ($toggle_saved): ?>
            <div class="notice notice-success is-dismissible" style="margin: 20px 0;">
                <p><strong>?? Automation status saved.</strong></p>
            </div>
        <?php endif; ?>
        
        <?php if ($recipients_updated): ?>
            <div class="notice notice-success is-dismissible" style="margin: 20px 0;">
                <p><strong>?? Recipient list updated successfully.</strong></p>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width: 800px;">
            <h2>Automation Status</h2>
            <?php $is_active = get_option('musesys_reports_active', 'yes') === 'yes'; ?>
            <form method="post" style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee;">
                <?php wp_nonce_field('musesys_toggle_reports'); ?>
                <p>
                    <label>
                        <input type="checkbox" name="reports_active" value="1" <?php checked($is_active, true); ?>>
                        <strong>Enable Automated Daily Reports</strong>
                    </label>
                </p>
                <p class="description">Uncheck this box to temporarily pause automated reports without clearing the schedule.</p>
                <p>
                    <button type="submit" name="toggle_reports" class="button button-primary">Save Status</button>
                </p>
            </form>

            <h2>Automated Schedule</h2>
            <p>Reports are automatically sent twice daily:</p>
            <ul style="line-height: 1.8;">
                <li><strong>Morning Report:</strong> 7:30 AM EST</li>
                <li><strong>Afternoon Report:</strong> 4:00 PM EST</li>
            </ul>

            <h3>Next Scheduled Reports (Database Status)</h3>
            <table class="widefat" style="max-width: 600px;">
                <thead>
                    <tr>
                        <th>Report</th>
                        <th>Next Scheduled Time (Converted to Local)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Morning Report</strong></td>
                        <td><?php echo $morning_display; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Afternoon Report</strong></td>
                        <td><?php echo $afternoon_display; ?></td>
                    </tr>
                </tbody>
            </table>

            <form method="post" style="margin-top: 20px;">
                <?php wp_nonce_field('musesys_fix_schedule'); ?>
                <button type="submit" name="fix_schedule" class="button button-secondary">
                    <span class="dashicons dashicons-update"></span> Reset Schedule Timers
                </button>
                <p class="description">Click this if the times look wrong above.</p>
            </form>
        </div>

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Manage Recipients</h2>
            <p class="description">Check the box next to an email address to include them in the daily reports.</p>
            
            <form method="post">
                <?php wp_nonce_field('musesys_update_recipients'); ?>
                <table class="widefat striped" style="margin-bottom: 15px;">
                    <thead>
                        <tr>
                            <th style="width: 80px; text-align: center;">Active</th>
                            <th>Email Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_recipients as $email => $is_active): ?>
                        <tr>
                            <td style="text-align: center;">
                                <input type="checkbox" name="active_recipients[]" value="<?php echo esc_attr($email); ?>" <?php checked($is_active, 1); ?>>
                            </td>
                            <td><?php echo esc_html($email); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #3582c4; margin-bottom: 15px;">
                    <p style="margin-top: 0;"><strong>Add New Recipient:</strong></p>
                    <input type="email" name="new_recipient" placeholder="new.boss@gerharzequipment.com" style="width: 100%; max-width: 400px; padding: 5px;">
                </div>

                <p>
                    <button type="submit" name="update_recipients" class="button button-primary">
                        <span class="dashicons dashicons-admin-users" style="margin-top: 3px;"></span> Save Recipient List
                    </button>
                </p>
            </form>
        </div>

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>🔧 Manual Test Report</h2>
            <form method="post">
                <?php wp_nonce_field('musesys_send_reports'); ?>
                <p>
                    <button type="submit" name="send_reports" class="button button-primary button-hero" style="margin-top: 10px;">
                        <span class="dashicons dashicons-email" style="margin-top: 6px;"></span> Send Reports Now
                    </button>
                </p>
            </form>
        </div>

        <?php if ($migration_result !== null): ?>
            <div class="notice notice-success is-dismissible" style="margin: 20px 0;">
                <p>
                    <strong>🔄 Migration Complete.</strong>
                    Migrated: <strong><?php echo (int) $migration_result['migrated']; ?></strong> |
                    Skipped (already set): <strong><?php echo (int) $migration_result['skipped']; ?></strong> |
                    No legacy key: <strong><?php echo (int) $migration_result['no_eta']; ?></strong>
                </p>
            </div>
        <?php endif; ?>

        <!-- ONE-TIME MIGRATION TOOL — comment out after use -->
        <div class="card" style="max-width: 800px; margin-top: 20px; border-left: 4px solid #f0ad4e;">
            <h2 style="border-bottom-color: #f0ad4e;">🔄 One-Time: Migrate Logistics Dates</h2>
            <p>Migrates all orders from the legacy <code>_muse_logistics_eta</code> meta key to <code>_muse_logistics_date</code>, normalizing dates to zero-padded <code>YYYY-MM-DD</code> format. Safe to run multiple times — already-migrated orders are skipped.</p>
            <form method="post">
                <?php wp_nonce_field('musesys_logistics_migration'); ?>
                <p>
                    <button type="submit" name="run_logistics_migration" class="button button-secondary button-hero" style="margin-top: 10px; border-color: #f0ad4e; color: #856404;">
                        <span class="dashicons dashicons-migrate" style="margin-top: 6px;"></span> Run Migration Now
                    </button>
                </p>
            </form>
        </div>
        <!-- END ONE-TIME MIGRATION TOOL -->

    </div>

    <style>
        .card {
            background: #fff;
            padding: 20px;
            border: 1px solid #c3c4c7;
            box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
        }

        .card h2 {
            margin-top: 0;
            border-bottom: 2px solid #3582c4;
            padding-bottom: 10px;
        }

        .card h3 {
            margin-top: 20px;
            color: #1d2327;
        }
    </style>
<?php
}

/**
 * ONE-TIME MIGRATION: Copy _muse_logistics_eta → _muse_logistics_date
 * Normalizes date to zero-padded Y-m-d format.
 * Safe to run multiple times — skips orders where _muse_logistics_date already exists.
 */
function musesys_migrate_logistics_dates(): array {
    $migrated = 0;
    $skipped  = 0;
    $no_eta   = 0;

    $orders = wc_get_orders([
        'limit'  => -1,
        'status' => 'any',
    ]);

    foreach ($orders as $order) {
        $existing_new = $order->get_meta('_muse_logistics_date', true);
        $legacy_eta   = $order->get_meta('_muse_logistics_eta', true);

        // Already has the new key populated — skip
        if (!empty($existing_new)) {
            $skipped++;
            continue;
        }

        // No legacy key either — nothing to migrate
        if (empty($legacy_eta)) {
            $no_eta++;
            continue;
        }

        // Attempt to parse and normalize the date
        $normalized = null;
        $formats    = ['Y-m-d', 'Y-n-j', 'm/d/Y', 'n/j/Y', 'Y/m/d', 'Y/n/j'];
        foreach ($formats as $fmt) {
            $parsed = DateTime::createFromFormat($fmt, trim($legacy_eta));
            if ($parsed !== false) {
                $normalized = $parsed->format('Y-m-d');
                break;
            }
        }

        if ($normalized) {
            $order->update_meta_data('_muse_logistics_date', $normalized);
            $order->save_meta_data();
            $migrated++;
        } else {
            // Unparseable date — write raw so it surfaces visibly rather than silently vanishing
            $order->update_meta_data('_muse_logistics_date', $legacy_eta);
            $order->save_meta_data();
            $migrated++;
        }
    }

    return [
        'migrated' => $migrated,
        'skipped'  => $skipped,
        'no_eta'   => $no_eta,
    ];
}