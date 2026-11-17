<?php

/**

 * Plugin Name: MuseSys Favorites

 * Description: AJAX-powered favorite toggle (compatible with Wegmans2.0).

 * Version: 1.1.0

 * Author: MuseSystems

 */



if (!defined('ABSPATH')) exit;



class MuseSys_Favorites {



    public static function init() {



        // AJAX actions

        add_action('wp_ajax_muse_toggle_favorite', [__CLASS__, 'toggle_favorite']);

        add_action('wp_ajax_nopriv_muse_toggle_favorite', '__return_false');
        
        // Cleanup Hook
        add_action('admin_init', [__CLASS__, 'check_cleanup']);



        // Render W icon on product pages and loops

        add_action('woocommerce_after_shop_loop_item_title', [__CLASS__, 'render_icon_loop'], 12);

        add_action('woocommerce_single_product_summary', [__CLASS__, 'render_icon_single'], 31);



        // Assets

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

    }



    /* --------------------------------------------------------------

        ASSETS

    -------------------------------------------------------------- */



    public static function enqueue_assets() {



        wp_enqueue_style(

            'muse-fav-style',

            plugin_dir_url(__FILE__) . 'muse-favorites.css',

            [],

            '1.1.0'

        );



        wp_enqueue_script(

            'muse-fav-js',

            plugin_dir_url(__FILE__) . 'muse-favorites.js',

            ['jquery'],

            '1.1.0',

            true

        );



        wp_localize_script('muse-fav-js', 'MuseFavAjax', [

            'ajaxurl' => admin_url('admin-ajax.php'),

            'nonce'   => wp_create_nonce('muse_fav_nonce')

        ]);

    }



    /* --------------------------------------------------------------

        ICON RENDERING (LOOP & SINGLE)

    -------------------------------------------------------------- */



    private static function w_icon_svg() {

        return '

        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">

            <path d="M10 10 L22 54 L32 28 L42 54 L54 10" />

        </svg>';

    }



    private static function is_favorited($product_id) {

        $user_id = get_current_user_id();

        if (!$user_id) return false;

        $favorites = get_user_meta($user_id, 'muse_favorite_parts', true);

        if (!is_array($favorites)) $favorites = [];

        return in_array($product_id, $favorites);

    }



    public static function render_icon_loop() {

        global $product;

        if (!$product) return;

        echo self::render_icon($product->get_id());

    }



    public static function render_icon_single() {

        global $product;

        if (!$product) return;

        echo '<div style="margin:10px 0;">' . self::render_icon($product->get_id()) . '</div>';

    }



    private static function render_icon($product_id) {



        $active = self::is_favorited($product_id);

        $classes = $active ? 'muse-favorite-toggle muse-fav-active'

                           : 'muse-favorite-toggle muse-fav-inactive';



        return '

        <button class="' . $classes . '" 

                data-product-id="' . esc_attr($product_id) . '" 

                aria-label="Toggle Favorite">'

                . self::w_icon_svg() .

        '</button>';

    }



    /* --------------------------------------------------------------

        AJAX HANDLER

    -------------------------------------------------------------- */



    public static function toggle_favorite() {

        check_ajax_referer('muse_fav_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['msg' => 'Not logged in']);

        $pid = intval($_POST['product_id'] ?? 0);
        if (!$pid) wp_send_json_error(['msg' => 'Invalid product']);

        $favorites = get_user_meta($user_id, 'muse_favorite_parts', true);
        if (!is_array($favorites)) $favorites = [];
        
        // Ensure integers consistent
        $favorites = array_map('intval', $favorites);

        if (in_array($pid, $favorites, true)) {
            // Remove
            $favorites = array_diff($favorites, [$pid]);
            $favorites = array_values($favorites); // Re-index
            update_user_meta($user_id, 'muse_favorite_parts', $favorites);
            wp_send_json_success(['status' => 'removed']);
        } else {
            // Add
            $favorites[] = $pid;
            $favorites = array_values(array_unique($favorites)); // Prevent duplicates
            update_user_meta($user_id, 'muse_favorite_parts', $favorites);
            wp_send_json_success(['status' => 'added']);
        }
    }

    /**
     * One-time cleanup tool.
     * Usage: /wp-admin/?musesys_cleanup_favorites=1
     */
    public static function check_cleanup() {
        if (isset($_GET['musesys_cleanup_favorites']) && current_user_can('manage_options')) {
            $users = get_users(['fields' => 'ID']); 
            foreach ($users as $uid) {
                $favs = get_user_meta($uid, 'muse_favorite_parts', true);
                if (is_array($favs) && !empty($favs)) {
                    $unique = array_values(array_unique(array_map('intval', $favs)));
                    if (count($unique) !== count($favs)) {
                        update_user_meta($uid, 'muse_favorite_parts', $unique);
                    }
                }
            }
            wp_die('Favorites cleaned!');
        }
    }

}



MuseSys_Favorites::init();

