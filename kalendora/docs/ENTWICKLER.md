# Kalendora – Entwickler-Dokumentation

Stand: Phase 9. Alles hier ist öffentliche, stabile Schnittstelle. Änderungen werden im Changelog angekündigt.

---

## 1. Design ohne Code

**Kalendora → Einstellungen → Design & eigener Code**

| Einstellung | Wirkung |
|---|---|
| Hauptfarbe | `--kalendora-primary` (Buttons, gewählte Zeit, Fokus) |
| Eckenradius 0–24 px | `--kalendora-radius` |
| Abstände: Kompakt / Normal / Großzügig | Klasse `kalendora--compact` bzw. `kalendora--comfortable` |
| Farbschema: Hell / Dunkel / Automatisch | Klasse `kalendora--scheme-dark` bzw. `kalendora--scheme-auto` (folgt `prefers-color-scheme`) |
| Schrift: wie Theme / Systemschrift | Klasse `kalendora--font-system` |

---

## 2. CSS

Jedes Formular hat die Klassen `kalendora`, `kalendora-form-<ID>`, `kalendora--booking` bzw. `kalendora--standard`.
Eigenes CSS gibt es global (Einstellungen) und pro Formular (Formular → Einstellungen → Eigener Code).
HTML-Tags werden aus dem CSS entfernt; speichern dürfen es nur Benutzer mit dem Recht `unfiltered_html`.

### CSS-Variablen

| Variable | Standard | Bedeutung |
|---|---|---|
| `--kalendora-primary` | `#2563eb` | Hauptfarbe |
| `--kalendora-primary-contrast` | `#fff` | Schrift auf der Hauptfarbe |
| `--kalendora-text` | `inherit` | Textfarbe |
| `--kalendora-muted` | `#6b7280` | Hinweise, Hilfetexte |
| `--kalendora-border` | `#d1d5db` | Rahmen |
| `--kalendora-bg` | `#fff` | Hintergrund der Felder |
| `--kalendora-soft` | 10 % Hauptfarbe | Hervorhebungen (z. B. „Gewählt: …“) |
| `--kalendora-error` | `#c81e1e` | Fehler |
| `--kalendora-radius` | `10px` | Eckenradius |
| `--kalendora-gap` | `18px` | Abstand zwischen Feldern |
| `--kalendora-input-height` | `46px` | Höhe der Eingabefelder |

```css
/* Nur Formular 3, breiter und runder */
.kalendora-form-3 { --kalendora-radius: 18px; --kalendora-gap: 24px; max-width: 900px; }
```

---

## 3. JavaScript

Eigenes JavaScript gibt es global (läuft **einmal pro Seite**, sobald ein Kalendora-Formular darauf ist) und
pro Formular (läuft **für dieses Formular**, sobald es bereit ist). Der Code wird über eine Warteschlange
ausgeführt und funktioniert daher unabhängig von der Ladereihenfolge, auch bei nachgeladenen Formularen.
Fehler im eigenen Code werden abgefangen und in der Browser-Konsole gemeldet; das Formular funktioniert weiter.

### Variablen im eigenen Code

| Code | `kalendora` ist … | zusätzlich |
|---|---|---|
| global | die Seiten-API `window.kalendora` | – |
| pro Formular | die API **dieses** Formulars | `form` = das Formular-Element (`div.kalendora`) |

### Seiten-API `window.kalendora`

| Methode | Beschreibung |
|---|---|
| `kalendora.forms` | Liste der APIs aller Formulare auf der Seite |
| `kalendora.on( event, ( data, formApi ) => {} )` | Ereignis **aller** Formulare (auch später geladener) |
| `kalendora.off( event, callback )` | Abmelden |
| `kalendora.ready( ( formApi ) => {} )` | Für jedes Formular ausführen, sobald es bereit ist |

Von außen (z. B. im Theme) ohne Ladereihenfolge-Probleme:

```js
( window.kalendoraQueue = window.kalendoraQueue || [] ).push( function ( kalendora ) {
	kalendora.on( 'success', function ( data, form ) { /* … */ } );
} );
```

### Formular-API

| Methode / Eigenschaft | Beschreibung |
|---|---|
| `id`, `element`, `form` | Formular-ID, Container, `<form>`-Element |
| `on( event, ( data, api ) => {} )`, `off( event, cb )` | Ereignisse dieses Formulars |
| `values()` | Alle Werte als Objekt (`{ email: '…', name: { first, last } }`) |
| `setValue( key, value )` | Feld setzen (Text, Auswahl, Radio, Checkbox-Liste, Name-Objekt); löst Bedingungslogik aus. Gibt `false` zurück, wenn es das Feld nicht gibt |
| `step()`, `goTo( index )` | Aktueller Schritt bzw. Sprung zu Schritt (mehrstufige Formulare) |

