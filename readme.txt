=== Doodle Clone Scheduler ===
Contributors: tedhattemer
Tags: scheduling, meetings, polls, availability, booking
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 2.0.4
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
* One-click announcement email to all voters once a time is chosen.
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

= 2.0.4 =
IMPORTANT: fixes a data-loss bug in 2.0.2/2.0.3 where deleting the plugin wiped
all Meeting Events. Upgrade before any further delete/reinstall.

= 2.0.3 =
Fixes the Voter Roster showing slot times in the wrong timezone. Display only.

= 2.0.2 =
Adds proper uninstall cleanup and a site-timezone fallback. No data migration.
