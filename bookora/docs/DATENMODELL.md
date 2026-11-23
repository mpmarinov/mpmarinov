# Bookora – Datenmodell (Phase 0)

Alle Tabellen tragen das Präfix `{$wpdb->prefix}bookora_`. Zeiten werden **immer in UTC** gespeichert
(`DATETIME`), die Zeitzone des Kunden bzw. der Ressource wird separat abgelegt. Strukturierte,
selten gefilterte Daten (Formularfelder, Einstellungen) liegen als JSON in `LONGTEXT`-Spalten.

## Übersicht

```
forms ──< bookings >── services >──< resources ──< schedules
            │  │                         │    └──< schedule_exceptions
            │  └──< booking_values       └──< calendar_connections ──< external_events
            │                                                   └──< booking_sync
            ├──< action_tokens
            ├──< notification_log
customers ──┘
email_templates (pro Ereignis, optional pro Formular und Sprache)
```

## Tabellen

| Tabelle | Zweck | Wichtige Spalten |
|---|---|---|
| `forms` | Buchungsformulare | `title`, `status`, `fields` (JSON), `settings` (JSON), `custom_css`, `custom_js` |
| `services` | Leistungen | `name`, `duration`, `buffer_before`, `buffer_after`, `capacity`, `price`, `color`, `min_notice`, `max_advance` |
| `resources` | Mitarbeiter, Räume, Geräte | `type`, `name`, `email`, `user_id`, `timezone`, `color` |
| `resource_services` | Welche Ressource bietet welche Leistung | `resource_id`, `service_id` |
| `schedules` | Wöchentliche Arbeitszeiten | `resource_id` (NULL = global), `weekday` 0–6, `start_time`, `end_time` |
| `schedule_exceptions` | Feiertage, Urlaub, Sonderöffnung | `resource_id`, `date_start`, `date_end`, `start_time`, `end_time`, `type` (closed/open) |
| `customers` | Kunden | `email`, `name`, `phone`, `user_id`, `locale`, `timezone` |
| `bookings` | Buchungen | `uid`, `form_id`, `service_id`, `resource_id`, `customer_id`, `start_utc`, `end_utc`, `timezone`, `status`, `seats` |
| `booking_values` | Eingaben aus dem Formular | `booking_id`, `field_key`, `value` |
| `action_tokens` | Ein-Klick-Links | `booking_id`, `action`, `token_hash` (SHA-256), `expires_at`, `used_at` |
| `email_templates` | Eigene Texte | `event`, `form_id`, `locale`, `subject`, `body` |
| `notification_log` | Versandprotokoll | `booking_id`, `channel`, `event`, `recipient`, `status`, `error` |
| `calendar_connections` | Google / iCloud / Outlook | `resource_id`, `provider`, `credentials` (verschlüsselt), `calendar_id`, `sync_token` |
| `external_events` | Belegte Zeiten aus externen Kalendern | `connection_id`, `external_id`, `start_utc`, `end_utc` |
| `booking_sync` | Zuordnung Buchung ↔ externer Termin | `booking_id`, `connection_id`, `external_id`, `etag` |

## Status einer Buchung

`pending` → `confirmed` → `completed`
`pending` → `rejected`
`pending` / `confirmed` → `cancelled`
`confirmed` → `no_show`

## Schutz vor Doppelbuchungen

1. Eine Transaktion wird gestartet und die Zeile der Ressource mit `SELECT … FOR UPDATE` gesperrt.
2. Danach wird geprüft, ob sich der Zeitraum (inkl. Puffer) mit `pending`/`confirmed`-Buchungen
   oder externen Terminen überschneidet und ob die Kapazität reicht.
3. Erst dann wird eingefügt und die Transaktion abgeschlossen.

Gleichzeitige Anfragen auf dieselbe Ressource werden so nacheinander abgearbeitet.

## Sicherheit der Daten

- Ein-Klick-Tokens werden nur als SHA-256-Hash gespeichert, der Klartext steht ausschließlich in der E-Mail.
- OAuth-Zugangsdaten und CalDAV-Passwörter werden mit libsodium (`sodium_crypto_secretbox`) verschlüsselt.
  Der Schlüssel wird aus den WordPress-Salts abgeleitet und liegt nicht in der Datenbank.
- Personenbezogene Daten sind an die Datenschutz-Werkzeuge von WordPress (Export/Löschung) angebunden.

## Migrationen

Die Schema-Version steht in der Option `bookora_db_version`. Bei einem Update vergleicht der Installer
die Versionen und führt `dbDelta()` sowie versionierte Migrationsschritte aus.
