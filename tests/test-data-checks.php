<?php
/**
 * 2.4.7: poll votes must name real times (#9); one email-identity rule
 * everywhere (#10); the last slot can be deleted (#8).
 * Run from the repo root:  php tests/test-data-checks.php
 */
require __DIR__ . '/bootstrap.php';

$D1    = date( 'Y-m-d', strtotime( '+10 days' ) );
$NONCE = [ 'dcs_save_details_nonce' => 'nonce-dcs_save_details_action' ];
$CLOSE = [ 'dcs_close_poll' => '1', 'dcs_close_poll_nonce' => 'nonce-dcs_close_poll_action' ];
$slot  = fn( $time, $max = '' ) => [ 'date' => $D1, 'time' => $time, 'duration_minutes' => '60', 'max' => (string) $max, 'attendees' => [ [ 'name' => '', 'email' => '' ] ] ];

function slot_at( $id, $time ) {
    foreach ( slots( $id ) as $s ) if ( $s['time'] === $time ) return $s;
    return null;
}
function key_at( $id, $time ) { return dcs_slot_key( slot_at( $id, $time ) ); }
function emails_at( $id, $time ) { return array_column( slot_at( $id, $time )['attendees'] ?? [], 'email' ); }
function vote_as( $id, $email, array $keys, string $token = '' ) {
    return ajax( [ 'event_id' => $id, 'name' => 'Voter', 'email' => $email, 'slot_ids' => $keys ]
        + ( $token !== '' ? [ 'dcs_edit_token' => $token ] : [] ) );
}
function all_emails( $id ) {
    $out = [];
    foreach ( slots( $id ) as $s ) foreach ( (array) ( $s['attendees'] ?? [] ) as $a ) if ( ! empty( $a['email'] ) ) $out[] = $a['email'];
    return $out;
}

// ---------------------------------------------------------------------------
section( 'A. Poll votes must name real times (#9)' );

make_event( 970, 'poll', 'Retreat' );
save_event( 970, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => [ $slot( '09:00' ), $slot( '12:00' ) ] ] );
$k9 = key_at( 970, '09:00' ); $k12 = key_at( 970, '12:00' );
$ann = vote_as( 970, 'ann@example.com', [ $k9 ] );
ok( $ann && $ann->ok, 'normal vote accepted' );

$GLOBALS['_mail'] = [];
$r = vote_as( 970, 'zed@example.com', [ 'slot_madeup' ] );
ok( $r && ! $r->ok && str_contains( (string) $r->data, 'no longer available' ), 'vote naming only a made-up time is refused' );
ok( ! in_array( 'zed@example.com', all_emails( 970 ), true ), '...nothing stored' );
ok( get_post_meta( 970, '_dcs_token_issued_' . md5( 'zed@example.com' ), true ) === '', '...no edit link issued' );
ok( $GLOBALS['_mail'] === [], '...no confirmation and no "New response" email' );

$r = vote_as( 970, 'ann@example.com', [ 'slot_madeup' ], $ann->data['token'] );
ok( $r && ! $r->ok && emails_at( 970, '09:00' ) === [ 'ann@example.com' ], 'with a valid edit link: made-up time refused, earlier vote kept' );

$r = vote_as( 970, 'ann@example.com', [ $k12, 'slot_madeup' ], $ann->data['token'] );
ok( $r && ! $r->ok && emails_at( 970, '12:00' ) === [], 'real + made-up time: whole submission refused' );

$r = vote_as( 970, 'bo@example.com', [ $k12, $k12, '' ] );
ok( $r && $r->ok && emails_at( 970, '12:00' ) === [ 'bo@example.com' ], 'repeated / blank ids ignored; stored once' );

// ---------------------------------------------------------------------------
section( 'B. One email identity rule for polls (#10)' );

make_event( 971, 'poll', 'Kickoff' );
save_event( 971, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => [ $slot( '09:00' ), $slot( '12:00' ) ] ] );
$k9 = key_at( 971, '09:00' ); $k12 = key_at( 971, '12:00' );

