#!/usr/bin/env php
<?php
// Daily summary report script - Database based
// Set timezone
date_default_timezone_set('Asia/Kolkata');

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/mailer.php';

class DailySummary {
    private $logger;
    private $mailer;
    
    public function __construct() {
        $this->logger = new Logger();
        
        try {
            $this->mailer = new Mailer();
        } catch (Exception $e) {
            $this->logger->log("Mailer initialization failed: " . $e->getMessage(), 'ERROR');
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
    
    public function generateReport($date = null) {
        if (!$date) {
            $date = date('Y-m-d', strtotime('-1 day'));
        }
        
        $this->logger->log("Daily summary requested for $date", 'INFO');
        echo date('d-m-Y h:i:s A') . " - Generating summary for $date\n";
        
        if (!$this->mailer) {
            $this->logger->log("Mailer not available!", 'ERROR');
            echo "❌ Mailer not available!\n";
            return false;
        }
        
        // Send email using mailer
        try {
            $sent = $this->mailer->sendDailySummary($date);
            
            if ($sent) {
                $this->logger->log("Daily summary email sent for $date", 'INFO');
                echo date('d-m-Y h:i:s A') . " - ✅ Daily summary sent for $date\n";
                return true;
            } else {
                $this->logger->log("Failed to send daily summary email for $date", 'ERROR');
                echo date('d-m-Y h:i:s A') . " - ❌ Failed to send daily summary for $date\n";
                return false;
            }
        } catch (Exception $e) {
            $this->logger->log("Exception sending email: " . $e->getMessage(), 'ERROR');
            echo "❌ Exception: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

$date = $argv[1] ?? null;
$summary = new DailySummary();
$result = $summary->generateReport($date);
exit($result ? 0 : 1);
?>