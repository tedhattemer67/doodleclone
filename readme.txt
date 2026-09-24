=== Doodle Clone Scheduler ===
Contributors: tedhattemer
Tags: scheduling, meetings, polls, availability, booking
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 2.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ad-free scheduling for 1-on-1 / limited-capacity bookings and group availability polls.

== Description ==

Doodle Clone Scheduler covers the common "find a time" workflows without the
ads and clutter of hosted schedulers:

* **1-on-1 / limited capacity** — attendees pick a single slot; each slot has a
  capacity and fills up.
* **Group availability poll** — attendees check every slot that works; the
  organiser reviews the results and closes the poll on a final time.

Other features:

* Magic edit links (HMAC-signed, no database table) so voters can revise their
  availability for 30 days.
* Confirmation emails with an .ics attachment and an "Add to Google Calendar"
  link.
* Admin poll-results roster with per-voter edit-link status.
* Meeting details per event, overridable per slot: in person / online /
  hybrid, address, Zoom/Teams link, meeting ID, passcode, dial-in numbers and
  notes. Never shown on the public page — only emailed as final details.
* Close & send final details: close a poll on its chosen time, or close
  1-on-1 registration, then email everyone their slot's details (with .ics).
  Re-send to everyone, or only to people not yet emailed.
* `[doodle_schedule_overview]` shortcode listing all events and their slots
  (booker names shown only to logged-in editors).

Times are handled in a single configurable timezone — define `DCS_TIMEZONE` in
wp-config.php to pin one, or leave it unset to use the site's own timezone.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin, or extract the
   `doodle-clone-scheduler` folder into `wp-content/plugins/`.
2. Activate the plugin.
3. Create a **Meeting Event**, choose its type (booking or group poll), add
   time slots, and publish. The booking / poll form is appended to the event's
   page automatically.

== Uninstall ==

Deleting the plugin leaves all Meeting Event posts and their data untouched. If
you want that content gone, delete the Meeting Events yourself before or after
removing the plugin.

== Changelog ==

= 2.2.0 =
* New **Meeting Details** box: format (in person / online / hybrid),
  address or room, meeting link (https only), meeting ID, passcode, dial-in
  numbers and notes. Set once for the event; any slot can override
  individual fields. A slot with its own meeting link is treated as a
  separate meeting — its meeting ID, passcode and dial-in never fall back to
  the event default (no Zoom passcode on a Teams slot). Stored separately
  from slot data (`_meeting_details`) and never rendered on the public page
  or returned by the booking endpoint.
* 1-on-1 events can now be closed: closing stops new sign-ups (the public
  page shows "Registration is closed") and can be reopened.
* **Send final details** (replaces "Send announcement"), for both types:
  * Group poll: every voter gets the chosen time plus its meeting details.
  * 1-on-1: each attendee gets their own slot's time and details.
  * "Send to everyone" or "Send only to people not yet emailed".
  * Details are read live at send time, so a link changed after closing goes
    out on the next re-send.
  * The admin gets one summary email per send.
  * Warns when an online/hybrid slot with recipients has no meeting link.
* 1-on-1 booking confirmations now note that meeting details will follow.
* .ics fixes: UID now includes the slot id (separate slots of one event no
  longer collapse into one calendar entry); TEXT values are escaped per
  RFC 5545 and long lines folded; LOCATION / URL / DESCRIPTION carry the
  meeting details; SEQUENCE increases on each send so re-imports update the
  existing entry instead of duplicating it.

= 2.1.1 =
* Removed the "Select all times that work for you…" instruction line from
  public poll pages.

= 2.1.0 =
* Reconciled with the earlier, never-merged `harden-1on1-booking` branch:
  * 1-on-1 booking: one booking per email address per event, matched on the
    normalised address (Gmail dot/+tag variants included).
  * 1-on-1 booking: a slot more than 15 minutes past its start time can no
    longer be booked. Adjustable via the `dcs_booking_past_grace` filter
    (seconds).
  * Client IP detection (used by rate limiting) is now filterable via
    `dcs_client_ip`, for sites that need to read a trusted proxy/CDN header
    instead of the default `REMOTE_ADDR`.
* The booking/poll locking added in 2.0.7 now uses a real MySQL session
  advisory lock (`GET_LOCK`/`RELEASE_LOCK`) instead of an options-table-based
  lock. Functionally equivalent, but it can't be left stale by a crashed
  request — the lock releases automatically when its DB connection closes,
  so there's no TTL/staleness bookkeeping to get wrong.

= 2.0.8 =
* Added lightweight, dependency-free bot mitigation to the public booking/poll
  form: a honeypot field invisible to real visitors, and a signed render
  timestamp rejecting submissions that arrive faster than a person could
  plausibly fill the form. No third-party service, no CAPTCHA widget, no
  added friction for real users.

