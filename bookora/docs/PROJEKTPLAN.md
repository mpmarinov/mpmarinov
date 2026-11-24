# Bookora – Projektplan

**Hersteller:** Marinov Solutions
**Produktname:** Bookora – Termine & Buchungen von Marinov Solutions
**Plugin-Slug:** `bookora` · Textdomain `bookora` · REST `bookora/v1` · Tabellenpräfix `bookora_`
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
| 5 | Sync mit Android/iOS | **Free (umgesetzt):** abonnierbarer Kalender (ICS-Feed) für iPhone, Android/Google und Outlook – für den Betrieb und je Mitarbeiter – plus Import privater Kalender (Google, iCloud, Outlook) als belegte Zeiten. Damit ist die Verfügbarkeit in beide Richtungen abgeglichen, ohne Konto-Verknüpfung. **Pro (später):** direkte Verbindung per Google Calendar API (OAuth), iCloud CalDAV und Microsoft Graph – Termine werden sofort eingetragen. Jede Bestätigungs-E-Mail enthält eine `.ics`-Datei „Zum Kalender hinzufügen“ |
| 6 | One-Klick-Bestätigung | Signierter Link (HMAC-Token, Ablaufzeit, einmalig verwendbar) in der Admin-E-Mail → bestätigen/ablehnen ohne Login. Zusätzlich Schnellaktionen im Dashboard |
| 7 | Bestätigungstexte selbst definieren | Vorlagen-Editor mit Platzhaltern (`{kunde_name}`, `{datum}`, `{uhrzeit}`, `{leistung}`, `{storno_link}` …), pro Formular überschreibbar, mehrsprachig |
| 8 | Absagetexte selbst definieren | wie 7, inkl. optionalem Absagegrund |
| 9 | Gebuchte Zeiten blockieren | Serverseitige Slot-Sperre mit Datenbank-Locking (keine Doppelbuchung bei gleichzeitigen Anfragen), externe Kalendertermine blockieren ebenfalls |
| 10 | Vorschau verfügbarer Zeiten | Live-Verfügbarkeit im Formular (REST-API), Admin-Vorschau pro Formular |
| 11 | Eigener Zeitstrahl-Kalender | Admin-Timeline (horizontaler Zeitstrahl pro Tag/Woche, Ressourcen als Zeilen), Drag & Drop zum Verschieben, Farbcodes nach Status |
| 12 | Mobile & Web | Mobile-First, responsives Admin-Dashboard, Touch-Bedienung, Barrierefreiheit (WCAG 2.2 AA) |
| 13 | Automatische E-Mails | Eingang, Bestätigung, Absage, Erinnerung (z. B. 24 h / 1 h vorher), Umbuchung, Storno, Admin-Benachrichtigung; Versand über Warteschlange (Action Scheduler), kompatibel mit SMTP-Plugins |
| 15 | Allgemeine Formulare (ohne Termin) | Jedes Formular ist entweder **Standard** (Kontakt, Anfrage, Umfrage, Bewerbung …) oder **Buchung**. Gleicher Builder, gleiche Felder, gleiche E-Mails – Einträge werden in einer eigenen Eintragsverwaltung gespeichert (Filter, Suche, Status, CSV-Export, Spam-Schutz) |
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
| 3 | Formular-Builder (alle Feldtypen, bedingte Logik, Mehrschritt) + Frontend-Rendering, Standard- und Buchungsformulare, Eintragsverwaltung | 8–10 Tage |
| 4 | Frontend-Kalender & Live-Verfügbarkeit, Gutenberg-Block, Shortcode | 3 Tage |
| 5 | Buchungsverwaltung, Ein-Klick-Bestätigung/Absage, Status-Workflow | 2–3 Tage |
| 6 | E-Mail-System: Vorlagen-Editor, Platzhalter, Erinnerungen, ICS-Anhänge | 3 Tage |
| 7 | Admin-Zeitstrahl (Timeline) mit Drag & Drop | 3–4 Tage |
| 8 | Kalender-Sync: ICS-Feed, Google (Android), iCloud CalDAV (iOS), Outlook | 5–6 Tage |
| 9 | Custom CSS/JS, Entwickler-Hooks, Theming | 1–2 Tage |
| 10 | Sicherheits-Audit, Performance, Barrierefreiheit, Tests, Dokumentation | 3–4 Tage |
| 11 | Formularvorlagen (Friseur, Arzt, Beratung, Werkstatt, Kurs, Kontakt, Anfrage …) + Einrichtungsassistent | 2–3 Tage |
| 12 | Integrationen 1.0: Mailchimp, Brevo, Google Sheets, Slack, Telegram, reCAPTCHA/Turnstile/hCaptcha | 3–4 Tage |
| 13 | Produktseite unter `mpmarinov.com/bookora`: Landingpage, Preise, Doku, Changelog, Support, Lizenzbedingungen | 3–4 Tage |
| 14 | WordPress.org: readme.txt, Screenshots, Banner/Icon, Plugin-Check, Einreichung | 1–2 Tage |
| | **Summe Entwicklung (Version 1.0)** | **ca. 43–56 Tage ≈ 9–12 Wochen** |
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
2. **Google Cloud Projekt** für die OAuth-App – erst für die direkte Google-Verbindung in Pro nötig (Free kommt ohne aus); Google verlangt eine App-Verifizierung im Namen des Herausgebers.
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

