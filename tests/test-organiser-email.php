<?php
/**
 * Organiser notifications go to the event's owner (2.4.1).
 * Run from the repo root:  php tests/test-organiser-email.php
 */
require __DIR__ . '/bootstrap.php';

$D1 = date( 'Y-m-d', strtotime( '+10 days' ) );
$GLOBALS['_users'] = [ 42 => 'olive@example.com', 43 => 'not-an-email' ];
function owned_event( $id, $mode, $author ) {
    $p = make_event( $id, $mode, "Event $id" );
    $p->post_author = $author;
    return $p;
}
function booking_event( $id, $author ) {
    global $D1;
    owned_event( $id, 'booking', $author );
    save_event( $id, [ 'dcs_meeting_mode' => 'booking', 'slots' => [
        [ 'date' => $D1, 'time' => '09:00', 'duration_minutes' => '60', 'max' => '1' ],
        [ 'date' => $D1, 'time' => '10:00', 'duration_minutes' => '60', 'max' => '1' ],
    ] ] );
}
function book( $id, $who, $i = 0 ) {
    return ajax( [ 'event_id' => $id, 'name' => ucfirst( $who ), 'email' => "$who@example.com", 'slot_id' => dcs_slot_key( slots( $id )[ $i ] ) ] );
}

// ---------------------------------------------------------------------------
section( 'A. Who is the organiser' );
owned_event( 900, 'booking', 42 );
owned_event( 901, 'booking', 0 );
owned_event( 902, 'booking', 99 );  // author account deleted
owned_event( 903, 'booking', 43 );  // author has a bad email
ok( dcs_event_organiser_email( 900 ) === 'olive@example.com', 'event author\'s email' );
ok( dcs_event_organiser_email( 901 ) === 'admin@example.com', 'no author: site admin email' );
ok( dcs_event_organiser_email( 902 ) === 'admin@example.com', 'author account gone: site admin email' );
ok( dcs_event_organiser_email( 903 ) === 'admin@example.com', 'author email invalid: site admin email' );
ok( dcs_event_organiser_email( 12345 ) === 'admin@example.com', 'unknown event: site admin email' );

// ---------------------------------------------------------------------------
section( 'B. New-booking notification' );
booking_event( 910, 42 );
$GLOBALS['_mail'] = [];
ok( book( 910, 'amy' )->ok, 'Amy books' );
$o = mails_to( 'olive@example.com' );
ok( count( $o ) === 1 && str_contains( $o[0]['subj'], 'New booking' ) && str_contains( $o[0]['body'], 'Amy (amy@example.com)' ), 'organiser gets "New booking"' );
ok( mails_to( 'admin@example.com' ) === [], 'site admin does not' );
ok( count( mails_to( 'amy@example.com' ) ) === 1, 'attendee still gets their confirmation' );

booking_event( 911, 0 );
$GLOBALS['_mail'] = [];
book( 911, 'ben' );
ok( count( mails_to( 'admin@example.com' ) ) === 1, 'event without an owner: notification to site admin, as before' );

// ---------------------------------------------------------------------------
section( 'C. Final-details summary' );
save_event( 910, [ 'dcs_close_poll' => '1', 'dcs_close_poll_nonce' => 'nonce-dcs_close_poll_action' ] );
$GLOBALS['_mail'] = [];
save_event( 910, [ 'dcs_send_announce' => 'all', 'dcs_send_announce_nonce' => 'nonce-dcs_send_announce_action' ] );
$o = mails_to( 'olive@example.com' );
ok( count( $o ) === 1 && str_contains( $o[0]['subj'], 'Final details sent' ) && str_contains( $o[0]['body'], 'Amy' ), 'organiser gets the send summary' );
ok( mails_to( 'admin@example.com' ) === [], 'site admin does not' );
ok( count( mails_to( 'amy@example.com' ) ) === 1, 'attendee gets final details' );

// ---------------------------------------------------------------------------
section( 'D. "Contact the organiser" messages' );
booking_event( 920, 42 );
book( 920, 'cat' );
$r = book( 920, 'cat', 1 );
ok( $r && ! $r->ok && str_contains( (string) $r->data, 'contact the organiser at olive@example.com' ), 'second booking refused, points to the event\'s organiser' );

owned_event( 930, 'poll', 42 );
update_post_meta( 930, '_meeting_slots', [ [ 'id' => 'slot_p1', 'date' => $D1, 'time' => '11:00', 'duration_minutes' => 30, 'max' => 0, 'attendees' => [] ] ] );
$b64     = rtrim( strtr( base64_encode( json_encode( [ 'event_id' => 930, 'email' => 'x@example.com', 'exp' => time() - 60 ] ) ), '+/', '-_' ), '=' );
$expired = $b64 . '.' . hash_hmac( 'sha256', $b64, AUTH_SALT );
$html    = front( 930, $expired );
ok( str_contains( $html, 'Your edit link has expired' ) && str_contains( $html, 'mailto:olive@example.com' ) && ! str_contains( $html, 'admin@example.com' ), 'expired link on open poll: contact the organiser' );

update_post_meta( 930, '_poll_status', 'closed' );
$html = front( 930, $expired );
ok( str_contains( $html, 'mailto:olive@example.com' ) && ! str_contains( $html, 'admin@example.com' ), 'expired link on closed poll: contact the organiser' );

finish();
