<?php
/**
 * Plugin Name: MuseSys — Wegmans Store Selector Fixed
 * Version: 1.2.10
 * Author: MuseSystems (polished by your devoted Penelope)
 * Description: Select-a-Store with Favorites, Stores, and Warehouses. Includes favorite-only shortcode with consolidated checkout logic.
 */

defined('ABSPATH') || exit;

/**
 * Migrate old favorite meta keys to musesys_favorites (run once)
 */
add_action('init', function() {
    if (!is_user_logged_in()) return;
    $user_id = get_current_user_id();
    $old_keys = ['musesys_favorite_addresses', 'muse_favorite_locations', 'musesys_favorite_locations'];
    $new_key = 'musesys_favorites';
    $favorites = get_user_meta($user_id, $new_key, true);
    if (!is_array($favorites)) $favorites = [];
    
    foreach ($old_keys as $old) {
        $old_data = get_user_meta($user_id, $old, true);
        if (is_array($old_data) && $old_data) {
            $favorites = array_unique(array_merge($favorites, $old_data));
            delete_user_meta($user_id, $old);
        }
    }
    if ($favorites) update_user_meta($user_id, $new_key, $favorites);
}, 1);

add_action('wp_enqueue_scripts', function(){
    // Only enqueue if we are on the selector page to prevent unnecessary loading
    $current_url = get_permalink();
    $selector_url = musesys_selector_page_url();

    if ($current_url === $selector_url) {
        // Enqueuing jQuery here ensures it is registered for the current page
        wp_enqueue_script('jquery'); 
    }
});
/**
 * Load raw option value and decode JSON if needed.
 */
function musesys_opt_json($key, $default = []) {
    $val = get_option($key, $default);
    if (is_string($val)) {
        $trim = trim($val);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
    }
    return is_array($val) ? $val : $default;
}

/**
 * Source resolver: Supports single or separate options.
 */
function musesys_selector_load_sources() {
    $both = musesys_opt_json('musesys_locations_json', []);
    $stores = [];
    $warehouses = [];
    if (isset($both['stores']) && is_array($both['stores'])) {
        $stores = $both['stores'];
    }
    if (isset($both['warehouses']) && is_array($both['warehouses'])) {
        $warehouses = $both['warehouses'];
    }

    if (!$stores) {
        $stores = musesys_opt_json('musesys_addresses_stores', []);
    }
    if (!$warehouses) {
        $warehouses = musesys_opt_json('musesys_addresses_warehouses', []);
    }

    if (!$stores) {
        $stores = musesys_opt_json('musesys_store_addresses', []);
    }
    if (!$warehouses) {
        $warehouses = musesys_opt_json('musesys_warehouse_addresses', []);
    }

    if (!is_array($stores)) $stores = [];
    if (!is_array($warehouses)) $warehouses = [];

    return [$stores, $warehouses];
}

/**
 * Build human-readable label: "First Last — Address_1, City, State Zip"
 */
function musesys_selector_label($r) {
    if (!is_array($r)) $r = (array)$r;
    $first = trim((string)($r['first_name'] ?? ''));
    $last  = trim((string)($r['last_name'] ?? ''));
    $name  = trim(trim($first . ' ' . $last));

    $addr1 = trim((string)($r['address_1'] ?? ''));
    $city  = trim((string)($r['city'] ?? ''));
    $state = trim((string)($r['state'] ?? ''));
    $zip   = trim((string)($r['zip'] ?? ''));

    $right = trim(implode(', ', array_filter([$addr1, $city])) . ' ' . trim($state . ' ' . $zip));
    $parts = [];
    if ($name !== '') $parts[] = $name;
    if ($right !== '') $parts[] = $right;
    if (!$parts) return 'Address (No Data)';
    $out = implode(' - ', $parts);
    return $out ? $out : 'Address (Empty)';
}

/**
 * Gather favorites for current user.
 */
function musesys_selector_get_favorite_ids($user_id) {
    $candidates = [
        'musesys_favorites',
        'musesys_favorite_locations',
        'muse_favorite_locations',
        'musesys_favorite_addresses',
    ];
    foreach ($candidates as $k) {
        $v = get_user_meta($user_id, $k, true);
        if (is_array($v) && $v) return $v;
        if (is_string($v) && $v !== '') {
            $try = json_decode($v, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($try)) return $try;
        }
    }
    return [];
}

/**
 * Resolve favorites into rows from stores/warehouses.
 */