## 8. Entscheidungen (Stand 23.11.2026)

| Thema | Entscheidung |
|---|---|
| Geschäftsmodell | **Free + Pro (Abo)**, faire Preise, Fokus auf hohe Nutzerzahl |
| Produktname | **Bookora** (Slug `bookora`) |
| Startsprachen | Deutsch, Englisch, Bulgarisch |
| Umfang 1.0 | alle Anforderungen + „Empfohlen für 1.0“ aus Abschnitt 7 |
| Formulare ohne Termin | **Ja** – Bookora ist Formular-Builder **und** Buchungssystem in einem Plugin |
| Produktseite | Unterordner `mpmarinov.com/bookora` (kein eigenes Hosting); Domain bookora.com/.de als Weiterleitung reservieren |
| Verkauf & Lizenzen | **Entschieden (24.11.2026):** Pro-Abo über den eigenen WooCommerce-Shop auf mpmarinov.com (Stripe + PayPal vorhanden), eigener Lizenz- und Update-Server. Shop wird für digitale Produkte EU-weit angepasst (OSS) – siehe 10b.3 |

## 9. Free- und Pro-Strategie

**Grundsatz:** Die Free-Version enthält alles, wofür andere Plugins Geld verlangen und was ein
kleiner Betrieb täglich braucht. Pro bringt Funktionen für Wachstum und Umsatz, nicht für die Grundlagen.
Laut den Regeln von wordpress.org darf die Free-Version nicht künstlich beschränkt sein
(keine Testphasen, keine gesperrten Funktionen). Pro wird deshalb ein **separates Add-on-Plugin**,
das sich über Hooks in den Free-Kern einklinkt.

**Free (wordpress.org)**
- Unbegrenzte Formulare, Leistungen, Buchungen (keine künstlichen Limits)
- Alle Feldtypen, bedingte Logik, mehrstufige Formulare
- Frontend-Kalender, Live-Verfügbarkeit, Doppelbuchungsschutz
- Admin-Zeitstrahl mit Drag & Drop
- Ein-Klick-Bestätigung/Absage, alle E-Mail-Vorlagen frei editierbar, Erinnerungen
- Kalender-Sync: Kalender-Abo für iPhone/Android/Outlook (Betrieb + je Mitarbeiter), private Google-/iCloud-/Outlook-Kalender blockieren belegte Zeiten
- Bis zu 3 Mitarbeiter/Ressourcen
- Kunden-Selbstservice (Umbuchen/Stornieren), Zeitzonen, Gruppenbuchungen
- Eigenes CSS/JS, Entwickler-Hooks, DSGVO-Werkzeuge, CSV-Export

