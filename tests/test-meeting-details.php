<?php
/**
 * Meeting details + close/send final details (2.2.0).
 * Run from the repo root:  php tests/test-meeting-details.php
 * No WordPress or database needed — tests/wp-stubs.php stands in for WP.
 */
require __DIR__ . '/bootstrap.php';

$D1      = date( 'Y-m-d', strtotime( '+10 days' ) );
$SECRETS = [ 'zoom.us/j/111', 'secret-pass', 'teams.microsoft.com', 'teams-pass', '558 8656', 'Brien' ];
function leaks( string $html ): array { global $SECRETS; return array_values( array_filter( $SECRETS, fn( $s ) => str_contains( $html, $s ) ) ); }

// ---------------------------------------------------------------------------
section( 'A. Sanitizing' );
$d = dcs_sanitize_meeting_details( [ 'format' => 'online', 'url' => 'javascript:alert(1)' ] );
ok( $d['url'] === '', 'javascript: link dropped' );
$d = dcs_sanitize_meeting_details( [ 'url' => 'http://zoom.us/j/1' ] );
ok( $d['url'] === '', 'plain http link dropped' );
$d = dcs_sanitize_meeting_details( [ 'url' => 'zoom.us/j/1' ] );
ok( $d['url'] === '', 'scheme-less link dropped' );
$d = dcs_sanitize_meeting_details( [ 'url' => 'https://zoom.us/j/123?pwd=abc' ] );
ok( $d['url'] === 'https://zoom.us/j/123?pwd=abc', 'https link kept' );
ok( dcs_sanitize_meeting_details( [ 'format' => 'bogus' ] )['format'] === 'unspecified', 'bad format -> unspecified (default)' );
ok( dcs_sanitize_meeting_details( [ 'format' => 'bogus' ], true )['format'] === '', 'bad format -> "" (override = inherit)' );
ok( dcs_sanitize_meeting_details( [ 'location' => '<b>Room</b> 1' ] )['location'] === 'Room 1', 'tags stripped from location' );
ok( dcs_sanitize_meeting_details( [ 'dial_in' => "+1 111\n+1 222" ] )['dial_in'] === "+1 111\n+1 222", 'dial-in keeps line breaks' );
ok( ! dcs_meeting_details_has_content( dcs_sanitize_meeting_details( [] ) ), 'empty record has no content' );

// ---------------------------------------------------------------------------
section( 'B. ICS escaping / folding' );
ok( dcs_ics_escape( "a,b;c\\d\ne" ) === 'a\,b\;c\\\\d\ne', 'RFC 5545 TEXT escaping' );
$folded = dcs_ics_fold( 'DESCRIPTION:' . str_repeat( 'é', 60 ) );
ok( ics_lines_ok( $folded ), 'UTF-8 line folded at <=75 bytes without splitting characters' );
ok( str_replace( "\r\n ", '', $folded ) === 'DESCRIPTION:' . str_repeat( 'é', 60 ), 'folding round-trips' );
ok( dcs_ics_fold( 'SHORT:x' ) === 'SHORT:x', 'short line untouched' );

// ---------------------------------------------------------------------------
section( 'B2. Calendar description formatting' );
$fmt = dcs_sanitize_meeting_details( [
    'format' => 'hybrid', 'location' => '30 E Broad Street', 'url' => 'https://example.org/join?a=1&b=2',
    'meeting_id' => '123 456', 'passcode' => 'pw', 'dial_in' => "+1 111 111\n+1 222 222", 'notes' => 'Bring ID',
] );
$plain = dcs_meeting_details_plaintext( $fmt, 'https://example.com/e/1/' );
ok( $plain === "Format: Hybrid (in person + online)\nLocation: 30 E Broad Street\n\n"
    . "Join online: https://example.org/join?a=1&b=2\nMeeting ID: 123 456\nPasscode: pw\n\n"
    . "Dial-in:\n+1 111 111\n+1 222 222\n\nBring ID\n\nEvent page: https://example.com/e/1/", 'plain text: one item per line, blank line between sections' );
