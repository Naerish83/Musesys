jQuery(document).ready(function ($) {

    $(document).on('click', '.muse-favorite-toggle', function (e) {
        e.preventDefault();

        let icon = $(this);
        let pid  = icon.data('product-id');

        icon.addClass('muse-fav-processing');

        $.post(MuseFavAjax.ajaxurl, {
            action: 'muse_toggle_favorite',
            product_id: pid,
            nonce: MuseFavAjax.nonce
        }, function (response) {

            icon.removeClass('muse-fav-processing');

            if (!response || !response.success) {
                console.warn('Favorite toggle failed.');
                return;
            }

            if (response.data.status === 'added') {
                icon.removeClass('muse-fav-inactive').addClass('muse-fav-active');
            } else {
                icon.removeClass('muse-fav-active').addClass('muse-fav-inactive');
            }

        });

    });

});
