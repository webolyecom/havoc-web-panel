#!/bin/bash
# Havoc WEB Panel installer - Debian 12 (bookworm) / Debian 13 (trixie)
#   git clone <repository URL> havoc-web-panel && cd havoc-web-panel && sudo bash install/install.sh
# Options (env): HAVOC_WEBSERVER=nginx|ols|lsws (lsws: HAVOC_LSWS_LICENSE=<serial>, default TRIAL)  HAVOC_PHP_VERSIONS="8.1 8.2 8.3 8.4"  HAVOC_PORT=2087
#                HAVOC_TARBALL=<https URL of havoc-panel.tar.gz>  HAVOC_UPDATE_URL=<same, used by self-update>
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

[ "$(id -u)" -eq 0 ] || { echo "Run as root."; exit 1; }
. /etc/os-release
case "${VERSION_ID:-}" in 12|13) ;; *) echo "Unsupported OS (need Debian 12 or 13)."; exit 1;; esac
CODENAME="$VERSION_CODENAME"
WEBSERVER="${HAVOC_WEBSERVER:-nginx}"
PHP_VERSIONS="${HAVOC_PHP_VERSIONS:-8.1 8.2 8.3 8.4}"
PORT="${HAVOC_PORT:-2087}"
[ "$VERSION_ID" = 12 ] && PV=8.2 || PV=8.4          # PHP that runs the panel itself
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/.." 2>/dev/null && pwd || true)"
log() { echo -e "\n\033[1;36m==>\033[0m $*"; }

log "Base packages"
apt-get update -y
apt-get install -y curl ca-certificates gnupg openssl rsync tar certbot mariadb-server redis-server logrotate sudo lsb-release cron rclone tar openssh-server pure-ftpd bind9 bind9-utils ufw

log "PHP repository (Sury) for PHP 8.1-8.4"
curl -fsSLo /tmp/sury.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
dpkg -i /tmp/sury.deb
echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $CODENAME main" > /etc/apt/sources.list.d/php-sury.list
apt-get update -y

log "PHP: panel runtime $PV + hosting versions ($PHP_VERSIONS)"
apt-get install -y "php$PV-cli" "php$PV-fpm" "php$PV-sqlite3" "php$PV-mbstring" "php$PV-curl" "php$PV-xml" "php$PV-zip"
for v in $PHP_VERSIONS; do
  apt-get install -y "php$v-fpm" "php$v-cli" "php$v-mysql" "php$v-curl" "php$v-gd" "php$v-mbstring" "php$v-xml" \
    "php$v-zip" "php$v-intl" "php$v-bcmath" "php$v-opcache" || echo "WARN: php$v not fully installed"
  # per-domain FPM masters (havoc-fpm-<id>) replace the distro pools
  systemctl disable --now "php$v-fpm" 2>/dev/null || true
done
update-alternatives --set php "/usr/bin/php$PV" || true

log "Web server: Nginx (the panel always runs on Nginx; sites use: $WEBSERVER)"
case "$WEBSERVER" in nginx|ols|lsws) ;; *) echo "Unknown HAVOC_WEBSERVER (nginx, ols or lsws)"; exit 1;; esac
apt-get install -y nginx libnginx-mod-http-modsecurity modsecurity-crs 2>/dev/null || apt-get install -y nginx
rm -f /etc/nginx/sites-enabled/default; systemctl enable --now nginx

log "System user and directories"
id havoc >/dev/null 2>&1 || useradd --system --home-dir /var/lib/havoc --shell /usr/sbin/nologin havoc
install -d -o havoc -g havoc -m 750 /var/lib/havoc /var/lib/havoc/sessions
install -d -o root -g root -m 755 /opt/havoc
install -d -o root -g havoc -m 750 /etc/havoc /etc/havoc/nginx /etc/havoc/ssl
install -d -m 755 /etc/havoc/nginx/conf.d
install -d -m 755 /var/log/havoc /var/log/havoc/domains /var/www/havoc-acme /etc/havoc/fpm /etc/havoc/php
install -d -o havoc -g havoc -m 750 /var/log/havoc/panel
echo 'd /run/havoc-fpm 0755 root root -' > /etc/tmpfiles.d/havoc.conf && systemd-tmpfiles --create

