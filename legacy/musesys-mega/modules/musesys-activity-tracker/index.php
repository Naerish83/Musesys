<?php
/**
 * Plugin Name: Muse Prop 65 Notices for WooCommerce
 * Description: Adds per-product (and per-variation) California Prop 65 warnings and displays them on product pages, cart, and checkout. Includes a shortcode [prop65_notice].
 * Version: 1.0.0
 * Author: MuseSystems
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('Muse_Prop65_Notices')) {
final class Muse_Prop65_Notices {

    const META_ENABLED   = '_muse_prop65_enabled';
    const META_CHEMS     = '_muse_prop65_chems';
    const META_MESSAGE   = '_muse_prop65_message';

    const VMETA_ENABLED  = '_muse_prop65_enabled_var';
    const VMETA_CHEMS    = '_muse_prop65_chems_var';
    const VMETA_MESSAGE  = '_muse_prop65_message_var';

    // Default message if custom is empty. {chemicals} token replaced when available.
    const DEFAULT_MSG = 'WARNING: This product can expose you to chemicals including {chemicals}, which are known to the State of California to cause cancer and birth defects or other reproductive harm. For more information, visit www.P65Warnings.ca.gov.';

    public static function init() {
        // Admin UI: product data tab
        add_filter('woocommerce_product_data_tabs', [__CLASS__, 'add_product_tab']);
        add_action('woocommerce_product_data_panels', [__CLASS__, 'render_product_panel']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_product_meta']);

        // Admin UI: variations
        add_action('woocommerce_product_after_variable_attributes', [__CLASS__, 'variation_fields'], 10, 3);
        add_action('woocommerce_save_product_variation', [__CLASS__, 'save_variation_fields'], 10, 2);

        // Frontend display
        add_action('wp_enqueue_scripts', [__CLASS__, 'styles']);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render_single_notice'], 25);
        add_filter('woocommerce_cart_item_name', [__CLASS__, 'render_cart_line_notice'], 10, 3);
        add_action('woocommerce_review_order_before_cart_contents', [__CLASS__, 'render_checkout_notice_block'], 10);

        // Shortcode
        add_shortcode('prop65_notice', [__CLASS__, 'shortcode']);

        // Ensure line item meta carries across orders if needed (no output change, but keeps context)
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'attach_order_item_meta'], 10, 4);
    }

    /* ---------------------------
       Admin: Product Tab & Panel
       --------------------------- */
    public static function add_product_tab($tabs) {
        $tabs['muse_prop65'] = [
            'label'    => 'Prop 65',
            'target'   => 'muse_prop65_panel',
            'class'    => ['show_if_simple','show_if_variable','show_if_external','show_if_grouped'],
            'priority' => 80,
        ];
        return $tabs;
    }

    public static function render_product_panel() {
        global $post;
        $enabled = get_post_meta($post->ID, self::META_ENABLED, true);
        $chems   = get_post_meta($post->ID, self::META_CHEMS, true);
        $msg     = get_post_meta($post->ID, self::META_MESSAGE, true);
        ?>
        <div id="muse_prop65_panel" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                woocommerce_wp_checkbox([
                    'id'          => self::META_ENABLED,
                    'label'       => __('Enable Prop 65 Warning', 'muse-prop65'),
                    'value'       => $enabled === 'yes' ? 'yes' : 'no',
                    'desc_tip'    => true,
                    'description' => __('Check to display a Prop 65 warning for this product. Variations can override.', 'muse-prop65'),
                ]);
                woocommerce_wp_text_input([
                    'id'          => self::META_CHEMS,
                    'label'       => __('Chemicals (comma-separated)', 'muse-prop65'),
                    'value'       => $chems,
                    'desc_tip'    => true,
                    'description' => __('Example: Lead, DEHP. Used in the default warning text.', 'muse-prop65'),
                ]);
                woocommerce_wp_textarea_input([
                    'id'          => self::META_MESSAGE,
                    'label'       => __('Custom Message (optional)', 'muse-prop65'),
                    'value'       => $msg,
                    'placeholder' => self::DEFAULT_MSG,
                    'desc_tip'    => true,
                    'description' => __('Leave blank to use the default template. Use {chemicals} token to inject the list above.', 'muse-prop65'),
                    'rows'        => 4,
                ]);
                ?>
            </div>
        </div>
        <?php
    }

    public static function save_product_meta($post_id) {
        $enabled = isset($_POST[self::META_ENABLED]) ? 'yes' : 'no';
        update_post_meta($post_id, self::META_ENABLED, $enabled);
        update_post_meta($post_id, self::META_CHEMS, sanitize_text_field($_POST[self::META_CHEMS] ?? ''));
        update_post_meta($post_id, self::META_MESSAGE, wp_kses_post($_POST[self::META_MESSAGE] ?? ''));
    }

    /* ---------------------------
       Admin: Variation overrides
       --------------------------- */
    public static function variation_fields($loop, $variation_data, $variation) {
        $vid = $variation->ID;
        $ven = get_post_meta($vid, self::VMETA_ENABLED, true);
        $vch = get_post_meta($vid, self::VMETA_CHEMS, true);
        $vms = get_post_meta($vid, self::VMETA_MESSAGE, true);
        ?>
        <div class="form-row form-row-full">
            <label><strong>Prop 65 (variation override)</strong></label>
        </div>
        <p class="form-row form-row-first">
            <label for="<?php echo esc_attr(self::VMETA_ENABLED . "_{$vid}"); ?>">
                <input type="checkbox" class="checkbox"
                    name="<?php echo esc_attr(self::VMETA_ENABLED . "[{$vid}]"); ?>"
                    id="<?php echo esc_attr(self::VMETA_ENABLED . "_{$vid}"); ?>"
                    <?php checked($ven, 'yes'); ?> />
                Enable for this variation
            </label>
        </p>
        <p class="form-row form-row-last">
            <input type="text"
                name="<?php echo esc_attr(self::VMETA_CHEMS . "[{$vid}]"); ?>"
                value="<?php echo esc_attr($vch); ?>"
                placeholder="Lead, DEHP"
                style="width:100%;" />
        </p>
        <p class="form-row form-row-full">
            <textarea name="<?php echo esc_attr(self::VMETA_MESSAGE . "[{$vid}]"); ?>" rows="3" style="width:100%;" placeholder="<?php echo esc_attr(self::DEFAULT_MSG); ?>"><?php echo esc_textarea($vms); ?></textarea>
            <em>Leave fields blank to fall back to product-level settings.</em>
        </p>
        <hr/>
        <?php
    }

    public static function save_variation_fields($variation_id, $i) {
        $en = isset($_POST[self::VMETA_ENABLED][$variation_id]) ? 'yes' : '';
        update_post_meta($variation_id, self::VMETA_ENABLED, $en ? 'yes' : '');

        $ch = $_POST[self::VMETA_CHEMS][$variation_id] ?? '';
        $ms = $_POST[self::VMETA_MESSAGE][$variation_id] ?? '';

        update_post_meta($variation_id, self::VMETA_CHEMS, sanitize_text_field($ch));
        update_post_meta($variation_id, self::VMETA_MESSAGE, wp_kses_post($ms));
    }

    /* ---------------------------
       Frontend rendering
       --------------------------- */
    public static function styles() {
        $css = '.muse-prop65{border:1px solid #f3c991;background:#fff7e6;color:#663c00;padding:12px 14px;border-radius:6px;margin:12px 0;display:flex;gap:10px;align-items:flex-start}
                .muse-prop65 .muse-prop65-ico{font-weight:700}
                .muse-prop65 small{display:block;opacity:.9}';
        wp_register_style('muse-prop65-style', false);
        wp_enqueue_style('muse-prop65-style');
        wp_add_inline_style('muse-prop65-style', $css);
    }

    public static function render_single_notice() {
        global $product;
        if (!$product instanceof WC_Product) { return; }
        $notice = self::resolve_notice_for_product($product);
        if ($notice) {
            echo self::html_notice($notice);
        }
    }

    // Append a compact line under each cart item name if warning applies
    public static function render_cart_line_notice($name, $cart_item, $cart_key) {
        $product = $cart_item['data'] ?? null;
        if (!$product instanceof WC_Product) { return $name; }
        $notice = self::resolve_notice_for_product($product, $cart_item);
        if ($notice) {
            $name .= '<div class="muse-prop65" style="margin:6px 0 0"><span class="muse-prop65-ico">⚠️</span><small>'
                   . wp_kses_post($notice)
                   . '</small></div>';
        }
        return $name;
    }

    // Single block near top of checkout if any items carry warning
    public static function render_checkout_notice_block() {
        if (WC()->cart && !WC()->cart->is_empty()) {
            foreach (WC()->cart->get_cart() as $item) {
                $product = $item['data'] ?? null;
                if ($product instanceof WC_Product) {
                    $notice = self::resolve_notice_for_product($product, $item);
                    if ($notice) {
                        echo self::html_notice($notice);
                        break;
                    }
                }
            }
        }
    }

    /* ---------------------------
       Shortcode
       --------------------------- */
    public static function shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts, 'prop65_notice');
        $product = null;

        if (!empty($atts['id'])) {
            $product = wc_get_product((int)$atts['id']);
        } elseif (function_exists('wc_get_product') && is_product()) {
            $product = wc_get_product(get_the_ID());
        }

        if ($product instanceof WC_Product) {
            $notice = self::resolve_notice_for_product($product);
            return $notice ? self::html_notice($notice, false) : '';
        }
        return '';
    }

    /* ---------------------------
       Order item carry-over (optional)
       --------------------------- */
    public static function attach_order_item_meta($item, $cart_item_key, $values, $order) {
        $product = $values['data'] ?? null;
        if (!$product instanceof WC_Product) { return; }
        $notice = self::resolve_notice_for_product($product, $values);
        if ($notice) {
            $item->add_meta_data('Prop 65', wp_strip_all_tags($notice), true);
        }
    }

    /* ---------------------------
       Core logic
       --------------------------- */
    private static function resolve_notice_for_product(WC_Product $product, $context = null) {
        // Variation override priority
        $vid = 0;
        if (!empty($context['variation_id'])) {
            $vid = (int) $context['variation_id'];
        } elseif ($product->is_type('variation')) {
            $vid = $product->get_id();
        }

        if ($vid) {
            $ven = get_post_meta($vid, self::VMETA_ENABLED, true);
            $vch = get_post_meta($vid, self::VMETA_CHEMS, true);
            $vms = get_post_meta($vid, self::VMETA_MESSAGE, true);

            if ($ven === 'yes') {
                $chem = trim($vch);
                $msg  = trim($vms);
                return self::build_message($chem, $msg);
            }
            // if variation explicitly disabled, fall through to product-level
        }

        $pid = $product->get_parent_id() ?: $product->get_id();
        $enabled = get_post_meta($pid, self::META_ENABLED, true);
        if ($enabled !== 'yes') {
            return '';
        }
        $chem = trim(get_post_meta($pid, self::META_CHEMS, true));
        $msg  = trim(get_post_meta($pid, self::META_MESSAGE, true));
        return self::build_message($chem, $msg);
    }

    private static function build_message($chemicals, $custom) {
        $tmpl = $custom !== '' ? $custom : self::DEFAULT_MSG;
        $chem = $chemicals !== '' ? $chemicals : 'chemicals';
        return str_replace('{chemicals}', esc_html($chem), wp_kses_post($tmpl));
    }

    private static function html_notice($message, $wrap = true) {
        $html = '<div class="muse-prop65"><span class="muse-prop65-ico">⚠️</span><div>'
              . wp_kses_post($message)
              . '</div></div>';
        return $wrap ? $html : $html;
    }
}
Muse_Prop65_Notices::init();
}
