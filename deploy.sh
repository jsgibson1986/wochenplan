#!/usr/bin/env bash
# Update auf dem Server: ssh allinkl 'cd ~/www/htdocs/w021d878/wochenkalender-app && bash deploy.sh'
set -euo pipefail
cd "$(dirname "$0")"

if [ ! -f .env ]; then
    echo "Abbruch: .env fehlt." >&2
    exit 1
fi

if git remote get-url origin >/dev/null 2>&1; then
    git pull --ff-only
fi

# Mit Dev-Paketen: Die Tests laufen auf diesem Server (wie bei der
# Prüfungsformate-App). Dev-Pakete liegen außerhalb von public/.
composer install --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan filament:assets

# Bewusst KEIN config:cache/route:cache/event:cache (und kein `optimize`):
# Mit gecachter Konfiguration würden Tests die echte Datenbank verwenden,
# gecachte Routen machen neue Routen bis zum nächsten Deploy unsichtbar.
# Für eine App dieser Größe ist der Geschwindigkeitsgewinn vernachlässigbar.
php artisan optimize:clear
php artisan view:cache

# scp/tar aus Windows kann zu enge Rechte setzen (Stolperstein all-inkl).
find public -type d -exec chmod 755 {} \;
find public -type f -exec chmod 644 {} \;

echo "Deploy fertig."
