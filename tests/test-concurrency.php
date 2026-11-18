<?php
/**
 * Lost-update protection (2.4.3):
 *  - every writer of _meeting_slots takes the same per-event lock;
 *  - an admin save from an edit page that was opened before people responded
 *    keeps those responses (three-way merge against the loaded state).
 * Run from the repo root:  php tests/test-concurrency.php
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
function emails_at( $id, $time ) {
    $e = array_column( slot_at( $id, $time )['attendees'] ?? [], 'email' );
    sort( $e );
    return $e;
}
function key_at( $id, $time ) { return dcs_slot_key( slot_at( $id, $time ) ); }
function lock_of( $id ) { return 'dcs_' . md5( 'event_' . $id ); }

/**
 * The Meeting Slots box as the browser would submit it: renders the meta box
 * and turns its inputs / selects / textareas back into a $_POST['slots'] array.
 */
function admin_form( $id ) {
    $html  = render( fn() => DCS_Admin::render_slots_box( get_post( $id ) ) );
    $html  = preg_replace( '#<script type="text/template".*?</script>#s', '', $html );
    $hd    = fn( $s ) => html_entity_decode( $s, ENT_QUOTES, 'UTF-8' );
    $pairs = [];
    preg_match_all( '#<input[^>]*>#', $html, $m );
    foreach ( $m[0] as $tag ) {
        if ( ! preg_match( '#name="([^"]*)"#', $tag, $n ) ) continue;
        $v = preg_match( '#value="([^"]*)"#', $tag, $vm ) ? $vm[1] : '';
        $pairs[] = urlencode( $hd( $n[1] ) ) . '=' . urlencode( $hd( $v ) );
    }
    preg_match_all( '#<select[^>]*name="([^"]*)"[^>]*>(.*?)</select>#s', $html, $m, PREG_SET_ORDER );
    foreach ( $m as $s ) {
        $v = preg_match( '#<option value="([^"]*)"[^>]*selected#', $s[2], $o ) ? $o[1]
            : ( preg_match( '#<option value="([^"]*)"#', $s[2], $o ) ? $o[1] : '' );
        $pairs[] = urlencode( $hd( $s[1] ) ) . '=' . urlencode( $hd( $v ) );
    }
    preg_match_all( '#<textarea[^>]*name="([^"]*)"[^>]*>(.*?)</textarea>#s', $html, $m, PREG_SET_ORDER );
    foreach ( $m as $t ) $pairs[] = urlencode( $hd( $t[1] ) ) . '=' . urlencode( $hd( $t[2] ) );
    parse_str( implode( '&', $pairs ), $out );
    return $out['slots'];
}
function vote( $id, $who, array $times, string $token = '' ) {
    return ajax( [ 'event_id' => $id, 'name' => ucfirst( $who ), 'email' => "$who@example.com",
        'slot_ids' => array_map( fn( $t ) => key_at( $id, $t ), $times ) ]
        + ( $token !== '' ? [ 'dcs_edit_token' => $token ] : [] ) );
}
function book( $id, $who, $time ) {
    return ajax( [ 'event_id' => $id, 'name' => ucfirst( $who ), 'email' => "$who@example.com", 'slot_id' => key_at( $id, $time ) ] );
}

// ---------------------------------------------------------------------------
section( 'A. One lock per event for every writer' );

make_event( 800, 'booking', '1-on-1s' );
save_event( 800, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'slots' => [ $slot( '09:00', 1 ), $slot( '10:00', 1 ) ] ] );
make_event( 801, 'poll', 'Poll' );
save_event( 801, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => [ $slot( '09:00' ), $slot( '12:00' ) ] ] );
make_event( 802, 'sessions', 'Sessions' );
save_event( 802, $NONCE + [ 'dcs_meeting_mode' => 'sessions', 'slots' => [ $slot( '09:00', 5 ) ] ] );

$GLOBALS['_locks'] = [];
book( 800, 'amy', '09:00' );
book( 800, 'ben', '10:00' );
ok( $GLOBALS['_locks'] === [ lock_of( 800 ), lock_of( 800 ) ], 'bookings for different 1-on-1 slots take the same event lock' );
ok( emails_at( 800, '09:00' ) === [ 'amy@example.com' ] && emails_at( 800, '10:00' ) === [ 'ben@example.com' ], 'both bookings stored' );

$GLOBALS['_locks'] = [];
vote( 801, 'cat', [ '09:00' ] );
ok( $GLOBALS['_locks'] === [ lock_of( 801 ) ], 'poll vote takes the event lock' );

$GLOBALS['_locks'] = [];
ajax( [ 'event_id' => 802, 'name' => 'Dan', 'email' => 'dan@example.com', 'part_slots' => [ key_at( 802, '09:00' ) ] ] );
ok( $GLOBALS['_locks'] === [ lock_of( 802 ) ], 'Sessions sign-up takes the event lock' );

$GLOBALS['_locks'] = [];
save_event( 801, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => admin_form( 801 ) ] );
ok( $GLOBALS['_locks'] && count( array_unique( $GLOBALS['_locks'] ) ) === 1 && $GLOBALS['_locks'][0] === lock_of( 801 ), 'admin save chain uses only the event lock' );

update_option( 'dcs_db_version', '0' );
$GLOBALS['_locks'] = [];
dcs_maybe_upgrade();
ok( in_array( lock_of( 801 ), $GLOBALS['_locks'], true ) && ! in_array( 'dcs_' . md5( 'poll_801' ), $GLOBALS['_locks'], true ), '2.3.4 upgrade step takes the event lock' );
update_option( 'dcs_db_version', '2.4.0' );

