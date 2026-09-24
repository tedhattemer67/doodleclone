<?php
/**
 * Sessions / series meeting type (2.3.0).
 * Run from the repo root:  php tests/test-sessions.php
 */
require __DIR__ . '/bootstrap.php';

$D1   = date( 'Y-m-d', strtotime( '+10 days' ) );
$D2   = date( 'Y-m-d', strtotime( '+17 days' ) );
$D3   = date( 'Y-m-d', strtotime( '+24 days' ) );
$NONCE = [ 'dcs_save_details_nonce' => 'nonce-dcs_save_details_action', 'dcs_parts_present' => '1' ];
$SECRETS = [ 'zoom.us/j/555', 'zoom-pass', 'Z-ID', 'teams.microsoft.com', 'p2-pass', 'p1pm-pass', '555 0000' ];
function leaks( string $html ): array { global $SECRETS; return array_values( array_filter( $SECRETS, fn( $s ) => str_contains( $html, $s ) ) ); }
function slot_by( $id, $date, $time ) {
    foreach ( slots( $id ) as $s ) if ( $s['date'] === $date && $s['time'] === $time ) return $s;
    return null;
}
function key_of( $id, $date, $time ) { return dcs_slot_key( slot_by( $id, $date, $time ) ); }
function names_in( $id, $date, $time ) { return array_column( slot_by( $id, $date, $time )['attendees'] ?? [], 'name' ); }
function token_for( $id, $email ) { return dcs_make_edit_token( $id, $email ); }
/** Splits a final-details email body into its per-part blocks, keyed by heading. */
function blocks( string $body ): array {
    $out = [];
    foreach ( preg_split( '#<h3[^>]*>#', $body ) as $i => $chunk ) {
        if ( $i === 0 ) continue;
        [ $head, $rest ] = explode( '</h3>', $chunk, 2 );
        $out[ html_entity_decode( $head, ENT_QUOTES ) ] = $rest;
    }
    return $out;
}

$empty_details = [ 'format' => '', 'location' => '', 'url' => '', 'meeting_id' => '', 'passcode' => '', 'dial_in' => '', 'notes' => '' ];
$slot = fn( $date, $time, $max, $part, $details = null ) => [
    'date' => $date, 'time' => $time, 'duration_minutes' => '60', 'max' => (string) $max, 'part' => $part,
    'attendees' => [ [ 'name' => '', 'email' => '' ] ], 'details' => $details ?? $empty_details,
];

$base = $NONCE + [
    'dcs_meeting_mode'        => 'sessions',
    'dcs_sessions_attendance' => 'recommended',
    'dcs_details' => [ 'format' => 'online', 'url' => 'https://zoom.us/j/555', 'meeting_id' => 'Z-ID', 'passcode' => 'zoom-pass', 'dial_in' => "+1 555 0000", 'location' => '', 'notes' => '' ],
    'parts' => [
        [ 'id' => 'part_aaaa1111', 'title' => 'Part 1: Kickoff', 'details' => $empty_details ],
        [ 'id' => 'part_bbbb2222', 'title' => 'Part 2: Deep dive',
          'details' => [ 'format' => 'online', 'url' => 'https://teams.microsoft.com/l/p2', 'passcode' => 'p2-pass' ] + $empty_details ],
        [ 'id' => 'part_cccc3333', 'title' => '', 'details' => $empty_details ],
    ],
    'slots' => [
        $slot( $D1, '09:00', 2, 'part_aaaa1111' ),
        $slot( $D1, '14:00', 2, 'part_aaaa1111', [ 'passcode' => 'p1pm-pass' ] + $empty_details ),
        $slot( $D2, '09:00', 2, 'part_bbbb2222' ),
        $slot( $D2, '14:00', 1, 'part_bbbb2222' ),
        $slot( $D3, '09:00', 2, 'part_cccc3333' ),
    ],
];

