# Bookora Pro verkaufen – Einrichtung im Shop (mpmarinov.com)

Drei Teile:

| Teil | Ordner | Wo installiert |
|---|---|---|
| Bookora (kostenlos) | `bookora/` | wordpress.org, bei den Kunden |
| Bookora Pro (Add-on) | `bookora-pro/` | bei den Kunden, Download aus deinem Shop |
| Marinov Lizenzserver | `marinov-license-server/` | **nur auf mpmarinov.com** (WooCommerce) |

## 1. Lizenzserver installieren
1. ZIP von `marinov-license-server/` erstellen und unter *Plugins → Installieren → Plugin hochladen* einspielen, aktivieren.
2. Danach gibt es *WooCommerce → Lizenzen* und im Kundenkonto den Punkt „Lizenzen“.
3. *Einstellungen → Permalinks* einmal speichern (damit `/mein-konto/licenses/` funktioniert).

## 2. Produkte anlegen
Je Paket ein **virtuelles** Produkt (nicht „herunterladbar“ – der Download läuft über die Lizenz):

| Produkt | Preis | Websites | Gültigkeit (Monate) |
|---|---|---|---|
| Bookora Pro Starter | 39 € | 1 | 12 |
| Bookora Pro Business | 79 € | 5 | 12 |
| Bookora Pro Agentur | 149 € | 0 (= unbegrenzt) | 12 |

Im Produkt unter *Produktdaten → Allgemein*: **Lizenzschlüssel** anhaken, Software `bookora-pro`, Websites und Gültigkeit eintragen.
Steuerklasse für digitale Produkte wählen (EU-OSS, Steuersatz des Kundenlandes).

**Bestellungen automatisch abschließen:** Virtuelle Produkte werden nach Zahlung über Stripe/PayPal meist automatisch „Abgeschlossen“. Erst dann entsteht der Schlüssel und steht in der Bestell-E-Mail.

**Verlängerung:**
- *Ohne WooCommerce Subscriptions:* Der Kunde kauft nach einem Jahr erneut; du verlängerst den alten Schlüssel unter *WooCommerce → Lizenzen → +12 Monate* (oder er nutzt den neuen Schlüssel).
- *Mit WooCommerce Subscriptions* (kostenpflichtiges Plugin): Produkt als Abo anlegen. Der Schlüssel gilt bis zur nächsten Zahlung + 7 Tage, wird bei jeder Zahlung verlängert und endet nach Kündigung mit Ablauf der bezahlten Zeit.

Erstattung oder Stornierung der Bestellung sperrt den Schlüssel automatisch.

## 3. Neue Pro-Version veröffentlichen
1. ZIP von `bookora-pro/` erstellen (Ordnername im ZIP: `bookora-pro`), Version in `bookora-pro.php` erhöhen.
2. *WooCommerce → Lizenzen → Neue Version veröffentlichen*: Version, Änderungen, ZIP → **Veröffentlichen**.
3. Kunden-Websites prüfen zweimal täglich und zeigen das Update unter *Dashboard → Aktualisierungen*.
   Nur gültige, aktivierte Lizenzen erhalten einen Download-Link (1 Stunde gültig, signiert).

Die ZIPs liegen in `wp-content/uploads/mls-releases/` unter Zufallsnamen; der Ordner ist per `.htaccess`/`web.config` gesperrt.

## 4. Sicherheit
- Schlüssel: nur als Hash (Suche) und verschlüsselt (Anzeige im Kundenkonto) gespeichert.
- Nach 20 falschen Schlüsseln pro IP und Stunde wird gesperrt (Filter `marinov_license_max_fails`).
- Lokale und Staging-Seiten zählen nicht als Website (Adressen wie `localhost`, `*.local`, `*.test` oder beginnend mit `staging.`, `stage.`, `stag.`, `stg.`, `dev.`, `test.` – also auch `stg.mpmarinov.com`).
- Ein Ausfall des Lizenzservers schaltet bei Kunden nichts ab: Pro läuft weiter, nur Updates kommen später.

## 5. Vor dem Livegang testen (Checkliste)
Hier im Container getestet: Schlüssel, Aktivierung, Website-Grenze, Staging, Ablauf, Sperre, Updates,
signierter Download, Brute-Force-Schutz, Pro-Client und Webhooks (41 Prüfungen,
`php marinov-license-server/tests/integration.php`). **Nicht** getestet, weil WooCommerce hier nicht installierbar ist:

- [ ] Produktfelder speichern (Lizenzschlüssel, Websites, Monate)
- [ ] Testkauf mit Stripe im Testmodus → Bestellung „Abgeschlossen“ → Schlüssel in E-Mail und Dankeseite
- [ ] Kundenkonto → „Lizenzen“: Schlüssel sichtbar, Website deaktivieren
- [ ] Bestellung erstatten → Schlüssel gesperrt
- [ ] (falls Subscriptions) Verlängerung und Kündigung

Am besten auf einer Kopie des Shops (Staging deines Hosters) oder mit Stripe-Testmodus außerhalb der Geschäftszeiten.
