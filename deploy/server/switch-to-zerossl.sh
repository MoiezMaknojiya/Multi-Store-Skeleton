#!/usr/bin/env bash
#
# Moves the site's certificates from Let's Encrypt to ZeroSSL (deploy/README.md → "A visitor cannot open
# the site"). Run it once, as root, with the EAB credentials from the ZeroSSL dashboard (Developer → "EAB
# Credentials for ACME Clients" → Generate) and the address the account should be registered under:
#
#   ssh -i ~/.ssh/signage_deploy root@SERVER_IP "bash /root/signage-setup/switch-to-zerossl.sh app.example.com KID HMAC you@example.com"
#
# Why: since January 2026 Let's Encrypt signs from its new "Generation Y" roots (ISRG Root YE / YR). A
# browser updated this year knows them; an antivirus or proxy that terminates TLS with its own elderly CA
# bundle does not, and drops the handshake — ERR_SSL_PROTOCOL_ERROR for the visitor, nothing in this
# server's logs. The sites that keep working through such software (Google, banks) use authorities whose
# roots have sat in every bundle for a decade. ZeroSSL chains to USERTrust (2010, valid to 2038): free,
# 90-day certificates over the same ACME protocol, so certbot's timer keeps renewing them like before.
#
# The Let's Encrypt certificates are left in place, unused, so going back is one edit of the site file.
set -euo pipefail

DOMAIN="${1:?Usage: switch-to-zerossl.sh app.example.com EAB_KID EAB_HMAC_KEY you@example.com}"
KID="${2:?EAB key ID is missing}"
HMAC="${3:?EAB HMAC key is missing}"
EMAIL="${4:?the account email is missing}"
SITE=/etc/nginx/sites-available/signage
ACME=https://acme.zerossl.com/v2/DV90

[[ $EUID -eq 0 ]] || { echo "Run this as root." >&2; exit 1; }
[[ -f "$SITE" ]] || { echo "$SITE is missing: run setup.sh first." >&2; exit 1; }
grep -q "live/${DOMAIN}/fullchain.pem" "$SITE" || { echo "The site does not use a Let's Encrypt certificate for $DOMAIN — nothing to switch." >&2; exit 1; }

echo "== 1/3 An ECDSA and an RSA certificate from ZeroSSL"
# The first request registers the account (EAB binds it to the ZeroSSL account); the second reuses it.
certbot certonly --nginx --non-interactive --agree-tos --email "$EMAIL" \
    --server "$ACME" --eab-kid "$KID" --eab-hmac-key "$HMAC" \
    --cert-name "${DOMAIN}-zerossl-ecdsa" --key-type ecdsa -d "$DOMAIN"
certbot certonly --nginx --non-interactive --agree-tos --email "$EMAIL" \
    --server "$ACME" \
    --cert-name "${DOMAIN}-zerossl-rsa" --key-type rsa --rsa-key-size 2048 -d "$DOMAIN"
for name in "${DOMAIN}-zerossl-ecdsa" "${DOMAIN}-zerossl-rsa"; do
    [[ -s "/etc/letsencrypt/live/$name/fullchain.pem" ]] || { echo "certbot wrote no certificate for $name." >&2; exit 1; }
done

echo "== 2/3 Pointing Nginx at them"
cp "$SITE" "$SITE.before-zerossl"
sed -i \
    -e "s|/etc/letsencrypt/live/${DOMAIN}/|/etc/letsencrypt/live/${DOMAIN}-zerossl-ecdsa/|g" \
    -e "s|/etc/letsencrypt/live/${DOMAIN}-rsa/|/etc/letsencrypt/live/${DOMAIN}-zerossl-rsa/|g" \
    "$SITE"
nginx -t
systemctl reload nginx

echo "== 3/3 The chains the server hands out now"
for cipher in ECDHE-ECDSA-AES256-GCM-SHA384 ECDHE-RSA-AES256-GCM-SHA384; do
    top=$( { echo | openssl s_client -connect "$DOMAIN:443" -servername "$DOMAIN" -tls1_2 -cipher "$cipher" -showcerts 2>/dev/null; } \
        | openssl crl2pkcs7 -nocrl -certfile /dev/stdin 2>/dev/null | openssl pkcs7 -print_certs -noout 2>/dev/null \
        | grep '^issuer=' | tail -1)
    echo "   ${cipher%%-AES*}: ${top:-'(no answer)'}"
done
echo
echo "USERTrust or AAA Certificate Services at the top means the old bundles can follow it."
echo "The old site file is kept at $SITE.before-zerossl; the Let's Encrypt certificates stay on disk unused."
