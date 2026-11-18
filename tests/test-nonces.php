<?php
/**
 * 2.4.8: every edit-screen save needs the edit nonce (#7); a stale nonce on
 * the public form is reported so script.js can fetch a fresh one (#11).
 * Run from the repo root:  php tests/test-nonces.php
 */
require __DIR__ . '/bootstrap.php';

$D1    = date( 'Y-m-d', strtotime( '+10 days' ) );
$NONCE = [ 'dcs_save_details_nonce' => 'nonce-dcs_save_details_action' ];
$slot  = fn( $time, $max = '' ) => [ 'date' => $D1, 'time' => $time, 'duration_minutes' => '60', 'max' => (string) $max ];
$times = fn( $id ) => array_column( (array) slots( $id ), 'time' );

// ---------------------------------------------------------------------------
section( 'A. Edit-screen saves need the edit nonce (#7)' );

make_event( 990, 'booking', 'Forms' );
save_event( 990, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'dcs_default_duration' => '45', 'slots' => [ $slot( '09:00', 1 ) ] ] );
ok( $times( 990 ) === [ '09:00' ], 'with the nonce: slots saved' );
ok( get_post_meta( 990, '_meeting_default_duration', true ) === 45, '...and default duration saved' );

// A forged POST (no / wrong nonce) from a logged-in organiser's browser.
foreach ( [ 'no nonce' => [], 'wrong nonce' => [ 'dcs_save_details_nonce' => 'forged' ] ] as $label => $n ) {
    save_event( 990, $n + [
        'dcs_meeting_mode'        => 'poll',
        'dcs_sessions_attendance' => 'required',
        'dcs_default_duration'    => '5',
        'dcs_poll_status'         => 'closed',
        'slots'                   => [ $slot( '13:00' ) ],
    ] );
    ok( $times( 990 ) === [ '09:00' ], "$label: slots unchanged" );
    ok( get_post_meta( 990, '_meeting_mode', true ) === 'booking', "$label: meeting type unchanged" );
    ok( get_post_meta( 990, '_sessions_attendance', true ) === '', "$label: attendance setting unchanged" );
    ok( get_post_meta( 990, '_meeting_default_duration', true ) === 45, "$label: default duration unchanged" );
    ok( get_post_meta( 990, '_poll_status', true ) === 'open', "$label: open/closed status unchanged" );
}

save_event( 990, [ 'dcs_slots_present' => '1' ] );
ok( $times( 990 ) === [ '09:00' ], 'emptied slot box without the nonce: slots kept' );

save_event( 990, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => [ $slot( '13:00' ) ] ] );
ok( $times( 990 ) === [ '13:00' ] && get_post_meta( 990, '_meeting_mode', true ) === 'poll', 'with the nonce again: changes go through' );

$GLOBALS['_can'] = false;
save_event( 990, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'slots' => [ $slot( '15:00' ) ] ] );
$GLOBALS['_can'] = true;
ok( $times( 990 ) === [ '13:00' ] && get_post_meta( 990, '_meeting_mode', true ) === 'poll', 'nonce but no permission on the event: nothing changes' );

$html = render( fn() => DCS_Admin::render_edit_nonce( get_post( 990 ) ) );
ok( substr_count( $html, 'name="dcs_save_details_nonce"' ) === 1, 'edit screen prints the nonce once' );
$other = new WP_Post( 991, 'A page' ); $other->post_type = 'page';
ok( render( fn() => DCS_Admin::render_edit_nonce( $other ) ) === '', '...and not on other post types' );

// ---------------------------------------------------------------------------
section( 'B. Stale nonce on the public form (#11)' );

make_event( 992, 'poll', 'Cached' );
save_event( 992, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => [ $slot( '09:00' ) ] ] );
$key  = dcs_slot_key( slots( 992 )[0] );
$vote = [ 'event_id' => 992, 'name' => 'Ann', 'email' => 'ann@example.com', 'slot_ids' => [ $key ] ];

$r = ajax( [ 'dcs_nonce' => 'expired-nonce' ] + $vote );
ok( $r && ! $r->ok && $r->status === 403, 'expired nonce refused with 403' );
ok( is_array( $r->data ) && $r->data['code'] === 'bad_nonce', '...marked bad_nonce so the script can recover' );
ok( is_string( $r->data['message'] ) && str_contains( $r->data['message'], 'refresh the page' ), '...with a readable fallback message' );
ok( array_column( (array) slots( 992 )[0]['attendees'], 'email' ) === [], '...nothing stored' );

$GLOBALS['_nocache'] = false;
$_POST = [ 'action' => 'dcs_fresh_nonce' ];
try { DCS_Ajax::handle_fresh_nonce(); $fresh = null; } catch ( JsonResponse $e ) { $fresh = $e; }
$_POST = [];
ok( $fresh && $fresh->ok && $fresh->data['nonce'] === wp_create_nonce( 'dcs_book_slot' ), 'fresh-nonce endpoint returns a current nonce' );
ok( $GLOBALS['_nocache'] === true, '...sent with no-cache headers' );

$r = ajax( [ 'dcs_nonce' => $fresh->data['nonce'] ] + $vote );
ok( $r && $r->ok && array_column( slots( 992 )[0]['attendees'], 'email' ) === [ 'ann@example.com' ], 'retry with the fresh nonce: vote recorded' );

$js = file_get_contents( dirname( __DIR__ ) . '/script.js' );
ok( str_contains( $js, "d.code === 'bad_nonce' && ! retried" ) && str_contains( $js, "action: 'dcs_fresh_nonce'" ), 'script.js retries once with a fresh nonce' );

finish();