**Pro (Abo)**
- Unbegrenzte Mitarbeiter/Ressourcen mit eigenem Login und eigenem Kalender-Sync
- Direkte Kalender-Verbindung (Google OAuth, iCloud CalDAV, Outlook/Microsoft 365): Termine werden sofort eingetragen, Aktualisierung in Sekunden statt Stunden
- Online-Zahlung und Anzahlung (Stripe, PayPal), Gutscheine, Rabattcodes
- SMS- und WhatsApp-Erinnerungen
- Automatische Videolinks (Google Meet, Zoom, Jitsi)
- Wiederkehrende Termine, Warteliste mit Auto-Benachrichtigung
- Webhooks, Zapier/Make
- Statistik-Dashboard (Auslastung, No-Shows, Umsatz)
- KI-Assistent für Texte, White-Label, Priority-Support

**Preisvorschlag (netto, jährlich, monatlich kündbar als Option)**

| Paket | Websites | Jahr | Monat |
|---|---|---|---|
| Starter | 1 | 39 € | 4,90 € |
| Business | 5 | 79 € | 8,90 € |
| Agency | unbegrenzt | 149 € | 15,90 € |

Zum Vergleich: Vergleichbare Pro-Versionen kosten am Markt meist 80–250 € pro Jahr für eine Website.

**Lizenz- und Zahlungsabwicklung:** Empfehlung ist ein „Merchant of Record“ (z. B. Freemius, Paddle
oder Lemon Squeezy). Der Anbieter übernimmt EU-Umsatzsteuer, Rechnungen und Lizenzschlüssel,
das spart dir Buchhaltungsaufwand. **Alternative (24.11.2026): eigener Shop auf mpmarinov.com, siehe 10b.3.** Die Entscheidung fällt vor Phase 13.

**Architekturfolgen**
- Free-Kern mit klaren Erweiterungspunkten (Feldtypen, Zahlungs-Gateways, Benachrichtigungskanäle, Sync-Anbieter als Module)
- Pro-Add-on als eigenes Repository/Plugin mit eigenem Update-Server über den Lizenzanbieter
- Kein Pro-Code im Free-Plugin (Vorgabe von wordpress.org)

## 10. Positionierung

**„Ein Plugin statt zwei.“** Andere Hersteller trennen Formular-Plugin und Buchungs-Plugin.
Bookora vereint beides – mit einer Free-Version ohne künstliche Limits.
Wer Bookora für ein einfaches Kontaktformular installiert, entdeckt die Buchungsfunktionen direkt im selben Plugin.

## 10a. Produktname

„MS-Booking“ ist leider riskant: „MS Bookings“ wird allgemein als **Microsoft Bookings** verstanden.
Das führt zu Markenrechtsproblemen, möglicher Ablehnung auf wordpress.org und Verwechslung in der Suche.

Vorschläge (Anzeige auf wordpress.org z. B. „Bookora – Termine & Buchungen von Marinov Solutions“):

| Name | Wirkung |
|---|---|
| **Bookora** | kurz, international, leicht merkbar, klingt nach Plattform |
| **Slotwise** | erklärt das Produkt (Zeitfenster + clever), stark für SEO |
| **Kalendra** | elegant, funktioniert in DE/EN/BG gleich gut |
| **Termivo** | modern, Bezug zu „Termin“ |
| **MarSol Booking** | direkter Bezug zu **Mar**inov **Sol**utions, ohne Microsoft-Assoziation |

Vor der finalen Wahl prüfen: freier Slug auf wordpress.org, Marken (DPMA, EUIPO), Domain (.com/.de).

## 10b. Neue Anforderungen (24.11.2026)

