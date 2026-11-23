# Bookora

**Bookora – Termine & Buchungen** ist ein WordPress-Buchungssystem von **Marinov Solutions**.

| Ordner | Inhalt |
|---|---|
| `docs/PROJEKTPLAN.md` | Plan, Zeitplan, Free/Pro-Strategie, Fortschritt |
| `docs/DATENMODELL.md` | Datenbankstruktur und Sicherheitskonzept |
| `bookora/` | Das Plugin (Free-Version, Ordnername = Slug) |

## Entwicklung

```bash
cd bookora
composer install   # PHP-Werkzeuge (Tests, Coding Standards)
npm install        # Build-Werkzeuge für das Admin
npm run build      # Admin-Oberfläche bauen
composer test      # Unit-Tests
composer lint      # WordPress Coding Standards
npx wp-env start   # lokale WordPress-Testumgebung (Docker)
```