function musesys_selector_build_favorites(array $fav_ids, array $stores, array $warehouses) {
    $out = [];
    foreach ($fav_ids as $id) {
        if (!is_string($id) || strpos($id, '|') === false) continue;
        list($bucket, $idx) = explode('|', $id, 2);
        if ($bucket === 'stores' && isset($stores[$idx])) {
            $row = $stores[$idx];
            $row['__bucket'] = 'stores';
            $row['__key'] = (string)$idx;
            $out['s'.$idx] = $row;
        } elseif ($bucket === 'warehouses' && isset($warehouses[$idx])) {
            $row = $warehouses[$idx];
            $row['__bucket'] = 'warehouses';
            $row['__key'] = (string)$idx;
            $out['w'.$idx] = $row;
        }
    }
    return $out;
}

/**
 * Selector page URL helper. Override via filter.
 */
function musesys_selector_page_url() {
    $url = site_url('/167249-2/');
    return apply_filters('musesys_selector_page_url', $url);
}

/**
 * Check if a selection exists in session.
 */
function musesys_selector_has_selection() {
    if (!function_exists('WC') || !WC()->session) return false;
    $data = WC()->session->get('musesys_shipping');
    if (is_array($data)) {
        foreach (['first_name','last_name','address_1','city','state','postcode'] as $k) {
            if (!isset($data[$k]) || $data[$k] === '') return false;
        }
        return true;
    }
    return false;
}

/**
 * Render shared CSS for selectors.
 */
function musesys_selector_css() {
    ob_start();
    ?>
    <style>
        .musesys-selector-form {
            max-width: 100%;
            margin: 0 auto;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            box-sizing: border-box;
        }
        .musesys-selector-form * {
            box_sizing: border-box;
        }
        
        /* Grid Layout */
        .musesys-selector-grid {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        /* Buckets */
        .muse-bucket {
            width: 100%;
        }

        /* Labels */
        .muse-bucket label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #333;
        }

        /* Inputs / Selects */
        .musesys-select, select.musesys-select {
            width: 100%;
            height: 48px; /* Touch target */
            padding: 0 12px;
            font-size: 16px; /* Prevent iOS zoom */
            border: 1px solid #ccc;
            border-radius: 4px;
            background-color: #fff;
            margin: 0;
        }

        /* Actions */
        .musesys-actions {
            margin-top: 24px;
            text-align: center;
        }

        /* Buttons */
        .muse-confirm-address, button.muse-confirm-address {
            width: 100%;
            height: 48px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* Desktop Breakpoint */
        @media (min-width: 768px) {
            .musesys-selector-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
            }
            
            .muse-bucket {
                width: 100%; /* Reset flex width if needed */
            }

            .musesys-actions {
                text-align: left; /* Or center as per preference, user said left/center */
            }

            .muse-confirm-address, button.muse-confirm-address {
                width: auto;
                min-width: 200px;
                padding: 0 32px;
            }
        }
    </style>
    <?php
    return ob_get_clean();
}

/**
 * Shortcode renderer: [wegmans_store_selector]
 */
