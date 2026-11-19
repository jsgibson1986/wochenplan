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

composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan filament:assets
php artisan optimize

# scp/tar aus Windows kann zu enge Rechte setzen (Stolperstein all-inkl).
find public -type d -exec chmod 755 {} \;
find public -type f -exec chmod 644 {} \;

echo "Deploy fertig."