$html = dcs_meeting_details_html( $fmt, 'https://example.com/e/1/' );
ok( substr_count( $html, '<br>' ) >= 10 && ! str_contains( $html, "\n" ), 'HTML version uses <br>, no bare newlines (Google collapses those)' );
ok( str_contains( $html, 'Location: 30 E Broad Street<br><br>Join online:' ), 'HTML sections separated by a blank line' );
ok( str_contains( $html, '<a href="https://example.org/join?a=1&amp;b=2">' ), 'join link clickable and escaped' );
$evil = dcs_meeting_details_html( [ 'format' => 'unspecified', 'notes' => '<img src=x onerror=alert(1)>' ] );
ok( ! str_contains( $evil, '<img' ), 'HTML version escapes values' );
$gurl = dcs_google_calendar_url( new WP_Post( 999, 'X' ), time(), time() + 3600, $fmt );
parse_str( parse_url( $gurl, PHP_URL_QUERY ), $q );
ok( str_contains( $q['details'], 'Location: 30 E Broad Street<br><br>Join online:' ), 'Google link details carry <br> line breaks' );
ok( ! str_contains( dcs_google_calendar_url( new WP_Post( 999, 'X' ), time(), time() ), '%3Cbr' ), 'Google link without details unchanged (public notice)' );

// ---------------------------------------------------------------------------
section( 'C. Saving details (1-on-1 event)' );
make_event( 100, 'booking', 'Team Sync' );
$base_post = [
    'dcs_meeting_mode'       => 'booking',
    'dcs_save_details_nonce' => 'nonce-dcs_save_details_action',
    'dcs_details'            => [
        'format' => 'online', 'location' => '', 'url' => 'https://zoom.us/j/111', 'meeting_id' => '111 222 333',
        'passcode' => 'secret-pass', 'dial_in' => "+1 646 558 8656 US (New York)\n+1 301 715 8592 US", 'notes' => '',
    ],
    'slots' => [
        [ 'date' => $D1, 'time' => '09:00', 'duration_minutes' => '60', 'max' => '10',
          'attendees' => [ [ 'name' => '', 'email' => '' ] ],
          'details' => [ 'format' => '', 'location' => '', 'url' => '', 'meeting_id' => '', 'passcode' => '', 'dial_in' => '', 'notes' => '' ] ],
        [ 'date' => $D1, 'time' => '14:00', 'duration_minutes' => '60', 'max' => '10',
          'attendees' => [ [ 'name' => '', 'email' => '' ] ],
          'details' => [ 'format' => 'hybrid', 'location' => "O'Brien Hall, Room 4B; 2nd floor", 'url' => 'https://teams.microsoft.com/l/meetup-join/abc',
                         'meeting_id' => '', 'passcode' => 'teams-pass', 'dial_in' => '', 'notes' => '<script>alert(1)</script>Bring a laptop' ] ],
    ],
];
save_event( 100, $base_post );
$s   = slots( 100 );
$md  = get_post_meta( 100, '_meeting_details', true );
$id1 = $s[0]['id']; $id2 = $s[1]['id'];
ok( count( $s ) === 2, 'two slots saved' );
ok( ( $md['default']['url'] ?? '' ) === 'https://zoom.us/j/111', 'event default saved' );
ok( array_keys( $md['slots'] ) === [ $id2 ], 'only the slot with an override is stored' );
ok( ! isset( $s[1]['details'] ), 'details NOT stored inside _meeting_slots' );
$e1 = dcs_slot_details( 100, $s[0] ); $e2 = dcs_slot_details( 100, $s[1] );
ok( $e1['url'] === 'https://zoom.us/j/111' && $e1['passcode'] === 'secret-pass', 'slot 1 inherits event default' );
ok( $e2['url'] === 'https://teams.microsoft.com/l/meetup-join/abc' && $e2['format'] === 'hybrid', 'slot 2 override wins' );
ok( $e2['location'] === "O'Brien Hall, Room 4B; 2nd floor", 'slashes removed (O\'Brien, not O\\\'Brien)' );
ok( $e2['meeting_id'] === '' && $e2['dial_in'] === '', 'slot with its own (Teams) link does NOT inherit Zoom meeting ID / dial-in' );
ok( $e2['passcode'] === 'teams-pass', 'own-link slot keeps its own passcode' );