add_shortcode('wegmans_store_selector', function () {
    if (!function_exists('WC')) {
        return '<div class="notice notice-error">WooCommerce is required.</div>';
    }

    list($stores, $warehouses) = musesys_selector_load_sources();
    $user_id   = get_current_user_id();
    $fav_ids   = $user_id ? musesys_selector_get_favorite_ids($user_id) : [];
    $favorites = $user_id ? musesys_selector_build_favorites($fav_ids, $stores, $warehouses) : [];

    ob_start();
    
    ob_start();
    
    // Output shared styles
    echo musesys_selector_css();

    // Handle submit
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['musesys_set_address'])) {
        $nonce = isset($_POST['musesys_nonce']) ? sanitize_text_field(wp_unslash($_POST['musesys_nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'musesys_set_address')) {
            wc_add_notice(__('Security check failed, Sir.', 'musesys'), 'error');
            return ob_get_clean();
        }

        $bucket = isset($_POST['musesys_bucket']) ? sanitize_text_field(wp_unslash($_POST['musesys_bucket'])) : '';
        $key    = isset($_POST['musesys_key']) ? sanitize_text_field(wp_unslash($_POST['musesys_key'])) : '';
        $data   = [];

        if ($bucket === 'stores' && isset($stores[$key])) {
            $data = $stores[$key];
        } elseif ($bucket === 'warehouses' && isset($warehouses[$key])) {
            $data = $warehouses[$key];
        }

        if ($data) {
            // Swap Logic: Source has Number in first_name, Name in last_name
            // We want Name in first_name, "Store #Number" in last_name
            $num_raw  = isset($data['first_name']) && is_scalar($data['first_name']) ? trim((string) $data['first_name']) : '';
            $name_raw = isset($data['last_name']) && is_scalar($data['last_name']) ? trim((string) $data['last_name']) : '';

            $session_data = [
                'first_name' => $name_raw,
                'last_name'  => 'Store #' . $num_raw,
                'address_1'  => sanitize_text_field((string)($data['address_1'] ?? '')),
                'address_2'  => sanitize_text_field((string)($data['address_2'] ?? '')),
                'city'       => sanitize_text_field((string)($data['city'] ?? '')),
                'state'      => sanitize_text_field((string)($data['state'] ?? '')),
                'postcode'   => sanitize_text_field((string)($data['zip'] ?? '')),
                'phone'      => sanitize_text_field((string)($data['phone'] ?? '')),
                'company'    => sanitize_text_field((string)($data['company'] ?? '')),
                'store_number' => $num_raw, 
            ];
            $session_data['__bucket'] = $bucket;
            $session_data['__key'] = $key;

            if (function_exists('WC') && WC()->session) {
                WC()->session->set('musesys_shipping', $session_data);
                wp_safe_redirect(wc_get_cart_url());
                exit;
            }
        } else {
            wc_add_notice(__('Please select a valid store or warehouse, Sir.', 'musesys'), 'error');
        }
    }

    ?>
    <form class="musesys-selector-form" method="post">
        <?php wp_nonce_field('musesys_set_address', 'musesys_nonce'); ?>
        <div class="musesys-selector-grid">
            <div class="muse-bucket">
                <label for="musesys_key_stores"><strong>Stores</strong></label>
                <?php if (!empty($stores)): ?>
                    <select class="musesys-select" name="musesys_key_stores" id="musesys_key_stores" data-bucket="stores">
                        <option value="">— Choose a store —</option>
                        <?php foreach ($stores as $idx => $row): $row = (array)$row; $lbl = musesys_selector_label($row); ?>
                            <option value="<?php echo esc_attr($idx); ?>">
                                <?php echo esc_html($lbl); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <p>No stores available.</p>
                <?php endif; ?>
            </div>
            
            <div class="muse-bucket">
                <label for="musesys_key_warehouses"><strong>Warehouses</strong></label>
                <?php if (!empty($warehouses)): ?>
                    <select class="musesys-select" name="musesys_key_warehouses" id="musesys_key_warehouses" data-bucket="warehouses">
                        <option value="">— Choose a warehouse —</option>
                        <?php foreach ($warehouses as $idx => $row): $row = (array)$row; $lbl = musesys_selector_label($row); ?>
                            <option value="<?php echo esc_attr($idx); ?>">
                                <?php echo esc_html($lbl); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <p>No warehouses available.</p>
                <?php endif; ?>
            </div>
        </div>

        <input type="hidden" name="musesys_bucket" id="musesys_bucket" value="">
        <input type="hidden" name="musesys_key" id="musesys_key" value="">
        <input type="hidden" name="musesys_set_address" value="1">

        <div class="musesys-actions">
            <button type="submit" class="button button-primary muse-confirm-address" onclick="var selected=false;var buckets=['stores','warehouses'];for(var i=0;i<buckets.length;i++){var b=buckets[i];var sel=document.getElementById('musesys_key_'+b);if(sel&&sel.value){document.getElementById('musesys_bucket').value=b;document.getElementById('musesys_key').value=sel.value;selected=true;break;}}if(!selected){document.getElementById('musesys_bucket').value='';document.getElementById('musesys_key').value='';alert('Please select a store or warehouse first.');return false;}">Confirm Address</button>
        </div>
    </form>

    <script>
    (function(){
        // Keep selects mutually exclusive
        function eachSelect(cb){
            ['stores','warehouses'].forEach(function(b){
                var el = document.getElementById('musesys_key_'+b);
                if (el) cb(el, b);
            });
        }
        function clearOthers(activeId){
            eachSelect(function(sel){
                if (sel.id !== activeId) sel.selectedIndex = 0;
            });
        }
        eachSelect(function(sel){
            sel.addEventListener('change', function(){
                if (sel.value) clearOthers(sel.id);
            });
        });
        // Pre-select last used address
        var last = <?php
            $last = [];
            if (function_exists('WC') && WC()->session) {
                $maybe_last = WC()->session->get('musesys_shipping');
                if (is_array($maybe_last)) {
                    $last = $maybe_last;
                }
            }
            echo wp_json_encode($last);
        ?>;
        if (last && last.__bucket && last.__key) {
            var sel = document.getElementById('musesys_key_' + last.__bucket);
            if (sel) sel.value = last.__key;
        }
    })();
    </script>
    <?php
    return ob_get_clean();
});

