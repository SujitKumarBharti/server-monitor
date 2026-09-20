#!/usr/bin/env php
<?php
// Main server check script
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/mailer.php';

class ServerChecker {
    private $logger;
    private $mailer;
    private $lastStatus = null;
    private $statusFile = __DIR__ . '/../logs/last_status.json';
    
    public function __construct() {
        $this->logger = new Logger();
        try {
            $this->mailer = new Mailer();
        } catch (Exception $e) {
            $this->logger->log("Mailer initialization failed: " . $e->getMessage(), 'ERROR');
        }
        $this->loadLastStatus();
    }
    
    private function loadLastStatus() {
        if (file_exists($this->statusFile)) {
            $data = json_decode(@file_get_contents($this->statusFile), true);
            if ($data) {
                $this->lastStatus = $data['status'];
            }
        }
    }
    
    private function saveLastStatus($status, $internetStatus) {
        $data = [
            'status' => $status,
            'timestamp' => date('d-m-Y h:i:s A'),
            'internet_status' => $internetStatus,
            'internet_timestamp' => date('d-m-Y h:i:s A')
        ];
        @file_put_contents($this->statusFile, json_encode($data));
    }
    
    public function checkInternet() {
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
    
    public function run() {
        $internetOnline = $this->checkInternet();
        $internetStatus = $internetOnline ? 'ONLINE' : 'OFFLINE';
        
        if ($internetOnline) {
            $this->logger->logInternetOnline('INTERNET ONLINE - Internet connection available');
        } else {
            $this->logger->logInternetOffline('INTERNET OFFLINE - No internet connection');
        }
        
        $this->saveLastStatus('ONLINE', $internetStatus);
        
        return true;
    }
}

$checker = new ServerChecker();
$result = $checker->run();
echo date('d-m-Y h:i:s A') . " - Server check completed. Status: ONLINE" . PHP_EOL;
exit(0);
?>