log "Panel files"
if [ -n "$SRC_DIR" ] && [ -f "$SRC_DIR/public/index.php" ]; then
  rsync -a --delete --exclude node_modules --exclude .git "$SRC_DIR/" /opt/havoc/
else
  : "${HAVOC_TARBALL:?Set HAVOC_TARBALL to the release tarball URL (or run install.sh from a checkout)}"
  curl -fsSL --proto '=https' -o /tmp/havoc.tgz "$HAVOC_TARBALL"
  tar -xzf /tmp/havoc.tgz -C /opt/havoc --strip-components=1
fi
chown -R root:root /opt/havoc
chmod 750 /opt/havoc/ctl/havocctl /opt/havoc/bin/havoc-update /opt/havoc/bin/havoc-meili-install /opt/havoc/bin/havoc-pma-install /opt/havoc/bin/havoc-ws-install
chmod 755 /opt/havoc/bin/havoc-cronwrap /opt/havoc/ctl/fsworker
chmod 755 /opt/havoc/bin/havoc

log "Configuration"
KEY=$(openssl rand -hex 32)
sed "s/CHANGE_ME/$KEY/" /opt/havoc/config/config.php.dist > /etc/havoc/config.php
chown root:havoc /etc/havoc/config.php; chmod 640 /etc/havoc/config.php
[ -n "${HAVOC_UPDATE_URL:-${HAVOC_TARBALL:-}}" ] && echo "HAVOC_UPDATE_URL=${HAVOC_UPDATE_URL:-$HAVOC_TARBALL}" > /etc/havoc/update.conf
chmod 600 /etc/havoc/update.conf 2>/dev/null || true

# only the helper may run as root, nothing else
echo 'havoc ALL=(root) NOPASSWD: /opt/havoc/ctl/havocctl *' > /etc/sudoers.d/havoc
chmod 440 /etc/sudoers.d/havoc && visudo -cf /etc/sudoers.d/havoc

log "Panel HTTPS (self-signed until you attach a real certificate), port $PORT"
openssl req -x509 -nodes -newkey rsa:3072 -days 825 -keyout /etc/havoc/ssl/panel.key -out /etc/havoc/ssl/panel.crt \
  -subj "/CN=$(hostname -f)" -addext "subjectAltName=DNS:$(hostname -f)" 2>/dev/null
chown root:havoc /etc/havoc/ssl/panel.*; chmod 640 /etc/havoc/ssl/panel.key

cat > /etc/havoc/php-fpm.conf <<FPM
[global]
pid = /run/havoc-panel-fpm.pid
error_log = /var/log/havoc/panel/php-fpm.log
daemonize = no
[panel]
user = havoc
group = havoc
listen = /run/havoc-panel.sock
listen.owner = havoc
listen.group = havoc
listen.mode = 0660
pm = ondemand
pm.max_children = 12
catch_workers_output = yes
php_admin_flag[log_errors] = on
php_admin_value[max_execution_time] = 660
php_admin_value[expose_php] = off
php_admin_value[session.cookie_httponly] = 1
php_admin_flag[display_errors] = off
php_admin_value[post_max_size] = 48M
php_admin_value[upload_max_filesize] = 8M
php_admin_value[memory_limit] = 256M
FPM

