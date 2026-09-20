<?php
// Main Dashboard - Server Check Status
// Set correct timezone
date_default_timezone_set('Asia/Kolkata');

// Fix PCRE JIT for PHP 8.5
if (function_exists('ini_set')) {
    @ini_set('pcre.jit', '0');
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Database connection
require_once __DIR__ . '/includes/database.php';
$db = Database::getInstance();
$todayData = $db->getTodayData();
$history = $db->getHistory(30);
$allDates = $db->getAllDates();

$statusFile = __DIR__ . '/logs/last_status.json';

// ============================================
// FORMAT SECONDS
// ============================================

function formatSeconds($seconds) {
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    $parts = [];
    if ($days > 0) $parts[] = $days . 'd';
    if ($hours > 0) $parts[] = $hours . 'h';
    if ($minutes > 0) $parts[] = $minutes . 'm';
    if ($secs > 0 || empty($parts)) $parts[] = $secs . 's';
    
    return implode(' ', $parts);
}

function timeToSeconds($time) {
    if (!$time) return 0;
    $parts = explode(':', $time);
    if (count($parts) === 3 && ctype_digit(implode('', $parts))) {
        return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
    }
    return 0;
}

function secondsToTime($seconds) {
    $seconds = max(0, (int) $seconds);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
}

function calculateUptimePercentage($onlineSeconds, $offlineSeconds) {
    $measuredSeconds = $onlineSeconds + $offlineSeconds;
    if ($measuredSeconds <= 0) {
        return 100.00;
    }

    return round(($onlineSeconds / $measuredSeconds) * 100, 2);
}

function calculateLiveUptimeMetrics($onlineSeconds, $recordedOfflineSeconds, $elapsedTodaySeconds) {
    $onlineSeconds = max(0, (int) $onlineSeconds);
    $recordedOfflineSeconds = max(0, (int) $recordedOfflineSeconds);
    $elapsedTodaySeconds = max(0, (int) $elapsedTodaySeconds);

    // Use clock-based elapsed time as baseline, but never hide already recorded time.
    $baseSeconds = max($elapsedTodaySeconds, $onlineSeconds + $recordedOfflineSeconds);
    $effectiveOfflineSeconds = max(0, $baseSeconds - $onlineSeconds);

    $percentage = $baseSeconds > 0 ? round(($onlineSeconds / $baseSeconds) * 100, 2) : 100.00;

    return [
        'percentage' => $percentage,
        'offline_seconds' => $effectiveOfflineSeconds,
        'base_seconds' => $baseSeconds
    ];
}

function calculateDailyUptimeMetrics($date, $onlineSeconds, $recordedOfflineSeconds, $elapsedTodaySeconds) {
    $onlineSeconds = max(0, (int) $onlineSeconds);
    $recordedOfflineSeconds = max(0, (int) $recordedOfflineSeconds);
    $elapsedTodaySeconds = max(0, (int) $elapsedTodaySeconds);

    $baseSeconds = max($onlineSeconds + $recordedOfflineSeconds, 1);

    if ($date === date('Y-m-d')) {
        $baseSeconds = max($baseSeconds, $elapsedTodaySeconds);
    } else {
        $baseSeconds = max($baseSeconds, 86400);
    }

    $offlineSeconds = max(0, $baseSeconds - $onlineSeconds);
    $percentage = round(($onlineSeconds / $baseSeconds) * 100, 2);

    return [
        'percentage' => $percentage,
        'offline_seconds' => $offlineSeconds,
        'base_seconds' => $baseSeconds
    ];
}

// ============================================
// OTHER FUNCTIONS
// ============================================

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

function getServerIPs() {
    $ips = [];
    
    $output = shell_exec('ip route show 2>/dev/null');
    if ($output) {
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (preg_match('/src\s+([0-9.]+)/', $line, $matches)) {
                $ip = $matches[1];
                if ($ip != '127.0.0.1' && !in_array($ip, $ips)) {
                    $ips[] = $ip;
                }
            }
        }
    }
    
    if (empty($ips)) {
        $output = shell_exec('ip -4 addr show 2>/dev/null | grep inet | grep -v 127.0.0.1');
        if ($output) {
            preg_match_all('/inet\s+([0-9.]+)\//', $output, $matches);
            if (!empty($matches[1])) {
                $ips = array_merge($ips, $matches[1]);
            }
        }
    }
    
    if (empty($ips)) {
        $output = shell_exec('hostname -I 2>/dev/null');
        if ($output) {
            $ipList = explode(' ', trim($output));
            foreach ($ipList as $ip) {
                if ($ip != '127.0.0.1' && !empty($ip)) {
                    $ips[] = $ip;
                }
            }
        }
    }
    
    $ips = array_unique($ips);
    
    if (empty($ips)) {
        $ips[] = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
    }
    
    return $ips;
}

