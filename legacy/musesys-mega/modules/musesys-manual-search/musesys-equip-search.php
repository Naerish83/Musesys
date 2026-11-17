<?php
/**
 * Plugin Module: MuseSys Manuals Search
 * Description: Registers a Manual custom post type and provides a searchable UI for equipment manuals stored under uploads/manuals/.
 * Author: Muse Systems
 * Version: 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Guard to avoid double-loading
if ( defined( 'MUSESYS_MANUALS_LOADED' ) ) {
    return;
}
define( 'MUSESYS_MANUALS_LOADED', true );

/**
 * Get manuals base directory and URL (defaults to uploads/manuals/).
 *
 * @return array [ 'dir' => string, 'url' => string ]
 */
function musesys_manuals_get_base_paths() {
    $uploads = wp_upload_dir();
    $dir     = trailingslashit( $uploads['basedir'] ) . 'manuals';
    $url     = trailingslashit( $uploads['baseurl'] ) . 'manuals';

    $dir = apply_filters( 'musesys_manuals_base_dir', $dir );
    $url = apply_filters( 'musesys_manuals_base_url', $url );

    return [
        'dir' => untrailingslashit( $dir ),
        'url' => untrailingslashit( $url ),
    ];
}

/**
 * Register Manual CPT and taxonomies.
 */
add_action( 'init', 'musesys_manuals_register_cpt', 10 );
function musesys_manuals_register_cpt() {

    // CPT: Manuals
    $labels = [
        'name'               => __( 'Manuals', 'musesys-manuals' ),
        'singular_name'      => __( 'Manual', 'musesys-manuals' ),
        'add_new'            => __( 'Add New', 'musesys-manuals' ),
        'add_new_item'       => __( 'Add New Manual', 'musesys-manuals' ),
        'edit_item'          => __( 'Edit Manual', 'musesys-manuals' ),
        'new_item'           => __( 'New Manual', 'musesys-manuals' ),
        'view_item'          => __( 'View Manual', 'musesys-manuals' ),
        'search_items'       => __( 'Search Manuals', 'musesys-manuals' ),
        'not_found'          => __( 'No manuals found.', 'musesys-manuals' ),
        'not_found_in_trash' => __( 'No manuals found in Trash.', 'musesys-manuals' ),
        'menu_name'          => __( 'Manuals', 'musesys-manuals' ),
    ];

    $args = [
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => false, // We’ll attach under MuseSys.
        'supports'           => [ 'title', 'revisions' ],
        'exclude_from_search'=> true,
        'publicly_queryable' => false,
        'has_archive'        => false,
        'rewrite'            => false,
        'capability_type'    => 'post',
    ];

    register_post_type( 'muse_manual', $args );

    // Brand taxonomy
    $brand_labels = [
        'name'          => __( 'Manual Brands', 'musesys-manuals' ),
        'singular_name' => __( 'Manual Brand', 'musesys-manuals' ),
        'search_items'  => __( 'Search Brands', 'musesys-manuals' ),
        'all_items'     => __( 'All Brands', 'musesys-manuals' ),
        'edit_item'     => __( 'Edit Brand', 'musesys-manuals' ),
        'update_item'   => __( 'Update Brand', 'musesys-manuals' ),
        'add_new_item'  => __( 'Add New Brand', 'musesys-manuals' ),
        'menu_name'     => __( 'Brands', 'musesys-manuals' ),
    ];

    register_taxonomy(
        'muse_manual_brand',
        'muse_manual',
        [
            'labels'       => $brand_labels,
            'hierarchical' => false,
            'show_ui'      => true,
            'show_in_menu' => false,
            'show_tagcloud'=> false,
        ]
    );
}

/**
 * Add submenu under MuseSys main menu.
 */
add_action( 'admin_menu', 'musesys_manuals_admin_menu', 20 );
function musesys_manuals_admin_menu() {
    add_submenu_page(
        'musesys',
        __( 'Manual Index', 'musesys-manuals' ),
        __( 'Manual Index', 'musesys-manuals' ),
        'manage_options',
        'musesys-manuals',
        'musesys_manuals_admin_page'
    );
}

/**
 * Admin page: Manual index + rebuild button.
 */