### Ereignisse

| Ereignis | `data` | Hinweis |
|---|---|---|
| `ready` | `{ id }` | Formular ist einsatzbereit |
| `change` | `{ key, value, values }` | Ein Feld wurde geändert |
| `service` | `{ id }` | Leistung gewählt |
| `resource` | `{ id }` | Mitarbeiter gewählt (`null` = egal) |
| `date` | `{ date: 'YYYY-MM-DD' }` | Tag im Kalender gewählt |
| `slot` | `{ date, time, end_time, start, resources }` oder `null` | Uhrzeit gewählt bzw. Auswahl zurückgesetzt |
| `step` | `{ index, total }` | Schritt gewechselt |
| `beforeSubmit` | `{ values, cancel( message ) }` | **Abbrechbar:** `return false` oder `data.cancel( 'Text' )` |
| `submit` | gesendete Daten | Anfrage geht gerade raus |
| `success` | Server-Antwort `{ message, redirect }` | Erfolgreich gespeichert |
| `error` | Server-Antwort `{ code, message, data }` | z. B. Zeit inzwischen vergeben |

Jedes Ereignis wird zusätzlich als DOM-Ereignis `kalendora:<name>` am Formular ausgelöst (bubbelt bis `document`),
`detail = { formId, data }`. Ideal für Google Tag Manager, Matomo & Co. ohne Kalendora-Code:

```js
document.addEventListener( 'kalendora:success', function ( e ) {
	window.dataLayer = window.dataLayer || [];
	window.dataLayer.push( { event: 'kalendora_booking', form_id: e.detail.formId } );
} );
```

### Beispiele

```js
// Pro Formular: Hinweis bei Terminen am Samstag
kalendora.on( 'slot', function ( slot ) {
	if ( slot && new Date( slot.date ).getDay() === 6 ) {
		alert( 'Samstags nur mit Voranmeldung per Telefon.' );
	}
} );

// Pro Formular: Gutscheincode prüfen, bevor gesendet wird
kalendora.on( 'beforeSubmit', function ( data ) {
	if ( data.values.gutschein && ! /^BK-\d{4}$/.test( data.values.gutschein ) ) {
		data.cancel( 'Der Gutscheincode hat das Format BK-1234.' );
	}
} );

// Pro Formular: Feld aus der Adresszeile vorbelegen (?quelle=instagram)
kalendora.setValue( 'quelle', new URLSearchParams( location.search ).get( 'quelle' ) || '' );
```

---

## 4. PHP-Hooks

### Aktionen

| Hook | Parameter | Zeitpunkt |
|---|---|---|
| `kalendora_loaded` | `Plugin $plugin` | Kalendora ist geladen |
| `kalendora_installed` | – | Nach Aktivierung/Datenbank-Update |
| `kalendora_booking_created` | `int $booking_id, int $entry_id, array $input` | Neue Buchung |
| `kalendora_booking_status_changed` | `int $booking_id, string $status, string $previous` | Bestätigt, abgelehnt, storniert … |
| `kalendora_booking_moved` | `int $booking_id, string $previous_start_utc, ?int $previous_resource` | Im Zeitstrahl verschoben |
| `kalendora_entry_created` | `int $entry_id, array $values, array $form` | Neuer Formulareintrag |
| `kalendora_calendar_synced` | `int $connection_id, int $busy_times` | Privater Kalender importiert |
| `kalendora_form_before_submit` | `$form` | Ausgabe vor dem Absende-Knopf (z. B. CAPTCHA) |
| `kalendora_newsletter_signup` | `$result, $provider, $email` | Nach einem Newsletter-Anmeldeversuch |
| `kalendora_customer_cancelled` | `int $booking_id, float $fee, string $reason` | Kunde hat über den Link in der E-Mail abgesagt |
| `kalendora_customer_rescheduled` | `int $booking_id, string $previous_start_utc` | Kunde hat über den Link verschoben |
| `kalendora_booking_fee_changed` | `int $booking_id, float $amount, string $status, string $reason` | Ausfallgebühr erfasst, bezahlt, erlassen |

### Filter

