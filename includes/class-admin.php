<?php
/**
 * DCS Admin
 * Registers the meeting_event post type, all meta boxes, and all save handlers.
 *
 * Save-hook priority map (single canonical registration):
 *  Priority  5  — Snapshot existing slots before overwrite    (dcs_snapshot_pre_save)
 *  Priority 10  — Save slots from $_POST                      (save_slots)
 *  Priority 15  — Save meeting details (default + per slot)   (save_meeting_details)
 *  Priority 20  — Save mode + default duration                (save_mode)
 *  Priority 30  — Save open/closed status                     (save_poll_status)
 *  Priority 40  — Handle close/reopen action + build snapshot (handle_poll_close_reopen)
 *  Priority 50  — Send final-details email if requested       (handle_announcement)
 *  Priority 200 — Normalise timestamps & backfill end/dur     (normalize_slots)
 *  Priority 500 — Merge duration from pre-save snapshot       (merge_from_snapshot)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DCS_Admin {

    /**
     * Per-slot detail overrides collected by save_slots() (keyed by the slot's
     * final id) for save_meeting_details() to write. null = no slots posted.
     */
    private static ?array $pending_overrides = null;

    public static function init(): void {
        add_action( 'init',            [ __CLASS__, 'register_post_type' ] );
        add_action( 'add_meta_boxes',  [ __CLASS__, 'register_meta_boxes' ] );
        add_action( 'admin_footer',    [ __CLASS__, 'admin_footer_scripts' ] );

        // Single chained save hook — explicit priorities, no anonymous closures
        add_action( 'save_post_meeting_event', [ __CLASS__, 'dcs_snapshot_pre_save' ],        5,    3 );
        add_action( 'save_post_meeting_event', [ __CLASS__, 'save_slots' ],                   10,   1 );
        add_action( 'save_post_meeting_event', [ __CLASS__, 'save_meeting_details' ],         15,   1 );
        add_action( 'save_post_meeting_event', [ __CLASS__, 'save_mode' ],                    20,   1 );
        add_action( 'save_post_meeting_event', [ __CLASS__, 'save_poll_status' ],             30,   2 );
        add_action( 'save_post_meeting_event', [ __CLASS__, 'handle_poll_close_reopen' ],     40,   2 );
        add_action( 'save_post_meeting_event', [ __CLASS__, 'handle_announcement' ],          50,   2 );
        add_action( 'save_post_meeting_event', [ __CLASS__, 'normalize_slots' ],              200,  1 );
        add_action( 'save_post_meeting_event', [ __CLASS__, 'merge_durations_from_snapshot'], 500,  1 );

        // REST (Gutenberg) save — run normalize last
        add_action( 'rest_after_insert_meeting_event', [ __CLASS__, 'rest_after_insert' ], 10, 1 );
    }

    // =========================================================================
    // Post Type
    // =========================================================================

    public static function register_post_type(): void {
        register_post_type( 'meeting_event', [
            'labels'      => [
                'name'          => __( 'Meeting Events', 'doodle-clone-scheduler' ),
                'singular_name' => __( 'Meeting Event', 'doodle-clone-scheduler' ),
            ],
            'public'      => true,
            'has_archive' => true,
            'rewrite'     => [ 'slug' => 'meeting_event' ],
            'supports'    => [ 'title', 'editor' ],
            'menu_icon'   => 'dashicons-calendar-alt',
        ] );
    }

    // =========================================================================
    // Meta Boxes
    // =========================================================================

    public static function register_meta_boxes(): void {
        $post_type = 'meeting_event';

        add_meta_box( 'dcs_meeting_type',   __( 'Meeting Type', 'doodle-clone-scheduler' ),    [ __CLASS__, 'render_mode_box' ],        $post_type, 'side',   'high' );
        add_meta_box( 'dcs_slots',          __( 'Meeting Slots', 'doodle-clone-scheduler' ),   [ __CLASS__, 'render_slots_box' ],       $post_type, 'normal', 'high' );
        add_meta_box( 'dcs_meeting_details', __( 'Meeting Details (default for all slots)', 'doodle-clone-scheduler' ), [ __CLASS__, 'render_details_box' ], $post_type, 'normal', 'high' );
        add_meta_box( 'dcs_poll_results',   __( 'Poll Results', 'doodle-clone-scheduler' ),    [ __CLASS__, 'render_results_box' ],     $post_type, 'normal', 'default' );
        add_meta_box( 'dcs_close_poll',     __( 'Close & Send Final Details', 'doodle-clone-scheduler' ), [ __CLASS__, 'render_close_poll_box' ], $post_type, 'normal', 'low' );
    }

    // =========================================================================
    // Meeting Type Meta Box
    // =========================================================================

    public static function render_mode_box( WP_Post $post ): void {
        $mode = get_post_meta( $post->ID, '_meeting_mode', true ) ?: 'booking';
        ?>
        <p><strong><?php esc_html_e( 'How should attendees respond?', 'doodle-clone-scheduler' ); ?></strong></p>
        <label style="display:block;margin-bottom:6px;">
            <input type="radio" name="dcs_meeting_mode" value="booking" <?php checked( $mode, 'booking' ); ?>>
            <?php esc_html_e( '1-on-1 / limited capacity (choose one time)', 'doodle-clone-scheduler' ); ?>
        </label>
        <label style="display:block;">
            <input type="radio" name="dcs_meeting_mode" value="poll" <?php checked( $mode, 'poll' ); ?>>
            <?php esc_html_e( 'Group poll (select all times that work)', 'doodle-clone-scheduler' ); ?>
        </label>
        <?php
    }

    // =========================================================================
    // Slots Meta Box
    // =========================================================================

    public static function render_slots_box( WP_Post $post ): void {
        $slots = get_post_meta( $post->ID, '_meeting_slots', true );
        if ( ! is_array( $slots ) ) $slots = [];
        $overrides = dcs_get_meeting_details( $post->ID )['slots'];
        ?>
        <div id="dcs-slot-wrapper">
        <?php foreach ( $slots as $i => $slot ) : ?>
            <div class="dcs-slot" data-index="<?php echo $i; ?>">
                <label><?php esc_html_e( 'Date:', 'doodle-clone-scheduler' ); ?></label>
                <input type="date" name="slots[<?php echo $i; ?>][date]" value="<?php echo esc_attr( $slot['date'] ?? '' ); ?>" required><br>

                <label><?php esc_html_e( 'Time:', 'doodle-clone-scheduler' ); ?></label>
                <input type="time" name="slots[<?php echo $i; ?>][time]" value="<?php echo esc_attr( $slot['time'] ?? '' ); ?>" required>

                <label><?php esc_html_e( 'Duration:', 'doodle-clone-scheduler' ); ?></label>
                <?php self::render_duration_select( "slots[{$i}][duration_minutes]", $slot['duration_minutes'] ?? '' ); ?>
                <br>

                <label><?php esc_html_e( 'Max:', 'doodle-clone-scheduler' ); ?></label>
                <input type="number" name="slots[<?php echo $i; ?>][max]" value="<?php echo esc_attr( $slot['max'] ?? 1 ); ?>" min="1">

                <div class="dcs-attendees">
                <?php
                $attendees = ( ! empty( $slot['attendees'] ) && is_array( $slot['attendees'] ) )
                    ? $slot['attendees']
                    : [ [ 'name' => '', 'email' => '' ] ];
                foreach ( $attendees as $j => $a ) : ?>
                    <div class="dcs-attendee-row">
                        <input type="text"  name="slots[<?php echo $i; ?>][attendees][<?php echo $j; ?>][name]"  value="<?php echo esc_attr( $a['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Name', 'doodle-clone-scheduler' ); ?>">
                        <input type="email" name="slots[<?php echo $i; ?>][attendees][<?php echo $j; ?>][email]" value="<?php echo esc_attr( $a['email'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Email', 'doodle-clone-scheduler' ); ?>">
                        <button type="button" class="dcs-remove-att button"><?php esc_html_e( 'Remove', 'doodle-clone-scheduler' ); ?></button>
                    </div>
                <?php endforeach; ?>
                </div>
                <button type="button" class="dcs-add-att button"><?php esc_html_e( 'Add Attendee', 'doodle-clone-scheduler' ); ?></button>
                <button type="button" class="dcs-remove-slot button button-link-delete"><?php esc_html_e( 'Remove Slot', 'doodle-clone-scheduler' ); ?></button>
                <?php
                $ov = $overrides[ dcs_slot_key( $slot ) ] ?? [];
                $ov = is_array( $ov ) ? dcs_sanitize_meeting_details( $ov, true ) : [];
                self::render_slot_override( "slots[{$i}][details]", $ov );
                ?>
                <hr>
            </div>
        <?php endforeach; ?>
        </div>
        <p><button type="button" class="button button-primary" id="dcs-add-slot"><?php esc_html_e( '+ Add Slot', 'doodle-clone-scheduler' ); ?></button></p>
        <?php
        // Template for the override section of slots added client-side; the
        // JS swaps __i__ for the new slot's index. Kept server-rendered so the
        // field markup lives in exactly one place.
        echo '<script type="text/template" id="dcs-slot-override-tpl">';
        self::render_slot_override( 'slots[__i__][details]', [] );
        echo '</script>';
    }

    /** Collapsible per-slot "override meeting details" section. */
    private static function render_slot_override( string $name_prefix, array $values ): void {
        $open = $values && dcs_meeting_details_has_content( $values );
        echo '<details class="dcs-slot-override"' . ( $open ? ' open' : '' ) . ' style="margin:8px 0;">';
        echo '<summary>' . esc_html__( 'Override meeting details for this slot', 'doodle-clone-scheduler' ) . '</summary>';
        echo '<p class="description">' . esc_html__( 'Leave a field blank to use the event default from the Meeting Details box. Entering a meeting link here makes this slot a separate meeting: its meeting ID, passcode and dial-in are then taken only from this slot, never from the default.', 'doodle-clone-scheduler' ) . '</p>';
        self::render_details_fields( $name_prefix, $values, true );
        echo '</details>';
    }

    // =========================================================================
    // Meeting Details Meta Box (event-wide default)
    // =========================================================================

    public static function render_details_box( WP_Post $post ): void {
        $default = dcs_sanitize_meeting_details( dcs_get_meeting_details( $post->ID )['default'] );
        wp_nonce_field( 'dcs_save_details_action', 'dcs_save_details_nonce' );
        echo '<p class="description">'
            . esc_html__( 'Applies to every slot unless a slot overrides it. These details are never shown on the public page — they are only emailed to attendees when you close the event and send final details.', 'doodle-clone-scheduler' )
            . '</p>';
        self::render_details_fields( 'dcs_details', $default, false );
    }

    /**
     * Renders the details inputs. In-person and online fields are wrapped so
     * the footer JS can show only those relevant to the chosen format.
     */
    private static function render_details_fields( string $prefix, array $v, bool $is_override ): void {
        $v   = wp_parse_args( $v, [ 'format' => $is_override ? '' : 'unspecified', 'location' => '', 'url' => '', 'meeting_id' => '', 'passcode' => '', 'dial_in' => '', 'notes' => '' ] );
        $n   = fn( $f ) => esc_attr( $prefix . '[' . $f . ']' );

        echo '<div class="dcs-details">';

        echo '<p><label>' . esc_html__( 'Format:', 'doodle-clone-scheduler' ) . '</label> ';
        echo '<select class="dcs-format" name="' . $n( 'format' ) . '">';
        if ( $is_override ) {
            echo '<option value=""' . selected( $v['format'], '', false ) . '>' . esc_html__( '— Use event default —', 'doodle-clone-scheduler' ) . '</option>';
        }
        foreach ( dcs_meeting_formats() as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '"' . selected( $v['format'], $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></p>';

        echo '<div class="dcs-d-inperson">';
        printf(
            '<p><label>%s</label> <input type="text" class="regular-text" name="%s" value="%s" placeholder="%s"></p>',
            esc_html__( 'Address / room:', 'doodle-clone-scheduler' ),
            $n( 'location' ),
            esc_attr( $v['location'] ),
            esc_attr__( 'e.g. 123 Main St, Room 4B', 'doodle-clone-scheduler' )
        );
        echo '</div>';

        echo '<div class="dcs-d-online">';
        printf(
            '<p><label>%s</label> <input type="url" class="regular-text" name="%s" value="%s" placeholder="https://zoom.us/j/… or https://teams.microsoft.com/…"></p>',
            esc_html__( 'Meeting link:', 'doodle-clone-scheduler' ),
            $n( 'url' ),
            esc_attr( $v['url'] )
        );
        printf(
            '<p><label>%s</label> <input type="text" name="%s" value="%s"> <label>%s</label> <input type="text" name="%s" value="%s" autocomplete="off"></p>',
            esc_html__( 'Meeting ID:', 'doodle-clone-scheduler' ),
            $n( 'meeting_id' ),
            esc_attr( $v['meeting_id'] ),
            esc_html__( 'Passcode:', 'doodle-clone-scheduler' ),
            $n( 'passcode' ),
            esc_attr( $v['passcode'] )
        );
        printf(
            '<p><label style="vertical-align:top;">%s</label> <textarea name="%s" rows="3" class="large-text" style="max-width:32em;" placeholder="%s">%s</textarea></p>',
            esc_html__( 'Dial-in:', 'doodle-clone-scheduler' ),
            $n( 'dial_in' ),
            esc_attr__( 'One number per line, e.g. +1 646 558 8656 US (New York)', 'doodle-clone-scheduler' ),
            esc_textarea( $v['dial_in'] )
        );
        echo '</div>';

        printf(
            '<p><label style="vertical-align:top;">%s</label> <textarea name="%s" rows="2" class="large-text" style="max-width:32em;">%s</textarea></p>',
            esc_html__( 'Notes:', 'doodle-clone-scheduler' ),
            $n( 'notes' ),
            esc_textarea( $v['notes'] )
        );

        echo '</div>';
    }

    private static function render_duration_select( string $name, $selected ): void {
        $options = [ 30, 60, 90, 120, 150, 180, 210, 240, 270, 300, 360, 420, 480, 540, 600 ];
        echo '<select name="' . esc_attr( $name ) . '" style="min-width:100px;">';
        echo '<option value="">—</option>';
        foreach ( $options as $mins ) {
            $label    = dcs_format_duration( $mins );
            $sel      = selected( strval( $selected ), strval( $mins ), false );
            printf( '<option value="%d"%s>%s</option>', $mins, $sel, esc_html( $label ) );
        }
        echo '</select>';
    }

    // =========================================================================
    // Poll Results Meta Box
    // =========================================================================

    public static function render_results_box( WP_Post $post ): void {
        $slots = get_post_meta( $post->ID, '_meeting_slots', true );
        if ( ! is_array( $slots ) || empty( $slots ) ) {
            echo '<p>' . esc_html__( 'No time slots defined yet.', 'doodle-clone-scheduler' ) . '</p>';
            return;
        }

        $selected_idx = intval( get_post_meta( $post->ID, '_poll_selected_slot_index', true ) );

        echo '<style>
            .dcs-results-table { width:100%; border-collapse:collapse; }
            .dcs-results-table th, .dcs-results-table td { border:1px solid #e2e8f0; padding:8px; text-align:left; vertical-align:top; }
            .dcs-results-table th { background:#f8fafc; }
            .dcs-badge { display:inline-block; padding:2px 8px; border-radius:12px; background:#eef2ff; }
            .dcs-winner { background:#f0fdf4 !important; }
            .dcs-voter-table { width:100%; border-collapse:collapse; margin-top:20px; }
            .dcs-voter-table th, .dcs-voter-table td { border:1px solid #e2e8f0; padding:7px 9px; text-align:left; vertical-align:top; }
            .dcs-voter-table th { background:#f8fafc; }
            .dcs-token-active   { color:#1d7914; font-weight:600; }
            .dcs-token-expired  { color:#996800; }
            .dcs-token-none     { color:#787c82; }
            .dcs-slot-pill { display:inline-block; padding:1px 7px; border-radius:10px; background:#eef2ff; font-size:12px; margin:1px 2px 1px 0; white-space:nowrap; }
            .dcs-slot-pill.winner { background:#dcfce7; color:#1d7914; font-weight:600; }
        </style>';

        // --- Slot results table ---
        echo '<table class="dcs-results-table"><thead><tr>'
            . '<th>' . esc_html__( 'Time', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Duration', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Votes', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Voters', 'doodle-clone-scheduler' ) . '</th>'
            . '</tr></thead><tbody>';

        foreach ( $slots as $idx => $slot ) {
            $attendees = is_array( $slot['attendees'] ?? null ) ? $slot['attendees'] : [];
            $names     = array_map(
                fn( $a ) => esc_html( ( $a['name'] ?? '' ) ?: ( $a['email'] ?? '' ) ?: '—' ),
                $attendees
            );
            $row_class = ( $idx === $selected_idx ) ? ' class="dcs-winner"' : '';
            $dur_mins  = intval( $slot['duration_minutes'] ?? 0 );
            if ( ! $dur_mins && ! empty( $slot['start'] ) && ! empty( $slot['end'] ) ) {
                $dur_mins = intdiv( intval( $slot['end'] ) - intval( $slot['start'] ), 60 );
            }

            echo '<tr' . $row_class . '>';
            echo '<td>' . esc_html( dcs_slot_range( $slot ) ) . '</td>';
            echo '<td>' . esc_html( dcs_format_duration( $dur_mins ) ?: '—' ) . '</td>';
            echo '<td><span class="dcs-badge">' . count( $attendees ) . '</span></td>';
            echo '<td>' . ( $names ? implode( ', ', $names ) : '—' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Quick summary
        $scores = array_map( fn( $s ) => count( $s['attendees'] ?? [] ), $slots );
        arsort( $scores );
        $top_idx   = array_key_first( $scores );
        $top_votes = $scores[ $top_idx ];
        if ( $top_votes > 0 ) {
            echo '<p style="margin-top:10px;">'
                . sprintf(
                    esc_html__( 'Most votes: %s (%d vote(s))', 'doodle-clone-scheduler' ),
                    '<strong>' . esc_html( dcs_slot_range( $slots[ $top_idx ] ) ) . '</strong>',
                    $top_votes
                )
                . '</p>';
        }

        // --- Voter roster with token status ---
        self::render_voter_roster( $post->ID, $slots );
    }

    /**
     * Renders the voter roster table inside the Poll Results meta box.
     * For each unique voter: name, email, slot selections, and edit-link status.
     */
    private static function render_voter_roster( int $post_id, array $slots ): void {
        // Per-slot display label + start timestamp. Use the SAME formatter as the
        // Poll Results table (dcs_slot_range → dcs_tz) so the two sections always
        // agree; dcs_slot_label() renders in the site timezone and is wrong here.
        $slot_display = [];
        $slot_start   = [];
        foreach ( $slots as $idx => $slot ) {
            $slot_display[ $idx ] = dcs_slot_range( $slot );
            $slot_start[ $idx ]   = intval( $slot['start'] ?? 0 );
        }

        // Build a map: normalised email → [ name, raw email, selected slot indexes ]
        $voters = [];
        foreach ( $slots as $idx => $slot ) {
            if ( empty( $slot['attendees'] ) || ! is_array( $slot['attendees'] ) ) continue;
            foreach ( $slot['attendees'] as $a ) {
                $raw  = $a['email'] ?? '';
                $norm = dcs_normalize_email( $raw );
                if ( ! $norm ) continue;
                if ( ! isset( $voters[ $norm ] ) ) {
                    $voters[ $norm ] = [
                        'name'     => $a['name'] ?? '',
                        'email'    => $raw,
                        'slot_idx' => [],
                    ];
                }
                $voters[ $norm ]['slot_idx'][] = $idx;
                // Keep the first non-empty name we see for this voter
                if ( empty( $voters[ $norm ]['name'] ) && ! empty( $a['name'] ) ) {
                    $voters[ $norm ]['name'] = $a['name'];
                }
            }
        }

        if ( empty( $voters ) ) {
            echo '<p style="margin-top:16px;color:#646970;">'
                . esc_html__( 'No voters yet.', 'doodle-clone-scheduler' )
                . '</p>';
            return;
        }

        // Determine the winning slot's start timestamp for highlighting
        $snap          = get_post_meta( $post_id, '_poll_selected_slot_snapshot', true );
        $winning_start = ( is_array( $snap ) && ! empty( $snap['start'] ) ) ? intval( $snap['start'] ) : 0;

        echo '<h4 style="margin:20px 0 6px;">' . esc_html__( 'Voter Roster', 'doodle-clone-scheduler' ) . '</h4>';

        echo '<table class="dcs-voter-table"><thead><tr>'
            . '<th>' . esc_html__( 'Name', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Email', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Voted for', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Edit link', 'doodle-clone-scheduler' ) . '</th>'
            . '</tr></thead><tbody>';

        foreach ( $voters as $norm_email => $voter ) {
            // Read the timestamp written by handle_poll_vote() when the confirmation
            // email was sent. This is the start of the voter's current 30-day token window.
            $issued_key    = '_dcs_token_issued_' . md5( $norm_email );
            $last_issued   = intval( get_post_meta( $post_id, $issued_key, true ) );

            $now = time();
            if ( $last_issued > 0 ) {
                $token_exp   = $last_issued + 2592000; // 30 days
                $token_state = ( $now < $token_exp ) ? 'active' : 'expired';
                $days_left   = max( 0, (int) ceil( ( $token_exp - $now ) / DAY_IN_SECONDS ) );
            } else {
                // Voter exists in slot data but no token has been issued yet
                // (e.g. manually added by admin, or migrated from v1).
                $token_state = 'none';
                $days_left   = 0;
            }

            // Build slot pills
            $pills = '';
            foreach ( $voter['slot_idx'] as $idx ) {
                $s           = $slot_start[ $idx ] ?? 0;
                $is_winner   = $winning_start && $s === $winning_start;
                $pill_class  = $is_winner ? 'dcs-slot-pill winner' : 'dcs-slot-pill';
                $lbl         = $slot_display[ $idx ] ?? ( 'Slot #' . ( (int) $idx + 1 ) );
                $pills      .= '<span class="' . $pill_class . '">' . esc_html( $lbl ) . '</span>';
            }

            // Token status pill
            if ( $token_state === 'active' ) {
                $status_html = '<span class="dcs-token-active">● '
                    . sprintf(
                        /* translators: %d: days until edit link expires */
                        esc_html( _n( 'Active (%d day left)', 'Active (%d days left)', $days_left, 'doodle-clone-scheduler' ) ),
                        $days_left
                    )
                    . '</span>';
            } elseif ( $token_state === 'expired' ) {
                $status_html = '<span class="dcs-token-expired">● '
                    . esc_html__( 'Expired', 'doodle-clone-scheduler' )
                    . '</span>';
            } else {
                $status_html = '<span class="dcs-token-none">—&nbsp;'
                    . esc_html__( 'No link sent', 'doodle-clone-scheduler' )
                    . '</span>';
            }

            echo '<tr>';
            echo '<td>' . esc_html( $voter['name'] ?: '—' ) . '</td>';
            echo '<td>' . esc_html( $voter['email'] ) . '</td>';
            echo '<td>' . ( $pills ?: '—' ) . '</td>';
            echo '<td>' . $status_html . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p style="margin-top:6px;color:#787c82;font-size:12px;">'
            . esc_html__( '"Active" = edit link is within its 30-day window from last submission. "Expired" = window elapsed; voter must re-submit to get a fresh link. "No link sent" = voter was added manually or migrated from v1.', 'doodle-clone-scheduler' )
            . '</p>';
    }

    // =========================================================================
    // Close / Reopen Poll Meta Box
    // =========================================================================

    public static function render_close_poll_box( WP_Post $post ): void {
        $mode     = get_post_meta( $post->ID, '_meeting_mode', true ) ?: 'booking';
        $is_group = in_array( $mode, [ 'poll', 'group' ], true );

        if ( ! $is_group ) {
            self::render_close_booking_box( $post );
            return;
        }

        $status       = get_post_meta( $post->ID, '_poll_status', true ) ?: 'open';
        $selected_idx = get_post_meta( $post->ID, '_poll_selected_slot_index', true );
        $selected_idx = ( $selected_idx !== '' ) ? intval( $selected_idx ) : -1;
        $snapshot     = get_post_meta( $post->ID, '_poll_selected_slot_snapshot', true );
        $sel_start    = is_array( $snapshot ) ? intval( $snapshot['start'] ?? 0 ) : intval( get_post_meta( $post->ID, '_poll_selected_start', true ) );
        $sel_end      = is_array( $snapshot ) ? intval( $snapshot['end'] ?? 0 )   : intval( get_post_meta( $post->ID, '_poll_selected_end', true ) );

        $slots = get_post_meta( $post->ID, '_meeting_slots', true );
        if ( ! is_array( $slots ) ) $slots = [];

        // Compute suggested winner (most votes, ties broken by earliest slot)
        $suggested_idx = -1;
        $best_votes    = -1;
        $best_start    = PHP_INT_MAX;
        foreach ( $slots as $idx => $sl ) {
            $votes = count( $sl['attendees'] ?? [] );
            $s     = intval( $sl['start'] ?? 0 );
            if ( $votes > $best_votes || ( $votes === $best_votes && $s > 0 && $s < $best_start ) ) {
                $best_votes    = $votes;
                $best_start    = $s ?: $best_start;
                $suggested_idx = $idx;
            }
        }

        // Announcement panel (shown when closed)
        if ( $status === 'closed' && ( is_array( $snapshot ) && ! empty( $snapshot['start'] ) ) ) {
            $winner_id = (string) get_post_meta( $post->ID, '_poll_selected_slot_id', true );
            $winner    = null;
            foreach ( $slots as $sl ) {
                if ( dcs_slot_key( $sl ) === $winner_id ) { $winner = $sl; break; }
            }
            $missing = [];
            if ( $winner && dcs_meeting_details_missing_link( dcs_slot_details( $post->ID, $winner ) ) ) {
                $missing[] = dcs_slot_range( $winner );
            }
            self::render_announcement_panel( $post->ID, $missing );
        }

        echo '<p>' . esc_html__( 'Select the final time and close the poll. The most popular slot is pre-selected.', 'doodle-clone-scheduler' ) . '</p>';

        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__( 'Pick', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Time', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Duration', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Votes', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Voters', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Suggested', 'doodle-clone-scheduler' ) . '</th>'
            . '</tr></thead><tbody>';

        foreach ( $slots as $idx => $sl ) {
            $s        = intval( $sl['start'] ?? 0 );
            $e        = intval( $sl['end'] ?? 0 );
            $dur_mins = intval( $sl['duration_minutes'] ?? 0 ) ?: ( $s && $e > $s ? intdiv( $e - $s, 60 ) : 0 );
            $votes    = count( $sl['attendees'] ?? [] );
            $names    = implode( ', ', array_map(
                fn( $a ) => esc_html( ( $a['name'] ?? '' ) ?: ( $a['email'] ?? '' ) ?: '—' ),
                $sl['attendees'] ?? []
            ) );

            // Determine if this row should be checked
            $checked = false;
            if ( $selected_idx >= 0 ) {
                $checked = ( $idx === $selected_idx );
            } elseif ( $sel_start ) {
                $checked = ( $s === $sel_start );
            } elseif ( $status === 'open' && $idx === $suggested_idx ) {
                $checked = true;
            }

            echo '<tr>';
            printf( '<td><input type="radio" name="dcs_selected_slot_index" value="%d"%s></td>', $idx, $checked ? ' checked' : '' );
            echo '<td>' . esc_html( dcs_slot_range( $sl ) ) . '</td>';
            echo '<td>' . esc_html( dcs_format_duration( $dur_mins ) ?: '—' ) . '</td>';
            echo '<td>' . intval( $votes ) . '</td>';
            echo '<td>' . ( $names ?: '—' ) . '</td>';
            echo '<td>' . ( $idx === $suggested_idx ? '<span class="dashicons dashicons-thumbs-up" title="Suggested"></span>' : '' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        wp_nonce_field( 'dcs_close_poll_action', 'dcs_close_poll_nonce' );

        echo '<div style="margin-top:12px;">';
        if ( $status === 'open' ) {
            echo '<button class="button button-primary" name="dcs_close_poll" value="1">'
                . esc_html__( 'Close Poll with Selected Time', 'doodle-clone-scheduler' )
                . '</button>';
        } else {
            echo '<em style="margin-right:8px;">' . esc_html__( 'Poll is currently closed.', 'doodle-clone-scheduler' ) . '</em>';
            echo '<button class="button" name="dcs_reopen_poll" value="1">'
                . esc_html__( 'Reopen Poll', 'doodle-clone-scheduler' )
                . '</button>';
        }
        echo '</div>';
    }

    /**
     * Close / reopen registration for a 1-on-1 event, with a per-slot view of
     * who's booked and whether each slot's meeting details are complete.
     */
    private static function render_close_booking_box( WP_Post $post ): void {
        $status = get_post_meta( $post->ID, '_poll_status', true ) ?: 'open';
        $slots  = get_post_meta( $post->ID, '_meeting_slots', true );
        if ( ! is_array( $slots ) ) $slots = [];
        usort( $slots, fn( $a, $b ) => intval( $a['start'] ?? 0 ) <=> intval( $b['start'] ?? 0 ) );

        $missing = [];
        $rows    = '';
        foreach ( $slots as $sl ) {
            $attendees = is_array( $sl['attendees'] ?? null ) ? $sl['attendees'] : [];
            $details   = dcs_slot_details( $post->ID, $sl );
            $flag      = dcs_meeting_details_missing_link( $details );
            if ( $flag && $attendees ) $missing[] = dcs_slot_range( $sl );

            $names = implode( ', ', array_map(
                fn( $a ) => esc_html( ( $a['name'] ?? '' ) ?: ( $a['email'] ?? '' ) ?: '—' ),
                $attendees
            ) );
            $rows .= '<tr>'
                . '<td>' . esc_html( dcs_slot_range( $sl ) ) . '</td>'
                . '<td>' . count( $attendees ) . ' / ' . intval( $sl['max'] ?? 1 ) . '</td>'
                . '<td>' . ( $names ?: '—' ) . '</td>'
                . '<td' . ( $flag ? ' style="color:#b32d2e;font-weight:600;"' : '' ) . '>' . esc_html( dcs_meeting_details_summary( $details ) ) . '</td>'
                . '</tr>';
        }

        if ( $status === 'closed' ) {
            self::render_announcement_panel( $post->ID, $missing );
        }

        echo '<p>' . esc_html__( 'Closing stops new sign-ups. Once closed, you can email each attendee the final details for their slot (location, meeting link, passcode, dial-in).', 'doodle-clone-scheduler' ) . '</p>';

        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__( 'Time', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Booked', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Attendees', 'doodle-clone-scheduler' ) . '</th>'
            . '<th>' . esc_html__( 'Meeting details', 'doodle-clone-scheduler' ) . '</th>'
            . '</tr></thead><tbody>'
            . ( $rows ?: '<tr><td colspan="4">' . esc_html__( 'No time slots defined yet.', 'doodle-clone-scheduler' ) . '</td></tr>' )
            . '</tbody></table>';

        wp_nonce_field( 'dcs_close_poll_action', 'dcs_close_poll_nonce' );

        echo '<div style="margin-top:12px;">';
        if ( $status === 'open' ) {
            echo '<button class="button button-primary" name="dcs_close_poll" value="1">'
                . esc_html__( 'Close Registration', 'doodle-clone-scheduler' )
                . '</button>';
        } else {
            echo '<em style="margin-right:8px;">' . esc_html__( 'Registration is currently closed.', 'doodle-clone-scheduler' ) . '</em>';
            echo '<button class="button" name="dcs_reopen_poll" value="1">'
                . esc_html__( 'Reopen Registration', 'doodle-clone-scheduler' )
                . '</button>';
        }
        echo '</div>';
    }

    /**
     * @param string[] $missing_links  Slot labels that are online/hybrid, have
     *                                 recipients, but no join link yet.
     */
    private static function render_announcement_panel( int $post_id, array $missing_links = [] ): void {
        $all      = dcs_collect_voter_emails( $post_id );
        $count    = count( $all );
        $sent_at  = get_post_meta( $post_id, '_poll_announce_sent_at', true );
        $is_poll  = in_array( get_post_meta( $post_id, '_meeting_mode', true ), [ 'poll', 'group' ], true );

        echo '<div style="margin-bottom:16px;padding:10px;border:1px solid #dcdcde;background:#fff;">';
        echo '<h4 style="margin:0 0 8px;">' . esc_html__( 'Send Final Details', 'doodle-clone-scheduler' ) . '</h4>';
        printf(
            '<p style="margin:0 0 8px;">%s</p>',
            esc_html( sprintf(
                $is_poll
                    ? __( 'Recipients: %d (all voters)', 'doodle-clone-scheduler' )
                    : __( 'Recipients: %d (all attendees — each gets their own slot)', 'doodle-clone-scheduler' ),
                $count
            ) )
        );

        if ( $missing_links ) {
            echo '<div class="notice notice-warning inline" style="margin:0 0 8px;"><p>'
                . esc_html__( 'These online/hybrid slots have no meeting link yet:', 'doodle-clone-scheduler' )
                . ' <strong>' . esc_html( implode( '; ', $missing_links ) ) . '</strong>'
                . '</p></div>';
        }

        if ( $count > 0 ) {
            $sample = array_slice( $all, 0, 10 );
            echo '<details><summary>' . esc_html__( 'Preview email list', 'doodle-clone-scheduler' ) . '</summary>'
                . '<div style="font-family:monospace;padding-top:6px;">' . esc_html( implode( ', ', $sample ) ) . ( $count > 10 ? ' …' : '' ) . '</div>'
                . '</details>';
        }

        if ( $sent_at ) {
            echo '<p style="margin:8px 0;color:#646970;">'
                . sprintf( esc_html__( 'Last sent: %s', 'doodle-clone-scheduler' ), esc_html( wp_date( 'M j, Y · g:ia T', intval( $sent_at ), dcs_tz() ) ) )
                . '</p>';
        }

        wp_nonce_field( 'dcs_send_announce_action', 'dcs_send_announce_nonce' );
        echo '<p>';
        echo '<button class="button button-primary" name="dcs_send_announce" value="all">' . esc_html__( 'Send to everyone', 'doodle-clone-scheduler' ) . '</button> ';
        if ( $sent_at ) {
            echo '<button class="button" name="dcs_send_announce" value="new">' . esc_html__( 'Send only to people not yet emailed', 'doodle-clone-scheduler' ) . '</button>';
        }
        echo '</p></div>';
    }

    // =========================================================================
    // Save Handlers (called by add_action hooks in init())
    // =========================================================================

    /** Priority 5: Snapshot existing slots before any handler can overwrite them. */
    public static function dcs_snapshot_pre_save( int $post_id, WP_Post $post, bool $update ): void {
        if ( get_post_type( $post_id ) !== 'meeting_event' ) return;
        $existing = get_post_meta( $post_id, '_meeting_slots', true );
        if ( is_array( $existing ) && ! empty( $existing ) ) {
            update_post_meta( $post_id, '_dcs_pre_save_slots', $existing );
        } else {
            delete_post_meta( $post_id, '_dcs_pre_save_slots' );
        }
    }

    /** Priority 10: Write slots submitted via the admin form. */
    public static function save_slots( int $post_id ): void {
        if ( ! self::should_save( $post_id ) ) return;
        if ( empty( $_POST['slots'] ) || ! is_array( $_POST['slots'] ) ) return;

        // WordPress adds slashes to every superglobal; strip them before
        // sanitizing so values like "O'Brien" are not stored as "O\'Brien".
        $posted_slots = wp_unslash( $_POST['slots'] );

        $existing_slots = get_post_meta( $post_id, '_meeting_slots', true );
        $existing_by_id = [];
        if ( is_array( $existing_slots ) ) {
            foreach ( $existing_slots as $es ) {
                if ( ! empty( $es['id'] ) ) $existing_by_id[ $es['id'] ] = $es;
            }
        }

        $clean     = [];
        $overrides = [];
        foreach ( $posted_slots as $s ) {
            if ( empty( $s['date'] ) || empty( $s['time'] ) ) continue;

            $start_ts = dcs_epoch_from_local( sanitize_text_field( $s['date'] ), sanitize_text_field( $s['time'] ) );

            // Sanitize attendees
            $attendees = [];
            if ( ! empty( $s['attendees'] ) && is_array( $s['attendees'] ) ) {
                foreach ( $s['attendees'] as $a ) {
                    $n = sanitize_text_field( $a['name'] ?? '' );
                    $e = sanitize_email( $a['email'] ?? '' );
                    if ( $n || $e ) $attendees[] = [ 'name' => $n, 'email' => $e ];
                }
            }

            $dur_mins = isset( $s['duration_minutes'] ) && $s['duration_minutes'] !== '' ? intval( $s['duration_minutes'] ) : 0;

            // Try to preserve the existing slot ID (so voters can still edit)
            // Match by start timestamp first, then fall back to generating a new ID.
            $slot_id = uniqid( 'slot_' );
            foreach ( $existing_by_id as $eid => $esl ) {
                if ( intval( $esl['start'] ?? 0 ) === $start_ts ) {
                    $slot_id = $eid;
                    break;
                }
            }

            // Details override travels in the same form row as the slot, so it
            // is keyed by this row's final id — survives date/time edits that
            // mint a new id above.
            if ( isset( $s['details'] ) ) {
                $ov = dcs_sanitize_meeting_details( $s['details'], true );
                if ( dcs_meeting_details_has_content( $ov ) ) $overrides[ $slot_id ] = $ov;
            }

            $row = [
                'id'               => $slot_id,
                'date'             => sanitize_text_field( $s['date'] ),
                'time'             => sanitize_text_field( $s['time'] ),
                'max'              => intval( $s['max'] ?? 1 ),
                'attendees'        => $attendees,
                'start'            => $start_ts,
                'duration_minutes' => $dur_mins,
            ];

            if ( $dur_mins > 0 && $start_ts > 0 ) {
                $row['end'] = $start_ts + $dur_mins * 60;
            }

            $clean[] = $row;
        }

        update_post_meta( $post_id, '_meeting_slots', $clean );
        self::$pending_overrides = $overrides;
    }

    /**
     * Priority 15: Save meeting details — the event-wide default from the
     * Meeting Details box plus per-slot overrides collected by save_slots().
     * Overrides for slots that no longer exist are dropped so deleted slots
     * don't leave orphaned passcodes behind.
     */
    public static function save_meeting_details( int $post_id ): void {
        if ( ! self::should_save( $post_id ) ) return;
        if ( ! isset( $_POST['dcs_save_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dcs_save_details_nonce'] ) ), 'dcs_save_details_action' ) ) {
            self::$pending_overrides = null;
            return;
        }

        $stored = dcs_get_meeting_details( $post_id );

        $default = isset( $_POST['dcs_details'] )
            ? dcs_sanitize_meeting_details( wp_unslash( $_POST['dcs_details'] ) )
            : $stored['default'];

        if ( self::$pending_overrides !== null ) {
            $overrides = self::$pending_overrides;
        } else {
            $overrides = $stored['slots'];
        }
        self::$pending_overrides = null;

        $slots     = get_post_meta( $post_id, '_meeting_slots', true );
        $valid_ids = is_array( $slots ) ? array_map( 'dcs_slot_key', $slots ) : [];
        $overrides = array_intersect_key( $overrides, array_flip( $valid_ids ) );

        if ( ! dcs_meeting_details_has_content( $default ) && ! $overrides ) {
            delete_post_meta( $post_id, '_meeting_details' );
            return;
        }
        update_post_meta( $post_id, '_meeting_details', [ 'default' => $default, 'slots' => $overrides ] );
    }

    /** Priority 20: Save meeting mode and default duration. */
    public static function save_mode( int $post_id ): void {
        if ( ! self::should_save( $post_id ) ) return;
        if ( isset( $_POST['dcs_meeting_mode'] ) ) {
            $mode = $_POST['dcs_meeting_mode'] === 'poll' ? 'poll' : 'booking';
            update_post_meta( $post_id, '_meeting_mode', $mode );
        }
        if ( isset( $_POST['dcs_default_duration'] ) ) {
            $dur = max( 1, intval( $_POST['dcs_default_duration'] ) );
            update_post_meta( $post_id, '_meeting_default_duration', $dur );
        }
    }

    /** Priority 30: Save open/closed status (all meeting types). */
    public static function save_poll_status( int $post_id, WP_Post $post ): void {
        if ( ! self::should_save( $post_id ) ) return;

        if ( isset( $_POST['dcs_poll_status'] ) ) {
            $val = $_POST['dcs_poll_status'] === 'closed' ? 'closed' : 'open';
            update_post_meta( $post_id, '_poll_status', $val );
        } elseif ( ! get_post_meta( $post_id, '_poll_status', true ) ) {
            update_post_meta( $post_id, '_poll_status', 'open' );
        }
    }

    /**
     * Priority 40: Handle the Close / Reopen buttons.
     * Group poll: close on a chosen slot (snapshot it). 1-on-1: close or
     * reopen registration — there's no single winner to record.
     */
    public static function handle_poll_close_reopen( int $post_id, WP_Post $post ): void {
        if ( ! self::should_save( $post_id ) ) return;
        $mode    = get_post_meta( $post_id, '_meeting_mode', true ) ?: 'booking';
        $is_poll = in_array( $mode, [ 'poll', 'group' ], true );

        if ( ! $is_poll ) {
            $action = ! empty( $_POST['dcs_reopen_poll'] ) ? 'open' : ( ! empty( $_POST['dcs_close_poll'] ) ? 'closed' : '' );
            if ( $action === '' ) return;
            if ( ! isset( $_POST['dcs_close_poll_nonce'] ) || ! wp_verify_nonce( $_POST['dcs_close_poll_nonce'], 'dcs_close_poll_action' ) ) return;
            update_post_meta( $post_id, '_poll_status', $action );
            return;
        }

        // Reopen
        if ( ! empty( $_POST['dcs_reopen_poll'] ) && $_POST['dcs_reopen_poll'] == '1' ) {
            if ( ! isset( $_POST['dcs_close_poll_nonce'] ) || ! wp_verify_nonce( $_POST['dcs_close_poll_nonce'], 'dcs_close_poll_action' ) ) return;
            update_post_meta( $post_id, '_poll_status', 'open' );
            delete_post_meta( $post_id, '_poll_selected_slot_id' );
            delete_post_meta( $post_id, '_poll_selected_slot_snapshot' );
            delete_post_meta( $post_id, '_poll_selected_slot_index' );
            delete_post_meta( $post_id, '_poll_selected_start' );
            delete_post_meta( $post_id, '_poll_selected_end' );
            return;
        }

        // Close
        if ( empty( $_POST['dcs_close_poll'] ) || $_POST['dcs_close_poll'] != '1' ) return;
        if ( ! isset( $_POST['dcs_close_poll_nonce'] ) || ! wp_verify_nonce( $_POST['dcs_close_poll_nonce'], 'dcs_close_poll_action' ) ) return;

        $sel_idx = isset( $_POST['dcs_selected_slot_index'] ) && $_POST['dcs_selected_slot_index'] !== ''
            ? intval( $_POST['dcs_selected_slot_index'] )
            : -1;

        if ( $sel_idx < 0 ) return;

        $slots = get_post_meta( $post_id, '_meeting_slots', true );
        if ( ! is_array( $slots ) || ! isset( $slots[ $sel_idx ] ) ) return;

        $winner = dcs_normalize_slot_timestamps( $slots[ $sel_idx ] );
        $slots[ $sel_idx ] = $winner;
        update_post_meta( $post_id, '_meeting_slots', $slots );

        update_post_meta( $post_id, '_poll_status',                 'closed' );
        update_post_meta( $post_id, '_poll_selected_slot_index',    $sel_idx );
        update_post_meta( $post_id, '_poll_selected_slot_id',       $winner['id'] ?? '' );
        update_post_meta( $post_id, '_poll_selected_slot_snapshot', $winner );
        update_post_meta( $post_id, '_poll_selected_start',         $winner['start'] ?? 0 );
        update_post_meta( $post_id, '_poll_selected_end',           $winner['end'] ?? 0 );
    }

    /** Priority 50: Send announcement emails if the admin clicked the send button. */
    public static function handle_announcement( int $post_id, WP_Post $post ): void {
        if ( ! self::should_save( $post_id ) ) return;
        if ( empty( $_POST['dcs_send_announce'] ) ) return;

        $mode_val = sanitize_text_field( $_POST['dcs_send_announce'] );
        if ( ! in_array( $mode_val, [ 'all', 'new' ], true ) ) return;
        if ( ! isset( $_POST['dcs_send_announce_nonce'] ) || ! wp_verify_nonce( $_POST['dcs_send_announce_nonce'], 'dcs_send_announce_action' ) ) return;

        if ( ! dcs_event_is_closed( $post_id ) ) return;

        // A closed poll must also have a chosen slot; a closed 1-on-1 needs nothing more.
        if ( in_array( get_post_meta( $post_id, '_meeting_mode', true ), [ 'poll', 'group' ], true ) ) {
            $snap = get_post_meta( $post_id, '_poll_selected_slot_snapshot', true );
            if ( ! is_array( $snap ) || empty( $snap['start'] ) ) return;
        }

        DCS_Mailer::send_final_details( $post_id, $mode_val );
    }

    /** Priority 200: Normalise all slot timestamps after slots are saved. */
    public static function normalize_slots( int $post_id ): void {
        if ( get_post_type( $post_id ) !== 'meeting_event' ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

        $slots = get_post_meta( $post_id, '_meeting_slots', true );
        if ( ! is_array( $slots ) || empty( $slots ) ) return;

        $changed = false;
        foreach ( $slots as $i => $slot ) {
            $normalized = dcs_normalize_slot_timestamps( $slot );
            if ( $normalized !== $slot ) {
                $slots[ $i ] = $normalized;
                $changed = true;
            }
        }
        if ( $changed ) {
            update_post_meta( $post_id, '_meeting_slots', $slots );
        }
    }

    /**
     * Priority 500: Restore any duration/end data that earlier save handlers may
     * have dropped, using the pre-save snapshot taken at priority 5.
     */
    public static function merge_durations_from_snapshot( int $post_id ): void {
        if ( get_post_type( $post_id ) !== 'meeting_event' ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

        $slots    = get_post_meta( $post_id, '_meeting_slots', true );
        $snapshot = get_post_meta( $post_id, '_dcs_pre_save_slots', true );
        if ( ! is_array( $slots ) || ! is_array( $snapshot ) ) return;

        // Build lookup by stable slot ID and start timestamp
        $by_id    = [];
        $by_start = [];
        foreach ( $snapshot as $b ) {
            if ( ! empty( $b['id'] ) ) $by_id[ $b['id'] ] = $b;
            if ( ! empty( $b['start'] ) ) $by_start[ intval( $b['start'] ) ] = $b;
        }

        $changed = false;
        foreach ( $slots as $i => $sl ) {
            $d = intval( $sl['duration_minutes'] ?? 0 );
            $e = intval( $sl['end'] ?? 0 );
            if ( $d > 0 && $e > 0 ) continue; // nothing to repair

            $src = null;
            if ( ! empty( $sl['id'] ) && isset( $by_id[ $sl['id'] ] ) ) {
                $src = $by_id[ $sl['id'] ];
            } elseif ( ! empty( $sl['start'] ) && isset( $by_start[ intval( $sl['start'] ) ] ) ) {
                $src = $by_start[ intval( $sl['start'] ) ];
            }

            if ( is_array( $src ) ) {
                $bd = intval( $src['duration_minutes'] ?? 0 );
                $be = intval( $src['end'] ?? 0 );
                if ( $d <= 0 && $bd > 0 ) { $sl['duration_minutes'] = $bd; $d = $bd; $changed = true; }
                if ( $e <= 0 && $be > 0 ) { $sl['end'] = $be; $changed = true; }
                if ( $e <= 0 && intval( $sl['start'] ?? 0 ) > 0 && $d > 0 ) {
                    $sl['end'] = intval( $sl['start'] ) + $d * 60;
                    $changed = true;
                }
                $slots[ $i ] = $sl;
            }
        }

        if ( $changed ) {
            update_post_meta( $post_id, '_meeting_slots', $slots );
        }
        delete_post_meta( $post_id, '_dcs_pre_save_slots' );
    }

    /** Runs after Gutenberg REST saves to ensure normalization still happens. */
    public static function rest_after_insert( WP_Post $post ): void {
        if ( get_post_type( $post->ID ) !== 'meeting_event' ) return;
        self::normalize_slots( $post->ID );
    }

    // =========================================================================
    // Admin footer: slot builder JS
    // =========================================================================

    public static function admin_footer_scripts(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'meeting_event' ) return;
        ?>
        <style>
        #dcs-slot-wrapper .dcs-slot label { display:inline-block; min-width:80px; }
        </style>
        <script>
        (function($){
            function durationSelect(name, selected) {
                var opts = [30,60,90,120,150,180,210,240,270,300,360,420,480,540,600];
                var labels = {30:'30m',60:'1h',90:'1h 30m',120:'2h',150:'2h 30m',180:'3h',210:'3h 30m',240:'4h',270:'4h 30m',300:'5h',360:'6h',420:'7h',480:'8h',540:'9h',600:'10h'};
                var s = '<select name="'+name+'" style="min-width:100px;"><option value="">—</option>';
                opts.forEach(function(m){ s += '<option value="'+m+'"'+(String(selected)===String(m)?' selected':'')+'>'+labels[m]+'</option>'; });
                return s + '</select>';
            }
            function attendeeRow(i, j, n, e) {
                return '<div class="dcs-attendee-row">'
                    + '<input type="text"  name="slots['+i+'][attendees]['+j+'][name]"  value="'+(n||'')+'" placeholder="Name">'
                    + '<input type="email" name="slots['+i+'][attendees]['+j+'][email]" value="'+(e||'')+'" placeholder="Email">'
                    + '<button type="button" class="dcs-remove-att button">Remove</button>'
                    + '</div>';
            }
            function slotBlock(i) {
                return '<div class="dcs-slot" data-index="'+i+'">'
                    + '<label>Date:</label> <input type="date" name="slots['+i+'][date]" required><br>'
                    + '<label>Time:</label> <input type="time" name="slots['+i+'][time]" required>'
                    + '<label>Duration:</label> '+durationSelect('slots['+i+'][duration_minutes]','')+' <br>'
                    + '<label>Max:</label>  <input type="number" name="slots['+i+'][max]" min="1" value="1"><br>'
                    + '<div class="dcs-attendees">' + attendeeRow(i, 0, '', '') + '</div>'
                    + '<button type="button" class="dcs-add-att button">Add Attendee</button> '
                    + '<button type="button" class="dcs-remove-slot button button-link-delete">Remove Slot</button>'
                    + $('#dcs-slot-override-tpl').html().replace(/__i__/g, i)
                    + '<hr></div>';
            }

            // Show only the fields relevant to the chosen format. Unspecified
            // (or "use event default") shows everything.
            function toggleDetailFields($select) {
                var v = $select.val();
                var $d = $select.closest('.dcs-details');
                $d.find('.dcs-d-inperson').toggle(v !== 'online');
                $d.find('.dcs-d-online').toggle(v !== 'in_person');
            }
            $(document).on('change', 'select.dcs-format', function(){ toggleDetailFields($(this)); });
            $('select.dcs-format').each(function(){ toggleDetailFields($(this)); });

            $(document).on('click', '.dcs-add-att', function(){
                var $slot  = $(this).closest('.dcs-slot');
                var i      = $slot.data('index');
                var count  = $slot.find('.dcs-attendee-row').length;
                $slot.find('.dcs-attendees').append(attendeeRow(i, count, '', ''));
            });

            $(document).on('click', '.dcs-remove-att', function(){
                $(this).closest('.dcs-attendee-row').remove();
            });

            $('#dcs-add-slot').on('click', function(){
                var next = $('#dcs-slot-wrapper .dcs-slot').length;
                $('#dcs-slot-wrapper').append(slotBlock(next));
            });

            $(document).on('click', '.dcs-remove-slot', function(){
                $(this).closest('.dcs-slot').remove();
                // Re-index remaining slots
                $('#dcs-slot-wrapper .dcs-slot').each(function(idx){
                    var $s = $(this);
                    $s.attr('data-index', idx);
                    $s.find('input, select, textarea').each(function(){
                        var name = $(this).attr('name');
                        if (!name) return;
                        name = name.replace(/slots\[\d+\]/, 'slots['+idx+']');
                        var attIdx = $(this).closest('.dcs-attendee-row').index();
                        name = name.replace(/attendees\]\[\d+\]/, 'attendees]['+attIdx+']');
                        $(this).attr('name', name);
                    });
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Returns true if it is safe to run a save handler:
     * - Not an autosave
     * - Not a revision
     * - Current user can edit the post
     * - Correct post type
     */
    private static function should_save( int $post_id ): bool {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return false;
        if ( wp_is_post_revision( $post_id ) ) return false;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return false;
        if ( get_post_type( $post_id ) !== 'meeting_event' ) return false;
        return true;
    }
}
