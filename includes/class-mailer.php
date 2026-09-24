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
            "Hi %s,\n\nYou're confirmed for \"%s\".\nTime: %s\n\n%s\n\n— %s",
            $name,
            $title,
            dcs_format_range( $start_ts, $end_ts ),
            __( 'Meeting details (location or how to join) will be sent to you before the meeting.', 'doodle-clone-scheduler' ),
            get_bloginfo( 'name' )
        );

        // Deliberately no meeting details here: join links and passcodes go
        // out only in the final-details email once registration is closed.
        // Same UID as the final-details ICS, which carries a higher SEQUENCE,
        // so importing that one updates this calendar entry in place.
        $headers  = self::plain_headers();
        $ics_path = ( $start_ts && $post ) ? dcs_build_ics( $post, $start_ts, $end_ts, dcs_slot_key( $slot ) ) : '';

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
     * Sessions / series sign-up confirmation: the person's schedule so far
     * (one line per part, including parts they're skipping) plus the magic
     * edit link. No meeting details and no .ics yet — picks can still change
     * until registration closes, and a stale calendar entry for a dropped
     * session would be worse than none. The final-details email carries both.
     *
     * @param array $chosen  The slots this person is now registered for.
     */
    public static function send_sessions_confirmation( int $event_id, string $name, string $email, array $chosen, bool $is_update = false ): void {
        $title = html_entity_decode( get_the_title( $event_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // Same throttle as poll confirmations: first submissions at most one
        // email per minute per address; edits always send a fresh link.
        if ( ! $is_update ) {
            $rate_key = 'dcs_sess_conf_' . $event_id . '_' . md5( strtolower( $email ) );
            if ( get_transient( $rate_key ) ) return;
            set_transient( $rate_key, 1, 60 );
        }

        $slots  = get_post_meta( $event_id, '_meeting_slots', true );
        $groups = dcs_group_slots_by_part( dcs_get_parts( $event_id ), is_array( $slots ) ? $slots : [] );
        $keys   = array_map( 'dcs_slot_key', $chosen );

        $lines = [];
        foreach ( $groups as $gi => $g ) {
            $pick = null;
            foreach ( $g['slots'] as $sl ) {
                if ( in_array( dcs_slot_key( $sl ), $keys, true ) ) { $pick = $sl; break; }
            }
            $when   = $pick ? dcs_slot_range( $pick ) : __( 'not attending', 'doodle-clone-scheduler' );
            $lines[] = count( $groups ) > 1 ? '- ' . dcs_part_label( $g, $gi ) . ': ' . $when : '- ' . $when;
        }

        $token = dcs_make_edit_token( $event_id, $email );
        $link  = add_query_arg( 'dcs_token', rawurlencode( $token ), get_permalink( $event_id ) );

        $subject = $is_update
            ? sprintf( __( 'Your sessions for "%s" have been updated', 'doodle-clone-scheduler' ), $title )
            : sprintf( __( 'You\'re registered: %s', 'doodle-clone-scheduler' ), $title );
        $body = sprintf(
            "Hi %s,\n\n%s\n\n%s\n\n%s\n\n%s\n%s\n\n— %s",
            $name,
            $is_update
                ? sprintf( __( 'Your sessions for "%s" have been updated. Your schedule:', 'doodle-clone-scheduler' ), $title )
                : sprintf( __( 'You\'re registered for "%s". Your schedule:', 'doodle-clone-scheduler' ), $title ),
            implode( "\n", $lines ),
            __( 'Meeting details (location or how to join) will be emailed to you once registration closes.', 'doodle-clone-scheduler' ),
            __( 'Need to change your sessions? Use this link (valid for 30 days):', 'doodle-clone-scheduler' ),
            $link,
            get_bloginfo( 'name' )
        );

        wp_mail( $email, $subject, $body, self::plain_headers() );
    }

    /**
     * Back-compat alias: the poll announcement is now the poll flavour of
     * send_final_details().
     */
    public static function send_poll_announcement( int $post_id, string $mode = 'all' ): int {
        return self::send_final_details( $post_id, $mode );
    }

    /**
     * Sends the final-details email once the organiser has closed the event.
     *
     *  - Group poll: every voter gets the chosen slot.
     *  - 1-on-1:     each attendee gets the slot(s) they are booked into.
     *
     * Each email carries the slot's time, meeting details (location / join
     * link / meeting ID / passcode / dial-in / notes), a Google Calendar link
     * and an .ics per slot. Details are read live at send time, so edits made
     * after closing go out on the next re-send.
     *
     * @param int    $post_id  The meeting event post ID.
     * @param string $mode     'all' to send to everyone, 'new' to skip anyone
     *                         already emailed by a previous send.
     * @return int             Number of emails successfully sent.
     */
    public static function send_final_details( int $post_id, string $mode = 'all' ): int {
        $post = get_post( $post_id );
        if ( ! $post ) return 0;

        $recipients = self::final_details_recipients( $post_id );
        if ( empty( $recipients ) ) return 0;

        // One SEQUENCE per send: every .ics from this send supersedes the
        // signup .ics (SEQUENCE 0) and any earlier send for the same slot.
        $sequence = intval( get_post_meta( $post_id, '_dcs_final_sequence', true ) ) + 1;
        update_post_meta( $post_id, '_dcs_final_sequence', $sequence );

        $title     = wp_strip_all_tags( html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $permalink = get_permalink( $post_id );
        $mode_meta = (string) get_post_meta( $post_id, '_meeting_mode', true );
        $is_poll   = in_array( $mode_meta, [ 'poll', 'group' ], true );
        $sessions  = dcs_is_sessions_mode( $mode_meta );
        $headers   = [ 'Content-Type: text/html; charset=UTF-8' ];

        $sent_hashes   = (array) get_post_meta( $post_id, '_poll_announce_recipient_hashes', true );
        $sent_hash_map = array_fill_keys( $sent_hashes, true );

        $ics_cache  = []; // slot key => temp .ics path, built once per slot
        $sent_count = 0;
        $sent_to    = [];

        foreach ( $recipients as $norm => $r ) {
            $hash = hash( 'sha256', $norm );
            if ( $mode === 'new' && isset( $sent_hash_map[ $hash ] ) ) continue;

            $attachments = [];
            $blocks      = '';
            $first_range = '';
            foreach ( $r['slots'] as $slot ) {
                [ $start, $end ] = dcs_slot_times( $slot );
                if ( ! $start ) continue;
                $key     = dcs_slot_key( $slot );
                $details = dcs_slot_details( $post_id, $slot );
                $range   = dcs_format_range( $start, $end );
                if ( $first_range === '' ) $first_range = $range;

                if ( ! isset( $ics_cache[ $key ] ) ) {
                    $ics_cache[ $key ] = dcs_build_ics( $post, $start, $end, $key, $details, $sequence );
                }
                if ( $ics_cache[ $key ] ) $attachments[] = $ics_cache[ $key ];

                $blocks .= self::slot_block_html( $range, $details, dcs_google_calendar_url( $post, $start, $end, $details ), dcs_slot_part_title( $post_id, $slot ) );
            }
            if ( $blocks === '' ) continue;

            if ( $sessions ) {
                $subject = sprintf( __( 'Your schedule: %s', 'doodle-clone-scheduler' ), $title );
                $intro   = sprintf(
                    /* translators: %s: event title */
                    __( 'Registration for %s is closed. Here is your schedule with the details for each session:', 'doodle-clone-scheduler' ),
                    '<strong>' . esc_html( $title ) . '</strong>'
                );
            } elseif ( $is_poll ) {
                $subject = sprintf( __( '%1$s scheduled for %2$s', 'doodle-clone-scheduler' ), $title, $first_range );
                $intro   = sprintf(
                    /* translators: %s: event title */
                    __( '%s has been scheduled. Here are the details:', 'doodle-clone-scheduler' ),
                    '<strong>' . esc_html( $title ) . '</strong>'
                );
            } else {
                $subject = sprintf( __( 'Meeting details: %s', 'doodle-clone-scheduler' ), $title );
                $intro   = sprintf(
                    /* translators: %s: event title */
                    __( 'Here are the final details for %s:', 'doodle-clone-scheduler' ),
                    '<strong>' . esc_html( $title ) . '</strong>'
                );
            }

            $greeting = $r['name'] !== ''
                ? sprintf( __( 'Hi %s,', 'doodle-clone-scheduler' ), esc_html( $r['name'] ) )
                : esc_html__( 'Hello,', 'doodle-clone-scheduler' );

            $body = '<p>' . $greeting . '</p>'
                . '<p>' . $intro . '</p>'
                . $blocks
                . '<p><a href="' . esc_url( $permalink ) . '">' . esc_html__( 'View event page', 'doodle-clone-scheduler' ) . '</a></p>'
                . '<p>— ' . esc_html( get_bloginfo( 'name' ) ) . '</p>';

            $ok = wp_mail( $norm, $subject, $body, $headers, $attachments );
            if ( $ok ) {
                $sent_count++;
                $sent_hash_map[ $hash ] = true;
                $sent_to[] = $norm;
            }
        }

        update_post_meta( $post_id, '_poll_announce_sent_at', time() );
        update_post_meta( $post_id, '_poll_announce_count', $sent_count );
        update_post_meta( $post_id, '_poll_announce_recipient_hashes', array_keys( $sent_hash_map ) );

        foreach ( $ics_cache as $path ) {
            if ( $path && file_exists( $path ) ) @unlink( $path );
        }

        if ( $sent_count > 0 ) {
            self::send_final_details_admin_summary( $post_id, $title, $recipients, $sent_to );
        }

        return $sent_count;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns [ normalised email => [ 'name' => string, 'slots' => slot[] ] ]
     * for everyone who should get the final-details email.
     */
    private static function final_details_recipients( int $post_id ): array {
        $slots = get_post_meta( $post_id, '_meeting_slots', true );
        if ( ! is_array( $slots ) ) return [];

        $mode = get_post_meta( $post_id, '_meeting_mode', true ) ?: 'booking';
        $out  = [];

        if ( in_array( $mode, [ 'poll', 'group' ], true ) ) {
            $winner = self::poll_winner_slot( $post_id, $slots );
            if ( ! $winner ) return [];
            // Every voter is told the final time, whether or not they voted for it.
            foreach ( $slots as $slot ) {
                foreach ( (array) ( $slot['attendees'] ?? [] ) as $a ) {
                    $norm = dcs_normalize_email( is_array( $a ) ? ( $a['email'] ?? '' ) : (string) $a );
                    if ( ! $norm ) continue;
                    if ( ! isset( $out[ $norm ] ) ) $out[ $norm ] = [ 'name' => '', 'slots' => [ $winner ] ];
                    if ( $out[ $norm ]['name'] === '' && is_array( $a ) && ! empty( $a['name'] ) ) {
                        $out[ $norm ]['name'] = $a['name'];
                    }
                }
            }
            return $out;
        }

        // 1-on-1: each attendee gets their own slot(s). Normally one, but an
        // admin can hand-enter the same person into several.
        foreach ( $slots as $slot ) {
            foreach ( (array) ( $slot['attendees'] ?? [] ) as $a ) {
                if ( ! is_array( $a ) ) continue;
                $norm = dcs_normalize_email( $a['email'] ?? '' );
                if ( ! $norm ) continue;
                if ( ! isset( $out[ $norm ] ) ) $out[ $norm ] = [ 'name' => '', 'slots' => [] ];
                if ( $out[ $norm ]['name'] === '' && ! empty( $a['name'] ) ) $out[ $norm ]['name'] = $a['name'];
                $out[ $norm ]['slots'][] = $slot;
            }
        }
        foreach ( $out as &$r ) {
            usort( $r['slots'], fn( $x, $y ) => intval( $x['start'] ?? 0 ) <=> intval( $y['start'] ?? 0 ) );
        }
        unset( $r );
        return $out;
    }

    /**
     * Resolves the chosen poll slot from the live slot list (so details and
     * time edits made after closing are honoured), falling back to the
     * snapshot taken at close time if the slot has since been removed.
     */
    private static function poll_winner_slot( int $post_id, array $slots ): ?array {
        $id = (string) get_post_meta( $post_id, '_poll_selected_slot_id', true );
        if ( $id !== '' ) {
            foreach ( $slots as $slot ) {
                if ( dcs_slot_key( $slot ) === $id ) return $slot;
            }
        }
        $snap = get_post_meta( $post_id, '_poll_selected_slot_snapshot', true );
        return ( is_array( $snap ) && ! empty( $snap['start'] ) ) ? $snap : null;
    }

    /**
     * HTML block for one slot: optional part heading, time, details, optional
     * calendar link. All values escaped.
     */
    private static function slot_block_html( string $range, array $d, string $google_url, string $heading = '' ): string {
        $formats = dcs_meeting_formats();
        $format  = $d['format'] ?? 'unspecified';
        $rows    = [];
        $html    = $heading !== '' ? '<h3 style="margin:18px 0 6px;">' . esc_html( $heading ) . '</h3>' : '';

        $rows[] = [ __( 'When', 'doodle-clone-scheduler' ), '<strong>' . esc_html( $range ) . '</strong>' ];
        if ( $format !== 'unspecified' && isset( $formats[ $format ] ) ) {
            $rows[] = [ __( 'Format', 'doodle-clone-scheduler' ), esc_html( $formats[ $format ] ) ];
        }
        if ( ( $d['location'] ?? '' ) !== '' ) {
            $rows[] = [ __( 'Location', 'doodle-clone-scheduler' ), esc_html( $d['location'] ) ];
        }
        if ( ( $d['url'] ?? '' ) !== '' ) {
            $rows[] = [ __( 'Join online', 'doodle-clone-scheduler' ), '<a href="' . esc_url( $d['url'] ) . '">' . esc_html( $d['url'] ) . '</a>' ];
        }
        if ( ( $d['meeting_id'] ?? '' ) !== '' ) {
            $rows[] = [ __( 'Meeting ID', 'doodle-clone-scheduler' ), esc_html( $d['meeting_id'] ) ];
        }
        if ( ( $d['passcode'] ?? '' ) !== '' ) {
            $rows[] = [ __( 'Passcode', 'doodle-clone-scheduler' ), esc_html( $d['passcode'] ) ];
        }
        if ( ( $d['dial_in'] ?? '' ) !== '' ) {
            $rows[] = [ __( 'Dial-in', 'doodle-clone-scheduler' ), nl2br( esc_html( $d['dial_in'] ) ) ];
        }
        if ( ( $d['notes'] ?? '' ) !== '' ) {
            $rows[] = [ __( 'Notes', 'doodle-clone-scheduler' ), nl2br( esc_html( $d['notes'] ) ) ];
        }

        $html .= '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;border:1px solid #dcdcde;margin:0 0 12px;">';
        foreach ( $rows as [ $label, $value ] ) {
            $html .= '<tr><th align="left" valign="top" style="border-bottom:1px solid #f0f0f1;padding-right:14px;white-space:nowrap;">'
                . esc_html( $label ) . '</th><td valign="top" style="border-bottom:1px solid #f0f0f1;">' . $value . '</td></tr>';
        }
        $html .= '</table>';
        if ( $google_url !== '' ) {
            $html .= '<p style="margin:0 0 18px;"><a href="' . esc_url( $google_url ) . '">'
                . esc_html__( 'Add to Google Calendar', 'doodle-clone-scheduler' ) . '</a></p>';
        }
        return $html;
    }

    /** One summary email to the site admin per send: each slot, its details, who was emailed. */
    private static function send_final_details_admin_summary( int $post_id, string $title, array $recipients, array $sent_to ): void {
        $by_slot = [];
        foreach ( $recipients as $norm => $r ) {
            foreach ( $r['slots'] as $slot ) {
                $key = dcs_slot_key( $slot );
                if ( ! isset( $by_slot[ $key ] ) ) $by_slot[ $key ] = [ 'slot' => $slot, 'people' => [] ];
                $label = ( $r['name'] !== '' ? $r['name'] . ' ' : '' ) . '<' . $norm . '>';
                if ( in_array( $norm, $sent_to, true ) ) $label .= ' ' . __( '(emailed now)', 'doodle-clone-scheduler' );
                $by_slot[ $key ]['people'][] = $label;
            }
        }

        $body = '<p>' . sprintf(
            /* translators: 1: number of emails, 2: event title */
            esc_html__( 'Final details were sent to %1$d recipient(s) for %2$s.', 'doodle-clone-scheduler' ),
            count( $sent_to ),
            '<strong>' . esc_html( $title ) . '</strong>'
        ) . '</p>';

        foreach ( $by_slot as $entry ) {
            [ $start, $end ] = dcs_slot_times( $entry['slot'] );
            $details = dcs_slot_details( $post_id, $entry['slot'] );
            $body   .= self::slot_block_html( dcs_format_range( $start, $end ), $details, '', dcs_slot_part_title( $post_id, $entry['slot'] ) );
            $body   .= '<p style="margin:0 0 20px;"><em>' . esc_html__( 'Attendees:', 'doodle-clone-scheduler' ) . '</em><br>'
                . implode( '<br>', array_map( 'esc_html', $entry['people'] ) ) . '</p>';
        }

        wp_mail(
            get_option( 'admin_email' ),
            sprintf( __( 'Final details sent: %s', 'doodle-clone-scheduler' ), $title ),
            $body,
            [ 'Content-Type: text/html; charset=UTF-8' ]
        );
    }

    private static function plain_headers(): array {
        return [ 'Content-Type: text/plain; charset=UTF-8' ];
    }
}