| Hook | Parameter → Rückgabe | Zweck |
|---|---|---|
| `kalendora_slot_available` | `bool $ok, int $start_utc, int $service_id, ?int $resource_id` → `bool` | Eigene Regeln für buchbare Zeiten. Gilt für die Anzeige **und** beim Buchen |
| `kalendora_validate_submission` | `array $errors, array $values, array $form` → `array` | Eigene Prüfungen (`$errors['feld'] = 'Text'`, `'_form'` für das ganze Formular) |
| `kalendora_resource_candidates` | `array $ids, array $service, int $start` → `array` | Reihenfolge/Auswahl bei „beliebiger Mitarbeiter“ |
| `kalendora_form_html` | `string $html, array $form` → `string` | HTML eines Formulars |
| `kalendora_frontend_config` | `array $config, array $form` → `array` | Daten für das Formular-Script |
| `kalendora_field_types` | `array $types` → `array` | Eigene Feldtypen registrieren |
| `kalendora_email_events` | `array $events` → `array` | E-Mail-Ereignisse ergänzen |
| `kalendora_email_html` | `string $html, string $subject, string $body` → `string` | Komplettes E-Mail-HTML |
| `kalendora_upload_limit` | `int` | Uploads pro IP (bzw. IPv6 /64) und Stunde (Standard 30) |
| `kalendora_spam_check` | `true\|WP_Error` | Eigene Spam-Prüfung beim Absenden (`$result, $input, $meta`) |
| `kalendora_captcha_fail_open` | `bool` | Formulare annehmen, wenn der CAPTCHA-Dienst nicht erreichbar ist (Standard `true`) |
| `kalendora_booking_input` | `array` | Buchungsdaten vor dem Speichern (`$input, $form, $clean`) |
| `kalendora_booking_response` | `array` | Antwort an den Besucher nach der Buchung (`$response, $booking_id, $form`), z. B. Weiterleitung |
| `kalendora_notify_booking_created` | `bool` | E-Mails zur neuen Buchung jetzt senden (`$notify, $booking_id`) |
| `kalendora_booking_data` | `array` | Buchungsdaten fürs Admin; `badges` = Liste `{label, tone}` |
| `kalendora_reminder_batch` | `int` | Erinnerungen pro Cron-Lauf (Standard 40, Rest eine Minute später) |
| `kalendora_mail_queue` | `bool` | E-Mails über die Warteschlange senden (Standard `true`; `false` = sofort wie vor 1.8) |
| `kalendora_cancellation_policy` | `array $rule, ?array $service` → `array` | Stornoregel einer Leistung ändern (Frist `hours`, `fee`, `fee_type`, `fee_value`, `no_show`, `late_online`, `self_cancel`, `self_reschedule`) |
| `kalendora_send_reschedule_email` | `bool $send, int $booking_id` → `bool` | E-Mail beim Verschieben unterdrücken |
| `kalendora_feed_event` | `?array $event, array $booking, string $scope` → `?array` | Termin im Kalender-Abo ändern (`null` = weglassen) |
| `kalendora_sanitize_settings` | `array $clean, array $input` → `array` | Eigene Einstellungen ergänzen |
| `kalendora_min_submit_seconds` | `int` | Spam-Schutz: Mindestzeit bis zum Absenden (Standard 3 s) |
| `kalendora_rate_limit` | `int` | Spam-Schutz: Einsendungen pro IP in 10 Minuten (Standard 10) |
| `kalendora_trusted_ip_header` | `string` | **Wichtig hinter Cloudflare/Proxy:** z. B. `'HTTP_CF_CONNECTING_IP'`. Sonst teilen sich alle Besucher die IP des Proxys und damit das Spam-Limit. Bei Listen wie `X-Forwarded-For` wird der rechte (vom Proxy angehängte) Eintrag verwendet |
| `kalendora_open_request_limits` | `array{email:int, ip:int}` | Offene (unbestätigte) Anfragen pro E-Mail-Adresse (Standard 3) und pro IP in 24 h (Standard 6) |
| `kalendora_log_retention_days` | `int` | Tage, die das E-Mail-Protokoll aufbewahrt wird (Standard 365, mindestens 30) |
| `kalendora_csv_delimiter` | `string` | Trennzeichen im CSV-Export |
| `kalendora_currency`, `kalendora_currency_symbol` | `string` | Währung |
| `kalendora_resource_limit` | `int` | Anzahl Mitarbeiter (Free: 3) |
| `kalendora_is_pro` | `bool` | Wird vom Pro-Add-on gesetzt |

### Beispiele

