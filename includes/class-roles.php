<?php
/**
 * Meeting Organiser role (2.4.0).
 *
 * Meeting Events have their own capabilities (edit_meeting_events, …) instead
 * of sharing the blog's post capabilities, so a user can be allowed to run
 * meetings without being able to touch posts, pages or anything else.
 *
 * - Existing roles keep exactly the access they had: on upgrade each role gets
 *   the meeting-event version of every post capability it already holds.
 * - The new "Meeting Organiser" role can create, edit, publish, close/send and
 *   delete its OWN events only.
 * - Anyone who can't edit other people's events sees only their own in the
 *   Meeting Events list.
 * - Organisers get a stripped-down wp-admin: no Dashboard, they land on their
 *   events list, and the toolbar loses the WordPress/comments menus.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DCS_Roles {

    const ROLE = 'dcs_organizer';

    /** Post capability => Meeting Event capability. */
    const CAP_MAP = [
        'edit_posts'             => 'edit_meeting_events',
        'edit_others_posts'      => 'edit_others_meeting_events',
        'edit_published_posts'   => 'edit_published_meeting_events',
        'edit_private_posts'     => 'edit_private_meeting_events',
        'publish_posts'          => 'publish_meeting_events',
        'read_private_posts'     => 'read_private_meeting_events',
        'delete_posts'           => 'delete_meeting_events',
        'delete_others_posts'    => 'delete_others_meeting_events',
        'delete_published_posts' => 'delete_published_meeting_events',
        'delete_private_posts'   => 'delete_private_meeting_events',
    ];

    /** What a Meeting Organiser can do: everything, but only on their own events. */
    const ORGANISER_CAPS = [
        'read',
        'edit_meeting_events',
        'edit_published_meeting_events',
        'publish_meeting_events',
        'delete_meeting_events',
        'delete_published_meeting_events',
    ];

    public static function init(): void {
        add_action( 'pre_get_posts',                 [ __CLASS__, 'limit_list_to_own_events' ] );
        add_filter( 'views_edit-meeting_event',      [ __CLASS__, 'filter_list_views' ] );
        add_action( 'admin_menu',                    [ __CLASS__, 'simplify_menu' ], 999 );
        add_action( 'load-index.php',                [ __CLASS__, 'redirect_dashboard' ] );
        add_filter( 'login_redirect',                [ __CLASS__, 'login_redirect' ], 10, 3 );
        add_action( 'admin_bar_menu',                [ __CLASS__, 'simplify_admin_bar' ], 999 );
    }

    /**
     * Creates the role and grants the meeting-event capabilities. Idempotent;
     * run from dcs_maybe_upgrade().
     */
    public static function install(): void {
        foreach ( wp_roles()->role_objects as $role ) {
            foreach ( self::CAP_MAP as $post_cap => $event_cap ) {
                if ( $role->has_cap( $post_cap ) ) $role->add_cap( $event_cap );
            }
        }

        $organiser = get_role( self::ROLE );
        if ( ! $organiser ) {
            $organiser = add_role( self::ROLE, __( 'Meeting Organiser', 'doodle-clone-scheduler' ), [] );
        }
        if ( $organiser ) {
            foreach ( self::ORGANISER_CAPS as $cap ) $organiser->add_cap( $cap );
        }
    }

    /** May the current user see and edit everyone's events? */
    public static function sees_all_events(): bool {
        return current_user_can( 'edit_others_meeting_events' );
    }

    /**
     * True for users whose only job here is running meetings: they hold the
     * organiser role and can't edit blog posts. They get the simplified admin.
     * Someone who is an organiser AND e.g. an Editor keeps the normal admin.
     */
    public static function is_organiser_only(): bool {
        $user = wp_get_current_user();
        return $user && in_array( self::ROLE, (array) $user->roles, true )
            && ! current_user_can( 'edit_posts' );
    }

    /** Meeting Events list: show only your own events unless you may edit others'. */
    public static function limit_list_to_own_events( $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) return;
        if ( $query->get( 'post_type' ) !== 'meeting_event' ) return;
        if ( self::sees_all_events() ) return;
        $query->set( 'author', get_current_user_id() );
    }

    /**
     * The All / Published / Draft links above the list count everyone's events,
     * which would leak how many events other people have. Drop them; the list
     * itself already shows only your own.
     */
    public static function filter_list_views( array $views ): array {
        return self::sees_all_events() ? $views : [];
    }

    public static function simplify_menu(): void {
        if ( ! self::is_organiser_only() ) return;
        remove_menu_page( 'index.php' );
    }

    public static function events_list_url(): string {
        return admin_url( 'edit.php?post_type=meeting_event' );
    }

    /** Where an organiser opening the Dashboard should go instead, or '' to stay. */
    public static function dashboard_redirect_url(): string {
        return self::is_organiser_only() ? self::events_list_url() : '';
    }

    public static function redirect_dashboard(): void {
        $url = self::dashboard_redirect_url();
        if ( $url === '' ) return;
        wp_safe_redirect( $url );
        exit;
    }

    /** Send organisers to their events after login, unless they were headed somewhere specific. */
    public static function login_redirect( $redirect_to, $requested, $user ) {
        if ( ! ( $user instanceof WP_User ) ) return $redirect_to;
        if ( ! in_array( self::ROLE, (array) $user->roles, true ) || user_can( $user, 'edit_posts' ) ) return $redirect_to;
        $default = untrailingslashit( admin_url() );
        if ( $requested === '' || untrailingslashit( (string) $redirect_to ) === $default ) {
            return self::events_list_url();
        }
        return $redirect_to;
    }

    public static function simplify_admin_bar( $bar ): void {
        if ( ! self::is_organiser_only() ) return;
        foreach ( [ 'wp-logo', 'comments', 'updates', 'dashboard' ] as $node ) {
            $bar->remove_node( $node );
        }
    }
}
