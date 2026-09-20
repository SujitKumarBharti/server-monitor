# Server Monitor

A PHP-based server monitoring dashboard and background service for uptime checks, status logging, email alerts, and daily summaries.

## Components

- index.php: web dashboard
- scripts/server_check.php: server check logic
- scripts/server_check_daemon.php: long-running monitoring daemon
- scripts/daily_summary.php: daily email summary
- scripts/uptime_updater.php: uptime data updater
- includes/: database, logging, and mail helpers

## Configuration

Runtime configuration files are intentionally excluded from version control.

- config/database.json
- config/smtp.json
- config/sendmail.json

Create these files on the server with environment-specific values before running the application. Do not commit credentials, generated logs, or service runtime data.

## Running the service

The production daemon is managed by systemd. The service unit is installed outside this repository.

sudo systemctl status servercheck
sudo systemctl restart servercheck
