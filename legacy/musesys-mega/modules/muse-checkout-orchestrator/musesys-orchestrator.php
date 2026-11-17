<?php
/**
 * Plugin Name: Muse Checkout Orchestrator
 * Plugin URI: https://muse.systems/
 * Description: Centralizes Wegmans checkout field logic: per-line notes with Work Order # extraction, per-item Install Store # (required), optional global WO + global Install Store modes, contact phone snapshot, field cosmetics, and fragments. Billing requirements are relaxed to allow Wegmans-specific flow (billing data is effectively driven by user meta + store selection).
 * Version: 2.7.0
 * Author: Muse Systems
 * License: GPL-2.0-or-later
 * Text Domain: muse-checkout-orchestrator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Tenant gate removed for Wegmans 2.0 (single-site only)

// Include guard
if ( defined( 'MUSE_CO_GUARD' ) ) {
    return;
}
define( 'MUSE_CO_GUARD', true );

/** ----------------------------------------------------------------------------
 * CONFIG
 * ---------------------------------------------------------------------------*/

// Hide specific fields cosmetically
const MUSE_CO_HIDE = [ 'shipping_company', 'shipping_address_2' ];

const MUSE_CO_PRIORITIES = [
    'billing_phone'     => 25,
    'billing_email'     => 26,
    'billing_address_1' => 40,
    'billing_city'      => 50,
    'billing_state'     => 60,
    'billing_postcode'  => 70,
    'order_comments'    => 200,
];

const MUSE_CO_LABELS = [
    'billing_phone'  => 'Contact Phone',
    'order_comments' => 'Order Notes for Warehouse',
];

// Work Order behavior (global + per-line)
const MUSE_WO_FIELD_KEY          = 'muse_global_work_order';        // text field (global WO#)
const MUSE_WO_TOGGLE_KEY         = 'muse_global_work_order_toggle'; // checkbox toggle
const MUSE_WO_REQUIRE_TOGGLE     = true;                            // require toggle to activate global mode
const MUSE_WO_PROPAGATE_TO_ITEMS = true;                            // copy global WO# to item meta
const MUSE_WO_ITEM_NOTE_KEY      = 'muse_line_note';                // cart/session key for per-line input
const MUSE_WO_ITEM_META_WO       = 'WO Number';                     // item meta label for extracted WO#
const MUSE_WO_ITEM_META_NOTE     = 'Line Note';                     // item meta label for leftover note
const MUSE_WO_REGEX              = '/^[A-Z0-9-]{4,20}$/i';          // WO# pattern: 4–20 chars, alnum and dashes

// Install Store (per-item, required, with optional global mode)
const MUSE_IS_LABEL              = 'Install Store #';
const MUSE_IS_CART_KEY           = 'muse_install_store';         // cart/session key
const MUSE_IS_FIELD_ROOT         = 'musesys_install_store';      // $_POST root array
const MUSE_IS_META_LABEL         = 'Install Store #';            // human label on order items
const MUSE_IS_META_KEY           = '_muse_store_global';     // machine label on order items
const MUSE_IS_MAX                = 200;                          // 000–200 inclusive

// Global Install Store controls
const MUSE_IS_GLOBAL_TOGGLE_KEY  = 'muse_global_install_store_toggle';
const MUSE_IS_GLOBAL_FIELD_KEY   = 'muse_global_install_store';

/** ----------------------------------------------------------------------------
 * USER META: Contact Phone at profile + optional checkout prefill
 * ---------------------------------------------------------------------------*/
add_action( 'show_user_profile', 'muse_co_profile_contact_phone' );
add_action( 'edit_user_profile', 'muse_co_profile_contact_phone' );
function muse_co_profile_contact_phone( $user ) {
    $val = get_user_meta( $user->ID, 'contact_phone', true );
    ?>
    <h2>Muse Contact</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="contact_phone">Contact Phone</label></th>
            <td>
                <input name="contact_phone" id="contact_phone" type="text" value="<?php echo esc_attr( $val ); ?>" class="regular-text" />
                <p class="description">Displayed at checkout as “Contact Phone”.</p>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'personal_options_update', 'muse_co_profile_save' );
