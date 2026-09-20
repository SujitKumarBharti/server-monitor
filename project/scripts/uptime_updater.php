#!/usr/bin/env php
<?php
// Uptime Updater - Updates current uptime every minute
// Set timezone
date_default_timezone_set('Asia/Kolkata');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../includes/logger.php';

$statusFile = __DIR__ . '/../logs/last_status.json';
$statusData = file_exists($statusFile) ? json_decode(@file_get_contents($statusFile), true) : [];
$systemOnline = ($statusData['status'] ?? 'ONLINE') === 'ONLINE';
$internetOnline = ($statusData['internet_status'] ?? 'ONLINE') === 'ONLINE';

$logger = new Logger();
$logger->updateCurrentUptime($systemOnline, $internetOnline);

echo date('d-m-Y h:i:s A') . " - Uptime updated in database\n";
exit(0);
?>