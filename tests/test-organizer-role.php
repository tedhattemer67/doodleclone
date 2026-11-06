<?php
/**
 * Meeting Organiser role and own-events-only access (2.4.0).
 * Run from the repo root:  php tests/test-organizer-role.php
 */
require __DIR__ . '/bootstrap.php';

class FakeQuery {
    public $vars; public $main;
    function __construct( array $vars, bool $main = true ) { $this->vars = $vars; $this->main = $main; }
    function is_main_query() { return $this->main; }
    function get( $k ) { return $this->vars[ $k ] ?? ''; }
    function set( $k, $v ) { $this->vars[ $k ] = $v; }
}
class FakeAdminBar {
    public $removed = [];
    function remove_node( $id ) { $this->removed[] = $id; }
}
/** Act as a user with the given roles; caps come from those roles. */
function as_user( int $id, array $roles ) {
    $GLOBALS['_user_id']    = $id;
    $GLOBALS['_user_roles'] = $roles;
    $caps = [];
    foreach ( $roles as $r ) $caps += array_filter( get_role( $r )->capabilities ?? [] );
    $GLOBALS['_user_caps'] = $caps;
}
$role_caps = fn( $r ) => array_keys( array_filter( get_role( $r )->capabilities ) );

// ---------------------------------------------------------------------------
section( 'A. Post type uses its own capabilities' );
DCS_Admin::register_post_type();
$pt = $GLOBALS['_post_types']['meeting_event'];
ok( $pt['capability_type'] === [ 'meeting_event', 'meeting_events' ] && $pt['map_meta_cap'] === true, 'capability_type meeting_event(s), map_meta_cap on' );
ok( in_array( 'author', $pt['supports'], true ), 'supports author (admins can assign an event to an organiser)' );

// ---------------------------------------------------------------------------
section( 'B. Upgrade from 2.3.5' );
$GLOBALS['_opts']['dcs_db_version'] = '2.3.4';
dcs_maybe_upgrade();
ok( get_option( 'dcs_db_version' ) === '2.4.0', 'version recorded' );

$all = array_values( DCS_Roles::CAP_MAP );
ok( ! array_diff( $all, $role_caps( 'administrator' ) ), 'administrator gets every meeting-event cap' );
ok( ! array_diff( $all, $role_caps( 'editor' ) ), 'editor gets every meeting-event cap (still edits everyone\'s events)' );
ok( get_role( 'author' )->has_cap( 'publish_meeting_events' ) && ! get_role( 'author' )->has_cap( 'edit_others_meeting_events' ), 'author: own events only, as before' );
ok( get_role( 'contributor' )->has_cap( 'edit_meeting_events' ) && ! get_role( 'contributor' )->has_cap( 'publish_meeting_events' ), 'contributor: drafts only, as before' );
ok( ! array_intersect( $all, $role_caps( 'subscriber' ) ), 'subscriber gets nothing' );

$org = get_role( DCS_Roles::ROLE );
ok( $org !== null, 'Meeting Organiser role created' );
ok( $org && ! array_diff( DCS_Roles::ORGANISER_CAPS, $role_caps( DCS_Roles::ROLE ) ), 'organiser can create, edit, publish and delete own events' );
ok( $org && ! $org->has_cap( 'edit_others_meeting_events' ) && ! $org->has_cap( 'delete_others_meeting_events' ), 'organiser cannot touch others\' events' );
ok( $org && ! $org->has_cap( 'edit_posts' ) && ! $org->has_cap( 'upload_files' ), 'organiser has no blog-post or media access' );

// Running again changes nothing and doesn't duplicate the role.
$before = serialize( $GLOBALS['_roles'] );
DCS_Roles::install();
ok( serialize( $GLOBALS['_roles'] ) === $before, 'install is idempotent' );

section( 'B2. Fresh install' );
_dcs_test_reset_roles();
$GLOBALS['_opts'] = [];
dcs_maybe_upgrade();
ok( get_option( 'dcs_db_version' ) === '2.4.0' && get_role( 'administrator' )->has_cap( 'edit_others_meeting_events' ) && get_role( DCS_Roles::ROLE ), 'fresh install: caps + role, version recorded' );

// ---------------------------------------------------------------------------
section( 'C. Events list shows only your own' );
as_user( 42, [ DCS_Roles::ROLE ] );
$q = new FakeQuery( [ 'post_type' => 'meeting_event' ] );
DCS_Roles::limit_list_to_own_events( $q );
ok( $q->get( 'author' ) === 42, 'organiser: list limited to their events' );
ok( DCS_Roles::filter_list_views( [ 'all' => 'All (9)', 'mine' => 'Mine (2)' ] ) === [], 'organiser: status counts (which count everyone\'s) hidden' );

