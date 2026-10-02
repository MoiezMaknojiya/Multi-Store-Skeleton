#!/usr/bin/env bash
#
# Serves the panel at one more address, over HTTPS (deploy/README.md → "Another address for the panel").
# Run it as root, yourself — it changes Nginx's settings — from the project folder, in Git Bash:
#
#   scp -i ~/.ssh/signage_deploy deploy/server/add-domain.sh deploy/server/nginx-site.conf root@SERVER_IP:/root/signage-setup/
#   ssh -i ~/.ssh/signage_deploy root@SERVER_IP "bash /root/signage-setup/add-domain.sh sign.example.com"
#
# The address the panel already answers at is left exactly as it is: its site file is never touched. The new one
# gets a site file of its own (/etc/nginx/sites-available/signage-DOMAIN) written from nginx-site.conf, both kinds
# of certificate on the chain old devices can follow (add-rsa-certificate.sh and use-compatible-chain.sh say
# why), the TLS settings and HTTP/2 the first site carries, and plain HTTP sent on to HTTPS.
#
# With --check as a second word it changes nothing: it writes the site into a temporary folder and asks Nginx
# whether it would take it beside the sites that are there.
#
# Safe to run again: a certificate that is in place is kept and the site is written afresh. If Nginx will not
# take the new site, it is taken out again and nothing is reloaded.
set -euo pipefail

DOMAIN="${1:?Usage: add-domain.sh sign.example.com [--check]}"
CHECK="${2:-}"
HERE="$(cd "$(dirname "$0")" && pwd)"
MAIN=/etc/nginx/sites-available/signage
SITE="/etc/nginx/sites-available/signage-$DOMAIN"
LINK="/etc/nginx/sites-enabled/signage-$DOMAIN"
LETSENCRYPT=/etc/letsencrypt/live