### 10b.1 Erinnerungen 7 Tage und 24 Stunden vorher ✅ umgesetzt (24.11.2026)
**Vorher:** Technisch schon möglich. Unter *E-Mails → Erinnerungen* „168, 24“ eintragen (Stunden, bis zu 5 Erinnerungen, max. 30 Tage).
**Umsetzung (vor Phase 11):**
- Eingabe in Tagen/Stunden statt nur Stunden („7 Tage“, „24 Stunden“), Standard für neue Installationen: 7 Tage + 24 Stunden
- Platzhalter `{zeit_bis_termin}` („in 7 Tagen“ / „morgen“), damit ein Text für beide Erinnerungen passt; optional eigener Text je Erinnerung
- Regel: Wird ein Termin erst kurz vorher bestätigt, entfällt die längere Erinnerung (keine „in 7 Tagen“-Mail 2 Tage vorher); nach Cron-Ausfall wird nur die nächstliegende Erinnerung gesendet
- Erinnerung kann je Leistung abgeschaltet werden

### 10b.2 Stornofrist und Ausfallgebühr (optional, per Schalter) ✅ umgesetzt (24.11.2026, zusammen mit dem Kunden-Selbstservice)
Viele Praxen arbeiten mit „Absage bis 24 h vorher, sonst x % Ausfallgebühr“. In Bookora als **Option, standardmäßig aus**:
- Schalter „Stornoregel verwenden“ global, je Leistung überschreibbar
- Frist in Stunden (z. B. 24), Gebühr in % vom Leistungspreis **oder** fester Betrag; gilt wahlweise auch für Nichterscheinen („No-Show“)
- Regeltext wird automatisch im Formular angezeigt + **Pflicht-Checkbox** zur Zustimmung; Zeitpunkt und Text der Zustimmung werden zur Buchung gespeichert (Nachweis)
- Selbstservice-Storno nach Fristablauf: deutlicher Hinweis mit Betrag vor dem Absenden („Es fällt eine Gebühr von 24,00 € an“), alternativ Online-Storno nach Frist sperren („Bitte rufen Sie uns an“)
- Buchung wird als „Spät storniert – Gebühr 24,00 €“ markiert, Betreiber bekommt E-Mail, Liste/CSV-Export der offenen Gebühren
- Platzhalter für E-Mails: `{storno_frist}`, `{storno_gebuehr}`, `{storno_bis}` (Datum/Uhrzeit, bis wann kostenlos)
- **Free:** Gebühr wird erfasst und angezeigt, Rechnung stellt die Praxis selbst. **Pro:** automatische Abbuchung über Stripe (hinterlegte Karte/Vorautorisierung)
- Rechtlicher Hinweis in der Hilfe: Ausfallhonorare sind in DE nur mit vorheriger Vereinbarung durchsetzbar, bei Ärzten umstritten – die Checkbox schafft den Nachweis, ersetzt aber keine Rechtsberatung

### 10b.3 Pro-Abo über den eigenen Online-Shop
Statt Merchant of Record (Freemius/Paddle/Lemon Squeezy) Verkauf im bestehenden Shop auf mpmarinov.com.
**Vorteile:** keine 5–10 % Provision, alles in einem Shop, eigene Kundendaten.
**Nachteile:** EU-Umsatzsteuer für digitale Produkte (Steuersatz des Kundenlandes, Meldung über OSS), Rechnungen, Mahnungen, Erstattungen selbst.
**Bausteine (bei WooCommerce):**
- Wiederkehrende Zahlung: WooCommerce Subscriptions (kostenpflichtig) oder kostenlose Alternative, Zahlungsanbieter mit Abo-Fähigkeit (Stripe, PayPal)
- Lizenzschlüssel: wird nach Kauf automatisch erzeugt und per E-Mail verschickt, gilt für 1/5/unbegrenzt Websites
- Lizenz- und Update-Server auf mpmarinov.com (baue ich): Pro-Add-on prüft Lizenz, holt Updates, Aktivierung/Deaktivierung je Website, Ablauf bei Kündigung
**Entschieden:** WooCommerce mit Stripe und PayPal. Der 3D-Druck bleibt auf Deutschland beschränkt; für Bookora Pro wird der Shop um digitale Produkte mit EU-Umsatzsteuer (OSS-Registrierung beim BZSt, Steuersätze je Land) erweitert. Umsetzung Lizenz-/Update-Server in Phase 13

