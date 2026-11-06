# Doodle Clone Scheduler

A WordPress plugin for "find a time" scheduling without the ads and clutter of
hosted schedulers. Run 1-on-1 bookings, group availability polls and
multi-part session series from your own site. Attendees don't need accounts,
and visitors' browsers make no third-party requests.

- **Version:** 2.4.2
- **Requires:** WordPress 6.0+, PHP 8.0+, MySQL/MariaDB (uses `GET_LOCK`)
- **Changelog:** see [`readme.txt`](readme.txt)

---

## Contents

- [Meeting types at a glance](#meeting-types-at-a-glance)
- [Installation](#installation)
- [Configuration](#configuration)
- [Meeting Organiser role](#meeting-organiser-role)
- [For organisers](#for-organisers-wp-admin)
  - [Creating a Meeting Event](#creating-a-meeting-event)
  - [Slots and capacity (Max)](#slots-and-capacity-max)
  - [Sessions / series: parts and attendance](#sessions--series-parts-and-attendance)
  - [Meeting details](#meeting-details)
  - [Watching responses](#watching-responses)
  - [Closing and sending final details](#closing-and-sending-final-details)
  - [Schedule overview shortcode](#schedule-overview-shortcode)
- [For attendees](#for-attendees-the-public-page)
- [What emails get sent](#what-emails-get-sent)
- [Theming the public form](#theming-the-public-form)
- [Developer hooks](#developer-hooks)
- [Security and privacy](#security-and-privacy)
- [Development and tests](#development-and-tests)
- [Uninstalling](#uninstalling)
- [License](#license)

---

## Meeting types at a glance

Every Meeting Event has one of three types. Choose it in the **Meeting Type**
box in the editor sidebar.

| Type | Attendees… | Slot "Max" means… | Organiser finishes by… |
|---|---|---|---|
| **1-on-1 / limited capacity** | pick **one** time | how many people can book it (new slots default to 1) | closing registration, then emailing each person their slot's details |
| **Group poll** | tick **every** time that works | how many "yes" votes a time can take (new slots default to no limit) | choosing the final time, closing the poll, then emailing everyone |
| **Sessions / series** | pick at most **one time per part** of a series | how many people can attend that session (new slots default to no limit) | closing registration, then emailing each person their own schedule |

---

## Installation

1. Get the plugin into `wp-content/plugins/doodle-clone-scheduler/`. Either:
   - upload a zip via **Plugins → Add New → Upload Plugin**, or
   - clone or deploy this repository into that folder. A Git-based deployer
     works well, since `main` is always the released version.
2. Activate **Doodle Clone Scheduler** under **Plugins**.
3. A new **Meeting Events** menu appears in wp-admin (calendar icon).

> To build a distributable zip without the tests and dev files, use
> `git archive` (`.gitattributes` marks those paths `export-ignore`):
>
> ```sh
> git archive --format=zip --prefix=doodle-clone-scheduler/ -o doodle-clone-scheduler.zip HEAD
> ```

On first load after an upgrade, the plugin runs any one-time data migrations
automatically. It tracks them in the `dcs_db_version` option.

---

## Configuration

There is no settings page. There are two things to configure.

### Timezone

Every slot time is entered and shown in **one timezone**. Set it in
`wp-config.php`:

```php
define( 'DCS_TIMEZONE', 'America/Chicago' ); // any valid PHP timezone ID
```

If you don't define it, the plugin defaults to `America/New_York`. The public
form states the timezone once above the time grid ("All times are EDT."). It
shows both abbreviations ("EDT/EST") when the slots span a daylight-saving
change.

### Email

All mail goes through `wp_mail()`, so it uses whatever mailer your site already
has (an SMTP plugin is strongly recommended for deliverability).

The **organiser address** for an event is the account email of the event's
**Author** (its owner). Organiser notifications go there, and "contact the
organiser" messages shown to attendees use it. If the event has no author,
the author's account has been deleted, or its email is invalid, the site's
**Administration Email Address** (Settings → General) is used instead.

To route these somewhere else (for example, always copy a shared inbox),
filter `dcs_organiser_email`. See [Developer hooks](#developer-hooks).

---

## Meeting Organiser role

To let someone run meetings without giving them access to the rest of the
site, give them the **Meeting Organiser** role (Users → Add New, or edit an
existing user).

| | Meeting Organiser | Author | Editor / Administrator |
|---|---|---|---|
| Create, edit, publish and delete **own** events | ✓ | ✓ | ✓ |
| Close events and send final details | own | own | all |
| See and edit **other people's** events | | | ✓ |
| Posts, pages, media, comments | | own posts and media | ✓ |

What organisers see in wp-admin:

- No Dashboard. Logging in, or opening wp-admin, takes them straight to
  **Meeting Events**. The toolbar loses the WordPress and comments menus.
- The **Meeting Events** list shows only their own events. The
  All / Published / Draft counts are hidden, since those count everyone's.
- Everything inside an event (slots, details, results, Close & Send) works
  exactly as described below.

**Giving an event to an organiser.** An event belongs to its **Author**. Events
organisers create are theirs automatically. To hand over an existing event,
an Administrator or Editor opens it and picks the organiser in the **Author**
box. Events created before 2.4.0 belong to whoever created them.

Anyone who can't edit other people's events (organisers and Authors) sees only
their own in the list. A user who holds the organiser role *and* can edit blog
posts (for example, also an Editor) keeps the normal wp-admin.

> **Capabilities.** Meeting Events use their own capabilities
> (`edit_meeting_events`, `edit_others_meeting_events`,
> `publish_meeting_events`, …) instead of the blog-post ones. When upgrading to
> 2.4.0, every role receives the Meeting Event version of each post capability
> it already had, so existing access is unchanged. Role-editor plugins show
> them under the `meeting_event` names.

---

## For organisers (wp-admin)

### Creating a Meeting Event

1. Go to **Meeting Events → Add New**.
2. Give it a **title** (used in email subjects and calendar entries) and
   optional body content. The booking/poll form is appended **below** your
   content automatically. You don't need a shortcode.
3. In **Meeting Type** (sidebar), choose 1-on-1, Group poll, or
   Sessions / series.
4. Add time slots in **Meeting Slots** (see below).
5. Optionally fill in **Meeting Details** (location, Zoom/Teams link, etc.).
6. **Publish.** Only published events accept responses. Drafts, scheduled and
   trashed events are rejected even if someone knows the ID.

Share the event's permalink (`/meeting_event/<slug>/`) with your attendees.

### Slots and capacity (Max)

Each slot in the **Meeting Slots** box has:

| Field | Notes |
|---|---|
| **Date / Time** | Wall-clock time in the plugin timezone. |
| **Duration** | 30 min to 10 h. Used for the end time, the .ics file and calendar links. |
| **Max** | Capacity. **Leave blank for no limit.** |
| **Part** | Sessions / series only: which part this slot belongs to. |
| **Attendees** | Name + email rows. You can add or remove people by hand here. |
| **Override meeting details** | Collapsible. Per-slot location/link, see [Meeting details](#meeting-details). |

Click **+ Add Slot** for more times, and **Remove Slot** to delete one.

How Max behaves:

- **1-on-1:** once a slot has Max bookings it shows **Full** and can't be picked.
- **Group poll:** once a time has Max "yes" votes it shows **Full** and is
  disabled for new voters. Someone who already said yes keeps it when editing.
  Limited times show "N spots left", and the admin tables show "votes / max".
- **Sessions:** places left are shown per time. Full times are disabled except
  for people who already hold them.

> **Tip:** a slot keeps its identity (and its attendees' edit links keep
> working) as long as its start time doesn't change. If you change a slot's
> date or time, it becomes a new slot as far as matching is concerned.

### Sessions / series: parts and attendance

Use this type when **every meeting is held** and people choose which ones to
attend. Examples: a 3-part training offered at several times each, or one
workshop repeated across a week.

**Parts box** (shown for Sessions events):

- **+ Add Part** creates a part (e.g. "Part 1: Kickoff"). Rename or remove parts
  at any time. Order here is the order shown to attendees.
- Each part can **override meeting details** (e.g. a different room for Part 2).
- Assign each slot to a part using the slot's **Part** dropdown. Offer a part at
  several times by giving it several slots.
- **No parts defined** means one session offered at several times. Attendees
  pick exactly one.

**Attendance** (in the Meeting Type box):

| Setting | Effect on the public form |
|---|---|
| **All parts recommended** *(default)* | Attendees should pick one time per part but may choose "Can't attend this part". A part with only one time is pre-selected. |
| **All parts required** | A time must be chosen for every part. No skip option. |
| **Pick any parts** | Attendees choose whichever parts they want (at least one). |

### Meeting details

The **Meeting Details** box holds the information people need to actually show
up:

- **Format:** Not specified / In person / Online / Hybrid
- **Address / room**
- **Meeting link** (must be `https://`; anything else is dropped)
- **Meeting ID**, **Passcode**
- **Dial-in** numbers (one per line)
- **Notes**

These details are **never shown on the public page** and never sent in
sign-up confirmations. They go out only in the **final details** email after
you close the event.

Details resolve in layers: **event default → part (Sessions only) → slot**.
A blank field means "use the level above". One rule matters here:

> If a slot (or part) sets **its own meeting link**, it is treated as a
> **different meeting**. Its meeting ID, passcode and dial-in come *only* from
> that level, never from the default. That stops a Zoom passcode from being
> sent alongside a Teams link.

### Watching responses

- **Poll Results box:** every slot with its duration, vote count (as
  "votes / max" when limited) and voter names, plus the slot with the most
  votes. The **Voter Roster** lists each voter, the times they chose, and their
  edit-link status:
  - **Active (N days left):** they can still revise via their emailed link.
  - **Expired:** 30 days have passed. They must submit again to get a fresh link.
  - **No link sent:** you added them by hand, or they predate edit links.
- **Close & Send Final Details box** (1-on-1 and Sessions): per-slot booked
  count vs. capacity, attendee names, and a meeting-details summary. A slot
  shows **NO LINK** in red if it is online/hybrid, has attendees, and is
  missing a join link. Sessions events with several parts also show a **"Who
  is attending each part"** grid.
- The event's owner gets an **email for each 1-on-1 booking** ("New
  booking: …"), and one when **someone new votes in a poll or signs up for
  Sessions** ("New response: …", listing their picks and linking to the
  event). Later edits through a person's edit link don't send another. See
  [Email](#email) for who the owner is.

### Closing and sending final details

All in the **Close & Send Final Details** box.

**Group poll**

1. The most-voted time (earliest wins ties) is pre-selected. Pick a different
   one if you like.
2. Click **Close Poll with Selected Time** (this saves the post). The public
   page now shows "This poll is now closed" with the chosen time and an
   "Add to Google Calendar" link.
3. A **Send Final Details** panel appears. **Send to everyone** emails every
   voter the chosen time plus its meeting details and an `.ics` file.
4. **Reopen Poll** clears the chosen time and accepts votes again.

**1-on-1 and Sessions / series**

1. Click **Close Registration**. The public page shows "Registration for this
   event is closed" and no form.
2. **Send to everyone** emails each attendee *their own* slot(s): time, meeting
   details, a Google Calendar link and one `.ics` per session. Sessions
   attendees get a heading per part.
3. **Reopen Registration** accepts sign-ups again.

**Re-sending.** After the first send, a second button appears:
**Send only to people not yet emailed**. Use it after reopening and picking up
late sign-ups. **Send to everyone** again re-sends to all.

Details are read **live at send time**, so if a Zoom link changes after
closing, update it and re-send. Each send bumps the calendar `SEQUENCE`, so
re-importing the new `.ics` **updates** the existing calendar entry instead of
duplicating it. You receive one summary email per send. If any online/hybrid
slot with recipients has no link, a warning lists those slots above the send
buttons.

### Schedule overview shortcode

```
[doodle_schedule_overview]
```

Place it on any page or post to list **every** Meeting Event and its slots in a
table with a status per slot:

- Logged-in users who can edit posts see **who** booked each slot.
- Everyone else sees only **Booked** / **Available**.

---

## For attendees (the public page)

Attendees visit the event's page, enter their **name** and **email**, and
respond. No account is needed.

**1-on-1:** times are grouped into day cards. Choose one and click **Book**.
Full slots, and slots more than 15 minutes past their start, can't be booked.
Each email address can hold **one** booking per event. To change it, contact
the organiser.

**Group poll:** tick every time that works. A counter shows "N times
selected". Click **Submit Availability**. Times at their limit show **Full**.

**Sessions / series:** one group per part. Choose a time for each part (or
"Can't attend this part" if allowed), then click **Register**. Each option
shows places left.

**Changing your response (magic edit links).** Poll and Sessions
confirmations include a personal link, valid for **30 days**, that reopens the
form pre-filled with your current choices. Submitting through it replaces your
earlier response, and you get a fresh link each time. Once a link has been
issued for an email address, **changes require that link**, so nobody else
can overwrite your answer by typing your email. An expired link shows a clear
notice, and you can submit again from the blank form.

After a poll closes, opening your edit link shows a personal summary of what
you voted for, with the chosen time highlighted.

---

## What emails get sent

| When | To | Contains |
|---|---|---|
| 1-on-1 booking | Attendee | Confirmed time, `.ics` (no meeting details yet) |
| 1-on-1 booking | Event owner | Who booked which slot |
| Poll vote (new or edited) | Voter | Confirmation + 30-day edit link |
| Poll vote (first from that email) | Event owner | "New response": who, and the times they picked |
| Sessions sign-up (new or edited) | Attendee | Their schedule per part (incl. skipped) + edit link. No details or `.ics` yet. |
| Sessions sign-up (first from that email) | Event owner | "New response": who, and their schedule per part |
| Organiser clicks **Send** | Each voter/attendee | HTML email: time(s), full meeting details, Google Calendar link, `.ics` per slot |
| Organiser clicks **Send** | Event owner | Summary of who was emailed |

"Event owner" is the event's Author, or the site admin email if it has none
(see [Email](#email)).

First-time poll and Sessions confirmations are throttled to one per address per
minute. Edits always send a fresh link.

---

## Theming the public form

The plugin ships its own stylesheet (`frontend.css`) and a bundled copy of
**Source Sans Pro** (`fonts/`, SIL Open Font License). It loads nothing from
Google or any other CDN.

Everything is driven by CSS custom properties, which you can override from your
theme or **Appearance → Customize → Additional CSS**:

```css
.dcs-booking-form,
.dcs-notice,
.dcs-message,
.dcs-schedule-overview {
    --dcs-accent:       #7a3e9d;   /* buttons, selected times */
    --dcs-accent-hover: #5f2f7b;
    --dcs-accent-tint:  #f4ecf8;
    --dcs-font:         inherit;   /* use the theme's font instead */
    --dcs-radius:       4px;
}
```

All variables: `--dcs-accent`, `--dcs-accent-hover`, `--dcs-accent-tint`,
`--dcs-text`, `--dcs-muted`, `--dcs-border`, `--dcs-surface`,
`--dcs-surface-alt`, `--dcs-success`, `--dcs-success-tint`, `--dcs-warning`,
`--dcs-warning-tint`, `--dcs-error`, `--dcs-error-tint`, `--dcs-radius`,
`--dcs-font`.

---

## Developer hooks

| Hook | Type | Default | Purpose |
|---|---|---|---|
| `dcs_booking_past_grace` | filter (int, seconds) | `900` (15 min) | How long after a slot starts it can still be booked (1-on-1 and Sessions). |
| `dcs_organiser_email` | filter (string, `$post_id`) | Event author's email, else site admin email | Where organiser notifications for an event go, and the address in "contact the organiser" messages. Return a comma-separated list to notify several people. |
| `dcs_client_ip` | filter (string) | `$_SERVER['REMOTE_ADDR']` | Client IP used for rate limiting. Override only to read a header **your** proxy/CDN sets and that clients can't spoof. |

```php
// Allow booking up to an hour after a slot starts.
add_filter( 'dcs_booking_past_grace', fn() => HOUR_IN_SECONDS );

// Behind Cloudflare: trust CF-Connecting-IP.
add_filter( 'dcs_client_ip', fn( $ip ) => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip );
```

**Data model** (post type `meeting_event`, for anyone scripting against it):

| Meta key | Holds |
|---|---|
| `_meeting_mode` | `booking`, `poll` or `sessions` |
| `_meeting_slots` | Array of slots: `id`, `date`, `time`, `start`, `end`, `duration_minutes`, `max` (0 = unlimited), `part`, `attendees[]` |
| `_meeting_parts` | Sessions parts: `[ [ 'id', 'title' ], … ]` |
| `_sessions_attendance` | `recommended`, `required` or `any` |
| `_meeting_details` | `default` / `parts` / `slots` details records. Kept separate from slots so the public side never reads it. |
| `_poll_status` | `open` or `closed` (all types) |
| `_poll_selected_slot_id` | Winning slot of a closed poll |

The public form submits to `admin-ajax.php?action=dcs_book_slot`.

---

## Security and privacy

- **No accounts, no third parties.** No CAPTCHA service, no Google Fonts, no
  external scripts.
- **Meeting details never reach the public page**, the booking endpoint, or
  sign-up confirmations. They only go out in the final-details email.
- **Edit links** are HMAC-SHA256-signed with your site's `AUTH_SALT`. No
  database table is needed, they expire after 30 days, and they are required
  for edits once issued.
- **Bot mitigation:** a hidden honeypot field plus a signed render timestamp
  that rejects submissions made within 3 seconds of page load.
- **Rate limits:** 20 submissions per IP per 5 minutes, and 8 per event+email
  per 10 minutes.
- **No overbooking:** capacity checks and writes run under MySQL advisory locks
  (`GET_LOCK`). These release automatically if a request dies.
- **One booking per person** on 1-on-1 events, matched on a normalised email,
  so Gmail `j.doe+x@gmail.com` and `jdoe@gmail.com` count as the same person.
- Nonces on every admin action and on the public form.

---

## Development and tests

```
doodle-clone-scheduler.php   bootstrap, constants
includes/helpers.php         timezone, slots, details, ICS, tokens, locks
includes/class-roles.php     Meeting Organiser role, own-events-only list, simplified admin
includes/class-admin.php     post type, meta boxes, save handlers
includes/class-frontend.php  public form, notices, shortcode
includes/class-ajax.php      dcs_book_slot endpoint (book / vote / sign up)
includes/class-mailer.php    all outbound email
script.js, frontend.css      public-page JS and styles
fonts/                       bundled Source Sans Pro (woff2)
tests/                       stubbed-WordPress test suite (not shipped)
```

The tests run on plain PHP 8 CLI. You don't need WordPress or a database:

```sh
php tests/test-meeting-details.php
php tests/test-sessions.php
php tests/test-poll-capacity.php
php tests/test-format-range.php
php tests/test-organizer-role.php
php tests/test-organiser-email.php
```

Each prints `N passed, M failed`.

When releasing, bump the version in **both** the plugin header and
`DCS_VERSION` in `doodle-clone-scheduler.php`, update `Stable tag` and the
changelog in `readme.txt`, then tag the commit (`vX.Y.Z`).

---

## Uninstalling

Deactivating or deleting the plugin **leaves all Meeting Events and their data
in place**, so a delete-and-reinstall upgrade is safe. The **Meeting Organiser**
role and the Meeting Event capabilities also stay. Remove the role with a
role-editor plugin if you no longer want it. To remove the content,
delete the Meeting Events yourself (Meeting Events → All → Trash → Empty
Trash).

---

## License

Copyright © 2026 Ted Hattemer. See [`LICENSE`](LICENSE). The bundled Source
Sans Pro font is under the SIL Open Font License ([`fonts/OFL.txt`](fonts/OFL.txt)).
