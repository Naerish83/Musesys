<?php
defined('ABSPATH') || exit;

if (!defined('MUSESYS_FAVORITES_MAX')) define('MUSESYS_FAVORITES_MAX', 15);

if (!function_exists('musesys_normalize_addresses')) {
function musesys_normalize_addresses($list, $type='store'){
    $out = [];
    foreach ((array)$list as $row){
        if (!is_array($row)) continue;
        
        // --- SYLVA FIX: THE DATA SANITIZER ---
        // If the JSON has the Number in 'first_name' (e.g. "20") and Name in 'last_name',
        // we SWAP them right here so the rest of the system gets clean data.
        if ( 
            isset($row['first_name']) && is_numeric($row['first_name']) && 
            isset($row['last_name']) && !is_numeric($row['last_name']) 
        ) {
            $temp_num = $row['first_name'];
            $temp_name = $row['last_name'];
            
            // Swap them to the correct WooCommerce standard (First=Name, Last=Number/ID)
            $row['first_name'] = $temp_name; 
            $row['last_name']  = $temp_num;
        }
        // -------------------------------------

        // Improved lookup: Fall back to first_name/last_name if "Store Name" keys are missing
        $name = isset($row['Store Name']) ? $row['Store Name'] : (isset($row['Name']) ? $row['Name'] : (isset($row['first_name']) ? $row['first_name'] : ''));
        $num  = isset($row['Store #']) ? $row['Store #'] : (isset($row['Number']) ? $row['Number'] : (isset($row['last_name']) ? $row['last_name'] : ''));
        
        $addr = isset($row['Address line 1']) ? $row['Address line 1'] : (isset($row['Address'])?$row['Address'] : (isset($row['address_1'])?$row['address_1']:''));
        $city = isset($row['City'])?$row['City'] : (isset($row['city'])?$row['city']:'');
        $state= isset($row['State'])?$row['State'] : (isset($row['state'])?$row['state']:'');
        $zip  = isset($row['Zip'])?$row['Zip'] : (isset($row['postcode'])?$row['postcode']:'');
        
        $label_num = $num ? ('#' . $num . ' – ') : '';
        $slug_src = strtolower(trim($type.'|'.$num.'|'.$name.'|'.$addr.'|'.$city.'|'.$state.'|'.$zip));
        $id = substr(md5($slug_src),0,12);
        
        $row['_id'] = $id;
        $row['_type'] = $type;
        $row['_label'] = trim($label_num . $name);
        
        // Ensure the clean name/num are accessible to the selector script if it needs them
        $row['store_name_clean'] = $name;
        $row['store_number_clean'] = $num;
        
        $out[] = $row;
    }
    return $out;
}}

function musesys_get_all_addresses_normalized(){
    $stores = musesys_normalize_addresses( get_option('musesys_addresses_stores', []), 'store' );
    $whs    = musesys_normalize_addresses( get_option('musesys_addresses_warehouses', []), 'warehouse' );
    return ['stores'=>$stores, 'warehouses'=>$whs];
}

function musesys_current_user_favorites(){
    $uid = get_current_user_id();
    $fav = ($uid)? get_user_meta($uid,'musesys_favorites', true) : [];
    if (!is_array($fav)) $fav = [];
    return $fav;
}

function musesys_set_current_user_favorites($ids){
    $uid = get_current_user_id();
    if (!$uid) return new WP_Error('not_logged_in', 'Not logged in', ['status'=>401]);
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique($ids));
    if (count($ids) > MUSESYS_FAVORITES_MAX) {
        return new WP_Error('too_many', 'Favorite limit exceeded', ['status'=>400, 'limit'=>MUSESYS_FAVORITES_MAX]);
    }
    update_user_meta($uid,'musesys_favorites', $ids);
    return $ids;
}

add_action('rest_api_init', function () {
    register_rest_route('musesys/v1','/addresses/(?P<set>stores|warehouses)', [
        'methods'=>'GET',
        'permission_callback'=>function(){ return current_user_can('read'); },
        'callback'=>function($req){
            $set = $req['set'];
            $data = musesys_get_all_addresses_normalized();
            return rest_ensure_response($data[$set]);
        }
    ]);
    register_rest_route('musesys/v1','/favorites', [
        'methods'=>'GET',
        'permission_callback'=>function(){ return is_user_logged_in(); },
        'callback'=>function(){ return rest_ensure_response(musesys_current_user_favorites()); }
    ]);
    register_rest_route('musesys/v1','/favorites', [
        'methods'=>'POST',
        'permission_callback'=>function(){ return is_user_logged_in(); },
        'callback'=>function($req){
            $ids = $req->get_param('ids');
            $res = musesys_set_current_user_favorites($ids);
            if (is_wp_error($res)) return $res;
            return rest_ensure_response(['ok'=>true,'ids'=>$res, 'limit'=>MUSESYS_FAVORITES_MAX]);
        }
    ]);
});
?>