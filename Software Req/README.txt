SERVER MONITOR - SOFTWARE REQUIREMENTS AND INSTALLATION GUIDE
================================================================

1. PROJECT OVERVIEW

Server Monitor is a PHP application with:
- An Apache web dashboard.
- A long-running PHP daemon managed by systemd.
- Internet checks using ping and a TCP/DNS socket fallback.
- MySQL or MariaDB storage for uptime statistics.
- File and database logging.
- SMTP alerts and daily summary emails.

There is no Composer, Node.js, npm, or third-party PHP mail dependency. SMTP is implemented with PHP socket functions.

2. RECOMMENDED SERVER

- Ubuntu Server 22.04 LTS or newer
- Apache 2.4+
- PHP 8.1+; PHP 8.5 recommended when available
- MySQL 8+ or MariaDB 10.6+
- systemd
- Outbound DNS/network and SMTP access
- Asia/Kolkata timezone, or the deployment timezone
- Git is optional for source download and updates

3. REQUIRED PHP FEATURES

Required PHP components:
- mysqli: MySQL/MariaDB access and prepared statements
- json: configuration and status files
- session: dashboard actions
- openssl: TLS/SSL SMTP
- stream networking/sockets: SMTP and connectivity tests
- filter: email validation
- ctype: time validation
- CLI and filesystem support: daemon, scripts, and logs

Required Linux commands:
- ping from iputils-ping
- ip from iproute2
- hostname and grep from the base system

4. INSTALL UBUNTU PACKAGES

sudo apt update
sudo apt install -y apache2 php php-cli php-mysql php-curl php-mbstring php-xml php-zip php-opcache php-common mariadb-server iputils-ping iproute2 git
sudo systemctl enable --now apache2
sudo systemctl enable --now mariadb

Verify:

apache2 -v
php -v
php -m | grep -E 'mysqli|json|openssl|session|filter|ctype'
mysql --version
systemctl --version
ping -c 1 8.8.8.8
ip route show

php-mysql provides mysqli. json, openssl, session, filter, ctype, and stream support are normally included in Ubuntu PHP packages.

5. APACHE SETUP

Recommended path:
/var/www/html/project/servercheck

Copy the application:

sudo mkdir -p /var/www/html/project
sudo cp -a project /var/www/html/project/servercheck
sudo chown -R root:www-data /var/www/html/project/servercheck
sudo find /var/www/html/project/servercheck -type d -exec chmod 755 {} \;
sudo find /var/www/html/project/servercheck -type f -exec chmod 644 {} \;
sudo chmod -R 775 /var/www/html/project/servercheck/logs

Create /etc/apache2/sites-available/servercheck.conf:

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

Enable and test:

sudo a2enmod php*
sudo a2ensite servercheck.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
sudo a2dissite 000-default.conf
sudo systemctl reload apache2

Use HTTPS in production. Add Certbot after a public DNS name is configured.

6. DATABASE SETUP

sudo mariadb

CREATE DATABASE servercheck CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'servercheck_user'@'localhost' IDENTIFIED BY 'replace-with-a-long-database-password';
GRANT ALL PRIVILEGES ON servercheck.* TO 'servercheck_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;

Required table:

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
    PRIMARY KEY (id), UNIQUE KEY date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

Configure config/database.json:

{
    "host": "localhost",
    "username": "servercheck_user",
    "password": "replace-with-a-long-database-password",
    "database": "servercheck"
}

Do not commit real credentials.

7. SMTP SETUP

Configure config/smtp.json:

{
    "host": "smtp.example.com",
    "username": "monitor@example.com",
    "password": "replace-with-an-smtp-password-or-app-password",
    "port": 587,
    "secure": "tls",
    "from_email": "monitor@example.com",
    "from_name": "Server Monitor"
}

Configure config/sendmail.json:

{
    "to_email": "admin@example.com",
    "to_name": "Administrator",
    "subject": "Server Status Update",
    "online_message": "System is ONLINE and running properly.",
    "offline_message": "System is OFFLINE. Please check the system."
}

Supported secure values: tls, ssl, none, or empty. Port 587 is common for STARTTLS; 465 is common for implicit SSL. Use an app password instead of a primary mailbox password.

8. SYSTEMD DAEMON

Create /etc/systemd/system/servercheck.service:

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

Enable and inspect:

sudo systemctl daemon-reload
sudo systemctl enable --now servercheck
sudo systemctl status servercheck
sudo journalctl -u servercheck -f
sudo systemctl restart servercheck

The daemon checks every minute, updates uptime, and sends daily summaries. Do not add duplicate cron jobs for those functions.

9. TESTING

Syntax check all PHP files:
find /var/www/html/project/servercheck -name '*.php' -print0 | xargs -0 -n1 php -l

Open the dashboard:
http://your-server-name.example.com/

Run one-time check:
sudo -u www-data php /var/www/html/project/servercheck/scripts/server_check.php

Run a summary:
sudo -u www-data php /var/www/html/project/servercheck/scripts/daily_summary.php 2026-09-19

Run uptime update:
sudo -u www-data php /var/www/html/project/servercheck/scripts/uptime_updater.php

Inspect logs:
sudo find /var/www/html/project/servercheck/logs -maxdepth 2 -type f -print
sudo tail -f /var/www/html/project/servercheck/logs/service.log
sudo tail -f /var/www/html/project/servercheck/logs/service_error.log

10. SECURITY

- Keep config/database.json, config/smtp.json, and config/sendmail.json out of Git.
- Keep logs and generated status files out of Git.
- Use a dedicated database user with access only to servercheck.
- Use an SMTP app password.
- Allow write access only to logs.
- Use HTTPS and restrict dashboard access.
- Rotate any credential that was exposed.
- Review systemd and application logs after deployment.
- Back up the database separately from generated logs.

11. PROJECT PATHS

index.php                         Web dashboard
includes/database.php             MySQL/MariaDB access
includes/logger.php               Database and file logging
includes/mailer.php               Direct SMTP mailer
scripts/server_check.php          One-time check
scripts/server_check_daemon.php   Long-running daemon
scripts/daily_summary.php         Daily summary command
scripts/uptime_updater.php        Uptime command
config/database.json              Database settings, sensitive
config/smtp.json                  SMTP settings, sensitive
config/sendmail.json              Recipient settings, sensitive
logs/                             Generated logs and status files