function musesys_manuals_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $message = '';

    if ( isset( $_POST['musesys_manuals_reindex'] ) && check_admin_referer( 'musesys_manuals_reindex_action', 'musesys_manuals_reindex_nonce' ) ) {
        $result  = musesys_manuals_rebuild_index();
        $message = sprintf(
            esc_html__( 'Manual index rebuilt. %1$d file(s) scanned, %2$d manual(s) updated/created.', 'musesys-manuals' ),
            intval( $result['files_scanned'] ),
            intval( $result['posts_synced'] )
        );
    }

    $paths = musesys_manuals_get_base_paths();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'MuseSys Manual Index', 'musesys-manuals' ); ?></h1>

        <p><?php esc_html_e( 'This tool scans your manuals directory and syncs PDF files to the Manuals index for fast searching.', 'musesys-manuals' ); ?></p>

        <table class="widefat striped" style="max-width:600px;margin-top:1rem;">
            <tr>
                <th scope="row"><?php esc_html_e( 'Manuals Directory', 'musesys-manuals' ); ?></th>
                <td><code><?php echo esc_html( $paths['dir'] ); ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Manuals URL Base', 'musesys-manuals' ); ?></th>
                <td><code><?php echo esc_html( $paths['url'] ); ?></code></td>
            </tr>
        </table>

        <?php if ( ! empty( $message ) ) : ?>
            <div class="notice notice-success" style="margin-top:1rem;">
                <p><?php echo esc_html( $message ); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" style="margin-top:1.5rem;">
            <?php wp_nonce_field( 'musesys_manuals_reindex_action', 'musesys_manuals_reindex_nonce' ); ?>
            <p>
                <input type="submit" name="musesys_manuals_reindex" class="button button-primary" value="<?php esc_attr_e( 'Rebuild Manual Index', 'musesys-manuals' ); ?>" />
            </p>
            <p class="description">
                <?php esc_html_e( 'Run this after adding or moving PDF manuals into the manuals directory.', 'musesys-manuals' ); ?>
            </p>
        </form>

        <hr style="margin:2rem 0;"/>

        <h2><?php esc_html_e( 'Current Manuals', 'musesys-manuals' ); ?></h2>
        <?php
        $manuals = get_posts( [
            'post_type'      => 'muse_manual',
            'posts_per_page' => 50,
            'post_status'    => 'any',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        if ( empty( $manuals ) ) {
            echo '<p>' . esc_html__( 'No manuals indexed yet.', 'musesys-manuals' ) . '</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:900px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Title', 'musesys-manuals' ) . '</th>';
            echo '<th>' . esc_html__( 'Brand', 'musesys-manuals' ) . '</th>';
            echo '<th>' . esc_html__( 'File', 'musesys-manuals' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $manuals as $manual ) {
                $file = get_post_meta( $manual->ID, '_muse_manual_file', true );
                $url  = get_post_meta( $manual->ID, '_muse_manual_url', true );
                $brands = get_the_terms( $manual->ID, 'muse_manual_brand' );
                $brand_str = '';
                if ( ! empty( $brands ) && ! is_wp_error( $brands ) ) {
                    $names = wp_list_pluck( $brands, 'name' );
                    $brand_str = implode( ', ', $names );
                }
                echo '<tr>';
                echo '<td><a href="' . esc_url( get_edit_post_link( $manual->ID ) ) . '">' . esc_html( get_the_title( $manual ) ) . '</a></td>';
                echo '<td>' . esc_html( $brand_str ) . '</td>';
                echo '<td>';
                if ( $url ) {
                    echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( basename( $file ) ) . '</a>';
                } else {
                    echo esc_html( basename( $file ) );
                }
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
        ?>
    </div>
    <?php
}

/**
 * Core: Rebuild the manual index from the manuals directory.
 *
 * @return array { files_scanned, posts_synced }
 */
function musesys_manuals_rebuild_index() {
    $paths = musesys_manuals_get_base_paths();
    $dir   = $paths['dir'];
    $url   = $paths['url'];

    $result = [
        'files_scanned' => 0,
        'posts_synced'  => 0,
    ];

    if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
        return $result;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $dir,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
        )
    );

    foreach ( $iterator as $fileinfo ) {
        /** @var SplFileInfo $fileinfo */
        if ( ! $fileinfo->isFile() ) {
            continue;
        }

        $ext = strtolower( $fileinfo->getExtension() );
        if ( 'pdf' !== $ext ) {
            continue;
        }

        $result['files_scanned']++;

        $full_path = $fileinfo->getPathname();
        $rel_path  = ltrim( str_replace( $dir, '', $full_path ), DIRECTORY_SEPARATOR );
        $file_url  = trailingslashit( $url ) . str_replace( DIRECTORY_SEPARATOR, '/', $rel_path );

        $parsed = musesys_manuals_parse_filename( $fileinfo->getFilename() );

        // Find existing manual by file meta
        $existing = get_posts( [
            'post_type'   => 'muse_manual',
            'meta_key'    => '_muse_manual_file',
            'meta_value'  => $full_path,
            'numberposts' => 1,
            'post_status' => 'any',
        ] );

        if ( ! empty( $existing ) ) {
            $manual_id = $existing[0]->ID;

            // Always update title to ensure clean names
            wp_update_post( [
                'ID'         => $manual_id,
                'post_title' => $parsed['title'],
            ] );
        } else {
            // Create new manual post
            $manual_id = wp_insert_post( [
                'post_type'   => 'muse_manual',
                'post_status' => 'publish',
                'post_title'  => $parsed['title'],
            ] );
        }

        if ( $manual_id && ! is_wp_error( $manual_id ) ) {
            update_post_meta( $manual_id, '_muse_manual_file', $full_path );
            update_post_meta( $manual_id, '_muse_manual_url', $file_url );
            update_post_meta( $manual_id, '_muse_manual_model_raw', $parsed['model'] );

            // Assign brand term if parsed. Use 'false' to replace old terms.
            $brands_to_set = $parsed['brand'] ? [ $parsed['brand'] ] : [];
            wp_set_object_terms( $manual_id, $brands_to_set, 'muse_manual_brand', false );

            $result['posts_synced']++;
        }
    }

    return $result;
}

