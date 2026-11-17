<?php
/**
 * Plugin Name: Wegmans Brand Filter & Search Logic
 * Description: Two-stage brand picker and "God Mode" search priority. 
 * Ensures brands are filtered correctly and exact part matches always win.
 * Version:     1.1.0
 * Author:      Gerharz Equipment
 */

defined( 'ABSPATH' ) || exit;

// --- CONFIG ------------------------------------------------------------------
define( 'GE_BRAND_TAXONOMY', 'product_brand' );
define( 'GE_BRAND_PARAM', 'brand' );
// -----------------------------------------------------------------------------

class GE_Brand_Filter {

    public function __construct() {
        add_action( 'wp_enqueue_scripts',           [ $this, 'enqueue' ] );
        add_action( 'woocommerce_before_shop_loop', [ $this, 'render_brand_bar' ], 5 );
        add_action( 'pre_get_posts',                [ $this, 'apply_brand_to_query' ] );
        
        // Relevanssi Hooks
        add_filter( 'relevanssi_modify_wp_query',   [ $this, 'scope_relevanssi_to_brand' ] );
        add_filter( 'relevanssi_hits_filter',       [ $this, 'force_exact_match_priority' ] );

        // Nuke WooCommerce's default result count + ordering bar
        remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
        remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
    }

    // --- ASSETS --------------------------------------------------------------

    public function enqueue() {
        if ( ! is_shop() && ! is_product_taxonomy() ) return;

        wp_enqueue_style( 'tom-select', 'https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.min.css', [], '2.3.1' );
        wp_enqueue_script( 'tom-select', 'https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js', [], '2.3.1', true );

        wp_enqueue_style( 'ge-brand-filter', plugin_dir_url( __FILE__ ) . 'assets/brand-filter.css', [ 'tom-select' ], '1.0.0' );
        wp_enqueue_script( 'ge-brand-filter', plugin_dir_url( __FILE__ ) . 'assets/brand-filter.js', [ 'tom-select' ], '1.0.0', true );

        wp_localize_script( 'ge-brand-filter', 'GE_BrandFilter', [
            'activeBrand' => $this->get_active_brand(),
            'brandParam'  => GE_BRAND_PARAM,
            'shopUrl'     => wc_get_page_permalink( 'shop' ),
        ] );
    }

    // --- HELPERS -------------------------------------------------------------

    private function get_brands(): array {
        $terms = get_terms( [
            'taxonomy'   => GE_BRAND_TAXONOMY,
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
            'number'     => 0,
        ] );
        return ( is_wp_error( $terms ) || empty( $terms ) ) ? [] : $terms;
    }

    private function get_active_brand(): string {
        return isset( $_GET[ GE_BRAND_PARAM ] )
            ? sanitize_title( wp_unslash( $_GET[ GE_BRAND_PARAM ] ) )
            : '';
    }

    // --- RENDER --------------------------------------------------------------