// Same-meeting override: no link of its own, just a different passcode
$md_tmp = get_post_meta( 100, '_meeting_details', true );
$md_tmp['slots'][ $id1 ] = [ 'format' => '', 'passcode' => 'other-pass' ];
update_post_meta( 100, '_meeting_details', $md_tmp );
$e1b = dcs_slot_details( 100, $s[0] );
ok( $e1b['url'] === 'https://zoom.us/j/111' && $e1b['meeting_id'] === '111 222 333' && $e1b['passcode'] === 'other-pass', 'passcode-only override keeps default Zoom link + ID' );
ok( str_contains( $e1b['dial_in'], '558 8656' ), 'passcode-only override keeps default dial-in' );
unset( $md_tmp['slots'][ $id1 ] );
update_post_meta( 100, '_meeting_details', $md_tmp );

// Location/notes still inherit on an own-link slot
$md_tmp['default']['notes'] = 'Default notes';
update_post_meta( 100, '_meeting_details', $md_tmp );
$e2b = dcs_slot_details( 100, $s[1] );
ok( str_contains( $e2b['notes'], 'Bring a laptop' ), 'slot notes override default notes' );
$md_tmp['default']['notes'] = '';
update_post_meta( 100, '_meeting_details', $md_tmp );
ok( ! str_contains( $e2['notes'], '<script>' ), 'script tags stripped from notes' );

$moved = $base_post; $moved['slots'][1]['time'] = '15:00';
save_event( 100, $moved );
$s2 = slots( 100 ); $md2 = get_post_meta( 100, '_meeting_details', true );
ok( $s2[1]['id'] !== $id2, 'changing slot time mints a new slot id (existing behaviour)' );
ok( array_keys( $md2['slots'] ) === [ $s2[1]['id'] ], 'override follows the slot to its new id; old id pruned' );
$id2 = $s2[1]['id'];

$removed = $moved; unset( $removed['slots'][1] );
save_event( 100, $removed );
ok( get_post_meta( 100, '_meeting_details', true )['slots'] === [], 'removing a slot drops its override' );

save_event( 100, $moved ); // put slot 2 back
$s = slots( 100 ); $id1 = $s[0]['id']; $id2 = $s[1]['id'];

$no_nonce = $moved; unset( $no_nonce['dcs_save_details_nonce'] ); $no_nonce['dcs_details']['url'] = 'https://evil.example/x';
save_event( 100, $no_nonce );
ok( get_post_meta( 100, '_meeting_details', true )['default']['url'] === 'https://zoom.us/j/111', 'save without details nonce leaves details untouched' );

save_event( 100, [] ); // quick edit / REST-style save: nothing posted
ok( count( get_post_meta( 100, '_meeting_details', true )['slots'] ) === 1, 'save with nothing posted keeps details' );

$GLOBALS['_can'] = false;
$bad = $moved; $bad['dcs_details']['url'] = 'https://evil.example/x';
save_event( 100, $bad );
$GLOBALS['_can'] = true;
ok( get_post_meta( 100, '_meeting_details', true )['default']['url'] === 'https://zoom.us/j/111', 'user without edit rights cannot change details' );

// ---------------------------------------------------------------------------
section( 'D. 1-on-1 sign-up while open' );
$GLOBALS['_mail'] = [];
$r = ajax( [ 'event_id' => 100, 'name' => 'Alice', 'email' => 'alice@example.com', 'slot_id' => $id1 ] );
ok( $r && $r->ok, 'Alice books slot 1' );
$r = ajax( [ 'event_id' => 100, 'name' => 'Bob', 'email' => 'bob@example.com', 'slot_id' => $id2 ] );
ok( $r && $r->ok, 'Bob books slot 2' );
$a = mails_to( 'alice@example.com' );
ok( count( $a ) === 1 && str_contains( $a[0]['body'], 'will be sent to you before the meeting' ), 'signup email says details will follow' );
ok( ! leaks( $a[0]['body'] . implode( '', $a[0]['ics'] ) ), 'signup email + .ics contain no link/passcode' );
ok( str_contains( $a[0]['ics'][0], 'UID:100-' . $id1 . '@example.com' ) && str_contains( $a[0]['ics'][0], 'SEQUENCE:0' ), 'signup .ics has per-slot UID, SEQUENCE 0' );
foreach ( [ 'ajax response' => json_encode( $r->data ) ] as $what => $blob ) ok( ! leaks( $blob ), "no secrets in $what" );

