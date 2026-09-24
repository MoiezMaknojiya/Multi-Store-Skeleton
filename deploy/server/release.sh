#!/usr/bin/env bash
#
# Runs ON THE SERVER as the deploy account, uploaded and started by deploy/deploy.sh — not run by hand.
# Unpacks the release that was just uploaded, links it to the shared settings and storage, installs PHP's
# packages (never the development ones), migrates, builds the caches, and only then switches the site over
# to it. Until that switch the running release keeps serving, so a failure on the way changes nothing.
# The five newest releases stay, for a quick way back (deploy/README.md → Going back).
set -euo pipefail

STAMP="${1:?release stamp}"
FIRST="${2:-}"
APP=/var/www/signage
ENV_FILE="$APP/shared/.env"
RELEASE="$APP/releases/$STAMP"
ARCHIVE="$APP/releases/$STAMP.tar.gz"
PHP=8.3

# The upload and this script go whatever happens; a half-built release folder is left for a look.
trap 'rm -f "$ARCHIVE" "$APP/releases/$STAMP.sh"' EXIT
fail() { echo "$1" >&2; exit 1; }

[[ "$STAMP" =~ ^[0-9]{14}$ ]] || fail "Bad release stamp."
[[ -z "$FIRST" || "$FIRST" == --first ]] || fail "Unknown option: $FIRST"
[[ -f "$ARCHIVE" ]] || fail "The upload is missing: $ARCHIVE"
[[ -f "$ENV_FILE" ]] || fail "$ENV_FILE is missing: run setup.sh first."
[[ ! -e "$RELEASE" ]] || fail "$RELEASE already exists."

# The settings are checked by name only: a value is never printed.
unfilled="$( { grep -E '^[A-Z_]+=.*__FILL_IN__' "$ENV_FILE" || true; } | cut -d= -f1 | tr '\n' ' ')"
[[ -z "$unfilled" ]] || fail "Fill these in first (nano $ENV_FILE): $unfilled"
grep -qx 'APP_ENV=production' "$ENV_FILE" || fail "APP_ENV must be production in $ENV_FILE."
grep -qx 'APP_DEBUG=false' "$ENV_FILE" || fail "APP_DEBUG must be false in $ENV_FILE."
if [[ "$FIRST" == --first ]]; then
    # The seeder would otherwise print a made-up password into this deploy's output, or reset the admin's.
    [[ ! -e "$APP/current" ]] || fail "--first is for the very first deploy only: this server already has a release."
    grep -Eq '^SEED_ADMIN_EMAIL=[^[:space:]]+@[^[:space:]]+' "$ENV_FILE" || fail "SEED_ADMIN_EMAIL needs your email in $ENV_FILE."
    grep -Eq '^SEED_ADMIN_PASSWORD=.{12,}' "$ENV_FILE" || fail "SEED_ADMIN_PASSWORD needs at least 12 characters in $ENV_FILE."
fi

echo "   unpacking"
mkdir "$RELEASE"
tar -xzf "$ARCHIVE" -C "$RELEASE"
cd "$RELEASE"

# What outlives a release: the settings, the uploads (media, published ads, fonts) and the logs.
ln -s "$ENV_FILE" .env
rm -rf storage/app storage/logs
ln -s "$APP/shared/storage/app" storage/app
ln -s "$APP/shared/storage/logs" storage/logs

echo "   installing PHP packages (no development ones)"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress < /dev/null
php artisan storage:link < /dev/null

echo "   migrating the database"
php artisan migrate --force < /dev/null
if [[ "$FIRST" == --first ]]; then
    echo "   making the first super admin"
    php artisan db:seed --force < /dev/null
fi

echo "   building the caches"
php artisan optimize < /dev/null

echo "   switching the site over"
ln -sfn "$RELEASE" "$APP/current.next"
mv -Tf "$APP/current.next" "$APP/current"
sudo -n systemctl reload php$PHP-fpm

# Ad Builder pages an older compiler wrote are written again from the version on the screens (never a draft),
# so every published page carries what the compiler writes now — its security policy among it. After the
# switch, never before: a page names the runtime by its version, and the site must already serve that version.
# The site is up either way, so a failure here is reported rather than failing the deploy.
echo "   writing out-of-date Ad Builder pages again"
php artisan builder:recompile --outdated < /dev/null \
    || echo "   WARNING: builder:recompile failed; run it by hand: cd $APP/current && php artisan builder:recompile --outdated" >&2

# Five releases stay. rm never follows the storage links, so the shared uploads are never touched.
find "$APP/releases" -mindepth 1 -maxdepth 1 -type d -regextype posix-extended -regex '.*/[0-9]{14}' \
    | sort | head -n -5 | while read -r old; do rm -rf "$old"; done

if [[ "$FIRST" == --first ]]; then
    echo "   done — sign in, then empty SEED_ADMIN_PASSWORD in $ENV_FILE"
fi