function getCurrentStatusFromFile($statusFile) {
    if (!file_exists($statusFile)) {
        return ['status' => 'UNKNOWN', 'timestamp' => 'Never', 'internet_status' => 'UNKNOWN'];
    }
    
    $data = json_decode(@file_get_contents($statusFile), true);
    if (!$data) {
        return ['status' => 'UNKNOWN', 'timestamp' => 'Never', 'internet_status' => 'UNKNOWN'];
    }
    
    return [
        'status' => $data['status'] ?? 'UNKNOWN',
        'timestamp' => $data['timestamp'] ?? 'Never',
        'internet_status' => $data['internet_status'] ?? 'UNKNOWN',
        'internet_timestamp' => $data['internet_timestamp'] ?? 'Never'
    ];
}

// ============================================
// HANDLE ACTIONS
// ============================================

if (isset($_POST['action'])) {
    session_start();
    $_SESSION['action'] = $_POST['action'];
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

session_start();
$actionMessage = '';
$actionType = '';
$actionToProcess = $_SESSION['action'] ?? null;
unset($_SESSION['action']);

if ($actionToProcess) {
    require_once __DIR__ . '/includes/logger.php';
    require_once __DIR__ . '/includes/mailer.php';
    
    $logger = new Logger();
    
    try {
        $mailer = new Mailer();
        
        if ($actionToProcess == 'send_summary') {
            $today = date('Y-m-d');
            $sent = $mailer->sendDailySummary($today);
            
            if ($sent) {
                $actionMessage = "✅ Daily summary email sent successfully for today ({$today}).";
                $actionType = 'success';
            } else {
                $actionMessage = "❌ Today's daily summary ({$today}) could not be sent. Review the SMTP error log for the exact reason.";
                $actionType = 'error';
            }
        } elseif ($actionToProcess == 'send_status') {
            $isOnline = checkInternet();
            $sent = $mailer->sendServerStatusEmail($isOnline, "Manual status check requested from dashboard");
            
            if ($sent) {
                $actionMessage = "✅ Status email sent successfully. Current status: " . ($isOnline ? '🟢 ONLINE' : '🔴 OFFLINE');
                $actionType = 'success';
            } else {
                $actionMessage = "❌ Status email could not be sent. Review the SMTP error log for the exact reason.";
                $actionType = 'error';
            }
        }
    } catch (Exception $e) {
        $actionMessage = "❌ Error: " . $e->getMessage();
        $actionType = 'error';
    }
}

// ============================================
// MAIN PAGE DATA
// ============================================

$serverIPs = getServerIPs();
$serverIP = !empty($serverIPs) ? implode(', ', $serverIPs) : '127.0.0.1';

$currentStatusData = getCurrentStatusFromFile($statusFile);
$currentStatus = $currentStatusData['status'];
$lastCheck = $currentStatusData['timestamp'];
$internetStatus = $currentStatusData['internet_status'];
$internetLastCheck = $currentStatusData['internet_timestamp'];

// Current uptime from database
$currentUptimeSeconds = timeToSeconds($todayData['current_uptime'] ?? '00:00:00');
$currentUptimeFormatted = secondsToTime($currentUptimeSeconds);

// Total uptime from database
$totalUptimeSeconds = timeToSeconds($todayData['total_uptime'] ?? '00:00:00');
$totalUptimeFormatted = secondsToTime($totalUptimeSeconds);

$elapsedTodaySeconds = (int) (time() - strtotime('today'));
$elapsedTodaySeconds = max(0, min($elapsedTodaySeconds, 86400));

$recordedOfflineSeconds = timeToSeconds($todayData['total_uptime_offline'] ?? '00:00:00');
$systemUptimeMetrics = calculateLiveUptimeMetrics($totalUptimeSeconds, $recordedOfflineSeconds, $elapsedTodaySeconds);
$offlineSeconds = $systemUptimeMetrics['offline_seconds'];
$percentage = $systemUptimeMetrics['percentage'];
$systemBaseFormatted = secondsToTime($systemUptimeMetrics['base_seconds']);

$internetUptimeSeconds = timeToSeconds($todayData['total_internet_uptime'] ?? '00:00:00');
$recordedInternetOfflineSeconds = timeToSeconds($todayData['total_internet_offline'] ?? '00:00:00');
$internetUptimeMetrics = calculateLiveUptimeMetrics($internetUptimeSeconds, $recordedInternetOfflineSeconds, $elapsedTodaySeconds);
$internetOfflineSeconds = $internetUptimeMetrics['offline_seconds'];
$internetPercentage = $internetUptimeMetrics['percentage'];
$internetBaseFormatted = secondsToTime($internetUptimeMetrics['base_seconds']);

// Counts
$pcOnCount = $todayData['pc_on_count'] ?? 0;
$pcOffCount = $todayData['pc_off_count'] ?? 0;
$rebootCount = $todayData['reboot_count'] ?? 0;
$shutdownCount = $todayData['shutdown_count'] ?? 0;
$internetOfflineCount = $todayData['internet_offline_count'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f6fa; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: white; border-radius: 10px; padding: 25px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .badge { display: inline-block; padding: 8px 20px; border-radius: 25px; font-weight: bold; font-size: 1.1em; margin: 5px 0; }
        .badge-online { background: #27ae60; color: white; }
        .badge-offline { background: #e74c3c; color: white; }
        .badge-unknown { background: #f39c12; color: white; }
        .badge-internet-online { background: #2ecc71; color: white; }
        .badge-internet-offline { background: #e67e22; color: white; }
        .info-box { padding: 12px 15px; border-radius: 8px; margin: 10px 0; background: #f8f9fa; }
        .info-system { background: #e8f5e9; }
        .info-internet { background: #e3f2fd; }
        .info-ip { background: #fff8e1; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 1em; font-weight: bold; transition: all 0.3s ease; }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; transform: translateY(-2px); }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #229954; transform: translateY(-2px); }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #e67e22; transform: translateY(-2px); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0; }
        .stat-box { background: white; padding: 20px; border-radius: 10px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .stat-box h3 { color: #7f8c8d; font-size: 0.9em; text-transform: uppercase; margin-bottom: 5px; }
        .stat-box .percentage { font-size: 2em; font-weight: bold; color: #27ae60; }
        .stat-box .label { color: #95a5a6; font-size: 0.85em; }
        .progress-bar { width: 100%; height: 8px; background: #ecf0f1; border-radius: 4px; margin: 10px 0; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 4px; transition: width 0.3s ease; }
        .progress-online { background: linear-gradient(90deg, #27ae60, #2ecc71); }
        .stat-row { display: flex; gap: 20px; justify-content: center; flex-wrap: wrap; margin-top: 10px; }
        .stat-item { text-align: center; }
        .stat-item .num { font-size: 1.3em; font-weight: bold; }
        .stat-item .lbl { font-size: 0.75em; color: #7f8c8d; }
        .color-online { color: #27ae60; }
        .color-offline { color: #e74c3c; }
        .status-row { display: flex; gap: 30px; flex-wrap: wrap; align-items: center; }
        .count-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-top: 10px; }
        .count-item { background: #f8f9fa; padding: 8px; border-radius: 5px; text-align: center; }
        .count-item .num { font-size: 1.5em; font-weight: bold; }
        .count-item .lbl { font-size: 0.7em; color: #7f8c8d; }
        .auto-refresh { color: #95a5a6; font-size: 0.8em; text-align: right; }
        .alert { padding: 15px; border-radius: 5px; margin: 10px 0; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .day-card { background: #f8f9fa; border-radius: 10px; padding: 15px; margin: 10px 0; }
        .day-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; margin-bottom: 10px; flex-wrap: wrap; }
        .log-entry { padding: 8px 12px; margin: 5px 0; background: #f8f9fa; border-radius: 4px; }
        .log-time { color: #7f8c8d; font-weight: bold; margin-right: 10px; }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
            .action-buttons .btn { width: 100%; text-align: center; }
            .stat-row { flex-direction: column; align-items: center; }
            .status-row { flex-direction: column; align-items: flex-start; }
            .count-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($actionMessage): ?>
        <div class="alert alert-<?php echo $actionType; ?>">
            <?php echo htmlspecialchars($actionMessage); ?>
        </div>
        <?php endif; ?>

        <!-- Main Status Card -->
        <div class="card">
            <h2>📊 Server Status</h2>
            <p style="color: #7f8c8d; margin-bottom: 15px;">Real-time monitoring and uptime tracking</p>
            
            <div class="status-row">
                <div>
                    <div style="font-size: 0.8em; color: #7f8c8d;">System Status</div>
                    <span class="badge badge-<?php echo strtolower($currentStatus == 'ONLINE' ? 'online' : ($currentStatus == 'OFFLINE' ? 'offline' : 'unknown')); ?>">
                        <?php echo $currentStatus; ?>
                    </span>
                </div>
                <div>
                    <div style="font-size: 0.8em; color: #7f8c8d;">Internet Status</div>
                    <span class="badge badge-internet-<?php echo strtolower($internetStatus == 'ONLINE' ? 'online' : 'offline'); ?>">
                        <?php echo $internetStatus; ?>
                    </span>
                </div>
            </div>
            
            <div class="info-box info-system">
                <strong>🖥️ Current Session Uptime:</strong> <?php echo $currentUptimeFormatted; ?><br>
                <span style="font-size: 0.95em;">⏱️ Total Uptime Today: <strong><?php echo $totalUptimeFormatted; ?></strong></span>
            </div>
            
            <div class="info-box info-internet">
                <strong>🌐 Internet Status:</strong> <?php echo $internetStatus; ?><br>
                <span style="font-size: 0.95em;">⏱️ Current Internet Uptime: <strong><?php echo secondsToTime(timeToSeconds($todayData['current_internet_uptime'] ?? '00:00:00')); ?></strong></span><br>
                <span style="font-size: 0.95em;">⏱️ Total Internet Uptime Today: <strong><?php echo secondsToTime($internetUptimeSeconds); ?></strong></span><br>
                <span style="font-size: 0.9em; color: #7f8c8d;">Last checked: <?php echo $internetLastCheck ?: 'Never'; ?></span>
            </div>
            
            <div class="info-box info-ip">
                <strong>🌐 Server IPs:</strong> <?php echo $serverIP; ?>
            </div>
            
            <div style="margin-top: 10px; color: #7f8c8d; font-size: 0.9em;">
                Last system check: <?php echo $lastCheck ?: 'Never'; ?>
            </div>
            
            <div class="action-buttons">
                <button class="btn btn-primary" onclick="location.reload()">🔄 Refresh</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="send_status">
                    <button type="submit" class="btn btn-success">📧 Send Status</button>
                </form>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="send_summary">
                    <button type="submit" class="btn btn-warning">📊 Send Summary</button>
                </form>
            </div>
            
            <div class="auto-refresh">🔄 Auto-refreshes every 60 seconds</div>
        </div>

        <!-- Today's Total Uptime Stats -->
        <div class="stats-grid">
            <div class="stat-box">
                <h3>📊 Today's Total System Uptime</h3>
                <div class="percentage"><?php echo number_format($percentage, 2); ?>%</div>
                <div class="label">of <?php echo $systemBaseFormatted; ?> tracked today</div>
                
                <div class="stat-row">
                    <div class="stat-item">
                        <div><span class="num color-online"><?php echo $totalUptimeFormatted; ?></span> <span class="lbl">✅ Online</span></div>
                    </div>
                    <div class="stat-item">
                        <div><span class="num color-offline"><?php echo secondsToTime($offlineSeconds); ?></span> <span class="lbl">❌ Offline</span></div>
                    </div>
                </div>
                
                <div class="progress-bar">
                    <div class="progress-fill progress-online" style="width: <?php echo min($percentage, 100); ?>%;"></div>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 0.75em; color: #95a5a6;">
                    <span>0%</span>
                    <span>100%</span>
                </div>
                <div style="margin-top: 8px; font-size: 0.75em; color: #7f8c8d;">
                    PC ON: <?php echo $pcOnCount; ?> times | PC OFF: <?php echo $pcOffCount; ?> times
                </div>
            </div>
            
            <div class="stat-box">
                <h3>📊 Today's Total Internet Uptime</h3>
                <div class="percentage"><?php echo number_format($internetPercentage, 2); ?>%</div>
                <div class="label">of <?php echo $internetBaseFormatted; ?> tracked today</div>
                
                <div class="stat-row">
                    <div class="stat-item">
                        <div><span class="num color-online"><?php echo secondsToTime($internetUptimeSeconds); ?></span> <span class="lbl">✅ Online</span></div>
                    </div>
                    <div class="stat-item">
                        <div><span class="num color-offline"><?php echo secondsToTime($internetOfflineSeconds); ?></span> <span class="lbl">❌ Offline</span></div>
                    </div>
                </div>
                
                <div class="progress-bar">
                    <div class="progress-fill progress-online" style="width: <?php echo min($internetPercentage, 100); ?>%;"></div>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 0.75em; color: #95a5a6;">
                    <span>0%</span>
                    <span>100%</span>
                </div>
                <div style="margin-top: 8px; font-size: 0.75em; color: #7f8c8d;">
                    Internet Offline: <?php echo $internetOfflineCount; ?> times
                </div>
            </div>
        </div>

        <!-- Today's Counts -->
        <div class="card">
            <h3>📈 Today's Event Counts</h3>
            <div class="count-grid">
                <div class="count-item">
                    <div class="num color-online"><?php echo $pcOnCount; ?></div>
                    <div class="lbl">🟢 PC ON</div>
                </div>
                <div class="count-item">
                    <div class="num color-offline"><?php echo $pcOffCount; ?></div>
                    <div class="lbl">🔴 PC OFF</div>
                </div>
                <div class="count-item">
                    <div class="num" style="color:#9b59b6;"><?php echo $rebootCount; ?></div>
                    <div class="lbl">🔄 Reboot</div>
                </div>
                <div class="count-item">
                    <div class="num" style="color:#e74c3c;"><?php echo $shutdownCount; ?></div>
                    <div class="lbl">⏹️ Shutdown</div>
                </div>
                <div class="count-item">
                    <div class="num" style="color:#e67e22;"><?php echo $internetOfflineCount; ?></div>
                    <div class="lbl">🌐 Internet Offline</div>
                </div>
            </div>
        </div>

        <!-- Event History (from Database) -->
        <div class="card">
            <h2>📊 Daily Uptime History</h2>
            <p style="color: #95a5a6; margin-bottom: 15px; font-size: 0.9em;">System and internet uptime history (Last 30 days)</p>
            
            <?php if (empty($history)): ?>
                <p>No data available yet.</p>
            <?php else: ?>
                <?php foreach ($history as $row): 
                    $dateObj = DateTime::createFromFormat('Y-m-d', $row['date']);
                    $displayDate = $dateObj ? $dateObj->format('l, F j, Y') : $row['date'];
                    $totalSec = timeToSeconds($row['total_uptime'] ?? '00:00:00');
                    $totalInternetSec = timeToSeconds($row['total_internet_uptime'] ?? '00:00:00');
                    $offlineSec = timeToSeconds($row['total_uptime_offline'] ?? '00:00:00');
                    $internetOfflineSec = timeToSeconds($row['total_internet_offline'] ?? '00:00:00');
                    $systemDayMetrics = calculateDailyUptimeMetrics($row['date'], $totalSec, $offlineSec, $elapsedTodaySeconds);
                    $internetDayMetrics = calculateDailyUptimeMetrics($row['date'], $totalInternetSec, $internetOfflineSec, $elapsedTodaySeconds);
                    $pct = $systemDayMetrics['percentage'];
                    $internetPct = $internetDayMetrics['percentage'];
                ?>
                <div class="day-card">
                    <div class="day-header">
                        <h3><?php echo $displayDate; ?></h3>
                        <span style="font-weight: bold; color: <?php echo $pct >= 90 ? '#27ae60' : ($pct >= 50 ? '#f39c12' : '#e74c3c'); ?>;">
                            <?php echo number_format($pct, 2); ?>% Uptime
                        </span>
                    </div>
                    <div style="display: flex; gap: 20px; flex-wrap: wrap; font-size: 0.9em; color: #7f8c8d;">
                        <span>⏱️ System: <?php echo $row['total_uptime'] ?? '00:00:00'; ?></span>
                        <span>🌐 Internet: <?php echo $row['total_internet_uptime'] ?? '00:00:00'; ?></span>
                        <span>🔄 PC ON: <?php echo $row['pc_on_count'] ?? 0; ?></span>
                        <span>🔴 PC OFF: <?php echo $row['pc_off_count'] ?? 0; ?></span>
                    </div>
                    <div class="progress-bar" style="margin-top: 5px;">
                        <div class="progress-fill progress-online" style="width: <?php echo min($pct, 100); ?>%;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div style="margin-top: 20px; text-align: center; color: #95a5a6; font-size: 0.8em;">
            Server Check System v3.0 | Database: serverchceck
        </div>
    </div>

    <script>
        setTimeout(function() { location.reload(); }, 60000);
    </script>
</body>
</html>