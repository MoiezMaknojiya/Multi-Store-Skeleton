#!/usr/bin/env bash
#
# Makes one address the panel's own (deploy/README.md → "Another address for the panel"): the links in its
# emails, the address of every picture, video and published ad, and — when a second word is given — the address
# its mail comes from. Run it as root, yourself, once add-domain.sh has put the address on HTTPS:
#
#   scp -i ~/.ssh/signage_deploy deploy/server/use-domain.sh root@SERVER_IP:/root/signage-setup/
#   ssh -i ~/.ssh/signage_deploy root@SERVER_IP "bash /root/signage-setup/use-domain.sh sign.example.com no-reply@example.com"
#
# In shared/.env it changes APP_URL (and MAIL_FROM_ADDRESS) — those lines alone, a copy of the file kept beside
# it, nothing of it printed — then rebuilds the settings cache, reloads PHP and writes every published Ad
# Builder page again, since a page names the panel's address in its own security policy. If the panel will not
# start with the new settings, the copy goes back.
#
# Two things it cannot do for you. A television knows the panel by the address it opened: each one opens
# https://DOMAIN/player and is paired again with "Replace Device", which keeps its playlist. And the mail
# address must be on a domain Brevo has authenticated, or the mail is refused: prove it afterwards with
# php artisan mail:test (deploy/README.md, step 6).
set -euo pipefail

DOMAIN="${1:?Usage: use-domain.sh sign.example.com [no-reply@example.com]}"
MAIL_FROM="${2:-}"
APP=/var/www/signage
ENV_FILE="$APP/shared/.env"
PHP=8.3

fail() { echo "$1" >&2; exit 1; }
# The panel's own commands run as the account the panel runs as, so nothing it writes ends up root's.
artisan() { runuser -u deploy -- env HOME=/home/deploy bash -c "cd '$APP/current' && php artisan $*" < /dev/null; }
ask() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN$1" || true; }

[[ $EUID -eq 0 ]] || fail "Run this as root."
[[ "$DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || fail "\"$DOMAIN\" does not look like a domain (sign.example.com)."
[[ -z "$MAIL_FROM" || "$MAIL_FROM" =~ ^[a-z0-9._+-]+@[a-z0-9.-]+\.[a-z]{2,}$ ]] || fail "\"$MAIL_FROM\" does not look like an email address (no-reply@example.com)."
[[ -f "$ENV_FILE" ]] || fail "$ENV_FILE is missing: run setup.sh first."
[[ -f "$APP/current/artisan" ]] || fail "There is no release yet: deploy first (deploy/README.md, step 6)."
# The settings are checked by name only: a value is never printed.
grep -q '^APP_URL=' "$ENV_FILE" || fail "$ENV_FILE has no APP_URL line."
[[ -z "$MAIL_FROM" ]] || grep -q '^MAIL_FROM_ADDRESS=' "$ENV_FILE" || fail "$ENV_FILE has no MAIL_FROM_ADDRESS line."

echo "== 1/4 Does https://$DOMAIN reach the panel"
up="$(ask /up)"
[[ "$up" == 200 ]] || fail "https://$DOMAIN/up answered $up on this server: run add-domain.sh $DOMAIN first."
echo "   yes"

echo "== 2/4 The settings"
copy="$ENV_FILE.before-$(date -u +%Y%m%d%H%M%S)"
cp -p "$ENV_FILE" "$copy"
chmod 600 "$copy"
put_back() {
    cp -p "$copy" "$ENV_FILE"
    artisan optimize > /dev/null 2>&1 || true
    systemctl reload "php$PHP-fpm" || true
}
sed -i "s|^APP_URL=.*|APP_URL=https://$DOMAIN|" "$ENV_FILE"
if [[ -n "$MAIL_FROM" ]]; then
    sed -i "s|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=$MAIL_FROM|" "$ENV_FILE"
fi
chown deploy:deploy "$ENV_FILE"
chmod 600 "$ENV_FILE"
echo "   APP_URL is https://$DOMAIN${MAIL_FROM:+, and the mail comes from $MAIL_FROM} (the file as it was: $copy)"

echo "== 3/4 The settings cache and PHP"
if ! built="$(artisan optimize 2>&1)"; then
    put_back
    echo "$built" >&2
    fail "The panel would not start with the new settings: they are as they were."
fi
systemctl reload "php$PHP-fpm"
seen="$(artisan config:show app.url --no-ansi 2> /dev/null || true)"
if [[ "$seen" != *"https://$DOMAIN"* ]]; then
    put_back
    fail "The panel does not call itself https://$DOMAIN after the change: the settings are as they were."
fi
if [[ -n "$MAIL_FROM" ]]; then
    seen="$(artisan config:show mail.from.address --no-ansi 2> /dev/null || true)"
    if [[ "$seen" != *"$MAIL_FROM"* ]]; then
        put_back
        fail "The panel's mail does not come from $MAIL_FROM after the change: the settings are as they were."
    fi
fi
echo "   the panel calls itself https://$DOMAIN"

echo "== 4/4 The published Ad Builder pages, and the panel itself"
artisan builder:recompile \
    || echo "   WARNING: builder:recompile failed; run it by hand: cd $APP/current && php artisan builder:recompile" >&2
failed=0
for path in /up /login; do
    code="$(ask "$path")"
    echo "   https://$DOMAIN$path  $code"
    [[ "$code" == 200 ]] || failed=1
done
[[ $failed -eq 0 ]] || fail "The panel does not answer as it should: look at $APP/shared/storage/logs."

cat <<NEXT

Done. The panel's own links and files name https://$DOMAIN now.
  1. Each television: open https://$DOMAIN/player on it, then pair it from the Screens page with
     "Replace Device" — it keeps its playlist.
  2. Prove the mail: ssh … deploy@SERVER_IP "cd $APP/current && php artisan mail:test you@example.com"
  3. Every deploy from now on: bash deploy/deploy.sh deploy@SERVER_IP https://$DOMAIN
The address the panel had before still answers until it is taken away (retire-domain.sh).
NEXT
