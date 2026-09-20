# Server Monitor Deployment Guide

This guide deploys the PHP Server Monitor project on an Ubuntu server with Apache, PHP, MariaDB/MySQL, and systemd.

## 1. Deployment Architecture

After deployment, the important paths are:

```text
/var/www/html/project/servercheck/       Application source and web root
/var/www/html/project/servercheck/config/Runtime JSON configuration; keep credentials here, not in Git
/var/www/html/project/servercheck/logs/   Application logs and generated status files
/etc/apache2/sites-available/servercheck.conf
                                         Apache virtual-host configuration
/etc/systemd/system/servercheck.service  Long-running PHP daemon definition
/var/lib/mysql/                          MariaDB/MySQL database files
/var/log/apache2/                        Apache access and error logs
```

The application has two runtime parts:

1. Apache loads `index.php` and serves the monitoring dashboard.
2. systemd runs `scripts/server_check_daemon.php` continuously in the background.

The daemon checks connectivity every minute, updates uptime data, writes logs/status files, and sends daily summaries. `cron` is not required for these tasks.

## 2. Required Software

Install the following on Ubuntu Server 22.04 LTS or newer:

- Apache 2.4+
- PHP 8.1+ with CLI and `mysqli`
- PHP JSON, OpenSSL, session, filter, ctype, and filesystem/stream support
- MariaDB 10.6+ or MySQL 8+
- `iputils-ping` for connectivity checks
- `iproute2` for local IP detection
- systemd, included with Ubuntu
- Git, only if deploying from the GitHub repository

Install packages:

```bash
sudo apt update
sudo apt install -y apache2 php php-cli php-mysql php-curl php-mbstring php-xml php-zip php-opcache php-common mariadb-server iputils-ping iproute2 git
```

Enable the base services:

```bash
sudo systemctl enable --now apache2
sudo systemctl enable --now mariadb
```

Verify the runtime:

```bash
php -v
php -m | grep -E 'mysqli|json|openssl|session|filter|ctype'
mysql --version
apache2ctl -v
ping -c 1 8.8.8.8
ip route show
```

## 3. Download the Application

Create the parent directory and clone the repository:

```bash
sudo mkdir -p /var/www/html/project
sudo chown "$USER":"$USER" /var/www/html/project
cd /var/www/html/project
git clone -b main https://github.com/SujitKumarBharti/server-monitor.git servercheck
cd /var/www/html/project/servercheck
```

If the directory already exists, update it instead of cloning again:

```bash
cd /var/www/html/project/servercheck
git pull origin main
```

Do not copy the remote server's `.git` directory into another Git repository. The application directory should have one Git history only.

## 4. Create the Database

Open MariaDB/MySQL:

```bash
sudo mariadb
```

Create a dedicated database user:

