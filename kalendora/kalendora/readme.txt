=== Kalendora – Appointments & Booking Forms ===
Contributors: marinovsolutions
Tags: booking, appointments, calendar, scheduling, booking form
Requires at least: 6.6
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Appointment booking for practices and small businesses: booking forms, live availability, reminders, a timeline and calendar sync for your phone.

== Description ==

Kalendora by Marinov Solutions turns your WordPress site into a complete booking system for practices, salons, workshops, consultants and course providers. Customers book free times around the clock; you keep appointments, reminders and cancellations under control – on your phone and on your computer.

= Booking =
* Live availability – booked times are blocked immediately, double bookings are prevented even when two people book at the same moment
* Services with duration, buffers, places for courses and a daily maximum
* Staff members and rooms with their own working hours (up to 3 in this plugin; the paid add-on Kalendora Pro removes the limit)
* Customers in another time zone can see the times in their own time
* Location per service: at the customer's address (with travel time blocked before and after, within working hours), at your office or remote/online – address with map link, directions or access details in the emails and calendar entries
* Setup wizard and 10 form templates (medical practice, hair salon, workshop, consulting, course, contact, quote request …)

= Fewer no-shows =
* Reminders, e.g. 7 days and 24 hours before the appointment
* Customers cancel or move their appointment themselves via a personal link – up to a deadline you choose
* Optional cancellation fee (e.g. "free up to 24 hours before, otherwise 50 %") with consent at booking, also for no-shows
* One-click confirmation and rejection directly from the email
* Reliable email queue with automatic retries if your mail server is briefly unavailable

= Your day at a glance =
* Dashboard with today's appointments and open requests
* Drag & drop timeline for day and week – move appointments, the customer is informed automatically
* Calendar subscription for iPhone, Android (Google Calendar) and Outlook – via QR code or link, one calendar for the business and one per staff member
* Private calendars (Google, iCloud, Outlook) block busy times automatically – only start and end times are stored

= Forms =
* Form builder with all field types, conditional logic and multi-step forms – also for forms without appointments (contact, request, survey)
* Secure file uploads (e.g. referrals, photos, PDFs) and signatures – stored privately, only visible to you
* Calculated fields, e.g. a total price – always recalculated on the server
* Fully customizable emails with placeholders and live preview

= Privacy and accessibility =
* All data stays on your server; no tracking, no external fonts, no data sent to us
* GDPR export and erasure, including uploaded files
* Accessible booking form and admin (WCAG 2.1 AA, tested with axe-core), full keyboard support
* English, German and Bulgarian included

= Optional integrations =
* Spam protection with Cloudflare Turnstile, hCaptcha or Google reCAPTCHA (in addition to the built-in protection)
* Notifications to Slack and Telegram
* Newsletter sign-up with Mailchimp or Brevo – only with consent and double opt-in

= For developers =
* Custom CSS and JavaScript (global and per form) with code editor, JavaScript events (e.g. for Google Tag Manager), PHP hooks and a REST API
* Design without code: main color, corner radius, spacing, light/dark/automatic color scheme

= Kalendora Pro =
The separate, paid add-on Kalendora Pro adds online payment and deposits (Stripe, PayPal), calculated travel time and kilometre allowance per customer address with service area and customer choice of location, unlimited staff members and resources and webhooks for Zapier, Make, n8n and Google Sheets. It is not required: everything listed above works with this plugin alone. More: https://mpmarinov.com/kalendora

== External services ==

Kalendora works without any account or external service. Two optional calendar features connect to services that you choose yourself:

