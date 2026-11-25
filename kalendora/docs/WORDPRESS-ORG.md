# Kalendora bei wordpress.org einreichen – Checkliste

Alles Technische ist vorbereitet. Die folgenden Schritte laufen über **dein** wordpress.org-Konto.

## Fertige Dateien

| Datei | Zweck |
|---|---|
| `dist/kalendora-1.0.0.zip` | Das Plugin zum Einreichen (583 KB, nur Laufzeitdateien + Quellcode, keine Tests) |
| `wporg-assets/icon-256x256.png`, `icon-128x128.png`, `icon.svg` | Plugin-Symbol |
| `wporg-assets/banner-1544x500.png`, `banner-772x250.png` | Kopfbild der Plugin-Seite |
| `wporg-assets/screenshot-1.png` … `screenshot-8.png` | Screenshots (Englisch, Beschriftungen stehen in der `readme.txt`) |
| `dist/kalendora-pro-1.0.0.zip` | Pro-Add-on – **nicht** bei wordpress.org, sondern in deinem Shop (Lizenzserver) |
| `dist/marinov-license-server-1.0.0.zip` | Nur für mpmarinov.com |

## Vor dem Einreichen (du)

1. **Konto:** auf wordpress.org anmelden bzw. registrieren. Den Benutzernamen in `kalendora/readme.txt` bei
   `Contributors:` eintragen (Platzhalter ist `marinovsolutions`) und ein neues ZIP bauen (`npm run plugin-zip`) – oder mir den Namen sagen.
2. **Name prüfen:** https://wordpress.org/plugins/search/kalendora/ – der Slug `kalendora` muss frei sein.
   Kurz auch beim DPMA/EUIPO nach „Kalendora“ suchen (Markenrecht).
3. **Plugin Check** (offizielles Werkzeug von WordPress.org) auf einer Test-WordPress-Seite oder lokal:
   *Plugins → Installieren → „Plugin Check“ → aktivieren → Werkzeuge → Plugin Check → Kalendora prüfen.*
   In meiner Umgebung durfte ich es nicht installieren (es führt Fremdcode aus). Ersatzweise geprüft:
   WordPress Coding Standards (0 Fehler), PHP-Kompatibilität, keine Debug-Ausgaben, keine verbotenen
   Funktionen, alle externen Adressen in der readme offengelegt, saubere Deinstallation.

## Einreichen

1. https://wordpress.org/plugins/developers/add/ → ZIP hochladen, Bestätigungen anhaken.
2. Wartezeit: meist **1–6 Wochen**. Das Review-Team antwortet per E-Mail; Rückfragen bitte an mich weitergeben,
   ich passe den Code an.
3. Nach der Freigabe bekommst du SVN-Zugang. Aufbau:
   ```
   trunk/            ← Inhalt von dist/kalendora-1.0.0.zip (Ordner kalendora/ entpackt)
   tags/1.0.0/       ← gleiche Dateien
   assets/           ← alle Dateien aus wporg-assets/ (Symbol, Banner, Screenshots)
   ```
   Das Hochladen per SVN kann ich vorbereiten (Befehle), ausführen musst du es mit deinem Zugang.

## Mögliche Rückfrage der Prüfer

**Grenze von 3 Mitarbeitern in der kostenlosen Version.** Regel 5 der Plugin-Richtlinien verbietet
„gesperrte“ Funktionen, die nur gegen Bezahlung freigeschaltet werden. Mengenbegrenzungen werden in der
Praxis meist akzeptiert (vergleichbare Buchungs-Plugins arbeiten so), die Prüfer entscheiden aber im
Einzelfall. Die readme beschreibt die Grenze jetzt offen. Falls das Team sie beanstandet, gibt es zwei Wege:
- Grenze in Free entfernen und Pro über andere Funktionen verkaufen (Webhooks, später Zahlung, SMS …), oder
- die Mitarbeiterverwaltung über 3 hinaus vollständig ins Pro-Add-on verlagern (Code nur dort).
Das entscheidest dann du – ich setze beides in kurzer Zeit um.

## Danach

- Changelog und `Stable tag` bei jeder Version erhöhen, `Tested up to` bei neuen WordPress-Versionen anpassen.
- Übersetzungen: wordpress.org übernimmt Deutsch/Bulgarisch auf translate.wordpress.org, wenn du dich dort
  als Übersetzer einträgst; die mitgelieferten Sprachdateien funktionieren bis dahin.