// ---------------------------------------------------------------------------
section( 'A. Admin: saving parts, slots and details' );
make_event( 500, 'booking', 'Leadership Series' );
save_event( 500, $base );
$parts = dcs_get_parts( 500 );
ok( get_post_meta( 500, '_meeting_mode', true ) === 'sessions', 'mode saved as sessions' );
ok( dcs_sessions_attendance( 500 ) === 'recommended', 'attendance saved' );
ok( array_column( $parts, 'id' ) === [ 'part_aaaa1111', 'part_bbbb2222', 'part_cccc3333' ], 'parts saved in order with browser-made ids kept' );
ok( dcs_part_label( $parts[2], 2 ) === 'Part 3', 'untitled part displays as "Part 3"' );
ok( slot_by( 500, $D2, '14:00' )['part'] === 'part_bbbb2222', 'slot remembers its part' );

$p1am = dcs_slot_details( 500, slot_by( 500, $D1, '09:00' ) );
$p1pm = dcs_slot_details( 500, slot_by( 500, $D1, '14:00' ) );
$p2   = dcs_slot_details( 500, slot_by( 500, $D2, '09:00' ) );
$p3   = dcs_slot_details( 500, slot_by( 500, $D3, '09:00' ) );
ok( $p1am['url'] === 'https://zoom.us/j/555' && $p1am['passcode'] === 'zoom-pass', 'part without override uses event default' );
ok( $p1pm['url'] === 'https://zoom.us/j/555' && $p1pm['meeting_id'] === 'Z-ID' && $p1pm['passcode'] === 'p1pm-pass', 'slot passcode-only override keeps Zoom link + ID' );
ok( $p2['url'] === 'https://teams.microsoft.com/l/p2' && $p2['passcode'] === 'p2-pass', 'part override (Teams) applies to its slots' );
ok( $p2['meeting_id'] === '' && $p2['dial_in'] === '', 'Teams part does not inherit Zoom meeting ID / dial-in' );
ok( $p3['url'] === 'https://zoom.us/j/555', 'third part falls back to event default' );

$bad = $base;
$bad['parts'][0]['id'] = 'evil"><script>';
$bad['slots'][0]['part'] = 'part_nonexistent';
make_event( 501, 'sessions', 'Scratch' );
save_event( 501, $bad );
$p501 = dcs_get_parts( 501 );
ok( dcs_valid_part_id( $p501[0]['id'] ) && $p501[0]['id'] !== 'evil"><script>', 'malformed part id replaced with a fresh valid one' );
ok( slot_by( 501, $D1, '09:00' )['part'] === $p501[0]['id'], 'slot pointing at an unknown part falls back to the first part' );

$less = $base; unset( $less['parts'][1] );
save_event( 501, $less );
ok( count( dcs_get_parts( 501 ) ) === 2, 'removing a part saves' );
ok( slot_by( 501, $D2, '09:00' )['part'] === 'part_aaaa1111', 'slots of a removed part move to the first part' );
ok( ! isset( get_post_meta( 501, '_meeting_details', true )['parts']['part_bbbb2222'] ), 'removed part\'s details override pruned' );

$noparts = $base; unset( $noparts['dcs_parts_present'], $noparts['parts'] );
save_event( 500, $noparts );
ok( count( dcs_get_parts( 500 ) ) === 3, 'save without the Parts box posted leaves parts alone' );
save_event( 500, $base );

update_post_meta( 500, '_meeting_mode', 'booking' );
ok( dcs_slot_details( 500, slot_by( 500, $D2, '09:00' ) )['url'] === 'https://zoom.us/j/555', 'switched to 1-on-1: leftover part details ignored' );
update_post_meta( 500, '_meeting_mode', 'sessions' );

// ---------------------------------------------------------------------------
section( 'B. Public form' );
$html = front( 500 );
ok( substr_count( $html, '<fieldset class="dcs-part"' ) === 3, 'one fieldset per part' );
ok( str_contains( $html, 'Part 1: Kickoff' ) && str_contains( $html, 'Part 2: Deep dive' ) && str_contains( $html, '>Part 3<' ), 'part headings shown (untitled -> "Part 3")' );
ok( substr_count( $html, 'Can&#039;t attend this part' ) === 3, '"Can\'t attend" offered for each part (recommended)' );
ok( str_contains( $html, 'Attending every part is recommended' ), 'recommended note shown' );
ok( (bool) preg_match( '#name="part_slots\[2\]" value="' . preg_quote( key_of( 500, $D3, '09:00' ), '#' ) . '" checked#', $html ), 'single-time part starts ticked' );
ok( (bool) preg_match( '#name="part_slots\[0\]" value="" checked#', $html ), 'multi-time part starts on "Can\'t attend" (no silent pick)' );
ok( str_contains( $html, '(2 spots left)' ) && str_contains( $html, '(1 spot left)' ), 'places left shown' );
ok( str_contains( $html, '>Register</button>' ), 'Register button' );
ok( ! leaks( $html ), 'public form leaks no details: ' . implode( ',', leaks( $html ) ) );

