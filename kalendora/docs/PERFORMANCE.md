# Kalendora – Leistungstest mit MySQL/MariaDB

Stand 24.11.2026 · MariaDB 10.11 · PHP 8.3 · WordPress 6.9 · lokal im Container (kein Zugriff auf Kundenserver)

Skript: `php tests/Performance/perf.php /pfad/zu/wordpress [mitarbeiter] [--keep]`
**Nur auf einer reinen Testinstallation ausführen – das Skript löscht vorher alle Kalendora-Daten.**

## Testdaten

| Szenario | Mitarbeiter | Buchungen | Einträge | Feldwerte | Kunden |
|---|---|---|---|---|---|
| Kleine Praxis (Free) | 3 | 14.242 | 34.242 | 96.968 | 15.000 |
| Große Praxis (Pro) | 20 | 95.128 | 115.128 | 420.512 | 30.000 |

Zwei Jahre Vergangenheit + drei Monate Zukunft, Mo–Fr 8–18 Uhr, ~85 % ausgebucht, dazu 20.000 Kontaktanfragen.

## Ergebnisse (Median, große Praxis)

| Ablauf | Zeit | Abfragen | Speicher |
|---|---|---|---|
| Freie Termine, ein Monat, „beliebiger Mitarbeiter“ (20) | 107 ms | 143 | 0,9 MB |
| Freie Termine, ein Monat, ein Mitarbeiter | 5 ms | 10 | 0,2 MB |
| Buchung anlegen (Konfliktprüfung + E-Mails) | 159 ms | 220 | 1,1 MB |
| Buchungsliste (Standard-Tab) | 56 ms | 4 | 0,1 MB |
| Buchungsliste, Seite 200 | 241 ms | 4 | 0,2 MB |
| Zeitstrahl Woche (534 Termine) | 71 ms | 87 | 5,9 MB |
| Menü-Zähler (jede Admin-Seite) | 3 ms | 2 | – |
| Suche in allen Formularwerten (420.000) | 725 ms | 4 | 0,1 MB |
| CSV-Export 95.000 Buchungen | 2,9 s | 193 | 4,2 MB |
| Kalender-Abo ganzer Betrieb (7.328 Termine, 2,8 MB) | 483 ms | 24 | 47 MB |
| Erinnerungen, ein Cron-Lauf (40 Mails) | 190 ms | 324 | 0,6 MB |

Kleine Praxis: alle Abläufe unter 60 ms, außer Suche (197 ms) und CSV-Export (350–420 ms).

## Gefunden und behoben

| Problem | Vorher | Nachher |
|---|---|---|
| Buchungsliste zählte alle Tabs einzeln mit 4 Joins | 9 Abfragen, 148 ms | 4 Abfragen, 56 ms |
| Große Seitenzahlen lasen ganze Zeilen | 386 ms | 241 ms (erst IDs, dann Zeilen) |
| Zeitstrahl Woche lud Öffnungszeiten je Tag × Mitarbeiter | 579 Abfragen | 87 Abfragen |
| **Zeitstrahl und Kalender-Abo zeigten höchstens 1.000 Termine** (große Praxen: Termine fehlten) | abgeschnitten | vollständig, seitenweise geladen |
| Menü-Zähler ohne passenden Index | 18 ms | 3 ms (neuer Index, Schema 1.7.0) |
| **CSV-Export lud alles in den Speicher** (Abbruch bei 128/256 MB-Hostern) | mehrere 100 MB | 4 MB, Zeilen werden direkt geschrieben |
| **Alle fälligen Erinnerungen in einem Lauf** (Zeitlimit, SMTP-Sperre beim Hoster) | unbegrenzt | max. 40 pro Lauf + 20 s, Rest eine Minute später (Filter `kalendora_reminder_batch`) |
| Integrationstest setzte stillschweigend Zeitzone Berlin voraus | 1 Fehler auf frischer Installation | Test setzt die Zeitzone selbst |

Alle 348 Integrationsprüfungen laufen auf **MySQL/MariaDB und SQLite** grün.

## Bewusst so gelassen

- **Volltextsuche über Formularwerte** (725 ms bei 420.000 Werten): `LIKE '%…%'` kann keinen Index nutzen. Für Praxen absolut ausreichend; ein FULLTEXT-Index wäre auf SQLite (Playground) nicht verfügbar.
- **„Beliebiger Mitarbeiter“** fragt je Mitarbeiter ab (~7 Abfragen). Bei 20 Mitarbeitern 107 ms – gut. Bei Bedarf später bündeln.
- **CSV-Export 2,9 s** für 95.000 Buchungen – läuft einmalig und speicherschonend.