### 10b.4 Weitere Punkte (Abgleich Plan ↔ Code, 24.11.2026)
| Punkt | Stand | Einplanung |
|---|---|---|
| Kunden-Selbstservice (Umbuchen/Stornieren per Link) | ✅ umgesetzt: Platzhalter `{manage_button}` / `{manage_link}` | – |
| QR-Code auf der Kalender-Abo-Seite (Bibliothek qrcode-generator, MIT, kostenlos) | ✅ 24.11.2026 (freigegeben): im Browser als SVG erzeugt, enthält den geräteunabhängigen Abo-Link; Lizenz in THIRD-PARTY-LICENSES.txt | – |
| Datei-Upload & Unterschrift | ✅ umgesetzt (siehe 10b.6) | – |
| Kunden-Zeitzone (Slots in eigener Zeit anzeigen) | ✅ 24.11.2026: Umschalter Ortszeit Betrieb / eigene Zeit (nur bei abweichender Zeitzone), Standard einstellbar, Kundenmail mit `{local_time}` | – |
| Berechnete Felder (z. B. Gesamtpreis) | ✅ 24.11.2026: Formel-Feld mit eigenem Parser (kein eval) in PHP und JS, 3.206 Zufallsformeln ohne Abweichung, Server rechnet immer neu | – |
| E-Mail-Warteschlange | ✅ 24.11.2026: eigene Warteschlange (Tabelle `mail_queue`, kein Fremdpaket): Zustellung nach der Antwort an den Besucher, Wiederholung nach 5/30/120 Min., Sperre gegen Doppelversand, Status „Wartet“ im Protokoll | – |
| Warteliste | fehlt | Pro (laut Abschnitt 9) |
| Bulgarische Übersetzung | ✅ 24.11.2026: alle 1.035 Texte (Admin, Formular, E-Mails), Kunden förmlich mit „Вие“; automatisch geprüft: Platzhalter, HTML, Zeilenumbrüche, Pluralformen | – |
| Abnahmetest auf Staging (Marin, per ZIP) | bereit | jetzt möglich |
| MySQL-Performancetest (lokal im Container, kein Zugriff auf Server) | ✅ 24.11.2026, siehe `docs/PERFORMANCE.md` | – |

### 10b.5 Umsetzung 24.11.2026 – Entscheidungen
- **Eine Frist für alles:** „Kostenlos bis X Stunden vorher“ gilt für Absagen und Verschieben (die vorhandene, bisher wirkungslose Einstellung `cancel_limit_hours` wird dafür verwendet). Danach: ohne Gebührenregel keine Online-Änderung mehr (Hinweis + Telefon/E-Mail), mit Gebührenregel Absage gegen Gebühr oder gesperrt (wählbar). Kurzfristiges Verschieben ist online nicht möglich, sonst ließe sich die Gebühr umgehen.
- **Gebühr nur mit Zustimmung:** Die Regel wird bei der Buchung als Kopie gespeichert (Text, Betrag, Zeitpunkt). Ältere Buchungen und Buchungen ohne Zustimmung kosten nie eine Gebühr.
- **Unbestätigte Anfragen** können immer kostenlos zurückgezogen werden.
- **Nichterscheinen:** Markieren als „Nicht erschienen“ erfasst die Gebühr automatisch, Zurücksetzen entfernt sie wieder. Manuelle Gebühr (z. B. telefonische Absage) im Buchungsdetail.
- **Erinnerungen:** Standard 7 Tage + 24 Stunden; pro Buchung nur die nächstliegende fällige Erinnerung; eine Erinnerung entfällt, wenn sie später als zur Hälfte der Vorlaufzeit käme (7-Tage-Erinnerung spätestens 3,5 Tage vorher); je Leistung abschaltbar; Platzhalter `{time_until}`.
- Tests: 316 Integrationsprüfungen (+49), 65 Unit-Tests (+7), Browser-Test Formular/Handy/Admin ohne Fehler.

