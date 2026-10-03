#!/usr/bin/env bash
# Incremental production deploy of deploy/aisi-release.tar.gz (4fdd202..HEAD).
# Run locally from the project root:  bash deploy/deploy.sh
# Follows docs/deployment-and-rollback.md: backup first, incremental
# migrations only (no migrate:fresh / seeding), cache rebuild, smoke check.
set -euo pipefail

HOST=aisi-server
ROOT=/home/aisi/htdocs/aisi.edu.ge
STAMP=$(date +%Y%m%d-%H%M%S)

scp deploy/aisi-release.tar.gz "$HOST:/tmp/aisi-release-$STAMP.tar.gz"

ssh "$HOST" bash -s <<EOF
set -euo pipefail
cd "$ROOT"

# 1. Backup: database + the files this release overwrites.
mkdir -p ../_backups
clpctl db:export --databaseName="\$(grep ^DB_DATABASE= .env | cut -d= -f2)" --file="../_backups/db-$STAMP.sql.gz"
tar -tzf /tmp/aisi-release-$STAMP.tar.gz | xargs -r tar -czf ../_backups/files-$STAMP.tar.gz --ignore-failed-read 2>/dev/null || true

# 2. Unpack and install dependencies (dompdf is new).
tar -xzf /tmp/aisi-release-$STAMP.tar.gz
rm -f public/robots.txt
# Removed in this release (light-only theme).
rm -f resources/js/components/appearance-tabs.tsx resources/js/pages/settings/appearance.tsx
composer install --no-dev --optimize-autoloader --no-interaction

# 3. Permissions, compiled views, incremental migrations, caches.
clpctl system:permissions:reset --directories=770 --files=660 --path=.
rm -f storage/framework/views/*.php
php artisan migrate --force
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
php artisan queue:restart || true

# 4. Smoke checks.
curl -s -o /dev/null -w "home %{http_code}\n" https://aisi.edu.ge/
curl -s -o /dev/null -w "robots %{http_code}\n" https://aisi.edu.ge/robots.txt
curl -sI https://aisi.edu.ge/login | grep -i -E "x-content-type-options|content-security-policy" || true
php artisan migrate:status | tail -6
EOF

echo "Done. Rollback: restore ../_backups/files-$STAMP.tar.gz and db-$STAMP.sql.gz (docs/deployment-and-rollback.md)."
echo "Still to set up on the server: cron 'schedule:run' every minute and a queue worker (see docs)."
