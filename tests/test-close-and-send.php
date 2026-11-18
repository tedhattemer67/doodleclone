<?php
/**
 * 2.4.6: closing a poll picks the winner by slot id, not row position (#4);
 * final-details sends record progress per email and can't run twice at once (#6).
 * Run from the repo root:  php tests/test-close-and-send.php
 */
require __DIR__ . '/bootstrap.php';

$D1    = date( 'Y-m-d', strtotime( '+10 days' ) );
$NONCE = [ 'dcs_save_details_nonce' => 'nonce-dcs_save_details_action' ];
$CLOSE = [ 'dcs_close_poll' => '1', 'dcs_close_poll_nonce' => 'nonce-dcs_close_poll_action' ];
$SEND  = fn( $mode ) => [ 'dcs_send_announce' => $mode, 'dcs_send_announce_nonce' => 'nonce-dcs_send_announce_action' ];
$slot  = fn( $time, $max = '', $att = [] ) => [ 'date' => $D1, 'time' => $time, 'duration_minutes' => '60', 'max' => (string) $max,
    'attendees' => $att ?: [ [ 'name' => '', 'email' => '' ] ] ];

function slot_at( $id, $time ) {
    foreach ( slots( $id ) as $s ) if ( $s['time'] === $time ) return $s;
    return null;
}
function key_at( $id, $time ) { return dcs_slot_key( slot_at( $id, $time ) ); }
function winner_time( $id ) { return get_post_meta( $id, '_poll_selected_slot_snapshot', true )['time'] ?? null; }
function close_box( $id ) { return render( fn() => DCS_Admin::render_close_poll_box( get_post( $id ) ) ); }
/** The whole edit screen as posted: slots box + close box (with its checked radio). */
function edit_screen( $id ) {
    return [ 'slots' => admin_form( $id ) ] + form_post( close_box( $id ) );
}
function notice_types() { return array_column( notices(), 'type' ); }
function notice_text() { return implode( ' | ', array_column( notices(), 'message' ) ); }

// ---------------------------------------------------------------------------
section( 'A. Close poll: winner chosen by slot id (#4)' );

make_event( 950, 'poll', 'Offsite' );
save_event( 950, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => [ $slot( '09:00' ), $slot( '12:00' ), $slot( '15:00' ) ] ] );

$box = close_box( 950 );
ok( str_contains( $box, 'name="dcs_selected_slot" value="' . esc_attr( key_at( 950, '12:00' ) ) . '"' ), 'close-box radios carry the slot id' );
ok( ! str_contains( $box, 'dcs_selected_slot_index' ), 'no row-position field any more' );

// Remove the 09:00 row and close on 12:00 in the same Update.
$post = edit_screen( 950 );
array_splice( $post['slots'], 0, 1 );
// The organiser clicks the 12:00 row (2nd row on the page). Set what either
// version of the page would post for that click; the id wins when present.
$post['dcs_selected_slot']       = key_at( 950, '12:00' );
$post['dcs_selected_slot_index'] = '1';
$noon = key_at( 950, '12:00' );
save_event( 950, $NONCE + $CLOSE + [ 'dcs_meeting_mode' => 'poll' ] + $post );
ok( dcs_event_is_closed( 950 ) && winner_time( 950 ) === '12:00', 'slot removed in the same save: still closes on the time chosen (12:00, not 15:00)' );
ok( get_post_meta( 950, '_poll_selected_slot_id', true ) === $noon, 'chosen slot id recorded' );

// After close, add an earlier slot (shifts every row position).
$post = edit_screen( 950 );
$post['slots'] = array_merge( [ $slot( '08:00' ) + [ 'loaded_id' => '' ] ], $post['slots'] );
save_event( 950, $NONCE + [ 'dcs_meeting_mode' => 'poll' ] + $post );
$box = close_box( 950 );
ok( (bool) preg_match( '#value="' . preg_quote( esc_attr( $noon ), '#' ) . '" checked#', $box ), 'close box still ticks the winner after rows shift' );
$res = render( fn() => DCS_Admin::render_results_box( get_post( 950 ) ) );
ok( substr_count( $res, 'class="dcs-winner"' ) === 1 && (bool) preg_match( '#class="dcs-winner"><td>[^<]*12:00pm#', $res ), 'results table highlights the winner, not whatever row is at its old position' );

// Reopen, then pick a time that is removed in the same save.
save_event( 950, [ 'dcs_reopen_poll' => '1', 'dcs_close_poll_nonce' => 'nonce-dcs_close_poll_action' ] );
$res = render( fn() => DCS_Admin::render_results_box( get_post( 950 ) ) );
ok( ! str_contains( $res, 'class="dcs-winner"' ), 'open poll: no row highlighted as winner (was always row 1)' );
clear_notices();
$post = edit_screen( 950 );
$post['dcs_selected_slot'] = key_at( 950, '15:00' );
$post['slots'] = array_values( array_filter( $post['slots'], fn( $s ) => $s['time'] !== '15:00' ) );
save_event( 950, $NONCE + $CLOSE + [ 'dcs_meeting_mode' => 'poll' ] + $post );
ok( ! dcs_event_is_closed( 950 ), 'chosen time removed in the same save: poll stays open' );
ok( notice_types() === [ 'error' ] && str_contains( notice_text(), 'no longer exists' ), '...and the organiser is told why' );