    public function render_brand_bar() {
        if ( ! is_shop() && ! is_product_taxonomy() ) return;

        $active  = $this->get_active_brand();
        $brands  = $this->get_brands();
        $shop_url = wc_get_page_permalink( 'shop' );
        $active_label = '';

        if ( $active ) {
            $term = get_term_by( 'slug', $active, GE_BRAND_TAXONOMY );
            $active_label = $term ? esc_html( $term->name ) : '';
        }
        ?>
        <div class="ge-brand-bar" id="ge-brand-bar">
            <div class="ge-brand-picker-wrap">
                
                <label for="ge-brand-select" style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px; color: #2e2e2e; text-transform: uppercase; letter-spacing: 0.5px;">
                    Filter Catalog by Brand:
                </label>

                <select id="ge-brand-select" name="brand" autocomplete="off">
                    <option value=""></option>
                    <?php foreach ( $brands as $term ) : ?>
                        <option value="<?php echo esc_attr( $term->slug ); ?>"
                            <?php selected( $active, $term->slug ); ?>>
                            <?php echo esc_html( $term->name ); ?>
                            (<?php echo absint( $term->count ); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ( $active ) : ?>
                    <a href="<?php echo esc_url( $shop_url ); ?>"
                       class="ge-brand-clear" title="Clear brand filter" style="margin-top: 8px; display: inline-block;">
                        ? Clear Filter
                    </a>
                <?php endif; ?>
            </div>

            <?php if ( $active && $active_label ) : ?>
                <p class="ge-brand-active-label">
                    Currently Viewing: <strong><?php echo $active_label; ?></strong>
                </p>
            <?php endif; ?>
        </div>

        <?php if ( ! $active ) : ?>
            <div class="ge-brand-gate" id="ge-brand-gate">
                <div class="ge-gate-inner">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                    </svg>
                    <p>Select a brand above to browse products.</p>
                    <span><?php echo number_format( wc_get_loop_prop( 'total', 200000 ) ); ?>+ products across <?php echo count( $brands ); ?> brands</span>
                </div>
            </div>
            <?php
            // Suppress the product grid
            add_action( 'pre_get_posts', function( \WP_Query $q ) {
                if ( $q->is_main_query() && ( $q->is_post_type_archive( 'product' ) || $q->is_shop() ) ) {
                    $q->set( 'post__in', [0] );
                }
            }, 999 );
            ?>
        <?php endif; ?>
        <?php
    }

    // --- QUERY LOGIC ---------------------------------------------------------

    public function apply_brand_to_query( \WP_Query $q ) {
        if ( is_admin() || ! $q->is_main_query() ) return;
        if ( ! is_shop() && ! $q->is_post_type_archive( 'product' ) ) return;

        $brand = $this->get_active_brand();
        if ( ! $brand ) return;

        $tax_query   = $q->get( 'tax_query' ) ?: [];
        $tax_query[] = [
            'taxonomy' => GE_BRAND_TAXONOMY,
            'field'    => 'slug',
            'terms'    => [ $brand ],
            'operator' => 'IN',
        ];
        $q->set( 'tax_query', $tax_query );
    }

    public function scope_relevanssi_to_brand( \WP_Query $q ) {
        $brand = $this->get_active_brand();
        if ( ! $brand ) return $q;

        $tax_query   = $q->get( 'tax_query' ) ?: [];
        $tax_query[] = [
            'taxonomy' => GE_BRAND_TAXONOMY,
            'field'    => 'slug',
            'terms'    => [ $brand ],
            'operator' => 'IN',
        ];
        $q->set( 'tax_query', $tax_query );
        return $q;
    }

    /**
     * "GOD MODE" SEARCH PRIORITY
     * Checks results for exact matches on EAM or Part Number.
     * If found, promotes them to the top.
     */
    public function force_exact_match_priority( $hits ) {
        global $wp_query;
        $query = isset( $wp_query->query_vars['s'] ) ? trim( $wp_query->query_vars['s'] ) : '';

        if ( empty( $query ) || empty( $hits[0] ) ) {
            return $hits;
        }

        $exact_matches = array();
        $fuzzy_matches = array();

        foreach ( $hits[0] as $hit ) {
            // Check your custom field keys (Muse_eam / Muse_partnumber)
            $eam_num  = get_post_meta( $hit->ID, 'Muse_eam', true );
            $part_num = get_post_meta( $hit->ID, 'Muse_partnumber', true );

            // Case-insensitive exact check
            if ( ( $eam_num && strcasecmp( $query, $eam_num ) === 0 ) || 
                 ( $part_num && strcasecmp( $query, $part_num ) === 0 ) ) {
                $exact_matches[] = $hit;
            } else {
                $fuzzy_matches[] = $hit;
            }
        }

        // If exact matches exist, they become the list (or at least the top)
        if ( ! empty( $exact_matches ) ) {
            $hits[0] = array_merge( $exact_matches, $fuzzy_matches );
        }

        return $hits;
    }
}

new GE_Brand_Filter();

// Built by Muse