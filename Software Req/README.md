# Server Monitor - Software Requirements and Installation Guide

## 1. Project Overview

Server Monitor is a PHP application that provides:

- A web dashboard served by Apache.
- A long-running PHP monitoring daemon managed by systemd.
- Internet connectivity checks using `ping` and a DNS socket fallback.
- MySQL or MariaDB storage for uptime and status statistics.
- File and database logging.
- SMTP email alerts and daily summary emails.
- Current status files and historical log files.

The application does not use Composer, Node.js, npm, or a third-party PHP mail library. SMTP is implemented directly with PHP socket functions.

## 2. Recommended Server Environment

- Operating system: Ubuntu Server 22.04 LTS or newer.
- Web server: Apache 2.4 or newer.
- PHP: PHP 8.1 or newer. PHP 8.5 is recommended when available.
- Database: MySQL 8.0+ or MariaDB 10.6+.
- Service manager: systemd.
- Network: outbound DNS, ICMP or TCP/53 connectivity, and outbound SMTP access.
- Timezone: Asia/Kolkata, or another timezone selected for the deployment.
- Git: optional, only for downloading and updating source code.

## 3. Required PHP Extensions and Features

Install or enable these PHP components:

- `mysqli` - MySQL/MariaDB connection and prepared statements.
- `json` - configuration and status JSON files.
- `session` - dashboard action handling.
- `openssl` - TLS/SSL SMTP connections.
- `sockets` or PHP stream networking - SMTP and connectivity checks.
- `filter` - email address validation.
- `ctype` - time value validation.
- `fileinfo` and standard filesystem support - log and status files.
- PHP CLI - required by the daemon and command-line scripts.

The following Linux commands are used by the application and must be installed:

- `ping`, normally provided by `iputils-ping`.
- `ip`, normally provided by `iproute2`.
- `hostname`, normally provided by the base Ubuntu system.
- `grep`, used by a fallback IP detection command.

## 4. Install Software on Ubuntu

Update package information:

```bash
sudo apt update
```

Install Apache, PHP, the required PHP modules, database server, and system utilities:

```bash
sudo apt install -y apache2 php php-cli php-mysql php-curl php-mbstring php-xml php-zip php-opcache php-common mariadb-server iputils-ping iproute2 git
```

`php-mysql` provides the `mysqli` extension. `openssl`, `json`, `session`, `filter`, `ctype`, and stream support are normally included with the standard Ubuntu PHP packages.

Enable and start the services:

```bash
sudo systemctl enable --now apache2
sudo systemctl enable --now mariadb
```

Check the installed versions:

```bash
apache2 -v
php -v
php -m | grep -E 'mysqli|json|openssl|session|filter|ctype'
mysql --version
systemctl --version
ping -c 1 8.8.8.8
ip route show
```

## 5. Apache Configuration

Recommended application location:

```text
/var/www/html/project/servercheck
```

Copy the project there, or clone it into the parent directory:

```bash
sudo mkdir -p /var/www/html/project
sudo cp -a project /var/www/html/project/servercheck
```

Set ownership and safe directory permissions. The web server user must be able to read PHP files and write the `logs` directory:

```bash
sudo chown -R root:www-data /var/www/html/project/servercheck
sudo find /var/www/html/project/servercheck -type d -exec chmod 755 {} \;
sudo find /var/www/html/project/servercheck -type f -exec chmod 644 {} \;
sudo chmod -R 775 /var/www/html/project/servercheck/logs
```

For Apache, the document root can point to the project directory. A simple virtual host is:

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

Save it as `/etc/apache2/sites-available/servercheck.conf`, then enable the site and PHP module:

```bash
sudo a2enmod php*
sudo a2ensite servercheck.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

If another default site is not needed, disable it:

```bash
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

Use HTTPS in production. Certbot can be installed separately when a public DNS name is available.

## 6. Database Setup

Create a database and a dedicated database user. Replace the example values with real deployment values:

```bash
sudo mariadb
```

Run:

