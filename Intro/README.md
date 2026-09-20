# Server Monitor - Project Introduction and Complete Flow

## 1. What This Project Does

Server Monitor is a PHP-based uptime and internet monitoring application.

It has two execution paths:

1. Apache serves `index.php` as a browser dashboard.
2. systemd runs `scripts/server_check_daemon.php` as a background process.

The daemon checks internet connectivity, records uptime in MySQL/MariaDB, writes status and log files, and sends SMTP notifications and daily summaries.

## 2. Complete Runtime Flow

```text
Browser request
    -> Apache
    -> index.php
    -> includes/database.php
    -> MySQL/MariaDB
    -> dashboard response

Server boot
    -> systemd
    -> servercheck.service
    -> scripts/server_check_daemon.php
    -> ping / DNS socket check
    -> includes/logger.php
    -> database + logs + status files
    -> includes/mailer.php
    -> SMTP server
```

The application is not a normal request-only PHP site. The dashboard is web-facing, while monitoring continues through the systemd daemon even when no browser is open.

## 3. Source Tree and Responsibility

```text
project/
├── index.php                         Browser dashboard and manual actions
├── includes/
│   ├── database.php                  MySQL/MariaDB connection and queries
│   ├── logger.php                    Database updates and file logging
│   └── mailer.php                    Direct SMTP client and email reports
├── scripts/
│   ├── server_check.php              One-time connectivity check
│   ├── server_check_daemon.php       Long-running systemd process
│   ├── daily_summary.php             Manual daily summary command
│   └── uptime_updater.php            Uptime update command
├── config/
│   ├── database.json                 Database credentials; sensitive
│   ├── smtp.json                     SMTP credentials; sensitive
│   └── sendmail.json                 Recipient and message settings
├── logs/                             Generated logs and status files
└── README.md                         Project notes
```

## 4. Dashboard Flow

1. A browser requests `index.php` through Apache.
2. `index.php` loads `includes/database.php`.
3. The database class reads `config/database.json`.
4. A MySQL/MariaDB connection is opened with `mysqli`.
5. Today's row and the last 30 days of history are loaded from the `server` table.
6. `logs/last_status.json` supplies the latest live status.
7. The dashboard calculates uptime percentages and renders the page.
8. Manual email actions use `includes/mailer.php` and the SMTP/sendmail JSON files.

The dashboard does not perform the continuous monitoring loop. That work belongs to the systemd daemon.

## 5. Monitoring Daemon Flow

`servercheck.service` starts `/usr/bin/php scripts/server_check_daemon.php` as the `www-data` user.

At startup the daemon:

1. Loads the logger and mailer.
2. Creates missing log directories when possible.
3. Sends a startup notification email.
4. Checks internet connectivity.
5. Saves the current state in `logs/last_status.json`.
6. Records the initial system and internet status.

During its loop, approximately once per minute, it:

1. Checks internet connectivity using `ping`.
2. Falls back to a socket connection to DNS port 53.
3. Detects status changes.
4. Writes daily log entries.
5. Updates the database counters and uptime values.
6. Runs `uptime_updater.php`.
7. Sends the previous day's summary around midnight, with an hourly catch-up window.
8. Sleeps briefly and repeats.

The daemon must stay active. `Restart=always` in systemd brings it back after a crash or reboot.

## 6. Data Flow

### Database

`includes/database.php` uses prepared `mysqli` statements against the `servercheck.server` table. It creates a row for the current date and stores uptime, offline time, reboot, shutdown, and internet-offline counters.

The database has one main table, `server`, with one unique row per date. Its primary key is an auto-increment `INT` named `id`; `date` has a unique index; uptime and offline measurements use nullable `TIME` columns; event counters use `INT` defaults of zero; and `updated_at` records the last automatic update timestamp. The table uses InnoDB with `utf8mb4_general_ci` collation.

### Files

- `logs/last_status.json`: latest system and internet status.
- `logs/last_summary_date.txt`: date of the last daily summary.
- `logs/service.log`: daemon activity log.
- `logs/service_error.log`: service-related errors when configured.
- `logs/YYYY-MM-DD/server.log`: daily status events.

### Email

`includes/mailer.php` opens a direct SMTP socket, supports TLS or SSL, authenticates, and sends:

- Startup notifications.
- Online/offline status messages.
- Daily summary reports.
- Manual dashboard email actions.

## 7. Deployment Flow

1. Install Apache, PHP, PHP CLI, `mysqli`, MariaDB/MySQL, `iputils-ping`, and `iproute2`.
2. Clone the project to `/var/www/html/project/servercheck`.
3. Create the `servercheck` database, user, and `server` table.
4. Create server-only JSON configuration files in `config/`.
5. Set source ownership and make `logs/` writable by `www-data`.
6. Point Apache `DocumentRoot` to the application directory.
7. Create `/etc/systemd/system/servercheck.service`.
8. Run `systemctl daemon-reload`.
9. Enable and start the service.
10. Check PHP syntax, Apache logs, application logs, and `journalctl`.
11. Open the dashboard and test email delivery.

Detailed commands are in `How to deploy/README.md` and software requirements are in `Software Req/README.md`.

## 8. What Runs Where

```text
Apache web request:       /var/www/html/project/servercheck/index.php
PHP background process:   /var/www/html/project/servercheck/scripts/server_check_daemon.php
systemd unit:             /etc/systemd/system/servercheck.service
Apache site config:       /etc/apache2/sites-available/servercheck.conf
Database files:           /var/lib/mysql/
Application configuration: project/config/*.json
Application logs:         project/logs/
Service logs:             journalctl -u servercheck
```

## 9. Daily Operations

Check service state:

```bash
sudo systemctl status servercheck --no-pager
sudo systemctl is-active servercheck
```

Follow daemon output:

```bash
sudo journalctl -u servercheck -f
```

Restart after PHP source changes:

```bash
sudo systemctl restart servercheck
```

Run a one-time check:

```bash
sudo -u www-data php scripts/server_check.php
```

Run a summary manually:

```bash
sudo -u www-data php scripts/daily_summary.php 2026-09-19
```

## 10. Security Rules

- Never commit real JSON credentials.
- Keep database and SMTP passwords out of screenshots and chat.
- Use a dedicated database user.
- Use an SMTP app password.
- Run the daemon as `www-data`, not root.
- Give write permission to `logs/` only.
- Use HTTPS and restrict dashboard access.
- Rotate credentials after exposure.
- Back up the database separately from generated logs.
