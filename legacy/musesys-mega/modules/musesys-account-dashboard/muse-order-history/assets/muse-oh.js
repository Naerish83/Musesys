/* muse-oh.js — Order History Tab Interactions */
jQuery(function ($) {

    var $table    = $('#muse-oh-orders-table');
    var $search   = $('#muse-oh-search');
    var $filter   = $('#muse-oh-status-filter');
    var $count    = $('#muse-oh-result-count');

    // ─── Toggle Detail Row ──────────────────────────────────────

    $table.on('click', '.muse-oh-toggle', function () {
        var $btn    = $(this);
        var targetId = $btn.data('target');
        var $detail  = $('#' + targetId);
        var expanded = $btn.attr('aria-expanded') === 'true';

        $btn.attr('aria-expanded', !expanded);
        $detail.prop('hidden', expanded);
    });

    // ─── Search + Status Filter ─────────────────────────────────

    function applyFilters() {
        var term   = $search.val().toLowerCase().trim();
        var status = $filter.val();
        var visible = 0;

        $table.find('.muse-oh-order-row').each(function () {
            var $row    = $(this);
            var orderNum = String($row.data('order') || '');
            var rowStatus = String($row.data('status') || '');
            var store    = String($row.data('store') || '');
            var wo       = String($row.data('wo') || '');
            var skus     = String($row.data('skus') || '').toLowerCase();

            var matchTerm = !term ||
                orderNum.indexOf(term) !== -1 ||
                store.indexOf(term) !== -1 ||
                wo.toLowerCase().indexOf(term) !== -1 ||
                skus.indexOf(term) !== -1;

            var matchStatus = !status || rowStatus === status;

            var show = matchTerm && matchStatus;

            $row.toggleClass('muse-oh-hidden', !show);

            // Also hide detail row if parent is hidden
            var detailId = $row.find('.muse-oh-toggle').data('target');
            if (detailId) {
                var $detail = $('#' + detailId);
                if (!show) {
                    $detail.addClass('muse-oh-hidden');
                } else {
                    $detail.removeClass('muse-oh-hidden');
                }
            }

            if (show) visible++;
        });

        $count.text(visible + ' order' + (visible !== 1 ? 's' : ''));
    }

    $search.on('input', applyFilters);
    $filter.on('change', applyFilters);

});
