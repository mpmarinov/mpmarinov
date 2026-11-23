# Bookora – Entwickler-Dokumentation

Stand: Phase 9. Alles hier ist öffentliche, stabile Schnittstelle. Änderungen werden im Changelog angekündigt.

---

## 1. Design ohne Code

**Bookora → Einstellungen → Design & eigener Code**

| Einstellung | Wirkung |
|---|---|
| Hauptfarbe | `--bookora-primary` (Buttons, gewählte Zeit, Fokus) |
| Eckenradius 0–24 px | `--bookora-radius` |
| Abstände: Kompakt / Normal / Großzügig | Klasse `bookora--compact` bzw. `bookora--comfortable` |
| Farbschema: Hell / Dunkel / Automatisch | Klasse `bookora--scheme-dark` bzw. `bookora--scheme-auto` (folgt `prefers-color-scheme`) |
| Schrift: wie Theme / Systemschrift | Klasse `bookora--font-system` |

---

## 2. CSS

Jedes Formular hat die Klassen `bookora`, `bookora-form-<ID>`, `bookora--booking` bzw. `bookora--standard`.
Eigenes CSS gibt es global (Einstellungen) und pro Formular (Formular → Einstellungen → Eigener Code).
HTML-Tags werden aus dem CSS entfernt; speichern dürfen es nur Benutzer mit dem Recht `unfiltered_html`.

### CSS-Variablen

| Variable | Standard | Bedeutung |
|---|---|---|
| `--bookora-primary` | `#2563eb` | Hauptfarbe |
| `--bookora-primary-contrast` | `#fff` | Schrift auf der Hauptfarbe |
| `--bookora-text` | `inherit` | Textfarbe |
| `--bookora-muted` | `#6b7280` | Hinweise, Hilfetexte |
| `--bookora-border` | `#d1d5db` | Rahmen |
| `--bookora-bg` | `#fff` | Hintergrund der Felder |
| `--bookora-soft` | 10 % Hauptfarbe | Hervorhebungen (z. B. „Gewählt: …“) |
| `--bookora-error` | `#c81e1e` | Fehler |
| `--bookora-radius` | `10px` | Eckenradius |
| `--bookora-gap` | `18px` | Abstand zwischen Feldern |
| `--bookora-input-height` | `46px` | Höhe der Eingabefelder |

```css
/* Nur Formular 3, breiter und runder */
.bookora-form-3 { --bookora-radius: 18px; --bookora-gap: 24px; max-width: 900px; }
```

---

## 3. JavaScript

Eigenes JavaScript gibt es global (läuft **einmal pro Seite**, sobald ein Bookora-Formular darauf ist) und
pro Formular (läuft **für dieses Formular**, sobald es bereit ist). Der Code wird über eine Warteschlange
ausgeführt und funktioniert daher unabhängig von der Ladereihenfolge, auch bei nachgeladenen Formularen.
Fehler im eigenen Code werden abgefangen und in der Browser-Konsole gemeldet; das Formular funktioniert weiter.

### Variablen im eigenen Code

| Code | `bookora` ist … | zusätzlich |
|---|---|---|
| global | die Seiten-API `window.bookora` | – |
| pro Formular | die API **dieses** Formulars | `form` = das Formular-Element (`div.bookora`) |

### Seiten-API `window.bookora`

| Methode | Beschreibung |
|---|---|
| `bookora.forms` | Liste der APIs aller Formulare auf der Seite |
| `bookora.on( event, ( data, formApi ) => {} )` | Ereignis **aller** Formulare (auch später geladener) |
| `bookora.off( event, callback )` | Abmelden |
| `bookora.ready( ( formApi ) => {} )` | Für jedes Formular ausführen, sobald es bereit ist |

Von außen (z. B. im Theme) ohne Ladereihenfolge-Probleme:

```js
( window.bookoraQueue = window.bookoraQueue || [] ).push( function ( bookora ) {
	bookora.on( 'success', function ( data, form ) { /* … */ } );
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

Jedes Ereignis wird zusätzlich als DOM-Ereignis `bookora:<name>` am Formular ausgelöst (bubbelt bis `document`),
`detail = { formId, data }`. Ideal für Google Tag Manager, Matomo & Co. ohne Bookora-Code:

```js
document.addEventListener( 'bookora:success', function ( e ) {
	window.dataLayer = window.dataLayer || [];
	window.dataLayer.push( { event: 'bookora_booking', form_id: e.detail.formId } );
} );
```

### Beispiele

```js
// Pro Formular: Hinweis bei Terminen am Samstag
bookora.on( 'slot', function ( slot ) {
	if ( slot && new Date( slot.date ).getDay() === 6 ) {
		alert( 'Samstags nur mit Voranmeldung per Telefon.' );
	}
} );

// Pro Formular: Gutscheincode prüfen, bevor gesendet wird
bookora.on( 'beforeSubmit', function ( data ) {
	if ( data.values.gutschein && ! /^BK-\d{4}$/.test( data.values.gutschein ) ) {
		data.cancel( 'Der Gutscheincode hat das Format BK-1234.' );
	}
} );

