<?php
/**
 * Plugin Name: Muse Systems - Quick Order by EAM
 * Plugin URI:  https://muse.systems/
 * Description: Adds a shortcode [muse_quick_order_form] to display a form for adding items to the cart by EAM # (SKU).
 * Version:     1.0
 * Author:      Muse Systems
 * Author URI:  https://muse.systems/
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * 1. PROCESS THE FORM
 * We run this on 'init' so it happens before any HTML is sent.
 * This lets us use wp_redirect() safely.
 */
add_action('init', 'muse_quick_order_process_submission');
function muse_quick_order_process_submission() {
    
    // Check if our form was submitted
    if (!isset($_POST['muse_quick_order_submit'])) {
        return;
    }

    // Security check
    if (!isset($_POST['muse_qo_nonce']) || !wp_verify_nonce($_POST['muse_qo_nonce'], 'muse_qo_action')) {
        die('Security check failed.');
    }

    // Make sure WooCommerce is active and we have a cart
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }

    // Check if we even have EAM numbers
    if (empty($_POST['eam_numbers']) || !is_array($_POST['eam_numbers'])) {
        return;
    }

    $added_count = 0;
    $error_eams = [];

    foreach ($_POST['eam_numbers'] as $eam) {
        $eam = sanitize_text_field(trim($eam));
        
        // Skip empty lines
        if (empty($eam)) {
            continue;
        }

        // Find the product ID by its SKU (EAM #)
        $product_id = wc_get_product_id_by_sku($eam);

        if ($product_id) {
            // Found it. Add to cart (quantity 1).
            if (WC()->cart->add_to_cart($product_id, 1)) {
                $added_count++;
            }
        } else {
            // Didn't find it. Log as an error.
            $error_eams[] = esc_html($eam);
        }
    }

    // --- We're done. Redirect back to the page with a status message. ---
    
    // Get the URL we came from
    $redirect_url = wp_get_referer();
    if (!$redirect_url) {
        $redirect_url = get_home_url(); // Failsafe
    }
    
    // Clean any old messages off the URL
    $redirect_url = remove_query_arg(array('qo_success', 'qo_error'), $redirect_url);

    // Add new messages
    if ($added_count > 0) {
        $redirect_url = add_query_arg('qo_success', $added_count, $redirect_url);
    }
    if (!empty($error_eams)) {
        $redirect_url = add_query_arg('qo_error', implode(',', $error_eams), $redirect_url);
    }

    // Go!
    wp_redirect($redirect_url);
    exit;
}


/**
 * 2. DISPLAY THE FORM
 * This is the shortcode handler.
 */