**Importing private calendars (busy times).** If you add a calendar address in Kalendora → Calendar Sync, your website downloads that address every 15 minutes (for example from Google Calendar, Apple iCloud or Microsoft Outlook). The request contains only the address you entered and your website's address (as user agent). Only the start and end times of the events are stored. Nothing is sent if you do not add a calendar.
Google: [Terms](https://policies.google.com/terms), [Privacy](https://policies.google.com/privacy) · Apple iCloud: [Terms](https://www.apple.com/legal/internet-services/icloud/), [Privacy](https://www.apple.com/legal/privacy/) · Microsoft Outlook: [Terms](https://www.microsoft.com/servicesagreement), [Privacy](https://privacy.microsoft.com/privacystatement)

**Subscribing with Google Calendar or Outlook.** The buttons "Google Calendar" and "Outlook" open calendar.google.com or outlook.live.com in your browser and pass your private Kalendora calendar address, so that the service can subscribe to it. The service then downloads your appointments from your website regularly (including customer names and contact details, unless you switch this off in Calendar Sync). This only happens when you click one of these buttons. Links: see Google and Microsoft above.

**Optional integrations (Kalendora → Integrations).** Nothing is sent unless you enter the access data of a service. The keys are stored encrypted in your database.

* *Spam protection* – Cloudflare Turnstile, hCaptcha or Google reCAPTCHA. When activated, the provider's script is loaded on pages with a Kalendora form, and on submit the answer token (not the visitor's IP address) is checked at the provider. [Cloudflare Privacy](https://www.cloudflare.com/privacypolicy/), [Turnstile Terms](https://www.cloudflare.com/website-terms/) · [hCaptcha Privacy](https://www.hcaptcha.com/privacy), [Terms](https://www.hcaptcha.com/terms) · [Google Privacy](https://policies.google.com/privacy), [Terms](https://policies.google.com/terms)
* *Slack and Telegram* – for the events you choose, a short message (customer name, service, date and time, staff member, admin link; for forms without appointment the submitted text fields) is sent to your Slack webhook or your Telegram bot. [Slack Privacy](https://slack.com/trust/privacy/privacy-policy), [Terms](https://slack.com/terms-of-service) · [Telegram Privacy](https://telegram.org/privacy), [Terms](https://telegram.org/tos)
* *Mailchimp and Brevo* – only for forms connected to the newsletter and only if the visitor ticks the consent box: email address and name are sent to your list; the service sends a double opt-in confirmation email. [Mailchimp/Intuit Privacy](https://www.intuit.com/privacy/statement/), [Terms](https://mailchimp.com/legal/terms/) · [Brevo Privacy](https://www.brevo.com/legal/privacypolicy/), [Terms](https://www.brevo.com/legal/termsofuse/)

== Third-party libraries ==

* [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) by Kazuhiko Arase (MIT) – QR codes for the calendar subscription, generated in the browser
* [dnd-kit](https://github.com/clauderic/dnd-kit) by Claudéric Demers (MIT) – drag & drop in the form builder

License texts: THIRD-PARTY-LICENSES.txt. The source code of the admin app is in assets/admin/src (built with @wordpress/scripts).

== Installation ==

1. Upload the plugin to `/wp-content/plugins/kalendora` or install it via Plugins → Add New.
2. Activate it.
3. Open **Kalendora → Settings** and set your opening hours.

== Frequently Asked Questions ==

= Is the free version limited? =

Forms, services and bookings are unlimited. You can add up to 3 staff members or resources (rooms, devices); if you work alone you do not need any. The paid add-on Kalendora Pro removes this limit. There are no trial periods and nothing stops working after a while.

= How do appointments get onto my phone? =

Open Kalendora → Calendar Sync and scan the QR code with your phone's camera, or tap "Send link to phone". Open the email on your phone and tap the button – iPhone, Android (Google Calendar) and Outlook subscribe to your Kalendora calendar. New, moved and cancelled appointments appear automatically. Google refreshes subscribed calendars every few hours, Apple and Outlook usually more often.

= Can my private appointments block booking times? =

Yes. Paste the private iCal address of your Google, iCloud or Outlook calendar. Kalendora checks it every 15 minutes and no longer offers times in which you already have something planned. Titles and details of your private appointments are never stored.

= Can customers cancel or move appointments themselves? =

Yes. Every booking email contains a personal link. Customers can cancel or move to another free time up to the deadline you set (default: 24 hours before). You are informed by email. Optionally you can use a cancellation fee: customers accept it when booking, and a late cancellation or a no-show records the fee in the booking. The free version does not collect payments – you invoice the fee yourself.

= Where are uploaded files stored? Are they safe? =

On your own server, in a private folder (wp-content/uploads/kalendora-private) under random names without file extension, so they cannot be opened or executed from the web. Only logged-in users with access to bookings can download them. Only harmless file types are accepted (images, PDF, Office documents, text, ZIP), and the real content is checked. Files that were uploaded but never submitted are deleted after 24 hours.

= Does Kalendora contact external services? =

Only services you set up yourself: the calendar addresses you enter (fetched every 15 minutes) and the optional integrations (spam protection, Slack, Telegram, Mailchimp, Brevo) once you enter their keys. The links "Google Calendar" and "Outlook" in the admin open the respective website in your browser. No data is sent to Marinov Solutions.

== Screenshots ==

1. Booking form on a phone: free times, choice of staff member, cancellation terms
2. Setup wizard with form templates
3. Timeline: day view with all staff members, appointments can be moved with drag & drop
4. Bookings with status, cancellation fee and form details
5. Form builder with conditional logic and calculated fields
6. Calendar subscription for iPhone, Android and Outlook via QR code
7. Email templates with placeholders and live preview
8. Customer self-service: cancel or move an appointment

== Changelog ==

= 1.0.0 =
* First public release.
* Booking forms with live availability, double-booking protection, buffers, places and daily limits.
* Staff members and resources with own working hours, holidays and special opening hours.
* Customer self-service (cancel/move) with deadline and optional cancellation fee.
* Reminders (e.g. 7 days and 24 hours before), email templates, one-click confirmation, email queue with retries.
* Timeline with drag & drop, dashboard, setup wizard and 10 form templates.
* Calendar subscription (QR code) and import of private calendars.
* File upload, signature, calculated fields, conditional logic, multi-step forms.
* Customer time zones, accessibility (WCAG 2.1 AA), English/German/Bulgarian.
* Optional integrations: Turnstile, hCaptcha, reCAPTCHA, Slack, Telegram, Mailchimp, Brevo.

== Upgrade Notice ==

= 1.0.0 =
First public release.