fail() { echo "$1" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "Run this as root."
[[ -z "$CHECK" || "$CHECK" == --check ]] || fail "Unknown option: $CHECK"
[[ "$DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || fail "\"$DOMAIN\" does not look like a domain (sign.example.com)."
[[ -f "$HERE/nginx-site.conf" ]] || fail "nginx-site.conf is missing next to add-domain.sh: copy it too."
[[ -f "$MAIN" ]] || fail "$MAIN is missing: run setup.sh first."
grep -Eq 'listen[^;]*443 ssl' "$MAIN" || fail "The first site has no HTTPS yet: run certbot --nginx first (deploy/README.md, step 5)."
if grep -E '^[[:space:]]*server_name[[:space:]]' "$MAIN" | tr -s ' ;' '\n' | grep -qxF "$DOMAIN"; then
    fail "$DOMAIN is the address the first site already answers at."
fi

# What the first site's HTTPS server carries besides its certificates — the curves, the settings file, the DH
# parameters, HTTP/2 and IPv6 — so the new address answers every client the first one does.
tls_settings="$(grep -E '^[[:space:]]*(ssl_ecdh_curve|ssl_dhparam|include[[:space:]][^;]*ssl[^;]*\.conf)' "$MAIN" \
    | sed -E 's/;[[:space:]]*#.*$/;/' | awk '!seen[$0]++' || true)"
[[ -n "$tls_settings" ]] || fail "The first site carries no TLS settings to copy (an ssl include or ssl_dhparam line)."
listen_flags=ssl
http2_line=""
if grep -Eq 'listen[^;]*443 ssl[^;]*http2' "$MAIN"; then
    listen_flags="ssl http2"
elif grep -Eq '^[[:space:]]*http2 on;' "$MAIN"; then
    http2_line="    http2 on;"
fi
ipv6=no
if grep -Eq 'listen \[::\]:443' "$MAIN"; then ipv6=yes; fi

# The whole site: nginx-site.conf's server (its plain-HTTP listen lines aside) on HTTPS with the two
# certificates given, then a second server that sends plain HTTP there.
write_site() {
    local ecdsa="$1" rsa="$2" out="$3"
    {
        echo "# The panel at one more address, $DOMAIN (deploy/server/add-domain.sh). The first address keeps its own file."
        sed -e "s|__DOMAIN__|$DOMAIN|g" -e '/^[[:space:]]*listen /d' "$HERE/nginx-site.conf" \
            | sed -n '/^server {/,$p' \
            | awk '{ lines[NR] = $0 } /^}[[:space:]]*$/ { last = NR } END { for (i = 1; i < last; i++) print lines[i] }'
        echo
        if [[ "$ipv6" == yes ]]; then echo "    listen [::]:443 $listen_flags;"; fi
        echo "    listen 443 $listen_flags;"
        if [[ -n "$http2_line" ]]; then echo "$http2_line"; fi
        echo "    ssl_certificate $ecdsa/fullchain.pem;"
        echo "    ssl_certificate_key $ecdsa/privkey.pem;"
        if [[ "$rsa" != "$ecdsa" ]]; then
            echo "    ssl_certificate $rsa/fullchain.pem; # RSA, for a client that cannot read ECDSA"
            echo "    ssl_certificate_key $rsa/privkey.pem; # RSA"
        fi
        echo "$tls_settings"
        echo "}"
        echo
        echo "server {"
        echo "    listen 80;"
        echo "    listen [::]:80;"
        echo "    server_name $DOMAIN;"
        echo
        echo "    location / {"
        echo "        return 301 https://\$host\$request_uri;"
        echo "    }"
        echo "}"
    } > "$out"
}

if [[ "$CHECK" == --check ]]; then
    echo "== Checking only: nothing on this server is changed"
    if [[ -e "$LINK" ]]; then
        nginx -t
        echo "   $DOMAIN is already one of this server's sites: Nginx takes what is there."
        exit 0
    fi
    # The first site's own certificates stand in for the ones Let's Encrypt has yet to issue.
    mapfile -t stand_in < <(grep -E '^[[:space:]]*ssl_certificate[[:space:]]' "$MAIN" \
        | sed -E 's|^[[:space:]]*ssl_certificate[[:space:]]+([^;]+)/fullchain\.pem;.*|\1|')
    [[ ${#stand_in[@]} -gt 0 ]] || fail "The first site names no certificate."
    work="$(mktemp -d)"
    trap 'rm -rf "$work"' EXIT
    # A copy of Nginx's own folder made of links, so every include it names is found, with one site more.
    for entry in /etc/nginx/*; do
        [[ "$(basename "$entry")" == nginx.conf ]] || ln -s "$entry" "$work/"
    done
    write_site "${stand_in[0]}" "${stand_in[1]:-${stand_in[0]}}" "$work/the-new-site"
    awk -v site="$work/the-new-site" '{ print } /include[[:space:]]+\/etc\/nginx\/sites-enabled\/\*;/ { print "\tinclude " site ";"; placed = 1 }
        END { exit placed ? 0 : 1 }' /etc/nginx/nginx.conf > "$work/nginx.conf" \
        || fail "/etc/nginx/nginx.conf does not include sites-enabled the usual way: nothing was checked."
    nginx -t -c "$work/nginx.conf"
    echo "   Nginx would take $DOMAIN beside the sites that are there. Its HTTPS part would read:"
    grep -E '^[[:space:]]*(listen|http2|ssl_|include[[:space:]][^;]*ssl)' "$work/the-new-site" | sed 's/^/   /'
    exit 0
fi

echo "== 1/4 Does $DOMAIN reach this server"
here="$(ip -4 -o route get 1.1.1.1 | sed -nE 's/.* src ([0-9.]+).*/\1/p')"
if command -v dig >/dev/null; then
    there="$(dig +short A "$DOMAIN" @1.1.1.1 | grep -E '^[0-9]+(\.[0-9]+){3}$' | tail -1 || true)"
else
    there="$(getent ahostsv4 "$DOMAIN" | awk 'NR == 1 { print $1 }' || true)"
fi
[[ -n "$there" ]] || fail "$DOMAIN has no A record yet: add one for this server ($here) at the domain's DNS, then run this again."
if [[ "$there" != "$here" ]]; then
    # A server behind its provider's own address (10.x, 172.16-31.x, 192.168.x) cannot tell: Let's Encrypt will.
    if [[ "$here" =~ ^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.) ]]; then
        echo "   $DOMAIN points at $there (this server only knows its inner address, $here)."
    else
        fail "$DOMAIN points at $there, and this server is $here. Point its A record here (DNS only, no proxy), wait until it answers $here, then run this again."
    fi
else
    echo "   yes: $there"
fi

echo "== 2/4 Its certificates"
if [[ -s "$LETSENCRYPT/$DOMAIN/fullchain.pem" && -s "$LETSENCRYPT/$DOMAIN-rsa/fullchain.pem" ]]; then
    echo "   (both are already here)"
else
    # Plain HTTP for the name first, so Let's Encrypt's question about it is answered by this server.
    if [[ ! -e "$LINK" ]]; then
        cat > "$SITE" <<SITE
# $DOMAIN on its way to HTTPS: deploy/server/add-domain.sh writes the whole site once the certificates are here.
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;

    location / {
        return 301 https://\$host\$request_uri;
    }
}
SITE
        ln -sfn "$SITE" "$LINK"
        if ! checked="$(nginx -t 2>&1)"; then
            rm -f "$SITE" "$LINK"
            echo "$checked" >&2
            fail "Nginx would not take a plain site for $DOMAIN: it is taken out again, and nothing was reloaded."
        fi
        systemctl reload nginx
    fi
    certbot certonly --nginx --non-interactive --agree-tos --keep-until-expiring --cert-name "$DOMAIN" \
        --key-type ecdsa --preferred-chain "ISRG Root X1" -d "$DOMAIN"
    certbot certonly --nginx --non-interactive --agree-tos --keep-until-expiring --cert-name "$DOMAIN-rsa" \
        --key-type rsa --rsa-key-size 2048 --preferred-chain "ISRG Root X1" -d "$DOMAIN"
    for name in "$DOMAIN" "$DOMAIN-rsa"; do
        [[ -s "$LETSENCRYPT/$name/fullchain.pem" ]] || fail "certbot wrote no certificate at $LETSENCRYPT/$name."
    done
fi

echo "== 3/4 The site"
before=""
if [[ -f "$SITE" ]]; then
    before="$SITE.before"
    cp "$SITE" "$before"
fi
write_site "$LETSENCRYPT/$DOMAIN" "$LETSENCRYPT/$DOMAIN-rsa" "$SITE"
ln -sfn "$SITE" "$LINK"
if ! checked="$(nginx -t 2>&1)"; then
    if [[ -n "$before" ]]; then mv "$before" "$SITE"; else rm -f "$SITE" "$LINK"; fi
    echo "$checked" >&2
    fail "Nginx would not take the site for $DOMAIN: it is as it was, and nothing was reloaded."
fi
[[ -z "$before" ]] || rm -f "$before"
systemctl reload nginx

echo "== 4/4 Asking $DOMAIN, on this server itself"
ask() { curl -s -o /dev/null -w "$1" --max-time 20 --resolve "$DOMAIN:443:127.0.0.1" --resolve "$DOMAIN:80:127.0.0.1" "${@:2}" || true; }
up="$(ask '%{http_code}' "https://$DOMAIN/up")"
plain="$(ask '%{http_code} %{redirect_url}' "http://$DOMAIN/login")"
rsa=$( { echo | openssl s_client -connect 127.0.0.1:443 -servername "$DOMAIN" -tls1_2 \
    -cipher 'ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256' 2>/dev/null; } | grep -c 'Cipher is ECDHE-RSA' || true)
echo "   https://$DOMAIN/up       : $up"
echo "   http://$DOMAIN/login     : $plain"
echo "   RSA-only client          : $([[ "$rsa" -gt 0 ]] && echo 'gets in' || echo 'REFUSED')"
if [[ "$listen_flags" == *http2* || -n "$http2_line" ]]; then
    version="$(ask '%{http_version}' --http2 "https://$DOMAIN/up")"
    echo "   HTTP version             : $version"
    [[ "$version" == 2 ]] || fail "https://$DOMAIN does not answer over HTTP/2 as the first site does."
fi
[[ "$up" == 200 ]] || fail "https://$DOMAIN/up answered $up, not 200: look at /var/log/nginx/error.log."
[[ "$plain" == "301 https://$DOMAIN/login" ]] || fail "Plain HTTP is not sent on to HTTPS."
[[ "$rsa" -gt 0 ]] || fail "A client that only reads RSA is refused."

cat <<NEXT

Done. The panel answers at https://$DOMAIN as well as at its first address, and both certificates renew
themselves. Its own links and files still name the first address: to move them, run use-domain.sh
(deploy/README.md → "Another address for the panel").
NEXT
