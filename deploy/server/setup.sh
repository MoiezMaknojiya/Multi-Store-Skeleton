#!/usr/bin/env bash
#
# Prepares a FRESH Ubuntu 24.04 server for the signage panel (deploy/README.md, step 3).
# Run it once, as root, yourself: it changes the server's security settings and creates the deploy account.
#
#   scp -i ~/.ssh/signage_deploy -r deploy/server root@SERVER_IP:/root/signage-setup
#   ssh -i ~/.ssh/signage_deploy root@SERVER_IP "bash /root/signage-setup/setup.sh app.example.com"
#
# It asks nothing. The database password and the app key are made here, at random, and written straight into
# /var/www/signage/shared/.env — never printed. What is left for you to fill in there is marked __FILL_IN__.
# Running it again is safe: it keeps the app key, gives the database a new password (written to .env
# again), and leaves an HTTPS site that certbot has already set up alone.
set -euo pipefail

DOMAIN="${1:?Usage: setup.sh app.example.com}"
HERE="$(cd "$(dirname "$0")" && pwd)"
APP=/var/www/signage
PHP=8.3
DB=signage
DB_USER=signage

[[ $EUID -eq 0 ]] || { echo "Run this as root." >&2; exit 1; }
grep -q 'VERSION_ID="24.04"' /etc/os-release || { echo "This script is for Ubuntu 24.04." >&2; exit 1; }
[[ "$DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "\"$DOMAIN\" does not look like a domain (app.example.com)." >&2; exit 1; }
# Password logins are switched off below: without a key nobody could log in over SSH any more.
[[ -s /root/.ssh/authorized_keys ]] || { echo "Root has no SSH key: create the server with the deploy key (Hetzner: Security → SSH keys)." >&2; exit 1; }
for file in php.ini php-fpm-pool.conf mysql.cnf nginx-site.conf env.production.example backup-database.sh; do
    [[ -f "$HERE/$file" ]] || { echo "$file is missing next to setup.sh: copy the whole deploy/server folder." >&2; exit 1; }
done

echo "== 1/9 Updates, swap and the clock"
# A new server finishes its own first-boot setup, and its automatic updates may hold apt for a while: wait.
if command -v cloud-init >/dev/null; then cloud-init status --wait >/dev/null || true; fi
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
APT=(apt-get -y -q -o DPkg::Lock::Timeout=600 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)
"${APT[@]}" update
"${APT[@]}" upgrade
if [[ ! -e /swapfile ]]; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
    swapon /swapfile
    grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
# Swap is the safety net for a rare peak, not somewhere to keep MySQL's cache.
echo 'vm.swappiness = 10' > /etc/sysctl.d/99-signage.conf
sysctl -q -p /etc/sysctl.d/99-signage.conf
timedatectl set-timezone UTC

echo "== 2/9 Packages"
# MySQL 8.4 LTS — the version the panel is built on — from Oracle's own repository: Ubuntu 24.04 carries only
# 8.0, which Oracle stopped fixing in April 2026. The signing key is checked against its fingerprint first.
MYSQL_KEY_FINGERPRINT=BCA43417C3B485DD128EC6D4B7B3B788A8D3785C
"${APT[@]}" install ca-certificates curl gnupg
curl -fsS -o /tmp/mysql-key.asc https://repo.mysql.com/RPM-GPG-KEY-mysql-2025
MYSQL_KEY="$(gpg --show-keys --with-colons /tmp/mysql-key.asc 2>/dev/null)"
grep -q "^fpr:::::::::$MYSQL_KEY_FINGERPRINT:" <<< "$MYSQL_KEY" || { echo "MySQL's signing key is not the expected one." >&2; exit 1; }
gpg --batch --yes --dearmor -o /usr/share/keyrings/mysql.gpg /tmp/mysql-key.asc
rm -f /tmp/mysql-key.asc
echo "deb [signed-by=/usr/share/keyrings/mysql.gpg] https://repo.mysql.com/apt/ubuntu/ noble mysql-8.4-lts" \
    > /etc/apt/sources.list.d/mysql.list
# Security fixes install themselves every day: Ubuntu's, and MySQL's from its repository.
printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' \
    > /etc/apt/apt.conf.d/20auto-upgrades
echo 'Unattended-Upgrade::Origins-Pattern:: "origin=MySQL,codename=${distro_codename}";' \
    > /etc/apt/apt.conf.d/51signage-unattended-upgrades
"${APT[@]}" update

"${APT[@]}" install nginx mysql-server composer unzip git openssl ufw fail2ban unattended-upgrades \
    certbot python3-certbot-nginx \
    php$PHP-fpm php$PHP-cli php$PHP-mysql php$PHP-gd php$PHP-mbstring php$PHP-xml php$PHP-curl \
    php$PHP-zip php$PHP-intl php$PHP-bcmath php$PHP-opcache
MYSQL_VERSION="$(mysqld --version)"
[[ "$MYSQL_VERSION" == *" 8.4."* ]] || { echo "Expected MySQL 8.4, got: $MYSQL_VERSION" >&2; exit 1; }

echo "== 3/9 Firewall: SSH, HTTP and HTTPS only"
ufw allow OpenSSH >/dev/null
ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null
# Keeps password guessers out of the logs; the server works without it, so a failure is only reported.
systemctl enable --now fail2ban >/dev/null 2>&1 || echo "   (fail2ban did not start — nothing else depends on it)"

echo "== 4/9 The deploy account (the code, the storage and PHP all run as it)"
id deploy >/dev/null 2>&1 || useradd --create-home --shell /bin/bash deploy
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
install -m 600 -o deploy -g deploy /root/.ssh/authorized_keys /home/deploy/.ssh/authorized_keys
# The one thing a deploy needs root for: reloading PHP, so the new code is the code that runs.
echo "deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php$PHP-fpm" > /etc/sudoers.d/signage-deploy
chmod 440 /etc/sudoers.d/signage-deploy
visudo -cqf /etc/sudoers.d/signage-deploy

echo "== 5/9 SSH: keys only"
# sshd keeps the FIRST value it reads, and Ubuntu 24.04 ships 50-cloud-init.conf with PasswordAuthentication
# yes — so this file is named to be read before it.
cat > /etc/ssh/sshd_config.d/00-signage.conf <<'SSHD'
# The signage panel (deploy/README.md): no password logins over SSH; root only with a key.
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
SSHD
install -d -m 755 /run/sshd
sshd -t
SSHD_EFFECTIVE="$(sshd -T)"
grep -qx 'passwordauthentication no' <<< "$SSHD_EFFECTIVE" \
    || { echo "SSH would still accept passwords: check /etc/ssh/sshd_config.d." >&2; exit 1; }
systemctl reload ssh 2>/dev/null || systemctl restart ssh

echo "== 6/9 PHP"
install -m 644 "$HERE/php.ini" /etc/php/$PHP/fpm/conf.d/99-signage.ini
install -m 644 "$HERE/php.ini" /etc/php/$PHP/cli/conf.d/99-signage.ini
install -m 644 "$HERE/php-fpm-pool.conf" /etc/php/$PHP/fpm/pool.d/signage.conf
rm -f /etc/php/$PHP/fpm/pool.d/www.conf
php-fpm$PHP -t
systemctl restart php$PHP-fpm

echo "== 7/9 MySQL: the database and its user"
install -d /etc/mysql/mysql.conf.d
install -m 644 "$HERE/mysql.cnf" /etc/mysql/mysql.conf.d/99-signage.cnf
systemctl restart mysql
MYSQL_SETTINGS="$(mysql -NBe 'SELECT @@bind_address, @@innodb_buffer_pool_size >= 536870912')"
[[ "$MYSQL_SETTINGS" == $'127.0.0.1\t1' ]] \
    || { echo "MySQL did not take mysql.cnf (bind address, cache size): $MYSQL_SETTINGS" >&2; exit 1; }
# The panel talks to MySQL through its socket, wherever this MySQL keeps it.
DB_SOCKET="$(mysql -NBe 'SELECT @@socket')"
[[ -S "$DB_SOCKET" ]] || { echo "MySQL's socket is not where it says: $DB_SOCKET" >&2; exit 1; }
# Hex only, so it needs no quoting anywhere it goes.
DB_PASSWORD="$(openssl rand -hex 24)"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "== 8/9 Folders and the app's settings"
# What outlives a release: the uploads (storage/app) and the logs. Each release keeps its own compiled views
# and caches, so building a new one never clears what the running one is reading.
install -d -o deploy -g deploy "$APP" "$APP/releases" "$APP/shared" "$APP/shared/storage" \
    "$APP/shared/storage/app" "$APP/shared/storage/app/public" "$APP/shared/storage/app/private" "$APP/shared/storage/logs"
if [[ -e "$APP/shared/.env" ]]; then
    sed -i -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" -e "s|^DB_SOCKET=.*|DB_SOCKET=$DB_SOCKET|" "$APP/shared/.env"
else
    APP_KEY="base64:$(openssl rand -base64 32)"
    sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__DB_PASSWORD__|$DB_PASSWORD|" -e "s|__APP_KEY__|$APP_KEY|" \
        -e "s|__DB_SOCKET__|$DB_SOCKET|" "$HERE/env.production.example" > "$APP/shared/.env"
fi
chown deploy:deploy "$APP/shared/.env"
chmod 600 "$APP/shared/.env"
unset DB_PASSWORD APP_KEY

echo "== 9/9 Nginx, the scheduler and a nightly database backup"
# Behind Cloudflare, a visitor's own address arrives in CF-Connecting-IP — believed from Cloudflare's
# addresses only, fetched now. Without Cloudflare in front, these lines change nothing.
CF_V4="$(curl -fsS https://www.cloudflare.com/ips-v4)"
CF_V6="$(curl -fsS https://www.cloudflare.com/ips-v6)"
for range in $CF_V4 $CF_V6; do
    [[ "$range" =~ ^[0-9a-f:.]+/[0-9]{1,3}$ ]] || { echo "Cloudflare's address list looks wrong: \"$range\"." >&2; exit 1; }
done
{
    echo "# Cloudflare's addresses (https://www.cloudflare.com/ips), fetched $(date -u +%F) by setup.sh."
    for range in $CF_V4 $CF_V6; do
        echo "set_real_ip_from $range;"
    done
    echo "real_ip_header CF-Connecting-IP;"
} > /etc/nginx/snippets/cloudflare-realip.conf
if grep -q 'managed by Certbot' /etc/nginx/sites-available/signage 2>/dev/null; then
    echo "   (the site already has HTTPS from certbot: left as it is)"
else
    sed "s|__DOMAIN__|$DOMAIN|g" "$HERE/nginx-site.conf" > /etc/nginx/sites-available/signage
fi
ln -sfn /etc/nginx/sites-available/signage /etc/nginx/sites-enabled/signage
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

# Laravel's scheduler (the activity log's yearly partitions, routes/console.php).
echo "* * * * * deploy cd $APP/current && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/signage-scheduler
chmod 644 /etc/cron.d/signage-scheduler

install -m 700 "$HERE/backup-database.sh" /usr/local/sbin/signage-backup-database
echo "30 3 * * * root /usr/local/sbin/signage-backup-database" > /etc/cron.d/signage-backup
chmod 644 /etc/cron.d/signage-backup

cat <<NEXT

Done. The server is ready.

Next (deploy/README.md):
  1. Fill in every __FILL_IN__:   nano $APP/shared/.env
  2. Once $DOMAIN points at this server, HTTPS:   certbot --nginx --redirect -d $DOMAIN
  3. First deploy, from your PC:   bash deploy/deploy.sh deploy@<this server's IP> https://$DOMAIN --first
NEXT
if [[ -f /var/run/reboot-required ]]; then
    echo "  The updates need one restart of the server: run  reboot  (then log in again)."
fi