```php
// Neukunden freitags nicht online buchen lassen (Anzeige und Buchung).
add_filter( 'kalendora_slot_available', function ( $ok, $start ) {
	return $ok && 'Fri' !== wp_date( 'D', $start );
}, 10, 2 );

// Nur Firmen-E-Mail-Adressen zulassen.
add_filter( 'kalendora_validate_submission', function ( $errors, $values ) {
	if ( isset( $values['email'] ) && str_ends_with( $values['email'], '@gmail.com' ) ) {
		$errors['email'] = 'Bitte verwenden Sie Ihre Firmenadresse.';
	}
	return $errors;
}, 10, 2 );

// Neue Buchung an ein CRM melden.
add_action( 'kalendora_booking_created', function ( $booking_id ) {
	wp_remote_post( 'https://crm.example.com/hook', array( 'body' => array( 'id' => $booking_id ) ) );
} );

// Im Handy-Kalender nur den Vornamen zeigen.
add_filter( 'kalendora_feed_event', function ( $event, $booking ) {
	$event['summary'] = $booking['service_name'] . ' – ' . strtok( $booking['name'], ' ' );
	return $event;
}, 10, 2 );
```

---

## 5. REST-API (Namensraum `kalendora/v1`)

Admin-Routen brauchen ein eingeloggtes Konto mit dem passenden Kalendora-Recht (Nonce `X-WP-Nonce`).

| Route | Zweck |
|---|---|
| `GET /public/services`, `GET /public/opening` | Leistungen, Öffnungstage (öffentlich) |
| `GET /availability?service&from&to` | Freie Zeiten (öffentlich) |
| `POST /public/forms/{id}/submit` | Formular absenden (öffentlich, mit Spam-Schutz) |
| `POST /public/forms/{id}/upload` (multipart: `file`, `field`, `_bk_t`) | Datei für ein Upload-Feld hochladen → `{token, name, size}`; das Token wird beim Absenden als Wert des Feldes geschickt |
| `GET /bookings`, `POST /bookings/{id}/status`, `POST /bookings/{id}/move` | Buchungen verwalten (`view=fees` = offene Gebühren) |
| `POST /bookings/{id}/fee {status: open|paid|waived, amount?}` | Ausfallgebühr erfassen oder als bezahlt/erlassen markieren |
| `GET /timeline?view=day|week&date` | Zeitstrahl |
| `GET /entries`, `POST /entries/bulk` | Formulareinträge |
| `GET|POST /forms`, `GET|POST /services`, `/resources`, `/schedules/…` | Stammdaten |
| `GET /calendars`, `POST /calendars/connections`, `POST /calendars/feeds/{scope}/reset` | Kalender-Sync |
| `GET|POST /settings`, `GET|PUT /emails/{event}` | Einstellungen, E-Mail-Vorlagen |

## 6. Selbstservice-Link und Stornoregel

- Jede Buchung hat einen persönlichen Link `?kalendora_manage=<uid>.<signatur>` (HMAC-SHA256 aus den WordPress-Salts, 128 Bit, nichts gespeichert). In E-Mails: `{manage_button}` oder `{manage_link}`. PHP: `\Kalendora\Booking\ManagePage::url( $uid )`.
- Die Seite zeigt nur Informationen (GET). Absagen und Verschieben sind POST mit Nonce – Link-Scanner in Mailprogrammen können nichts auslösen.
- Verschieben geht nur auf reguläre freie Zeiten desselben Mitarbeiters (Öffnungszeiten, Vorlauf, Pausen, private Kalender gelten).
- Die Stornoregel wird bei der Buchung als Kopie gespeichert (`bookings.policy`, JSON: Frist, Gebühr, Text, Zeitpunkt der Zustimmung). Spätere Änderungen an Einstellungen oder Preis ändern bestehende Buchungen nicht. Eine Gebühr gilt nur, wenn der Kunde zugestimmt hat.
- Platzhalter: `{cancellation_policy}`, `{cancellation_deadline}`, `{cancellation_fee}`, `{cancellation_fee_note}`, `{cancel_reason}`, `{fee_info}`, `{time_until}`, `{business_phone}`.

## 7. Dateien und Unterschriften

- Dateien liegen in `wp-content/uploads/kalendora-private/` unter zufälligen Namen ohne Endung und sind nur über `admin-post.php?action=kalendora_file` (angemeldet, Nonce) abrufbar. PHP: `\Kalendora\Forms\FileStore::for_entry( $entry_id )` liefert Name, Größe, Typ und Download-Link je Feld.
- Erlaubte Typen: `FileStore::SAFE_TYPES` (Bilder, PDF, Office, Text, ZIP). SVG, HTML und Skripte sind nie möglich.
- Unterschrift: Wert ist entweder ein PNG (`data:image/png;base64,…`, max. 300 KB) oder `typed:Vorname Nachname` (Tastatur-Alternative).