$html = front( 100 );
ok( str_contains( $html, 'dcs-booking' ), 'open event shows the booking form' );
ok( ! leaks( $html ), 'open public page leaks nothing: ' . implode( ',', leaks( $html ) ) );
$ov = DCS_Frontend::render_overview_shortcode();
ok( ! leaks( $ov ), 'overview shortcode leaks nothing' );

// ---------------------------------------------------------------------------
section( 'E. Close 1-on-1 registration' );
$box = render( fn() => DCS_Admin::render_close_poll_box( get_post( 100 ) ) );
ok( str_contains( $box, 'Close Registration' ) && str_contains( $box, 'link set' ), 'close box shows slots + details status' );
save_event( 100, [ 'dcs_close_poll' => '1', 'dcs_close_poll_nonce' => 'nonce-dcs_close_poll_action' ] );
ok( get_post_meta( 100, '_poll_status', true ) === 'closed', 'Close Registration sets closed' );
$r = ajax( [ 'event_id' => 100, 'name' => 'Carol', 'email' => 'carol@example.com', 'slot_id' => $id1 ] );
ok( $r && ! $r->ok && $r->data === 'Registration for this event is closed.', 'sign-up after close is refused' );
$html = front( 100 );
ok( str_contains( $html, 'Registration for this event is closed' ) && ! str_contains( $html, 'dcs-booking' ), 'closed public page: notice, no form' );
ok( ! leaks( $html ), 'closed public page leaks nothing' );
$box = render( fn() => DCS_Admin::render_close_poll_box( get_post( 100 ) ) );
ok( str_contains( $box, 'Send to everyone' ) && str_contains( $box, 'Reopen Registration' ), 'closed box shows send panel + reopen' );

// ---------------------------------------------------------------------------
section( 'F. Send final details (1-on-1)' );
$GLOBALS['_mail'] = [];
save_event( 100, [ 'dcs_send_announce' => 'all', 'dcs_send_announce_nonce' => 'nonce-dcs_send_announce_action' ] );
$a = mails_to( 'alice@example.com' ); $b = mails_to( 'bob@example.com' ); $adm = mails_to( 'admin@example.com' );
ok( count( $a ) === 1 && count( $b ) === 1 && count( $adm ) === 1, 'one email each to Alice, Bob, and admin summary' );
ok( str_contains( $a[0]['body'], 'https://zoom.us/j/111' ) && str_contains( $a[0]['body'], 'secret-pass' ), 'Alice gets Zoom link + passcode' );
ok( str_contains( $a[0]['body'], '558 8656 US (New York)<br' ), 'dial-in lines rendered with line breaks' );
ok( ! str_contains( $a[0]['body'], 'teams' ), 'Alice does NOT get Bob\'s slot details' );
ok( str_contains( $b[0]['body'], 'teams.microsoft.com' ) && str_contains( $b[0]['body'], 'teams-pass' ) && ! str_contains( $b[0]['body'], 'zoom.us' ), 'Bob gets only his Teams details' );
ok( ! str_contains( $b[0]['body'], '111 222 333' ) && ! str_contains( $b[0]['body'], 'secret-pass' ) && ! str_contains( $b[0]['body'], '558 8656' ), 'Bob\'s Teams email has no Zoom meeting ID / passcode / dial-in' );
ok( ! str_contains( ics_unfold( $b[0]['ics'][0] ), 'secret-pass' ) && ! str_contains( ics_unfold( $b[0]['ics'][0] ), '111 222 333' ), 'Bob\'s .ics has no Zoom details' );
ok( str_contains( $b[0]['body'], 'O&#039;Brien Hall' ), 'location HTML-escaped in email' );
ok( str_contains( $b[0]['body'], 'Hi Bob,' ), 'personal greeting' );
$ics = $b[0]['ics'][0] ?? '';
ok( ics_lines_ok( $ics ), 'final .ics: CRLF, every line <=75 bytes, valid UTF-8' );
$u = ics_unfold( $ics );
ok( str_contains( $u, 'UID:100-' . $id2 . '@example.com' ) && str_contains( $u, 'SEQUENCE:1' ), 'final .ics reuses signup UID with SEQUENCE 1 (updates, not duplicates)' );
ok( str_contains( $u, "LOCATION:O'Brien Hall\\, Room 4B\\; 2nd floor" ), 'LOCATION escaped per RFC 5545' );
ok( str_contains( $u, 'URL:https://teams.microsoft.com/l/meetup-join/abc' ), 'URL property set' );
ok( str_contains( $u, 'Passcode: teams-pass' ), 'DESCRIPTION carries join details' );
ok( str_contains( $u, '2nd floor\n\nJoin online:' ), 'DESCRIPTION sections separated by blank lines' );
ok( str_contains( $u, 'X-ALT-DESC;FMTTYPE=text/html:' ) && str_contains( $u, '<br>' ), 'HTML X-ALT-DESC added for Outlook' );ok( str_contains( ics_unfold( $a[0]['ics'][0] ), 'LOCATION:https://zoom.us/j/111' ), 'online-only slot: LOCATION falls back to link' );
ok( str_contains( $adm[0]['body'], 'Alice' ) && str_contains( $adm[0]['body'], 'Bob' ) && str_contains( $adm[0]['body'], 'secret-pass' ), 'admin summary lists attendees + details' );

