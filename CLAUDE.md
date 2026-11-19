# Projektkontext: Wochenplan (Kalender für die Stundenplanung)

## Ziel
Web-App für das Stundenplanteam: pro Kalenderwoche alle relevanten Termine aus mehreren Quellen übersichtlich anzeigen (Bildschirm, Handy, Druck A4 quer). Keine A/B-Wochen.

## Stack & Ort
- Laravel 13 + Filament 5 + Livewire 4 + SQLite, `afsakar/filament-otp-login` (passwortloser Login per Mail-Code), `sabre/vobject` (iCal parsen, Serien auflösen).
- **Code liegt nur auf dem Server** (all-inkl, SSH-Host `allinkl`), entwickelt wird per SSH wie bei der Prüfungsformate-App:
  `~/www/htdocs/w021d878/wochenkalender-app` (Git-Repo `main`, noch **ohne GitHub-Remote**).
- Dieser lokale Ordner enthält nur diese Datei. Arbeitsweise: Dateien lokal im Scratchpad schreiben, per `tar | ssh` oder `scp` hochladen, danach `chmod 644/755` (Stolperstein: Windows-Uploads setzen teils zu enge Rechte).
- Updates: `ssh allinkl 'cd ~/www/htdocs/w021d878/wochenkalender-app && bash deploy.sh'` (composer, migrate, filament:assets, optimize).
- Tests: `php artisan test` auf dem Server (In-Memory-SQLite, `Http::fake()`, `MAIL_MAILER=array`).

## Datenquellen (Tabelle `quellen`, gepflegt unter /quellen, nur Admins)
| Quelle | Typ | Hinweis |
|---|---|---|
| Fortbildungen | `caldav` | `https://caldav.ssl-brn.de/caldav/1878`, Open-Xchange, Basic-Auth. Zugangsdaten trägt der Admin in der Oberfläche ein (Passwort verschlüsselt per `encrypted`-Cast). |
| Schulmanager | `ical` | Abo-Link (wirkt wie ein Passwort, nur in der DB, nie im Repo) |
| Prüfungstermine | `ical` | Feed der Prüfungsformate-App: `/kalender/feed/{ICS_FEED_TOKEN}.ics` (Token in deren `.env`) |
| Ferien / Feiertage | `ferien` / `feiertage` | OpenHolidays API, `DE-BY`. Nur landesweite Tage (lokales Friedensfest Augsburg raus) |

- Sync: `App\Services\Kalender\KalenderSync`. Zeitfenster heute −3 bis +15 Monate. Pro Quelle werden alte Termine nur bei Erfolg ersetzt, sonst `letzter_fehler`.
- Auslöser: `php artisan kalender:sync`, Button „Jetzt aktualisieren“ (Admin), Route `/system/sync?token=SYNC_TOKEN` (für KAS-HTTP-Cronjob, alle 15 Min.; falscher Token → 404).
- `termine` speichert **aufgelöste Einzelvorkommen** in lokaler Zeit Europe/Berlin; ganztägig: `ende` exklusiv (Folgetag 00:00).

## Oberfläche
- Startseite `/` = `App\Filament\Pages\Wochenansicht` (Livewire, `?woche=2026-W47`), Logik in `App\Services\Kalender\Woche` (Balken packen, Ferienmarkierung, Suche, ausgeblendete Quellen).
- Gemeinsames Raster für Bildschirm und Druck: `resources/views/kalender/raster.blade.php`; Druck: `/druck?von=…&bis=…` (`kalender/druck.blade.php`).
- CSS ohne Build: `public/css/wochenplan.css` (über Render-Hook eingebunden). Filaments vorkompiliertes Tailwind kennt eigene Klassen nicht, deshalb kein Tailwind in eigenen Views.
- Ferien/Feiertage färben Tagesspalten. Gleichnamige Ganztagstermine aus dem Schulmanager (z. B. „Weihnachtsferien“) werden dann nicht zusätzlich als Balken gezeigt.
- Nutzer-Einstellungen (ausgeblendete Quellen, Wochenende) in `users.einstellungen` (JSON).
- Rollen: `users.ist_admin` (Quellen/Benutzer verwalten, Policies `QuellePolicy`/`UserPolicy`), sonst nur Lesen. Inaktive Nutzer (`aktiv=false`) kommen nicht rein.

## Übernommen aus der Prüfungsformate-App (`~/www/htdocs/w021d878/leistungsnachweise-app`)
- `App\Filament\Auth\Login` (OTP-Fixes: `public $step`, robuste Code-Eingabe, Drosselung von `verifyCode`), deutsche Übersetzungen, Login-View, `OtpLoginTest`.
- `SicherheitsHeader`-Middleware + `<FilesMatch>`-Header-Block in `public/.htaccess`.
- Mail-Konfiguration (SMTP `mail.ssl-brn.de:587`) aus deren `.env`.
- Dort ergänzt (19.11.2026, **noch nicht committet**, wartet auf Freigabe): `Termin::toVevent()`, Feed-Route in `routes/web.php`, `services.ics_feed.token`, `tests/Feature/IcsFeedTest.php`. 121 Tests grün.

## Stolpersteine
- Das Projekt muss physisch unter `www/htdocs/w021d878/` liegen, Domain-Webspace in KAS auf `/wochenkalender-app/public/` (Symlinks scheitern an der Chroot).
- Kein SSH-Port-Forwarding bei all-inkl, also kein lokaler Browser-Test über einen Tunnel.
- Quoting über `ssh '…'` mit PHP-Code ist fehleranfällig. Besser kleine PHP-Skripte hochladen, mit `< /dev/null` ausführen und danach löschen. Geheimnisse per STDIN übergeben.
- `sabre/vobject` `expand()` braucht UIDs bei Serien (Parser vergibt Ersatz-UID) und `Settings::$maxRecurrences` hochgesetzt (alte Tagesserien).
- Model-Defaults (`$attributes`) für `aktiv`/`ist_admin` setzen. Sonst sind frisch erzeugte User-Instanzen vor dem Neuladen „inaktiv“ und bekommen 403.

## Offen (Stand 19.11.2026)
- Subdomain in KAS anlegen und auf `/wochenkalender-app/public/` zeigen lassen, SSL aktivieren. Danach `APP_URL` setzen und `bash deploy.sh` ausführen.
- Admin-Zugang anlegen: `php artisan wochenplan:einrichten --admin=<mail> --name="<Name>"`.
- CalDAV-Zugangsdaten für „Fortbildungen“ eintragen und die Quelle aktivieren.
- KAS-HTTP-Cronjob auf `https://<subdomain>/system/sync?token=<SYNC_TOKEN>` alle 15 Minuten.
- GitHub-Repo anlegen und als Remote verbinden.
- Feed-Änderung in der Prüfungsformate-App committen und pushen.