add_action( 'edit_user_profile_update', 'muse_co_profile_save' );
function muse_co_profile_save( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return;
    }
    if ( isset( $_POST['contact_phone'] ) ) {
        update_user_meta( $user_id, 'contact_phone', sanitize_text_field( wp_unslash( $_POST['contact_phone'] ) ) );
    }
}

// Prefill billing_phone from contact_phone if empty (non-enforcing)
add_filter( 'woocommerce_checkout_get_value', function ( $value, $input ) {
    if ( $input === 'billing_phone' && empty( $value ) && is_user_logged_in() ) {
        $cp = get_user_meta( get_current_user_id(), 'contact_phone', true );
        if ( ! empty( $cp ) ) {
            return $cp;
        }
    }
    return $value;
}, 10, 2 );

/** ----------------------------------------------------------------------------
 * FIELD COSMETICS: hide fields / labels / priorities / global controls
 * + Wegmans billing requirements relaxation
 * ---------------------------------------------------------------------------*/
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {

    // Hide select fields
    foreach ( MUSE_CO_HIDE as $key ) {
        foreach ( [ 'billing', 'shipping', 'account', 'order' ] as $group ) {
            if ( isset( $fields[ $group ][ $key ] ) ) {
                $fields[ $group ][ $key ]['required']      = false;
                $fields[ $group ][ $key ]['class'][]       = 'muse-co-hidden';
                $fields[ $group ][ $key ]['label_class'][] = 'muse-co-hidden';
            }
        }
    }

    // Labels
    foreach ( MUSE_CO_LABELS as $key => $label ) {
        foreach ( [ 'billing', 'shipping', 'order' ] as $group ) {
            if ( isset( $fields[ $group ][ $key ] ) ) {
                $fields[ $group ][ $key ]['label'] = $label;
            }
        }
    }

    // Priorities
    foreach ( MUSE_CO_PRIORITIES as $key => $prio ) {
        foreach ( [ 'billing', 'shipping', 'order' ] as $group ) {
            if ( isset( $fields[ $group ][ $key ] ) ) {
                $fields[ $group ][ $key ]['priority'] = $prio;
            }
        }
    }

    // Cosmetics for Order Notes
    if ( isset( $fields['order']['order_comments'] ) ) {
        $fields['order']['order_comments']['class']       = [ 'form-row-wide' ];
        $fields['order']['order_comments']['placeholder'] =
            'Anything the warehouse should know? Gate codes, dock hours, “call first,” etc.';
    }

    // Wegmans: Relax billing requirements & lock billing fields to system-supplied data
    if ( isset( $fields['billing'] ) && is_array( $fields['billing'] ) ) {

        $billing_soft_required = [
            'billing_first_name',
            'billing_last_name',
            'billing_company',
            'billing_address_1',
            'billing_address_2',
            'billing_city',
            'billing_state',
            'billing_postcode',
            'billing_country',
        ];

        foreach ( $billing_soft_required as $key ) {
            if ( ! isset( $fields['billing'][ $key ] ) ) {
                continue;
            }

            // Not required from the Wegmans tech filling out the form
            $fields['billing'][ $key ]['required'] = false;

            // Visually indicate "system-managed"
            $fields['billing'][ $key ]['class'][] = 'musesys-locked';

            // Prevent user edits but still submit values (readonly, not disabled)
            if ( ! isset( $fields['billing'][ $key ]['custom_attributes'] ) || ! is_array( $fields['billing'][ $key ]['custom_attributes'] ) ) {
                $fields['billing'][ $key ]['custom_attributes'] = [];
            }
            $fields['billing'][ $key ]['custom_attributes']['readonly'] = 'readonly';
        }
    }

    // Global WO toggle + field
    $fields['order'][ MUSE_WO_TOGGLE_KEY ] = [
        'type'     => 'checkbox',
        'label'    => 'Use one Work Order # for all items',
        'required' => false,
        'class'    => [ 'form-row-wide' ],
        'priority' => 120,
    ];
    $fields['order'][ MUSE_WO_FIELD_KEY ] = [
        'type'        => 'text',
        'label'       => 'Work Order # (Global)',
        'required'    => false,
        'class'       => [ 'form-row-first' ],
        'priority'    => 121,
        'placeholder' => 'e.g., WO-12345',
    ];

    // Global Install Store toggle + field
    $fields['order'][ MUSE_IS_GLOBAL_TOGGLE_KEY ] = [
        'type'     => 'checkbox',
        'label'    => 'Use one Install Store # for all items',
        'required' => false,
        'class'    => [ 'form-row-wide' ],
        'priority' => 122,
    ];
    $fields['order'][ MUSE_IS_GLOBAL_FIELD_KEY ] = [
        'type'     => 'select',
        'label'    => 'Install Store # (Global)',
        'required' => false,
        'class'    => [ 'form-row-first' ],
        'priority' => 123,
        'options'  => muse_is_options(),
    ];

    return $fields;
}, 10 );