/**
 * Heuristic filename parser.
 * Updated to strip junk, brackets, and ignore numeric brands.
 */
function musesys_manuals_parse_filename( $filename ) {
    // 1. Strip extension
    $name = preg_replace( '/\.pdf$/i', '', $filename );
    
    // 2. Kill .ocr junk (case insensitive)
    $name = preg_replace( '/\.ocr/i', '', $name );

    // 3. Clean separators AND BRACKETS (Fixes the "17))" issue)
    $name = str_replace( [ '_', '-', '(', ')' ], ' ', $name );
    $name = trim( preg_replace( '/\s+/', ' ', $name ) );

    $parts = explode( ' ', $name );
    $brand = '';
    $model = '';

    // 4. Heuristic: If first part is text, it's a brand. If numeric, ignore it.
    if ( ! empty( $parts ) ) {
        $first = $parts[0];
        if ( ! is_numeric($first) ) {
             $brand = ucfirst( strtolower( $first ) );
        }
    }

    // Very loose heuristic: assume last token containing a digit is model
    foreach ( array_reverse( $parts ) as $chunk ) {
        if ( preg_match( '/\d/', $chunk ) ) {
            $model = $chunk;
            break;
        }
    }

    // Title: nicer formatting
    $title = ucwords( strtolower( $name ) );

    return [
        'title' => $title,
        'brand' => $brand,
        'model' => $model,
    ];
}

/**
 * Shortcode: [musesys_manual_search]
 *
 * Renders a simple search form + results list for Manuals.
 */
