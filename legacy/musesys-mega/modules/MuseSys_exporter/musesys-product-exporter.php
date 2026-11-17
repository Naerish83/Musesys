<?php
/**
 * Plugin Name: MuseSys Product Exporter
 * Description: Smart WooCommerce product exporter with auto-discovered custom meta and chunked CSV downloads
 * Version:     1.0.0
 * Author:      Gerharz Equipment
 */

defined( 'ABSPATH' ) || exit;

class MuseSys_Product_Exporter {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
        add_action( 'admin_post_musesys_export_products', [ $this, 'handle_export' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menu() {
        add_submenu_page(
            'musesys',
            'Product Exporter',
            'Product Exporter',
            'manage_options',
            'musesys-product-exporter',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'musesys_page_musesys-product-exporter' !== $hook ) return;

        wp_enqueue_style(
            'musesys-exporter',
            plugin_dir_url( __FILE__ ) . 'assets/exporter.css',
            [], '1.0.0'
        );
        wp_enqueue_script(
            'musesys-exporter',
            plugin_dir_url( __FILE__ ) . 'assets/exporter.js',
            [ 'jquery' ], '1.0.0', true
        );
    }

    // Meta discovery with WC system key blocklist
    private function get_product_meta_keys(): array {
        global $wpdb;

        $blocked_prefixes = [
            '_transient',
            '_wc_',
            '_yoast',
            '_aioseop',
            '_edit_',
            '_thumbnail',
            '_wp_',
        ];

        $blocked_exact = [
            'total_sales',
            'product_version',
            'ratings_users',
            'wc_average_rating',
            'wc_review_count',
        ];

        $sql = "
            SELECT DISTINCT pm.meta_key
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE p.post_type = 'product'
            AND pm.meta_key NOT LIKE '\_%'
            ORDER BY pm.meta_key ASC
        ";

        $all_keys = $wpdb->get_col( $sql );
        $clean_keys = [];

        foreach ( $all_keys as $key ) {
            // Skip blocked exact matches
            if ( in_array( $key, $blocked_exact, true ) ) continue;

            // Skip blocked prefixes
            $skip = false;
            foreach ( $blocked_prefixes as $prefix ) {
                if ( 0 === strpos( $key, $prefix ) ) {
                    $skip = true;
                    break;
                }
            }
            if ( $skip ) continue;

            $clean_keys[] = $key;
        }

        return $clean_keys;
    }

    // Get all brands for filter dropdown
    private function get_brands(): array {
        $terms = get_terms( [
            'taxonomy'   => 'product_brand',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );

        return is_wp_error( $terms ) ? [] : $terms;
    }

    public function render_page() {
        $meta_keys = $this->get_product_meta_keys();
        $brands    = $this->get_brands();

        // Core fields always available
        $core_fields = [
            'id'          => 'Product ID',
            'sku'         => 'SKU',
            'title'       => 'Title',
            'description' => 'Description',
            'price'       => 'Price',
            'stock'       => 'Stock Quantity',
            'status'      => 'Status',
            'brand'       => 'Brand',
        ];

        ?>
        <div class="wrap musesys-exporter-wrap">
            <h1>Product Exporter</h1>
            <p class="description">
                Export WooCommerce products with custom meta fields. 
                Select columns, apply filters, and download in chunks.
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" 
                  class="musesys-export-form">
                
                <?php wp_nonce_field( 'musesys_export_products', 'musesys_export_nonce' ); ?>
                <input type="hidden" name="action" value="musesys_export_products">

                <div class="export-section">
                    <h2>Core Fields</h2>
                    <div class="field-grid">
                        <?php foreach ( $core_fields as $key => $label ) : ?>
                            <label>
                                <input type="checkbox" name="columns[]" 
                                       value="<?php echo esc_attr( $key ); ?>" 
                                       checked>
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ( ! empty( $meta_keys ) ) : ?>
                <div class="export-section">
                    <h2>Custom Meta Fields</h2>
                    <p class="section-note">Auto-discovered from your products</p>
                    <div class="field-grid">
                        <?php foreach ( $meta_keys as $key ) : ?>
                            <label>
                                <input type="checkbox" name="columns[]" 
                                       value="meta:<?php echo esc_attr( $key ); ?>">
                                <code><?php echo esc_html( $key ); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button button-small" id="select-all-meta">
                        Select All Meta
                    </button>
                    <button type="button" class="button button-small" id="deselect-all-meta">
                        Deselect All Meta
                    </button>
                </div>
                <?php endif; ?>

                <div class="export-section">
                    <h2>Filters</h2>
                    <div class="filter-row">
                        <label>
                            <span class="label-text">Brand</span>
                            <select name="filter_brand">
                                <option value="">All Brands</option>
                                <?php foreach ( $brands as $brand ) : ?>
                                    <option value="<?php echo esc_attr( $brand->slug ); ?>">
                                        <?php echo esc_html( $brand->name ); ?> 
                                        (<?php echo absint( $brand->count ); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            <span class="label-text">Status</span>
                            <select name="filter_status">
                                <option value="any">Any Status</option>
                                <option value="publish" selected>Published</option>
                                <option value="draft">Draft</option>
                                <option value="pending">Pending</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="export-section">
                    <h2>Chunking</h2>
                    <p class="section-note">
                        Export products in chunks to avoid timeouts on large datasets
                    </p>
                    <div class="filter-row">
                        <label>
                            <span class="label-text">Skip first N products</span>
                            <input type="number" name="skip" value="0" min="0" step="1000">
                        </label>

                        <label>
                            <span class="label-text">Limit (max products)</span>
                            <input type="number" name="limit" value="10000" 
                                   min="1" max="10000" step="1000">
                        </label>
                    </div>
                    <p class="help-text">
                        Example: Skip 0, Limit 10000 = first 10k products<br>
                        Skip 10000, Limit 10000 = next 10k products
                    </p>
                </div>

                <div class="export-section">
                    <button type="submit" class="button button-primary button-hero">
                        Export CSV
                    </button>
                </div>

            </form>
        </div>
        <?php
    }

    // Handle export form submission
    public function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        check_admin_referer( 'musesys_export_products', 'musesys_export_nonce' );

        $columns = isset( $_POST['columns'] ) ? array_map( 'sanitize_text_field', $_POST['columns'] ) : [];
        $brand   = isset( $_POST['filter_brand'] ) ? sanitize_title( $_POST['filter_brand'] ) : '';
        $status  = isset( $_POST['filter_status'] ) ? sanitize_text_field( $_POST['filter_status'] ) : 'publish';
        $skip    = isset( $_POST['skip'] ) ? absint( $_POST['skip'] ) : 0;
        // Capped: runs inside the server's own memory and time limits, so export in 10k chunks via skip.
        $limit   = isset( $_POST['limit'] ) ? min( max( absint( $_POST['limit'] ), 1 ), 10000 ) : 10000;

        if ( empty( $columns ) ) {
            wp_die( 'No columns selected for export.' );
        }

        // Build query args
        $args = [
            'post_type'      => 'product',
            'post_status'    => $status,
            'posts_per_page' => $limit,
            'offset'         => $skip,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ];

        // Apply brand filter
        if ( $brand ) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'product_brand',
                    'field'    => 'slug',
                    'terms'    => [ $brand ],
                ],
            ];
        }

        $query = new WP_Query( $args );
        $product_ids = $query->posts;

        if ( empty( $product_ids ) ) {
            wp_die( 'No products found matching the selected filters.' );
        }

        // Generate CSV
        $filename = 'products-export-' . gmdate( 'Y-m-d-His' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // Write CSV header row
        $header_row = [];
        foreach ( $columns as $col ) {
            $header_row[] = $this->get_column_label( $col );
        }
        fputcsv( $output, $header_row, ',', '"', '\\' );

        // Suspend cache to prevent Object Cache Pro from timing out on large exports
        wp_suspend_cache_addition( true );

        // Write product rows
        foreach ( $product_ids as $product_id ) {
            $row = $this->build_product_row( $product_id, $columns );
            fputcsv( $output, $row, ',', '"', '\\' );
        }

        wp_suspend_cache_addition( false );

        fclose( $output );
        exit;
    }

    // Build a single product row for CSV
    private function build_product_row( int $product_id, array $columns ): array {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return [];

        $row = [];

        foreach ( $columns as $col ) {
            if ( 0 === strpos( $col, 'meta:' ) ) {
                // Custom meta field
                $meta_key = substr( $col, 5 );
                $row[] = get_post_meta( $product_id, $meta_key, true );
            } else {
                // Core field
                $row[] = $this->get_core_field_value( $product, $col );
            }
        }

        return $row;
    }

    // Get value for core field
    private function get_core_field_value( $product, string $field ) {
        switch ( $field ) {
            case 'id':
                return $product->get_id();
            case 'sku':
                return $product->get_sku();
            case 'title':
                return $product->get_name();
            case 'description':
                return $product->get_description();
            case 'price':
                return $product->get_price();
            case 'stock':
                return $product->get_stock_quantity();
            case 'status':
                return $product->get_status();
            case 'brand':
                $terms = wp_get_post_terms( $product->get_id(), 'product_brand', [ 'fields' => 'names' ] );
                return ! empty( $terms ) ? implode( ', ', $terms ) : '';
            default:
                return '';
        }
    }

    // Get human-readable label for column
    private function get_column_label( string $col ): string {
        if ( 0 === strpos( $col, 'meta:' ) ) {
            return substr( $col, 5 );
        }

        $labels = [
            'id'          => 'Product ID',
            'sku'         => 'SKU',
            'title'       => 'Title',
            'description' => 'Description',
            'price'       => 'Price',
            'stock'       => 'Stock Quantity',
            'status'      => 'Status',
            'brand'       => 'Brand',
        ];

        return $labels[ $col ] ?? $col;
    }
}

MuseSys_Product_Exporter::instance();
