/**
 * Wegmans Brand Filter — brand-filter.js
 */
(function () {
    'use strict';

    const cfg = window.GE_BrandFilter || {};

    document.addEventListener('DOMContentLoaded', function () {
        const el = document.getElementById('ge-brand-select');
        if (!el) return;

        const ts = new TomSelect(el, {
            maxOptions:  200,
            placeholder: 'Search or select a brand...',
            searchField: ['text'],
            sortField:   [{ field: 'text', direction: 'asc' }],
            
            // Allow selecting the "empty" option to reset, 
            // but since our PHP value is "", it renders the placeholder.
            allowEmptyOption: true,
            
            // UX Tweak: Open dropdown immediately on focus
            openOnFocus: true,

            render: {
                option: function (data, escape) {
                    return '<div class="ge-ts-option">' + escape(data.text) + '</div>';
                },
                item: function (data, escape) {
                    const label = escape(data.text).replace(/\s*\(\d+\)$/, '');
                    return '<div>' + label + '</div>';
                },
            },

            onChange: function (value) {
                const url = new URL(window.location.href);

                if (value) {
                    url.searchParams.set(cfg.brandParam || 'brand', value);
                    url.searchParams.delete('s');
                    url.searchParams.delete('paged');
                } else {
                    window.location.href = cfg.shopUrl || url.origin + url.pathname;
                    return;
                }
                window.location.href = url.toString();
            },

            // The "Tooltip" Fix: Ensure input clears nicely
            onFocus: function() {
                // If the user clicks and it's empty, clear any residual text
                if (this.getValue() === '') {
                    this.clear(true);
                }
            }
        });

        if (cfg.activeBrand) {
            ts.setValue(cfg.activeBrand, true);
        }
    });

})();