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
$form[] = $slot( '15:00', 2 ) + [ 'loaded_id' => '' ];
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

// ---------------------------------------------------------------------------
section( 'E. Slot identity comes from the row, not the start time (2.4.4)' );

// Sessions with two parts run in parallel at the same time (two rooms).
$P = [ 'dcs_parts_present' => '1', 'parts' => [
    [ 'id' => 'part_room1aaaa', 'title' => 'Room 1' ],
    [ 'id' => 'part_room2bbbb', 'title' => 'Room 2' ],
] ];
$room = fn( $part, $time = '09:00' ) => $slot( $time, 5 ) + [ 'part' => $part ];
make_event( 820, 'sessions', 'Parallel rooms' );
save_event( 820, $NONCE + $P + [ 'dcs_meeting_mode' => 'sessions', 'dcs_sessions_attendance' => 'any',
    'slots' => [ $room( 'part_room1aaaa' ), $room( 'part_room2bbbb' ) ] ] );
function by_part( $id, $part ) {
    foreach ( slots( $id ) as $s ) if ( ( $s['part'] ?? '' ) === $part ) return $s;
    return null;
}
$r1 = by_part( 820, 'part_room1aaaa' )['id'];
$r2 = by_part( 820, 'part_room2bbbb' )['id'];
ok( $r1 !== '' && $r2 !== '' && $r1 !== $r2, 'two new slots at the same time get different ids' );

ajax( [ 'event_id' => 820, 'name' => 'Ann', 'email' => 'ann@example.com', 'part_slots' => [ $r1 ] ] );
ajax( [ 'event_id' => 820, 'name' => 'Ben', 'email' => 'ben@example.com', 'part_slots' => [ $r2 ] ] );

$form = admin_form( 820 );
$form[1]['details'] = [ 'format' => 'in_person', 'location' => 'Room 2, east wing', 'url' => '', 'meeting_id' => '', 'passcode' => '', 'dial_in' => '', 'notes' => '' ];
save_event( 820, $NONCE + $P + [ 'dcs_meeting_mode' => 'sessions', 'slots' => $form ] );
ok( by_part( 820, 'part_room1aaaa' )['id'] === $r1 && by_part( 820, 'part_room2bbbb' )['id'] === $r2, 'admin save keeps both same-time slots\' ids (no collision)' );
ok( array_column( by_part( 820, 'part_room1aaaa' )['attendees'], 'email' ) === [ 'ann@example.com' ]
    && array_column( by_part( 820, 'part_room2bbbb' )['attendees'], 'email' ) === [ 'ben@example.com' ], 'each person stays in their own room' );
$md = get_post_meta( 820, '_meeting_details', true );
ok( array_keys( $md['slots'] ) === [ $r2 ], 'override lands on the room it was entered for only' );
ok( dcs_slot_details( 820, by_part( 820, 'part_room1aaaa' ) )['location'] === '', 'other room does not pick up that override' );

// A form rendered before 2.4.4 (no loaded_id): start-time fallback, each id used once.
$legacy = admin_form( 820 );
foreach ( $legacy as &$row ) unset( $row['loaded_id'], $row['loaded_att'] );
unset( $row );
save_event( 820, $NONCE + $P + [ 'dcs_meeting_mode' => 'sessions', 'slots' => $legacy ] );
$ids = array_column( slots( 820 ), 'id' );
ok( $ids === [ $r1, $r2 ], 'legacy form: same-time slots matched by start, each existing id used once' );

// Browser-added row at the same time as an existing slot, posted FIRST.
$form = admin_form( 820 );
$new  = $room( 'part_room1aaaa' ) + [ 'loaded_id' => '' ];
save_event( 820, $NONCE + $P + [ 'dcs_meeting_mode' => 'sessions', 'slots' => array_merge( [ $new ], $form ) ] );
$s = slots( 820 );
ok( count( $s ) === 3 && $s[1]['id'] === $r1 && $s[2]['id'] === $r2, 'existing slots keep their ids even when a new same-time row comes first' );
ok( ! in_array( $s[0]['id'], [ $r1, $r2 ], true ), 'browser-added row gets a fresh id' );
ok( count( array_unique( array_column( $s, 'id' ) ) ) === 3, 'all ids unique' );

// Forged form naming the same slot twice: only the first row gets it.
$form = admin_form( 820 );
$form[2]['loaded_id'] = $form[1]['loaded_id'];
save_event( 820, $NONCE + $P + [ 'dcs_meeting_mode' => 'sessions', 'slots' => $form ] );
ok( count( array_unique( array_column( slots( 820 ), 'id' ) ) ) === 3, 'duplicate loaded_id: second row gets a new id' );

// Changing a slot's time keeps its id (with the hidden field).
make_event( 821, 'poll', 'Retime' );
save_event( 821, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => [ $slot( '09:00' ), $slot( '12:00' ) ] ] );
$noon = key_at( 821, '12:00' );
vote( 821, 'ivy', [ '12:00' ] );
$form = admin_form( 821 );
$form[1]['details'] = [ 'format' => 'online', 'location' => '', 'url' => 'https://zoom.us/j/9', 'meeting_id' => '', 'passcode' => '', 'dial_in' => '', 'notes' => '' ];
save_event( 821, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => $form ] );
$form = admin_form( 821 );
$form[1]['time'] = '13:00';
save_event( 821, $NONCE + $CLOSE + [ 'dcs_meeting_mode' => 'poll', 'slots' => $form, 'dcs_selected_slot_index' => '1' ] );
ok( key_at( 821, '13:00' ) === $noon, 'retimed slot keeps its id' );
ok( emails_at( 821, '13:00' ) === [ 'ivy@example.com' ], 'votes stay with it' );
ok( array_keys( get_post_meta( 821, '_meeting_details', true )['slots'] ) === [ $noon ], 'its override stays with it' );
ok( get_post_meta( 821, '_poll_selected_slot_id', true ) === $noon, 'closing on it records the same id' );

$foot = render( fn() => DCS_Admin::admin_footer_scripts() );
ok( str_contains( $foot, '[loaded_id]" value="">' ), 'browser-added slots carry a blank loaded_id' );

finish();