/** ----------------------------------------------------------------------------
 * CSS
 * ---------------------------------------------------------------------------*/
add_action( 'wp_head', function () {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return;
    }
    $css = "
        .muse-co-hidden{display:none!important;}
        #muse-co-line-notes{margin:1rem 0;}
        .muse-co-line{display:flex;flex-direction:column;gap:.25rem;margin:.5rem 0;}
        .muse-co-line label{font-weight:600;}

        /* Inline layout: WO input (grow) + Install Store (fixed min width) */
        .muse-co-inline{display:flex;gap:.5rem;align-items:center;}
        .muse-co-inline .muse-co-wo{flex:1 1 auto;}
        .muse-co-inline .muse-co-is{flex:0 0 170px;max-width:200px;}
        @media (max-width:640px){
            .muse-co-inline{flex-direction:column;align-items:stretch;}
            .muse-co-inline .muse-co-is{flex:0 0 auto;max-width:none;}
        }

        .muse-co-disabled input,
        .muse-co-disabled select{opacity:.5;pointer-events:none;}

        .musesys-locked{background:#f5f5f5;cursor:not-allowed;}
    ";
    echo '<style>' . $css . '</style>';
} );

/** ----------------------------------------------------------------------------
 * Helpers: Install Store options + sanitize + state
 * ---------------------------------------------------------------------------*/
function muse_is_options() {
    $opts        = [ '' => __( 'Install Store #', 'muse-checkout-orchestrator' ) ];
    $opts['000'] = '000';
    for ( $i = 1; $i <= (int) MUSE_IS_MAX; $i++ ) {
        $opts[ sprintf( '%03d', $i ) ] = sprintf( '%03d', $i );
    }
    return $opts;
}

function muse_is_sanitize( $raw ) {
    $num = preg_replace( '/\D+/', '', (string) $raw );
    return $num === '' ? '' : sprintf( '%03d', (int) $num );
}

function muse_is_current_from_cart_item( $cart_item ) {
    if ( isset( $cart_item[ MUSE_IS_CART_KEY ] ) && is_string( $cart_item[ MUSE_IS_CART_KEY ] ) ) {
        return muse_is_sanitize( $cart_item[ MUSE_IS_CART_KEY ] );
    }
    return '';
}

/** ----------------------------------------------------------------------------
 * Global Mode Helpers
 * ---------------------------------------------------------------------------*/