// Late addition, then "only people not yet emailed"
$s = slots( 100 ); $s[0]['attendees'][] = [ 'name' => 'Dave', 'email' => 'dave@example.com' ]; update_post_meta( 100, '_meeting_slots', $s );
$GLOBALS['_mail'] = [];
save_event( 100, [ 'dcs_send_announce' => 'new', 'dcs_send_announce_nonce' => 'nonce-dcs_send_announce_action' ] );
$to = array_column( $GLOBALS['_mail'], 'to' ); sort( $to );
ok( $to === [ 'admin@example.com', 'dave@example.com' ], '"not yet emailed" sends only to Dave (+ admin summary)' );

// Link changed after sending -> resend to everyone
$md = get_post_meta( 100, '_meeting_details', true ); $md['default']['url'] = 'https://zoom.us/j/999'; update_post_meta( 100, '_meeting_details', $md );
$GLOBALS['_mail'] = [];
save_event( 100, [ 'dcs_send_announce' => 'all', 'dcs_send_announce_nonce' => 'nonce-dcs_send_announce_action' ] );
$a = mails_to( 'alice@example.com' );
ok( str_contains( $a[0]['body'], 'https://zoom.us/j/999' ) && ! str_contains( $a[0]['body'], 'j/111' ), 'resend uses the updated link' );
ok( str_contains( $a[0]['ics'][0], 'SEQUENCE:3' ), 'SEQUENCE increments per send' );
ok( count( $GLOBALS['_mail'] ) === 4, 'resend to everyone: Alice, Bob, Dave + admin' );

// Bad send nonce
$GLOBALS['_mail'] = [];
save_event( 100, [ 'dcs_send_announce' => 'all', 'dcs_send_announce_nonce' => 'wrong' ] );
ok( count( $GLOBALS['_mail'] ) === 0, 'send with bad nonce sends nothing' );

// Missing-link warning
$md['default']['url'] = ''; update_post_meta( 100, '_meeting_details', $md );
$box = render( fn() => DCS_Admin::render_close_poll_box( get_post( 100 ) ) );
ok( str_contains( $box, 'have no meeting link yet' ) && str_contains( $box, 'NO LINK' ), 'warns when an online slot with attendees has no link' );
$md['default']['url'] = 'https://zoom.us/j/999'; update_post_meta( 100, '_meeting_details', $md );

// Reopen
save_event( 100, [ 'dcs_reopen_poll' => '1', 'dcs_close_poll_nonce' => 'nonce-dcs_close_poll_action' ] );
ok( get_post_meta( 100, '_poll_status', true ) === 'open', 'Reopen Registration sets open' );
$r = ajax( [ 'event_id' => 100, 'name' => 'Carol', 'email' => 'carol@example.com', 'slot_id' => $id1 ] );
ok( $r && $r->ok, 'sign-ups work again after reopen' );

