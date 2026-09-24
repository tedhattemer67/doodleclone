<?php
/**
 * DCS Frontend
 * Injects the booking/poll form into meeting event single posts.
 * Handles magic edit-link token resolution.
 *
 * Security: nothing in this file may read _meeting_details or call
 * dcs_slot_details() / dcs_meeting_details_plaintext(). Join links,
 * passcodes and dial-in numbers are only ever emailed (DCS_Mailer), never
 * rendered on the public page — anyone with the event URL can see it.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DCS_Frontend {

    public static function init(): void {
        add_filter( 'the_content',         [ __CLASS__, 'inject_form' ], 20 );
        add_action( 'wp_enqueue_scripts',  [ __CLASS__, 'enqueue_assets' ] );
        add_shortcode( 'doodle_schedule_overview', [ __CLASS__, 'render_overview_shortcode' ] );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public static function enqueue_assets(): void {
        if ( ! is_singular( 'meeting_event' ) ) return;
        wp_enqueue_script( 'jquery' );
        wp_enqueue_script(
            'dcs-frontend',
            DCS_PLUGIN_URL . 'script.js',
            [ 'jquery' ],
            DCS_VERSION,
            true
        );
        wp_localize_script( 'dcs-frontend', 'dcs_ajax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dcs_book_slot' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Form injection
    // -------------------------------------------------------------------------

    public static function inject_form( string $content ): string {
        if ( ! is_singular( 'meeting_event' ) || ! in_the_loop() ) return $content;

        $post_id = get_the_ID();
        $mode    = get_post_meta( $post_id, '_meeting_mode', true ) ?: 'booking';
        $slots   = get_post_meta( $post_id, '_meeting_slots', true );
        if ( ! is_array( $slots ) || empty( $slots ) ) {
            return $content . '<p>' . esc_html__( 'No time slots are available yet.', 'doodle-clone-scheduler' ) . '</p>';
        }

        $is_poll   = in_array( $mode, [ 'poll', 'group' ], true );
        $is_closed = dcs_event_is_closed( $post_id );

        // Closed poll — show notice and winning time if selected
        if ( $is_closed && $is_poll ) {
            return self::render_closed_notice( $post_id ) . $content;
        }

        // Closed 1-on-1 registration — no form
        if ( $is_closed ) {
            return $content
                . '<div class="dcs-registration-closed" style="padding:16px 18px;border:1px solid #ccd0d4;background:#f6f7f7;border-radius:6px;margin:12px 0;">'
                . '<p style="margin:0;"><strong>' . esc_html__( 'Registration for this event is closed.', 'doodle-clone-scheduler' ) . '</strong> '
                . esc_html__( 'If you registered, your final meeting details have been or will be sent to you by email.', 'doodle-clone-scheduler' )
                . '</p></div>';
        }

        // Check for an expired token on an open poll — show a clear message rather
        // than silently rendering a blank un-prefilled form, which would confuse voters
        // who clicked a stale link expecting to see their previous choices.
        if ( ! empty( $_GET['dcs_token'] ) ) {
            $raw    = sanitize_text_field( $_GET['dcs_token'] );
            $result = dcs_decode_edit_token( $raw );
            if ( $result['state'] === 'expired' && intval( $result['data']['event_id'] ) === $post_id ) {
                $notice = self::render_expired_token_notice();
                return $content . $notice . self::render_form( $post_id, $slots, $is_poll, [] );
            }
        }

        // Resolve prefill data from a valid magic edit-link token
        $prefill = self::resolve_prefill( $post_id, $slots );

        // Success notice after redirect
        $notice = self::render_success_notice( $slots );

        return $content . $notice . self::render_form( $post_id, $slots, $is_poll, $prefill );
    }

    // -------------------------------------------------------------------------
    // Closed poll notice
    // -------------------------------------------------------------------------

    private static function render_closed_notice( int $post_id ): string {
        $snap  = get_post_meta( $post_id, '_poll_selected_slot_snapshot', true );
        $slots = get_post_meta( $post_id, '_meeting_slots', true );
        if ( ! is_array( $slots ) ) $slots = [];

        // Resolve voter identity from a valid edit-link token if present
        $voter = self::resolve_voter_from_token( $post_id, $slots );

        ob_start();
        echo '<div class="dcs-poll-closed" style="padding:16px 18px;border:1px solid #ccd0d4;background:#f6f7f7;border-radius:6px;margin:12px 0;">';

        // --- Winning time block ---
        echo '<p style="margin:0 0 10px;">';
        echo '<strong>' . esc_html__( 'This poll is now closed.', 'doodle-clone-scheduler' ) . '</strong>';
        if ( is_array( $snap ) && ! empty( $snap['start'] ) ) {
            $start = intval( $snap['start'] );
            $end   = isset( $snap['end'] ) ? intval( $snap['end'] ) : 0;
            echo ' ' . esc_html__( 'The meeting has been scheduled for:', 'doodle-clone-scheduler' );
            echo '</p>';
            echo '<p style="margin:0 0 10px;font-size:1.05em;">'
                . '<strong>' . esc_html( dcs_format_range( $start, $end ) ) . '</strong>';

            // Google Calendar and ICS links for the winner
            $post       = get_post( $post_id );
            $google_url = dcs_google_calendar_url( $post, $start, $end );
            echo ' &nbsp;<a href="' . esc_url( $google_url ) . '" target="_blank" rel="noopener noreferrer" style="font-size:0.9em;">'
                . esc_html__( 'Add to Google Calendar', 'doodle-clone-scheduler' )
                . '</a>';
            echo '</p>';
        } else {
            echo ' ' . esc_html__( 'The organiser has finalised the time — thank you for voting.', 'doodle-clone-scheduler' );
            echo '</p>';
        }

        // --- Personalised voter summary (only when a valid token is present) ---
        if ( $voter !== null ) {
            echo '<hr style="border:none;border-top:1px solid #dcdcde;margin:12px 0;">';

            // Expired token — show a clear explanation rather than a blank section
            if ( $voter['expired'] ) {
                $admin_email = antispambot( get_option( 'admin_email' ) );
                echo '<p style="margin:0;color:#646970;">'
                    . esc_html__( 'The edit link you used has expired (links are valid for 30 days).', 'doodle-clone-scheduler' )
                    . ' '
                    . sprintf(
                        /* translators: %s: admin email address */
                        esc_html__( 'If you need to verify your selections, please contact %s.', 'doodle-clone-scheduler' ),
                        '<a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a>'
                    )
                    . '</p>';
                echo '</div>';
                return ob_get_clean();
            }

            // Valid token — show personalised vote summary
            if ( ! empty( $voter['name'] ) ) {
                echo '<p style="margin:0 0 8px;">'
                    . sprintf(
                        esc_html__( 'Hi %s — here\'s what you voted for:', 'doodle-clone-scheduler' ),
                        '<strong>' . esc_html( $voter['name'] ) . '</strong>'
                    )
                    . '</p>';
            } else {
                echo '<p style="margin:0 0 8px;"><strong>' . esc_html__( 'Your selections:', 'doodle-clone-scheduler' ) . '</strong></p>';
            }

            if ( ! empty( $voter['slot_keys'] ) ) {
                // Resolve full slot data for each slot the voter selected
                $selected_slots = self::slots_for_keys( $slots, $voter['slot_keys'] );
                usort( $selected_slots, fn( $a, $b ) => ( $a['start'] ?? 0 ) - ( $b['start'] ?? 0 ) );

                // Find the winning slot so we can highlight it
                $winning_start = ( is_array( $snap ) && ! empty( $snap['start'] ) ) ? intval( $snap['start'] ) : 0;

                echo '<ul style="margin:0 0 0 1.2em;padding:0;">';
                foreach ( $selected_slots as $slot ) {
                    $is_winner = $winning_start && intval( $slot['start'] ?? 0 ) === $winning_start;
                    $style     = $is_winner ? 'color:#1d7914;font-weight:bold;' : 'color:#3c434a;';
                    $suffix    = $is_winner
                        ? ' &nbsp;<span style="font-size:0.85em;">✓ ' . esc_html__( 'Selected time', 'doodle-clone-scheduler' ) . '</span>'
                        : '';
                    echo '<li style="' . $style . 'margin-bottom:3px;">'
                        . esc_html( dcs_slot_range( $slot ) )
                        . $suffix
                        . '</li>';
                }
                echo '</ul>';
            } else {
                echo '<p style="margin:0;color:#646970;font-style:italic;">'
                    . esc_html__( 'No selections were recorded for your email address.', 'doodle-clone-scheduler' )
                    . '</p>';
            }
        }

        echo '</div>';
        return ob_get_clean();
    }

    /**
     * Resolves voter identity from the dcs_token query parameter.
     *
     * Returns one of:
     *   null                          — no token in URL, or token is for a different event
     *   [ 'expired' => true, ... ]    — signature valid but TTL elapsed
     *   [ 'expired' => false, ... ]   — valid token with email, name, slot_keys
     */
    private static function resolve_voter_from_token( int $post_id, array $slots ): ?array {
        if ( empty( $_GET['dcs_token'] ) ) return null;

        $raw    = sanitize_text_field( $_GET['dcs_token'] );
        $result = dcs_decode_edit_token( $raw );

        // Completely invalid or tampered — treat as if no token was present
        if ( $result['state'] === 'invalid' ) return null;

        $data = $result['data'];

        // Token is for a different event — ignore it
        if ( intval( $data['event_id'] ) !== $post_id ) return null;

        // Token is expired but was legitimately issued for this event
        if ( $result['state'] === 'expired' ) {
            return [ 'expired' => true, 'email' => $data['email'], 'name' => '', 'slot_keys' => [] ];
        }

        // Valid token — look up the voter's selections
        $email = $data['email'];
        $name  = '';
        $keys  = [];

        foreach ( $slots as $slot ) {
            if ( empty( $slot['attendees'] ) ) continue;
            foreach ( $slot['attendees'] as $a ) {
                if ( strtolower( $a['email'] ?? '' ) !== $email ) continue;
                if ( ! empty( $a['name'] ) && $name === '' ) {
                    $name = $a['name'];
                }
                $keys[] = dcs_slot_key( $slot );
            }
        }

        return [ 'expired' => false, 'email' => $email, 'name' => $name, 'slot_keys' => $keys ];
    }

    /**
     * Returns the subset of $slots whose identity key matches any entry in $keys.
     */
    private static function slots_for_keys( array $slots, array $keys ): array {
        return array_values( array_filter(
            $slots,
            fn( $slot ) => in_array( dcs_slot_key( $slot ), $keys, true )
        ) );
    }

    // -------------------------------------------------------------------------
    // Expired token notice (open poll)
    // -------------------------------------------------------------------------

    private static function render_expired_token_notice(): string {
        $admin_email = antispambot( get_option( 'admin_email' ) );
        return '<div class="dcs-token-expired" style="padding:12px 14px;border:1px solid #f0b849;background:#fef9ef;border-radius:6px;margin:0 0 16px;">'
            . '<strong>' . esc_html__( 'Your edit link has expired.', 'doodle-clone-scheduler' ) . '</strong> '
            . esc_html__( 'Edit links are valid for 30 days. Your previous selections are no longer pre-filled, but you can submit your availability again using the form below.', 'doodle-clone-scheduler' )
            . ' '
            . sprintf(
                /* translators: %s: admin email address */
                esc_html__( 'If you need help, contact %s.', 'doodle-clone-scheduler' ),
                '<a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a>'
            )
            . '</div>';
    }

    // -------------------------------------------------------------------------
    // Post-booking success notice (query-string redirect)
    // -------------------------------------------------------------------------

    private static function render_success_notice( array $slots ): string {
        if ( empty( $_GET['booking_success'] ) || empty( $_GET['name'] ) ) return '';
        $slot_key = sanitize_text_field( wp_unslash( $_GET['booking_success'] ) );
        $user     = sanitize_text_field( wp_unslash( $_GET['name'] ) );
        foreach ( $slots as $slot ) {
            if ( dcs_slot_key( $slot ) !== $slot_key ) continue;
            $range = dcs_slot_range( $slot );
            return '<div class="notice notice-success" style="padding:10px;margin-bottom:1em;">'
                . sprintf(
                    esc_html__( 'Thanks %1$s — you are booked for %2$s.', 'doodle-clone-scheduler' ),
                    esc_html( $user ),
                    '<strong>' . esc_html( $range ) . '</strong>'
                )
                . '</div>';
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // Token-based prefill
    // -------------------------------------------------------------------------

    /**
     * If a valid dcs_token is in the query string, return prefill data
     * (email, name, and array of previously selected slot keys).
     */
    private static function resolve_prefill( int $post_id, array $slots ): array {
        $prefill = [ 'email' => '', 'name' => '', 'slot_keys' => [] ];
        if ( empty( $_GET['dcs_token'] ) ) return $prefill;

        $token_data = dcs_parse_edit_token( sanitize_text_field( $_GET['dcs_token'] ) );
        if ( ! $token_data || intval( $token_data['event_id'] ) !== $post_id ) return $prefill;

        $prefill['email'] = $token_data['email'];

        foreach ( $slots as $slot ) {
            if ( empty( $slot['attendees'] ) ) continue;
            foreach ( $slot['attendees'] as $a ) {
                if ( strtolower( $a['email'] ?? '' ) !== $prefill['email'] ) continue;
                if ( ! empty( $a['name'] ) && $prefill['name'] === '' ) {
                    $prefill['name'] = $a['name'];
                }
                $prefill['slot_keys'][] = dcs_slot_key( $slot );
            }
        }

        return $prefill;
    }

    // -------------------------------------------------------------------------
    // Form HTML
    // -------------------------------------------------------------------------

    private static function render_form( int $post_id, array $slots, bool $is_poll, array $prefill ): string {
        // Sort slots chronologically
        usort( $slots, fn( $a, $b ) => ( $a['start'] ?? 0 ) - ( $b['start'] ?? 0 ) );

        // Group by date
        $grouped = [];
        foreach ( $slots as $slot ) {
            $date_key = $slot['date'] ?? '';
            $grouped[ $date_key ][] = $slot;
        }

        ob_start();
        echo '<form id="dcs-booking" class="dcs-booking-form" novalidate>';
        echo wp_nonce_field( 'dcs_book_slot', 'dcs_nonce', true, false );

        // Anti-bot: a signed render timestamp (checked server-side against a
        // minimum age) plus a honeypot field real visitors never see or fill.
        // Off-screen rather than display:none — a display:none check is the
        // first thing a scraper that bothers to look usually tests for.
        printf( '<input type="hidden" name="dcs_ts" value="%s">', esc_attr( dcs_make_timing_token() ) );
        echo '<div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;">'
            . '<label for="dcs-hp">' . esc_html__( 'Leave this field blank', 'doodle-clone-scheduler' ) . '</label>'
            . '<input type="text" name="dcs_hp" id="dcs-hp" tabindex="-1" autocomplete="off">'
            . '</div>';

        // Pass the edit token through so the AJAX response can keep the voter
        // in their edit-link context after a resubmission.
        if ( ! empty( $_GET['dcs_token'] ) ) {
            printf(
                '<input type="hidden" name="dcs_edit_token" value="%s">',
                esc_attr( sanitize_text_field( $_GET['dcs_token'] ) )
            );
        }

        foreach ( $grouped as $date => $day_slots ) {
            echo '<h3>' . esc_html( date_i18n( 'l, F j, Y', strtotime( $date ) ) ) . '</h3>';
            echo '<ul class="dcs-slots">';
            foreach ( $day_slots as $slot ) {
                self::render_slot_item( $slot, $is_poll, $prefill );
            }
            echo '</ul>';
        }

        echo '<p>';
        printf(
            '<input type="text" name="name" id="dcs-name" placeholder="%s" value="%s" required> ',
            esc_attr__( 'Your Name', 'doodle-clone-scheduler' ),
            esc_attr( $prefill['name'] )
        );
        printf(
            '<input type="email" name="email" id="dcs-email" placeholder="%s" value="%s" required>',
            esc_attr__( 'Your Email', 'doodle-clone-scheduler' ),
            esc_attr( $prefill['email'] )
        );
        echo '</p>';

        printf( '<input type="hidden" name="event_id" value="%d">', $post_id );

        $btn_label = $is_poll
            ? esc_html__( 'Submit Availability', 'doodle-clone-scheduler' )
            : esc_html__( 'Book', 'doodle-clone-scheduler' );
        echo '<button type="submit">' . $btn_label . '</button>';
        echo '</form>';
        echo '<div id="dcs-message" aria-live="polite"></div>';

        return ob_get_clean();
    }

    private static function render_slot_item( array $slot, bool $is_poll, array $prefill ): void {
        $label     = esc_html( dcs_slot_range( $slot ) );
        $value     = esc_attr( dcs_slot_key( $slot ) );
        $attendees = $slot['attendees'] ?? [];
        $max       = intval( $slot['max'] ?? 1 );
        $full      = ! $is_poll && count( $attendees ) >= $max;

        echo '<li>';
        if ( $full ) {
            echo '<span class="dcs-slot-full">' . $label . ' — ' . esc_html__( 'Full', 'doodle-clone-scheduler' ) . '</span>';
        } elseif ( $is_poll ) {
            $checked = in_array( dcs_slot_key( $slot ), $prefill['slot_keys'], true ) ? ' checked' : '';
            printf(
                '<label><input type="checkbox" name="slot_ids[]" value="%s"%s> %s</label>',
                $value,
                $checked,
                $label
            );
        } else {
            printf(
                '<label><input type="radio" name="slot_id" value="%s" required> %s</label>',
                $value,
                $label
            );
        }
        echo '</li>';
    }

    // -------------------------------------------------------------------------
    // Schedule overview shortcode
    // -------------------------------------------------------------------------

    public static function render_overview_shortcode(): string {
        $events = get_posts( [ 'post_type' => 'meeting_event', 'numberposts' => -1 ] );
        if ( ! $events ) return '<p>' . esc_html__( 'No meeting events found.', 'doodle-clone-scheduler' ) . '</p>';

        // Only privileged users see who booked; everyone else sees Booked/Available.
        $show_names = current_user_can( 'edit_posts' );

        $output = '<div class="dcs-schedule-overview">';
        foreach ( $events as $event ) {
            $slots = get_post_meta( $event->ID, '_meeting_slots', true );
            if ( ! is_array( $slots ) || empty( $slots ) ) continue;
            usort( $slots, fn( $a, $b ) => ( $a['start'] ?? 0 ) - ( $b['start'] ?? 0 ) );

            $output .= '<h2>' . esc_html( get_the_title( $event->ID ) ) . '</h2>';
            $output .= '<table border="1" cellpadding="6" cellspacing="0" style="margin-bottom:20px;">';
            $output .= '<thead><tr><th>' . esc_html__( 'Time', 'doodle-clone-scheduler' ) . '</th><th>' . esc_html__( 'Status', 'doodle-clone-scheduler' ) . '</th></tr></thead><tbody>';
            foreach ( $slots as $slot ) {
                $attendees = ( ! empty( $slot['attendees'] ) && is_array( $slot['attendees'] ) ) ? $slot['attendees'] : [];

                if ( empty( $attendees ) ) {
                    $att = '<em>' . esc_html__( 'Available', 'doodle-clone-scheduler' ) . '</em>';
                } elseif ( $show_names ) {
                    $names = array_filter( array_map(
                        fn( $a ) => trim( (string) ( $a['name'] ?? '' ) ) ?: (string) ( $a['email'] ?? '' ),
                        $attendees
                    ) );
                    $att = $names
                        ? esc_html( implode( ', ', $names ) )
                        : esc_html__( 'Booked', 'doodle-clone-scheduler' );
                } else {
                    $att = esc_html__( 'Booked', 'doodle-clone-scheduler' );
                }

                $output .= '<tr><td>' . esc_html( dcs_slot_range( $slot ) ) . '</td><td>' . $att . '</td></tr>';
            }
            $output .= '</tbody></table>';
        }
        $output .= '</div>';
        return $output;
    }
}
