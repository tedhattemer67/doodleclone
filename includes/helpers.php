<?php
/**
 * DCS Helpers
 * Pure utility functions with no side effects or WP hook registrations.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// Timezone
// ---------------------------------------------------------------------------

/**
 * Returns the timezone used for all slot wall-clock times.
 *
 * Order of preference:
 *   1. The DCS_TIMEZONE constant, when defined and valid.
 *   2. The site's own timezone (Settings → General), via wp_timezone().
 *   3. UTC, as a last resort.
 */
function dcs_tz(): DateTimeZone {
    static $tz = null;
    if ( $tz instanceof DateTimeZone ) {
        return $tz;
    }

    if ( defined( 'DCS_TIMEZONE' ) && DCS_TIMEZONE ) {
        try {
            $tz = new DateTimeZone( DCS_TIMEZONE );
            return $tz;
        } catch ( Exception $ex ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[DCS] Invalid DCS_TIMEZONE "' . DCS_TIMEZONE . '": ' . $ex->getMessage() );
            }
        }
    }

    $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
    return $tz;
}

// ---------------------------------------------------------------------------
// Slot data helpers
// ---------------------------------------------------------------------------

/**
 * Returns a human-readable label for a slot, preferring stored label,
 * then computed from start/end timestamps, then a fallback.
 */
function dcs_slot_label( array $slot ): string {
    if ( ! empty( $slot['label'] ) ) {
        return $slot['label'];
    }
    $start = isset( $slot['start'] ) ? intval( $slot['start'] ) : 0;
    $end   = isset( $slot['end'] )   ? intval( $slot['end'] )   : 0;
    if ( $start ) {
        $fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        $s = date_i18n( $fmt, $start );
        if ( $end && $end > $start ) {
            return $s . ' - ' . date_i18n( get_option( 'time_format' ), $end );
        }
        return $s;
    }
    return isset( $slot['id'] ) ? 'Slot #' . $slot['id'] : 'Slot';
}

/**
 * Returns the stable identifier used to refer to a slot in form submissions,
 * AJAX requests, and prefill/matching logic.
 *
 * Prefers the slot's own persistent 'id' (assigned once by
 * DCS_Admin::save_slots() and preserved across edits) over dcs_slot_label().
 * The label is a rendered date/time string in the *site* timezone — two
 * distinct slots can render identically (e.g. an accidental duplicate), and
 * even a single slot's label can shift if the site timezone or date/time
 * format settings change. Using it as an identity key meant a collision
 * silently mismatched a vote/booking to the wrong slot. 'id' has neither
 * problem, so every real identity check should go through this function
 * rather than calling dcs_slot_label() directly.
 */
function dcs_slot_key( array $slot ): string {
    if ( ! empty( $slot['id'] ) ) {
        return (string) $slot['id'];
    }
    // Malformed/legacy data with no id — fall back to the old behaviour
    // rather than failing to identify the slot at all.
    return dcs_slot_label( $slot );
}

/**
 * Formats a timestamp range in the plugin timezone.
 * Returns a string like "Mar 5, 2026 · 2:00pm – 3:00pm ET"
 */
function dcs_format_range( int $start, int $end = 0 ): string {
    if ( $start <= 0 ) return '—';
    try {
        $s_dt = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( dcs_tz() );
        $label = $s_dt->format( 'M j, Y · g:ia' );
        if ( $end > 0 ) {
            $e_dt = ( new DateTimeImmutable( '@' . $end ) )->setTimezone( dcs_tz() );
            $label .= ' – ' . $e_dt->format( 'g:ia' );
        }
        $abbr = ( new DateTime( 'now', dcs_tz() ) )->format( 'T' ); // EDT or EST
        return $label . ' ' . $abbr;
    } catch ( Throwable $ex ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[DCS] dcs_format_range error: ' . $ex->getMessage() );
        }
        return gmdate( 'M j, Y · g:ia', $start ) . ' UTC';
    }
}

/**
 * Formats the range derived from a slot array.
 */
function dcs_slot_range( array $slot ): string {
    $start = isset( $slot['start'] ) ? intval( $slot['start'] ) : 0;
    $end   = isset( $slot['end'] )   ? intval( $slot['end'] )   : 0;
    if ( $end <= 0 && ! empty( $slot['duration_minutes'] ) && $start > 0 ) {
        $end = $start + intval( $slot['duration_minutes'] ) * 60;
    }
    return dcs_format_range( $start, $end );
}

