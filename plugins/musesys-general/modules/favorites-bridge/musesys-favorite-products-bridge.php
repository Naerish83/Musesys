<?php
/**
 * Plugin Name: MuseSys — Favorite Products (Bridge)
 * Description: Restores [musesys_favorite_products] + hearts on product cards. Stores IDs in user_meta 'musesys_favorite_products'.
 * Version: 1.0.1
 */

defined('ABSPATH') || exit;

const MUSE_FAV_PROD_KEY = 'musesys_favorite_products';

/* Storage */
function muse_favp_get_ids($user=0){ $user=$user?:get_current_user_id(); $ids=get_user_meta($user,MUSE_FAV_PROD_KEY,true); return is_array($ids)?array_values(array_unique(array_map('intval',$ids))):[]; }
function muse_favp_set_ids($ids,$user=0){ $user=$user?:get_current_user_id(); update_user_meta($user,MUSE_FAV_PROD_KEY,array_values(array_map('intval',$ids))); }

/* Heart button HTML */
function muse_favp_heart_html($product_id){
  if (!is_user_logged_in()) return '';
  $ids = muse_favp_get_ids();
  $active = in_array((int)$product_id,$ids,true) ? ' data-active="1"' : '';
  return '<button class="muse-favp-heart" data-product="'.esc_attr($product_id).'"'.$active.' aria-label="Toggle favorite">❤</button>';
}

/* Inject heart on archive and single product */
// add_action('woocommerce_after_shop_loop_item_title', function(){ global $product; if ($product) echo muse_favp_heart_html($product->get_id()); }, 20);
// add_action('woocommerce_single_product_summary', function(){ global $product; if ($product) echo '<div style="margin-top:8px">'.muse_favp_heart_html($product->get_id()).'</div>'; }, 35);

/* Assets */
add_action('wp_enqueue_scripts', function(){
  if (!is_user_logged_in()) return;
  wp_add_inline_style('wp-block-library', '.muse-favp-heart{border:0;background:transparent;font-size:20px;cursor:pointer;opacity:.5}.muse-favp-heart[data-active="1"]{opacity:1}.muse-favp-list li{margin:.25rem 0}');
  wp_add_inline_script('jquery-core', "
    (function(){
      function post(id){ return fetch('".esc_url(admin_url('admin-ajax.php'))."',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'muse_favp_toggle',product_id:id}).toString()}).then(r=>r.json()); }
      document.addEventListener('click',function(e){
        var b=e.target.closest('.muse-favp-heart'); if(!b) return;
        e.preventDefault(); var id=b.getAttribute('data-product')||''; if(!id) return;
        post(id).then(function(resp){ if(resp && resp.success){ b.toggleAttribute('data-active'); } else { alert('Login required or server error'); } });
      });
    })();
  ");
}, 20);

/* AJAX toggle */
add_action('wp_ajax_muse_favp_toggle', function(){
  if (!is_user_logged_in()) wp_send_json_error(['msg'=>'login'],403);
  $pid = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
  if ($pid<=0) wp_send_json_error(['msg'=>'bad id'],400);
  $ids = muse_favp_get_ids();
  $pos = array_search($pid,$ids,true);
  if ($pos===false) { $ids[]=$pid; } else { array_splice($ids,$pos,1); }
  muse_favp_set_ids($ids);
  wp_send_json_success(['ids'=>$ids]);
});

/* Shortcode: [musesys_favorite_products] */
add_shortcode('musesys_favorite_products', function(){
  if (!is_user_logged_in()) return '<p>Please sign in to view favorite products.</p>';
  
  // Use new MuseSys Favorites key
  $ids = get_user_meta(get_current_user_id(), 'muse_favorite_parts', true);
  if (!is_array($ids) || empty($ids)) return '<p>No favorite products yet.</p>';
  
  $items = get_posts([
      'post_type'      => 'product',
      'post__in'       => $ids,
      'numberposts'    => 100,        // sane cap
      'no_found_rows'  => true,       // skip SQL_CALC_FOUND_ROWS
      'orderby'        => 'post__in', // preserve user's order
  ]);
  
  ob_start();
  echo '<ul class="muse-favp-list">';
  foreach ($items as $p){
    $link = get_permalink($p->ID);
    // W Icon SVG
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><path d="M10 10 L22 54 L32 28 L42 54 L54 10" /></svg>';
    
    echo '<li>
            <a href="'.esc_url($link).'">'.esc_html(get_the_title($p)).'</a> 
            <button class="muse-favorite-toggle muse-fav-active" data-product-id="'.esc_attr($p->ID).'" aria-label="Toggle Favorite">
                ' . $svg . '
            </button>
          </li>';
  }
  echo '</ul>';
  return ob_get_clean();
});
