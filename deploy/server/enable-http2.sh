#!/usr/bin/env bash
#
# Turns HTTP/2 on for the site's HTTPS server (deploy/README.md → "5. HTTPS"). Run it once, as root, after
# certbot has given the site HTTPS — from the project folder, in Git Bash:
#
#   scp -i ~/.ssh/signage_deploy deploy/server/enable-http2.sh root@SERVER_IP:/root/signage-setup/
#   ssh -i ~/.ssh/signage_deploy root@SERVER_IP "bash /root/signage-setup/enable-http2.sh app.example.com"
#
# Why: over HTTP/1.1 a browser opens at most six connections to one site, and a television fetching a big video
# for its offline cache, playing another and asking for its playlist every thirty seconds can use them all — a
# poll then waits behind a download. HTTP/2 carries every request over one connection. Every browser a
# television runs speaks it; one that does not keeps HTTP/1.1, which Nginx still answers.
#
# Safe to run again: it notices HTTP/2 already on and changes nothing. Before any change the site is copied
# beside itself; if Nginx will not take the new one, the copy goes back and nothing is reloaded.
set -euo pipefail

DOMAIN="${1:?Usage: enable-http2.sh app.example.com}"
SITE=/etc/nginx/sites-available/signage

[[ $EUID -eq 0 ]] || { echo "Run this as root." >&2; exit 1; }
[[ -f "$SITE" ]] || { echo "$SITE is missing: run setup.sh first." >&2; exit 1; }
grep -Eq 'listen[^;]*443 ssl' "$SITE" || { echo "This site has no HTTPS yet: run certbot --nginx first." >&2; exit 1; }

if grep -Eq 'listen[^;]*443 ssl[^;]*http2|^[[:space:]]*http2 on;' "$SITE"; then
    echo "== HTTP/2 is already on"
else
    echo "== Turning HTTP/2 on"
    cp "$SITE" "$SITE.before-http2"
    version="$(nginx -v 2>&1 | sed -E 's|.*nginx/([0-9]+\.[0-9]+\.[0-9]+).*|\1|')"

    if printf '%s\n%s\n' 1.25.1 "$version" | sort -V -C; then
        # Nginx 1.25.1 and later: a directive of its own, once, in the HTTPS server.
        awk '{ print } /listen[^;]*443 ssl/ && !done { print "    http2 on;"; done = 1 }' "$SITE" > "$SITE.new"
    else
        # Ubuntu 24.04's Nginx (1.24): a flag on each HTTPS listen line — certbot's "listen 443 ssl;" and
        # "listen [::]:443 ssl ipv6only=on;" become "... ssl http2;" and "... ssl http2 ipv6only=on;".
        sed -E 's/(listen[^;]*443 ssl)([ ;])/\1 http2\2/' "$SITE" > "$SITE.new"
    fi
    mv "$SITE.new" "$SITE"

    if ! checked="$(nginx -t 2>&1)"; then
        mv "$SITE.before-http2" "$SITE"
        echo "$checked" >&2
        echo "Nginx would not take HTTP/2 (Nginx $version): the site is as it was, and nothing was reloaded." >&2
        exit 1
    fi

    systemctl reload nginx
fi

echo "== Asking the site the way a television would"
got="$(curl -s -o /dev/null -w '%{http_version}' --http2 --max-time 20 "https://$DOMAIN/up" || true)"

if [[ "$got" != 2 ]]; then
    echo "https://$DOMAIN/up answers over HTTP/${got:-nothing}, not 2." >&2
    [[ -f "$SITE.before-http2" ]] && echo "The site as it was before is kept at $SITE.before-http2." >&2
    exit 1
fi

echo "   https://$DOMAIN answers over HTTP/2."
