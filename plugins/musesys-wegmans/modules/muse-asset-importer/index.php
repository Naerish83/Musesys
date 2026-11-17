<?php
/**
 * Plugin Name: Muse Asset Importer
 * Description: Utility to bulk-create Asset Products from a predefined list. Trigger with ?muse_import_assets=1
 * Version: 1.0.0
 * Author: MuseSystems
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Muse_Asset_Importer {

    public static function init(): void {
        add_action('init', [__CLASS__, 'run_import']);
    }

    public static function run_import(): void {
        if (!isset($_GET['muse_import_assets']) || !current_user_can('manage_options')) {
            return;
        }

        // 1. Define your data here (format: 'AssetTag' => 'Model Name')
        $assets = [
            'ASSET-001' => 'Hobart Mixer A200',
            'ASSET-002' => 'Vulcan Range 6-Burner',
            'ASSET-003' => 'True Reach-in Freezer',
            // Paste your list below...
        ];

        // Ensure Category Exists
        $cat_slug = 'shop-assets';
        $term = get_term_by('slug', $cat_slug, 'product_cat');
        if (!$term) {
            $term = wp_insert_term('Shop Assets', 'product_cat', ['slug' => $cat_slug]);
            if (is_wp_error($term)) {
                wp_die('Could not create category: ' . $term->get_error_message());
            }
            $cat_id = $term['term_id'];
        } else {
            $cat_id = $term->term_id;
        }

        echo '<div style="background:#fff; padding:20px; font-family:monospace;">';
        echo '<h2>Starting Asset Import...</h2>';

        $created = 0;
        $skipped = 0;

        foreach ($assets as $tag => $model) {
            $sku = sanitize_text_field((string)$tag);
            $model_name = sanitize_text_field($model);

            // Check if exists
            if (wc_get_product_id_by_sku($sku)) {
                echo "SKIP: $sku (Already Exists)<br>";
                $skipped++;
                continue;
            }

            // Create Product
            try {
                $product = new WC_Product_Simple();
                $product->set_name("Asset: $model_name");
                $product->set_sku($sku);
                $product->set_status('publish');
                $product->set_catalog_visibility('visible');
                $product->set_price(''); // Empty price
                $product->set_regular_price('');
                $product->set_category_ids([$cat_id]);
                $product->set_manage_stock(false);
                $product->set_sold_individually(true);
                
                $id = $product->save();

                if ($id) {
                    echo "<strong style='color:green;'>CREATED: $sku (ID: $id)</strong><br>";
                    $created++;
                } else {
                    echo "<span style='color:red;'>ERROR: $sku (Save failed)</span><br>";
                }
            } catch (Exception $e) {
                echo "<span style='color:red;'>ERROR: $sku - " . $e->getMessage() . "</span><br>";
            }
        }

        echo "<hr><h3>Done. Created: $created | Skipped: $skipped</h3>";
        echo '</div>';
        exit; // Stop further loading
    }
}

Muse_Asset_Importer::init();
