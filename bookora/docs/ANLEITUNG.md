# Bookora – Anleitung für Praxen und Betriebe

Diese Anleitung führt Sie in etwa 20 Minuten von der Installation bis zur ersten Online-Buchung.
Alle Menüpunkte finden Sie im WordPress-Admin unter **Bookora**.

---

## 1. Installation

1. **Plugins → Installieren** → „Bookora“ suchen → **Jetzt installieren** → **Aktivieren**.
   (Oder die ZIP-Datei unter **Plugins → Installieren → Plugin hochladen** einspielen.)
2. Voraussetzungen: WordPress 6.6 oder neuer, PHP 8.1 oder neuer. Fast jeder aktuelle Hoster erfüllt das.
3. Nach der Aktivierung erscheint links im Menü **Bookora**.

> **Tipp:** Prüfen Sie unter **Einstellungen → Allgemein** (WordPress), ob die **Zeitzone** stimmt, z. B. „Berlin“. Alle Termine richten sich danach.

## 1a. Einrichtungsassistent

Beim ersten Öffnen von **Bookora → Übersicht** startet ein Assistent mit fünf Schritten: Betrieb, Öffnungszeiten, erste Leistung, Formularvorlage, fertig. Auf Wunsch legt er gleich eine **Seite mit dem Formular als Entwurf** an. Sie können ihn jederzeit überspringen und später über „Einrichtungsassistent erneut starten“ wieder aufrufen.

Danach zeigt die Übersicht die Termine von heute, offene Anfragen, neue Einträge, offene Gebühren und die nächsten Termine.

## 2. Grundeinstellungen (Bookora → Einstellungen)

| Bereich | Was Sie hier einstellen |
|---|---|
| **Allgemein** | Name des Betriebs, Telefon (erscheint, wenn Kunden kurzfristig nicht mehr online absagen können), E-Mail-Adresse für Benachrichtigungen, Zeitformat |
| **Buchungsregeln** | *Neue Buchungen sind* „bestätigt“ (sofort fest) oder „offen“ (Sie bestätigen jede Anfrage selbst). *Mindestvorlauf* (z. B. 120 Minuten = keine Buchung für die nächsten 2 Stunden). *Buchbar bis* (z. B. 90 Tage im Voraus) |
| **Zeitzone der Besucher** | Unter *Buchungsregeln*: Besucher aus einer anderen Zeitzone (z. B. Kunden im Ausland) sehen die Zeiten standardmäßig in Ihrer Ortszeit und können mit einem Klick auf ihre eigene Zeit umschalten. Für Online-Termine (Video, Telefon) können Sie „ihrer eigenen Zeit“ als Standard wählen. Die E-Mail an den Kunden nennt dann zusätzlich seine Ortszeit, z. B. „09:00 – 09:45 Uhr (03:00 – 03:45 Ihre Zeit, New York)“ |
| **Absagen & Verschieben durch Kunden** | Ob Kunden selbst absagen/verschieben dürfen, bis wann kostenlos, optional eine Ausfallgebühr (siehe Abschnitt 9) |
| **Design & eigener Code** | Hauptfarbe, Ecken, Abstände, Hell/Dunkel/Automatisch, Schrift – mit Live-Vorschau |
| **Sicherheit & Datenschutz** | Aufbewahrung, Löschen beim Deinstallieren |

## 3. Öffnungszeiten (Bookora → Öffnungszeiten)

- Tragen Sie je Wochentag Ihre Zeiten ein. Pausen: einfach zwei Zeiträume anlegen, z. B. 08:00–12:00 und 14:00–18:00.
- **Feiertage, Urlaub & Sonderöffnungszeiten:** Ein Zeitraum „Geschlossen“ sperrt alle Termine (z. B. Betriebsurlaub). „Sonderöffnungszeiten“ öffnen ausnahmsweise (z. B. ein Samstag).
- Arbeiten Sie allein? Dann reichen die allgemeinen Öffnungszeiten – Sie brauchen keine Mitarbeiter anzulegen.

## 4. Mitarbeiter & Ressourcen

Nur nötig, wenn mehrere Personen oder Räume parallel Termine haben.

1. **Bookora → Mitarbeiter & Ressourcen → Mitarbeiter / Ressource hinzufügen.**
2. Name und E-Mail eintragen. Eigene Arbeitszeiten sind möglich – sonst gelten die allgemeinen Öffnungszeiten.
3. Die kostenlose Version erlaubt 3 Mitarbeiter oder Ressourcen, Bookora Pro unbegrenzt viele.

## 5. Leistungen (Bookora → Leistungen)

Für jede Behandlung bzw. Dienstleistung:

- **Dauer** (z. B. 45 Minuten) und **Puffer** davor/danach (z. B. 15 Minuten zum Aufräumen)
- **Preis** (wird angezeigt und für eine prozentuale Ausfallgebühr verwendet)
- **Plätze** (mehr als 1 für Kurse, z. B. Yoga mit 8 Plätzen)
- **Max. pro Tag** (z. B. höchstens 2 Massagen am Tag)
- **Mitarbeiter**, die diese Leistung anbieten; optional „Beliebiger Mitarbeiter“ anbieten
- Abweichende Absage-Regeln oder Erinnerungen nur für diese Leistung (z. B. keine Ausfallgebühr für die Erstberatung)