/**
 * Converts a date string + time string (as entered in the admin UI) to a UTC epoch.
 * The strings are interpreted as local wall-clock time in DCS_TIMEZONE.
 */
function dcs_epoch_from_local( string $date, string $time ): int {
    $date = trim( $date );
    $time = trim( $time );
    if ( $date === '' || $time === '' ) return 0;
    try {
        $dt = new DateTimeImmutable( $date . ' ' . $time, dcs_tz() );
        return $dt->getTimestamp();
    } catch ( Exception $ex ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[DCS] dcs_epoch_from_local failed for "' . $date . ' ' . $time . '": ' . $ex->getMessage() );
        }
        return 0;
    }
}

/**
 * Returns a human-readable duration string from a minutes integer.
 * e.g. 90 → "1h 30m", 60 → "1h", 45 → "45m"
 */
function dcs_format_duration( int $minutes ): string {
    if ( $minutes <= 0 ) return '';
    $h  = intdiv( $minutes, 60 );
    $m  = $minutes % 60;
    if ( $h > 0 && $m > 0 ) return $h . 'h ' . $m . 'm';
    if ( $h > 0 )            return $h . 'h';
    return $m . 'm';
}

/**
 * Normalises an email address. Returns '' if not a valid email.
 */
function dcs_normalize_email( string $email ): string {
    $email = strtolower( trim( $email ) );
    if ( ! is_email( $email ) ) return '';
    // Strip Gmail subaddressing and dots for deduplication
    if ( str_ends_with( $email, '@gmail.com' ) || str_ends_with( $email, '@googlemail.com' ) ) {
        [ $local, $domain ] = explode( '@', $email );
        $local = str_replace( '.', '', preg_replace( '/\+.*/', '', $local ) );
        return $local . '@' . $domain;
    }
    return $email;
}

/**
 * Gathers every unique voter email from all slots of a meeting event.
 * Returns an array of normalised email strings.
 */
function dcs_collect_voter_emails( int $post_id ): array {
    $slots = get_post_meta( $post_id, '_meeting_slots', true );
    if ( ! is_array( $slots ) ) return [];
    $map = [];
    foreach ( $slots as $slot ) {
        $candidates = [];
        if ( ! empty( $slot['attendees'] ) && is_array( $slot['attendees'] ) ) {
            $candidates = array_merge( $candidates, $slot['attendees'] );
        }
        foreach ( $candidates as $c ) {
            $raw = '';
            if ( is_string( $c ) )                        $raw = $c;
            elseif ( is_array( $c ) && isset( $c['email'] ) ) $raw = $c['email'];
            $norm = dcs_normalize_email( $raw );
            if ( $norm ) $map[ $norm ] = true;
        }
    }
    return array_keys( $map );
}

/**
 * Ensures a slot array has consistent start, end, and duration_minutes.
 * Never forces a default duration — only derives what it can from existing data.
 */
function dcs_normalize_slot_timestamps( array $slot ): array {
    $s = isset( $slot['start'] ) ? intval( $slot['start'] ) : 0;
    $e = isset( $slot['end'] )   ? intval( $slot['end'] )   : 0;
    $d = isset( $slot['duration_minutes'] ) ? intval( $slot['duration_minutes'] ) : 0;

    // Derive start from date/time labels if missing
    if ( ! $s && ! empty( $slot['date'] ) && ! empty( $slot['time'] ) ) {
        $s = dcs_epoch_from_local( $slot['date'], $slot['time'] );
        if ( $s ) $slot['start'] = $s;
    }

    // Derive end from start + duration
    if ( $s > 0 && $d > 0 && $e <= 0 ) {
        $e = $s + $d * 60;
        $slot['end'] = $e;
    }

    // Derive duration from start + end
    if ( $s > 0 && $e > $s && $d <= 0 ) {
        $d = intdiv( $e - $s, 60 );
        $slot['duration_minutes'] = $d;
    }

    // Ensure end matches start + duration when both are present (duration is authoritative)
    if ( $s > 0 && $d > 0 ) {
        $slot['end'] = $s + $d * 60;
    }

    return $slot;
}

/**
 * Returns a slot's [ start, end ] epochs for outbound mail and calendar files.
 *
 * Recomputes start from the stored wall-clock date/time labels when present
 * (avoids server-timezone drift in older stored epochs), and falls back to
 * end = start when the slot has no duration, so an ICS never has a blank DTEND.
 */
