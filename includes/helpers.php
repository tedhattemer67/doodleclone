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

// ---------------------------------------------------------------------------
// ICS generation
// ---------------------------------------------------------------------------

/**
 * Generates an ICS file attachment for a confirmed meeting slot.
 * Returns the path to a temp file, or '' on failure.
 */
function dcs_build_ics( WP_Post $post, int $start_ts, int $end_ts ): string {
    $uid      = $post->ID . '@' . parse_url( home_url(), PHP_URL_HOST );
    $now      = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Ymd\THis\Z' );
    $dtstart  = ( new DateTimeImmutable( '@' . $start_ts ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
    $dtend    = ( new DateTimeImmutable( '@' . max( $end_ts, $start_ts ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
    $summary  = addcslashes( html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ',;' );
    $desc     = addcslashes( get_permalink( $post ), ',;' );
    $host     = parse_url( home_url(), PHP_URL_HOST );

    $ics = implode( "\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//' . $host . '//DCS//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:'      . $uid,
        'DTSTAMP:'  . $now,
        'DTSTART:'  . $dtstart,
        'DTEND:'    . $dtend,
        'SUMMARY:'  . $summary,
        'DESCRIPTION:' . $desc,
        'END:VEVENT',
        'END:VCALENDAR',
        '',
    ] );

    $tmp = wp_tempnam( 'dcs-' . $post->ID . '.ics' );
    if ( $tmp && file_put_contents( $tmp, $ics ) !== false ) {
        return $tmp;
    }
    return '';
}

/**
 * Builds a Google Calendar add-event URL for a meeting.
 */
function dcs_google_calendar_url( WP_Post $post, int $start_ts, int $end_ts ): string {
    $title   = rawurlencode( html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    $details = rawurlencode( get_permalink( $post ) );
    $s       = ( new DateTimeImmutable( '@' . $start_ts ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
    $e       = ( new DateTimeImmutable( '@' . $end_ts ) )  ->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
    return 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . $title . '&dates=' . $s . '/' . $e . '&details=' . $details;
}

// ---------------------------------------------------------------------------
// Rate limiting (transient-based, no DB table required)
// ---------------------------------------------------------------------------

/**
 * Returns the requesting client's IP address.
 *
 * Deliberately reads only REMOTE_ADDR, never X-Forwarded-For or similar
 * client-supplied headers — those are trivially spoofable and would let an
 * attacker pick their own rate-limit bucket. Sites behind a proxy that need
 * the real client IP should resolve it via their proxy config, not here.
 */
function dcs_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string( $ip ) ? $ip : '';
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
 * Attempts to acquire a short-lived advisory lock for $key. Returns true if
 * acquired, false if someone else currently holds it.
 *
 * A transient-based "check then set" can't guarantee exclusivity — two
 * requests can both see the key absent and both proceed. This instead relies
 * on add_option(), which fails if the option row already exists (a unique
 * key constraint at the DB level), giving a real mutual-exclusion guarantee
 * without a custom table.
 *
 * The lock has no explicit release-on-crash mechanism (there's no "unlock on
 * disconnect" for options), so it stores its acquisition time and is treated
 * as free once older than $ttl_seconds — a request that dies mid-critical-
 * section can't wedge the lock shut forever. Callers should still call
 * dcs_release_lock() as soon as the critical section ends.
 */
function dcs_acquire_lock( string $key, int $ttl_seconds = 10 ): bool {
    $option = 'dcs_lock_' . md5( $key );

    if ( add_option( $option, time(), '', 'no' ) ) {
        return true;
    }

    $held_since = (int) get_option( $option );
    if ( $held_since && ( time() - $held_since ) > $ttl_seconds ) {
        update_option( $option, time(), false );
        return true;
    }

    return false;
}

/** Releases a lock acquired via dcs_acquire_lock(). */
function dcs_release_lock( string $key ): void {
    delete_option( 'dcs_lock_' . md5( $key ) );
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
