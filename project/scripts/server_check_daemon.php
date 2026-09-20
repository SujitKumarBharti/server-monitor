#!/usr/bin/env php
<?php
// Complete Server Check Daemon - Database Based
// Set timezone
date_default_timezone_set('Asia/Kolkata');

set_time_limit(0);
ini_set('memory_limit', '256M');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Load required files
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/mailer.php';

// Configuration
$baseDir = dirname(__DIR__);
$logFile = $baseDir . '/logs/service.log';
$statusFile = $baseDir . '/logs/last_status.json';
$lastSummaryDate = $baseDir . '/logs/last_summary_date.txt';

// Ensure logs directory exists
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Global objects
$logger = null;
$mailer = null;
$lastSystemStatus = null;
$lastInternetStatus = null;
$firstRun = true;

function daemonLog($message, $type = 'INFO') {
    global $logFile, $logger;
    $timestamp = date('d-m-Y h:i:s A');
    $logEntry = "[$timestamp] [$type] $message" . PHP_EOL;
    
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    echo $logEntry;
    
    if ($logger) {
        $logger->log($message, $type);
    }
}

function checkInternet() {
    $command = "ping -c 1 -W 2 google.com 2>&1";
    $output = shell_exec($command);
    if (strpos($output, '1 packets transmitted, 1 received') !== false) {
        return true;
    }
    
    $fp = @fsockopen('8.8.8.8', 53, $errno, $errstr, 2);
    if ($fp) {
        fclose($fp);
        return true;
    }
    
    return false;
}

function getLastStatus() {
    global $statusFile;
    if (file_exists($statusFile)) {
        $data = json_decode(@file_get_contents($statusFile), true);
        return $data;
    }
    return null;
}

function saveStatus($systemStatus, $internetStatus) {
    global $statusFile;
    $data = [
        'status' => $systemStatus,
        'timestamp' => date('d-m-Y h:i:s A'),
        'internet_status' => $internetStatus,
        'internet_timestamp' => date('d-m-Y h:i:s A')
    ];
    @file_put_contents($statusFile, json_encode($data, JSON_PRETTY_PRINT));
}