// ---------------------------------------------------------------------------
section( 'C. Sign-up, validation and edits' );
$GLOBALS['_mail'] = [];
$r = ajax( [ 'event_id' => 500, 'name' => 'Ann', 'email' => 'ann@example.com',
    'part_slots' => [ key_of( 500, $D1, '09:00' ), key_of( 500, $D2, '09:00' ), '' ] ] );
ok( $r && $r->ok && $r->data['mode'] === 'sessions' && ! empty( $r->data['token'] ), 'Ann registers for parts 1 & 2, skips 3' );
ok( names_in( 500, $D1, '09:00' ) === [ 'Ann' ] && names_in( 500, $D2, '09:00' ) === [ 'Ann' ] && names_in( 500, $D3, '09:00' ) === [], 'Ann placed in exactly her two slots' );
$m = mails_to( 'ann@example.com' );
ok( count( $m ) === 1 && str_contains( $m[0]['body'], 'Part 3: not attending' ) && str_contains( $m[0]['body'], 'Part 1: Kickoff: ' ), 'confirmation lists schedule incl. skipped part' );
ok( str_contains( $m[0]['body'], 'dcs_token=' ), 'confirmation has edit link' );
ok( ! leaks( $m[0]['body'] ) && $m[0]['ics'] === [], 'confirmation has no details and no .ics yet' );
ok( ! leaks( json_encode( $r->data ) ), 'AJAX response leaks nothing' );

$r = ajax( [ 'event_id' => 500, 'name' => 'Ben', 'email' => 'ben@example.com',
    'part_slots' => [ key_of( 500, $D1, '09:00' ), key_of( 500, $D2, '14:00' ), key_of( 500, $D3, '09:00' ) ] ] );
ok( $r && $r->ok, 'Ben registers for all three (takes the last place at part 2, 2pm)' );

$r = ajax( [ 'event_id' => 500, 'name' => 'Cal', 'email' => 'cal@example.com', 'part_slots' => [ key_of( 500, $D2, '14:00' ) ] ] );
ok( $r && ! $r->ok && str_contains( $r->data, 'is already full' ), 'full slot refused' );
$r = ajax( [ 'event_id' => 500, 'name' => 'Cal', 'email' => 'cal@example.com', 'part_slots' => [ key_of( 500, $D1, '09:00' ), key_of( 500, $D1, '14:00' ) ] ] );
ok( $r && ! $r->ok && $r->data === 'Please choose only one time for each part.', 'two times in one part refused' );
$r = ajax( [ 'event_id' => 500, 'name' => 'Cal', 'email' => 'cal@example.com', 'part_slots' => [ '', '', '' ] ] );
ok( $r && ! $r->ok && $r->data === 'Please choose at least one session.', 'nothing picked refused' );
$r = ajax( [ 'event_id' => 500, 'name' => 'Cal', 'email' => 'cal@example.com', 'part_slots' => [ 'slot_forged' ] ] );
ok( $r && ! $r->ok && str_contains( $r->data, 'no longer available' ), 'unknown slot refused' );
ok( names_in( 500, $D1, '14:00' ) === [], 'refused attempts changed nothing' );

$r = ajax( [ 'event_id' => 500, 'name' => 'Mallory', 'email' => 'ann@example.com', 'part_slots' => [ key_of( 500, $D1, '14:00' ) ] ] );
ok( $r && ! $r->ok && str_contains( $r->data, 'edit link' ), 'someone else re-using Ann\'s email without her link is refused' );
ok( names_in( 500, $D1, '09:00' ) === [ 'Ann', 'Ben' ], '...and Ann\'s picks are untouched' );

