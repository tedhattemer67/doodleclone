/**
 * DCS Frontend Script
 * Handles the booking/poll form submission via AJAX.
 */
jQuery( document ).ready( function ( $ ) {

    $( '#dcs-booking' ).on( 'submit', function ( e ) {
        e.preventDefault();

        var $form    = $( this );
        var $btn     = $form.find( 'button[type="submit"]' );
        var $message = $( '#dcs-message' );

        $btn.prop( 'disabled', true );
        $message.text( '' ).removeClass( 'dcs-message--error' );

        var data = $form.serialize()
            + '&action=dcs_book_slot'
            + '&dcs_nonce=' + encodeURIComponent( dcs_ajax.nonce );

        $.post( dcs_ajax.ajax_url, data )
            .done( function ( response ) {
                if ( response.success ) {
                    var d = response.data || {};

                    if ( d.mode === 'poll' ) {
                        // Show contextual message depending on whether this was an edit
                        var msg = d.is_update
                            ? 'Your availability has been updated. Check your email for a fresh edit link.'
                            : 'Thanks! Your availability has been recorded. Check your email for a link to edit your response.';
                        $message.text( msg );

                        // Carry the fresh token forward in the live form too, not just the
                        // URL — the server now requires a valid token to authorize any
                        // resubmission against an email that already has selections on
                        // file, so without this a same-page resubmit (no reload) would be
                        // wrongly rejected as unauthorized.
                        if ( d.token ) {
                            var $tokenInput = $form.find( 'input[name="dcs_edit_token"]' );
                            if ( ! $tokenInput.length ) {
                                $tokenInput = $( '<input>', { type: 'hidden', name: 'dcs_edit_token' } ).appendTo( $form );
                            }
                            $tokenInput.val( d.token );

                            // Update the page URL to carry the fresh token so that if the
                            // voter bookmarks or refreshes, the form still pre-populates.
                            if ( window.history && window.history.replaceState ) {
                                var url = new URL( window.location.href );
                                url.searchParams.set( 'dcs_token', d.token );
                                window.history.replaceState( {}, '', url.toString() );
                            }
                        }

                        $btn.prop( 'disabled', false );
                        return;
                    }

                    // 1-on-1 booking: redirect with success params
                    var url = new URL( window.location.href );
                    url.searchParams.set( 'booking_success', d.slot_id );
                    url.searchParams.set( 'name', d.user );
                    // Drop any edit token from the URL on a confirmed booking
                    url.searchParams.delete( 'dcs_token' );
                    window.location = url.toString();

                } else {
                    $message
                        .text( response.data || 'Something went wrong. Please try again.' )
                        .addClass( 'dcs-message--error' );
                    $btn.prop( 'disabled', false );
                }
            } )
            .fail( function () {
                $message
                    .text( 'A network error occurred. Please try again.' )
                    .addClass( 'dcs-message--error' );
                $btn.prop( 'disabled', false );
            } );
    } );

} );
