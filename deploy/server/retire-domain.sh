#!/usr/bin/env bash
#
# Takes an address the panel has moved away from off this server (deploy/README.md → "Another address for the
# panel"): its Nginx site and its two certificates. Run it as root, yourself, once use-domain.sh has made
# another address the panel's own and the televisions have been paired again there:
#
#   scp -i ~/.ssh/signage_deploy deploy/server/retire-domain.sh root@SERVER_IP:/root/signage-setup/
#   ssh -i ~/.ssh/signage_deploy root@SERVER_IP "bash /root/signage-setup/retire-domain.sh app.old-example.com"
#
# The address that stays becomes the server's first site (/etc/nginx/sites-available/signage), where the other
# scripts of this folder look for it. The retired site is kept beside it as signage.retired-DOMAIN, switched off.
#
# With --check as a second word it changes nothing: it asks Nginx whether it would take the sites that stay.
#
# It refuses while the panel still calls itself by the address. If Nginx will not take what is left, everything
# goes back as it was and nothing is reloaded. The certificates are deleted last, once Nginx no longer reads
# them; Let's Encrypt issues new ones should the address ever come back (add-domain.sh).
set -euo pipefail

OLD="${1:?Usage: retire-domain.sh app.old-example.com [--check]}"
CHECK="${2:-}"
APP=/var/www/signage
AVAILABLE=/etc/nginx/sites-available
ENABLED=/etc/nginx/sites-enabled
MAIN="$AVAILABLE/signage"
RETIRED="$AVAILABLE/signage.retired-$OLD"

fail() { echo "$1" >&2; exit 1; }
artisan() { runuser -u deploy -- env HOME=/home/deploy bash -c "cd '$APP/current' && php artisan $*" < /dev/null; }
# Every name a site file answers to, one a line.
names() { grep -E '^[[:space:]]*server_name[[:space:]]' "$1" | tr -s ' ;' '\n' | grep -vxF -e server_name -e '' || true; }
# The file behind the switched-on site that answers to a name, or nothing.
site_of() {
    local link
    for link in "$ENABLED"/*; do
        if [[ -f "$link" ]] && names "$link" | grep -qxF "$1"; then
            readlink -f "$link"
            return
        fi
    done
}

[[ $EUID -eq 0 ]] || fail "Run this as root."
[[ -z "$CHECK" || "$CHECK" == --check ]] || fail "Unknown option: $CHECK"
[[ "$OLD" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || fail "\"$OLD\" does not look like a domain (app.example.com)."
[[ -f "$APP/current/artisan" ]] || fail "There is no release yet: nothing to ask what the panel calls itself."

own="$(artisan config:show app.url --no-ansi 2> /dev/null | grep -oE 'https?://[a-z0-9.-]+' | head -1 | sed -E 's|^https?://||' || true)"
[[ -n "$own" ]] || fail "The panel did not say what it calls itself (php artisan config:show app.url)."
[[ "$own" != "$OLD" ]] || fail "The panel still calls itself https://$OLD: give it the address that stays first (use-domain.sh)."

old_site="$(site_of "$OLD")"
stay="$(site_of "$own")"
[[ -n "$old_site" ]] || fail "No site of this server answers at $OLD: there is nothing to retire."
[[ -n "$stay" ]] || fail "No site of this server answers at $own, the address the panel calls itself: run add-domain.sh $own first."
[[ "$stay" != "$old_site" ]] || fail "One site file answers at both $OLD and $own: take $OLD out of its server_name by hand."
[[ ! -e "$RETIRED" ]] || fail "$RETIRED is already there: move it away first."

if [[ "$CHECK" == --check ]]; then
    echo "== Checking only: nothing on this server is changed"
    work="$(mktemp -d)"
    trap 'rm -rf "$work"' EXIT
    # A copy of Nginx's own folder made of links, with every switched-on site but the one to retire.
    for entry in /etc/nginx/*; do
        case "$(basename "$entry")" in
            nginx.conf | sites-enabled) ;;
            *) ln -s "$entry" "$work/" ;;
        esac
    done
    mkdir "$work/sites-enabled"
    for link in "$ENABLED"/*; do
        target="$(readlink -f "$link")"
        if [[ "$target" != "$old_site" ]]; then ln -s "$target" "$work/sites-enabled/$(basename "$link")"; fi
    done
    sed "s|/etc/nginx/sites-enabled/|$work/sites-enabled/|" /etc/nginx/nginx.conf > "$work/nginx.conf"
    grep -q "$work/sites-enabled/" "$work/nginx.conf" || fail "/etc/nginx/nginx.conf does not include sites-enabled the usual way: nothing was checked."
    nginx -t -c "$work/nginx.conf"
    echo "   Nginx would take the server without $OLD. The panel stays at https://$own."
    exit 0
fi

echo "== 1/3 Taking the site of $OLD out (the panel stays at https://$own)"
old_link=""
stay_link=""
for link in "$ENABLED"/*; do
    target="$(readlink -f "$link")"
    if [[ "$target" == "$old_site" ]]; then old_link="$link"; fi
    if [[ "$target" == "$stay" ]]; then stay_link="$link"; fi
done
put_back() {
    if [[ "$old_site" == "$MAIN" ]]; then
        rm -f "$ENABLED/signage"
        mv "$MAIN" "$stay"
        ln -sfn "$stay" "$stay_link"
    fi
    mv "$RETIRED" "$old_site"
    ln -sfn "$old_site" "$old_link"
}
mv "$old_site" "$RETIRED"
rm -f "$old_link"
if [[ "$old_site" == "$MAIN" ]]; then
    # The site that stays takes the first site's place, so the other scripts here go on finding it.
    mv "$stay" "$MAIN"
    rm -f "$stay_link"
    ln -sfn "$MAIN" "$ENABLED/signage"
fi
if ! checked="$(nginx -t 2>&1)"; then
    put_back
    echo "$checked" >&2
    fail "Nginx would not take the server without $OLD: everything is as it was, and nothing was reloaded."
fi
if [[ "$old_site" == "$MAIN" ]]; then
    sed -i "1s|^#.*|# The signage panel's site (deploy/README.md), at $own since retire-domain.sh took $OLD away.|" "$MAIN"
fi
systemctl reload nginx

echo "== 2/3 Its certificates"
for name in "$OLD" "$OLD-rsa"; do
    if [[ ! -d "/etc/letsencrypt/live/$name" ]]; then
        echo "   (no certificate called $name)"
    elif grep -Rqs "/etc/letsencrypt/live/$name/" "$ENABLED"/; then
        echo "   ($name is still read by a site here: kept)"
    else
        certbot delete --non-interactive --cert-name "$name"
    fi
done

echo "== 3/3 Asking the panel, on this server itself"
up="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 --resolve "$own:443:127.0.0.1" "https://$own/up" || true)"
left="$(nginx -T 2> /dev/null | grep -E '^[[:space:]]*server_name[[:space:]]' | tr -s ' ;' '\n' | grep -cxF "$OLD" || true)"
echo "   https://$own/up  $up"
echo "   sites still answering at $OLD: $left"
[[ "$up" == 200 ]] || fail "https://$own/up answered $up, not 200: the retired site is $RETIRED."
[[ "$left" == 0 ]] || fail "A site still answers at $OLD."

cat <<NEXT

Done. This server no longer answers at $OLD; the panel is at https://$own alone.
The retired site is kept, switched off, at $RETIRED.
Now its DNS record can go too: until then the name reaches this server and the browser warns about the certificate.
NEXT