// ---------------------------------------------------------------------------
section( 'G. Group poll' );
make_event( 200, 'poll', 'Planning Poll' );
save_event( 200, [
    'dcs_meeting_mode'       => 'poll',
    'dcs_save_details_nonce' => 'nonce-dcs_save_details_action',
    'dcs_details'            => [ 'format' => 'in_person', 'location' => 'Main Library, Room 2' ],
    'slots' => [
        [ 'date' => $D1, 'time' => '10:00', 'duration_minutes' => '60', 'max' => '1', 'attendees' => [ [ 'name' => '', 'email' => '' ] ] ],
        [ 'date' => $D1, 'time' => '13:00', 'duration_minutes' => '60', 'max' => '1', 'attendees' => [ [ 'name' => '', 'email' => '' ] ] ],
    ],
] );
$ps = slots( 200 );
$r = ajax( [ 'event_id' => 200, 'name' => 'Erin', 'email' => 'erin@example.com', 'slot_ids' => [ $ps[0]['id'], $ps[1]['id'] ] ] );
ok( $r && $r->ok, 'Erin votes for both slots' );
$r = ajax( [ 'event_id' => 200, 'name' => 'Frank', 'email' => 'frank@example.com', 'slot_ids' => [ $ps[0]['id'] ] ] );
ok( $r && $r->ok, 'Frank votes for slot 1 only' );
save_event( 200, [ 'dcs_close_poll' => '1', 'dcs_close_poll_nonce' => 'nonce-dcs_close_poll_action', 'dcs_selected_slot_index' => '1' ] );
ok( get_post_meta( 200, '_poll_selected_slot_id', true ) === $ps[1]['id'], 'poll closed on slot 2' );
$html = front( 200 );
ok( str_contains( $html, 'This poll is now closed' ) && ! str_contains( $html, 'Main Library' ), 'closed poll page shows time but not the location' );
$GLOBALS['_mail'] = [];
save_event( 200, [ 'dcs_send_announce' => 'all', 'dcs_send_announce_nonce' => 'nonce-dcs_send_announce_action' ] );
$e = mails_to( 'erin@example.com' ); $f = mails_to( 'frank@example.com' );
[ $ws, $we ] = dcs_slot_times( $ps[1] );
ok( count( $e ) === 1 && count( $f ) === 1, 'both voters emailed (Frank too, though he didn\'t vote for the winner)' );
ok( str_contains( $f[0]['body'], esc_html( dcs_format_range( $ws, $we ) ) ) && str_contains( $f[0]['body'], 'Main Library, Room 2' ), 'poll email: winning time + location' );
ok( str_contains( $f[0]['subj'], 'scheduled for' ), 'poll subject unchanged in style' );
// Edit location after closing, resend — live read, not the close-time snapshot
save_event( 200, [ 'dcs_save_details_nonce' => 'nonce-dcs_save_details_action', 'dcs_details' => [ 'format' => 'in_person', 'location' => 'Annex B' ] ] );
$GLOBALS['_mail'] = [];
save_event( 200, [ 'dcs_send_announce' => 'all', 'dcs_send_announce_nonce' => 'nonce-dcs_send_announce_action' ] );
ok( str_contains( mails_to( 'frank@example.com' )[0]['body'], 'Annex B' ), 'resend after closing picks up edited details' );
ok( ( get_post_meta( 200, '_poll_selected_slot_id', true ) === $ps[1]['id'] ) && dcs_event_is_closed( 200 ), 'editing details does not reopen the poll' );
$r = ajax( [ 'event_id' => 200, 'name' => 'Gil', 'email' => 'gil@example.com', 'slot_ids' => [ $ps[0]['id'] ] ] );
ok( $r && ! $r->ok && $r->data === 'This poll is no longer accepting responses.', 'closed poll still refuses votes (message unchanged)' );

