#!/usr/bin/env bash
# Install ClamAV for upload scanning and switch the site to it.
# Needs root (the site user cannot install packages). From the project root:
#   ssh -t aisi-server 'sudo bash -s' < deploy/clamav-setup.sh
# What it does: installs clamav + clamav-daemon, fetches signatures, makes
# clamd listen on 127.0.0.1:3310 only, then — as the site's own user —
# backs up .env, sets UPLOADS_SCANNER=clamd, rebuilds the config cache,
# restarts the queue workers, proves detection with the harmless EICAR test
# string and scans the uploads stored while there was no scanner.
# clamd needs about 1–1.5 GB of RAM.
set -euo pipefail

APP=/home/aisi/htdocs/aisi.edu.ge
APP_USER=$(stat -c %U "$APP/artisan")
CONF=/etc/clamav/clamd.conf

echo "== 1. packages"
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq clamav clamav-daemon

echo "== 2. signatures"
systemctl stop clamav-freshclam 2>/dev/null || true
freshclam --quiet || echo "freshclam: will retry in the background"
systemctl enable --now clamav-freshclam

echo "== 3. clamd on localhost only"
set_opt() {
    if grep -q "^$1 " "$CONF"; then sed -i "s|^$1 .*|$1 $2|" "$CONF"; else echo "$1 $2" >> "$CONF"; fi
}
set_opt TCPSocket 3310
set_opt TCPAddr 127.0.0.1
set_opt StreamMaxLength 50M
systemctl enable clamav-daemon
systemctl restart clamav-daemon

printf "waiting for clamd to load signatures"
for _ in $(seq 1 100); do
    if timeout 3 bash -c 'exec 3<>/dev/tcp/127.0.0.1/3310; printf "zPING\0" >&3; head -c 4 <&3' 2>/dev/null | grep -q PONG; then
        echo " — ready"; break
    fi
    printf "."; sleep 3
done

echo "== 4. switch the site to clamd (as $APP_USER)"
sudo -u "$APP_USER" bash -s <<EOF
set -euo pipefail
cd "$APP"
cp .env ".env.before-clamav-\$(date +%Y%m%d-%H%M%S)"
chmod 600 .env.before-clamav-*
set_env() {
    if grep -q "^\$1=" .env; then sed -i "s|^\$1=.*|\$1=\$2|" .env; else echo "\$1=\$2" >> .env; fi
}
set_env UPLOADS_SCANNER clamd
set_env CLAMD_ADDRESS tcp://127.0.0.1:3310
php artisan config:cache
php artisan queue:restart
if ! php artisan uploads:scan-unscanned --check-only; then
    # Not working: back to the previous setting so uploads keep flowing.
    set_env UPLOADS_SCANNER null
    php artisan config:cache
    php artisan queue:restart
    echo "clamd check failed — site switched back to UPLOADS_SCANNER=null"
    exit 1
fi
php artisan uploads:scan-unscanned
EOF

echo "== done. Undo: set UPLOADS_SCANNER=null in $APP/.env, php artisan config:cache, php artisan queue:restart"
