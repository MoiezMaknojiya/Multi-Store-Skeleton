#!/usr/bin/env bash
#
# Re-issues both certificates on a chain that ends at ISRG Root X1 (deploy/README.md → "A visitor cannot
# open the site"). Run it as root:
#
#   ssh -i ~/.ssh/signage_deploy root@SERVER_IP "bash /root/signage-setup/use-compatible-chain.sh app.example.com"
#
# Why: Let's Encrypt now signs from new roots (ISRG Root YE / YR). A browser or operating system updated in
# the last few years knows them, but an antivirus or proxy that terminates TLS with its own elderly CA bundle
# does not — it cannot build a path to anything it trusts and drops the handshake, which the visitor sees as
# ERR_SSL_PROTOCOL_ERROR while every log on this server stays clean. Let's Encrypt still offers the older
# chain through ISRG Root X1, trusted almost everywhere since 2021, and --preferred-chain asks for it.
#
# Safe to run again, and safe to run while the site is serving: nginx only reloads once the new files and the
# configuration have both been checked.
set -euo pipefail

DOMAIN="${1:?Usage: use-compatible-chain.sh app.example.com}"
WANTED="ISRG Root X1"

[[ $EUID -eq 0 ]] || { echo "Run this as root." >&2; exit 1; }

chain_of() { openssl crl2pkcs7 -nocrl -certfile "$1" 2>/dev/null | openssl pkcs7 -print_certs -noout 2>/dev/null | grep '^issuer=' | tail -1; }

for name in "$DOMAIN" "${DOMAIN}-rsa"; do
    live="/etc/letsencrypt/live/$name"
    [[ -s "$live/fullchain.pem" ]] || { echo "   (no certificate called $name — skipped)"; continue; }

    type=$(openssl x509 -in "$live/cert.pem" -noout -text | grep -q 'id-ecPublicKey' && echo ecdsa || echo rsa)
    echo "== $name ($type)"
    echo "   before: $(chain_of "$live/fullchain.pem")"

    certbot certonly --nginx --non-interactive --agree-tos --cert-name "$name" \
        --key-type "$type" --preferred-chain "$WANTED" --force-renewal -d "$DOMAIN"

    echo "   after : $(chain_of "$live/fullchain.pem")"
done

nginx -t
systemctl reload nginx

echo
echo "== What the server now hands out"
for args in "-tls1_2 -cipher ECDHE-ECDSA-AES256-GCM-SHA384" "-tls1_2 -cipher ECDHE-RSA-AES256-GCM-SHA384"; do
    # shellcheck disable=SC2086
    top=$( { echo | openssl s_client -connect "$DOMAIN:443" -servername "$DOMAIN" -showcerts $args 2>/dev/null; } \
        | grep -E '^ [0-9]+ s:' | tail -1)
    echo "   ${args##*-cipher } ends at ${top#* s:}"
done
echo
echo "A chain ending at ISRG Root X1 is the one old antivirus and proxy bundles can follow."
