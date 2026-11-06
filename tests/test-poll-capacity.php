<?php
/**
 * Group poll Max / capacity (2.3.4).
 * Run from the repo root:  php tests/test-poll-capacity.php
 */
require __DIR__ . '/bootstrap.php';

$D1    = date( 'Y-m-d', strtotime( '+10 days' ) );
$NONCE = [ 'dcs_save_details_nonce' => 'nonce-dcs_save_details_action' ];
function slot_by( $id, $time ) {
    foreach ( slots( $id ) as $s ) if ( $s['time'] === $time ) return $s;
    return null;
}
function key_of( $id, $time ) { return dcs_slot_key( slot_by( $id, $time ) ); }
function names_in( $id, $time ) { return array_column( slot_by( $id, $time )['attendees'] ?? [], 'name' ); }
function vote( $id, $who, array $times, string $token = '' ) {
    return ajax( [ 'event_id' => $id, 'name' => ucfirst( $who ), 'email' => "$who@example.com",
        'slot_ids' => array_map( fn( $t ) => key_of( $id, $t ), $times ) ]
        + ( $token !== '' ? [ 'dcs_edit_token' => $token ] : [] ) );
}
$slot = fn( $time, $max ) => [ 'date' => $D1, 'time' => $time, 'duration_minutes' => '60', 'max' => (string) $max, 'attendees' => [ [ 'name' => '', 'email' => '' ] ] ];

make_event( 600, 'poll', 'Team lunch' );
save_event( 600, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => [ $slot( '09:00', '' ), $slot( '12:00', 2 ), $slot( '15:00', 1 ) ] ] );

// ---------------------------------------------------------------------------
section( 'A. Saving' );
ok( slot_by( 600, '09:00' )['max'] === 0 && slot_by( 600, '12:00' )['max'] === 2, 'poll: blank Max saved as 0 (no limit); number kept' );
$foot = render( fn() => DCS_Admin::admin_footer_scripts() );
ok( str_contains( $foot, "|| 'booking') === 'booking' ? '1' : ''" ), 'new poll slots start with a blank Max' );

// ---------------------------------------------------------------------------
section( 'B. Voting against a limit' );
$all_ok = true;
for ( $n = 1; $n <= 15; $n++ ) {
    $r = ajax( [ 'event_id' => 600, 'name' => "P$n", 'email' => "p$n@example.com", 'slot_ids' => [ key_of( 600, '09:00' ) ] ] );
    $all_ok = $all_ok && $r && $r->ok;
}
ok( $all_ok && count( names_in( 600, '09:00' ) ) === 15, '15 yes votes on the no-limit time' );

ok( vote( 600, 'ann', [ '12:00', '15:00' ] )->ok, 'Ann: yes to 12:00 and 15:00 (takes the only 15:00 place)' );
ok( vote( 600, 'ben', [ '12:00' ] )->ok, 'Ben: yes to 12:00 (fills it, 2 of 2)' );
$r = vote( 600, 'cal', [ '09:00', '12:00' ] );
ok( $r && ! $r->ok && str_contains( $r->data, 'is already full' ), 'Cal refused: 12:00 is full' );
ok( ! in_array( 'Cal', names_in( 600, '09:00' ), true ), '...and the whole vote is refused, nothing half-saved' );
$r = vote( 600, 'cal', [ '15:00' ] );
ok( $r && ! $r->ok, 'Cal refused: 15:00 (Max 1) is full' );
ok( vote( 600, 'cal', [ '09:00' ] )->ok, 'Cal: yes to the unlimited time only is fine' );

$r = vote( 600, 'ann', [ '12:00', '15:00' ], dcs_make_edit_token( 600, 'ann@example.com' ) );
ok( $r && $r->ok && $r->data['is_update'], 'Ann edits and keeps both full times she already holds' );
ok( names_in( 600, '12:00' ) === [ 'Ben', 'Ann' ] && names_in( 600, '15:00' ) === [ 'Ann' ], '...no one added twice, limits still respected' );

ok( vote( 600, 'ben', [ '09:00' ], dcs_make_edit_token( 600, 'ben@example.com' ) )->ok, 'Ben edits: drops 12:00' );
ok( vote( 600, 'cal', [ '09:00', '12:00' ], dcs_make_edit_token( 600, 'cal@example.com' ) )->ok, '...which frees a place Cal can now take' );
ok( names_in( 600, '12:00' ) === [ 'Ann', 'Cal' ], '12:00 is Ann + Cal' );

