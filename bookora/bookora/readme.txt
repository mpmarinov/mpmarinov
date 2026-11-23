=== Bookora – Appointments & Booking Forms ===
Contributors: marinovsolutions
Tags: booking, appointments, calendar, scheduling, booking form
Requires at least: 6.6
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Booking forms, live availability, a drag & drop timeline and calendar sync for iPhone, Android and Outlook – free, without artificial limits.

== Description ==

Bookora by Marinov Solutions turns your WordPress site into a complete booking system.

* Unlimited booking forms with all field types, conditional logic and multi-step forms
* Services with duration, buffers, capacity and opening hours
* Live availability – already booked times are blocked automatically
* One-click confirmation and rejection directly from the email
* Fully customizable confirmation, rejection and reminder emails
* Drag & drop timeline for your day and week – move appointments, the customer is informed automatically
* Calendar subscription for iPhone, Android (Google Calendar) and Outlook – one calendar for the business and one per staff member
* Private calendars (Google, iCloud, Outlook) block busy times automatically – only start and end times are stored
* Mobile-first, accessible and translation-ready (English, German, Bulgarian)
* Design without code: main color, corner radius, spacing, light/dark/automatic color scheme
* Custom CSS and JavaScript (global and per form) with code editor, JavaScript events (e.g. for Google Tag Manager), developer hooks and REST API
* GDPR tools: data export and erasure

This plugin is under active development.

== External services ==

Bookora works without any account or external service. Two optional calendar features connect to services that you choose yourself:

**Importing private calendars (busy times).** If you add a calendar address in Bookora → Calendar Sync, your website downloads that address every 15 minutes (for example from Google Calendar, Apple iCloud or Microsoft Outlook). The request contains only the address you entered and your website's address (as user agent). Only the start and end times of the events are stored. Nothing is sent if you do not add a calendar.
Google: [Terms](https://policies.google.com/terms), [Privacy](https://policies.google.com/privacy) · Apple iCloud: [Terms](https://www.apple.com/legal/internet-services/icloud/), [Privacy](https://www.apple.com/legal/privacy/) · Microsoft Outlook: [Terms](https://www.microsoft.com/servicesagreement), [Privacy](https://privacy.microsoft.com/privacystatement)

**Subscribing with Google Calendar or Outlook.** The buttons "Google Calendar" and "Outlook" open calendar.google.com or outlook.live.com in your browser and pass your private Bookora calendar address, so that the service can subscribe to it. The service then downloads your appointments from your website regularly (including customer names and contact details, unless you switch this off in Calendar Sync). This only happens when you click one of these buttons. Links: see Google and Microsoft above.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/bookora` or install it via Plugins → Add New.
2. Activate it.
3. Open **Bookora → Settings** and set your opening hours.

== Frequently Asked Questions ==

= Is the free version limited? =

No. Unlimited forms, services and bookings are included for free.

= How do appointments get onto my phone? =

Open Bookora → Calendar Sync and tap "Send link to phone". Open the email on your phone and tap the button – iPhone, Android (Google Calendar) and Outlook subscribe to your Bookora calendar. New, moved and cancelled appointments appear automatically. Google refreshes subscribed calendars every few hours, Apple and Outlook usually more often.

= Can my private appointments block booking times? =

Yes. Paste the private iCal address of your Google, iCloud or Outlook calendar. Bookora checks it every 15 minutes and no longer offers times in which you already have something planned. Titles and details of your private appointments are never stored.

= Does Bookora contact external services? =

Only the calendar addresses you enter yourself are fetched (every 15 minutes, via the WordPress HTTP API). The links "Google Calendar" and "Outlook" in the admin open the respective website in your browser. No data is sent to Marinov Solutions.

== Changelog ==

= 0.1.0 =
* Initial development release: database schema, roles, settings, admin foundation, privacy tools.
