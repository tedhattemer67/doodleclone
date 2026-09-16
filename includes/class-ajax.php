<?php
/**
 * DCS Ajax
 * Handles all wp_ajax_* endpoints. Every handler verifies a nonce before processing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DCS_Ajax {

    public static function init(): void {
        add_action( 'wp_ajax_dcs_book_slot',        [ __CLASS__, 'handle_booking' ] );
        add_action( 'wp_ajax_nopriv_dcs_book_slot', [ __CLASS__, 'handle_booking' ] );
    }

    // -------------------------------------------------------------------------
    // Booking / poll vote
    // -------------------------------------------------------------------------

    public static function handle_booking(): void {
        // Security: verify nonce before touching any data
        if ( ! check_ajax_referer( 'dcs_book_slot', 'dcs_nonce', false ) ) {
            wp_send_json_error( __( 'Security check failed. Please refresh the page and try again.', 'doodle-clone-scheduler' ), 403 );
        }

        // Security: per-IP throttle so this public, unauthenticated endpoint can't
        // be scripted to flood slots or blast confirmation emails at scale. The
        // nonce alone doesn't stop this — it's valid for ~24h and reusable.
        if ( dcs_rate_limited( 'ip_' . dcs_client_ip(), 20, 300 ) ) {
            wp_send_json_error( __( 'Too many requests. Please wait a few minutes and try again.', 'doodle-clone-scheduler' ), 429 );
        }

        $event_id = intval( $_POST['event_id'] ?? 0 );
        $name     = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

        if ( ! $event_id || ! $name || ! is_email( $email ) ) {
            wp_send_json_error( __( 'Please fill in all required fields.', 'doodle-clone-scheduler' ) );
        }

        // Security: require the event to actually be published, not just the
        // right post type — otherwise a draft/scheduled/trashed event (never
        // linked anywhere, front-end form never rendered) could still be
        // booked or voted on by anyone who guesses or enumerates its ID.
        if ( get_post_type( $event_id ) !== 'meeting_event' || get_post_status( $event_id ) !== 'publish' ) {
            wp_send_json_error( __( 'Invalid event.', 'doodle-clone-scheduler' ) );
        }

        // Security: separate, tighter throttle per event+email — stops repeated
        // attempts against one target address even when spread across many IPs
        // (relevant once hijack attempts are blocked below, since an attacker
        // could otherwise still brute-force-probe a given address).
        if ( dcs_rate_limited( 'ev_' . $event_id . '_' . strtolower( $email ), 8, 600 ) ) {
            wp_send_json_error( __( 'Too many attempts for this email address. Please wait a few minutes and try again.', 'doodle-clone-scheduler' ), 429 );
        }

        $mode = get_post_meta( $event_id, '_meeting_mode', true ) ?: 'booking';

        // Block submissions to closed polls
        if ( in_array( $mode, [ 'poll', 'group' ], true ) ) {
            $status = get_post_meta( $event_id, '_poll_status', true ) ?: 'open';
            if ( $status === 'closed' ) {
                wp_send_json_error( __( 'This poll is no longer accepting responses.', 'doodle-clone-scheduler' ) );
            }
        }

        $slots = get_post_meta( $event_id, '_meeting_slots', true );
        if ( ! is_array( $slots ) ) $slots = [];

        if ( $mode === 'poll' || $mode === 'group' ) {
            self::handle_poll_vote( $event_id, $slots, $name, $email );
        } else {
            self::handle_single_booking( $event_id, $slots, $name, $email );
        }
    }

    // -------------------------------------------------------------------------
    // Private: poll vote
    // -------------------------------------------------------------------------

    private static function handle_poll_vote( int $event_id, array $slots, string $name, string $email ): void {
        $selected_keys = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['slot_ids'] ?? [] ) ) );

        if ( empty( $selected_keys ) ) {
            wp_send_json_error( __( 'Please select at least one time slot.', 'doodle-clone-scheduler' ) );
        }

        $email_lower = strtolower( $email );

        // Correctness: hold a lock across the whole read-modify-write of
        // _meeting_slots. Without it, two voters submitting close together
        // would both read the same snapshot and whichever writes second would
        // silently overwrite the first voter's selections (lost update) —
        // plausible in practice, since poll invites/reminders tend to prompt
        // a burst of near-simultaneous responses.
        $lock_key = 'poll_' . $event_id;
        if ( ! dcs_acquire_lock( $lock_key ) ) {
            wp_send_json_error( __( 'This poll is receiving a lot of responses right now. Please try again in a moment.', 'doodle-clone-scheduler' ) );
        }

        // Re-read now that we hold the lock — the copy passed in may already
        // be stale if another voter's submission was just written.
        $fresh = get_post_meta( $event_id, '_meeting_slots', true );
        $slots = is_array( $fresh ) ? $fresh : $slots;

        // Detect whether this voter already has responses — determines edit vs. first submission.
        $is_update = false;
        foreach ( $slots as $slot ) {
            if ( empty( $slot['attendees'] ) ) continue;
            foreach ( $slot['attendees'] as $a ) {
                if ( strtolower( $a['email'] ?? '' ) === $email_lower ) {
                    $is_update = true;
                    break 2;
                }
            }
        }

        // Security: an email that already has a *publicly issued* edit token can
        // only be changed by whoever holds a valid token for this exact event +
        // email. Without this, anyone who knows or guesses a voter's address
        // could silently overwrite their choices — replace-by-email semantics
        // make that a one-request takeover, not just a nuisance.
        //
        // Gate on "was a token ever issued" rather than just $is_update: an admin
        // can hand-enter attendees directly in the Slots meta box (the Voter
        // Roster shows these as "No link sent"), and that person must still be
        // able to submit their own real availability the first time without a
        // token to present — there's no prior public submission to protect yet.
        $issued_key   = '_dcs_token_issued_' . md5( dcs_normalize_email( $email ) );
        $token_issued = (bool) get_post_meta( $event_id, $issued_key, true );

        if ( $token_issued ) {
            $token_raw  = sanitize_text_field( wp_unslash( $_POST['dcs_edit_token'] ?? '' ) );
            $token      = $token_raw ? dcs_parse_edit_token( $token_raw ) : false;
            $authorized = $token
                && intval( $token['event_id'] ) === $event_id
                && strtolower( $token['email'] ) === $email_lower;

            if ( ! $authorized ) {
                dcs_release_lock( $lock_key );
                wp_send_json_error( __( 'This email address already has a response on file. Please use the edit link that was emailed to you to change your selections.', 'doodle-clone-scheduler' ), 403 );
            }
        }

        // Remove this voter from all slots (replace-by-email semantics)
        foreach ( $slots as $i => $slot ) {
            if ( empty( $slot['attendees'] ) || ! is_array( $slot['attendees'] ) ) continue;
            $slots[ $i ]['attendees'] = array_values( array_filter(
                $slot['attendees'],
                fn( $a ) => strtolower( $a['email'] ?? '' ) !== $email_lower
            ) );
        }

        // Add voter to selected slots
        foreach ( $slots as $i => &$slot ) {
            if ( in_array( dcs_slot_key( $slot ), $selected_keys, true ) ) {
                if ( ! isset( $slot['attendees'] ) || ! is_array( $slot['attendees'] ) ) {
                    $slot['attendees'] = [];
                }
                $slot['attendees'][] = [ 'name' => $name, 'email' => $email ];
            }
        }
        unset( $slot );

        update_post_meta( $event_id, '_meeting_slots', $slots );

        // Record when this voter's token was issued so the admin roster (and the
        // authorization check above, on their next submission) can tell this
        // email now has a publicly issued token.
        update_post_meta( $event_id, $issued_key, time() );

        dcs_release_lock( $lock_key );

        // Send confirmation — bypass rate-limit on edits so they always get a fresh link
        DCS_Mailer::send_poll_confirmation( $event_id, $name, $email, $is_update );

        // Return a fresh token in the response so the JS can update the page URL,
        // keeping the voter in their edit-link context without a full redirect.
        $fresh_token = dcs_make_edit_token( $event_id, $email );

        wp_send_json_success( [
            'mode'      => 'poll',
            'user'      => $name,
            'is_update' => $is_update,
            'token'     => $fresh_token,
        ] );
    }

    // -------------------------------------------------------------------------
    // Private: 1-on-1 booking
    // -------------------------------------------------------------------------

    private static function handle_single_booking( int $event_id, array $slots, string $name, string $email ): void {
        $slot_key = sanitize_text_field( wp_unslash( $_POST['slot_id'] ?? '' ) );

        // Correctness: hold a per-slot lock across the capacity check and the
        // write. Without it, two requests for the last spot in a capped slot
        // can both read the same attendee count, both pass the check, and both
        // get confirmed — a classic check-then-write race that overbooks the
        // slot. Scoped to this one slot so bookings for other slots aren't
        // blocked by it.
        $lock_key = 'book_' . $event_id . '_' . $slot_key;
        if ( ! dcs_acquire_lock( $lock_key ) ) {
            wp_send_json_error( __( 'That slot is being booked by someone else right now. Please try again in a moment.', 'doodle-clone-scheduler' ) );
        }

        // Re-read now that we hold the lock — the copy passed in may already
        // be stale if another booking was just written to this slot.
        $fresh = get_post_meta( $event_id, '_meeting_slots', true );
        $slots = is_array( $fresh ) ? $fresh : $slots;

        foreach ( $slots as &$slot ) {
            if ( dcs_slot_key( $slot ) !== $slot_key ) continue;

            $slot['attendees'] = $slot['attendees'] ?? [];
            $max               = intval( $slot['max'] ?? 1 );

            if ( count( $slot['attendees'] ) >= $max ) {
                dcs_release_lock( $lock_key );
                wp_send_json_error( __( 'Sorry, that slot is already full.', 'doodle-clone-scheduler' ) );
            }

            $slot['attendees'][] = [ 'name' => $name, 'email' => $email ];
            update_post_meta( $event_id, '_meeting_slots', $slots );
            dcs_release_lock( $lock_key );

            DCS_Mailer::send_booking_confirmation( $event_id, $slot, $name, $email );

            wp_send_json_success( [ 'slot_id' => $slot_key, 'user' => $name ] );
        }
        unset( $slot );

        dcs_release_lock( $lock_key );
        wp_send_json_error( __( 'Slot not found.', 'doodle-clone-scheduler' ) );
    }
}