function muse_co_is_wo_global_mode() {
    $toggle = ! empty( $_POST[ MUSE_WO_TOGGLE_KEY ] );
    $global = isset( $_POST[ MUSE_WO_FIELD_KEY ] ) ? trim( (string) $_POST[ MUSE_WO_FIELD_KEY ] ) : '';
    return MUSE_WO_REQUIRE_TOGGLE ? ( $toggle && $global !== '' ) : ( $global !== '' );
}

function muse_co_is_is_global_mode() {
    $toggle = ! empty( $_POST[ MUSE_IS_GLOBAL_TOGGLE_KEY ] );
    $global = isset( $_POST[ MUSE_IS_GLOBAL_FIELD_KEY ] ) ? muse_is_sanitize( $_POST[ MUSE_IS_GLOBAL_FIELD_KEY ] ) : '';
    return $toggle && $global !== '';
}

/** ----------------------------------------------------------------------------
 * LINE NOTES UI — single source of truth via checkout fragment (no duplicates)
 * Adds inline per-line Install Store dropdown (required per item)
 * ---------------------------------------------------------------------------*/
function muse_co_render_line_notes_markup() {
    if ( ! function_exists( 'WC' ) || ! WC()->cart || empty( WC()->cart->get_cart() ) ) {
        return;
    }
    if ( is_cart() ) {
        return;
    }

    $wo_global_mode = muse_co_is_wo_global_mode();
    $is_global_mode = muse_co_is_is_global_mode();

    echo '<div class="muse-co-line-notes" id="muse-co-line-notes">';
    echo '<h3>Line Notes</h3>';

    $options = muse_is_options();

    foreach ( WC()->cart->get_cart() as $key => $item ) {
        $product  = $item['data'];
        $existing = isset( $item[ MUSE_WO_ITEM_NOTE_KEY ] ) ? $item[ MUSE_WO_ITEM_NOTE_KEY ] : '';
        $is_value = muse_is_current_from_cart_item( $item );

        echo '<div class="muse-co-line">';

        echo '<label for="muse_line_note_' . esc_attr( $key ) . '">' . esc_html( $product->get_name() ) . '</label>';

        echo '<div class="muse-co-inline">';

        // WO input (hidden when global WO mode is active)
        $wo_style = $wo_global_mode ? 'style="display:none;"' : '';
        echo '<input type="text" ' . $wo_style . ' id="muse_line_note_' . esc_attr( $key ) . '" name="' . MUSE_WO_ITEM_NOTE_KEY . '[' . esc_attr( $key ) . ']" value="' . esc_attr( $existing ) . '" class="input-text muse-co-wo" placeholder="Enter Work Order #"/>';

        // Install Store select (hidden when global Install Store mode is active)
        $is_style  = $is_global_mode ? 'style="display:none;"' : '';
        $select_id = 'muse_install_store_' . esc_attr( $key );
        echo '<select ' . $is_style . ' class="muse-co-is" id="' . $select_id . '" name="' . esc_attr( MUSE_IS_FIELD_ROOT ) . '[' . esc_attr( $key ) . ']" required>';
        foreach ( $options as $val => $text ) {
            $sel = selected( $is_value, (string) $val, false );
            echo '<option value="' . esc_attr( $val ) . '" ' . $sel . '>' . esc_html( $text ) . '</option>';
        }
        echo '</select>';

        echo '</div>'; // .muse-co-inline
        echo '</div>'; // .muse-co-line
    }
    echo '</div>';
    ?>
    <script>
    (function(){
        function updateLineNotes(){
            var tWO = document.querySelector('input[name="<?php echo esc_js( MUSE_WO_TOGGLE_KEY ); ?>"]');
            var gWO = document.querySelector('input[name="<?php echo esc_js( MUSE_WO_FIELD_KEY ); ?>"]');

            var tIS = document.querySelector('input[name="<?php echo esc_js( MUSE_IS_GLOBAL_TOGGLE_KEY ); ?>"]');
            var gIS = document.querySelector('select[name="<?php echo esc_js( MUSE_IS_GLOBAL_FIELD_KEY ); ?>"]');

            var block = document.getElementById('muse-co-line-notes');
            if (!block) return;

            var lines = block.querySelectorAll('.muse-co-line');

            var woGlobalMode = <?php echo MUSE_WO_REQUIRE_TOGGLE ? '(tWO && tWO.checked && gWO && gWO.value.trim()!=="")' : '(gWO && gWO.value.trim()!=="")'; ?>;
            var isGlobalMode = (tIS && tIS.checked && gIS && gIS.value && gIS.value.trim() !== "");

            lines.forEach(function(line){
                var woInput = line.querySelector('.muse-co-wo');
                var isSelect = line.querySelector('.muse-co-is');

                // WO global: hide/disable per-line WO only
                if (woInput) {
                    if (woGlobalMode) {
                        woInput.style.display = 'none';
                        woInput.setAttribute('disabled','disabled');
                    } else {
                        woInput.style.display = '';
                        woInput.removeAttribute('disabled');
                    }
                }

                // Install Store global: hide/disable per-line Install Store only
                if (isSelect) {
                    if (isGlobalMode) {
                        isSelect.style.display = 'none';
                        isSelect.setAttribute('disabled','disabled');
                    } else {
                        isSelect.style.display = '';
                        isSelect.removeAttribute('disabled');
                    }
                }
            });
        }
        document.addEventListener('change',updateLineNotes);
        document.addEventListener('keyup',updateLineNotes);
        document.addEventListener('DOMContentLoaded',updateLineNotes);
        document.body && document.body.addEventListener('updated_checkout',updateLineNotes);
        updateLineNotes();
    })();
    </script>
    <?php
}