```sql
CREATE DATABASE servercheck CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'servercheck_user'@'localhost' IDENTIFIED BY 'replace-with-a-long-database-password';
GRANT ALL PRIVILEGES ON servercheck.* TO 'servercheck_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Create the table used by `includes/database.php`:

```sql
CREATE TABLE server (
    id INT NOT NULL AUTO_INCREMENT,
    date DATE NOT NULL,
    current_uptime TIME DEFAULT NULL,
    total_uptime TIME DEFAULT NULL,
    current_internet_uptime TIME DEFAULT NULL,
    total_internet_uptime TIME DEFAULT NULL,
    total_uptime_offline TIME DEFAULT NULL,
    total_internet_offline TIME DEFAULT NULL,
    pc_on_count INT NOT NULL DEFAULT 0,
    pc_off_count INT NOT NULL DEFAULT 0,
    reboot_count INT NOT NULL DEFAULT 0,
    shutdown_count INT NOT NULL DEFAULT 0,
    internet_offline_count INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

For a new installation, the SQL above can be run from the MariaDB prompt after selecting the `servercheck` database, or with `USE servercheck;` before the `CREATE TABLE` statement.

## 5. Create Runtime Configuration

The repository contains placeholder JSON files. Keep real credentials server-only and replace the placeholders during deployment:

```bash
cd /var/www/html/project/servercheck
sudo install -d -o www-data -g www-data -m 775 logs
sudo nano config/database.json
sudo nano config/smtp.json
sudo nano config/sendmail.json
```

Use the following shapes and replace every example value with real deployment values.

`config/database.json`:

```json
{
    "host": "localhost",
    "username": "servercheck_user",
    "password": "replace-with-a-long-database-password",
    "database": "servercheck"
}
```

`config/smtp.json`:

```json
{
    "host": "smtp.example.com",
    "username": "monitor@example.com",
    "password": "replace-with-an-smtp-app-password",
    "port": 587,
    "secure": "tls",
    "from_email": "monitor@example.com",
    "from_name": "Server Monitor"
}
```

`config/sendmail.json`:

```json
{
    "to_email": "admin@example.com",
    "to_name": "Administrator",
    "subject": "Server Status Update",
    "online_message": "System is ONLINE and running properly.",
    "offline_message": "System is OFFLINE. Please check the system."
}
```

Validate JSON before starting the service:

```bash
php -r 'foreach (glob("config/*.json") as $file) { json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); echo "$file: OK\n"; }'
```

Never commit real database or SMTP credentials. Use an SMTP app password instead of a primary email password.

## 6. Set Ownership and Permissions

Apache and the systemd daemon will run as `www-data`. The application must be readable, while only generated logs need write access:

```bash
sudo chown -R root:www-data /var/www/html/project/servercheck
sudo find /var/www/html/project/servercheck -type d -exec chmod 755 {} \;
sudo find /var/www/html/project/servercheck -type f -exec chmod 644 {} \;
sudo chown -R www-data:www-data /var/www/html/project/servercheck/logs
sudo chmod -R 775 /var/www/html/project/servercheck/logs
sudo chmod 640 /var/www/html/project/servercheck/config/*.json
```

If the Git working tree must be updated by a non-root deployment user, grant that user controlled ownership of the source tree, then restore the runtime ownership after the update. Do not make the entire web root world-writable.

## 7. Configure Apache

Create the Apache virtual host:

```bash
sudo nano /etc/apache2/sites-available/servercheck.conf
```

Use:

```apache
<VirtualHost *:80>
    ServerName your-server-name.example.com
    DocumentRoot /var/www/html/project/servercheck

    <Directory /var/www/html/project/servercheck>
        AllowOverride AuthConfig FileInfo Indexes Limit
        Options FollowSymLinks
        Require all granted
        DirectoryIndex index.php
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/servercheck-error.log
    CustomLog ${APACHE_LOG_DIR}/servercheck-access.log combined
</VirtualHost>
```

Enable and test the site:

```bash
sudo a2enmod php*
sudo a2ensite servercheck.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

If the default site is not needed:

```bash
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

Use HTTPS and a firewall rule for production. Certbot can be added after DNS points to the server.

## 8. Create the systemd Service

The service file is not stored inside the application repository. It belongs in `/etc/systemd/system/` because systemd reads unit files from that directory.

Create it:

```bash
sudo nano /etc/systemd/system/servercheck.service
```

Contents:

```ini
[Unit]
Description=Server Check Monitoring Service
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/html/project/servercheck
ExecStart=/usr/bin/php /var/www/html/project/servercheck/scripts/server_check_daemon.php
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

What each important setting does:

- `After` starts the daemon after networking and MariaDB are available.
- `User` and `Group` prevent the daemon from running as root.
- `WorkingDirectory` makes relative application paths predictable.
- `ExecStart` runs the long-running PHP daemon, not the web dashboard.
- `Restart=always` brings the monitor back after a crash or reboot.
- `RestartSec=10` waits ten seconds before restarting.
- `StandardOutput` and `StandardError` send daemon output to journald.
- `WantedBy=multi-user.target` enables startup during normal server boot.

Load and start the service:

```bash
sudo systemctl daemon-reload
sudo systemctl enable servercheck
sudo systemctl start servercheck
sudo systemctl status servercheck --no-pager
```

`daemon-reload` is required after creating or editing a unit file. It is not required after ordinary PHP source changes, but restarting the service is required for the running daemon to use those changes.

Useful systemctl commands:

```bash
sudo systemctl status servercheck
sudo systemctl is-enabled servercheck
sudo systemctl is-active servercheck
sudo systemctl restart servercheck
sudo systemctl stop servercheck
sudo systemctl start servercheck
sudo systemctl disable servercheck
sudo systemctl cat servercheck
```

View service logs:

```bash
sudo journalctl -u servercheck -n 100 --no-pager
sudo journalctl -u servercheck -f
sudo journalctl -u servercheck --since today
```

## 9. First Deployment Test

Run a PHP syntax check:

```bash
find /var/www/html/project/servercheck -name '*.php' -print0 | xargs -0 -n1 php -l
```

Run the one-time checker as the service user:

```bash
sudo -u www-data php /var/www/html/project/servercheck/scripts/server_check.php
```

Run the uptime updater once:

```bash
sudo -u www-data php /var/www/html/project/servercheck/scripts/uptime_updater.php
```

Run a daily summary test for a known date:

```bash
sudo -u www-data php /var/www/html/project/servercheck/scripts/daily_summary.php 2026-09-19
```

Check the dashboard in a browser:

```text
http://your-server-name.example.com/
```

Check output from all layers:

```bash
sudo systemctl status servercheck --no-pager
sudo journalctl -u servercheck -n 50 --no-pager
sudo tail -n 50 /var/www/html/project/servercheck/logs/service.log
sudo tail -n 50 /var/log/apache2/servercheck-error.log
```

## 10. Updating an Existing Deployment

Keep runtime config and logs on the server while updating source code:

```bash
cd /var/www/html/project/servercheck
sudo systemctl stop servercheck
git pull origin main
find . -name '*.php' -print0 | xargs -0 -n1 php -l
sudo chown -R root:www-data /var/www/html/project/servercheck
sudo chown -R www-data:www-data /var/www/html/project/servercheck/logs
sudo chmod -R 775 /var/www/html/project/servercheck/logs
sudo systemctl start servercheck
sudo systemctl status servercheck --no-pager
```

If the systemd unit changed, run this before starting:

```bash
sudo systemctl daemon-reload
```

If the Apache virtual host changed, test and reload it:

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Before `git pull`, check for local changes. Do not overwrite server-only config files:

```bash
git status --short
git diff -- config/ logs/
```

## 11. Troubleshooting

### Service is not running

```bash
sudo systemctl status servercheck --no-pager
sudo journalctl -u servercheck -n 100 --no-pager
php -l scripts/server_check_daemon.php
```

Check that `ExecStart`, `WorkingDirectory`, and the `www-data` permissions are correct.

### Database connection fails

```bash
sudo -u www-data cat config/database.json
sudo mariadb -u servercheck_user -p servercheck
```

Check the database name, user, password, host, table, and `mysqli` extension.

### Emails fail

Check SMTP host, port, security mode, username, app password, sender address, recipient address, and outbound firewall rules. Review:

```bash
sudo journalctl -u servercheck -n 100 --no-pager
tail -n 100 logs/service_error.log
```

### Logs are not written

```bash
ls -ld logs
ls -l logs
sudo -u www-data touch logs/write-test && rm logs/write-test
```

The `logs` directory must be writable by `www-data`.

### Apache shows a PHP download or 500 error

```bash
php -v
php -m | grep mysqli
sudo apache2ctl configtest
sudo systemctl status apache2 --no-pager
sudo tail -n 100 /var/log/apache2/servercheck-error.log
```

Ensure the PHP Apache module is installed/enabled and the `DocumentRoot` points to the directory containing `index.php`.

## 12. Operations Summary

```text
Web dashboard:       Apache -> /var/www/html/project/servercheck/index.php
Background monitor:  systemd -> scripts/server_check_daemon.php
Database:             MariaDB/MySQL -> servercheck.server
Config:               project/config/*.json, server-only and sensitive
App logs:             project/logs/
Service unit:         /etc/systemd/system/servercheck.service
Apache site:          /etc/apache2/sites-available/servercheck.conf
Service logs:         journalctl -u servercheck
```