add_shortcode( 'musesys_manual_search', 'musesys_manuals_shortcode' );
function musesys_manuals_shortcode( $atts ) {
    $atts = shortcode_atts( [
        'show_brand_filter' => 'yes',
        'results_per_page'  => 30,
    ], $atts, 'musesys_manual_search' );

    $show_brand_filter = ( 'yes' === strtolower( $atts['show_brand_filter'] ) );
    $limit             = max( 1, intval( $atts['results_per_page'] ) );

    $search_term = isset( $_GET['mmq'] ) ? sanitize_text_field( wp_unslash( $_GET['mmq'] ) ) : '';
    $brand_slug  = isset( $_GET['mmbrand'] ) ? sanitize_text_field( wp_unslash( $_GET['mmbrand'] ) ) : '';

    ob_start();

    ?>
    <form method="get" class="musesys-manual-search-form" style="margin-bottom:1rem;">
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;">
            <div>
                <label for="musesys-mm-q" style="display:block;font-weight:600;">
                    <?php esc_html_e( 'Search Manuals', 'musesys-manuals' ); ?>
                </label>
                <input
                    type="text"
                    id="musesys-mm-q"
                    name="mmq"
                    value="<?php echo esc_attr( $search_term ); ?>"
                    placeholder="<?php esc_attr_e( 'e.g. Hobart 403 mixer', 'musesys-manuals' ); ?>"
                    style="min-width:240px;"
                />
            </div>

            <?php if ( $show_brand_filter ) : ?>
                <div>
                    <label for="musesys-mm-brand" style="display:block;font-weight:600;">
                        <?php esc_html_e( 'Brand', 'musesys-manuals' ); ?>
                    </label>
                    <select id="musesys-mm-brand" name="mmbrand">
                        <option value=""><?php esc_html_e( 'All Brands', 'musesys-manuals' ); ?></option>
                        <?php
                        $brands = get_terms( [
                            'taxonomy'   => 'muse_manual_brand',
                            'hide_empty' => true,
                        ] );
                        if ( ! empty( $brands ) && ! is_wp_error( $brands ) ) {
                            foreach ( $brands as $brand ) {
                                printf(
                                    '<option value="%1$s"%3$s>%2$s</option>',
                                    esc_attr( $brand->slug ),
                                    esc_html( $brand->name ),
                                    selected( $brand_slug, $brand->slug, false )
                                );
                            }
                        }
                        ?>
                    </select>
                </div>
            <?php endif; ?>

            <div>
                <label style="display:block;font-weight:600;visibility:hidden;">&nbsp;</label>
                <button type="submit" class="button">
                    <?php esc_html_e( 'Search', 'musesys-manuals' ); ?>
                </button>
            </div>
        </div>
    </form>
    <?php

    // Only run query if user has typed something or selected a brand
    if ( '' !== $search_term || '' !== $brand_slug ) {
        $args = [
            'post_type'      => 'muse_manual',
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            's'              => $search_term,
            // Bypass Relevanssi to ensure this custom search works for unindexed CPTs
            'suppress_filters' => true,
        ];

        if ( $brand_slug ) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'muse_manual_brand',
                    'field'    => 'slug',
                    'terms'    => $brand_slug,
                ],
            ];
        }

        $query = new WP_Query( $args );

        if ( $query->have_posts() ) {
            echo '<ul class="musesys-manual-results" style="list-style:disc;padding-left:1.2rem;">';
            while ( $query->have_posts() ) {
                $query->the_post();
                $manual_id = get_the_ID();
                $url       = get_post_meta( $manual_id, '_muse_manual_url', true );
                $model     = get_post_meta( $manual_id, '_muse_manual_model_raw', true );
                $brands    = get_the_terms( $manual_id, 'muse_manual_brand' );

                $brand_str = '';
                if ( ! empty( $brands ) && ! is_wp_error( $brands ) ) {
                    $names     = wp_list_pluck( $brands, 'name' );
                    $brand_str = implode( ', ', $names );
                }

                echo '<li style="margin-bottom:.5rem;">';
                echo '<strong>' . esc_html( get_the_title() ) . '</strong>';

                $meta_bits = [];
                if ( $brand_str ) {
                    $meta_bits[] = esc_html( $brand_str );
                }
                if ( $model ) {
                    $meta_bits[] = esc_html( $model );
                }
                if ( ! empty( $meta_bits ) ) {
                    echo ' <span style="color:#666;">(' . implode( ' • ', $meta_bits ) . ')</span>';
                }

                if ( $url ) {
                    // SYLVA FIX: Use HTML entity for separator to avoid encoding diamonds "?"
                    echo ' &ndash; <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">'
                        . esc_html__( 'Open Manual', 'musesys-manuals' )
                        . '</a>';
                }

                echo '</li>';
            }
            echo '</ul>';

            wp_reset_postdata();
        } else {
            echo '<p>' . esc_html__( 'No manuals matched your search.', 'musesys-manuals' ) . '</p>';
        }
    }

    return ob_get_clean();
}