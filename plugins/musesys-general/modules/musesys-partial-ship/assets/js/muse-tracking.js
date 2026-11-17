jQuery(document).ready(function ($) {
    // Click handler for Track buttons
    $(document).on('click', 'a.muse_track', function (e) {
        var href = $(this).attr('href') || '';
        if (href.indexOf('#muse-track-') === 0) {
            e.preventDefault();
            var orderId = parseInt(href.replace('#muse-track-', ''), 10);
            var dataMap = (window.MuseTrackings && window.MuseTrackings[orderId]) ? window.MuseTrackings[orderId] : null;

            var list = $('#muse-tracking-list');
            list.empty();

            if (!dataMap || !dataMap.trackings || !dataMap.trackings.length) {
                list.append('<p>No tracking information available.</p>');
            } else {
                dataMap.trackings.forEach(function (t) {
                    var safeCarrier = $('<div/>').text(t.carrier || '').html();
                    var safeTn = $('<div/>').text(t.tracking || '').html();

                    if (t.type === 'TECH_DELIVERY') {
                        list.append('<p><strong>' + safeCarrier + '</strong></p>');
                        // Optional: Add date if available, currently just carrier label
                    } else if (t.type === 'CUSTOMER_PICKUP') {
                        list.append('<p><strong>' + safeCarrier + '</strong></p>');
                    } else {
                        var link = (t.url && t.url !== '#') ? '<a href="' + encodeURI(t.url) + '" target="_blank" rel="noopener">Track</a>' : '';
                        list.append('<p><strong>Carrier:</strong> ' + safeCarrier + '</p>');
                        list.append('<p><strong>Tracking #:</strong> ' + safeTn + ' ' + link + '</p>');
                    }
                    list.append('<hr style="margin: 8px 0; border: 0; border-top: 1px solid #eee;">');
                });
            }

            $('#muse-tracking-modal').show();
            $('#muse-modal-overlay').show();
        }
    });

    // Close modal
    $('#muse-modal-close, #muse-modal-overlay').on('click', function () {
        $('#muse-tracking-modal').hide();
        $('#muse-modal-overlay').hide();
    });
});