= 2.0.7 =
* Fixed: two people booking the last spot in a capped slot at nearly the same
  moment could both get confirmed, overbooking it. Booking a slot now holds a
  brief per-slot lock across the capacity check and the write.
* Fixed: two people submitting a group poll response at nearly the same
  moment could silently clobber each other's selections (whichever write
  landed second won). Poll submissions now hold a brief per-event lock across
  the read-modify-write, and re-read the latest data before applying changes.
* Security: the booking/poll AJAX endpoint now requires the event to actually
  be published — a draft, scheduled, or trashed event could previously still
  be booked or voted on by guessing its ID directly.
* Security: the magic edit-link signing key no longer falls back to a fixed
  string hardcoded in the plugin source if a site is somehow missing its
  normal WordPress salts. It now falls back to a random, site-specific secret
  generated once and stored in the database.

= 2.0.6 =
* Fixed: slot selection (booking, poll voting, prefill, and the closed-poll
  voter summary) now identifies a slot by its own stable ID instead of its
  rendered date/time label. Previously, two slots that happened to render an
  identical label (e.g. an accidentally duplicated slot) would be silently
  confused with each other — a booking or vote could land on the wrong slot.
  This also removes a timezone inconsistency: the old label was formatted in
  the site's timezone while everything else in the plugin uses the configured
  plugin timezone.
* Note for existing sites: if a visitor already has the booking/poll form open
  in a browser tab from before this update, ask them to refresh before
  submitting.

= 2.0.5 =
* Security: a group poll vote can no longer be overwritten by submitting
  someone else's email address. Editing an existing response now requires the
  edit-link token that was emailed to that voter; first-time submissions, and
  voters an admin entered manually in the Slots box (never issued a token),
  are unaffected.
* Security: added rate limiting to the public booking/poll AJAX endpoint (per
  IP, and per event+email) so it can't be scripted to flood slot capacity or
  send bulk confirmation emails.
* The frontend script now keeps the edit token in the live form (not just the
  URL) after a poll submission, so resubmitting without a page reload still
  works under the new authorization check.

= 2.0.4 =
* Removed uninstall.php entirely. In 2.0.2/2.0.3 it deleted every Meeting Event
  and all plugin data whenever the plugin was deleted — including during a
  delete-then-reinstall upgrade. It should never have removed content. Plugin
  data now always survives deletion.

= 2.0.3 =
* Fixed: the admin Voter Roster showed slot times in the WordPress site
  timezone while every other view (Poll Results, Close Group Poll, public page)
  used the plugin timezone, so the two disagreed by the UTC offset. The roster
  now uses the same formatter as Poll Results.
* The announcement panel's "Last sent" timestamp now shows in the plugin
  timezone too.

= 2.0.2 =
* Security: verify the close-poll nonce on the "Reopen Poll" action too.
* Added uninstall.php — removes all Meeting Event posts, plugin post meta, and
  rate-limit transients when the plugin is deleted.
* Added readme.txt.
* Timezone now falls back to the site's own setting (wp_timezone()) when
  DCS_TIMEZONE is undefined or invalid; the constant still wins when set.
* Declared the plugin license in the header.

= 2.0.1 =
* Fixed: the admin Voter Roster's edit-link status was always "No link sent" for
  Gmail addresses containing dots or "+tags" (meta-key normalisation mismatch).
* Edit-link tokens now use URL-safe base64; links emailed by older versions are
  still accepted for their full 30-day life.
* Unslash $_POST / $_GET before sanitising so names like "O'Brien" are stored
  and displayed without a stray backslash.
* Guarded array access against PHP 8 "undefined key" warnings.
* `[doodle_schedule_overview]` shows booker names only to users who can edit
  posts; everyone else sees "Booked" / "Available".
* Removed a dead post-meta write in the mailer.
* Declared Requires PHP: 8.0 and Requires at least: 6.0.

= 2.0.0 =
* First version tracked in git.

== Upgrade Notice ==

= 2.0.5 =
Security fixes: closes a vote/booking-hijack hole (anyone could overwrite
another voter's poll response by reusing their email) and adds rate limiting
to the public AJAX endpoint. Recommended before wider public use.

= 2.0.4 =
IMPORTANT: fixes a data-loss bug in 2.0.2/2.0.3 where deleting the plugin wiped
all Meeting Events. Upgrade before any further delete/reinstall.

= 2.0.3 =
Fixes the Voter Roster showing slot times in the wrong timezone. Display only.

= 2.0.2 =
Adds proper uninstall cleanup and a site-timezone fallback. No data migration.
