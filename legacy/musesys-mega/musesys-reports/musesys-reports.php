<?php

/**
 * Plugin Name: MuseSys Reports
 * Description: Automated twice-daily reports for Wegmans Orders and Product Requests
 * Version: 1.0.6
 * Author: Cole H & Echo
 * Author URI: https://gerharzequipment.com
 * Text Domain: musesys-reports
 */

if (!defined('ABSPATH')) exit;

define('MUSESYS_REPORTS_VERSION', '1.0.6');
define('MUSESYS_REPORTS_PATH', plugin_dir_path(__FILE__));
define('MUSESYS_REPORTS_URL', plugin_dir_url(__FILE__));

// Recipient list
define('MUSESYS_REPORT_RECIPIENTS', [
    'Aaron.l@gerharzequipment.com',
    'cole.h@gerharzequipment.com',
    'kevin.L@gerharzequipment.com',
    'Chris.MC@gerharzequipment.com',
    'James.K@gerharzequipment.com',
    'Christopher.F@gerharzequipment.com'
]);

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
add_action('init', 'musesys_reports_register_crons'); // Changed name to reflect logic
add_action('admin_menu', 'musesys_reports_admin_menu', 999);
add_action('musesys_morning_report', 'musesys_send_automated_reports');
add_action('musesys_afternoon_report', 'musesys_send_automated_reports');

// Activation hook to explicitly set schedule once
register_activation_hook(__FILE__, 'musesys_reports_activation');

function musesys_reports_activation()
{
    musesys_reports_clear_crons();
    musesys_reports_register_crons();
}

// Deactivation: Clear scheduled events
register_deactivation_hook(__FILE__, 'musesys_reports_clear_crons');

function musesys_reports_clear_crons()
{
    wp_clear_scheduled_hook('musesys_morning_report');
    wp_clear_scheduled_hook('musesys_afternoon_report');
}

/**
 * Register cron events if they don't exist
 */
function musesys_reports_register_crons()
{
    // SYLVA FIX: Only schedule if NOT already scheduled. 
    // Do not clear hooks here. That was causing the "Time Amnesia".

    $eastern = new DateTimeZone('America/New_York');
    $utc = new DateTimeZone('UTC');

    // Morning report: 7:30 AM EST/EDT
    if (!wp_next_scheduled('musesys_morning_report')) {
        $morning_eastern = new DateTime('today 07:30:00', $eastern);
        $now_eastern = new DateTime('now', $eastern);

        // If 7:30 passed today, schedule for tomorrow
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
 * Admin page with manual trigger and status display
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

    // Force Reschedule Button (To fix broken schedules without deactivating)
    $rescheduled = false;
    if (isset($_POST['fix_schedule']) && check_admin_referer('musesys_fix_schedule')) {
        musesys_reports_clear_crons();
        musesys_reports_register_crons();
        $rescheduled = true;
    }

    // Get next scheduled times
    $morning_next = wp_next_scheduled('musesys_morning_report');
    $afternoon_next = wp_next_scheduled('musesys_afternoon_report');

    // Convert to Eastern for display
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
            <h2>?? Manual Test Report</h2>
            <form method="post">
                <?php wp_nonce_field('musesys_send_reports'); ?>
                <p>
                    <button type="submit" name="send_reports" class="button button-primary button-hero" style="margin-top: 10px;">
                        <span class="dashicons dashicons-email" style="margin-top: 6px;"></span> Send Reports Now
                    </button>
                </p>
            </form>
        </div>

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Recipients</h2>
            <ul>
                <?php foreach (MUSESYS_REPORT_RECIPIENTS as $email): ?>
                    <li><?php echo esc_html($email); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
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