## 6. Buchungsformular erstellen (Bookora → Formulare)

1. **Neues Formular** → Vorlage wählen:
   - *Mit Terminbuchung:* Einfache Terminbuchung, Friseur & Kosmetik, Arztpraxis & Therapie (zweistufig, Versicherung, Überweisung hochladen, Einwilligung für Gesundheitsdaten), Beratung & Coaching, Werkstatt, Kurs & Gruppe
   - *Ohne Termin:* Kontaktformular, Angebotsanfrage (mit Foto-Upload), Rückrufbitte, Feedback

   Jede Vorlage ist nur ein Startpunkt – alle Felder lassen sich ändern.
2. Weitere Felder links anklicken oder auf die Fläche ziehen: Telefon, Nachricht, Auswahl, Datum, **Datei-Upload** (z. B. Überweisung, Fotos), **Unterschrift** u. v. m.
3. Feld anklicken (oder mit der Tastatur auf „bearbeiten“) → rechts Beschriftung, Pflichtfeld, Breite, Bedingungen.
4. Reihenfolge: mit der Maus ziehen oder per Tastatur – Griff fokussieren, Leertaste, Pfeiltasten, Leertaste.
5. **Speichern** und Status auf **Veröffentlicht** stellen.

### Formular auf der Webseite einbinden

- **Block-Editor:** Block **„Bookora“** einfügen und Formular wählen.
- **Andere Editoren (Elementor, Divi, klassisch):** Shortcode einfügen, z. B. `[bookora id="3"]`. Den passenden Shortcode zeigt die Formularliste (**Shortcode kopieren**).

Für reine Anfragen ohne Termin gibt es das **Kontaktformular** – die Einträge landen unter **Bookora → Einträge**.

## 7. Buchungen bearbeiten

### Bookora → Buchungen

- Reiter **Offen**, **Anstehend**, **Vergangen**, **Geschlossen** und (falls benutzt) **Offene Gebühren**.
- Buchung öffnen → **Bestätigen**, **Ablehnen**, **Termin stornieren**, **Erschienen** / **Nicht erschienen**, interne Notizen, hochgeladene Dateien und Unterschrift.
- Bei offenen Anfragen bekommen Sie eine E-Mail mit **Ein-Klick-Bestätigen/Ablehnen** – Sie müssen sich dafür nicht anmelden.

### Bookora → Zeitstrahl

- Tages- und Wochenansicht, eine Zeile je Mitarbeiter.
- Termin mit der Maus verschieben (15-Minuten-Raster, auch auf einen anderen Mitarbeiter) oder Termin öffnen und neue Zeit wählen. Der Kunde bekommt auf Wunsch automatisch eine E-Mail mit der neuen Zeit.

### Export

**Bookora → Einträge** bzw. Formularliste → **CSV exportieren**. Die Datei öffnet sich direkt in Excel (Umlaute korrekt, Semikolon als Trenner).

## 8. E-Mails und Erinnerungen (Bookora → E-Mails)

- Vorlagen für Bestätigung, Ablehnung, Absage, Verschiebung, Erinnerung und die Benachrichtigungen an Sie.
- Platzhalter wie `{name}`, `{date}`, `{time}`, `{local_time}` (Ortszeit des Kunden, nur wenn abweichend), `{service}`, `{manage_button}` (Knopf „Termin verwalten“) – rechts mit Live-Vorschau und **Test-E-Mail**.
- **Erinnerungen vor dem Termin:** empfohlen **7 Tage und 24 Stunden** vorher. Eine Erinnerung entfällt, wenn der Termin erst kurz vorher gebucht wurde. Der Platzhalter `{time_until}` schreibt automatisch „in 7 Tagen“ oder „morgen“.

> **Wichtig für zuverlässige E-Mails:** Viele Hoster versenden WordPress-Mails nur eingeschränkt. Ein SMTP-Plugin (z. B. „WP Mail SMTP“) mit dem Postfach Ihrer Domain sorgt dafür, dass Bestätigungen nicht im Spam landen.

## 9. Kunden sagen selbst ab oder verschieben – mit optionaler Ausfallgebühr

Jede Buchungs-E-Mail enthält einen persönlichen Link **„Termin verwalten“**. Darüber kann der Kunde:

- **absagen** – bis zur Frist kostenlos (Standard: 24 Stunden vorher),
- **verschieben** – auf einen freien Termin desselben Mitarbeiters, ebenfalls bis zur Frist.

Sie werden jeweils per E-Mail informiert, der Termin wird sofort wieder frei.

**Ausfallgebühr (optional, standardmäßig aus):** unter **Einstellungen → Absagen & Verschieben durch Kunden → Ausfallgebühr verwenden**.

