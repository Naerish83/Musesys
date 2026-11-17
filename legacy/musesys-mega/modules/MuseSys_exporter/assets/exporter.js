/**
 * MuseSys Product Exporter - Admin JS
 */
jQuery(document).ready(function($) {
    'use strict';

    // Select all meta checkboxes
    $('#select-all-meta').on('click', function(e) {
        e.preventDefault();
        $('input[name="columns[]"][value^="meta:"]').prop('checked', true);
    });

    // Deselect all meta checkboxes
    $('#deselect-all-meta').on('click', function(e) {
        e.preventDefault();
        $('input[name="columns[]"][value^="meta:"]').prop('checked', false);
    });

    // Form validation before submit
    $('.musesys-export-form').on('submit', function(e) {
        var checkedColumns = $('input[name="columns[]"]:checked').length;
        
        if (checkedColumns === 0) {
            e.preventDefault();
            alert('Please select at least one column to export.');
            return false;
        }

        // Show loading state
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Exporting...');
    });
});
