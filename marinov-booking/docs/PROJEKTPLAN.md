# Marinov Booking – Projektplan

**Hersteller:** Marinov Solutions
**Plugin-Slug (Vorschlag):** `marinov-booking`
**Stand:** 23.11.2026 · Version des Plans: 1.0

---

## 1. Ziel

Ein modernes WordPress-Plugin für Terminbuchungen mit frei gestaltbaren Formularen,
Kalender- und Zeitstrahl-Ansicht, automatischer Kalender-Synchronisierung (Android/iOS),
Ein-Klick-Bestätigung, eigenen E-Mail-Texten und voller Erweiterbarkeit per CSS/JavaScript.
Veröffentlichung im offiziellen WordPress-Plugin-Verzeichnis (wordpress.org).

## 2. Funktionsumfang (aus deinen Anforderungen)

| # | Anforderung | Umsetzung |
|---|---|---|
| 1 | Unterschiedliche Buchungsformulare | Drag-&-Drop-Formular-Builder im Admin (React), beliebig viele Formulare, je Formular eigener Gutenberg-Block + Shortcode `[marinov_booking id="…"]` |
| 2 | Alle Feldvarianten | Text, E-Mail, Telefon (mit Ländervorwahl), Zahl, Textbereich, Auswahl, Mehrfachauswahl, Radio, Checkbox, Datum, Uhrzeit, Datum+Zeit, Datei-Upload, Adresse, URL, Bewertung/Sterne, Schieberegler, versteckte Felder, Einwilligung (DSGVO), Unterschrift, Trenner/Überschrift/HTML-Block, berechnete Felder, mehrstufige Formulare (Steps), bedingte Logik (Feld X nur sichtbar wenn …), Validierungsregeln, Platzhalter, Pflichtfelder |
| 3 | Mit Zeiten | Leistungen mit Dauer, Pufferzeiten vor/nach, Öffnungszeiten pro Wochentag, Pausen, Ausnahmen (Feiertage, Urlaub), Mindestvorlauf, max. Buchungshorizont |
| 4 | Mit Kalender | Frontend-Kalender (Monat/Woche/Tag) mit freien Slots, mobile Ansicht als Liste |
| 5 | Sync mit Android/iOS | **Android:** Google Calendar API (OAuth, Zwei-Wege). **iOS:** iCloud über CalDAV (Zwei-Wege) bzw. abonnierbarer ICS-Feed (Ein-Weg). Zusätzlich: Microsoft Outlook über Microsoft Graph. Jede Bestätigungs-E-Mail enthält eine `.ics`-Datei „Zum Kalender hinzufügen“ |
| 6 | One-Klick-Bestätigung | Signierter Link (HMAC-Token, Ablaufzeit, einmalig verwendbar) in der Admin-E-Mail → bestätigen/ablehnen ohne Login. Zusätzlich Schnellaktionen im Dashboard |
| 7 | Bestätigungstexte selbst definieren | Vorlagen-Editor mit Platzhaltern (`{kunde_name}`, `{datum}`, `{uhrzeit}`, `{leistung}`, `{storno_link}` …), pro Formular überschreibbar, mehrsprachig |
| 8 | Absagetexte selbst definieren | wie 7, inkl. optionalem Absagegrund |
| 9 | Gebuchte Zeiten blockieren | Serverseitige Slot-Sperre mit Datenbank-Locking (keine Doppelbuchung bei gleichzeitigen Anfragen), externe Kalendertermine blockieren ebenfalls |
| 10 | Vorschau verfügbarer Zeiten | Live-Verfügbarkeit im Formular (REST-API), Admin-Vorschau pro Formular |
| 11 | Eigener Zeitstrahl-Kalender | Admin-Timeline (horizontaler Zeitstrahl pro Tag/Woche, Ressourcen als Zeilen), Drag & Drop zum Verschieben, Farbcodes nach Status |
| 12 | Mobile & Web | Mobile-First, responsives Admin-Dashboard, Touch-Bedienung, Barrierefreiheit (WCAG 2.2 AA) |
| 13 | Automatische E-Mails | Eingang, Bestätigung, Absage, Erinnerung (z. B. 24 h / 1 h vorher), Umbuchung, Storno, Admin-Benachrichtigung; Versand über Warteschlange (Action Scheduler), kompatibel mit SMTP-Plugins |
| 14 | Eigenes CSS & JavaScript | Pro Formular und global: CSS-Editor (Code-Editor von WP), JS-Hooks (`marinovBooking.on('slotSelected', …)`), PHP-Filter/Actions für Entwickler, CSS-Variablen für Theming |

## 3. Technologie (Stand der Technik 2026)

- **PHP 8.1+**, **WordPress 6.6+** (getestet bis aktuelle Version), Multisite-fähig
- **Architektur:** Namespaces, PSR-4-Autoloading (Composer), Service-Container, eigene Datenbanktabellen (performant statt Post-Meta)
- **Frontend:** Gutenberg-Blöcke (block.json, Interactivity API), Vanilla-JS-Fallback für Shortcode, keine jQuery-Abhängigkeit
- **Admin:** React über `@wordpress/scripts` und `@wordpress/components` (natives WP-Look & Feel)
- **API:** WordPress REST API (`/wp-json/marinov-booking/v1/…`) mit Rechteprüfung
- **Hintergrundjobs:** Action Scheduler (E-Mails, Sync, Erinnerungen)
- **Sicherheit:** Nonces, Capabilities (eigene Rollen „Buchungsmanager“), Prepared Statements, Escaping aller Ausgaben, Rate-Limiting, Honeypot + optional Cloudflare Turnstile/hCaptcha, verschlüsselte Speicherung von OAuth-Tokens, DSGVO-Export/-Löschung über WP-Privacy-Tools
- **Qualität:** PHPCS mit WordPress Coding Standards, PHPStan, PHPUnit, Jest, Playwright-E2E-Tests, „Plugin Check“ (offizielles WP.org-Prüftool), GitHub Actions CI
- **i18n:** komplett übersetzbar (Deutsch, Englisch, Bulgarisch zum Start), RTL-tauglich

