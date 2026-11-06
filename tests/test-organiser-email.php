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

// ---------------------------------------------------------------------------
section( 'E. New-response notice: Group poll (2.4.2)' );
$D2 = date( 'Y-m-d', strtotime( '+11 days' ) );
owned_event( 940, 'poll', 42 );
save_event( 940, [ 'dcs_meeting_mode' => 'poll', 'slots' => [
    [ 'date' => $D2, 'time' => '15:00', 'duration_minutes' => '60', 'max' => '' ],
    [ 'date' => $D1, 'time' => '09:00', 'duration_minutes' => '60', 'max' => '' ],
    [ 'date' => $D1, 'time' => '12:00', 'duration_minutes' => '60', 'max' => '' ],
] ] );
$pkey = function ( $date, $time ) {
    foreach ( slots( 940 ) as $s ) if ( $s['date'] === $date && $s['time'] === $time ) return dcs_slot_key( $s );
};
$pvote = fn( $who, array $keys, $token = '' ) => ajax( [ 'event_id' => 940, 'name' => ucfirst( $who ), 'email' => "$who@example.com", 'slot_ids' => $keys ]
    + ( $token !== '' ? [ 'dcs_edit_token' => $token ] : [] ) );

$GLOBALS['_mail'] = [];
ok( $pvote( 'pam', [ $pkey( $D2, '15:00' ), $pkey( $D1, '09:00' ) ] )->ok, 'Pam votes' );
$o = mails_to( 'olive@example.com' );
ok( count( $o ) === 1 && $o[0]['subj'] === 'New response: Event 940', 'organiser gets "New response: <title>"' );
ok( str_contains( $o[0]['body'], 'Pam (pam@example.com) is available for:' ), '...saying who responded' );
$b = $o[0]['body'];
ok( str_contains( $b, '- ' . dcs_slot_range( slots( 940 )[1] ) ) && str_contains( $b, '- ' . dcs_slot_range( slots( 940 )[0] ) ) && ! str_contains( $b, dcs_slot_range( slots( 940 )[2] ) ), '...listing exactly the times she picked' );
ok( strpos( $b, dcs_slot_range( slots( 940 )[1] ) ) < strpos( $b, dcs_slot_range( slots( 940 )[0] ) ), '...earliest first' );
ok( str_contains( $b, 'wp-admin/post.php?post=940&action=edit' ), '...with a link to the event\'s results' );
ok( count( mails_to( 'pam@example.com' ) ) === 1 && mails_to( 'admin@example.com' ) === [], 'voter still gets her confirmation; site admin gets nothing' );

$GLOBALS['_mail'] = [];
ok( $pvote( 'pam', [ $pkey( $D1, '12:00' ) ], dcs_make_edit_token( 940, 'pam@example.com' ) )->ok, 'Pam edits her vote via her link' );
ok( mails_to( 'olive@example.com' ) === [] && count( mails_to( 'pam@example.com' ) ) === 1, 'edit: no organiser email (voter still gets a fresh link)' );

// Someone the organiser added by hand, responding for the first time, is new.
$s = slots( 940 ); $s[0]['attendees'][] = [ 'name' => 'Hal', 'email' => 'hal@example.com' ]; update_post_meta( 940, '_meeting_slots', $s );
$GLOBALS['_mail'] = [];
ok( $pvote( 'hal', [ $pkey( $D1, '09:00' ) ] )->ok && count( mails_to( 'olive@example.com' ) ) === 1, 'hand-added person\'s first own response: organiser notified' );

$GLOBALS['_mail'] = [];
$r = $pvote( 'pam', [ $pkey( $D1, '09:00' ) ] );
ok( $r && ! $r->ok && mails_to( 'olive@example.com' ) === [], 'refused submission (no edit link): no organiser email' );

owned_event( 941, 'poll', 0 );
save_event( 941, [ 'dcs_meeting_mode' => 'poll', 'slots' => [ [ 'date' => $D1, 'time' => '09:00', 'duration_minutes' => '60', 'max' => '' ] ] ] );
$GLOBALS['_mail'] = [];
ajax( [ 'event_id' => 941, 'name' => 'Ned', 'email' => 'ned@example.com', 'slot_ids' => [ dcs_slot_key( slots( 941 )[0] ) ] ] );
ok( count( mails_to( 'admin@example.com' ) ) === 1, 'poll with no owner: notice to site admin' );

// ---------------------------------------------------------------------------
section( 'F. New-response notice: Sessions (2.4.2)' );
owned_event( 950, 'sessions', 42 );
save_event( 950, [ 'dcs_save_details_nonce' => 'nonce-dcs_save_details_action', 'dcs_parts_present' => '1',
    'dcs_meeting_mode' => 'sessions', 'dcs_sessions_attendance' => 'recommended',
    'parts' => [ [ 'id' => 'part_aaaa1111', 'title' => 'Kickoff' ], [ 'id' => 'part_bbbb2222', 'title' => 'Wrap-up' ] ],
    'slots' => [
        [ 'date' => $D1, 'time' => '09:00', 'duration_minutes' => '60', 'max' => '', 'part' => 'part_aaaa1111' ],
        [ 'date' => $D2, 'time' => '09:00', 'duration_minutes' => '60', 'max' => '', 'part' => 'part_bbbb2222' ],
    ] ] );
$skey = dcs_slot_key( slots( 950 )[0] );
$GLOBALS['_mail'] = [];
$r = ajax( [ 'event_id' => 950, 'name' => 'Sue', 'email' => 'sue@example.com', 'part_slots' => [ $skey, '' ] ] );
ok( $r && $r->ok, 'Sue signs up for Kickoff, skips Wrap-up' );
$o = mails_to( 'olive@example.com' );
ok( count( $o ) === 1 && $o[0]['subj'] === 'New response: Event 950', 'organiser gets "New response: <title>"' );
ok( count( $o ) === 1 && str_contains( $o[0]['body'], 'Sue (sue@example.com) registered for:' )
    && str_contains( $o[0]['body'], 'Kickoff: ' . dcs_slot_range( slots( 950 )[0] ) )
    && str_contains( $o[0]['body'], 'Wrap-up: not attending' ), '...with her schedule per part, including skipped parts' );

$GLOBALS['_mail'] = [];
$r = ajax( [ 'event_id' => 950, 'name' => 'Sue', 'email' => 'sue@example.com', 'part_slots' => [ $skey, dcs_slot_key( slots( 950 )[1] ) ],
    'dcs_edit_token' => dcs_make_edit_token( 950, 'sue@example.com' ) ] );
ok( $r && $r->ok && mails_to( 'olive@example.com' ) === [], 'edit: no organiser email' );

finish();
