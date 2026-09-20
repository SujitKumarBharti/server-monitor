<?php
// Database connection class
// Set timezone
date_default_timezone_set('Asia/Kolkata');

class Database {
    private static $instance = null;
    private $conn;
    private $config;
    
    private function __construct() {
        $configFile = __DIR__ . '/../config/database.json';
        
        if (!file_exists($configFile)) {
            die("Database config file not found: $configFile");
        }
        
        $this->config = json_decode(file_get_contents($configFile), true);
        
        if (!$this->config) {
            die("Invalid database configuration!");
        }
        
        try {
            $this->conn = new mysqli(
                $this->config['host'],
                $this->config['username'],
                $this->config['password'],
                $this->config['database']
            );
            
            if ($this->conn->connect_error) {
                die("Connection failed: " . $this->conn->connect_error);
            }
            
            $this->conn->set_charset("utf8mb4");
        } catch (Exception $e) {
            die("Database connection error: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    // Get today's data
    public function getTodayData() {
        $today = date('Y-m-d');
        $stmt = $this->conn->prepare("SELECT * FROM server WHERE date = ?");
        $stmt->bind_param("s", $today);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        // Create new row for today
        return $this->createTodayRow();
    }
    
    // Create today's row
    public function createTodayRow() {
        $today = date('Y-m-d');
        $stmt = $this->conn->prepare("INSERT INTO server (date) VALUES (?)");
        $stmt->bind_param("s", $today);
        
        if ($stmt->execute()) {
            return $this->getTodayData();
        }
        return null;
    }
    
    // Update today's data
    public function updateToday($data) {
        $today = date('Y-m-d');
        
        $sql = "UPDATE server SET 
                current_uptime = ?,
                total_uptime = ?,
                current_internet_uptime = ?,
                total_internet_uptime = ?,
                total_uptime_offline = ?,
                total_internet_offline = ?,
                pc_on_count = ?,
                pc_off_count = ?,
                reboot_count = ?,
                shutdown_count = ?,
                internet_offline_count = ?
                WHERE date = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "ssssssiiiiis",
            $data['current_uptime'],
            $data['total_uptime'],
            $data['current_internet_uptime'],
            $data['total_internet_uptime'],
            $data['total_uptime_offline'],
            $data['total_internet_offline'],
            $data['pc_on_count'],
            $data['pc_off_count'],
            $data['reboot_count'],
            $data['shutdown_count'],
            $data['internet_offline_count'],
            $today
        );
        
        return $stmt->execute();
    }
    
    // Get history (last 30 days)
    public function getHistory($limit = 30) {
        $stmt = $this->conn->prepare("SELECT * FROM server ORDER BY date DESC LIMIT ?");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        return $data;
    }
    
    // Get data for a specific date
    public function getDataForDate($date) {
        $stmt = $this->conn->prepare("SELECT * FROM server WHERE date = ?");
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return null;
    }
    
    // Get all dates
    public function getAllDates() {
        $stmt = $this->conn->prepare("SELECT date FROM server ORDER BY date DESC");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $dates = [];
        while ($row = $result->fetch_assoc()) {
            $dates[] = $row['date'];
        }
        return $dates;
    }
    
    // Close connection
    public function close() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}
?>