// Initial placement (stable area)
add_action( 'woocommerce_checkout_before_order_review', function () {
    muse_co_render_line_notes_markup();
}, 9 );

// Fragment replacement (prevents duplicates on AJAX refresh)
add_filter( 'woocommerce_update_order_review_fragments', function ( $fragments ) {
    ob_start();
    muse_co_render_line_notes_markup();
    $fragments['div#muse-co-line-notes'] = ob_get_clean();
    return $fragments;
} );

/** ----------------------------------------------------------------------------
 * Persist per-line notes and Install Store to cart session (non-global WO mode)
 * ---------------------------------------------------------------------------*/
add_action( 'woocommerce_checkout_process', function () {
    if ( ! function_exists( 'WC' ) || ! WC()->cart || empty( WC()->cart->cart_contents ) ) {
        return;
    }

    $wo_global_mode = muse_co_is_wo_global_mode();

    // Persist line notes only in non-global WO mode (existing behavior)
    if ( ! $wo_global_mode && ! empty( $_POST[ MUSE_WO_ITEM_NOTE_KEY ] ) && is_array( $_POST[ MUSE_WO_ITEM_NOTE_KEY ] ) ) {
        foreach ( $_POST[ MUSE_WO_ITEM_NOTE_KEY ] as $cart_key => $val ) {
            if ( isset( WC()->cart->cart_contents[ $cart_key ] ) ) {
                WC()->cart->cart_contents[ $cart_key ][ MUSE_WO_ITEM_NOTE_KEY ] = sanitize_text_field( $val );
            }
        }
    }

    // Persist per-line Install Store selection regardless of WO global mode
    if ( ! empty( $_POST[ MUSE_IS_FIELD_ROOT ] ) && is_array( $_POST[ MUSE_IS_FIELD_ROOT ] ) ) {
        foreach ( $_POST[ MUSE_IS_FIELD_ROOT ] as $cart_key => $val ) {
            if ( isset( WC()->cart->cart_contents[ $cart_key ] ) ) {
                WC()->cart->cart_contents[ $cart_key ][ MUSE_IS_CART_KEY ] = muse_is_sanitize( $val );
            }
        }
    }
} );

/** ----------------------------------------------------------------------------
 * Display in checkout review (non-cart): WO meta + Install Store row
 * ---------------------------------------------------------------------------*/