$GLOBALS['_mail'] = [];
$r = ajax( [ 'event_id' => 500, 'name' => 'Ann', 'email' => 'ann@example.com', 'dcs_edit_token' => token_for( 500, 'ann@example.com' ),
    'part_slots' => [ key_of( 500, $D1, '14:00' ), '', key_of( 500, $D3, '09:00' ) ] ] );
ok( $r && $r->ok && $r->data['is_update'], 'Ann edits with her link: moves to 2pm, drops part 2, adds part 3' );
ok( names_in( 500, $D1, '09:00' ) === [ 'Ben' ] && names_in( 500, $D1, '14:00' ) === [ 'Ann' ] && names_in( 500, $D2, '09:00' ) === [] && in_array( 'Ann', names_in( 500, $D3, '09:00' ), true ), 'old picks replaced, not added to' );
ok( str_contains( mails_to( 'ann@example.com' )[0]['subj'], 'updated' ), 'update email sent' );

$r = ajax( [ 'event_id' => 500, 'name' => 'Ben', 'email' => 'ben@example.com', 'dcs_edit_token' => token_for( 500, 'ben@example.com' ),
    'part_slots' => [ key_of( 500, $D1, '09:00' ), key_of( 500, $D2, '14:00' ), '' ] ] );
ok( $r && $r->ok, 'Ben keeps his place in the full 2pm slot while editing' );
ok( names_in( 500, $D2, '14:00' ) === [ 'Ben' ], '...still exactly one person there' );

$r = ajax( [ 'event_id' => 500, 'name' => 'Dan', 'email' => 'd.an@gmail.com', 'part_slots' => [ key_of( 500, $D1, '14:00' ) ] ] );
ok( $r && $r->ok, 'Dan registers with a Gmail address' );
$r = ajax( [ 'event_id' => 500, 'name' => 'Dan again', 'email' => 'dan+x@gmail.com', 'part_slots' => [ key_of( 500, $D3, '09:00' ) ] ] );
ok( $r && ! $r->ok, 'Gmail dot/+tag variant is the same person (needs the edit link)' );

$html = front( 500, token_for( 500, 'ann@example.com' ) );
ok( (bool) preg_match( '#value="' . preg_quote( key_of( 500, $D1, '14:00' ), '#' ) . '" checked#', $html ), 'edit link pre-selects Ann\'s current picks' );
ok( str_contains( $html, 'your current choice' ) && str_contains( $html, '>Update my sessions</button>' ), 'edit view labels current choices' );
ok( (bool) preg_match( '#name="part_slots\[1\]" value="" checked#', $html ), 'part Ann skipped shows "Can\'t attend" selected' );
$html = front( 500 );
ok( (bool) preg_match( '#class="dcs-slot-full"><input type="radio" name="part_slots\[1\]" value="' . preg_quote( key_of( 500, $D2, '14:00' ), '#' ) . '" disabled#', $html ), 'full slot disabled for everyone else' );

// Slot that has already started
$past = $base; $past['slots'][] = $slot( date( 'Y-m-d', strtotime( '-1 day' ) ), '09:00', 5, 'part_cccc3333' );
make_event( 502, 'sessions', 'Past test' );
save_event( 502, $past );
$r = ajax( [ 'event_id' => 502, 'name' => 'Eve', 'email' => 'eve@example.com',
    'part_slots' => [ '', '', dcs_slot_key( slot_by( 502, date( 'Y-m-d', strtotime( '-1 day' ) ), '09:00' ) ) ] ] );
ok( $r && ! $r->ok && str_contains( $r->data, 'already started' ), 'slot that already started is refused' );

// ---------------------------------------------------------------------------
section( 'D. Attendance settings' );
make_event( 510, 'sessions', 'Required series' );
save_event( 510, array_merge( $base, [ 'dcs_sessions_attendance' => 'required' ] ) );
$html = front( 510 );
ok( ! str_contains( $html, 'Can&#039;t attend' ) && str_contains( $html, 'Please choose a time for every part.' ), 'required: no "Can\'t attend", note shown' );
$r = ajax( [ 'event_id' => 510, 'name' => 'Fay', 'email' => 'fay@example.com', 'part_slots' => [ key_of( 510, $D1, '09:00' ), key_of( 510, $D2, '09:00' ) ] ] );
ok( $r && ! $r->ok && $r->data === 'Please choose a time for every part.', 'required: missing a part refused' );
$r = ajax( [ 'event_id' => 510, 'name' => 'Fay', 'email' => 'fay@example.com', 'part_slots' => [ key_of( 510, $D1, '09:00' ), key_of( 510, $D2, '09:00' ), key_of( 510, $D3, '09:00' ) ] ] );
ok( $r && $r->ok, 'required: all parts accepted' );

