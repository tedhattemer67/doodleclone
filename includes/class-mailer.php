<?php
/**
 * DCS Mailer
 * All outbound email logic lives here. No hooks registered — called explicitly.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DCS_Mailer {

    /**
     * Sends a booking confirmation with an ICS attachment to the attendee,
     * and a plain notification to the site admin.
     */
    public static function send_booking_confirmation( int $event_id, array $slot, string $name, string $email ): void {
        $post      = get_post( $event_id );
        $title     = html_entity_decode( get_the_title( $event_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $start_ts  = isset( $slot['start'] ) ? intval( $slot['start'] ) : 0;
        $end_ts    = isset( $slot['end'] )   ? intval( $slot['end'] )   : $start_ts + 1800;

        $subject = sprintf( __( 'Your meeting is confirmed: %s', 'doodle-clone-scheduler' ), $title );
        $body    = sprintf(
            "Hi %s,\n\nYou're confirmed for \"%s\".\nTime: %s\n\n— %s",
            $name,
            $title,
            dcs_format_range( $start_ts, $end_ts ),
            get_bloginfo( 'name' )
        );

        $headers  = self::plain_headers();
        $ics_path = ( $start_ts && $post ) ? dcs_build_ics( $post, $start_ts, $end_ts ) : '';

        wp_mail( $email, $subject, $body, $headers, $ics_path ? [ $ics_path ] : [] );

        // Admin notification
        wp_mail(
            get_option( 'admin_email' ),
            sprintf( __( 'New booking: %s', 'doodle-clone-scheduler' ), $title ),
            sprintf( '%s (%s) booked %s', $name, $email, dcs_format_range( $start_ts, $end_ts ) ),
            $headers
        );

        if ( $ics_path && file_exists( $ics_path ) ) {
            @unlink( $ics_path );
        }
    }

    /**
     * Sends a poll vote confirmation with a magic edit link so the voter
     * can return and update their choices before the poll closes.
     *
     * @param bool $is_update  True when the voter is editing existing choices.
     *                         Skips the rate-limit so they always receive a fresh link.
     */
    public static function send_poll_confirmation( int $event_id, string $name, string $email, bool $is_update = false ): void {
        $title = html_entity_decode( get_the_title( $event_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // Rate-limit first-time submissions to one email per 60 seconds.
        // Always send on explicit edits so the voter always has a working link.
        if ( ! $is_update ) {
            $rate_key = 'dcs_poll_conf_' . $event_id . '_' . md5( strtolower( $email ) );
            if ( get_transient( $rate_key ) ) return;
            set_transient( $rate_key, 1, 60 );
        }

        $token = dcs_make_edit_token( $event_id, $email );
        $link  = add_query_arg( 'dcs_token', rawurlencode( $token ), get_permalink( $event_id ) );

        if ( $is_update ) {
            $subject = sprintf( __( 'Your availability for "%s" has been updated', 'doodle-clone-scheduler' ), $title );
            $body    = sprintf(
                "Hi %s,\n\nYour availability for \"%s\" has been updated.\n\nNeed to change your selections again? Use this link (valid for 30 days):\n%s\n\n— %s",
                $name,
                $title,
                $link,
                get_bloginfo( 'name' )
            );
        } else {
            $subject = sprintf( __( 'Your availability for "%s" has been recorded', 'doodle-clone-scheduler' ), $title );
            $body    = sprintf(
                "Hi %s,\n\nThanks for submitting your availability for \"%s\".\n\nNeed to change your selections? Use this link (valid for 30 days):\n%s\n\n— %s",
                $name,
                $title,
                $link,
                get_bloginfo( 'name' )
            );
        }

        wp_mail( $email, $subject, $body, self::plain_headers() );

        // Note: the "token issued at" timestamp that drives the admin voter
        // roster's expiry column is written by DCS_Ajax::handle_poll_vote()
        // under the _dcs_token_issued_<md5(normalised email)> key. It is
        // recorded there because that path always runs, even when this mail
        // is skipped by the rate limiter.
    }

    /**
     * Sends the poll announcement to all voters once the admin closes the poll
     * and selects a winning time slot.
     *
     * @param int    $post_id   The meeting event post ID.
     * @param string $mode      'all' to send to everyone, 'new' to skip previously emailed voters.
     * @return int              Number of emails successfully sent.
     */
    public static function send_poll_announcement( int $post_id, string $mode = 'all' ): int {
        $snap = get_post_meta( $post_id, '_poll_selected_slot_snapshot', true );
        if ( ! is_array( $snap ) || empty( $snap['start'] ) ) return 0;

        $start_ts = intval( $snap['start'] );
        $end_ts   = isset( $snap['end'] ) ? intval( $snap['end'] ) : 0;

        // Recompute from stored ET labels to avoid server-timezone drift
        if ( ! empty( $snap['date'] ) && ! empty( $snap['time'] ) ) {
            $recomputed = dcs_epoch_from_local( $snap['date'], $snap['time'] );
            if ( $recomputed ) {
                $start_ts = $recomputed;
                if ( ! empty( $snap['duration_minutes'] ) ) {
                    $end_ts = $start_ts + intval( $snap['duration_minutes'] ) * 60;
                }
            }
        }
        if ( ! $end_ts ) $end_ts = $start_ts; // fallback — no blank DTEnd in ICS

        $post      = get_post( $post_id );
        $title_raw = get_the_title( $post_id );
        $title     = wp_strip_all_tags( html_entity_decode( $title_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $permalink = get_permalink( $post_id );
        $google    = dcs_google_calendar_url( $post, $start_ts, $end_ts );
        $ics_path  = dcs_build_ics( $post, $start_ts, $end_ts );
        $range     = dcs_format_range( $start_ts, $end_ts );

        $subject = sprintf( '%s scheduled for %s', $title, $range );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        $all_recipients  = dcs_collect_voter_emails( $post_id );
        $sent_hashes     = (array) get_post_meta( $post_id, '_poll_announce_recipient_hashes', true );
        $sent_hash_map   = array_fill_keys( $sent_hashes, true );

        $sent_count = 0;
        foreach ( $all_recipients as $em ) {
            $norm = dcs_normalize_email( $em );
            if ( ! $norm ) continue;
            $hash = hash( 'sha256', $norm );
            if ( $mode === 'new' && isset( $sent_hash_map[ $hash ] ) ) continue;

            $body = '<p>Hello,</p>'
                . '<p><strong>' . esc_html( $title ) . '</strong> has been scheduled for <strong>'
                . esc_html( $range ) . '</strong>.</p>'
                . '<p>'
                . '<a href="' . esc_url( $google ) . '">Add to Google Calendar</a> &middot; '
                . '<a href="' . esc_url( $permalink ) . '">View details</a>'
                . '</p>';

            $ok = wp_mail( $norm, $subject, $body, $headers, $ics_path ? [ $ics_path ] : [] );
            if ( $ok ) {
                $sent_count++;
                $sent_hash_map[ $hash ] = true;
            }
        }

        update_post_meta( $post_id, '_poll_announce_sent_at', time() );
        update_post_meta( $post_id, '_poll_announce_count', $sent_count );
        update_post_meta( $post_id, '_poll_announce_recipient_hashes', array_keys( $sent_hash_map ) );

        if ( $ics_path && file_exists( $ics_path ) ) {
            @unlink( $ics_path );
        }

        return $sent_count;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function plain_headers(): array {
        return [ 'Content-Type: text/plain; charset=UTF-8' ];
    }
}