add_filter( 'woocommerce_get_item_data', function ( $item_data, $cart_item ) {
    if ( is_cart() ) {
        return $item_data;
    }

    // Existing WO/Note presentation (per-line)
    if ( ! empty( $cart_item[ MUSE_WO_ITEM_NOTE_KEY ] ) ) {
        $val   = wc_clean( $cart_item[ MUSE_WO_ITEM_NOTE_KEY ] );
        $wo    = '';
        $parts = preg_split( '/[\s,;:|]+/', $val );
        foreach ( $parts as $p ) {
            if ( preg_match( MUSE_WO_REGEX, $p ) ) {
                $wo = $p;
                break;
            }
        }
        if ( $wo !== '' ) {
            $item_data[] = [
                'key'     => MUSE_WO_ITEM_META_WO,
                'value'   => strtoupper( $wo ),
                'display' => strtoupper( $wo ),
            ];
            $leftover = trim( preg_replace( '/\b' . preg_quote( $wo, '/' ) . '\b/i', '', $val, 1 ) );
            if ( $leftover !== '' ) {
                $item_data[] = [
                    'key'     => MUSE_WO_ITEM_META_NOTE,
                    'value'   => $leftover,
                    'display' => $leftover,
                ];
            }
        } else {
            $item_data[] = [
                'key'     => MUSE_WO_ITEM_META_NOTE,
                'value'   => $val,
                'display' => $val,
            ];
        }
    }

    // Install Store display
    $is_val = muse_is_current_from_cart_item( $cart_item );
    if ( $is_val !== '' ) {
        $item_data[] = [
            'key'     => MUSE_IS_META_LABEL,
            'value'   => $is_val,
            'display' => $is_val,
        ];
    }

    return $item_data;
}, 10, 2 );

/** ----------------------------------------------------------------------------
 * Save WO#/notes + Install Store to order item meta (propagate globals if ON)
 * ---------------------------------------------------------------------------*/
add_action( 'woocommerce_checkout_create_order_line_item', function ( $item, $cart_item_key, $values, $order ) {
    // Global WO logic
    $wo_global_mode = muse_co_is_wo_global_mode();
    $global_wo      = isset( $_POST[ MUSE_WO_FIELD_KEY ] ) ? trim( (string) $_POST[ MUSE_WO_FIELD_KEY ] ) : '';

    if ( $wo_global_mode && MUSE_WO_PROPAGATE_TO_ITEMS && $global_wo !== '' ) {
        $item->add_meta_data( MUSE_WO_ITEM_META_WO, strtoupper( $global_wo ), true );
    } else {
        $input = isset( $_POST[ MUSE_WO_ITEM_NOTE_KEY ][ $cart_item_key ] )
            ? trim( (string) $_POST[ MUSE_WO_ITEM_NOTE_KEY ][ $cart_item_key ] )
            : ( isset( $values[ MUSE_WO_ITEM_NOTE_KEY ] ) ? trim( (string) $values[ MUSE_WO_ITEM_NOTE_KEY ] ) : '' );

        if ( $input !== '' ) {
            $wo    = '';
            $parts = preg_split( '/[\s,;:|]+/', $input );
            foreach ( $parts as $p ) {
                if ( preg_match( MUSE_WO_REGEX, $p ) ) {
                    $wo = $p;
                    break;
                }
            }
            if ( $wo !== '' ) {
                $item->add_meta_data( MUSE_WO_ITEM_META_WO, strtoupper( $wo ), true );
                $leftover = trim( preg_replace( '/\b' . preg_quote( $wo, '/' ) . '\b/i', '', $input, 1 ) );
                if ( $leftover !== '' ) {
                    $item->add_meta_data( MUSE_WO_ITEM_META_NOTE, $leftover, true );
                }
            } else {
                $item->add_meta_data( MUSE_WO_ITEM_META_NOTE, $input, true );
            }
        }
    }

    // Global Install Store logic
    $is_global_mode = muse_co_is_is_global_mode();
    $global_is      = $is_global_mode
        ? muse_is_sanitize( $_POST[ MUSE_IS_GLOBAL_FIELD_KEY ] ?? '' )
        : '';

    $is_val = '';
    if ( $is_global_mode && $global_is !== '' ) {
        $is_val = $global_is;
    } elseif ( isset( $_POST[ MUSE_IS_FIELD_ROOT ][ $cart_item_key ] ) ) {
        $is_val = muse_is_sanitize( $_POST[ MUSE_IS_FIELD_ROOT ][ $cart_item_key ] );
    } elseif ( isset( $values[ MUSE_IS_CART_KEY ] ) ) {
        $is_val = muse_is_sanitize( $values[ MUSE_IS_CART_KEY ] );
    }

    if ( $is_val !== '' ) {
        $item->add_meta_data( MUSE_IS_META_LABEL, $is_val, true ); // human-friendly
        $item->add_meta_data( MUSE_IS_META_KEY, $is_val, true );   // machine key
    }
}, 10, 4 );