clear_notices();
$post = edit_screen( 950 );
unset( $post['dcs_selected_slot'] );
save_event( 950, $NONCE + $CLOSE + [ 'dcs_meeting_mode' => 'poll' ] + $post );
ok( ! dcs_event_is_closed( 950 ) && notice_types() === [ 'warning' ], 'no time chosen: stays open, with a warning' );

// An edit page opened before 2.4.6 still posts the row position.
clear_notices();
save_event( 950, $NONCE + $CLOSE + [ 'dcs_meeting_mode' => 'poll', 'dcs_selected_slot_index' => '1' ] );
ok( dcs_event_is_closed( 950 ) && winner_time( 950 ) === '12:00' && notices() === [], 'legacy dcs_selected_slot_index still closes' );

// ---------------------------------------------------------------------------
section( 'B. Final details: progress kept if the request dies (#6)' );

$who = fn( $n ) => [ [ 'name' => ucfirst( $n ), 'email' => "$n@example.com" ] ];
make_event( 960, 'booking', 'Interviews' );
save_event( 960, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'slots' => [
    $slot( '09:00', 1, $who( 'ann' ) ), $slot( '10:00', 1, $who( 'ben' ) ),
    $slot( '11:00', 1, $who( 'cy' ) ),  $slot( '12:00', 1, $who( 'dee' ) ) ] ] );
save_event( 960, $CLOSE );
ok( dcs_event_is_closed( 960 ), '1-on-1 registration closed' );

$GLOBALS['_mail'] = [];
$GLOBALS['_locks'] = [];
$GLOBALS['_mail_budget'] = 2; // two emails go out, then the request dies
$died = false;
try { save_event( 960, $SEND( 'all' ) ); } catch ( RequestDied $e ) { $died = true; }
unset( $GLOBALS['_mail_budget'] );
$_POST = [];
ok( $died && count( $GLOBALS['_mail'] ) === 2, 'simulated timeout after two emails' );
ok( in_array( 'dcs_' . md5( 'final_details_960' ), $GLOBALS['_locks'], true ), 'send runs under its own per-event send lock' );
ok( count( (array) get_post_meta( 960, '_poll_announce_recipient_hashes', true ) ) === 2, 'the two emailed people are already recorded' );
ok( (bool) get_post_meta( 960, '_poll_announce_sent_at', true ), '"Last sent" recorded too' );
ok( str_contains( close_box( 960 ), 'name="dcs_send_announce" value="new"' ), '"Send only to people not yet emailed" is offered' );

$GLOBALS['_mail'] = [];
save_event( 960, $SEND( 'new' ) );
$to = array_column( array_filter( $GLOBALS['_mail'], fn( $m ) => $m['to'] !== 'admin@example.com' ), 'to' );
sort( $to );
ok( $to === [ 'cy@example.com', 'dee@example.com' ], 'retry with "not yet emailed" sends only to the rest' );

// ---------------------------------------------------------------------------
section( 'C. Final details: failed emails reported and retried' );

make_event( 961, 'booking', 'Interviews II' );
save_event( 961, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'slots' => [
    $slot( '09:00', 1, $who( 'ann' ) ), $slot( '10:00', 1, $who( 'ben' ) ), $slot( '11:00', 1, $who( 'cy' ) ) ] ] );
save_event( 961, $CLOSE );
clear_notices();
$GLOBALS['_mail'] = [];
$GLOBALS['_mail_fail'] = [ 'ben@example.com' ];
save_event( 961, $SEND( 'all' ) );
unset( $GLOBALS['_mail_fail'] );
ok( notice_types() === [ 'error' ] && str_contains( notice_text(), 'sent to 2 recipient(s); 1 could not be sent' ), 'organiser told 2 sent, 1 failed' );

clear_notices();
$GLOBALS['_mail'] = [];
save_event( 961, $SEND( 'new' ) );
$to = array_column( array_filter( $GLOBALS['_mail'], fn( $m ) => $m['to'] !== 'admin@example.com' ), 'to' );
ok( $to === [ 'ben@example.com' ], 'retry reaches only the one that failed' );
ok( notice_types() === [ 'success' ] && str_contains( notice_text(), 'Final details sent to 1 recipient.' ), 'success notice after the retry' );

// ---------------------------------------------------------------------------
section( 'D. Final details: a second send while one is running' );

clear_notices();
$GLOBALS['_mail'] = [];
$seq = get_post_meta( 960, '_dcs_final_sequence', true );
$GLOBALS['_lock_busy'] = true;
save_event( 960, $SEND( 'all' ) );
$GLOBALS['_lock_busy'] = false;
ok( $GLOBALS['_mail'] === [], 'nothing sent while another send holds the lock' );
ok( get_post_meta( 960, '_dcs_final_sequence', true ) === $seq, 'calendar sequence not bumped' );
ok( notice_types() === [ 'warning' ] && str_contains( notice_text(), 'already being sent' ), 'organiser told a send is already running' );

ok( DCS_Mailer::send_poll_announcement( 960 ) === 4, 'send_poll_announcement() wrapper still returns the count' );

finish();
