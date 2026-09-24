<?php
/**
 * Minimal in-memory WordPress stand-ins — just enough surface for the
 * Doodle Clone Scheduler plugin code to run under the PHP CLI.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'AUTH_SALT', 'test-salt' );

$GLOBALS['_meta']       = [];
$GLOBALS['_mail']       = [];
$GLOBALS['_transients'] = [];
$GLOBALS['_posts']      = [];
$GLOBALS['_opts']       = [ 'admin_email' => 'admin@example.com', 'date_format' => 'F j, Y', 'time_format' => 'g:i a' ];

class WP_Post {
    public $ID; public $post_title; public $post_status = 'publish'; public $post_type = 'meeting_event';
    function __construct( $id, $title ) { $this->ID = $id; $this->post_title = $title; }
}
class WP_Error {
    private $m;
    function __construct( $c, $m ) { $this->m = $m; }
    function get_error_message() { return $this->m; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

class JsonResponse extends Exception {
    public $ok; public $data;
    function __construct( $ok, $data ) { $this->ok = $ok; $this->data = $data; parent::__construct( 'json' ); }
}
function wp_send_json_error( $d = null, $code = null ) { throw new JsonResponse( false, $d ); }
function wp_send_json_success( $d = null ) { throw new JsonResponse( true, $d ); }

// Meta / options / transients
function get_post_meta( $id, $k, $single = true ) { return $GLOBALS['_meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['_meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['_meta'][ $id ][ $k ] ); return true; }
function get_option( $k, $d = false ) { return $GLOBALS['_opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['_opts'][ $k ] = $v; }
function get_transient( $k ) { return $GLOBALS['_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['_transients'][ $k ] = $v; }
function wp_cache_delete() {}

// i18n / escaping / sanitizing
function __( $s, $d = null ) { return $s; }
function _n( $a, $b, $n, $d = null ) { return $n == 1 ? $a : $b; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_attr__( $s, $d = null ) { return esc_attr( $s ); }
function esc_html_e( $s, $d = null ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = null ) { echo esc_attr( $s ); }
function esc_textarea( $s ) { return esc_html( $s ); }
function esc_url( $u ) { return esc_html( $u ); }
function esc_url_raw( $u, $protocols = null ) {
    $u = trim( (string) $u );
    if ( $u === '' ) return '';
    if ( ! preg_match( '#^([a-z][a-z0-9+.-]*):#i', $u, $m ) ) { $u = 'http://' . $u; $scheme = 'http'; }
    else { $scheme = strtolower( $m[1] ); }
    if ( ! in_array( $scheme, $protocols ?? [ 'http', 'https', 'mailto' ], true ) ) return '';
    return preg_replace( '/[\x00-\x20]/', '', $u );
}
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_email( $e ) { return filter_var( trim( (string) $e ), FILTER_SANITIZE_EMAIL ); }
function is_email( $e ) { return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false; }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( (string) $v ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : addslashes( (string) $v ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function selected( $a, $b, $echo = true ) { $r = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) echo $r; return $r; }
function checked( $a, $b, $echo = true ) { $r = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) echo $r; return $r; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function apply_filters( $h, $v ) { return $v; }
function add_action() {} function add_filter() {} function add_shortcode() {} function add_meta_box() {}
function antispambot( $e ) { return $e; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.com/wp-content/plugins/dcs/'; }

// Nonces / capabilities
function wp_create_nonce( $a ) { return 'nonce-' . $a; }
function wp_verify_nonce( $n, $a ) { return $n === 'nonce-' . $a ? 1 : false; }
function wp_nonce_field( $a, $n, $ref = true, $echo = true ) { $h = '<input type="hidden" name="' . $n . '" value="nonce-' . $a . '">'; if ( $echo ) echo $h; return $h; }
function check_ajax_referer( $a, $k, $die = true ) { return ( $_POST[ $k ] ?? '' ) === 'nonce-' . $a; }
function current_user_can() { return $GLOBALS['_can'] ?? true; }
function wp_is_post_revision() { return false; }

// Posts / URLs
function get_post( $id ) { return $GLOBALS['_posts'][ is_object( $id ) ? $id->ID : $id ] ?? null; }
function get_posts( $args ) { return array_values( $GLOBALS['_posts'] ); }
function get_post_type( $id ) { return isset( $GLOBALS['_posts'][ is_object( $id ) ? $id->ID : $id ] ) ? 'meeting_event' : false; }
function get_post_status( $id ) { return $GLOBALS['_posts'][ $id ]->post_status ?? false; }
function get_the_title( $p ) { $id = is_object( $p ) ? $p->ID : $p; return $GLOBALS['_posts'][ $id ]->post_title ?? ''; }
function get_permalink( $p ) { $id = is_object( $p ) ? $p->ID : $p; return 'https://example.com/meeting_event/' . $id . '/'; }
function get_edit_post_link( $id, $c = '' ) { return 'https://example.com/wp-admin/post.php?post=' . $id; }
function home_url() { return 'https://example.com'; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function get_bloginfo( $k ) { return 'Test Site'; }
function add_query_arg( $k, $v, $u ) { return $u . ( str_contains( $u, '?' ) ? '&' : '?' ) . $k . '=' . $v; }
function get_current_screen() { return (object) [ 'post_type' => 'meeting_event' ]; }

// Time
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
function date_i18n( $f, $ts ) { return date( $f, $ts ); }
function wp_date( $f, $ts, $tz = null ) { return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz ?: wp_timezone() )->format( $f ); }

// Front-end loop context
function is_singular() { return true; }
function in_the_loop() { return true; }
function get_the_ID() { return $GLOBALS['_current_post']; }

// Files / mail — attachments are read at send time, as a real mailer would
function wp_tempnam( $n ) { return tempnam( sys_get_temp_dir(), 'dcs' ); }
function wp_mail( $to, $subj, $body, $headers = [], $att = [] ) {
    $ics = [];
    foreach ( (array) $att as $a ) $ics[] = file_get_contents( $a );
    $GLOBALS['_mail'][] = compact( 'to', 'subj', 'body', 'ics' );
    return true;
}
function wp_generate_password() { return 'x'; }

class FakeWpdb {
    function prepare( $q, ...$a ) { return $q; }
    function get_var( $q ) { return '1'; }
    function query( $q ) { return true; }
}
$GLOBALS['wpdb'] = new FakeWpdb();