/** ----------------------------------------------------------------------------
 * Validation: Global WO logic + REQUIRED Install Store per item
 * ---------------------------------------------------------------------------*/
add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
    // Global WO validity
    $wo_global_mode = muse_co_is_wo_global_mode();
    $global_wo      = isset( $_POST[ MUSE_WO_FIELD_KEY ] ) ? trim( (string) $_POST[ MUSE_WO_FIELD_KEY ] ) : '';

    $is_valid_wo = function ( $token ) {
        return (bool) preg_match( MUSE_WO_REGEX, $token );
    };

    if ( $wo_global_mode ) {
        if ( ! $is_valid_wo( $global_wo ) ) {
            $errors->add( 'validation', __( 'Global Work Order # is invalid. Use 4–20 characters (letters, numbers, dashes).', 'muse-checkout-orchestrator' ) );
        }
    } else {
        // Per-item WO presence/format
        $cart = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart() : [];
        foreach ( $cart as $cart_key => $values ) {
            $input = isset( $_POST[ MUSE_WO_ITEM_NOTE_KEY ][ $cart_key ] )
                ? trim( (string) $_POST[ MUSE_WO_ITEM_NOTE_KEY ][ $cart_key ] )
                : ( isset( $values[ MUSE_WO_ITEM_NOTE_KEY ] ) ? (string) $values[ MUSE_WO_ITEM_NOTE_KEY ] : '' );
            $candidate = '';
            if ( $input !== '' ) {
                $parts = preg_split( '/[\s,;:|]+/', $input );
                foreach ( $parts as $p ) {
                    if ( $is_valid_wo( $p ) ) {
                        $candidate = $p;
                        break;
                    }
                }
            }
            if ( $candidate === '' ) {
                $product_name = isset( $values['data'] ) ? $values['data']->get_name() : __( 'Item', 'muse-checkout-orchestrator' );
                $errors->add(
                    'validation',
                    sprintf(
                        __( 'Please enter a valid Work Order # for “%s”.', 'muse-checkout-orchestrator' ),
                        $product_name
                    )
                );
            }
        }
    }

    // REQUIRED: Install Store for every item, unless global Install Store is active
    $is_global_mode = muse_co_is_is_global_mode();
    $global_is      = $is_global_mode
        ? muse_is_sanitize( $_POST[ MUSE_IS_GLOBAL_FIELD_KEY ] ?? '' )
        : '';

    if ( $is_global_mode ) {
        if ( $global_is === '' ) {
            $errors->add(
                'validation',
                sprintf(
                    __( 'Please select a valid %s in the global Install Store # field.', 'muse-checkout-orchestrator' ),
                    MUSE_IS_LABEL
                )
            );
        }
    } else {
        $cart = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart() : [];
        foreach ( $cart as $cart_key => $values ) {
            $posted = isset( $_POST[ MUSE_IS_FIELD_ROOT ][ $cart_key ] ) ? (string) $_POST[ MUSE_IS_FIELD_ROOT ][ $cart_key ] : '';
            $val    = $posted !== '' ? muse_is_sanitize( $posted )
                : ( isset( $values[ MUSE_IS_CART_KEY ] ) ? muse_is_sanitize( $values[ MUSE_IS_CART_KEY ] ) : '' );
            if ( $val === '' ) {
                $product_name = isset( $values['data'] ) ? $values['data']->get_name() : __( 'Item', 'muse-checkout-orchestrator' );
                $errors->add(
                    'validation',
                    sprintf(
                        __( 'Please select %1$s for “%2$s”.', 'muse-checkout-orchestrator' ),
                        MUSE_IS_LABEL,
                        $product_name
                    )
                );
            }
        }
    }
}, 10, 2 );

