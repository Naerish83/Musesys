<?php
/**
 * Plugin Name: Muse Asset Profile Mode
 * Description: Converts 'shop-assets' products into Reference Only pages (strips commerce, suggestions; adds asset label).
 * Version: 1.0.0
 * Author: MuseSystems
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Muse_Asset_Profile {

    /**
     * Initialize hooks.
     */
    public static function init(): void {
        // Use 'wp' to check conditionals safely after query is set up
        add_action('wp', [__CLASS__, 'apply_asset_mode']);
    }

    /**
     * Apply rules if we are on a shop-asset product.
     */
    public static function apply_asset_mode(): void {
        if (!is_product()) {
            return;
        }

        // Check for 'shop-assets' category (slug)
        if (!has_term('shop-assets', 'product_cat')) {
            return;
        }

        // 1. Strip Commerce (Add to Cart, Price)
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        
        // 2. Strip Suggestions (Related, Upsells)
        remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20);
        remove_action('woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15);
        
        // Some themes use different priorities, trying common ones
        remove_action('woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 10);
        remove_action('woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 20);

        // Cross-sells are typically cart-only, but just in case
        remove_action('woocommerce_after_single_product_summary', 'woocommerce_cross_sell_display', 10);
        
        // 3. Labeling
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render_asset_label'], 10);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render_request_button'], 30);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render_manuals_grid'], 40);
        
        // 4. Ensure SKU Visibility
        // SKU is usually handled by `woocommerce_template_single_meta` at priority 40.
        // We verify it's there. If a theme removed it, we might need to add it back, 
        // but user request implies standard availability, just "Ensure... clearly visible".
        // The default meta block is sufficient.
    }

    /**
     * Render the custom label.
     */
    public static function render_asset_label(): void {
        echo '<p class="price muse-asset-label" style="color:#555; font-weight:800; font-size:1.4em; margin-bottom:15px; text-transform:uppercase; border-bottom:2px solid #555; display:inline-block;">EQUIPMENT ASSET PROFILE</p>';
    }

    /**
     * Render "Request Parts" button.
     */
    public static function render_request_button(): void {
        global $product;
        if (!$product) {
            return;
        }

        $sku = $product->get_sku();
        // If no SKU, fall back to ID? User asked for Asset Tag (SKU).
        $param = $sku ? urlencode($sku) : '';
        $url = site_url('/product-request/?asset_tag=' . $param);

        echo '<a href="' . esc_url($url) . '" class="button alt" style="margin-top:10px; width:100%; text-align:center;">Request Parts for This Unit</a>';
    }

    /**
     * Inject JS to autofill forms if asset_tag is present in URL.
     * Hooks globally so it works on the destination page.
     */
    public static function inject_autofill_js(): void {
        // Only run if the parameter is actually in the URL to save bytes
        if (!isset($_GET['asset_tag'])) {
            return;
        }
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const params = new URLSearchParams(window.location.search);
            const tag = params.get('asset_tag');
            if (!tag) return;

            // Selector strategies: Name attributes, IDs, Gravity Forms standard IDs
            const selectors = [
                'input[name="eam"]',                // Muse Product Request
                'input[name="input_1"]',            // Standard GF first field
                'input[name="model"]',              // Muse PR Model fallback
                'input[id*="serial"]',              // Generic Serial ID
                'input[id*="asset"]'                // Generic Asset ID
            ];

            let found = false;
            selectors.forEach(sel => {
                if (found) return;
                const field = document.querySelector(sel);
                if (field) {
                    field.value = tag;
                    // Trigger change for React/Validation
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    found = true;
                }
            });
        });
        </script>
        <?php
    }
    /**
     * Render grid of manual download buttons.
     */
    public static function render_manuals_grid(): void {
        global $product;
        if (!$product) {
            return;
        }

        $manuals = get_post_meta($product->get_id(), '_asset_manual_files', true);
        
        // CSS for grid
        echo '<style>
            .muse-manuals-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin: 20px 0; }
            .muse-manual-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 12px; border: 1px solid #ddd; background: #f9f9f9; text-decoration: none; color: #333; font-weight: 600; border-radius: 4px; transition: all 0.2s; font-size: 0.9em; }
            .muse-manual-btn:hover { background: #eee; border-color: #bbb; color: #000; transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
            .muse-manual-btn .dashicons { font-size: 20px; width: 20px; height: 20px; color: #d63638; }
            .muse-no-manuals { margin: 20px 0; color: #777; font-size: 0.95em; padding: 10px; background: #fff8e5; border-left: 3px solid #f0c33c; }
        </style>';

        if (empty($manuals) || !is_array($manuals)) {
             $sku = $product->get_sku();
             $url = site_url('/product-request/?asset_tag=' . ($sku ? urlencode($sku) : '') . '&req_type=manual');
             echo '<div class="muse-no-manuals">No manuals on file. <a href="' . esc_url($url) . '" style="font-weight:bold; text-decoration:underline;">Request a Manual</a></div>';
             return;
        }

        echo '<div class="muse-manuals-grid">';
        foreach ($manuals as $file) {
            $label = esc_html($file['label'] ?? 'Manual');
            $url = esc_url($file['url'] ?? '#');
            echo "<a href='$url' target='_blank' class='muse-manual-btn'>";
            echo '<span class="dashicons dashicons-pdf"></span> ' . $label;
            echo "</a>";
        }
        echo '</div>';
    }
}

Muse_Asset_Profile::init();
// Add global autofill listener
add_action('wp_footer', ['Muse_Asset_Profile', 'inject_autofill_js']);
