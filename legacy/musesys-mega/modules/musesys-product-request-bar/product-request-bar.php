<?php
/**
 * Plugin Name: MuseSys Request Bar
 * Description: Global product request bar integrated with the Muse Product Request system. Anchored under OGWegmans header and WooCommerce fallback with duplicate prevention.
 * Version: 1.0.6
 * Author: MuseSystems
 */

if (!defined('ABSPATH')) exit;

class MuseSys_Request_Bar {

    /** Shared duplicate guard across all hooks */
    private static $bar_rendered = false;

    public static function init() {

        // HARD-DISABLED 2026-07-24 (Cole): this module is not in current use.
        // Left in place for reference only. Do not remove this guard without
        // explicit sign-off — it must stay inert even if this file is
        // accidentally redeployed to the Wegmans portal.
        return;

        // Primary injection under theme header
        add_action('shopme_header_after', [__CLASS__, 'inject_bar'], 5);

        // WooCommerce fallback for templates that don't call the header hook
        add_action('woocommerce_before_main_content', [__CLASS__, 'inject_bar'], 1);

        // CSS + JS
        add_action('wp_head', [__CLASS__, 'inject_css'], 20);
        add_action('wp_footer', [__CLASS__, 'inject_js'], 20);

        // Optional shortcode
        add_shortcode('muse_product_request_bar', [__CLASS__, 'render_shortcode']);
    }


    /**
     * Unified injection method
     * Prevents duplicate rendering across all hooks
     */
    public static function inject_bar() {

        if (!is_user_logged_in()) return;

        if (self::$bar_rendered) return;  // <-- GLOBAL GUARD
        self::$bar_rendered = true;

        echo self::render_shortcode();
    }


    /**
     * Shortcode / HTML Layout
     */
    public static function render_shortcode() {
        ob_start(); ?>

        <div id="muse-pr-bar">
            <div class="muse-pr-bar-inner">

                <span class="muse-pr-bar-label">Product Req.</span>

                <form id="muse-pr-bar-form" method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('muse_product_request_submit', 'muse_nonce'); ?>

                    <input type="text" name="eam" placeholder="EAM #" required>
                    <input type="text" name="brand" placeholder="Brand" required>
                    <input type="text" name="model" placeholder="Model #">
                    <input type="text" name="part" placeholder="Part # / Notes" required>

                    <label class="muse-pr-bar-file">
                        <input type="file" name="product_image" accept="image/*">
                        <span class="muse-pr-icon">📷</span>
                    </label>

                    <button type="submit" class="muse-pr-bar-submit">Submit</button>
                </form>

            </div>
        </div>

        <div id="muse-pr-bar-toast">Request submitted successfully.</div>

        <?php return ob_get_clean();
    }


    /**
     * CSS Styling
     */
    public static function inject_css() { ?>
        <style>

            #muse-pr-bar {
                width: 100%;
                background: #ffffff;
                border-bottom: 1px solid #dedede;
                padding: 6px 0;
                z-index: 9999;
                position: relative;
                display: flex;
                justify-content: center;
            }

            #muse-pr-bar .muse-pr-bar-inner {
                width: 100%;
                max-width: 1280px;
                padding: 0 16px;
                display: flex;
                align-items: center;
            }

            .muse-pr-bar-label {
                font-size: 14px;
                font-weight: 600;
                color: #333;
                margin-right: 12px;
                white-space: nowrap;
            }

            #muse-pr-bar-form {
                display: flex;
                gap: 6px;
                align-items: center;
            }

            #muse-pr-bar-form input[type="text"] {
                padding: 4px 6px;
                border: 1px solid #aaa;
                border-radius: 4px;
                font-size: 13px;
                width: 140px;
                background: #fff;
            }
            
            /* Force bar to match OGWegmans container width on Woo pages */
            body.woocommerce #muse-pr-bar .muse-pr-bar-inner,
            body.post-type-archive-product #muse-pr-bar .muse-pr-bar-inner,
            body.tax-product_cat #muse-pr-bar .muse-pr-bar-inner,
            body.tax-product_tag #muse-pr-bar .muse-pr-bar-inner {
                max-width: 1170px !important; /* Exact theme container width */
                margin: 0 auto !important;
                padding-left: 15px;
                padding-right: 15px;
                box-sizing: border-box;
            }


            .muse-pr-bar-file {
                width: 36px;
                height: 36px;
                background: #444;
                border-radius: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
            }
            .muse-pr-bar-file input {
                display: none;
            }
            .muse-pr-icon {
                font-size: 16px;
                color: #fff;
            }

            .muse-pr-bar-submit {
                background: #1d70d6;
                color: #fff;
                border: none;
                padding: 6px 14px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 13px;
                font-weight: 600;
            }
            .muse-pr-bar-submit:hover {
                background: #165aac;
            }

            #muse-pr-bar-toast {
                visibility: hidden;
                min-width: 260px;
                background-color: #323232;
                color: #fff;
                text-align: center;
                border-radius: 6px;
                padding: 12px;
                position: fixed;
                left: 50%;
                bottom: 30px;
                transform: translateX(-50%);
                font-size: 14px;
                opacity: 0;
                z-index: 99999;
                transition: opacity .4s ease-in-out;
            }
            #muse-pr-bar-toast.show {
                visibility: visible;
                opacity: 1;
            }

            /* ===== MOBILE RESPONSIVE ===== */
            @media (max-width: 768px) {
                #muse-pr-bar .muse-pr-bar-inner {
                    flex-direction: column;
                    align-items: flex-start;
                    padding: 8px 12px;
                }
                
                .muse-pr-bar-label {
                    margin-bottom: 6px;
                    margin-right: 0;
                }
                
                #muse-pr-bar-form {
                    width: 100%;
                    flex-wrap: wrap;
                    gap: 8px;
                }
                
                #muse-pr-bar-form input[type="text"] {
                    width: calc(50% - 4px); /* Two columns */
                    min-width: 120px;
                }
                
                .muse-pr-bar-submit {
                    width: 100%;
                    margin-top: 4px;
                }
            }

            /* Extra small screens - single column */
            @media (max-width: 480px) {
                #muse-pr-bar-form input[type="text"] {
                    width: 100%;
                }
            }

        </style>
    <?php }


    /**
     * Toast JS
     */
    public static function inject_js() { ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const toast = document.getElementById('muse-pr-bar-toast');
                const params = new URLSearchParams(window.location.search);

                if (params.get('request_sent') === '1') {
                    toast.classList.add('show');
                    setTimeout(() => toast.classList.remove('show'), 4000);
                }
            });
        </script>
    <?php }
}

// HARD-DISABLED 2026-07-24 (Cole): bootstrap call intentionally commented out
// as a second, independent kill-switch alongside the early return in init().
// MuseSys_Request_Bar::init();