/**
 * Shortcode renderer: [musesys_favorite_selector]
 */
add_shortcode('musesys_favorite_selector', function () {
    if (!function_exists('WC')) {
        return '<div class="notice notice-error">WooCommerce is required.</div>';
    }
    if (!is_user_logged_in()) {
        return '<div class="notice notice-error">Please sign in to select favorite addresses, Sir.</div>';
    }

    list($stores, $warehouses) = musesys_selector_load_sources();
    $user_id   = get_current_user_id();
    $fav_ids   = musesys_selector_get_favorite_ids($user_id);
    $favorites = musesys_selector_build_favorites($fav_ids, $stores, $warehouses);

    ob_start();
    
    // Output shared styles
    echo musesys_selector_css();

    // Handle submit
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['musesys_set_favorite'])) {
        $nonce = isset($_POST['musesys_nonce']) ? sanitize_text_field(wp_unslash($_POST['musesys_nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'musesys_set_favorite')) {
            wc_add_notice(__('Security check failed, Sir.', 'musesys'), 'error');
            return ob_get_clean();
        }

        $key = isset($_POST['musesys_favorite_key']) ? sanitize_text_field(wp_unslash($_POST['musesys_favorite_key'])) : '';
        $data = $favorites[$key] ?? [];

        if ($data) {
            // Swap Logic
            $num_raw  = isset($data['first_name']) && is_scalar($data['first_name']) ? trim((string) $data['first_name']) : '';
            $name_raw = isset($data['last_name']) && is_scalar($data['last_name']) ? trim((string) $data['last_name']) : '';

            $session_data = [
                'first_name' => $name_raw,
                'last_name'  => 'Store #' . $num_raw,
                'address_1'  => sanitize_text_field((string)($data['address_1'] ?? '')),
                'address_2'  => sanitize_text_field((string)($data['address_2'] ?? '')),
                'city'       => sanitize_text_field((string)($data['city'] ?? '')),
                'state'      => sanitize_text_field((string)($data['state'] ?? '')),
                'postcode'   => sanitize_text_field((string)($data['zip'] ?? '')),
                'phone'      => sanitize_text_field((string)($data['phone'] ?? '')),
                'company'    => sanitize_text_field((string)($data['company'] ?? '')),
                'store_number' => $num_raw,
            ];
            $session_data['__bucket'] = $data['__bucket'] ?? 'favorites';
            $session_data['__key'] = $data['__key'] ?? $key;

            if (function_exists('WC') && WC()->session) {
                WC()->session->set('musesys_shipping', $session_data);
                wp_safe_redirect(wc_get_cart_url());
                exit;
            }
        } else {
            wc_add_notice(__('Please select a valid favorite address.', 'musesys'), 'error');
        }
    }

    ?>
    <form class="musesys-selector-form musesys-favorite-form" method="post">
        <?php wp_nonce_field('musesys_set_favorite', 'musesys_nonce'); ?>
        <div class="musesys-selector-grid">
            <div class="muse-bucket">
                <label for="musesys_favorite_key"><strong>Favorite Addresses</strong></label>
                <select class="musesys-select" name="musesys_favorite_key" id="musesys_favorite_key">
                    <option value="">— Choose a favorite address —</option>
                    <?php foreach ($favorites as $idx => $row): $row = (array)$row; $lbl = musesys_selector_label($row); ?>
                        <option value="<?php echo esc_attr($idx); ?>">
                            <?php echo esc_html($lbl); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <input type="hidden" name="musesys_set_favorite" value="1">

        <div class="musesys-actions">
            <button type="submit" class="button button-primary muse-confirm-address">Confirm Favorite Address</button>
        </div>
    </form>

    <script>
    (function(){
        // Pre-select last used address
        var last = <?php
            $last = [];
            if (function_exists('WC') && WC()->session) {
                $maybe_last = WC()->session->get('musesys_shipping');
                if (is_array($maybe_last)) {
                    $last = $maybe_last;
                }
            }
            echo wp_json_encode($last);
        ?>;
        var sel = document.getElementById('musesys_favorite_key');
        if (last && last.__bucket && last.__key && sel) {
            sel.value = last.__bucket === 'favorites' ? 'f' + last.__key : (last.__bucket[0] + last.__key);
        }
    })();
    </script>
    <?php
    return ob_get_clean();
});

