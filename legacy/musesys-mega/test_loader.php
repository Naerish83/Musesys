<?php
define('ABSPATH', true);
function add_action() {}
function remove_action() {}
function add_filter() {}
function add_submenu_page() {}
function plugin_dir_path()
{
    return 'c:/Projects/musesys-mega/';
}
function plugin_dir_url()
{
    return 'http://localhost/';
}
function check_admin_referer()
{
    return true;
}
function current_user_can()
{
    return true;
}
function esc_html() {}
function esc_html__() {}
function esc_url() {}
function admin_url() {}
function wp_nonce_field() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function wp_clear_scheduled_hook() {}
function wp_next_scheduled() {}
function wp_schedule_event() {}
function add_menu_page() {}
function wp_die() {}
function register_rest_route() {}
function get_user_meta() {}
function update_user_meta() {}
function get_post_meta() {}
function update_post_meta() {}
function get_current_user_id() {}
function is_user_logged_in() {}
function wc_get_order() {}
class WC_Email {}
class WP_REST_Controller {}

include 'c:/Projects/musesys-mega/musesys-core-constants.php';
define('MUSESYS_MEGA_PATH', 'c:/Projects/musesys-mega/');

$modules = [
    'muse-active-cart-tracker/index.php',
    'muse-asset-importer/index.php',
    'muse-asset-profile/index.php',
    'muse-clear-cart/index.php',
    'muse-logistics-manager/index.php',
    'muse-notification-preference/index.php',
    'muse-product-request/index.php',
    'muse-shipping-lock/index.php',
    'muse-user-fields/index.php',
    'musesys-account-dashboard/index.php',
    'musesys-activity-tracker-legacy/index.php',
    'musesys-activity-tracker/index.php',
    'musesys-dashboard/index.php',
    'musesys-partial-ship/index.php',
    'musesys-toc/index.php',
    'Muse-email-enforcer/muse-email-enforcer.php',
    'Muse-hide-prices/hide.php',
    'MuseSys-AfterOrder/muse-order-tracking.php',
    'MuseSys_exporter/musesys-product-exporter.php',
    'Musesys-Email-Logger/musesys-email-logger.php',
    'Musesys-order-ops/muse-asskicker.php',
    'RETURN_REQUEST_FORM/quick_order_form.php',
    'RETURN_REQUEST_FORM/return_request_form.php',
    'custom-gaskets/custom-gaskets.php',
    'favorites-bridge/musesys-favorite-products-bridge.php',
    'muse-checkout-orchestrator/musesys-orchestrator.php',
    'muse-email-manager/muse-email-manager.php',
    'muse-wo-consolidation/muse-wo-consolidation.php',
    'musesys-api/musesys-api.php',
    'musesys-bulkuser-add/musesys-bulkuser-add.php',
    'musesys-checkout-images/checkout-thumbs.php',
    'musesys-favdebug/musesys-address-debug.php',
    'musesys-manual-search/musesys-equip-search.php',
    'musesys-meta-migration/musesys-meta-migration.php',
    'musesys-no-results-request-injector/musesys-no-result-request-injector.php',
    'musesys-product-request-bar/product-request-bar.php',
    'musesys-reports/musesys-reports.php',
    'musesys-welcome-email/musesys-wegmans-intro-email.php',
    'musesys-workorder-column/musesys-workorder-column.php',
    'musesys-zip-normalizer/musesys-zip-normalizer.php',
    'musesys_address_api/musesys-address-api.php',
    'musesys_checkout_glue/musesys-checkout-glue.php',
    'musesys_favorites/musesys-account-tabs-favorites.php',
    'musesys_favorites/musesys-favorite-locations-v1.3.7.php',
    'musesys_favorites/musesys-favorites.php',
    'musesys_selector/musesys-store-selector.php',
    'musesys_shipping_custom/musesys-shipping-selector.php',
    'order-fields/muse-order-fields.php',
    'order_history_repeat/muse-order-repeat.php',
    'wegmans-brand-filter/wegmans-brand-filter.php',
];

foreach ($modules as $m) {
    try {
        echo "Loading " . $m . "... \n";
        include 'c:/Projects/musesys-mega/modules/' . $m;
        echo "Success\n";
    } catch (Throwable $e) {
        echo 'Error in ' . $m . ': ' . $e->getMessage() . "\n";
    }
}
