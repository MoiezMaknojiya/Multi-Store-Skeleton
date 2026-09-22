#!/usr/bin/env bash
#
# Puts an RSA certificate beside the ECDSA one certbot issues by default, so the site opens for everybody
# (deploy/README.md → "A visitor cannot open the site"). Run it once, as root:
#
#   ssh -i ~/.ssh/signage_deploy root@SERVER_IP "bash /root/signage-setup/add-rsa-certificate.sh app.example.com"
#
# Why: an ECDSA certificate is smaller and faster, and every modern browser reads it — but a client that
# only knows RSA cannot complete the handshake at all, and Chrome shows ERR_SSL_PROTOCOL_ERROR. That client
# is rarely the visitor's browser: it is the antivirus, office proxy or ISP middlebox sitting in front of it,
# which terminates TLS itself. Those failures never reach this server's logs, so the site looks healthy while
# somebody stares at an error. Nginx can hold both kinds at once and hand each client the one it understands.
#
# Safe to run again: it notices a certificate that is already in place and only reloads.
set -euo pipefail

DOMAIN="${1:?Usage: add-rsa-certificate.sh app.example.com}"
SITE=/etc/nginx/sites-available/signage
LIVE="/etc/letsencrypt/live/${DOMAIN}-rsa"

[[ $EUID -eq 0 ]] || { echo "Run this as root." >&2; exit 1; }
[[ -f "$SITE" ]] || { echo "$SITE is missing: run setup.sh first." >&2; exit 1; }
grep -q 'managed by Certbot' "$SITE" || { echo "This site has no HTTPS yet: run certbot --nginx first." >&2; exit 1; }

echo "== 1/3 Asking Let's Encrypt for an RSA certificate"
certbot certonly --nginx --non-interactive --agree-tos --cert-name "${DOMAIN}-rsa" \
    --key-type rsa --rsa-key-size 2048 -d "$DOMAIN"
[[ -s "$LIVE/fullchain.pem" ]] || { echo "certbot wrote no certificate at $LIVE." >&2; exit 1; }

echo "== 2/3 Handing it to Nginx beside the ECDSA one"
if grep -q "${DOMAIN}-rsa/fullchain.pem" "$SITE"; then
    echo "   (it is already in the site)"
else
    cp "$SITE" "$SITE.before-rsa"
    # Straight after the certificate certbot manages, so both pairs sit in the same server block.
    awk -v cert="$LIVE/fullchain.pem" -v key="$LIVE/privkey.pem" '
        { print }
        /ssl_certificate_key .*privkey.pem;/ && !done {
            print "    ssl_certificate " cert "; # RSA, for a client that cannot read ECDSA"
            print "    ssl_certificate_key " key "; # RSA"
            done = 1
        }
    ' "$SITE" > "$SITE.new"
    mv "$SITE.new" "$SITE"
fi
nginx -t
systemctl reload nginx

echo "== 3/3 Proving both kinds of client get in"
rsa=$( { echo | openssl s_client -connect "$DOMAIN:443" -servername "$DOMAIN" -tls1_2 \
    -cipher 'ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256' 2>/dev/null; } | grep -c 'Cipher is ECDHE-RSA' || true)
ecdsa=$( { echo | openssl s_client -connect "$DOMAIN:443" -servername "$DOMAIN" -tls1_3 2>/dev/null; } | grep -c 'Cipher is TLS_' || true)

echo "   RSA-only client   : $([[ "$rsa" -gt 0 ]] && echo 'gets in' || echo 'STILL REFUSED')"
echo "   Modern client     : $([[ "$ecdsa" -gt 0 ]] && echo 'gets in' || echo 'STILL REFUSED')"
[[ "$rsa" -gt 0 && "$ecdsa" -gt 0 ]] || { echo "One of them is still refused — the old site is kept at $SITE.before-rsa." >&2; exit 1; }
echo "   Done. Both certificates renew themselves like the first one."