function sendStatusEmail($isOnline, $message = '') {
    global $mailer, $logger;
    
    if (!$mailer) {
        daemonLog("Mailer not available!", 'ERROR');
        return false;
    }
    
    try {
        $sent = $mailer->sendServerStatusEmail($isOnline, $message);
        if ($sent) {
            daemonLog("Status email sent successfully: " . ($isOnline ? 'ONLINE' : 'OFFLINE'), 'EMAIL');
            return true;
        } else {
            daemonLog("Failed to send status email", 'ERROR');
            return false;
        }
    } catch (Exception $e) {
        daemonLog("Email error: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

function runUptimeUpdater() {
    $updaterScript = __DIR__ . '/uptime_updater.php';
    if (file_exists($updaterScript)) {
        @shell_exec('/usr/bin/php ' . $updaterScript . ' 2>&1');
    }
}

function getLastSummarySentDate() {
    global $lastSummaryDate;

    if (!file_exists($lastSummaryDate)) {
        return '';
    }

    $value = trim((string) @file_get_contents($lastSummaryDate));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    return '';
}

function runDaemon() {
    global $logger, $mailer, $lastSummaryDate, $lastSystemStatus, $lastInternetStatus, $firstRun;
    
    daemonLog("=== SERVER CHECK DAEMON STARTED ===", 'START');
    daemonLog("PHP Version: " . phpversion(), 'INFO');
    daemonLog("Working Directory: " . __DIR__, 'INFO');
    
    try {
        $logger = new Logger();
        daemonLog("Logger initialized", 'INFO');
    } catch (Exception $e) {
        daemonLog("Logger init failed: " . $e->getMessage(), 'ERROR');
    }
    
    try {
        $mailer = new Mailer();
        daemonLog("Mailer initialized successfully", 'INFO');
        daemonLog("Sending startup notification email...", 'INFO');
        if (sendStatusEmail(true, "Server check daemon started successfully!")) {
            daemonLog("Startup notification email sent successfully", 'EMAIL');
        } else {
            daemonLog("Startup notification email failed", 'ERROR');
        }
    } catch (Exception $e) {
        daemonLog("Mailer init failed: " . $e->getMessage(), 'ERROR');
        daemonLog("Email notifications will not work!", 'ERROR');
    }
    
    $statusData = getLastStatus();
    $lastSystemStatus = $statusData['status'] ?? null;
    $lastInternetStatus = $statusData['internet_status'] ?? null;
    $lastMinute = date('Y-m-d H:i');
    $firstRun = true;
    $checkCounter = 0;
    $lastSummarySent = getLastSummarySentDate();
    $lastUpdaterRun = date('Y-m-d H:i');
    
    $systemStatus = 'ONLINE';
    $internetOnline = checkInternet();
    $internetStatus = $internetOnline ? 'ONLINE' : 'OFFLINE';
    
    if ($logger) {
        $logger->logSystemOnline('SYSTEM ONLINE - PC is running (Daemon started)');
        daemonLog("System is ONLINE", 'SYSTEM');
        
        if ($internetOnline) {
            $logger->logInternetOnline('INTERNET ONLINE - Internet connection available');
            daemonLog("Internet is ONLINE", 'INTERNET');
        } else {
            $logger->logInternetOffline('INTERNET OFFLINE - No internet connection');
            daemonLog("Internet is OFFLINE", 'INTERNET');
        }
        
        $lastSystemStatus = $systemStatus;
        $lastInternetStatus = $internetStatus;
        saveStatus($systemStatus, $internetStatus);
        runUptimeUpdater();
    }
    
    while (true) {
        try {
            $currentMinute = date('Y-m-d H:i');
            $currentHour = date('H');
            $currentMinuteInt = (int)date('i');
            $currentDate = date('Y-m-d');
            
            if ($firstRun || $currentMinute != $lastMinute) {
                $firstRun = false;
                $lastMinute = $currentMinute;
                $checkCounter++;
                
                daemonLog("--- Check #$checkCounter at " . date('h:i:s A') . " ---", 'CHECK');
                
                $systemStatus = 'ONLINE';
                $internetOnline = checkInternet();
                $internetStatus = $internetOnline ? 'ONLINE' : 'OFFLINE';
                
                if ($logger) {
                    if ($internetOnline && $lastInternetStatus == 'OFFLINE') {
                        $logger->logInternetOnline('INTERNET ONLINE - Internet connection available');
                        daemonLog("Internet went ONLINE", 'INTERNET');
                    } elseif (!$internetOnline && $lastInternetStatus == 'ONLINE') {
                        $logger->logInternetOffline('INTERNET OFFLINE - No internet connection');
                        daemonLog("Internet went OFFLINE", 'INTERNET');
                    }
                }
                
                $lastSystemStatus = $systemStatus;
                $lastInternetStatus = $internetStatus;
                saveStatus($systemStatus, $internetStatus);
                
                if ($currentMinute != $lastUpdaterRun) {
                    $lastUpdaterRun = $currentMinute;
                    runUptimeUpdater();
                }
                
                // Daily summary trigger: midnight window plus hourly catch-up if missed.
                $summaryAlreadySentForToday = ($lastSummarySent === $currentDate);
                $inMidnightWindow = ($currentHour === '00' && $currentMinuteInt >= 1 && $currentMinuteInt <= 10);
                $inCatchupWindow = ($currentHour !== '00' && $currentMinuteInt === 0);

                if (!$summaryAlreadySentForToday && ($inMidnightWindow || $inCatchupWindow)) {
                    $yesterday = date('Y-m-d', strtotime('-1 day'));
                    daemonLog("🎯 Sending daily summary for $yesterday", 'SUMMARY');

                    if ($mailer) {
                        $sent = $mailer->sendDailySummary($yesterday);
                        if ($sent) {
                            $lastSummarySent = $currentDate;
                            @file_put_contents($lastSummaryDate, $currentDate);
                            daemonLog("Daily summary sent for $yesterday", 'SUMMARY');
                        } else {
                            daemonLog("Failed to send daily summary for $yesterday (will retry)", 'ERROR');
                        }
                    } else {
                        daemonLog("Mailer not available for daily summary", 'ERROR');
                    }
                }
            }
            
            sleep(10);
            
        } catch (Exception $e) {
            daemonLog("❌ Error in daemon loop: " . $e->getMessage(), 'ERROR');
            sleep(30);
        }
    }
}

try {
    runDaemon();
} catch (Exception $e) {
    daemonLog("Fatal error: " . $e->getMessage(), 'FATAL');
    exit(1);
}
?>