$q = new FakeQuery( [ 'post_type' => 'post' ] );
DCS_Roles::limit_list_to_own_events( $q );
ok( $q->get( 'author' ) === '', 'other post types untouched' );
$q = new FakeQuery( [ 'post_type' => 'meeting_event' ], false );
DCS_Roles::limit_list_to_own_events( $q );
ok( $q->get( 'author' ) === '', 'secondary queries untouched' );
$GLOBALS['_is_admin'] = false;
$q = new FakeQuery( [ 'post_type' => 'meeting_event' ] );
DCS_Roles::limit_list_to_own_events( $q );
ok( $q->get( 'author' ) === '', 'public site untouched (archive still lists all published events)' );
$GLOBALS['_is_admin'] = true;

as_user( 7, [ 'author' ] );
$q = new FakeQuery( [ 'post_type' => 'meeting_event' ] );
DCS_Roles::limit_list_to_own_events( $q );
ok( $q->get( 'author' ) === 7, 'author: also limited to own events' );

as_user( 1, [ 'administrator' ] );
$q = new FakeQuery( [ 'post_type' => 'meeting_event' ] );
DCS_Roles::limit_list_to_own_events( $q );
ok( $q->get( 'author' ) === '', 'administrator: sees all events' );
ok( DCS_Roles::filter_list_views( [ 'all' => 'All (9)' ] ) === [ 'all' => 'All (9)' ], 'administrator: status counts kept' );
as_user( 3, [ 'editor' ] );
$q = new FakeQuery( [ 'post_type' => 'meeting_event' ] );
DCS_Roles::limit_list_to_own_events( $q );
ok( $q->get( 'author' ) === '', 'editor: sees all events' );

// ---------------------------------------------------------------------------
section( 'D. Simplified admin for organisers' );
as_user( 42, [ DCS_Roles::ROLE ] );
$GLOBALS['_removed_menus'] = [];
DCS_Roles::simplify_menu();
ok( $GLOBALS['_removed_menus'] === [ 'index.php' ], 'organiser: Dashboard menu removed' );
ok( DCS_Roles::dashboard_redirect_url() === 'https://example.com/wp-admin/edit.php?post_type=meeting_event', 'organiser: Dashboard redirects to their events' );
$bar = new FakeAdminBar();
DCS_Roles::simplify_admin_bar( $bar );
ok( in_array( 'wp-logo', $bar->removed, true ) && in_array( 'comments', $bar->removed, true ), 'organiser: toolbar trimmed' );

$org_user = new WP_User( 42, [ DCS_Roles::ROLE ] );
ok( DCS_Roles::login_redirect( admin_url(), '', $org_user ) === DCS_Roles::events_list_url(), 'organiser: lands on events list after login' );
ok( DCS_Roles::login_redirect( 'https://example.com/wp-admin/post.php?post=5&action=edit', 'https://example.com/wp-admin/post.php?post=5&action=edit', $org_user ) === 'https://example.com/wp-admin/post.php?post=5&action=edit', 'organiser: a specific requested page is kept' );

foreach ( [ 'administrator', 'editor' ] as $r ) {
    as_user( 1, [ $r ] );
    $GLOBALS['_removed_menus'] = [];
    DCS_Roles::simplify_menu();
    $bar = new FakeAdminBar();
    DCS_Roles::simplify_admin_bar( $bar );
    ok( $GLOBALS['_removed_menus'] === [] && $bar->removed === [] && DCS_Roles::dashboard_redirect_url() === '', "$r: normal admin" );
    ok( DCS_Roles::login_redirect( admin_url(), '', new WP_User( 1, [ $r ] ) ) === admin_url(), "$r: normal login redirect" );
}

// Organiser who is also an Editor keeps the full admin.
as_user( 43, [ DCS_Roles::ROLE, 'editor' ] );
ok( ! DCS_Roles::is_organiser_only() && DCS_Roles::dashboard_redirect_url() === '', 'organiser + editor: normal admin' );
ok( DCS_Roles::login_redirect( admin_url(), '', new WP_User( 43, [ DCS_Roles::ROLE, 'editor' ] ) ) === admin_url(), 'organiser + editor: normal login redirect' );

// ---------------------------------------------------------------------------
section( 'E. Saving still requires permission on that event' );
$D1 = date( 'Y-m-d', strtotime( '+10 days' ) );
unset( $GLOBALS['_user_caps'] ); $GLOBALS['_can'] = true;
make_event( 800, 'booking', 'Org event' );
save_event( 800, [ 'dcs_meeting_mode' => 'booking', 'slots' => [ [ 'date' => $D1, 'time' => '09:00', 'duration_minutes' => '60', 'max' => '1' ] ] ] );
ok( is_array( slots( 800 ) ) && count( slots( 800 ) ) === 1, 'allowed user saves slots' );
$GLOBALS['_can'] = false;
save_event( 800, [ 'dcs_meeting_mode' => 'booking', 'slots' => [] ] );
ok( count( slots( 800 ) ) === 1, 'user without edit permission on the event changes nothing' );
$GLOBALS['_can'] = true;

finish();