$first = vote_as( 971, 'ann.smith@gmail.com', [ $k9 ] );
ok( $first && $first->ok, 'first vote as ann.smith@gmail.com' );
ok( isset( $GLOBALS['_transients'][ 'dcs_rl_' . md5( 'ev_971_annsmith@gmail.com' ) ] ), 'per-address rate limit keyed on the normalised address' );
ok( isset( $GLOBALS['_transients'][ 'dcs_poll_conf_971_' . md5( 'annsmith@gmail.com' ) ] ), 'confirmation throttle keyed on the normalised address' );

$r = vote_as( 971, 'annsmith+work@gmail.com', [ $k12 ] );
ok( $r && ! $r->ok, 'Gmail variant without the edit link: refused (already was)' );

// Same person, different spelling, holding their edit link.
$r = vote_as( 971, 'AnnSmith@Gmail.com', [ $k12 ], $first->data['token'] );
ok( $r && $r->ok, 'edit link accepted for a Gmail variant of the same address' );
ok( emails_at( 971, '09:00' ) === [] && emails_at( 971, '12:00' ) === [ 'AnnSmith@Gmail.com' ], 'earlier picks replaced, not kept alongside (no duplicate voter)' );
ok( count( all_emails( 971 ) ) === 1, 'one row for the person in total' );

// The original link (ann.smith@…) still finds her picks on the page.
$page = front( 971, $first->data['token'] );
ok( (bool) preg_match( '#value="' . preg_quote( esc_attr( $k12 ), '#' ) . '" checked#', $page ), 'edit link pre-ticks her picks though the stored spelling differs' );
ok( str_contains( $page, 'value="Voter"' ), '...and pre-fills her name' );

save_event( 971, $CLOSE + [ 'dcs_selected_slot' => $k12 ] );
$page = front( 971, $first->data['token'] );
ok( str_contains( $page, 'Hi <strong>Voter</strong>' ) && str_contains( $page, 'dcs-vote-winner' ), 'closed poll: personal summary found by normalised email' );

// ---------------------------------------------------------------------------
section( 'C. Deleting the last slot (#8)' );

make_event( 980, 'booking', 'Cancelled' );
save_event( 980, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'slots' => [
    $slot( '09:00', 1 ) + [ 'details' => [ 'format' => 'online', 'url' => 'https://zoom.us/j/1', 'location' => '', 'meeting_id' => '', 'passcode' => 'p', 'dial_in' => '', 'notes' => '' ] ],
    $slot( '10:00', 1 ) ] ] );
ok( count( slots( 980 ) ) === 2 && get_post_meta( 980, '_meeting_details', true )['slots'], 'two slots and a slot override to start with' );

$box = render( fn() => DCS_Admin::render_slots_box( get_post( 980 ) ) );
ok( (bool) preg_match( '#name="dcs_slots_present" value="1">\s*<div id="dcs-slot-wrapper">#', $box ), 'marker rendered outside the slot list' );

// What the browser posts once every row has been removed: marker, no slots.
$before = slots( 980 );
save_event( 980, [ 'dcs_meeting_mode' => 'booking', 'dcs_slots_present' => '1' ] );
ok( slots( 980 ) === $before, 'marker without the edit-screen nonce: slots untouched' );
save_event( 980, [ 'dcs_meeting_mode' => 'booking' ] + $NONCE );
ok( slots( 980 ) === $before, 'no marker (save from elsewhere): slots untouched' );

save_event( 980, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'dcs_slots_present' => '1' ] );
ok( slots( 980 ) === [], 'all slots removed in the box: saved as no slots' );
ok( empty( get_post_meta( 980, '_meeting_details', true )['slots'] ?? [] ), 'their detail overrides (passcode) removed too' );
ok( str_contains( front( 980 ), 'No time slots are available yet.' ), 'public page says there are no times' );

save_event( 980, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'dcs_slots_present' => '1', 'slots' => [ $slot( '11:00', 1 ) ] ] );
ok( count( slots( 980 ) ) === 1, 'adding a slot again works' );

finish();