## 4. Phasen & Zeitplan

Zeitangaben = konzentrierte Entwicklungstage in gemeinsamen Sessions (ich programmiere, du testest und gibst Feedback).

| Phase | Inhalt | Dauer |
|---|---|---|
| 0 | Konzept final abstimmen, Datenmodell, UI-Entwürfe, offene Fragen klären | 1–2 Tage |
| 1 | Grundgerüst: Plugin-Struktur, Build-Pipeline, CI, DB-Tabellen, Rollen, Einstellungen | 2 Tage |
| 2 | Leistungen, Öffnungszeiten, Ausnahmen, Slot-Engine mit Doppelbuchungsschutz | 3–4 Tage |
| 3 | Formular-Builder (alle Feldtypen, bedingte Logik, Mehrschritt) + Frontend-Rendering | 5–6 Tage |
| 4 | Frontend-Kalender & Live-Verfügbarkeit, Gutenberg-Block, Shortcode | 3 Tage |
| 5 | Buchungsverwaltung, Ein-Klick-Bestätigung/Absage, Status-Workflow | 2–3 Tage |
| 6 | E-Mail-System: Vorlagen-Editor, Platzhalter, Erinnerungen, ICS-Anhänge | 3 Tage |
| 7 | Admin-Zeitstrahl (Timeline) mit Drag & Drop | 3–4 Tage |
| 8 | Kalender-Sync: ICS-Feed, Google (Android), iCloud CalDAV (iOS), Outlook | 5–6 Tage |
| 9 | Custom CSS/JS, Entwickler-Hooks, Theming | 1–2 Tage |
| 10 | Sicherheits-Audit, Performance, Barrierefreiheit, Tests, Dokumentation | 3–4 Tage |
| 11 | WordPress.org: readme.txt, Screenshots, Banner/Icon, Plugin-Check, Einreichung | 1–2 Tage |
| | **Summe Entwicklung (Version 1.0)** | **ca. 32–41 Tage ≈ 7–9 Wochen** |
| | WordPress.org-Review (Wartezeit, nicht beeinflussbar) | 1–6 Wochen |

**Meilensteine**
- M1 (nach Phase 4): Erste buchbare Version auf deiner Testseite
- M2 (nach Phase 7): Vollständige Verwaltung inkl. E-Mails und Zeitstrahl
- M3 (nach Phase 10): Release Candidate 1.0
- M4: Veröffentlichung auf wordpress.org

## 5. Wartung nach dem Release

- Sicherheitsprüfung bei jeder neuen WordPress-/PHP-Version und monatlich routinemäßig
- Abhängigkeits-Updates (Composer/npm) mit automatischem Schwachstellen-Scan in der CI
- Kompatibilitätstests gegen WordPress-Beta/RC vor jedem Major-Release
- Optional: regelmäßige geplante Prüf-Session (automatischer Termin), die ich einrichten kann

## 6. Was ich von dir brauche (kann ich nicht selbst erledigen)

1. **WordPress.org-Konto** von Marinov Solutions – die Einreichung und der SVN-Zugang laufen über dein Konto.
2. **Google Cloud Projekt** für die OAuth-App (Android-Sync); Google verlangt eine App-Verifizierung im Namen des Herausgebers.
3. **Apple:** keine App nötig – Nutzer verbinden iCloud mit einem app-spezifischen Passwort.
4. **Testumgebung:** eine Staging-WordPress-Seite (nicht die Live-Seite) für Abnahmetests – ich teste lokal in Docker, keine Zugriffe auf deinen Server.
5. **Branding:** Logo/Farben von Marinov Solutions für Icon, Banner und Admin-Design.

## 7. Zusätzliche Ideen zur Diskussion

**Empfohlen für 1.0**
- Mehrere Mitarbeiter/Ressourcen (Räume, Geräte) mit eigenen Kalendern
- Kunden-Selbstservice: Umbuchen/Stornieren per Link mit Fristen
- Zeitzonen-Unterstützung (Kunde sieht Slots in eigener Zeitzone)
- Gruppenbuchungen / Kapazitäten (z. B. Kurse mit 10 Plätzen)
- Warteliste mit automatischer Benachrichtigung bei frei gewordenem Slot
- Export (CSV/Excel) und DSGVO-konforme Löschfristen

**Später (1.x / Pro-Version)**
- Online-Zahlung / Anzahlung (Stripe, PayPal), Gutscheine
- SMS- und WhatsApp-Erinnerungen (reduziert No-Shows deutlich)
- Automatische Videolinks (Google Meet, Zoom, Jitsi)
- Wiederkehrende Termine
- Webhooks + Zapier/Make-Anbindung
- Statistik-Dashboard (Auslastung, No-Show-Quote, Umsatz)
- KI-Assistent für E-Mail-Texte und Formularvorschläge
- PWA für Mitarbeiter (Push-Benachrichtigung bei neuer Buchung)

## 8. Offene Entscheidungen

1. **Geschäftsmodell:** komplett kostenlos oder Free (wordpress.org) + Pro-Add-on? Das beeinflusst die Architektur von Anfang an.
2. **Plugin-Name:** „Marinov Booking“ oder ein eigener Produktname? Muss auf wordpress.org noch frei sein.
3. **Sprachen zum Start:** Deutsch, Englisch, Bulgarisch?
4. **Welche Zusatzideen** aus Abschnitt 7 sollen in Version 1.0?
