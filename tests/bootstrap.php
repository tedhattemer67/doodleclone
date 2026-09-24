<?php
/**
 * Shared test setup: loads the WP stubs and the plugin, and provides small
 * helpers for driving the plugin the way WordPress would.
 */
require __DIR__ . '/wp-stubs.php';
require dirname( __DIR__ ) . '/doodle-clone-scheduler.php';

error_reporting( E_ALL );
set_error_handler( function ( $no, $str, $file, $line ) {
    echo "PHP WARNING/NOTICE: $str in " . basename( $file ) . ":$line\n";
    $GLOBALS['fail']++;
    return true;
} );

$pass = 0; $fail = 0;
function section( $s ) { echo "\n== $s\n"; }
function ok( $cond, $msg ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  ok   $msg\n"; }
    else         { $fail++; echo "  FAIL $msg\n"; }
}
function finish() {
    global $pass, $fail;
    echo "\n$pass passed, $fail failed\n";
    exit( $fail ? 1 : 0 );
}
function make_event( $id, $mode, $title ) {
    $GLOBALS['_posts'][ $id ] = new WP_Post( $id, $title );
    update_post_meta( $id, '_meeting_mode', $mode );
    return $GLOBALS['_posts'][ $id ];
}
/** Runs the whole save_post_meeting_event chain in priority order, like WP does. */
function save_event( $id, array $post ) {
    $_POST = wp_slash( $post ); // WordPress slashes superglobals
    $p = get_post( $id );
    DCS_Admin::dcs_snapshot_pre_save( $id, $p, true );
    DCS_Admin::save_parts( $id );
    DCS_Admin::save_slots( $id );
    DCS_Admin::save_meeting_details( $id );
    DCS_Admin::save_mode( $id );
    DCS_Admin::save_poll_status( $id, $p );
    DCS_Admin::handle_poll_close_reopen( $id, $p );
    DCS_Admin::handle_announcement( $id, $p );
    DCS_Admin::normalize_slots( $id );
    DCS_Admin::merge_durations_from_snapshot( $id );
    $_POST = [];
}
function slots( $id ) { return get_post_meta( $id, '_meeting_slots', true ); }
/** Submits the public form via the AJAX handler; returns the JsonResponse. */
function ajax( array $post ) {
    $ts    = (string) ( time() - 10 );
    $_POST = wp_slash( $post + [
        'dcs_nonce' => 'nonce-dcs_book_slot',
        'dcs_ts'    => $ts . '.' . hash_hmac( 'sha256', $ts, AUTH_SALT ),
    ] );
    try { DCS_Ajax::handle_booking(); } catch ( JsonResponse $r ) { $_POST = []; return $r; }
    $_POST = [];
    return null;
}
function mails_to( $to ) { return array_values( array_filter( $GLOBALS['_mail'], fn( $m ) => $m['to'] === $to ) ); }
function render( callable $fn ) { ob_start(); $fn(); return ob_get_clean(); }
/** Renders the public event page, optionally as someone holding an edit link. */
function front( $id, string $token = '' ) {
    $GLOBALS['_current_post'] = $id;
    $_GET = $token !== '' ? [ 'dcs_token' => $token ] : [];
    $out  = DCS_Frontend::inject_form( '<p>body</p>' );
    $_GET = [];
    return $out;
}
function ics_lines_ok( string $ics ): bool {
    if ( ! str_contains( $ics, "\r\n" ) ) return false;
    foreach ( explode( "\r\n", $ics ) as $l ) {
        if ( strlen( $l ) > 75 || ! preg_match( '//u', $l ) ) return false;
    }
    return true;
}
function ics_unfold( string $ics ): string { return str_replace( "\r\n ", '', $ics ); }
