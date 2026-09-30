#!/usr/bin/env bash
#
# ScamSense — deploy the latest master to the Azure VM.
#
#     sudo bash /var/www/scamsense/deploy/azure/update.sh
#
# Pulls, builds, migrates, rebuilds the caches, restarts the services and
# runs the doctor. Also used by setup.sh for the first build (--no-pull).
# Use --no-pull after editing .env, to apply the change without fetching.

set -euo pipefail

APP_DIR="/var/www/scamsense"
SECRETS="/etc/scamsense/secrets.env"

step() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[31mSTOP: %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run with sudo."
[[ -f "$SECRETS" ]] || die "$SECRETS is missing. Run deploy/azure/setup.sh first."
# shellcheck disable=SC1090
source "$SECRETS"

OWNER="$(stat -c %U "$APP_DIR")"
as_owner() { sudo -u "$OWNER" -H "$@"; }
artisan() { sudo -u www-data php artisan "$@"; }

# Laravel writes to storage/ and bootstrap/cache as www-data; composer and
# git run as the owner. Both need to be able to write there.
fix_permissions() {
    chown -R "$OWNER:www-data" "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache"
    chmod -R ug+rwX "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache"
}

if [[ "${1:-}" != "--no-pull" ]]; then
    step "Pull master"
    as_owner git -C "$APP_DIR" pull --ff-only
fi
echo "Deploying $(as_owner git -C "$APP_DIR" log --oneline -1)"

fix_permissions

step "Laravel dependencies and assets"
cd "$APP_DIR/backend"
as_owner composer install --no-dev --optimize-autoloader --no-interaction --quiet
as_owner npm ci --no-audit --no-fund --loglevel=error
as_owner npm run build

step "ML service dependencies"
cd "$APP_DIR/ml-service"
[[ -x .venv/bin/python ]] || as_owner python3.11 -m venv .venv
as_owner .venv/bin/pip install --quiet --upgrade pip
as_owner .venv/bin/pip install --quiet -r requirements.txt

step "Migrate (as the migration user, then back to the app user)"
cd "$APP_DIR/backend"
fix_permissions
artisan config:clear
# Real environment variables win over .env, so this one command runs with
# schema rights; the cached configuration below goes back to scamsense_app.
sudo -u www-data env DB_USERNAME=scamsense_migrate DB_PASSWORD="$DB_MIGRATE_PASSWORD" \
    php artisan migrate --force --no-interaction

step "Configuration, route and view caches"
artisan config:cache
artisan route:cache
artisan view:cache
fix_permissions

step "Restart services"
# PHP-FPM restarts so OPcache and the Vite manifest are re-read
# (docs/deployment.md §6: a long-running PHP process serves stale assets).
systemctl restart php8.4-fpm
systemctl restart scamsense-ml
systemctl reload-or-restart caddy

step "Checks"
echo "Listening ports (5001 and 3306 must show 127.0.0.1 only):"
ss -ltn | awk 'NR==1 || /:(80|443|5001|3306) /'
for _ in $(seq 1 30); do
    curl -fs http://127.0.0.1:5001/health >/dev/null && break
    sleep 1
done
echo
echo "Doctor:"
artisan scamsense:doctor || echo "The doctor reported a problem. Read the lines above."
