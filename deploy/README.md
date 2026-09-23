# Going live

One Ubuntu 24.04 server (sized for Hetzner's CPX12: 1 vCPU, 2 GB, 40 GB) runs everything: Nginx, PHP 8.3-FPM,
MySQL 8.4 LTS, HTTPS from Let's Encrypt, Laravel's scheduler and a nightly database backup. Mail goes out
through Brevo. Nothing here is a hosted platform or a paid tool.

MySQL comes from Oracle's own repository, not Ubuntu's: 24.04 carries 8.0, which Oracle stopped fixing in
April 2026, and 8.4 is the version the panel is developed against.

| File | What it is | Who runs it |
|---|---|---|
| `server/setup.sh` | Prepares a fresh server once: updates, swap, firewall, keys-only SSH, the `deploy` account, PHP, MySQL, Nginx, cron, backups | **You**, as root — it changes the server's security settings |
| `server/env.production.example` | The live `.env`; `setup.sh` fills in the domain, app key and database password | You fill in the `__FILL_IN__` lines |
| `server/nginx-site.conf`, `php.ini`, `php-fpm-pool.conf`, `mysql.cnf`, `backup-database.sh` | Installed by `setup.sh` | — |
| `deploy.sh` | Puts the **last commit** live and checks it | Claude or you, from Git Bash on the PC |
| `server/release.sh` | The server's half of a deploy (uploaded by `deploy.sh` each time) | — |

## On the server

```
/var/www/signage/
  current -> releases/20260922120000     the live release (what Nginx serves)
  releases/                              one folder per deploy; the five newest stay
  shared/.env                            the settings (only the deploy account can read it)
  shared/storage/app                     uploads, published ads, fonts — every release shares them
  shared/storage/logs                    laravel-YYYY-MM-DD.log, two weeks kept
/var/backups/signage/db-YYYY-MM-DD.sql.gz   the nightly database dump, two weeks kept
```

A deploy builds the new release beside the running one (packages, migrations, caches) and only then points
`current` at it, so the site never serves a half-installed release. PHP runs as the `deploy` account; the
only thing it may do as root is reload PHP.

## 1. The server (you)

1. Hetzner Cloud → your project → **Security → SSH keys → Add SSH key**: paste the whole line of
   `~/.ssh/signage_deploy.pub` (Claude made this key on the PC; the private half never leaves it).
2. **Add server**: the location nearest the stores (the shops are American, so Germany — Falkenstein or
   Nuremberg — over Helsinki, and never Singapore), **Ubuntu 24.04**, the plan, **Public IPv4 on**, the SSH
   key ticked. Hetzner's own Backups (+20%) are optional: the server already dumps its database nightly, but
   only Backups (or a snapshot) also keep the uploads and the server itself.
3. Note the server's IPv4 address.

Outgrowing it later is a reboot: Hetzner rescales CPU and RAM in place, and the settings say which numbers
to raise (`deploy/server/mysql.cnf`, `php-fpm-pool.conf`). Growing the DISK cannot be undone, so leave it.

Keep `~/.ssh/signage_deploy` safe — it is the only way in over SSH once `setup.sh` has run. If it is ever
lost, Hetzner's web console (and its "Reset root password") still reaches the server.

## 2. The domain (you)

At the domain's DNS: an **A record** for the panel's address (e.g. `app.yourdomain.com`) → the server's IPv4.
If the DNS is on Cloudflare, leave it **DNS only** (grey cloud): Cloudflare's proxy refuses uploads over
100 MB on its free plan, and the panel takes up to 250 MB.

## 3. Prepare the server — once (you)

From Git Bash in the project folder:

```bash
scp -i ~/.ssh/signage_deploy -r deploy/server root@SERVER_IP:/root/signage-setup
ssh -i ~/.ssh/signage_deploy root@SERVER_IP "bash /root/signage-setup/setup.sh app.yourdomain.com"
```

Before closing that window, open a new one and make sure the deploy account gets in:
`ssh -i ~/.ssh/signage_deploy deploy@SERVER_IP` — then `exit`. If `setup.sh` ends by asking for a restart,
`ssh -i ~/.ssh/signage_deploy root@SERVER_IP reboot`.

## 4. The settings (you)

```bash
ssh -i ~/.ssh/signage_deploy root@SERVER_IP
nano /var/www/signage/shared/.env
```

Fill in every `__FILL_IN__`: `APP_NAME` (keep the quotes), the three `MAIL_` lines from Brevo (SMTP login,
SMTP key, a From address on your own domain that Brevo has verified), and `SEED_ADMIN_EMAIL` /
`SEED_ADMIN_PASSWORD` — your own email and a strong password; put the password in single quotes. Nobody
else sees this file, and a deploy never prints it.

## 5. HTTPS (you — certbot asks for your email and its terms)

Once the domain reaches the server (`ping app.yourdomain.com` shows its IP), on the server as root:

```bash
certbot --nginx --redirect -d app.yourdomain.com
```

It renews itself. Sign-in needs HTTPS: the session cookie is sent over HTTPS only.

## 6. The first deploy

```bash
bash deploy/deploy.sh deploy@SERVER_IP https://app.yourdomain.com --first
```

`--first` also makes the super admin from `SEED_ADMIN_*`, and refuses on a server that already has a release.
Then sign in, and empty the password line again (`SEED_ADMIN_PASSWORD=`) in `shared/.env`. Prove the mail
works: `ssh -i ~/.ssh/signage_deploy deploy@SERVER_IP "cd /var/www/signage/current && php artisan mail:test you@example.com"`.

## The televisions

A television opens `https://app.yourdomain.com/player` in its browser and shows a pairing code. The player is
a progressive web app: on a Chromium-based set or Android box it can be installed full screen (the browser's
"Add to home screen" / "Install app"), and its service worker keeps the page, the last playlist and every
file on it, so the set keeps playing when the shop's internet drops and picks up the live playlist the
moment it returns (docs/AD-BUILDER-SPEC.md §15). Nothing on the set says it is offline — the Screens page
does, from its missed heartbeats. On a browser without service workers the player simply plays online.

## Every deploy after that

Commit, then:

```bash
bash deploy/deploy.sh deploy@SERVER_IP https://app.yourdomain.com
```

Only the last commit goes up — uncommitted work is refused. Each deploy ends by checking the live site:
`/up` and `/login` answer 200, and **`/_dusk/login/1` and `/_dusk/user` answer 404**. Those two are Dusk's
password-free sign-in routes: they exist wherever the development packages are installed and `APP_ENV` is
not `production`, so the live server must never have either (`release.sh` refuses to deploy without
`APP_ENV=production` and `APP_DEBUG=false`, and installs with `composer install --no-dev`).

## Going back

The previous releases are still there. On the server as `deploy`:

```bash
ls /var/www/signage/releases
ln -sfn /var/www/signage/releases/STAMP /var/www/signage/current && sudo systemctl reload php8.3-fpm
```

A migration the newer release ran stays run — going back is for code, not for the database.

## A visitor cannot open the site

One person seeing `ERR_SSL_PROTOCOL_ERROR` while the site is fine for everybody else is almost never the
server. Work down this list before changing anything:

1. `nginx -t` and the certificate: `certbot certificates` — dates and domain.
2. **The server's own logs.** `grep -i ssl /var/log/nginx/error.log` and the access log. If the visitor's
   attempt is not there at all, it never reached this machine: something between them and here — an
   antivirus scanning HTTPS, an office proxy, their ISP — terminated it.
3. From anywhere else: `openssl s_client -connect app.example.com:443 -servername app.example.com` and a
   scan at ssllabs.com. A grade of A with a complete chain settles the server's side.
4. Ask them to open the same link **on mobile data instead of their WiFi**. If it opens, their network is
   the wall, and it is usually one of two things:
   - **The certificate is ECDSA-only** (certbot's default) and the middlebox in front of them only knows
     RSA. Fix it for everyone with `server/add-rsa-certificate.sh app.example.com`, which issues an RSA
     certificate beside it; Nginx then hands each client whichever it can read. Test the failing case with
     `openssl s_client … -tls1_2 -cipher 'ECDHE-RSA-AES256-GCM-SHA384'` — a handshake failure is the proof.
   - **The visitor's antivirus terminates TLS and its CA bundle is old** (found 2026-09-22: Spectrum's
     Security Suite, which is F-Secure). Since January 2026 Let's Encrypt signs from new roots (ISRG Root
     YE / YR) that such bundles do not have; the site fails, while google.com — Google Trust Services, roots
     in every bundle for a decade — works on the same PC. The server shows only "client closed connection
     while SSL handshaking" at `info` level, and the PC's own Schannel log names the mismatch. Neither an
     RSA certificate nor `--preferred-chain "ISRG Root X1"` (`server/use-compatible-chain.sh`) was enough
     for that bundle. The fix that mirrors what the big sites do is a certificate from an authority with an
     old root: `server/switch-to-zerossl.sh app.example.com KID HMAC you@example.com` (free, ACME, USERTrust
     root from 2010; the EAB credentials come from the ZeroSSL dashboard). Control test for the visitor:
     `curl -v https://letsencrypt.org` — it serves the same new chain and fails the same way.
   - **The network blocks the address or the hosting range.** Nothing on this server changes that; putting
     the site behind Cloudflare would — with its certificate authority set to Google Trust Services, not
     Let's Encrypt, or the same bundles fail again — at the cost of a 100 MB upload cap on its free plan.