### 10b.6 Datei-Upload & Unterschrift (24.11.2026)
- **Upload:** Datei wählen oder hineinziehen, Fortschrittsbalken, mehrere Dateien, Entfernen; Prüfung von Typ und Größe schon im Browser und nochmals auf dem Server. Angezeigt wird immer die echte Grenze (Formular-Einstellung, höchstens das Server-Limit; Hinweis im Builder).
- **Sicherheit:** Ablage in `uploads/bookora-private/JJJJ/MM/` unter zufälligem Namen **ohne Dateiendung** (nicht ausführbar, auch auf nginx), Ordner zusätzlich per `.htaccess`/`web.config` gesperrt. Nur harmlose Typen (Bilder, PDF, Office, Text, ZIP – kein SVG/HTML/PHP); der echte Inhalt wird geprüft, nicht nur die Endung. Besucher erhalten nur ein Einmal-Token (an Formular und Feld gebunden). Download nur angemeldet mit Recht „Buchungen ansehen“, mit Nonce, als Anhang, `nosniff` und Sandbox-CSP. Max. 30 Uploads pro IP und Stunde.
- **Aufräumen:** nicht abgesendete Uploads nach 24 h gelöscht; Dateien werden mit dem Eintrag, bei DSGVO-Löschung und beim Deinstallieren (wenn gewählt) entfernt.
- **Unterschrift:** Zeichnen mit Maus, Finger oder Stift (immer auf weißem Grund, auch im dunklen Design); Alternative für Tastatur/Screenreader: Namen eintippen. Gezeichnete Unterschrift wird als PNG geprüft (Format, Größe) und privat gespeichert.
- **Admin:** Dateien als Download-Links, Unterschrift als Bild im Eintrag und in der Buchung; CSV/E-Mail zeigen die Dateinamen.
- **Gefunden durch den Browser-Test:** Eine WordPress-Hilfsfunktion fehlte auf der öffentlichen Seite (nur im Admin geladen) → Absturz beim Absenden mit Unterschrift; Download-Links waren doppelt kodiert. Beides behoben, neuer HTTP-Test deckt den ersten Fall ab (Gegenprobe gemacht).
- Tests: 348 Integrationsprüfungen (+32), Browser-Test Desktop/Handy/Admin.

## 11. Fortschritt

