<?php
/**
 * Plugin Name: MuseSys — Addresses Debug
 * Description: Prints where addresses are coming from so King stops guessing.
 * Version: 1.0.0
 * Author: MuseSystems
 */
defined('ABSPATH') || exit;

add_action('plugins_loaded', function(){
  if (!function_exists('musesys_selector_get_addresses')) {
    $paths = [
      WP_CONTENT_DIR.'/plugins/musesys_selector/index.php',
      WP_CONTENT_DIR.'/plugins/musesys-shipping-selector.php',
      WP_CONTENT_DIR.'/mu-plugins/musesys-shipping-selector.php',
    ];
    foreach ($paths as $p) { if (file_exists($p)) { include_once $p; if (function_exists('musesys_selector_get_addresses')) break; } }
  }
});

add_shortcode('musesys_addresses_debug', function(){
  $lines = [];
  if (function_exists('musesys_selector_get_addresses')) {
    $src = (array)musesys_selector_get_addresses();
    $lines[] = 'Provider: stores='. (isset($src['stores'])?count($src['stores']):0) . ' warehouses='.(isset($src['warehouses'])?count($src['warehouses']):0);
  } else {
    $lines[] = 'Provider: MISSING';
  }
  $stores     = get_option('musesys_store_addresses', []);
  $warehouses = get_option('musesys_warehouse_addresses', []);
  $lines[] = 'Options: musesys_store_addresses='. (is_array($stores)?count($stores):0) . ' musesys_warehouse_addresses='. (is_array($warehouses)?count($warehouses):0);

  // Just in case you actually used a single JSON blob by mistake sometime
  $json = get_option('musesys_addresses_json', '');
  $n = 0; if (is_string($json) && $json !== '') { $tmp = json_decode($json, true); if (is_array($tmp)) $n = count($tmp); }
  $lines[] = 'Option: musesys_addresses_json='. $n;

  return '<pre style="background:#111;color:#eee;padding:10px;">'.esc_html(implode("\n",$lines)).'</pre>';
});