make_event( 511, 'sessions', 'Any series' );
save_event( 511, array_merge( $base, [ 'dcs_sessions_attendance' => 'any' ] ) );
$html = front( 511 );
ok( (bool) preg_match( '#name="part_slots\[2\]" value="" checked#', $html ) && ! str_contains( $html, 'recommended' ), 'pick any: nothing pre-ticked, no note' );

// Single session offered at several times (no parts)
make_event( 520, 'sessions', 'Workshop' );
save_event( 520, $NONCE + [
    'dcs_meeting_mode' => 'sessions', 'parts' => [],
    'slots' => [ $slot( $D1, '09:00', 10, '' ), $slot( $D1, '14:00', 10, '' ), $slot( $D2, '09:00', 10, '' ) ],
] );
ok( dcs_get_parts( 520 ) === [] && ! isset( slots( 520 )[0]['part'] ), 'no parts: slots saved without a part' );
$html = front( 520 );
ok( ! str_contains( $html, '<legend>' ) && ! str_contains( $html, 'Can&#039;t attend' ) && preg_match_all( '#type="radio"[^>]* required>#', $html ) === 3, 'single session: one plain list, a pick is required' );
$r = ajax( [ 'event_id' => 520, 'name' => 'Gus', 'email' => 'gus@example.com', 'part_slots' => [ key_of( 520, $D1, '14:00' ) ] ] );
ok( $r && $r->ok, 'single session: one pick accepted' );
$r = ajax( [ 'event_id' => 520, 'name' => 'Hal', 'email' => 'hal@example.com', 'part_slots' => [ key_of( 520, $D1, '09:00' ), key_of( 520, $D2, '09:00' ) ] ] );
ok( $r && ! $r->ok, 'single session: two picks refused' );
$gm = mails_to( 'gus@example.com' );
ok( count( $gm ) === 1 && ! str_contains( $gm[0]['body'], 'Part 1' ), 'single session confirmation has no part labels' );

// ---------------------------------------------------------------------------
section( 'E. Close and send final details' );
$box = render( fn() => DCS_Admin::render_close_poll_box( get_post( 500 ) ) );
ok( str_contains( $box, '<th>Part</th>' ) && str_contains( $box, 'Who is attending each part' ) && str_contains( $box, 'skipping' ), 'close box: Part column + roster with skipped parts' );
save_event( 500, [ 'dcs_close_poll' => '1', 'dcs_close_poll_nonce' => 'nonce-dcs_close_poll_action' ] );
ok( dcs_event_is_closed( 500 ), 'registration closed' );
$r = ajax( [ 'event_id' => 500, 'name' => 'Ivy', 'email' => 'ivy@example.com', 'part_slots' => [ key_of( 500, $D3, '09:00' ) ] ] );
ok( $r && ! $r->ok && $r->data === 'Registration for this event is closed.', 'sign-up after close refused' );
$html = front( 500 );
ok( str_contains( $html, 'Registration for this event is closed' ) && ! str_contains( $html, 'part_slots' ) && ! leaks( $html ), 'closed page: notice, no form, no leaks' );

