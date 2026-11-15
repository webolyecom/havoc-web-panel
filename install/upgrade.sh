#!/bin/bash
# Havoc WEB Panel: update an existing installation from this checkout.
#   sudo bash install/upgrade.sh
# Keeps /etc/havoc (config, encryption key, certificates), the panel database and all sites. Do NOT re-run install.sh on a
# running server: it would generate a new encryption key and stored backup credentials could no longer be decrypted.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run as root."; exit 1; }
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[ -f "$SRC/public/index.php" ] && [ -f "$SRC/VERSION" ] || { echo "Run this from an unpacked Havoc release."; exit 1; }
[ -f /etc/havoc/config.php ] && [ -d /opt/havoc ] || { echo "No Havoc installation found (use install/install.sh for a new server)."; exit 1; }
log() { echo -e "\n\033[1;36m==>\033[0m $*"; }
OLD=$(cat /opt/havoc/VERSION 2>/dev/null || echo "0.1.x")
NEW=$(cat "$SRC/VERSION")
log "Updating Havoc WEB Panel $OLD -> $NEW"

log "Code"
rm -rf /opt/havoc.old && cp -a /opt/havoc /opt/havoc.old
rsync -a --delete --exclude node_modules --exclude .git "$SRC/" /opt/havoc/
chown -R root:root /opt/havoc
chmod 750 /opt/havoc/ctl/havocctl /opt/havoc/bin/havoc-update /opt/havoc/bin/havoc-meili-install /opt/havoc/bin/havoc-pma-install /opt/havoc/bin/havoc-ws-install
chmod 755 /opt/havoc/bin/havoc /opt/havoc/bin/havoc-cronwrap /opt/havoc/ctl/fsworker
rm -f /opt/havoc/bin/havoc-es-install

log "Panel PHP-FPM settings"
F=/etc/havoc/php-fpm.conf
grep -q '^catch_workers_output' $F || sed -i '/^pm.max_children/a catch_workers_output = yes' $F
grep -q 'log_errors\]' $F || echo 'php_admin_flag[log_errors] = on' >> $F
grep -q 'max_execution_time\]' $F || echo 'php_admin_value[max_execution_time] = 660' >> $F

log "Panel web server settings (include dir, timeouts, compression)"
N=/etc/havoc/nginx/nginx.conf
install -d -m 755 /etc/havoc/nginx/conf.d
cp -a $N $N.bak
if ! grep -q 'conf.d/\*.conf' $N; then
  sed -i '0,/fastcgi_param HTTPS on;/s//fastcgi_param HTTPS on;\n    include \/etc\/havoc\/nginx\/conf.d\/*.conf;/' $N
fi
grep -q 'fastcgi_read_timeout' $N || sed -i '0,/client_max_body_size 64m;/s//client_max_body_size 64m;\n    fastcgi_read_timeout 660s;/' $N
if ! grep -q 'gzip on' $N; then
  sed -i '0,/fastcgi_read_timeout 660s;/s//fastcgi_read_timeout 660s;\n    gzip on;\n    gzip_types text\/css application\/javascript application\/json image\/svg+xml text\/plain;\n    gzip_min_length 1024;/' $N
fi
if ! /usr/sbin/nginx -t -c $N; then
  echo "Panel web server configuration test failed; restoring the previous file."; cp -a $N.bak $N
fi

log "Web server selection"
if [ ! -f /etc/havoc/webserver ]; then
  if systemctl is-active --quiet lshttpd 2>/dev/null && [ -f /usr/local/lsws/bin/openlitespeed ]; then echo ols > /etc/havoc/webserver
  elif systemctl is-active --quiet lsws 2>/dev/null; then echo lsws > /etc/havoc/webserver
  else echo nginx > /etc/havoc/webserver; fi
  chmod 644 /etc/havoc/webserver
fi
echo "Sites are served by: $(cat /etc/havoc/webserver)"

log "Database migrations"
runuser -u havoc -- php /opt/havoc/bin/havoc migrate

log "Restarting the panel"
systemctl daemon-reload
systemctl restart havoc-panel-fpm
systemctl reload havoc-panel-nginx || systemctl restart havoc-panel-nginx

log "Re-applying site configurations (adds www.<domain>, new PHP/Nginx options)"
runuser -u havoc -- php /opt/havoc/bin/havoc domains:reapply || true

log "Rewriting cron tables"
runuser -u havoc -- php /opt/havoc/bin/havoc cron:sync || true

if dpkg-query -W -f='${Status}' elasticsearch 2>/dev/null | grep -q 'install ok installed'; then
  echo -e "\n\033[1;33mNote:\033[0m Elasticsearch is still installed but no longer managed by the panel. To remove it:"
  echo "  systemctl disable --now elasticsearch && apt-get purge -y elasticsearch"
  echo "  rm -f /etc/apt/sources.list.d/elastic-8.x.list /usr/share/keyrings/elastic.gpg"
  echo "  rm -rf /var/lib/elasticsearch /etc/elasticsearch   # deletes its data"
fi
echo -e "\n\033[1;32mHavoc WEB Panel $NEW is ready.\033[0m Previous code kept in /opt/havoc.old."
