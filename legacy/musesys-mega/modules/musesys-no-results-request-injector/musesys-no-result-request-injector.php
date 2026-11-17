<?php
/**
 * Plugin Name: MuseSys – Zero-Result Footer Bridge (UltraSafe DOM, Fixed Shortcode)
 * Description: On search with zero results, prints a “search again” + the fixed Product Request form [muse_product_request_form] at wp_footer and moves it under the theme’s not-found search box.
 * Author: MuseSystems
 * Version: 1.2.3
 * Requires at least: 6.0
 * Tested up to: 6.8
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

final class Muse_ZR_Footer_Bridge_UltraSafe_Dom_Fixed {
    private static $instance = null;

    // Fixed shortcode per King’s directive
    private const FORM_SHORTCODE = '[muse_product_request_form]';

    private $opt_key = 'muse_zrfb_fixed_options';
    private $should_render = false;
    private $current_post_type_qv = '';
    private $safe_mode = true;

    public static function init() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        // Settings UI (no shortcode field — fixed)
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);

        // Decide late, post-query
        add_action('template_redirect', [$this, 'compute_should_render'], 99);

        // Footer output only (ultra-safe)
        add_action('wp_footer', [$this, 'maybe_print_footer_block'], 5);

        // CSS & relocator script
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets']);

        // Shortcode fallback (renders only if conditions match)
        add_shortcode('muse_zero_results_bridge', [$this, 'shortcode_render']);
    }

    /* --------------------------
     * Settings (no shortcode toggle)
     * -------------------------- */
    private function defaults(): array {
        return [
            'show_search_again'  => 1,
            'message_html'       => '<h3>No matches found.</h3><p>Tell us what you need and we’ll track it down.</p>',
            'add_css'            => 1,
            'safe_mode'          => 1,
            'restrict_post_type' => '', // e.g. 'product' to only run for product searches; empty = allow all
            'enable_footer_out'  => 1,  // footer output on/off
        ];
    }

    private function opts(): array {
        $opts = get_option($this->opt_key, []);
        return wp_parse_args(is_array($opts) ? $opts : [], $this->defaults());
    }

    public function register_settings() {
        register_setting($this->opt_key, $this->opt_key, [
            'type' => 'array',
            'sanitize_callback' => function($in) {
                $d = $this->defaults(); $o = [];
                $o['show_search_again']  = !empty($in['show_search_again']) ? 1 : 0;
                $allowed = [
                    'p'=>['class'=>[]], 'strong'=>[], 'em'=>[], 'b'=>[], 'i'=>[], 'u'=>[],
                    'h2'=>['class'=>[]], 'h3'=>['class'=>[]], 'h4'=>['class'=>[]],
                    'div'=>['class'=>[]], 'span'=>['class'=>[]], 'br'=>[], 'ul'=>['class'=>[]], 'ol'=>['class'=>[]], 'li'=>['class'=>[]],
                    'a'=>['href'=>[], 'target'=>[], 'rel'=>[], 'class'=>[]],
                ];
                $o['message_html']       = isset($in['message_html']) ? wp_kses($in['message_html'], $allowed) : $d['message_html'];
                $o['add_css']            = !empty($in['add_css']) ? 1 : 0;
                $o['safe_mode']          = !empty($in['safe_mode']) ? 1 : 0;
                $o['restrict_post_type'] = isset($in['restrict_post_type']) ? sanitize_text_field($in['restrict_post_type']) : '';
                $o['enable_footer_out']  = !empty($in['enable_footer_out']) ? 1 : 0;
                return $o;
            },
            'default' => $this->defaults(),
        ]);

        add_settings_section('muse_zrfb_main', 'Zero-Result Footer Bridge', function() {
            echo '<p>When the current request is a search with zero results, this prints a fallback block and moves it under your not-found search box. The Product Request form is fixed to <code>[muse_product_request_form]</code>.</p>';
        }, $this->opt_key);

        add_settings_field('enable_footer_out', 'Enable footer output', function() {
            $o = $this->opts();
            echo '<label><input type="checkbox" name="'.$this->opt_key.'[enable_footer_out]" value="1" '.checked(1, (int)$o['enable_footer_out'], false).' /> Append block at <code>wp_footer</code></label>';
        }, $this->opt_key, 'muse_zrfb_main');

        add_settings_field('restrict_post_type', 'Restrict by post_type (optional)', function() {
            $o = $this->opts();
            echo '<input type="text" name="'.$this->opt_key.'[restrict_post_type]" value="'.esc_attr($o['restrict_post_type']).'" class="regular-text" />';
            echo '<p class="description">Example: <code>product</code> to trigger only on product searches. Leave empty to allow all.</p>';
        }, $this->opt_key, 'muse_zrfb_main');

        add_settings_field('show_search_again', 'Show “Search Again” box', function() {
            $o = $this->opts();
            echo '<label><input type="checkbox" name="'.$this->opt_key.'[show_search_again]" value="1" '.checked(1, (int)$o['show_search_again'], false).' /> Render compact search form (preserves <code>post_type</code> if present)</label>';
        }, $this->opt_key, 'muse_zrfb_main');

        add_settings_field('message_html', 'Intro Message (HTML)', function() {
            $o = $this->opts();
            echo '<textarea name="'.$this->opt_key.'[message_html]" rows="4" class="large-text code">'.esc_textarea($o['message_html']).'</textarea>';
        }, $this->opt_key, 'muse_zrfb_main');

        add_settings_field('add_css', 'Include minimal CSS', function() {
            $o = $this->opts();
            echo '<label><input type="checkbox" name="'.$this->opt_key.'[add_css]" value="1" '.checked(1, (int)$o['add_css'], false).' /> Add simple spacing/layout styles</label>';
        }, $this->opt_key, 'muse_zrfb_main');

        add_settings_field('safe_mode', 'Safe Mode', function() {
            $o = $this->opts();
            echo '<label><input type="checkbox" name="'.$this->opt_key.'[safe_mode]" value="1" '.checked(1, (int)$o['safe_mode'], false).' /> If rendering fails, silently no-op</label>';
        }, $this->opt_key, 'muse_zrfb_main');
    }

    public function add_settings_page() {
        add_submenu_page(
            'musesys',
            'Zero-Result Footer Bridge',
            'Zero-Result Footer',
            'manage_options',
            $this->opt_key,
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) { return; }
        ?>
        <div class="wrap">
            <h1>Zero-Result Footer Bridge</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->opt_key);
                do_settings_sections($this->opt_key);
                submit_button();
                ?>
            </form>
            <hr>
            <p><strong>Form shortcode:</strong> This build always uses <code>[muse_product_request_form]</code>. No deviation, no accidents.</p>
            <p><strong>Placement:</strong> We print at <code>wp_footer</code> and move the block under <code>article.post-item.not-found</code> (right after its search box) when present.</p>
        </div>
        <?php
    }

    /* ---------- Core ---------- */
    public function compute_should_render() {
        if (is_admin()) { return; }
        if (!function_exists('is_search') || !is_search()) { return; }

        $o = $this->opts();
        $this->safe_mode = !empty($o['safe_mode']);

        $pt = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : '';
        if (is_array($pt)) { $pt = ''; }
        $this->current_post_type_qv = $pt;

        if (!empty($o['restrict_post_type']) && $pt !== $o['restrict_post_type']) {
            return;
        }

        global $wp_query;
        if (!isset($wp_query) || !($wp_query instanceof WP_Query)) { return; }
        if (!$wp_query->is_main_query()) { return; }

        $count = isset($wp_query->found_posts) ? (int) $wp_query->found_posts : (int) $wp_query->post_count;
        if ($count === 0) {
            $this->should_render = true;
        }
    }

    public function maybe_enqueue_assets() {
        $o = $this->opts();
        if (!$this->should_render) { return; }

        if (!empty($o['add_css'])) {
            $css = '
                .muse-zrfb { margin: 1.25rem 0 1.5rem; padding: 1rem; border: 1px solid #e5e7eb; border-radius: .5rem; }
                .muse-zrfb .muse-zrfb-message { margin-bottom: .75rem; }
                .muse-zrfb .muse-zrfb-search { margin: .75rem 0 1rem; }
                .muse-zrfb .muse-zrfb-form { margin-top: .75rem; }
                .muse-zrfb .muse-inline { display:flex; gap:.5rem; flex-wrap:wrap; }
                .muse-zrfb input[type="search"]{ padding:.5rem .6rem; }
                .muse-zrfb button, .muse-zrfb input[type="submit"]{ padding:.5rem .75rem; }
            ';
            wp_register_style('muse-zrfb', false);
            wp_enqueue_style('muse-zrfb');
            wp_add_inline_style('muse-zrfb', $css);
        }

        // Relocator: move our block under the not-found panel if present.
        $script = <<<JS
(function(){
    function place(){
        var block = document.getElementById('muse-zrfb');
        if(!block){ return; }
        // Prefer: article.post-item.not-found .post-content .row (your sample markup)
        var targetRow = document.querySelector('article.post-item.not-found .post-content .row');
        if(targetRow && targetRow.parentNode){
            if(targetRow.nextSibling){
                targetRow.parentNode.insertBefore(block, targetRow.nextSibling);
            } else {
                targetRow.parentNode.appendChild(block);
            }
            return;
        }
        // Fallback: append inside .post-content if .row not present
        var postContent = document.querySelector('article.post-item.not-found .post-content');
        if(postContent){
            postContent.appendChild(block);
            return;
        }
        // Last resort: leave in footer
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', place);
    } else {
        place();
    }
})();
JS;
        wp_register_script('muse-zrfb-move', false);
        wp_enqueue_script('muse-zrfb-move');
        wp_add_inline_script('muse-zrfb-move', $script);
    }

    private function build_block_html(): string {
        $o = $this->opts();

        // If shortcode rendering fails and safe_mode on, return empty.
        $shortcode_out = do_shortcode(self::FORM_SHORTCODE);
        if (!is_string($shortcode_out)) { $shortcode_out = ''; }
        if ($shortcode_out === '' && !empty($o['safe_mode'])) {
            return '';
        }

        $search_term = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $html  = '<div id="muse-zrfb" class="muse-zrfb">';
        if (!empty($o['message_html'])) {
            $html .= '<div class="muse-zrfb-message">'.$o['message_html'].'</div>';
        }

        if (!empty($o['show_search_again'])) {
            $action = home_url('/');
            $html .= '<div class="muse-zrfb-search">';
            $html .= '<form role="search" method="get" action="'.esc_url($action).'" class="muse-inline">';
            $html .= '<label class="screen-reader-text" for="muse-zrfb-s">Search for:</label>';
            $html .= '<input type="search" id="muse-zrfb-s" name="s" value="'.esc_attr($search_term).'" />';
            if (!empty($this->current_post_type_qv)) {
                $html .= '<input type="hidden" name="post_type" value="'.esc_attr($this->current_post_type_qv).'" />';
            }
            $html .= '<button type="submit">Search</button>';
            $html .= '</form>';
            $html .= '</div>';
        }

        $html .= '<div class="muse-zrfb-form">'.$shortcode_out.'</div>';
        $html .= '</div>';
        return $html;
    }

    public function maybe_print_footer_block() {
        $o = $this->opts();
        if (!$this->should_render || empty($o['enable_footer_out'])) { return; }
        $block = $this->build_block_html();
        if ($block === '') { return; }
        echo $block; // prints in footer, then JS relocates it into place
    }

    public function shortcode_render() : string {
        if (!$this->should_render) { return ''; }
        $block = $this->build_block_html();
        return $block ?: '';
    }
}

Muse_ZR_Footer_Bridge_UltraSafe_Dom_Fixed::init();
