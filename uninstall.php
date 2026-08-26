<?php
/**
 * Uninstall handler for Doodle Clone Scheduler.
 *
 * Runs only when the plugin is deleted from the WordPress admin (Plugins →
 * Delete). Deactivating the plugin does NOT trigger this file.
 *
 * This removes everything the plugin created:
 *   - every "meeting_event" post (each poll / booking event)
 *   - all plugin post meta (_meeting_*, _poll_*, _dcs_*)
 *   - the poll-confirmation rate-limit transients
 *
 * A meeting_event post carries all of its slots, votes and bookings in post
 * meta, so there is no "keep the content" middle ground — if you may want the
 * data back, export it before deleting the plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// --- 1. Delete the meeting_event posts (also clears their own post meta) ---
$event_ids = $wpdb->get_col(
    $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'meeting_event' )
);

foreach ( $event_ids as $event_id ) {
    wp_delete_post( (int) $event_id, true );
}

// --- 2. Sweep any plugin meta left on other post types (defensive) ---
// Backslash escapes the LIKE wildcard so "_" matches a literal underscore.
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta}
      WHERE meta_key LIKE '\\_meeting\\_%'
         OR meta_key LIKE '\\_poll\\_%'
         OR meta_key LIKE '\\_dcs\\_%'"
);

// --- 3. Remove poll-confirmation rate-limit transients ---
$wpdb->query(
    "DELETE FROM {$wpdb->options}
      WHERE option_name LIKE '\\_transient\\_dcs\\_%'
         OR option_name LIKE '\\_transient\\_timeout\\_dcs\\_%'"
);

// --- 4. Clear any cached object data ---
wp_cache_flush();