add_shortcode('muse_quick_order_form', 'muse_quick_order_display_form');
function muse_quick_order_display_form() {
    
    // Start an output buffer to "return" all this HTML
    ob_start();

    // --- A. Display Success/Error Messages (from the redirect) ---
    if (isset($_GET['qo_success'])) {
        $count = intval($_GET['qo_success']);
        $item_text = ($count === 1) ? 'item' : 'items';
        echo '<div class="muse-qo-message muse-qo-success">Successfully added ' . $count . ' ' . $item_text . ' to your cart.</div>';
    }
    
    if (isset($_GET['qo_error'])) {
        $bad_eams = explode(',', sanitize_text_field($_GET['qo_error']));
        echo '<div class="muse-qo-message muse-qo-error">The following EAM #s could not be found: <strong>' . implode(', ', $bad_eams) . '</strong></div>';
    }

    // --- B. Display the Form ---
    ?>
    <div class="muse-qo-form-wrapper">
        <form id="muse-quick-order-form" method="POST">
            
            <?php wp_nonce_field('muse_qo_action', 'muse_qo_nonce'); ?>
            
            <p class="muse-qo-note">This form only works for 6-digit EAM #s (SKU).</p>

            <div id="muse-qo-lines-container">
                <?php for ($i = 0; $i < 5; $i++) : // Start with 5 lines ?>
                <div class="muse-qo-line">
                    <input type="text" name="eam_numbers[]" class="muse-eam-input" placeholder="000000" maxlength="6" pattern="\d{6}">
                    <button type="button" class="muse-remove-line" style="display: none;">&times;</button>
                </div>
                <?php endfor; ?>
            </div>

            <div class="muse-qo-actions">
                <button type="button" id="muse-qo-add-line" class="button-secondary">Add Line +</button>
                <button type="submit" name="muse_quick_order_submit" class="button-primary">Add EAM(s) to Cart</button>
            </div>

        </form>
    </div>

    <?php
    // --- C. Add the CSS and JS (all in one file) ---
    // We add this here so it only loads when the shortcode is on the page.
    ?>
    <style>
        .muse-qo-form-wrapper {
            max-width: 400px;
            margin: 20px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #f9f9f9;
        }
        .muse-qo-note {
            font-style: italic;
            color: #555;
            text-align: center;
            margin-top: 0;
        }
        #muse-qo-lines-container {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 15px;
        }
        .muse-qo-line {
            display: flex;
            gap: 5px;
        }
        .muse-qo-line .muse-eam-input {
            width: 100%;
            flex: 1 1 auto;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 3px;
        }
        .muse-qo-line .muse-remove-line {
            flex: 0 0 auto;
            background: #e0e0e0;
            border: 1px solid #ccc;
            color: #777;
            cursor: pointer;
            width: 38px;
            padding: 0;
        }
        .muse-qo-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .muse-qo-actions .button-primary {
            background: #0073aa;
            border-color: #0073aa;
            color: #fff;
            padding: 10px 15px;
            font-size: 16px;
            cursor: pointer;
        }
        .muse-qo-actions .button-secondary {
            font-size: 14px;
            padding: 8px 12px;
            cursor: pointer;
        }
        .muse-qo-message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 3px;
        }
        .muse-qo-success {
            background: #e0f8e0;
            border: 1px solid #a0dca0;
        }
        .muse-qo-error {
            background: #f8e0e0;
            border: 1px solid #dca0a0;
        }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        
        var container = document.getElementById('muse-qo-lines-container');
        var addBtn = document.getElementById('muse-qo-add-line');

        // Function to add a new line
        function addLine() {
            var newLine = document.createElement('div');
            newLine.className = 'muse-qo-line';
            newLine.innerHTML = `
                <input type="text" name="eam_numbers[]" class="muse-eam-input" placeholder="000000" maxlength="6" pattern="\\d{6}">
                <button type="button" class="muse-remove-line">&times;</button>
            `;
            container.appendChild(newLine);
            updateRemoveButtons();
        }

        // Function to update which "remove" buttons are visible
        // We never let the user remove all the lines.
        function updateRemoveButtons() {
            var lines = container.querySelectorAll('.muse-qo-line');
            lines.forEach((line, index) => {
                var removeBtn = line.querySelector('.muse-remove-line');
                // Show remove button only if it's not the very first line
                removeBtn.style.display = (index > 0) ? 'inline-block' : 'none';
            });
        }

        // Add Line Button Click
        addBtn.addEventListener('click', addLine);

        // Remove Line Button Click (using event delegation)
        container.addEventListener('click', function(e) {
            if (e.target.classList.contains('muse-remove-line')) {
                // Check if it's not the first line before removing
                var lines = Array.from(container.querySelectorAll('.muse-qo-line'));
                var lineToRemove = e.target.parentElement;
                var index = lines.indexOf(lineToRemove);
                
                if (index > 0) { // Never remove the first line
                    lineToRemove.remove();
                    updateRemoveButtons();
                }
            }
        });

        // Initial check on page load
        updateRemoveButtons();
    });
    </script>
    <?php

    // Return the final HTML
    return ob_get_clean();
}