// ---------------------------------------------------------------------------
section( 'B. Rendered form carries the loaded state' );

$html = render( fn() => DCS_Admin::render_slots_box( get_post( 801 ) ) );
ok( str_contains( $html, 'name="slots[0][loaded_id]" value="' . esc_attr( key_at( 801, '09:00' ) ) . '"' ), 'hidden loaded_id per slot' );
ok( str_contains( $html, 'name="slots[0][loaded_att]" value="cat@example.com"' ), 'hidden loaded_att lists who was there' );

// ---------------------------------------------------------------------------
section( 'C. Stale admin form vs. responses made while it was open' );

make_event( 810, 'poll', 'Planning' );
save_event( 810, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => [ $slot( '09:00' ), $slot( '12:00' ) ] ] );
$bob = vote( 810, 'bob', [ '09:00' ] );

$form = admin_form( 810 );                 // organiser opens the edit page
vote( 810, 'ann', [ '09:00', '12:00' ] );  // ann responds while it is open
vote( 810, 'bob', [ '12:00' ], $bob->data['token'] ); // bob moves 9 → 12
save_event( 810, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => $form ] );

ok( emails_at( 810, '09:00' ) === [ 'ann@example.com' ], 'new response kept; voter who moved away is not put back' );
ok( emails_at( 810, '12:00' ) === [ 'ann@example.com', 'bob@example.com' ], 'other slot keeps both current voters' );
ok( count( slot_at( 810, '09:00' )['attendees'] ) === 1, 'no duplicate rows' );

// The organiser's own edits still win.
$form = admin_form( 810 );
foreach ( $form[0]['attendees'] as $j => $a ) if ( $a['email'] === 'ann@example.com' ) unset( $form[0]['attendees'][ $j ] );
$form[1]['attendees'][] = [ 'name' => 'Hand Added', 'email' => 'hand@example.com' ];
vote( 810, 'cy', [ '09:00' ] );            // and someone else responds meanwhile
save_event( 810, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => $form ] );
ok( emails_at( 810, '09:00' ) === [ 'cy@example.com' ], 'organiser removal sticks; concurrent response kept' );
ok( in_array( 'hand@example.com', emails_at( 810, '12:00' ), true ), 'organiser hand-added attendee saved' );

// Closing the poll from a stale page.
$form = admin_form( 810 );
vote( 810, 'dot', [ '09:00' ] );
save_event( 810, $NONCE + $CLOSE + [ 'dcs_meeting_mode' => 'poll', 'slots' => $form, 'dcs_selected_slot_index' => '0' ] );
ok( dcs_event_is_closed( 810 ), 'poll closed' );
ok( in_array( 'dot@example.com', emails_at( 810, '09:00' ), true ), 'vote cast while the close page was open survives the close' );
ok( in_array( 'dot@example.com', get_post_meta( 810, '_poll_selected_slot_snapshot', true )['attendees'] ? array_column( get_post_meta( 810, '_poll_selected_slot_snapshot', true )['attendees'], 'email' ) : [], true ), 'winner snapshot includes that vote' );

// 1-on-1: booking made while the edit page is open.
make_event( 811, 'booking', 'Office hours' );
save_event( 811, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'slots' => [ $slot( '09:00', 1 ), $slot( '10:00', 1 ) ] ] );
$form = admin_form( 811 );
book( 811, 'eve', '10:00' );
$form[0]['duration_minutes'] = '90'; // organiser changes something unrelated
save_event( 811, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'slots' => $form ] );
ok( emails_at( 811, '10:00' ) === [ 'eve@example.com' ], '1-on-1 booking made while the page was open is kept' );
ok( intval( slot_at( 811, '09:00' )['duration_minutes'] ) === 90, 'organiser edit applied too' );

// A slot whose time the organiser changes keeps responses made meanwhile.
$form = admin_form( 811 );
book( 811, 'fay', '09:00' );
$form[0]['time'] = '08:30';
save_event( 811, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'slots' => $form ] );
ok( emails_at( 811, '08:30' ) === [ 'fay@example.com' ], 'retimed slot keeps a booking made while the page was open' );

// Rows added in the browser have no loaded state: saved as typed.
$form   = admin_form( 811 );
$form[] = $slot( '15:00', 2 ) + [ 'attendees' => [] ];
$form[2]['attendees'] = [ [ 'name' => 'Gus', 'email' => 'gus@example.com' ] ];
save_event( 811, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'slots' => $form ] );
ok( emails_at( 811, '15:00' ) === [ 'gus@example.com' ], 'new browser-added slot saved as typed' );

// ---------------------------------------------------------------------------
section( 'D. Lock busy' );

$before = slots( 811 );
$form   = admin_form( 811 );
$form[0]['duration_minutes'] = '120';
$GLOBALS['_lock_busy'] = true;
$died = false;
try { save_event( 811, $NONCE + [ 'dcs_meeting_mode' => 'booking', 'slots' => $form ] ); }
catch ( WpDie $e ) { $died = str_contains( $e->getMessage(), 'Update again' ); }
$_POST = [];
ok( $died, 'admin save stops with a "try again" message instead of writing unlocked' );
ok( slots( 811 ) === $before, 'slots untouched' );

$r = book( 811, 'hal', '10:00' );
ok( $r && ! $r->ok && str_contains( (string) $r->data, 'busy' ), 'public booking reports busy instead of writing unlocked' );
$GLOBALS['_lock_busy'] = false;

finish();