## After changing `shared/.env`

The settings are cached on the server, so an edit changes nothing until the cache is rebuilt:

```bash
ssh -i ~/.ssh/signage_deploy deploy@SERVER_IP "cd /var/www/signage/current && php artisan optimize"
```

## Keeping it patched

Ubuntu's and MySQL's security updates install themselves daily. Two things still want a human:

- **A new kernel** needs one restart: if `ls /var/run/reboot-required` prints that file, `reboot` when the
  shops are quiet.
- **MySQL's repository key expires in October 2027.** When `apt-get update` starts warning about it
  (`EXPKEYSIG`), take the newest `RPM-GPG-KEY-mysql-<year>` from `repo.mysql.com` and replace the keyring:
  `curl -fsS https://repo.mysql.com/RPM-GPG-KEY-mysql-2027 | gpg --batch --yes --dearmor -o /usr/share/keyrings/mysql.gpg`

## Where to look

- The app's errors: `/var/www/signage/shared/storage/logs/laravel-YYYY-MM-DD.log`
- Nginx: `/var/log/nginx/error.log` · PHP: `journalctl -u php8.3-fpm`
- Backups: `/var/backups/signage` (root only). Copy one to the PC with
  `scp -i ~/.ssh/signage_deploy root@SERVER_IP:/var/backups/signage/db-YYYY-MM-DD.sql.gz .`
  Restoring one **replaces the live database** — as root:
  `gunzip < /var/backups/signage/db-YYYY-MM-DD.sql.gz | mysql signage`