/** ----------------------------------------------------------------------------
 * Order meta snapshot: store Contact Phone + Global WO (non-invasive)
 * ---------------------------------------------------------------------------*/
add_action( 'woocommerce_checkout_update_order_meta', function ( $order_id ) {
    $phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
    if ( $phone ) {
        update_post_meta( $order_id, '_muse_contact_phone', $phone );
    }

    $wo_global_mode = muse_co_is_wo_global_mode();
    $global_wo      = isset( $_POST[ MUSE_WO_FIELD_KEY ] ) ? trim( (string) $_POST[ MUSE_WO_FIELD_KEY ] ) : '';

    if ( $wo_global_mode && $global_wo !== '' ) {
        update_post_meta( $order_id, '_muse_wo_global', strtoupper( $global_wo ) );
    }

    $is_global_mode = muse_co_is_is_global_mode();
    $global_is      = $is_global_mode
        ? muse_is_sanitize( $_POST[ MUSE_IS_GLOBAL_FIELD_KEY ] ?? '' )
        : '';

    if ( $is_global_mode && $global_is !== '' ) {
        update_post_meta( $order_id, '_muse_store_global', $global_is );
    }
} );

/** ----------------------------------------------------------------------------
 * Admin & Email display for Contact Phone + Global WO
 * (Install Store per-item shows automatically via item meta)
 * ---------------------------------------------------------------------------*/
add_action( 'woocommerce_admin_order_data_after_billing_address', function ( $order ) {
    $id    = $order->get_id();
    $phone = get_post_meta( $id, '_muse_contact_phone', true );
    $gwo   = get_post_meta( $id, '_muse_wo_global', true );

    if ( $phone || $gwo ) {
        echo '<h4>Muse Checkout Fields</h4>';
        if ( $phone ) {
            echo '<p><strong>' . esc_html__( 'Contact Phone', 'muse-checkout-orchestrator' ) . ':</strong> ' . esc_html( $phone ) . '</p>';
        }
        if ( $gwo ) {
            echo '<p><strong>' . esc_html__( 'Global Work Order #', 'muse-checkout-orchestrator' ) . ':</strong> ' . esc_html( $gwo ) . '</p>';
        }
    }
}, 10 );

add_filter( 'woocommerce_email_order_meta_fields', function ( $fields, $sent_to_admin, $order ) {
    $id    = $order->get_id();
    $phone = get_post_meta( $id, '_muse_contact_phone', true );
    $gwo   = get_post_meta( $id, '_muse_wo_global', true );

    if ( $phone ) {
        $fields['muse_contact_phone'] = [
            'label' => __( 'Contact Phone', 'muse-checkout-orchestrator' ),
            'value' => $phone,
        ];
    }
    if ( $gwo ) {
        $fields['muse_global_wo'] = [
            'label' => __( 'Global Work Order #', 'muse-checkout-orchestrator' ),
            'value' => $gwo,
        ];
    }

    return $fields;
}, 10, 3 );