// Pro Formular: Feld aus der Adresszeile vorbelegen (?quelle=instagram)
bookora.setValue( 'quelle', new URLSearchParams( location.search ).get( 'quelle' ) || '' );
```

---

## 4. PHP-Hooks

### Aktionen

| Hook | Parameter | Zeitpunkt |
|---|---|---|
| `bookora_loaded` | `Plugin $plugin` | Bookora ist geladen |
| `bookora_installed` | – | Nach Aktivierung/Datenbank-Update |
| `bookora_booking_created` | `int $booking_id, int $entry_id, array $input` | Neue Buchung |
| `bookora_booking_status_changed` | `int $booking_id, string $status, string $previous` | Bestätigt, abgelehnt, storniert … |
| `bookora_booking_moved` | `int $booking_id, string $previous_start_utc, ?int $previous_resource` | Im Zeitstrahl verschoben |
| `bookora_entry_created` | `int $entry_id, array $values, array $form` | Neuer Formulareintrag |
| `bookora_calendar_synced` | `int $connection_id, int $busy_times` | Privater Kalender importiert |

### Filter

| Hook | Parameter → Rückgabe | Zweck |
|---|---|---|
| `bookora_slot_available` | `bool $ok, int $start_utc, int $service_id, ?int $resource_id` → `bool` | Eigene Regeln für buchbare Zeiten. Gilt für die Anzeige **und** beim Buchen |
| `bookora_validate_submission` | `array $errors, array $values, array $form` → `array` | Eigene Prüfungen (`$errors['feld'] = 'Text'`, `'_form'` für das ganze Formular) |
| `bookora_resource_candidates` | `array $ids, array $service, int $start` → `array` | Reihenfolge/Auswahl bei „beliebiger Mitarbeiter“ |
| `bookora_form_html` | `string $html, array $form` → `string` | HTML eines Formulars |
| `bookora_frontend_config` | `array $config, array $form` → `array` | Daten für das Formular-Script |
| `bookora_field_types` | `array $types` → `array` | Eigene Feldtypen registrieren |
| `bookora_email_events` | `array $events` → `array` | E-Mail-Ereignisse ergänzen |
| `bookora_email_html` | `string $html, string $subject, string $body` → `string` | Komplettes E-Mail-HTML |
| `bookora_send_reschedule_email` | `bool $send, int $booking_id` → `bool` | E-Mail beim Verschieben unterdrücken |
| `bookora_feed_event` | `?array $event, array $booking, string $scope` → `?array` | Termin im Kalender-Abo ändern (`null` = weglassen) |
| `bookora_sanitize_settings` | `array $clean, array $input` → `array` | Eigene Einstellungen ergänzen |
| `bookora_min_submit_seconds` | `int` | Spam-Schutz: Mindestzeit bis zum Absenden (Standard 3 s) |
| `bookora_rate_limit` | `int` | Spam-Schutz: Einsendungen pro IP in 10 Minuten (Standard 10) |
| `bookora_trusted_ip_header` | `string` | z. B. `'HTTP_CF_CONNECTING_IP'` hinter Cloudflare |
| `bookora_csv_delimiter` | `string` | Trennzeichen im CSV-Export |
| `bookora_currency`, `bookora_currency_symbol` | `string` | Währung |
| `bookora_resource_limit` | `int` | Anzahl Mitarbeiter (Free: 3) |
| `bookora_is_pro` | `bool` | Wird vom Pro-Add-on gesetzt |

### Beispiele

```php
// Neukunden freitags nicht online buchen lassen (Anzeige und Buchung).
add_filter( 'bookora_slot_available', function ( $ok, $start ) {
	return $ok && 'Fri' !== wp_date( 'D', $start );
}, 10, 2 );

// Nur Firmen-E-Mail-Adressen zulassen.
add_filter( 'bookora_validate_submission', function ( $errors, $values ) {
	if ( isset( $values['email'] ) && str_ends_with( $values['email'], '@gmail.com' ) ) {
		$errors['email'] = 'Bitte verwenden Sie Ihre Firmenadresse.';
	}
	return $errors;
}, 10, 2 );

// Neue Buchung an ein CRM melden.
add_action( 'bookora_booking_created', function ( $booking_id ) {
	wp_remote_post( 'https://crm.example.com/hook', array( 'body' => array( 'id' => $booking_id ) ) );
} );

// Im Handy-Kalender nur den Vornamen zeigen.
add_filter( 'bookora_feed_event', function ( $event, $booking ) {
	$event['summary'] = $booking['service_name'] . ' – ' . strtok( $booking['name'], ' ' );
	return $event;
}, 10, 2 );
```

---

## 5. REST-API (Namensraum `bookora/v1`)

Admin-Routen brauchen ein eingeloggtes Konto mit dem passenden Bookora-Recht (Nonce `X-WP-Nonce`).

| Route | Zweck |
|---|---|
| `GET /public/services`, `GET /public/opening` | Leistungen, Öffnungstage (öffentlich) |
| `GET /availability?service&from&to` | Freie Zeiten (öffentlich) |
| `POST /public/forms/{id}/submit` | Formular absenden (öffentlich, mit Spam-Schutz) |
| `GET /bookings`, `POST /bookings/{id}/status`, `POST /bookings/{id}/move` | Buchungen verwalten |
| `GET /timeline?view=day|week&date` | Zeitstrahl |
| `GET /entries`, `POST /entries/bulk` | Formulareinträge |
| `GET|POST /forms`, `GET|POST /services`, `/resources`, `/schedules/…` | Stammdaten |
| `GET /calendars`, `POST /calendars/connections`, `POST /calendars/feeds/{scope}/reset` | Kalender-Sync |
| `GET|POST /settings`, `GET|PUT /emails/{event}` | Einstellungen, E-Mail-Vorlagen |