cat > /etc/havoc/nginx/nginx.conf <<NGX
user havoc;
worker_processes auto;
pid /run/havoc-panel-nginx.pid;
error_log /var/log/havoc/panel/nginx-error.log;
events { worker_connections 1024; }
http {
  include /etc/nginx/mime.types;
  access_log /var/log/havoc/panel/nginx-access.log;
  server_tokens off;
  limit_req_zone \$binary_remote_addr zone=login:10m rate=20r/m;
  server {
    listen $PORT ssl;
    listen [::]:$PORT ssl;
    http2 on;
    ssl_certificate /etc/havoc/ssl/panel.crt;
    ssl_certificate_key /etc/havoc/ssl/panel.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    root /opt/havoc/public;
    client_max_body_size 64m;
    fastcgi_read_timeout 660s;
    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml text/plain;
    gzip_min_length 1024;
    include /etc/nginx/fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /opt/havoc/public/index.php;
    fastcgi_param HTTPS on;
    include /etc/havoc/nginx/conf.d/*.conf;
    location /assets/ { expires 7d; try_files \$uri =404; }
    location = /login { limit_req zone=login burst=10 nodelay; fastcgi_pass unix:/run/havoc-panel.sock; }
    location / { fastcgi_pass unix:/run/havoc-panel.sock; }
  }
}
NGX
# Nginx < 1.25.1 (Debian 12) needs the http2 flag on the listen line
if [ "$VERSION_ID" = 12 ]; then
  sed -i 's/listen \(\[::\]:\)\?\([0-9]*\) ssl;/listen \1\2 ssl http2;/; /^    http2 on;$/d' /etc/havoc/nginx/nginx.conf
fi

cat > /etc/systemd/system/havoc-panel-fpm.service <<UNIT
[Unit]
Description=Havoc WEB Panel PHP-FPM
After=network.target
[Service]
ExecStart=/usr/sbin/php-fpm$PV --nodaemonize --fpm-config /etc/havoc/php-fpm.conf
ExecReload=/bin/kill -USR2 \$MAINPID
KillMode=process
Restart=on-failure
[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/havoc-panel-nginx.service <<UNIT
[Unit]
Description=Havoc WEB Panel HTTPS front-end
After=network.target havoc-panel-fpm.service
[Service]
ExecStart=/usr/sbin/nginx -c /etc/havoc/nginx/nginx.conf -g 'daemon off;'
ExecReload=/bin/kill -HUP \$MAINPID
Restart=on-failure
[Install]
WantedBy=multi-user.target
UNIT

cat > /etc/systemd/system/havoc-backup.service <<UNIT
[Unit]
Description=Havoc scheduled backups
[Service]
Type=oneshot
User=havoc
ExecStart=/usr/bin/php /opt/havoc/bin/havoc backup:due
TimeoutStartSec=infinity
UNIT
cat > /etc/systemd/system/havoc-backup.timer <<UNIT
[Unit]
Description=Run Havoc backup scheduler hourly
[Timer]
OnCalendar=hourly
Persistent=true
[Install]
WantedBy=timers.target
UNIT
cat > /etc/systemd/system/havoc-usage.service <<UNIT
[Unit]
Description=Havoc usage, traffic and quota scan
[Service]
Type=oneshot
User=havoc
ExecStart=/usr/bin/php /opt/havoc/bin/havoc usage:scan
TimeoutStartSec=infinity
Nice=10
UNIT
cat > /etc/systemd/system/havoc-usage.timer <<UNIT
[Unit]
Description=Run the Havoc usage scan every 30 minutes
[Timer]
OnCalendar=*:0/30
Persistent=true
[Install]
WantedBy=timers.target
UNIT
install -d -m 711 /var/backups/havoc
install -d -o www-data -g www-data -m 750 /var/log/havoc/waf
install -d -m 755 /etc/havoc/waf

log "FTP (Pure-FTPd, virtual users, explicit TLS) and SFTP jail"
install -d -m 755 /srv/havoc-jail
groupadd -f havoc-sftp
C=/etc/pure-ftpd/conf
echo yes > $C/ChrootEveryone; echo yes > $C/NoAnonymous; echo yes > $C/DontResolve
echo "40000 40100" > $C/PassivePortRange
echo /etc/pure-ftpd/pureftpd.pdb > $C/PureDB
echo "${HAVOC_FTP_TLS:-2}" > $C/TLS          # 2 = TLS required, 1 = TLS optional
ln -sf $C/PureDB /etc/pure-ftpd/auth/50pure
touch /etc/pure-ftpd/pureftpd.passwd && pure-pw mkdb
openssl req -x509 -nodes -newkey rsa:3072 -days 825 -keyout /etc/ssl/private/pure-ftpd.pem -out /etc/ssl/private/pure-ftpd.pem \
  -subj "/CN=$(hostname -f)" 2>/dev/null
chmod 600 /etc/ssl/private/pure-ftpd.pem
systemctl enable pure-ftpd && systemctl restart pure-ftpd || echo "WARN: pure-ftpd did not start"
command -v ufw >/dev/null && { ufw allow 21/tcp; ufw allow 40000:40100/tcp; } || true

log "DNS (BIND9, authoritative only)"
install -d -o root -g bind -m 750 /etc/bind/havoc
cat > /etc/bind/named.conf.options <<BIND
options {
  directory "/var/cache/bind";
  listen-on { any; };
  listen-on-v6 { any; };
  recursion no;
  allow-query { any; };
  allow-transfer { none; };
  dnssec-validation no;
  version "none";
  minimal-responses yes;
};
BIND
echo '// Managed by Havoc WEB Panel' > /etc/bind/havoc/zones.conf; chown root:bind /etc/bind/havoc/zones.conf; chmod 640 /etc/bind/havoc/zones.conf
grep -q 'havoc/zones.conf' /etc/bind/named.conf.local || echo 'include "/etc/bind/havoc/zones.conf";' >> /etc/bind/named.conf.local
systemctl enable named 2>/dev/null; systemctl restart named || echo "WARN: BIND did not start (is port 53 in use, e.g. by systemd-resolved?)"
command -v ufw >/dev/null && { ufw allow 53/tcp; ufw allow 53/udp; } || true

log "Log rotation + certificate renewal hook"
cat > /etc/logrotate.d/havoc <<LR
/var/log/havoc/waf/*.log {
  weekly
  rotate 8
  compress
  delaycompress
  missingok
  notifempty
  postrotate
    systemctl reload nginx >/dev/null 2>&1 || true
  endscript
}
/var/log/havoc/domains/*.log {
  daily
  rotate 14
  compress
  delaycompress
  missingok
  notifempty
  sharedscripts
  postrotate
    systemctl reload nginx >/dev/null 2>&1 || /usr/local/lsws/bin/lswsctrl reload >/dev/null 2>&1 || true
  endscript
}
LR
install -d /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx 2>/dev/null || /usr/local/lsws/bin/lswsctrl reload 2>/dev/null || true\n' > /etc/letsencrypt/renewal-hooks/deploy/havoc
chmod +x /etc/letsencrypt/renewal-hooks/deploy/havoc

log "Database and admin account"
runuser -u havoc -- php /opt/havoc/bin/havoc migrate
CREDS=$(runuser -u havoc -- php /opt/havoc/bin/havoc admin:create admin "admin@$(hostname -f)")

systemctl daemon-reload
systemctl enable --now havoc-panel-fpm havoc-panel-nginx havoc-backup.timer havoc-usage.timer

echo nginx > /etc/havoc/webserver
if [ "$WEBSERVER" != nginx ]; then
  log "Installing and activating $WEBSERVER for the websites"
  if /opt/havoc/bin/havoc-ws-install "$WEBSERVER" "${HAVOC_LSWS_LICENSE:-TRIAL}" \
     && echo "{\"target\":\"$WEBSERVER\"}" | /opt/havoc/ctl/havocctl webserver.prepare | grep -q '"ok":true' \
     && echo "{\"target\":\"$WEBSERVER\"}" | /opt/havoc/ctl/havocctl webserver.activate | grep -q '"ok":true'; then
    echo "$WEBSERVER is serving the websites."
  else
    echo "WARN: $WEBSERVER could not be installed/activated; Nginx keeps serving the websites. Retry under Server > Web server."
  fi
fi
command -v ufw >/dev/null && ufw allow "$PORT"/tcp || true

IP=$(hostname -I | awk '{print $1}')
echo -e "\n\033[1;32mHavoc WEB Panel is ready.\033[0m"
echo "  URL:      https://$IP:$PORT  (self-signed certificate)"
echo "$CREDS" | sed 's/^/  /'
echo "  Web server: $(cat /etc/havoc/webserver)   |   Change the admin password and enable 2FA after first login."