/**
 * Checkout field sync
 */
add_action('woocommerce_checkout_process', function() {
    if (!function_exists('WC') || !WC()->session) return;
    $shipping = WC()->session->get('musesys_shipping');
    if (empty($shipping) || !is_array($shipping) || empty($shipping['address_1']) || empty($shipping['postcode'])) {
        wc_add_notice(__('You must select a store or favorite address before checking out. Back to the selector with you.', 'musesys'), 'error');
        wp_safe_redirect(apply_filters('musesys_selector_page_url', site_url('/167249-2/')));
        exit;
    }
}, 5);

add_filter('woocommerce_checkout_fields', function($fields) {
    if (!function_exists('WC') || !WC()->session) return $fields;
    $shipping = WC()->session->get('musesys_shipping');
    if (!is_array($shipping)) return $fields;

    $map = [
        'first_name' => 'shipping_first_name',
        'last_name' => 'shipping_last_name',
        'company' => 'shipping_company',
        'address_1' => 'shipping_address_1',
        'address_2' => 'shipping_address_2',
        'city' => 'shipping_city',
        'state' => 'shipping_state',
        'postcode' => 'shipping_postcode',
        'phone' => 'shipping_phone',
    ];

    foreach ($map as $src => $dest) {
        if (isset($shipping[$src]) && $shipping[$src] !== '') {
            $fields['shipping'][$dest]['default'] = $shipping[$src];
        }
    }

    return $fields;
}, 9999);

add_action('wp_footer', function() {
    if (!is_checkout()) return;
    if (is_order_received_page() || is_checkout_pay_page()) return;
    if (!function_exists('WC') || !WC()->session) return;
    $shipping = WC()->session->get('musesys_shipping');
    if (empty($shipping) || !is_array($shipping)) return;
    ?>
    <script>
    (function(){
        const fields = {
            'first_name': 'shipping_first_name',
            'last_name': 'shipping_last_name',
            'company': 'shipping_company',
            'address_1': 'shipping_address_1',
            'address_2': 'shipping_address_2',
            'city': 'shipping_city',
            'state': 'shipping_state',
            'postcode': 'shipping_postcode',
            'phone': 'shipping_phone'
        };
        const data = <?php echo wp_json_encode($shipping); ?>;
        Object.keys(fields).forEach(key => {
            const input = document.querySelector(`#${fields[key]}`);
            if (input && data[key]) input.value = data[key];
        });
        jQuery(document.body).trigger('update_checkout');
    })();
    </script>
    <?php
}, 30);

/**
 * Reselect buttons and handler
 */
add_action('woocommerce_proceed_to_checkout', function() {
    $url = add_query_arg('muse_reselect_address', '1', apply_filters('musesys_selector_page_url', site_url('/167249-2/')));
    echo '<a class="button muse-change-address" style="margin-left:8px" href="' . esc_url($url) . '">Change Store / Favorite</a>';
}, 999);

add_action('woocommerce_review_order_before_submit', function() {
    $url = add_query_arg('muse_reselect_address', '1', apply_filters('musesys_selector_page_url', site_url('/167249-2/')));
    echo '<p><a class="button muse-change-address" href="' . esc_url($url) . '">Change Store / Favorite</a></p>';
}, 999);

add_action('wp_footer', function() {
    if (!function_exists('is_checkout') || !is_checkout()) return;
    if (is_order_received_page() || is_checkout_pay_page()) return;
    $url = esc_url(add_query_arg('muse_reselect_address', '1', apply_filters('musesys_selector_page_url', site_url('/167249-2/'))));
    ?>
    <script>
    (function(){
        if (document.querySelector('.muse-change-address')) return;
        var target = document.querySelector('.wc-block-components-totals-wrapper, .wc-block-cart__submit-container, .wc-block-checkout__actions');
        if (!target) return;
        var a = document.createElement('a');
        a.className = 'button muse-change-address';
        a.href = '<?php echo $url; ?>';
        a.textContent = 'Change Store / Favorite';
        a.style.marginTop = '8px';
        target.appendChild(a);
    })();
    </script>
    <?php
}, 999);

