SERVER MONITOR - PROJECT INTRODUCTION AND COMPLETE FLOW
========================================================

1. WHAT THIS PROJECT DOES

Server Monitor is a PHP-based uptime and internet monitoring application.

It has two execution paths:
1. Apache serves index.php as a browser dashboard.
2. systemd runs scripts/server_check_daemon.php as a background process.

The daemon checks internet connectivity, records uptime in MySQL/MariaDB, writes status and log files, and sends SMTP notifications and daily summaries.

2. COMPLETE RUNTIME FLOW

Browser request -> Apache -> index.php -> database.php -> MySQL/MariaDB -> dashboard response

Server boot -> systemd -> servercheck.service -> server_check_daemon.php -> ping/DNS check -> logger.php -> database + logs + status files -> mailer.php -> SMTP server

The application is not a normal request-only PHP site. The dashboard is web-facing, while monitoring continues through the systemd daemon even when no browser is open.

3. SOURCE TREE AND RESPONSIBILITY

project/
  index.php                         Browser dashboard and manual actions
  includes/database.php             MySQL/MariaDB connection and queries
  includes/logger.php               Database updates and file logging
  includes/mailer.php               Direct SMTP client and email reports
  scripts/server_check.php          One-time connectivity check
  scripts/server_check_daemon.php   Long-running systemd process
  scripts/daily_summary.php         Manual daily summary command
  scripts/uptime_updater.php        Uptime update command
  config/database.json              Database credentials; sensitive
  config/smtp.json                  SMTP credentials; sensitive
  config/sendmail.json              Recipient and message settings
  logs/                             Generated logs and status files
  README.md                         Project notes

4. DASHBOARD FLOW

1. A browser requests index.php through Apache.
2. index.php loads includes/database.php.
3. The database class reads config/database.json.
4. A MySQL/MariaDB connection is opened with mysqli.
5. Today's row and the last 30 days of history are loaded from the server table.
6. logs/last_status.json supplies the latest live status.
7. The dashboard calculates uptime percentages and renders the page.
8. Manual email actions use includes/mailer.php and the SMTP/sendmail JSON files.

The dashboard does not perform the continuous monitoring loop. That work belongs to the systemd daemon.

5. MONITORING DAEMON FLOW

servercheck.service starts /usr/bin/php scripts/server_check_daemon.php as the www-data user.

At startup the daemon:
1. Loads the logger and mailer.
2. Creates missing log directories when possible.
3. Sends a startup notification email.
4. Checks internet connectivity.
5. Saves current state in logs/last_status.json.
6. Records initial system and internet status.

During its loop, approximately once per minute, it:
1. Checks internet connectivity using ping.
2. Falls back to a socket connection to DNS port 53.
3. Detects status changes.
4. Writes daily log entries.
5. Updates database counters and uptime values.
6. Runs uptime_updater.php.
7. Sends the previous day's summary around midnight, with an hourly catch-up window.
8. Sleeps briefly and repeats.

The daemon must stay active. Restart=always in systemd brings it back after a crash or reboot.

6. DATA FLOW

DATABASE
includes/database.php uses prepared mysqli statements against servercheck.server. It creates a row for the current date and stores uptime, offline time, reboot, shutdown, and internet-offline counters.

The database has one main table, server, with one unique row per date. Its primary key is an auto-increment INT named id; date has a unique index; uptime and offline measurements use nullable TIME columns; event counters use INT defaults of zero; and updated_at records the last automatic update timestamp. The table uses InnoDB with utf8mb4_general_ci collation.

FILES
- logs/last_status.json: latest system and internet status.
- logs/last_summary_date.txt: date of the last daily summary.
- logs/service.log: daemon activity log.
- logs/service_error.log: service-related errors when configured.
- logs/YYYY-MM-DD/server.log: daily status events.

EMAIL
includes/mailer.php opens a direct SMTP socket, supports TLS or SSL, authenticates, and sends startup notifications, online/offline messages, daily summary reports, and manual dashboard email actions.

7. DEPLOYMENT FLOW

1. Install Apache, PHP, PHP CLI, mysqli, MariaDB/MySQL, iputils-ping, and iproute2.
2. Clone the project to /var/www/html/project/servercheck.
3. Create the servercheck database, user, and server table.
4. Create server-only JSON configuration files in config/.
5. Set source ownership and make logs/ writable by www-data.
6. Point Apache DocumentRoot to the application directory.
7. Create /etc/systemd/system/servercheck.service.
8. Run systemctl daemon-reload.
9. Enable and start the service.
10. Check PHP syntax, Apache logs, application logs, and journalctl.
11. Open the dashboard and test email delivery.

Detailed commands are in How to deploy/README.md and software requirements are in Software Req/README.md.

8. WHAT RUNS WHERE

Apache web request:        /var/www/html/project/servercheck/index.php
PHP background process:    /var/www/html/project/servercheck/scripts/server_check_daemon.php
systemd unit:              /etc/systemd/system/servercheck.service
Apache site config:        /etc/apache2/sites-available/servercheck.conf
Database files:            /var/lib/mysql/
Application configuration: project/config/*.json
Application logs:          project/logs/
Service logs:              journalctl -u servercheck

9. DAILY OPERATIONS

sudo systemctl status servercheck --no-pager
sudo systemctl is-active servercheck
sudo journalctl -u servercheck -f
sudo systemctl restart servercheck
sudo -u www-data php scripts/server_check.php
sudo -u www-data php scripts/daily_summary.php 2026-09-19

10. SECURITY RULES

- Never commit real JSON credentials.
- Keep database and SMTP passwords out of screenshots and chat.
- Use a dedicated database user.
- Use an SMTP app password.
- Run the daemon as www-data, not root.
- Give write permission to logs/ only.
- Use HTTPS and restrict dashboard access.
- Rotate credentials after exposure.
- Back up the database separately from generated logs.
