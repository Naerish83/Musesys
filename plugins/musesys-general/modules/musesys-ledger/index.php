<?php
/**
 * Module Name: MuseSys Ledger
 * Description: Fulfillment-obligation ledger — current-state projection of order lines, immutable
 *              activity log, and a locked-down agent workspace. Spec: LEDGER_SPEC.md (repo root).
 * Version: 1.1.0
 * Author: MuseSystems
 */

if (!defined('ABSPATH')) exit;

// The mega loader only auto-includes modules/*/*.php, so includes/ are required here.
$musesys_ledger_dir = __DIR__ . '/includes/';
require_once $musesys_ledger_dir . 'class-ledger-state-resolver.php';
require_once $musesys_ledger_dir . 'class-ledger-schema.php';
require_once $musesys_ledger_dir . 'class-ledger-events.php';
require_once $musesys_ledger_dir . 'class-ledger-projector.php';
require_once $musesys_ledger_dir . 'class-ledger-scheduler.php';
require_once $musesys_ledger_dir . 'class-ledger-access.php';
require_once $musesys_ledger_dir . 'class-ledger-ajax.php';
require_once $musesys_ledger_dir . 'class-ledger-page.php';
require_once $musesys_ledger_dir . 'class-ledger-digest.php';
require_once $musesys_ledger_dir . 'class-ledger-audit.php';
require_once $musesys_ledger_dir . 'class-ledger-proposals.php';
require_once $musesys_ledger_dir . 'class-ledger-proposals-ajax.php';
require_once $musesys_ledger_dir . 'class-ledger-rest.php';
unset($musesys_ledger_dir);

// Roles/lockdown must work even if WooCommerce is briefly unavailable.
Ledger_Access::init();

if (class_exists('WooCommerce')) {
    add_action('init', ['Ledger_Schema', 'maybe_install'], 6);
    Ledger_Projector::init();
    Ledger_Scheduler::init();
    Ledger_Ajax::init();
    Ledger_Page::init();
    Ledger_Digest::init();
    Ledger_Proposals_Ajax::init();
    Ledger_Rest::init();
}
