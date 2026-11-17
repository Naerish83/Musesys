jQuery(document).ready(function($) {
    // Open the modal when a tracking button is clicked
    $('.muse-track-order-button').on('click', function(e) {
        e.preventDefault();

        // Get the list of tracking numbers
        var trackingNumbers = $(this).data('tracking-numbers');
        
        // Get the carrier
        var carrier = $(this).data('carrier');
        if (carrier == 'Other') {
            carrier = $(this).data('other-carrier');
        }

        // Clear previous tracking links
        $('#muse-tracking-links-container').empty();
        
        // Loop through and create a link for each tracking number
        if (trackingNumbers && trackingNumbers.length > 0) {
            $.each(trackingNumbers, function(index, number) {
                var trackingUrl = '';
                if (carrier == 'UPS') {
                    trackingUrl = 'https://www.ups.com/track?tracknum=' + number;
                } else if (carrier == 'FedEx') {
                    trackingUrl = 'https://www.fedex.com/fedextrack/?tracknumbers=' + number;
                } else {
                    trackingUrl = '#'; // Fallback for 'Other' or unknown
                }
                
                var trackingLink = $('<a>')
                    .attr('href', trackingUrl)
                    .attr('target', '_blank')
                    .text('Track #' + number + ' on ' + carrier);
                
                $('#muse-tracking-links-container').append(trackingLink).append('<br>');
            });
        } else {
             $('#muse-tracking-links-container').append('<p>No tracking information available.</p>');
        }

        // Show the modal
        $('#muse-tracking-modal').show();
        $('#muse-modal-overlay').show();
    });

    // Close the modal
    $('#muse-modal-close, #muse-modal-overlay').on('click', function() {
        $('#muse-tracking-modal').hide();
        $('#muse-modal-overlay').hide();
    });
});