function dcs_slot_times( array $slot ): array {
    $start = intval( $slot['start'] ?? 0 );
    $end   = intval( $slot['end'] ?? 0 );
    $dur   = intval( $slot['duration_minutes'] ?? 0 );

    if ( ! empty( $slot['date'] ) && ! empty( $slot['time'] ) ) {
        $recomputed = dcs_epoch_from_local( $slot['date'], $slot['time'] );
        if ( $recomputed ) {
            $start = $recomputed;
            if ( $dur > 0 ) $end = $start + $dur * 60;
        }
    }
    if ( $end <= 0 && $start > 0 && $dur > 0 ) $end = $start + $dur * 60;
    if ( $end < $start ) $end = $start;

    return [ $start, $end ];
}

/**
 * Returns true when a meeting event has been closed by the organiser
 * (poll closed on a final time, or 1-on-1 registration closed). Applies to
 * every meeting type; the meta key keeps its historical poll-only name.
 */
function dcs_event_is_closed( int $post_id ): bool {
    return get_post_meta( $post_id, '_poll_status', true ) === 'closed';
}

// ---------------------------------------------------------------------------
// Meeting details (location / online join info)
//
// Stored in the _meeting_details post meta, deliberately separate from
// _meeting_slots: the public form and the AJAX booking/vote handlers read and
// rewrite _meeting_slots on every request, and keeping passcodes and join
// links out of that array means nothing on the public side can leak them.
// These details are only ever read by the admin screens and the mailer.
//
//   _meeting_details = [
//     'default' => [ format, location, url, meeting_id, passcode, dial_in, notes ],
//     'slots'   => [ '<slot id>' => [ ...same fields, blank = use default... ] ],
//   ]
// ---------------------------------------------------------------------------

/**
 * Meeting formats, keyed by stored value. '' is only valid on a per-slot
 * override, where it means "use the event default".
 */
function dcs_meeting_formats(): array {
    return [
        'unspecified' => __( 'Not specified', 'doodle-clone-scheduler' ),
        'in_person'   => __( 'In person', 'doodle-clone-scheduler' ),
        'online'      => __( 'Online', 'doodle-clone-scheduler' ),
        'hybrid'      => __( 'Hybrid (in person + online)', 'doodle-clone-scheduler' ),
    ];
}

/** The text fields a details record carries, besides 'format'. */
function dcs_meeting_detail_fields(): array {
    return [ 'location', 'url', 'meeting_id', 'passcode', 'dial_in', 'notes' ];
}

/**
 * Sanitizes one details record from (already unslashed) form input.
 *
 * @param bool $is_override  Overrides may use format '' to inherit the default.
 */
function dcs_sanitize_meeting_details( $raw, bool $is_override = false ): array {
    $raw = is_array( $raw ) ? $raw : [];

    $format = sanitize_key( $raw['format'] ?? '' );
    if ( ! array_key_exists( $format, dcs_meeting_formats() ) ) {
        $format = $is_override ? '' : 'unspecified';
    }

    // Join links must be https — anything else (javascript:, data:, plain
    // http) is dropped rather than emailed out to every attendee.
    $url = trim( (string) ( $raw['url'] ?? '' ) );
    $url = $url !== '' ? esc_url_raw( $url, [ 'https' ] ) : '';

    return [
        'format'     => $format,
        'location'   => sanitize_text_field( $raw['location'] ?? '' ),
        'url'        => $url,
        'meeting_id' => sanitize_text_field( $raw['meeting_id'] ?? '' ),
        'passcode'   => sanitize_text_field( $raw['passcode'] ?? '' ),
        'dial_in'    => sanitize_textarea_field( $raw['dial_in'] ?? '' ),
        'notes'      => sanitize_textarea_field( $raw['notes'] ?? '' ),
    ];
}

/** True if a details record has anything worth storing or showing. */
function dcs_meeting_details_has_content( array $d ): bool {
    if ( ! empty( $d['format'] ) && $d['format'] !== 'unspecified' ) return true;
    foreach ( dcs_meeting_detail_fields() as $f ) {
        if ( ( $d[ $f ] ?? '' ) !== '' ) return true;
    }
    return false;
}