// ---------------------------------------------------------------------------
section( 'C. Public form' );
$html  = front( 600 );
$full  = fn( $t ) => (bool) preg_match( '#<label class="dcs-slot-full"><input type="checkbox" name="slot_ids\[\]" value="' . preg_quote( key_of( 600, $t ), '#' ) . '" disabled>#', $html );
ok( $full( '12:00' ) && $full( '15:00' ), 'full times disabled and marked Full for a new voter' );
ok( ! $full( '09:00' ) && ! preg_match( '#' . preg_quote( key_of( 600, '09:00' ), '#' ) . '"[^>]*disabled#', $html ), 'no-limit time never full' );
ok( substr_count( $html, 'dcs-badge--full' ) === 2 && ! str_contains( $html, 'spots left' ), 'no places-left note on unlimited times' );

$html = front( 600, dcs_make_edit_token( 600, 'ann@example.com' ) );
ok( (bool) preg_match( '#<label><input type="checkbox" name="slot_ids\[\]" value="' . preg_quote( key_of( 600, '15:00' ), '#' ) . '" checked>#', $html ), 'Ann\'s edit link: her full time is ticked and enabled' );

make_event( 601, 'poll', 'Spots' );
save_event( 601, $NONCE + [ 'dcs_meeting_mode' => 'poll', 'slots' => [ $slot( '10:00', 3 ) ] ] );
vote( 601, 'dee', [ '10:00' ] );
ok( str_contains( front( 601 ), '>2 spots left</span>' ), 'limited time shows places left' );

// ---------------------------------------------------------------------------
section( 'D. Admin' );
$results = render( fn() => DCS_Admin::render_results_box( get_post( 600 ) ) );
ok( str_contains( $results, '>17</span>' ) && str_contains( $results, '>2 / 2</span>' ) && str_contains( $results, '>1 / 1</span>' ), 'results box: plain count when unlimited, "votes / max" when limited' );
$close = render( fn() => DCS_Admin::render_close_poll_box( get_post( 600 ) ) );
ok( str_contains( $close, '<td>2 / 2</td>' ) && str_contains( $close, '<td>17</td>' ), 'close box: same' );
$box = render( fn() => DCS_Admin::render_slots_box( get_post( 600 ) ) );
ok( str_contains( $box, 'name="slots[0][max]" value="" min="1"' ) && str_contains( $box, 'name="slots[1][max]" value="2" min="1"' ), 'slots box shows blank / number' );

// ---------------------------------------------------------------------------
section( 'E. Upgrade from 2.3.3' );
$GLOBALS['_opts'] = [];
make_event( 700, 'poll', 'Old poll' );
update_post_meta( 700, '_meeting_slots', [
    [ 'id' => 'slot_a', 'date' => $D1, 'time' => '09:00', 'max' => 1, 'attendees' => [ [ 'name' => 'X', 'email' => 'x@example.com' ], [ 'name' => 'Y', 'email' => 'y@example.com' ] ] ],
    [ 'id' => 'slot_b', 'date' => $D1, 'time' => '10:00', 'attendees' => [] ], // pre-Max slot
] );
make_event( 701, 'booking', 'Old booking' );
update_post_meta( 701, '_meeting_slots', [ [ 'id' => 'slot_c', 'date' => $D1, 'time' => '09:00', 'max' => 1, 'attendees' => [] ] ] );
make_event( 702, 'sessions', 'Old sessions' );
update_post_meta( 702, '_meeting_slots', [ [ 'id' => 'slot_d', 'date' => $D1, 'time' => '09:00', 'max' => 4, 'attendees' => [] ] ] );

dcs_maybe_upgrade();
ok( dcs_slot_capacity( slots( 700 )[0] ) === null && dcs_slot_capacity( slots( 700 )[1] ) === null, 'old poll slots (Max 1, and no Max at all) become unlimited' );
ok( count( slots( 700 )[0]['attendees'] ) === 2, '...votes kept' );
ok( slots( 701 )[0]['max'] === 1 && slots( 702 )[0]['max'] === 4, '1-on-1 and Sessions Max untouched' );
ok( get_option( 'dcs_db_version' ) === '2.4.0', 'upgrade recorded (through 2.4.0)' );
$s = slots( 700 ); $s[0]['max'] = 1; update_post_meta( 700, '_meeting_slots', $s );
dcs_maybe_upgrade();
ok( slots( 700 )[0]['max'] === 1, 'runs only once: a Max set after upgrading is kept' );

finish();