$GLOBALS['_mail'] = [];
save_event( 500, [ 'dcs_send_announce' => 'all', 'dcs_send_announce_nonce' => 'nonce-dcs_send_announce_action' ] );
$ann = mails_to( 'ann@example.com' ); $ben = mails_to( 'ben@example.com' );
ok( count( $ann ) === 1 && count( $ben ) === 1 && count( mails_to( 'admin@example.com' ) ) === 1, 'one email per person + admin summary' );
ok( str_starts_with( $ann[0]['subj'], 'Your schedule:' ), 'subject "Your schedule: …"' );
$ab = blocks( $ann[0]['body'] );
ok( array_keys( $ab ) === [ 'Part 1: Kickoff', 'Part 3' ], 'Ann\'s email: only her parts, in order, with headings' );
ok( str_contains( $ab['Part 1: Kickoff'], 'p1pm-pass' ) && str_contains( $ab['Part 1: Kickoff'], 'Z-ID' ), 'Ann part 1 (2pm): slot passcode + Zoom ID' );
ok( ! str_contains( $ann[0]['body'], 'teams' ), 'Ann gets no Teams details (she dropped part 2)' );
ok( count( $ann[0]['ics'] ) === 2, 'Ann gets one .ics per session' );
$bb = blocks( $ben[0]['body'] );
ok( array_keys( $bb ) === [ 'Part 1: Kickoff', 'Part 2: Deep dive' ], 'Ben\'s email: parts 1 and 2' );
ok( str_contains( $bb['Part 2: Deep dive'], 'teams.microsoft.com/l/p2' ) && str_contains( $bb['Part 2: Deep dive'], 'p2-pass' ), 'Ben part 2: Teams link + passcode' );
ok( ! str_contains( $bb['Part 2: Deep dive'], 'Z-ID' ) && ! str_contains( $bb['Part 2: Deep dive'], 'zoom' ) && ! str_contains( $bb['Part 2: Deep dive'], '555 0000' ), 'Ben part 2 has no Zoom ID / link / dial-in' );
ok( str_contains( $bb['Part 1: Kickoff'], 'zoom-pass' ), 'Ben part 1: Zoom passcode' );
$uids = array_map( fn( $i ) => preg_match( '/UID:(\S+)/', $i, $mm ) ? $mm[1] : '', $ben[0]['ics'] );
ok( count( array_unique( $uids ) ) === 2 && ics_lines_ok( $ben[0]['ics'][1] ), 'Ben\'s two .ics files have distinct UIDs and valid lines' );
ok( str_contains( ics_unfold( $ben[0]['ics'][1] ), 'URL:https://teams.microsoft.com/l/p2' ), 'part 2 .ics carries the Teams link' );
$adm = mails_to( 'admin@example.com' )[0]['body'];
ok( str_contains( $adm, 'Part 2: Deep dive' ) && str_contains( $adm, 'Ann' ) && str_contains( $adm, 'Ben' ), 'admin summary grouped with part headings' );

// Single-session event emails have no part headings
update_post_meta( 520, '_poll_status', 'closed' );
$GLOBALS['_mail'] = [];
DCS_Mailer::send_final_details( 520 );
ok( ! str_contains( mails_to( 'gus@example.com' )[0]['body'], '<h3' ), 'single session final email: no part heading' );

// ---------------------------------------------------------------------------
section( 'F. Admin screens render' );
$mode_box = render( fn() => DCS_Admin::render_mode_box( get_post( 500 ) ) );
ok( (bool) preg_match( '#value="sessions"\s+checked#', $mode_box ) && (bool) preg_match( '#value="recommended"\s*checked#', $mode_box ), 'mode box: sessions + attendance' );
$parts_box = render( fn() => DCS_Admin::render_parts_box( get_post( 500 ) ) );
ok( str_contains( $parts_box, 'value="part_bbbb2222"' ) && str_contains( $parts_box, 'parts[1][details][url]' ) && str_contains( $parts_box, 'id="dcs-part-tpl"' ), 'parts box: rows, part overrides, template' );
ok( substr_count( $parts_box, '<details class="dcs-slot-override" open' ) === 1, 'only the part with an override starts expanded' );
$slots_box = render( fn() => DCS_Admin::render_slots_box( get_post( 500 ) ) );
ok( str_contains( $slots_box, 'name="slots[2][part]"' ) && str_contains( $slots_box, '<option value="part_bbbb2222" selected' ), 'slots box: Part dropdown with current part selected' );
ok( str_contains( $slots_box, 'id="dcs-slot-part-tpl"' ), 'slots box: Part dropdown template for new slots' );
$foot = render( fn() => DCS_Admin::admin_footer_scripts() );
ok( str_contains( $foot, 'refreshPartSelects' ) && str_contains( $foot, 'toggleSessionsUi' ), 'footer JS: part syncing + sessions toggle' );

finish();