/** Returns the raw stored _meeting_details structure, always well-formed. */
function dcs_get_meeting_details( int $post_id ): array {
    $stored = get_post_meta( $post_id, '_meeting_details', true );
    $stored = is_array( $stored ) ? $stored : [];
    return [
        'default' => is_array( $stored['default'] ?? null ) ? $stored['default'] : [],
        'slots'   => is_array( $stored['slots'] ?? null )   ? $stored['slots']   : [],
    ];
}

/**
 * Returns the effective details for one slot: the slot's own override
 * fields layered over the event-wide default. Blank override fields (and an
 * override format of '') fall through to the default.
 */
function dcs_slot_details( int $post_id, array $slot ): array {
    $all      = dcs_get_meeting_details( $post_id );
    $default  = dcs_sanitize_meeting_details( $all['default'] );
    $override = $all['slots'][ dcs_slot_key( $slot ) ] ?? [];

    $out = $default;
    if ( is_array( $override ) ) {
        $override = dcs_sanitize_meeting_details( $override, true );
        if ( $override['format'] !== '' ) $out['format'] = $override['format'];
        foreach ( dcs_meeting_detail_fields() as $f ) {
            if ( $override[ $f ] !== '' ) $out[ $f ] = $override[ $f ];
        }
    }
    return $out;
}

/** True when a slot is online/hybrid but has no join link to send. */
function dcs_meeting_details_missing_link( array $d ): bool {
    return in_array( $d['format'] ?? '', [ 'online', 'hybrid' ], true ) && ( $d['url'] ?? '' ) === '';
}

/** Short admin-facing summary, e.g. "Online · link set". */
function dcs_meeting_details_summary( array $d ): string {
    $formats = dcs_meeting_formats();
    $format  = $d['format'] ?? 'unspecified';
    if ( ! dcs_meeting_details_has_content( $d ) ) return '—';

    $parts = [ $formats[ $format ] ?? $formats['unspecified'] ];
    if ( in_array( $format, [ 'in_person', 'hybrid' ], true ) && ( $d['location'] ?? '' ) !== '' ) {
        $parts[] = $d['location'];
    }
    if ( in_array( $format, [ 'online', 'hybrid' ], true ) ) {
        $parts[] = ( $d['url'] ?? '' ) !== ''
            ? __( 'link set', 'doodle-clone-scheduler' )
            : __( 'NO LINK', 'doodle-clone-scheduler' );
    }
    return implode( ' · ', $parts );
}

/**
 * Plain-text rendering of a details record, used for the ICS DESCRIPTION
 * and the Google Calendar "details" field. Contains the join link and
 * passcode, so it must only ever go into mail — never onto a public page.
 */
function dcs_meeting_details_plaintext( array $d ): string {
    $formats = dcs_meeting_formats();
    $format  = $d['format'] ?? 'unspecified';
    $lines   = [];

    if ( $format !== 'unspecified' && isset( $formats[ $format ] ) ) {
        $lines[] = __( 'Format:', 'doodle-clone-scheduler' ) . ' ' . $formats[ $format ];
    }
    $labels = [
        'location'   => __( 'Location:', 'doodle-clone-scheduler' ),
        'url'        => __( 'Join link:', 'doodle-clone-scheduler' ),
        'meeting_id' => __( 'Meeting ID:', 'doodle-clone-scheduler' ),
        'passcode'   => __( 'Passcode:', 'doodle-clone-scheduler' ),
    ];
    foreach ( $labels as $f => $label ) {
        if ( ( $d[ $f ] ?? '' ) !== '' ) $lines[] = $label . ' ' . $d[ $f ];
    }
    if ( ( $d['dial_in'] ?? '' ) !== '' ) {
        $lines[] = __( 'Dial-in:', 'doodle-clone-scheduler' );
        $lines[] = $d['dial_in'];
    }
    if ( ( $d['notes'] ?? '' ) !== '' ) {
        $lines[] = '';
        $lines[] = $d['notes'];
    }
    return implode( "\n", $lines );
}

// ---------------------------------------------------------------------------
// ICS generation
// ---------------------------------------------------------------------------

/** Escapes a value for an RFC 5545 TEXT property. */
function dcs_ics_escape( string $text ): string {
    $text = str_replace( [ "\r\n", "\r" ], "\n", $text );
    return str_replace( [ '\\', ';', ',', "\n" ], [ '\\\\', '\;', '\,', '\n' ], $text );
}

/**
 * Folds a content line to RFC 5545's 75-octet limit. Splits on UTF-8
 * character boundaries (mb_strcut) so a multi-byte character is never cut.
 */