```sql
CREATE DATABASE servercheck CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'servercheck_user'@'localhost' IDENTIFIED BY 'replace-with-a-long-database-password';
GRANT ALL PRIVILEGES ON servercheck.* TO 'servercheck_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Create the table required by `includes/database.php`:

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

Configure `/var/www/html/project/servercheck/config/database.json`:

```json
{
    "host": "localhost",
    "username": "servercheck_user",
    "password": "replace-with-a-long-database-password",
    "database": "servercheck"
}
```

Commit only the placeholder values. Replace them with real credentials on the server and never commit those real values.

## 7. SMTP Configuration

The application sends mail directly through an SMTP server using `fsockopen()` and TLS/SSL sockets. Configure `config/smtp.json`:

```json
{
    "host": "smtp.example.com",
    "username": "monitor@example.com",
    "password": "replace-with-an-smtp-password-or-app-password",
    "port": 587,
    "secure": "tls",
    "from_email": "monitor@example.com",
    "from_name": "Server Monitor"
}
```

Supported security values are `tls`, `ssl`, `none`, or an empty value. Common ports are 587 for STARTTLS and 465 for implicit SSL.

Configure `config/sendmail.json` for the alert recipient and message templates:

```json
{
    "to_email": "admin@example.com",
    "to_name": "Administrator",
    "subject": "Server Status Update",
    "online_message": "System is ONLINE and running properly.",
    "offline_message": "System is OFFLINE. Please check the system."
}
```

Use an SMTP app password where the provider supports it. Never place a personal mailbox password in source control.

## 8. systemd Daemon Service

The primary background process is `scripts/server_check_daemon.php`. It checks internet status, updates uptime, writes status files, logs events, and sends startup and daily summary emails.

Create `/etc/systemd/system/servercheck.service`:

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

Enable and start the daemon:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now servercheck
sudo systemctl status servercheck
```

View live logs:

```bash
sudo journalctl -u servercheck -f
```

Restart after application changes:

```bash
sudo systemctl restart servercheck
```

The daemon already performs checks every minute and runs the uptime updater. Do not create a second cron job for the same functions unless the design is intentionally changed.

## 9. Manual Commands and Testing

Validate PHP syntax:

```bash
find /var/www/html/project/servercheck -name '*.php' -print0 | xargs -0 -n1 php -l
```

Test the database configuration and dashboard from a browser:

```text
http://your-server-name.example.com/
```

Run a one-time server check:

```bash
sudo -u www-data php /var/www/html/project/servercheck/scripts/server_check.php
```

Run a daily summary for a specific date:

```bash
sudo -u www-data php /var/www/html/project/servercheck/scripts/daily_summary.php 2026-09-19
```

Run the uptime updater once:

```bash
sudo -u www-data php /var/www/html/project/servercheck/scripts/uptime_updater.php
```

Check application logs:

```bash
sudo find /var/www/html/project/servercheck/logs -type f -maxdepth 2 -print
sudo tail -f /var/www/html/project/servercheck/logs/service.log
sudo tail -f /var/www/html/project/servercheck/logs/service_error.log
```

## 10. Security and Maintenance

- Keep `config/database.json`, `config/smtp.json`, and `config/sendmail.json` out of Git.
- Keep generated logs and status files out of Git.
- Use a dedicated database user with access only to the `servercheck` database.
- Use an SMTP app password, not a primary account password.
- Restrict write permission to `logs` only.
- Use HTTPS and restrict access to the dashboard when it contains private monitoring data.
- Rotate credentials if they have ever been exposed.
- Review `journalctl -u servercheck` and application logs after deployment.
- Back up the database separately from generated log files.

## 11. Important Project Paths

```text
index.php                         Web dashboard
includes/database.php             MySQL/MariaDB access
includes/logger.php               Database and file logging
includes/mailer.php               Direct SMTP mailer
scripts/server_check.php          One-time server check
scripts/server_check_daemon.php   Long-running systemd daemon
scripts/daily_summary.php         Daily summary command
scripts/uptime_updater.php        Uptime update command
config/database.json              Database settings, sensitive
config/smtp.json                  SMTP settings, sensitive
config/sendmail.json              Recipient and message settings
logs/                             Generated status and log files
```
