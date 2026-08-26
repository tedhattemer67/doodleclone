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

        $event_id = intval( $_POST['event_id'] ?? 0 );
        $name     = sanitize_text_field( $_POST['name'] ?? '' );
        $email    = sanitize_email( $_POST['email'] ?? '' );

        if ( ! $event_id || ! $name || ! is_email( $email ) ) {
            wp_send_json_error( __( 'Please fill in all required fields.', 'doodle-clone-scheduler' ) );
        }

        if ( get_post_type( $event_id ) !== 'meeting_event' ) {
            wp_send_json_error( __( 'Invalid event.', 'doodle-clone-scheduler' ) );
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
        $selected_labels = array_map( 'sanitize_text_field', (array) ( $_POST['slot_ids'] ?? [] ) );

        if ( empty( $selected_labels ) ) {
            wp_send_json_error( __( 'Please select at least one time slot.', 'doodle-clone-scheduler' ) );
        }

        $email_lower = strtolower( $email );

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
            if ( in_array( dcs_slot_label( $slot ), $selected_labels, true ) ) {
                if ( ! isset( $slot['attendees'] ) || ! is_array( $slot['attendees'] ) ) {
                    $slot['attendees'] = [];
                }
                $slot['attendees'][] = [ 'name' => $name, 'email' => $email ];
            }
        }
        unset( $slot );

        update_post_meta( $event_id, '_meeting_slots', $slots );

        // Record when this voter's token was issued so the admin roster can
        // show accurate expiry status without a database table.
        $issued_key = '_dcs_token_issued_' . md5( strtolower( $email ) );
        update_post_meta( $event_id, $issued_key, time() );

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
        $slot_label = sanitize_text_field( $_POST['slot_id'] ?? '' );

        foreach ( $slots as &$slot ) {
            if ( dcs_slot_label( $slot ) !== $slot_label ) continue;

            $slot['attendees'] = $slot['attendees'] ?? [];
            $max               = intval( $slot['max'] ?? 1 );

            if ( count( $slot['attendees'] ) >= $max ) {
                wp_send_json_error( __( 'Sorry, that slot is already full.', 'doodle-clone-scheduler' ) );
            }

            $slot['attendees'][] = [ 'name' => $name, 'email' => $email ];
            update_post_meta( $event_id, '_meeting_slots', $slots );

            DCS_Mailer::send_booking_confirmation( $event_id, $slot, $name, $email );

            wp_send_json_success( [ 'slot_id' => $slot_label, 'user' => $name ] );
        }
        unset( $slot );

        wp_send_json_error( __( 'Slot not found.', 'doodle-clone-scheduler' ) );
    }
}
