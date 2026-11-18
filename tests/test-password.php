<?php
/**
 * Password-protected events (2.4.5): no form, no submissions and no overview
 * listing until the visitor has entered the password.
 * Run from the repo root:  php tests/test-password.php
 */
require __DIR__ . '/bootstrap.php';

$D1     = date( 'Y-m-d', strtotime( '+10 days' ) );
$NONCE  = [ 'dcs_save_details_nonce' => 'nonce-dcs_save_details_action' ];
$CLOSE  = [ 'dcs_close_poll' => '1', 'dcs_close_poll_nonce' => 'nonce-dcs_close_poll_action' ];
$PROMPT = '<form class="post-password-form">Enter password</form>';
$slot   = fn( $time, $max = '' ) => [ 'date' => $D1, 'time' => $time, 'duration_minutes' => '60', 'max' => (string) $max, 'attendees' => [ [ 'name' => '', 'email' => '' ] ] ];

function key0( $id ) { return dcs_slot_key( slots( $id )[0] ); }
function page( $id, $content ) {
    $GLOBALS['_current_post'] = $id;
    $_GET = [];
    return DCS_Frontend::inject_form( $content );
}

make_event( 900, 'booking', 'Private 1-on-1s' );
save_event( 900, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'slots' => [ $slot( '09:00', 1 ), $slot( '10:00', 1 ) ] ] );
make_event( 901, 'poll', 'Private poll' );
save_event( 901, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => [ $slot( '09:00' ), $slot( '12:00' ) ] ] );
make_event( 902, 'sessions', 'Private sessions' );
save_event( 902, $NONCE + [ 'dcs_meeting_mode' => 'sessions', 'slots' => [ $slot( '09:00', 5 ) ] ] );
make_event( 903, 'booking', 'Open event' );
save_event( 903, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'slots' => [ $slot( '09:00', 1 ) ] ] );
foreach ( [ 900, 901, 902 ] as $id ) get_post( $id )->post_password = 's3cret';

// ---------------------------------------------------------------------------
section( 'A. Event page' );

foreach ( [ 900 => '1-on-1', 901 => 'poll', 902 => 'Sessions' ] as $id => $label ) {
    ok( page( $id, $PROMPT ) === $PROMPT, "$label: only the password prompt, no form" );
}

// A closed poll's notice reveals the chosen time — hidden too.
$GLOBALS['_postpass'][901] = true;
save_event( 901, $NONCE + $CLOSE + [ 'dcs_meeting_mode' => 'poll', 'slots' => [ $slot( '09:00' ), $slot( '12:00' ) ], 'dcs_selected_slot_index' => '1' ] );
unset( $GLOBALS['_postpass'][901] );
ok( page( 901, $PROMPT ) === $PROMPT, 'closed poll: chosen time not shown before the password' );

$GLOBALS['_postpass'][900] = true;
$out = page( 900, '<p>body</p>' );
ok( str_contains( $out, 'id="dcs-booking"' ), 'after entering the password the form is shown' );
unset( $GLOBALS['_postpass'][900] );
ok( str_contains( page( 903, '<p>body</p>' ), 'id="dcs-booking"' ), 'unprotected event unchanged' );

// ---------------------------------------------------------------------------
section( 'B. Submissions' );

$r = ajax( [ 'event_id' => 900, 'name' => 'Amy', 'email' => 'amy@example.com', 'slot_id' => key0( 900 ) ] );
ok( $r && ! $r->ok && str_contains( (string) $r->data, 'password protected' ), '1-on-1 booking refused without the password' );
update_post_meta( 901, '_poll_status', 'open' ); // reopen so the password check, not "closed", is what refuses
$r = ajax( [ 'event_id' => 901, 'name' => 'Ben', 'email' => 'ben@example.com', 'slot_ids' => [ key0( 901 ) ] ] );
ok( $r && ! $r->ok && str_contains( (string) $r->data, 'password protected' ), 'poll vote refused without the password' );
$r = ajax( [ 'event_id' => 902, 'name' => 'Cy', 'email' => 'cy@example.com', 'part_slots' => [ key0( 902 ) ] ] );
ok( $r && ! $r->ok && str_contains( (string) $r->data, 'password protected' ), 'Sessions sign-up refused without the password' );

$nobody = true;
foreach ( [ 900, 901, 902 ] as $id ) foreach ( slots( $id ) as $s ) if ( array_filter( array_column( $s['attendees'], 'email' ) ) ) $nobody = false;
ok( $nobody, 'nothing was stored' );
ok( mails_to( 'amy@example.com' ) === [] && mails_to( 'ben@example.com' ) === [], 'no confirmation emails sent' );

$GLOBALS['_postpass'][900] = true;
$r = ajax( [ 'event_id' => 900, 'name' => 'Amy', 'email' => 'amy@example.com', 'slot_id' => key0( 900 ) ] );
ok( $r && $r->ok, 'booking accepted once the password has been entered' );
unset( $GLOBALS['_postpass'][900] );

// ---------------------------------------------------------------------------
section( 'C. Overview shortcode' );

$GLOBALS['_can'] = false; // logged-out visitor
$GLOBALS['_postpass'][900] = true; // even with the password cookie
$ov = DCS_Frontend::render_overview_shortcode();
ok( str_contains( $ov, 'Open event' ), 'unprotected event listed' );
ok( ! str_contains( $ov, 'Private 1-on-1s' ) && ! str_contains( $ov, 'Private poll' ) && ! str_contains( $ov, 'Private sessions' ), 'protected events left out for visitors (cookie ignored: page caches)' );
unset( $GLOBALS['_postpass'][900] );

$GLOBALS['_can'] = true; // someone who can edit the events
$ov = DCS_Frontend::render_overview_shortcode();
ok( str_contains( $ov, 'Private 1-on-1s' ) && str_contains( $ov, 'Amy' ), 'protected events still listed for their editors' );

finish();