function dcs_ics_fold( string $line ): string {
    if ( strlen( $line ) <= 75 ) return $line;
    $out   = [];
    $first = true;
    while ( $line !== '' ) {
        $max   = $first ? 75 : 74; // continuation lines start with a space
        $chunk = function_exists( 'mb_strcut' ) ? mb_strcut( $line, 0, $max, 'UTF-8' ) : substr( $line, 0, $max );
        if ( $chunk === '' ) break;
        $out[] = ( $first ? '' : ' ' ) . $chunk;
        $line  = substr( $line, strlen( $chunk ) );
        $first = false;
    }
    return implode( "\r\n", $out );
}

/**
 * Generates an ICS file attachment for a meeting slot.
 * Returns the path to a temp file, or '' on failure.
 *
 * @param string $slot_key  Stable slot id. Included in the UID so each slot of
 *                          an event is its own calendar entry (with only the
 *                          post ID, calendars merged separate slots together).
 * @param array  $details   Effective meeting details (dcs_slot_details()); adds
 *                          LOCATION / URL and the join info to DESCRIPTION.
 * @param int    $sequence  Bump on each re-send so calendar apps update the
 *                          existing entry instead of adding a duplicate.
 */
function dcs_build_ics( WP_Post $post, int $start_ts, int $end_ts, string $slot_key = '', array $details = [], int $sequence = 0 ): string {
    $host     = parse_url( home_url(), PHP_URL_HOST );
    $uid_base = $post->ID . ( $slot_key !== '' ? '-' . preg_replace( '/[^A-Za-z0-9_.-]/', '', $slot_key ) : '' );
    $uid      = $uid_base . '@' . $host;
    $now      = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Ymd\THis\Z' );
    $dtstart  = ( new DateTimeImmutable( '@' . $start_ts ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
    $dtend    = ( new DateTimeImmutable( '@' . max( $end_ts, $start_ts ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
    $summary  = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

    $desc = get_permalink( $post );
    $info = $details ? dcs_meeting_details_plaintext( $details ) : '';
    if ( $info !== '' ) $desc = $info . "\n\n" . $desc;

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//' . $host . '//DCS//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:'         . $uid,
        'SEQUENCE:'    . max( 0, $sequence ),
        'DTSTAMP:'     . $now,
        'DTSTART:'     . $dtstart,
        'DTEND:'       . $dtend,
        'SUMMARY:'     . dcs_ics_escape( $summary ),
        'DESCRIPTION:' . dcs_ics_escape( $desc ),
    ];

    $format   = $details['format'] ?? 'unspecified';
    $location = '';
    if ( in_array( $format, [ 'in_person', 'hybrid', 'unspecified' ], true ) ) {
        $location = $details['location'] ?? '';
    }
    if ( $location === '' && ( $details['url'] ?? '' ) !== '' ) {
        $location = $details['url'];
    }
    if ( $location !== '' ) {
        $lines[] = 'LOCATION:' . dcs_ics_escape( $location );
    }
    if ( ( $details['url'] ?? '' ) !== '' ) {
        $lines[] = 'URL:' . $details['url'];
    }

    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    $ics = implode( "\r\n", array_map( 'dcs_ics_fold', $lines ) ) . "\r\n";

    $tmp = wp_tempnam( 'dcs-' . $uid_base . '.ics' );
    if ( $tmp && file_put_contents( $tmp, $ics ) !== false ) {
        return $tmp;
    }
    return '';
}

/**
 * Builds a Google Calendar add-event URL for a meeting.
 *
 * $details is empty by default so public callers (the closed-poll notice)
 * never put join links or passcodes into a URL on a public page. Pass the
 * effective details only when building a link for an email.
 */
function dcs_google_calendar_url( WP_Post $post, int $start_ts, int $end_ts, array $details = [] ): string {
    $title   = rawurlencode( html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    $text    = get_permalink( $post );
    $info    = $details ? dcs_meeting_details_plaintext( $details ) : '';
    if ( $info !== '' ) $text = $info . "\n\n" . $text;
    $s       = ( new DateTimeImmutable( '@' . $start_ts ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
    $e       = ( new DateTimeImmutable( '@' . $end_ts ) )  ->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
    $url     = 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . $title . '&dates=' . $s . '/' . $e . '&details=' . rawurlencode( $text );

    $location = '';
    if ( $details ) {
        $location = ( $details['location'] ?? '' ) !== '' ? $details['location'] : ( $details['url'] ?? '' );
    }
    if ( $location !== '' ) $url .= '&location=' . rawurlencode( $location );

    return $url;
}

// ---------------------------------------------------------------------------
// Rate limiting (transient-based, no DB table required)
// ---------------------------------------------------------------------------

/**
 * Returns the requesting client's IP address.
 *
 * Deliberately reads only REMOTE_ADDR by default, never X-Forwarded-For or
 * similar client-supplied headers — those are trivially spoofable and would
 * let an attacker pick their own rate-limit bucket. A site actually running
 * behind a known proxy/CDN can override this via the 'dcs_client_ip' filter
 * (e.g. to read a trusted CF-Connecting-IP header) rather than us guessing
 * at a default that would reopen the spoofing problem for everyone else.
 */
function dcs_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ip = is_string( $ip ) ? $ip : '';
    return (string) apply_filters( 'dcs_client_ip', $ip );
}

/**
 * Best-effort request throttle. Returns true if $bucket has already hit
 * $max_attempts within the last $window_seconds (i.e. the caller should be
 * blocked), false otherwise — and counts the current attempt either way.
 *
 * Backed by a transient counter rather than a lock, so a burst of near-
 * simultaneous requests can race past the limit by a request or two. That's
 * an acceptable tradeoff here: this exists to stop scripted abuse of the
 * public booking/poll endpoint (slot flooding, email spam), not to provide
 * hard guarantees.
 */
function dcs_rate_limited( string $bucket, int $max_attempts, int $window_seconds ): bool {
    $key   = 'dcs_rl_' . md5( $bucket );
    $count = (int) get_transient( $key );

    if ( $count >= $max_attempts ) {
        return true;
    }

    set_transient( $key, $count + 1, $window_seconds );
    return false;
}

// ---------------------------------------------------------------------------
// Advisory locking (for short read-modify-write critical sections)
// ---------------------------------------------------------------------------

/**
 * Runs $fn() while holding a MySQL session advisory lock for $key, and
 * returns whatever $fn() returns — or a WP_Error if the lock couldn't be
 * acquired within $timeout_seconds.
 *
 * GET_LOCK()/RELEASE_LOCK() give a real mutual-exclusion guarantee (unlike
 * dcs_rate_limited()'s transient counter, which can't). They're also
 * self-cleaning in a way an options-row lock isn't: the lock is tied to this
 * request's MySQL connection and is released automatically when that
 * connection closes, so a request that dies mid-critical-section can't wedge
 * it shut — no staleness/TTL bookkeeping needed.
 *
 * $fn() must not itself emit output (no wp_send_json_*) — the caller turns
 * its return value into a response after the lock has already been
 * released, so RELEASE_LOCK() is always reached via the finally below.
 *
 * If $fn() reads post meta, it should drop the object cache for that post
 * first (wp_cache_delete( $post_id, 'post_meta' )) — a persistent object
 * cache can otherwise serve a copy from before the lock was acquired, which
 * would defeat the point of locking at all.
 *
 * @return mixed|WP_Error
 */
function dcs_with_lock( string $key, callable $fn, int $timeout_seconds = 5 ) {
    global $wpdb;

    $name = 'dcs_' . md5( $key );
    $got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout_seconds ) );

    if ( (string) $got !== '1' ) {
        return new WP_Error(
            'dcs_lock_timeout',
            __( 'The system is busy. Please try again in a moment.', 'doodle-clone-scheduler' )
        );
    }

    try {
        return $fn();
    } finally {
        $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
    }
}

// ---------------------------------------------------------------------------
// Magic edit-link tokens (HMAC, no DB required)
// ---------------------------------------------------------------------------

/**
 * Returns the secret key used to sign edit-link tokens.
 * Uses WordPress's AUTH_SALT as a strong per-site secret.
 */
function dcs_token_secret(): string {
    if ( defined( 'AUTH_SALT' ) && AUTH_SALT ) return AUTH_SALT;
    if ( defined( 'NONCE_SALT' ) && NONCE_SALT ) return NONCE_SALT;

    // Last resort for a broken install missing its normal WP salts. Never use
    // a fixed literal here — this plugin's source is readable by anyone, so a
    // hardcoded secret would make every edit-link token forgeable by anyone.
    // Generate a random, site-specific secret once and persist it instead.
    $secret = get_option( 'dcs_fallback_secret' );
    if ( ! is_string( $secret ) || $secret === '' ) {
        $secret = wp_generate_password( 64, true, true );
        update_option( 'dcs_fallback_secret', $secret, false );
    }
    return $secret;
}

/**
 * Creates a signed, time-limited token encoding the event ID and voter email.
 * Format: base64url(json_payload).hmac_sha256
 *
 * The payload is URL-safe base64 (no +, /, or = characters) so the token
 * survives every URL-encoding round trip without corruption.
 */
function dcs_make_edit_token( int $event_id, string $email, int $ttl = 2592000 ): string {
    $payload = wp_json_encode( [
        'event_id' => $event_id,
        'email'    => strtolower( trim( $email ) ),
        'exp'      => time() + max( 300, $ttl ),
    ] );
    $b64 = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
    $sig = hash_hmac( 'sha256', $b64, dcs_token_secret() );
    return $b64 . '.' . $sig;
}

/**
 * Validates and decodes an edit token.
 * Returns the payload array on success, or false if the token is missing,
 * malformed, tampered with, or expired.
 *
 * Use dcs_decode_edit_token() when you need to distinguish expired from invalid.
 */
function dcs_parse_edit_token( string $token ): array|false {
    $result = dcs_decode_edit_token( $token );
    if ( $result['state'] !== 'valid' ) return false;
    return $result['data'];
}

/**
 * Decodes an edit token and returns a state descriptor so callers can
 * distinguish between the three possible outcomes:
 *
 *   [ 'state' => 'valid',   'data' => [...payload...] ]
 *   [ 'state' => 'expired', 'data' => [...payload...] ]  — signature OK, TTL elapsed
 *   [ 'state' => 'invalid', 'data' => null ]             — missing, malformed, or tampered
 *
 * This is the preferred function when you need to show the user a specific
 * message for an expired link rather than a generic failure.
 */
function dcs_decode_edit_token( string $token ): array {
    $invalid = [ 'state' => 'invalid', 'data' => null ];

    if ( ! $token || strpos( $token, '.' ) === false ) return $invalid;

    [ $b64, $sig ] = explode( '.', $token, 2 );

    // Verify signature first — never decode an untrusted payload
    $expected = hash_hmac( 'sha256', $b64, dcs_token_secret() );
    if ( ! hash_equals( $expected, $sig ) ) return $invalid;

    // Accept both URL-safe (current) and standard (legacy) base64 payloads so
    // edit links emailed before this change keep working for their full TTL.
    $data = json_decode( base64_decode( strtr( $b64, '-_', '+/' ) ), true );
    if ( ! is_array( $data ) || empty( $data['event_id'] ) || empty( $data['email'] ) || empty( $data['exp'] ) ) {
        return $invalid;
    }

    $data['email']    = strtolower( trim( $data['email'] ) );
    $data['event_id'] = intval( $data['event_id'] );

    if ( time() > intval( $data['exp'] ) ) {
        return [ 'state' => 'expired', 'data' => $data ];
    }

    return [ 'state' => 'valid', 'data' => $data ];
}

// ---------------------------------------------------------------------------
// Anti-bot form timing (HMAC-signed render timestamp)
// ---------------------------------------------------------------------------

/**
 * Creates a signed token encoding "now", embedded as a hidden field when the
 * form is rendered. Signed (not a plain timestamp) so it can't just be
 * backdated by a script that wants to look slow.
 */
function dcs_make_timing_token(): string {
    $ts  = (string) time();
    $sig = hash_hmac( 'sha256', $ts, dcs_token_secret() );
    return $ts . '.' . $sig;
}

/**
 * Returns the number of seconds between when the form was rendered and now,
 * or null if the token is missing, malformed, or tampered with.
 *
 * Deliberately returns null rather than a false-ish age on failure, so
 * callers can tell "no usable signal" apart from "arrived instantly" — an
 * oddly-cached or stripped-down page shouldn't be treated the same as an
 * actual too-fast bot submission.
 */
function dcs_timing_token_age( string $token ): ?int {
    if ( ! $token || strpos( $token, '.' ) === false ) return null;

    [ $ts, $sig ] = explode( '.', $token, 2 );
    $expected = hash_hmac( 'sha256', $ts, dcs_token_secret() );
    if ( ! hash_equals( $expected, $sig ) ) return null;

    $ts = intval( $ts );
    if ( $ts <= 0 ) return null;

    return time() - $ts;
}
