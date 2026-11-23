# Bookora – Datenmodell (Phase 0)

Alle Tabellen tragen das Präfix `{$wpdb->prefix}bookora_`. Zeiten werden **immer in UTC** gespeichert
(`DATETIME`), die Zeitzone des Kunden bzw. der Ressource wird separat abgelegt. Strukturierte,
selten gefilterte Daten (Formularfelder, Einstellungen) liegen als JSON in `LONGTEXT`-Spalten.

## Übersicht

```
forms ──< entries ──< entry_values
            │
            └──1 bookings >── services >──< resources ──< schedules
                  │                          │    └──< schedule_exceptions
                  ├──< action_tokens         └──< calendar_connections ──< external_events
                  └──< notification_log                          └──< booking_sync
customers ──< entries
email_templates (pro Ereignis, optional pro Formular und Sprache)
```

## Tabellen

| Tabelle | Zweck | Wichtige Spalten |
|---|---|---|
| `forms` | Alle Formulare | `title`, `type` (standard/booking), `status`, `fields` (JSON), `settings` (JSON), `custom_css`, `custom_js` |
| `services` | Leistungen | `name`, `duration`, `buffer_before`, `buffer_after`, `capacity`, `price`, `color`, `min_notice`, `max_advance` |
| `resources` | Mitarbeiter, Räume, Geräte | `type`, `name`, `email`, `user_id`, `timezone`, `color` |
| `resource_services` | Welche Ressource bietet welche Leistung | `resource_id`, `service_id` |
| `schedules` | Wöchentliche Arbeitszeiten | `resource_id` (NULL = global), `weekday` 0–6, `start_time`, `end_time` |
| `schedule_exceptions` | Feiertage, Urlaub, Sonderöffnung | `resource_id`, `date_start`, `date_end`, `start_time`, `end_time`, `type` (closed/open) |
| `customers` | Kunden | `email`, `name`, `phone`, `user_id`, `locale`, `timezone` |
| `entries` | Jede Formular-Einsendung (mit oder ohne Termin) | `uid`, `form_id`, `customer_id`, `status` (new/read/starred/spam/trash), `ip_hash`, `user_agent`, `source_url` |
| `entry_values` | Eingaben aus dem Formular | `entry_id`, `field_key`, `value` |
| `bookings` | Termin zu einem Eintrag | `uid`, `entry_id`, `form_id`, `service_id`, `resource_id`, `customer_id`, `start_utc`, `end_utc`, `timezone`, `status`, `seats` |
| `action_tokens` | Ein-Klick-Links | `booking_id`, `action`, `token_hash` (SHA-256), `expires_at`, `used_at` |
| `email_templates` | Eigene Texte | `event`, `form_id`, `locale`, `subject`, `body` |
| `notification_log` | Versandprotokoll | `booking_id`, `channel`, `event`, `recipient`, `status`, `error` |
| `calendar_connections` | Importierte Kalender (Google / iCloud / Outlook) | `resource_id` (NULL = ganzer Betrieb), `provider` (`ics`), `label`, `account` (nur Host), `credentials` (Adresse, verschlüsselt), `options` (JSON, z. B. ganztägig blockieren), `status` (active/error/paused), `last_error`, `sync_token` (ETag, Last-Modified, Anzahl), `last_synced_at` |
| `external_events` | Belegte Zeiten aus externen Kalendern (ohne Titel) | `connection_id`, `external_id`, `start_utc`, `end_utc`, `all_day` |
| `booking_sync` | Zuordnung Buchung ↔ externer Termin | `booking_id`, `connection_id`, `external_id`, `etag` |

## Status einer Buchung

`pending` → `confirmed` → `completed`
`pending` → `rejected`
`pending` / `confirmed` → `cancelled`
`confirmed` → `no_show`

## Schutz vor Doppelbuchungen

1. Pro Ressource (bzw. für den ganzen Betrieb) wird eine benannte MySQL-Sperre `GET_LOCK()` geholt.
   Unter SQLite (WordPress Playground) entfällt sie – dort gibt es ohnehin nur einen Schreibzugriff gleichzeitig.
2. Danach wird geprüft, ob sich der Zeitraum (inkl. Puffer) mit `pending`/`confirmed`-Buchungen
   oder belegten Zeiten aus externen Kalendern überschneidet und ob die Kapazität reicht.
3. Erst dann wird in einer Transaktion eingefügt und die Sperre freigegeben.
   Dasselbe gilt beim Verschieben im Zeitstrahl.

Gleichzeitige Anfragen auf dieselbe Ressource werden so nacheinander abgearbeitet.

## Sicherheit der Daten

- Ein-Klick-Tokens werden nur als SHA-256-Hash gespeichert, der Klartext steht ausschließlich in der E-Mail.
- Kalenderadressen (enthalten einen geheimen Schlüssel) sowie später OAuth-Zugangsdaten und CalDAV-Passwörter
  werden mit libsodium (`sodium_crypto_secretbox`) verschlüsselt.
  Der Schlüssel wird aus den WordPress-Salts abgeleitet und liegt nicht in der Datenbank.
- Kalender-Abo-Adressen: zufälliger 160-Bit-Schlüssel je Abo (Option `bookora_feed_tokens`), jederzeit zurücksetzbar;
  falsche Schlüssel erhalten 404.
- Aus privaten Kalendern werden nur Beginn und Ende gespeichert, keine Titel oder Beschreibungen.
- Personenbezogene Daten sind an die Datenschutz-Werkzeuge von WordPress (Export/Löschung) angebunden.

## Migrationen

Die Schema-Version steht in der Option `bookora_db_version`. Bei einem Update vergleicht der Installer
die Versionen und führt `dbDelta()` sowie versionierte Migrationsschritte aus.
