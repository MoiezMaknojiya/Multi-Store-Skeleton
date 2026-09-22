#!/usr/bin/env bash
#
# The nightly database backup (installed by setup.sh as /usr/local/sbin/signage-backup-database, run by cron
# as root at 03:30 UTC). Two weeks of dumps are kept in /var/backups/signage; older ones go. Root logs in to
# MySQL through its socket, so no password is stored anywhere for this.
set -euo pipefail

DIR=/var/backups/signage
install -d -m 700 "$DIR"

mysqldump --single-transaction --quick --routines signage | gzip > "$DIR/db-$(date -u +%F).sql.gz"
find "$DIR" -name 'db-*.sql.gz' -mtime +14 -delete
