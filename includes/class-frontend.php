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
        // Registered everywhere so the overview shortcode can enqueue it on
        // whatever page it's placed on. The font is bundled (fonts/, loaded
        // via @font-face in frontend.css) — no third-party requests.
        wp_register_style( 'dcs-frontend', DCS_PLUGIN_URL . 'frontend.css', [], DCS_VERSION );
        if ( ! is_singular( 'meeting_event' ) ) return;
        wp_enqueue_style( 'dcs-frontend' );
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
                . '<div class="dcs-notice dcs-registration-closed" role="status">'
                . '<p><strong>' . esc_html__( 'Registration for this event is closed.', 'doodle-clone-scheduler' ) . '</strong> '
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
                $blank  = [ 'email' => '', 'name' => '', 'slot_keys' => [] ];
                return $content . $notice . ( dcs_is_sessions_mode( $mode )
                    ? self::render_sessions_form( $post_id, $slots, $blank )
                    : self::render_form( $post_id, $slots, $is_poll, $blank ) );
            }
        }

        // Resolve prefill data from a valid magic edit-link token
        $prefill = self::resolve_prefill( $post_id, $slots );

        if ( dcs_is_sessions_mode( $mode ) ) {
            return $content . self::render_sessions_form( $post_id, $slots, $prefill );
        }

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
        echo '<div class="dcs-notice dcs-poll-closed" role="status">';

        // --- Winning time block ---
        echo '<p>';
        echo '<strong>' . esc_html__( 'This poll is now closed.', 'doodle-clone-scheduler' ) . '</strong>';
        if ( is_array( $snap ) && ! empty( $snap['start'] ) ) {
            $start = intval( $snap['start'] );
            $end   = isset( $snap['end'] ) ? intval( $snap['end'] ) : 0;
            echo ' ' . esc_html__( 'The meeting has been scheduled for:', 'doodle-clone-scheduler' );
            echo '</p>';
            echo '<p class="dcs-winner">'
                . '<strong>' . esc_html( dcs_format_range( $start, $end ) ) . '</strong>';

            // Google Calendar and ICS links for the winner
            $post       = get_post( $post_id );
            $google_url = dcs_google_calendar_url( $post, $start, $end );
            echo ' <a class="dcs-cal-link" href="' . esc_url( $google_url ) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__( 'Add to Google Calendar', 'doodle-clone-scheduler' )
                . '</a>';
            echo '</p>';
        } else {
            echo ' ' . esc_html__( 'The organiser has finalised the time — thank you for voting.', 'doodle-clone-scheduler' );
            echo '</p>';
        }

        // --- Personalised voter summary (only when a valid token is present) ---
        if ( $voter !== null ) {
            echo '<hr>';

            // Expired token — show a clear explanation rather than a blank section
            if ( $voter['expired'] ) {
                $admin_email = antispambot( get_option( 'admin_email' ) );
                echo '<p class="dcs-muted">'
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
                echo '<p>'
                    . sprintf(
                        esc_html__( 'Hi %s — here\'s what you voted for:', 'doodle-clone-scheduler' ),
                        '<strong>' . esc_html( $voter['name'] ) . '</strong>'
                    )
                    . '</p>';
            } else {
                echo '<p><strong>' . esc_html__( 'Your selections:', 'doodle-clone-scheduler' ) . '</strong></p>';
            }

            if ( ! empty( $voter['slot_keys'] ) ) {
                // Resolve full slot data for each slot the voter selected
                $selected_slots = self::slots_for_keys( $slots, $voter['slot_keys'] );
                usort( $selected_slots, fn( $a, $b ) => ( $a['start'] ?? 0 ) - ( $b['start'] ?? 0 ) );

                // Find the winning slot so we can highlight it
                $winning_start = ( is_array( $snap ) && ! empty( $snap['start'] ) ) ? intval( $snap['start'] ) : 0;

                echo '<ul class="dcs-vote-list">';
                foreach ( $selected_slots as $slot ) {
                    $is_winner = $winning_start && intval( $slot['start'] ?? 0 ) === $winning_start;
                    $suffix    = $is_winner
                        ? ' <span class="dcs-badge dcs-badge--success">✓ ' . esc_html__( 'Selected time', 'doodle-clone-scheduler' ) . '</span>'
                        : '';
                    echo '<li' . ( $is_winner ? ' class="dcs-vote-winner"' : '' ) . '>'
                        . esc_html( dcs_slot_range( $slot ) )
                        . $suffix
                        . '</li>';
                }
                echo '</ul>';
            } else {
                echo '<p class="dcs-muted">'
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
        return '<div class="dcs-notice dcs-notice--warning dcs-token-expired" role="status">'
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
            return '<div class="dcs-notice dcs-notice--success" role="status">'
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
        self::render_form_open();

        // Slot labels are times only: the date is the card heading and the
        // timezone is stated once here.
        $tz = dcs_slots_tz_label( $slots );
        echo '<p class="dcs-intro">' . esc_html( $is_poll
            ? __( 'Select every time that works for you.', 'doodle-clone-scheduler' )
            : __( 'Choose one time.', 'doodle-clone-scheduler' ) );
        if ( $tz !== '' ) {
            /* translators: %s: timezone abbreviation, e.g. EDT */
            echo ' <span class="dcs-tz">' . esc_html( sprintf( __( 'All times are %s.', 'doodle-clone-scheduler' ), $tz ) ) . '</span>';
        }
        echo '</p>';

        echo '<div class="dcs-days">';
        $i = 0;
        foreach ( $grouped as $date => $day_slots ) {
            $ts      = strtotime( $date );
            $head_id = 'dcs-day-' . $i++;
            printf( '<div class="dcs-day" role="group" aria-labelledby="%s">', esc_attr( $head_id ) );
            printf(
                '<h3 class="dcs-day-head" id="%s"><span class="dcs-day-name">%s</span> <span class="dcs-day-date">%s</span></h3>',
                esc_attr( $head_id ),
                esc_html( date_i18n( 'l', $ts ) ),
                esc_html( date_i18n( 'M j, Y', $ts ) )
            );
            echo '<ul class="dcs-slots">';
            foreach ( $day_slots as $slot ) {
                self::render_slot_item( $slot, $is_poll, $prefill );
            }
            echo '</ul></div>';
        }
        echo '</div>';

        self::render_form_close(
            $post_id,
            $prefill,
            $is_poll ? __( 'Submit Availability', 'doodle-clone-scheduler' ) : __( 'Book', 'doodle-clone-scheduler' ),
            $is_poll
        );

        return ob_get_clean();
    }

    /** Opening <form> tag plus the hidden nonce / anti-bot / edit-token fields shared by every form. */
    private static function render_form_open(): void {
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
    }

    /**
     * Name / email fields, event id, submit button, closing tag and message
     * area. $show_count adds the "N times selected" counter script.js fills.
     */
    private static function render_form_close( int $post_id, array $prefill, string $btn_label, bool $show_count = false ): void {
        echo '<fieldset class="dcs-details">';
        echo '<legend class="dcs-details-title">' . esc_html__( 'Your details', 'doodle-clone-scheduler' ) . '</legend>';
        echo '<div class="dcs-fields">';
        printf(
            '<p class="dcs-field"><label for="dcs-name">%s</label><input type="text" name="name" id="dcs-name" value="%s" autocomplete="name" required></p>',
            esc_html__( 'Name', 'doodle-clone-scheduler' ),
            esc_attr( $prefill['name'] )
        );
        printf(
            '<p class="dcs-field"><label for="dcs-email">%s</label><input type="email" name="email" id="dcs-email" value="%s" autocomplete="email" required></p>',
            esc_html__( 'Email', 'doodle-clone-scheduler' ),
            esc_attr( $prefill['email'] )
        );
        echo '</div>';
        echo '<p class="dcs-hint">' . esc_html__( 'Your confirmation will be emailed to this address.', 'doodle-clone-scheduler' ) . '</p>';
        echo '</fieldset>';

        printf( '<input type="hidden" name="event_id" value="%d">', $post_id );

        echo '<div class="dcs-actions">';
        echo '<button type="submit" class="dcs-submit">' . esc_html( $btn_label ) . '</button>';
        if ( $show_count ) {
            echo '<span class="dcs-count" aria-live="polite"></span>';
        }
        echo '</div>';
        echo '</form>';
        echo '<div id="dcs-message" class="dcs-message" aria-live="polite"></div>';
    }

    /**
     * Sessions / series form: one radio group per part. Each option shows how
     * many places are left; full times are disabled unless this person
     * already holds them (editing via their link). "Can't attend" is offered
     * unless every part is required.
     */
    private static function render_sessions_form( int $post_id, array $slots, array $prefill ): string {
        $groups     = dcs_group_slots_by_part( dcs_get_parts( $post_id ), $slots );
        $attendance = dcs_sessions_attendance( $post_id );
        $multi      = count( $groups ) > 1;
        $editing    = ! empty( $prefill['slot_keys'] );
        // A single session offered at several times: one pick is always
        // required, so there's no "Can't attend" option.
        $can_skip   = $multi && $attendance !== 'required';

        ob_start();
        self::render_form_open();

        if ( $multi && $attendance === 'recommended' ) {
            echo '<p class="dcs-attendance-note">' . esc_html__( 'Attending every part is recommended, but you can skip any you can\'t make.', 'doodle-clone-scheduler' ) . '</p>';
        } elseif ( $multi && $attendance === 'required' ) {
            echo '<p class="dcs-attendance-note">' . esc_html__( 'Please choose a time for every part.', 'doodle-clone-scheduler' ) . '</p>';
        }

        foreach ( $groups as $gi => $g ) {
            $field = 'part_slots[' . $gi . ']';

            // Which option starts selected: the person's current pick when
            // editing; otherwise, for "recommended", a part's only time.
            $selected = '';
            foreach ( $g['slots'] as $sl ) {
                if ( in_array( dcs_slot_key( $sl ), $prefill['slot_keys'], true ) ) $selected = dcs_slot_key( $sl );
            }
            if ( $selected === '' && ! $editing && $attendance === 'recommended' && count( $g['slots'] ) === 1 ) {
                $only = $g['slots'][0];
                if ( ! dcs_slot_is_full( $only, count( (array) ( $only['attendees'] ?? [] ) ) ) ) $selected = dcs_slot_key( $only );
            }

            echo '<fieldset class="dcs-part">';
            if ( $multi ) {
                echo '<legend><h3>' . esc_html( dcs_part_label( $g, $gi ) ) . '</h3></legend>';
            }
            echo '<ul class="dcs-slots">';
            foreach ( $g['slots'] as $sl ) {
                $key   = dcs_slot_key( $sl );
                $taken = count( (array) ( $sl['attendees'] ?? [] ) );
                $cap   = dcs_slot_capacity( $sl );
                $mine  = in_array( $key, $prefill['slot_keys'], true );
                $full  = dcs_slot_is_full( $sl, $taken ) && ! $mine;

                // No limit: no places-left note at all.
                $note = '';
                $mod  = '';
                if ( $mine ) {
                    $note = esc_html__( 'your current choice', 'doodle-clone-scheduler' );
                    $mod  = ' dcs-badge--mine';
                } elseif ( $full ) {
                    $note = esc_html__( 'Full', 'doodle-clone-scheduler' );
                    $mod  = ' dcs-badge--full';
                } elseif ( $cap !== null ) {
                    $left = $cap - $taken;
                    $note = esc_html( sprintf( _n( '%d spot left', '%d spots left', $left, 'doodle-clone-scheduler' ), $left ) );
                }

                printf(
                    '<li><label%s><input type="radio" name="%s" value="%s"%s%s%s> <span class="dcs-slot-time">%s</span>%s</label></li>',
                    $full ? ' class="dcs-slot-full"' : '',
                    esc_attr( $field ),
                    esc_attr( $key ),
                    checked( $selected, $key, false ),
                    $full ? ' disabled' : '',
                    $can_skip ? '' : ' required',
                    esc_html( dcs_slot_range( $sl ) ),
                    $note !== '' ? ' <span class="dcs-badge dcs-spots' . $mod . '">' . $note . '</span>' : ''
                );
            }
            if ( $can_skip ) {
                printf(
                    '<li class="dcs-slot-skip"><label><input type="radio" name="%s" value=""%s> %s</label></li>',
                    esc_attr( $field ),
                    $selected === '' ? ' checked' : '',
                    esc_html__( "Can't attend this part", 'doodle-clone-scheduler' )
                );
            }
            echo '</ul></fieldset>';
        }

        self::render_form_close( $post_id, $prefill, $editing ? __( 'Update my sessions', 'doodle-clone-scheduler' ) : __( 'Register', 'doodle-clone-scheduler' ) );

        return ob_get_clean();
    }

    private static function render_slot_item( array $slot, bool $is_poll, array $prefill ): void {
        $label     = '<span class="dcs-slot-time">' . esc_html( dcs_slot_time_range( $slot ) ) . '</span>';
        $value     = esc_attr( dcs_slot_key( $slot ) );
        $attendees = $slot['attendees'] ?? [];
        $full      = ! $is_poll && dcs_slot_is_full( $slot, count( (array) $attendees ) );

        echo '<li>';
        if ( $full ) {
            echo '<span class="dcs-slot-full">' . $label . ' <span class="dcs-badge dcs-badge--full">' . esc_html__( 'Full', 'doodle-clone-scheduler' ) . '</span></span>';
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

        wp_enqueue_style( 'dcs-frontend' );

        // Only privileged users see who booked; everyone else sees Booked/Available.
        $show_names = current_user_can( 'edit_posts' );

        $output = '<div class="dcs-schedule-overview">';
        foreach ( $events as $event ) {
            $slots = get_post_meta( $event->ID, '_meeting_slots', true );
            if ( ! is_array( $slots ) || empty( $slots ) ) continue;
            usort( $slots, fn( $a, $b ) => ( $a['start'] ?? 0 ) - ( $b['start'] ?? 0 ) );

            $output .= '<h2>' . esc_html( get_the_title( $event->ID ) ) . '</h2>';
            $output .= '<table class="dcs-overview-table">';
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