// ---------------------------------------------------------------------------
section( 'H. Legacy event (no details) — behaviour unchanged' );
make_event( 300, 'poll', 'Old Poll' );
update_post_meta( 300, '_meeting_slots', [ [ 'id' => 'slot_old1', 'date' => $D1, 'time' => '11:00', 'duration_minutes' => 30, 'max' => 1,
    'attendees' => [ [ 'name' => 'Hana', 'email' => 'hana@example.com' ] ] ] ] );
update_post_meta( 300, '_poll_status', 'closed' );
update_post_meta( 300, '_poll_selected_slot_id', 'slot_old1' );
update_post_meta( 300, '_poll_selected_slot_snapshot', dcs_normalize_slot_timestamps( slots( 300 )[0] ) );
$GLOBALS['_mail'] = [];
$n = DCS_Mailer::send_poll_announcement( 300 );
$h = mails_to( 'hana@example.com' );
ok( $n === 1 && count( $h ) === 1, 'old send_poll_announcement() still works (wrapper)' );
ok( ! str_contains( $h[0]['body'], 'Format' ) && ! str_contains( $h[0]['body'], 'Join online' ), 'no empty detail rows for events without details' );
ok( ! str_contains( $h[0]['ics'][0], 'LOCATION' ) && ! str_contains( $h[0]['ics'][0], 'URL:' ), 'no LOCATION/URL in .ics without details' );
ok( ! str_contains( $h[0]['ics'][0], 'X-ALT-DESC' ), 'no HTML description in .ics without details' );
// Expired edit link on an open poll: used to fatal (in_array on a missing
// prefill key); must show the "expired" notice and a blank form instead.
make_event( 302, 'poll', 'Open Poll' );
update_post_meta( 302, '_meeting_slots', [ [ 'id' => 'slot_p1', 'date' => $D1, 'time' => '11:00', 'duration_minutes' => 30, 'max' => 1, 'attendees' => [] ] ] );
$b64     = rtrim( strtr( base64_encode( json_encode( [ 'event_id' => 302, 'email' => 'x@example.com', 'exp' => time() - 60 ] ) ), '+/', '-_' ), '=' );
$expired = $b64 . '.' . hash_hmac( 'sha256', $b64, AUTH_SALT );
$html    = front( 302, $expired );
ok( str_contains( $html, 'Your edit link has expired' ) && str_contains( $html, 'name="slot_ids[]"' ), 'expired edit link on open poll: notice + blank form (was a fatal error)' );

make_event( 301, 'booking', 'Old Booking' );
update_post_meta( 301, '_meeting_slots', [ [ 'id' => 'slot_b1', 'date' => $D1, 'time' => '11:00', 'duration_minutes' => 30, 'max' => 2, 'attendees' => [] ] ] );
ok( str_contains( front( 301 ), 'dcs-booking' ), 'existing 1-on-1 with no status meta is open' );

// ---------------------------------------------------------------------------
section( 'I. Admin screens render cleanly' );
$slots_box = render( fn() => DCS_Admin::render_slots_box( get_post( 100 ) ) );
ok( str_contains( $slots_box, 'name="slots[1][details][url]"' ), 'slot override fields rendered with correct names' );
ok( substr_count( $slots_box, '<details class="dcs-slot-override" open' ) === 1, 'only the slot with an override starts expanded' );
ok( str_contains( $slots_box, 'id="dcs-slot-override-tpl"' ) && str_contains( $slots_box, 'slots[__i__][details][passcode]' ), 'JS template for new slots present' );
$det = render( fn() => DCS_Admin::render_details_box( get_post( 100 ) ) );
ok( str_contains( $det, 'dcs_save_details_nonce' ) && str_contains( $det, 'value="https://zoom.us/j/999"' ), 'details box renders nonce + saved values' );
$foot = render( fn() => DCS_Admin::admin_footer_scripts() );
ok( str_contains( $foot, 'dcs-slot-override-tpl' ) && str_contains( $foot, "'input, select, textarea'" ), 'footer JS uses template and re-indexes textareas' );
$res = render( fn() => DCS_Admin::render_results_box( get_post( 200 ) ) );
ok( str_contains( $res, 'Voter Roster' ), 'poll results box still renders' );

// ---------------------------------------------------------------------------
finish();