| Phase | Status |
|---|---|
| 0 – Konzept & Datenmodell | ✅ erledigt (`docs/DATENMODELL.md`) |
| 1 – Grundgerüst | ✅ erledigt: Plugin-Bootstrap, Autoloader, DI-Container, 15 DB-Tabellen mit Migrationen, Rollen & Rechte, Einstellungen + REST-API, React-Admin mit Einstellungsseite, Verschlüsselung (libsodium), DSGVO-Export/-Löschung, Deinstallation, Unit-Tests, PHPCS, CI |
| i18n | ✅ Deutsch und Bulgarisch komplett (je 1.035 Texte, PHP + JS), Werkzeug `npm run i18n` |
| 2 – Leistungen, Öffnungszeiten, Slot-Engine | ✅ erledigt: Slot-Engine (Puffer, Gruppen, Ausnahmen, Zeitumstellung), Doppelbuchungsschutz, REST-API, MySQL + SQLite (Playground), Admin-Bildschirme für Leistungen, Mitarbeiter/Ressourcen, Öffnungszeiten, Feiertage & Sonderöffnungen. 23 Unit-Tests, 62 Integrationsprüfungen, Browser-Test Desktop + Mobil grün |
| 3 – Formular-Builder & Eintragsverwaltung | ✅ Builder, Formulare auf der Webseite, Kalender, Buchungsverwaltung (Bestätigen/Ablehnen/Stornieren/Erschienen, Notizen, Wiedereröffnen mit Konfliktprüfung), Eintragsverwaltung (Lesen, Markieren, Spam, Papierkorb, Sammelaktionen, CSV-Export), Menü-Zähler. Datei-Upload & Unterschrift ✅ (24.11.2026) |
| 4–5 – Kalender & Status-Workflow | ✅ in Phase 2/3 enthalten; Ein-Klick-Bestätigung per E-Mail-Link folgt mit Phase 6 |
| 6 – E-Mail-System | ✅ 9 E-Mail-Vorlagen (Kunde/Betreiber/Formulare), Editor mit Platzhaltern und Live-Vorschau, Test-E-Mail, Ein-Klick-Bestätigen/Ablehnen (Scanner-sicher, einmalig, ablaufend), .ics-Anhang + Google-Kalender-Link, Erinnerungen per Cron, Versandprotokoll |
| 7 – Admin-Zeitstrahl | ✅ Tages- (Zeile je Mitarbeiter) und Wochenansicht, Öffnungs-/Schließzeiten, Jetzt-Linie, Verschieben per Drag & Drop (15-Min.-Raster, auch auf andere Mitarbeiter) oder per Formular, Konfliktprüfung inkl. Pausen, Hinweis außerhalb der Öffnungszeiten, E-Mail „Termin verschoben“ mit neuer Kalenderdatei (abwählbar), mobil scrollbar. 155 Integrationsprüfungen |
| 8 – Kalender-Sync | ✅ Kalender-Abo (ICS-Feed) für Betrieb + je Mitarbeiter mit geheimer, zurücksetzbarer Adresse, ETag/304, Datenschutz-Schalter, Abo-Seite mit Geräteerkennung, „Link aufs Handy senden“. Import privater Kalender alle 15 Min.: eigener RFC-5545-Parser (RRULE, EXDATE, Ausnahmen, Zeitzonen inkl. Outlook-Namen), nur Start/Ende gespeichert, Adresse verschlüsselt, SSRF-Schutz, klare Fehlermeldungen, belegte Zeiten im Zeitstrahl. Tests: 16 Parser-Tests, Differenztest gegen python-dateutil (26.000 Regeln, 0 Abweichungen), RFC-Formatprüfung des Feeds, Lasttest 4,7 MB/15.000 Termine (1,9 s, 9 MB), 218 Integrationsprüfungen |
| 11 – Formularvorlagen & Einrichtungsassistent | ✅ (24.11.2026) 10 Vorlagen (6 mit Termin, 4 ohne), Assistent in 5 Schritten (Betrieb, Öffnungszeiten mit Mittagspause, erste Leistung, Vorlage, Seite als Entwurf), Übersicht mit Tageszahlen und nächsten Terminen statt Platzhalter; axe-core 0 Verstöße. Berechnete Felder ✅ |
| 9 – Eigenes CSS/JS, Hooks, Theming | ✅ Design ohne Code (Hauptfarbe, Eckenradius, Abstände, Hell/Dunkel/Automatisch, Schrift) mit Live-Vorschau; WordPress-Code-Editor (Syntax-Hervorhebung, Fehlerprüfung) für globales und Formular-CSS/JS; Fehler behoben: globales JavaScript wurde nie ausgegeben, globales CSS/JS und Hauptfarbe hatten kein Eingabefeld; eigener Code über Warteschlange (unabhängig von der Ladereihenfolge), Ausbruch aus `<script>` verhindert, Fehler abgefangen; JS-API mit 11 Ereignissen inkl. abbrechbarem `beforeSubmit`, `setValue`/`goTo`, DOM-Ereignisse für Tag Manager; neue PHP-Hooks `bookora_slot_available` (Anzeige **und** Buchung), `bookora_validate_submission`, `bookora_feed_event`, `bookora_form_html`, `bookora_frontend_config`; `docs/ENTWICKLER.md`. Tests: 248 Integrationsprüfungen, Browser-Test der JS-API |
| 10 – Sicherheits-Audit, Performance, Barrierefreiheit, Doku | ✅ Sicherheits-Audit der öffentlichen Angriffsfläche durch unabhängigen Agenten: **keine kritischen Lücken** (keine SQL-Injection, kein XSS, kein CSRF, alle Admin-Routen geschützt). Behoben: E-Mail-Missbrauch (Kundeneingaben werden nie zu Links, max. 3 offene Anfragen pro E-Mail, 6 pro IP/24 h), wirkungslose CAPTCHA-Einstellung entfernt, Kundendaten nicht mehr fremd überschreibbar, Formular-Token an Formular gebunden und max. 7 Tage gültig, IPv6 /64, korrekter Proxy-Eintrag, fremde „Gesendet von“-Adressen verworfen, DSGVO-Löschung auf SQLite + E-Mail-Protokoll, Datums-/Schrittprüfung serverseitig, tägliche Wartung (fehlte komplett). Barrierefreiheit: Fehler per `aria-describedby`, Pfeiltasten im Kalender, Buchung komplett per Tastatur, Kontrast 6,1:1. 267 Integrationsprüfungen. Performance-Messung mit MySQL ✅ (`docs/PERFORMANCE.md`), Admin-Barrierefreiheit ✅ (axe-core WCAG 2.1 AA: 0 Verstöße auf 11 Seiten + Dialogen; Builder per Tastatur mit deutschen Screenreader-Ansagen), Endnutzer-Anleitung ✅ (`docs/ANLEITUNG.md`) |

