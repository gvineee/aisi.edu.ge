#!/usr/bin/env bash
# Incremental production deploy of deploy/aisi-release.tar.gz.
# Build it first (locally — the server has no Node.js):
#   npm run build
#   git diff --name-only --diff-filter=ACMR <deployed>..HEAD | grep -v '^docs/\|^tests/\|^deploy/' > deploy/files.txt
#   tar -czf deploy/aisi-release.tar.gz -T deploy/files.txt public/build
# Then run from the project root:  bash deploy/deploy.sh
# Follows docs/deployment-and-rollback.md: backup first, incremental
# migrations only (no migrate:fresh / seeding), cache rebuild, smoke check.
set -euo pipefail

HOST=aisi-server
ROOT=/home/aisi/htdocs/aisi.edu.ge
BACKUPS=/home/aisi/htdocs/_backups
STAMP=$(date +%Y%m%d-%H%M%S)

scp deploy/aisi-release.tar.gz "$HOST:/tmp/aisi-release-$STAMP.tar.gz"

ssh "$HOST" bash -s <<EOF
set -euo pipefail
cd "$ROOT"

# 1. Backup: database (clpctl needs an absolute --file path) + the files
#    this release overwrites + .env.
mkdir -p "$BACKUPS"
clpctl db:export --databaseName="\$(grep ^DB_DATABASE= .env | cut -d= -f2)" --file="$BACKUPS/db-$STAMP.sql.gz"
test -s "$BACKUPS/db-$STAMP.sql.gz"
tar -tzf /tmp/aisi-release-$STAMP.tar.gz | grep -v '/$' | xargs -r tar -czf "$BACKUPS/files-$STAMP.tar.gz" --ignore-failed-read 2>/dev/null || true
cp .env "$BACKUPS/env-$STAMP" && chmod 600 "$BACKUPS/env-$STAMP"

# 2. Maintenance window: unpack, autoload, migrate.
php artisan down --retry=30 || true
tar -xzf /tmp/aisi-release-$STAMP.tar.gz
composer install --no-dev --optimize-autoloader --no-interaction
rm -f storage/framework/views/*.php
PENDING=\$(php artisan migrate:status --pending 2>/dev/null | grep -c Pending || true)
php artisan migrate --force

# 3. Caches, workers, back up.
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
php artisan view:cache
php artisan queue:restart || true
php artisan up

# 4. Smoke checks.
curl -s -o /dev/null -w "home %{http_code}\n" https://aisi.edu.ge/
curl -s -o /dev/null -w "login %{http_code}\n" https://aisi.edu.ge/login
curl -s -o /dev/null -w "finance (guest) %{http_code}\n" https://aisi.edu.ge/portal/finance
curl -s -o /dev/null -w "documents (guest) %{http_code}\n" https://aisi.edu.ge/documents
php artisan migrate:status | tail -3
php artisan schedule:list | grep -E "finance|appointments|visit|library|documents" || true
tail -n 5 storage/logs/laravel.log 2>/dev/null | grep -i -E "error|exception" || echo "no new errors in log tail"
echo "Migrations run by this deploy: \$PENDING (schema-only rollback: php artisan migrate:rollback --step=\$PENDING)"
EOF

echo "Done. Rollback: php artisan down; restore $BACKUPS/files-$STAMP.tar.gz and db-$STAMP.sql.gz (clpctl db:import); php artisan migrate:rollback --step=<count printed above> if only schema; cache rebuild; php artisan up (docs/deployment-and-rollback.md)."
