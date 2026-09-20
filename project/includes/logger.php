<?php
// Set timezone
date_default_timezone_set('Asia/Kolkata');

// Logger class for servercheck - Database + File backup
class Logger {
    private $logFile;
    private $db;
    private $conn;
    private $todayData;
    
    public function __construct() {
        // File backup
        $logDate = date('Y-m-d');
        $logDir = __DIR__ . '/../logs/' . $logDate;
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/server.log';
        
        // Database connection
        try {
            require_once __DIR__ . '/database.php';
            $this->db = Database::getInstance();
            $this->conn = $this->db->getConnection();
            $this->todayData = $this->db->getTodayData();
        } catch (Exception $e) {
            error_log("Database connection failed: " . $e->getMessage());
            $this->conn = null;
        }
    }
    
    private function timeToSeconds($time) {
        if (!$time) return 0;
        $parts = explode(':', $time);
        if (count($parts) === 3 && ctype_digit(implode('', $parts))) {
            return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
        }
        return 0;
    }
    
    private function secondsToTime($seconds) {
        $seconds = max(0, (int) $seconds);
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
    }
    
    private function addSeconds($time, $addSeconds) {
        $current = $this->timeToSeconds($time);
        $total = $current + $addSeconds;
        return $this->secondsToTime($total);
    }

    private function refreshTodayData() {
        if ($this->db) {
            $this->todayData = $this->db->getTodayData();
        }
    }
    
    private function updateDatabase() {
        if (!$this->conn) return;
        
        try {
            $data = [
                'current_uptime' => $this->todayData['current_uptime'] ?? '00:00:00',
                'total_uptime' => $this->todayData['total_uptime'] ?? '00:00:00',
                'current_internet_uptime' => $this->todayData['current_internet_uptime'] ?? '00:00:00',
                'total_internet_uptime' => $this->todayData['total_internet_uptime'] ?? '00:00:00',
                'total_uptime_offline' => $this->todayData['total_uptime_offline'] ?? '00:00:00',
                'total_internet_offline' => $this->todayData['total_internet_offline'] ?? '00:00:00',
                'pc_on_count' => $this->todayData['pc_on_count'] ?? 0,
                'pc_off_count' => $this->todayData['pc_off_count'] ?? 0,
                'reboot_count' => $this->todayData['reboot_count'] ?? 0,
                'shutdown_count' => $this->todayData['shutdown_count'] ?? 0,
                'internet_offline_count' => $this->todayData['internet_offline_count'] ?? 0
            ];
            
            $this->db->updateToday($data);
        } catch (Exception $e) {
            error_log("Database update failed: " . $e->getMessage());
        }
    }
    
    private function logToFile($message, $status) {
        $timestamp = date('d-m-Y h:i:s A');
        $logEntry = "[$timestamp] [$status] $message" . PHP_EOL;
        @file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    public function log($message, $status = 'INFO') {
        $this->logToFile($message, $status);
    }
    
    // System status logs
    public function logSystemOnline($message = 'SYSTEM ONLINE') {
        $this->logToFile($message, 'ONLINE');
        
        if ($this->conn && $this->todayData) {
            $this->refreshTodayData();
            $this->todayData['current_uptime'] = '00:00:00';
            $this->todayData['pc_on_count'] = ($this->todayData['pc_on_count'] ?? 0) + 1;

            $this->updateDatabase();
        }
    }
    
    public function logSystemOffline($message = 'SYSTEM OFFLINE') {
        $this->logToFile($message, 'OFFLINE');
        
        if ($this->conn && $this->todayData) {
            $this->refreshTodayData();
            $this->todayData['current_uptime'] = '00:00:00';
            $this->todayData['pc_off_count'] = ($this->todayData['pc_off_count'] ?? 0) + 1;

            $this->updateDatabase();
        }
    }
    
    // Internet status logs
    public function logInternetOnline($message = 'INTERNET ONLINE') {
        $this->logToFile($message, 'INTERNET');
        
        if ($this->conn && $this->todayData) {
            $this->refreshTodayData();
            $this->todayData['current_internet_uptime'] = '00:00:00';
            $this->updateDatabase();
        }
    }
    
    public function logInternetOffline($message = 'INTERNET OFFLINE') {
        $this->logToFile($message, 'INTERNET');
        
        if ($this->conn && $this->todayData) {
            $this->refreshTodayData();
            $this->todayData['current_internet_uptime'] = '00:00:00';
            $this->todayData['internet_offline_count'] = ($this->todayData['internet_offline_count'] ?? 0) + 1;

            $this->updateDatabase();
        }
    }
    
    // System events
    public function logReboot($message = 'SYSTEM REBOOT') {
        $this->logToFile($message, 'REBOOT');
        
        if ($this->conn && $this->todayData) {
            $this->refreshTodayData();
            $this->todayData['reboot_count'] = ($this->todayData['reboot_count'] ?? 0) + 1;
            $this->updateDatabase();
        }
    }
    
    public function logShutdown($message = 'SYSTEM SHUTDOWN') {
        $this->logToFile($message, 'SHUTDOWN');
        
        if ($this->conn && $this->todayData) {
            $this->refreshTodayData();
            $this->todayData['shutdown_count'] = ($this->todayData['shutdown_count'] ?? 0) + 1;
            $this->updateDatabase();
        }
    }
    
    public function updateCurrentUptime($systemOnline = true, $internetOnline = true) {
        if ($this->conn && $this->todayData) {
            $this->refreshTodayData();

            if ($systemOnline) {
                $currentSeconds = $this->timeToSeconds($this->todayData['current_uptime'] ?? '00:00:00') + 60;
                $totalSeconds = $this->timeToSeconds($this->todayData['total_uptime'] ?? '00:00:00') + 60;
                $this->todayData['current_uptime'] = $this->secondsToTime($currentSeconds);
                $this->todayData['total_uptime'] = $this->secondsToTime($totalSeconds);
            } else {
                $this->todayData['current_uptime'] = '00:00:00';
                $offlineSeconds = $this->timeToSeconds($this->todayData['total_uptime_offline'] ?? '00:00:00') + 60;
                $this->todayData['total_uptime_offline'] = $this->secondsToTime($offlineSeconds);
            }

            if ($internetOnline) {
                $internetSeconds = $this->timeToSeconds($this->todayData['current_internet_uptime'] ?? '00:00:00') + 60;
                $internetTotalSeconds = $this->timeToSeconds($this->todayData['total_internet_uptime'] ?? '00:00:00') + 60;
                $this->todayData['current_internet_uptime'] = $this->secondsToTime($internetSeconds);
                $this->todayData['total_internet_uptime'] = $this->secondsToTime($internetTotalSeconds);
            } else {
                $this->todayData['current_internet_uptime'] = '00:00:00';
                $internetOfflineSeconds = $this->timeToSeconds($this->todayData['total_internet_offline'] ?? '00:00:00') + 60;
                $this->todayData['total_internet_offline'] = $this->secondsToTime($internetOfflineSeconds);
            }

            $this->updateDatabase();
        }
    }
    
    public function getLastStatus() {
        $logFile = $this->logFile;
        if (!file_exists($logFile)) {
            return null;
        }
        
        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) {
            return null;
        }
        
        $lastLine = end($lines);
        if (preg_match('/^\[(.*?)\] \[(.*?)\] (.*?)$/', $lastLine, $matches)) {
            return [
                'timestamp' => $matches[1],
                'status' => $matches[2],
                'message' => $matches[3]
            ];
        }
        return null;
    }
}
?>