- Prozent vom Leistungspreis oder fester Betrag, wahlweise auch bei Nichterscheinen.
- Im Formular erscheint der Regeltext mit einer **Pflicht-Checkbox**. Zeitpunkt und Text der Zustimmung werden zur Buchung gespeichert.
- Nach Ablauf der Frist sieht der Kunde vor der Absage den genauen Betrag – oder Sie sperren die Online-Absage ganz („Bitte rufen Sie uns an“).
- **Nicht erschienen** markieren erfasst die Gebühr automatisch. Unter **Buchungen → Offene Gebühren**: **Als bezahlt markieren** oder **Gebühr erlassen**.
- Die kostenlose Version zieht kein Geld ein – die Rechnung stellen Sie selbst.

> **Rechtlicher Hinweis:** Ausfallhonorare sind in Deutschland nur mit vorheriger Vereinbarung durchsetzbar, bei Ärzten ist die Rechtslage umstritten. Die Checkbox schafft den Nachweis, ersetzt aber keine Rechtsberatung.

## 10. Termine aufs Handy (Bookora → Kalender-Sync)

**Termine abonnieren:** Für den ganzen Betrieb und für jeden Mitarbeiter gibt es eine eigene, geheime Kalenderadresse.

- **Link aufs Handy senden** → E-Mail auf dem Handy öffnen → Knopf antippen. iPhone, Android (Google Kalender) und Outlook übernehmen den Kalender.
- Oder am PC den **QR-Code** mit der Handy-Kamera scannen.
- Neue, verschobene und abgesagte Termine erscheinen automatisch. Google aktualisiert abonnierte Kalender alle paar Stunden, Apple und Outlook meist häufiger.
- Ist ein Link in falsche Hände geraten: **Adresse zurücksetzen** – der alte Link funktioniert sofort nicht mehr.

**Private Termine blockieren:** Fügen Sie die *private iCal-Adresse* Ihres Google-, iCloud- oder Outlook-Kalenders ein. Bookora prüft sie alle 15 Minuten und bietet Zeiten, in denen Sie schon etwas vorhaben, nicht mehr an. Gespeichert werden nur Beginn und Ende – nie Titel oder Details.

## 11. Datei-Upload und Unterschrift

- Erlaubt sind nur harmlose Dateitypen (Bilder, PDF, Office, Text, ZIP). Der echte Inhalt wird geprüft.
- Die Dateien liegen privat auf Ihrem Server und sind nur für angemeldete Benutzer mit Zugriff auf Buchungen sichtbar.
- Die maximale Dateigröße legt auch Ihr Hoster fest. Liegt Ihre Einstellung darüber, zeigt der Formular-Builder einen Hinweis.
- Unterschrift: mit Finger, Maus oder Stift – oder der Kunde tippt seinen Namen (für Tastatur und Screenreader).

## 12. Mitarbeiter-Zugänge

WordPress-Benutzern können Sie die Rolle **Booking Manager** (Buchungsverwaltung) geben: Sie sehen und bearbeiten Buchungen, aber keine Einstellungen.

## 13. Datenschutz (DSGVO)

- **Werkzeuge → Personenbezogene Daten exportieren / löschen** (WordPress) erfasst auch alle Bookora-Daten inklusive hochgeladener Dateien.
- Ein Textvorschlag für Ihre Datenschutzerklärung steht unter **Einstellungen → Datenschutz → Leitfaden für Datenschutzerklärung**.
- Bookora sendet keine Daten an Marinov Solutions und lädt keine externen Schriften oder Skripte.

## 14. Häufige Fragen

**Kunden sehen keine freien Termine.**
Prüfen Sie: Öffnungszeiten eingetragen? Leistung aktiv und einem Mitarbeiter mit Arbeitszeiten zugeordnet? Mindestvorlauf/„Buchbar bis“ zu streng? Ein verbundener privater Kalender blockiert den Tag?

**Es kommen keine E-Mails an.**
**E-Mails → Test-E-Mail** senden. Kommt nichts an, liegt es meist am Mailversand des Hosters – ein SMTP-Plugin löst das.

**Erinnerungen kommen zu spät oder gar nicht.**
WordPress führt geplante Aufgaben nur aus, wenn jemand die Webseite besucht. Bei wenig Besuch: beim Hoster einen echten Cronjob einrichten, der `wp-cron.php` alle 15 Minuten aufruft.

**Ein Termin wurde doppelt vergeben.**
Das verhindert Bookora auch bei gleichzeitigen Buchungen. Prüfen Sie, ob der Termin im Admin manuell auf eine belegte Zeit verschoben wurde (der Zeitstrahl warnt dabei).

**Wie groß darf meine Praxis sein?**
Getestet mit 20 Mitarbeitern und rund 95.000 Buchungen: Die Terminsuche für Kunden dauert etwa 0,1 Sekunden, die Buchungsliste im Admin etwa 0,06 Sekunden.

---

Fragen oder Wünsche: über mpmarinov.com/bookora.