### Code-Review Phase 8 (unabhängiger zweiter Agent)

Gefunden und behoben: 304-Antworten froren das Importfenster ein (schwerwiegend), UTC-Serien, eigene Outlook-Zeitzonen,
RANGE=THISANDFUTURE, TZID mit Doppelpunkt, Schutz vor bösartigen Serien (Zeitbudget), abgeschnittene Downloads und
Datenbankfehler ersetzten gute Daten, Zeitstrahl an Umstellungstagen, nur noch https-Adressen. Jeder Punkt hat jetzt einen eigenen Test.

### Entscheidungen Phase 8 (selbstständig getroffen)

1. **Free ohne Konto-Verknüpfung:** Abo-Feed + Import über private iCal-Adressen statt OAuth. Funktioniert sofort, ohne Google-Verifizierung, und deckt iPhone, Android und Outlook ab. Direkte OAuth/CalDAV-Verbindung (sofortige Eintragung) wird Pro.
2. **Datenschutz:** Von privaten Kalendern werden nur Beginn und Ende gespeichert – keine Titel. Die Kalenderadresse liegt verschlüsselt in der Datenbank und wird nie an den Browser zurückgegeben.
3. **Sicherheit vor Bequemlichkeit:** Ist ein Kalender kurz nicht erreichbar, blockieren die zuletzt bekannten Zeiten weiter (lieber ein Slot zu wenig als eine Doppelbuchung). Nur „Pausieren“ gibt sie frei.
4. **Kalender des ganzen Betriebs** (z. B. Privatkalender der Inhaberin) blockiert alle Mitarbeiter; ein Mitarbeiter-Kalender nur diese Person.
5. **Eigene Termine nicht doppelt:** Kommen Bookora-Termine über ein Abo zurück, werden sie beim Import erkannt und übersprungen; der eigene Feed kann nicht importiert werden.
6. **„Link aufs Handy senden“ statt QR-Code:** Die QR-Bibliothek wurde ohne deine Freigabe nicht installiert. Per E-Mail kommt jeder Mitarbeiter direkt an seinen eigenen Link. QR kann später ergänzt werden.
7. **Ehrliche Beschreibung:** readme.txt verspricht kein „Zwei-Wege-Sync mit Google“ mehr, sondern beschreibt genau, was Free tut; neuer Abschnitt „External services“ (Pflicht bei wordpress.org).
8. **Ansprache:** Die E-Mail „Termin verschoben“ nutzte versehentlich „du“ – jetzt wie alle Kundentexte „Sie“.