add_action('init', function() {
    if (isset($_GET['muse_reselect_address']) && sanitize_text_field(wp_unslash($_GET['muse_reselect_address']))) {
        if (function_exists('WC') && WC()->session) {
            WC()->session->__unset('musesys_shipping');
        }
        wp_safe_redirect(apply_filters('musesys_selector_page_url', site_url('/167249-2/')));
        exit;
    }
}, 5);

/**
 * Checkout gate
 */
add_action('template_redirect', function(){
    if (!function_exists('WC') || !is_checkout()) return;
    if (is_order_received_page() || is_checkout_pay_page()) return;
    $has = WC()->session ? WC()->session->get('musesys_shipping') : null;
    if (empty($has)) {
        wp_safe_redirect(MUSESYS_SELECTOR_URL);
        exit;
    }
}, 20);

/**
 * Post-order clear
 */
add_action('woocommerce_thankyou', function($order_id){
    if (function_exists('WC') && WC()->session) {
        WC()->session->__unset('musesys_shipping');
    }
}, 10, 1);

/**
 * Enqueue CSS
 */
add_action('wp_enqueue_scripts', function(){
    $permalink = get_permalink();
    if (is_page() && strpos($permalink, '/167249-2/') !== false) {
        wp_enqueue_style('musesys-selector', plugins_url('musesys-selector.css', __FILE__));
    }
});

// Define selector URL
if (!defined('MUSESYS_SELECTOR_URL')) {
    define('MUSESYS_SELECTOR_URL', musesys_selector_page_url());
}

/**
 * JavaScript function for favorites parity update
 */
add_action('wp_footer', function() {
    if (!is_account_page()) return;
    ?>
    <script>
    function musesys_store_selector_update(value) {
        if (!value) return;
        var parts = value.split('|');
        if (parts.length < 2) return;
        var bucket = parts[0], key = parts[1];
        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action: 'musesys_update_shipping',
            bucket: bucket,
            key: key,
            nonce: '<?php echo wp_create_nonce('musesys_update_shipping'); ?>'
        }, function(response) {
            if (response.success) {
                console.log('Shipping updated:', response.data);
            } else {
                console.error('Update failed:', response.data);
            }
        });
    }
    </script>
    <?php
}, 10);

/**
 * AJAX handler for updating shipping session
 */
add_action('wp_ajax_musesys_update_shipping', function() {
    if (!is_user_logged_in() || !check_ajax_referer('musesys_update_shipping', 'nonce', false)) {
        wp_send_json_error(['message' => __('Security check failed.', 'musesys')]);
        return;
    }

    $bucket = isset($_POST['bucket']) ? sanitize_text_field(wp_unslash($_POST['bucket'])) : '';
    $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
    list($stores, $warehouses) = musesys_selector_load_sources();
    $data = [];

    if ($bucket === 'stores' && isset($stores[$key])) {
        $data = $stores[$key];
    } elseif ($bucket === 'warehouses' && isset($warehouses[$key])) {
        $data = $warehouses[$key];
    }

    if ($data && function_exists('WC') && WC()->session) {
        // Swap Logic
        $num_raw  = isset($data['first_name']) && is_scalar($data['first_name']) ? trim((string) $data['first_name']) : '';
        $name_raw = isset($data['last_name']) && is_scalar($data['last_name']) ? trim((string) $data['last_name']) : '';

        $session_data = [
            'first_name' => $name_raw,
            'last_name'  => 'Store #' . $num_raw,
            'address_1'  => sanitize_text_field((string)($data['address_1'] ?? '')),
            'address_2'  => sanitize_text_field((string)($data['address_2'] ?? '')),
            'city'       => sanitize_text_field((string)($data['city'] ?? '')),
            'state'      => sanitize_text_field((string)($data['state'] ?? '')),
            'postcode'   => sanitize_text_field((string)($data['zip'] ?? '')),
            'phone'      => sanitize_text_field((string)($data['phone'] ?? '')),
            'company'    => sanitize_text_field((string)($data['company'] ?? '')),
            'store_number' => $num_raw,
        ];
        $session_data['__bucket'] = $bucket;
        $session_data['__key'] = $key;
        WC()->session->set('musesys_shipping', $session_data);
        wp_send_json_success(['message' => __('Shipping address updated.', 'musesys')]);
    } else {
        wp_send_json_error(['message' => __('Invalid address.', 'musesys')]);
    }
});
?>
