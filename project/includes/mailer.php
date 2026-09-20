<?php
// Lightweight SMTP mailer for servercheck project

// Set timezone
date_default_timezone_set('Asia/Kolkata');

class Mailer {
    private $smtpConfig;
    private $debug = false;
    private $db;
    
    public function __construct($debug = false) {
        $this->debug = $debug;
        $configFile = __DIR__ . '/../config/smtp.json';
        
        if (!file_exists($configFile)) {
            $error = "SMTP config file not found: $configFile";
            error_log($error);
            throw new Exception($error);
        }
        
        $this->smtpConfig = json_decode(file_get_contents($configFile), true);
        
        if (!is_array($this->smtpConfig)) {
            throw new Exception("Invalid SMTP configuration!");
        }

        foreach (['host', 'username', 'password', 'from_email'] as $requiredKey) {
            if (empty($this->smtpConfig[$requiredKey])) {
                throw new Exception("Missing SMTP configuration: $requiredKey");
            }
        }
        
        // Database connection
        try {
            require_once __DIR__ . '/database.php';
            $this->db = Database::getInstance();
        } catch (Exception $e) {
            error_log("Database connection failed: " . $e->getMessage());
            $this->db = null;
        }
    }
    
    private function readSmtpResponse($socket) {
        $response = '';

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException('Empty response from SMTP server');
        }

        return $response;
    }

    private function smtpCommand($socket, $command, $expectedCodes) {
        if ($this->debug) {
            error_log('SMTP command: ' . preg_replace('/^AUTH LOGIN$/', 'AUTH LOGIN', $command));
        }

        fwrite($socket, $command . "\r\n");
        $response = $this->readSmtpResponse($socket);
        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('SMTP error ' . $code . ': ' . trim($response));
        }

        return $response;
    }

    private function connectSmtp() {
        $host = (string) $this->smtpConfig['host'];
        $port = (int) ($this->smtpConfig['port'] ?? 587);
        $secure = strtolower((string) ($this->smtpConfig['secure'] ?? 'tls'));
        $timeout = max(1, (int) ($this->smtpConfig['timeout'] ?? 10));
        $transport = $secure === 'ssl' ? 'ssl://' : '';
        $errorNumber = 0;
        $errorMessage = '';

        $socket = @fsockopen(
            $transport . $host,
            $port,
            $errorNumber,
            $errorMessage,
            $timeout
        );

        if (!$socket) {
            throw new RuntimeException("SMTP connection failed: $errorMessage ($errorNumber)");
        }

        stream_set_timeout($socket, $timeout);

        try {
            $this->readSmtpResponse($socket);
            $this->smtpCommand($socket, 'EHLO localhost', [250]);

            if ($secure === 'tls') {
                $this->smtpCommand($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Unable to enable TLS for SMTP connection');
                }
                $this->smtpCommand($socket, 'EHLO localhost', [250]);
            } elseif ($secure !== 'ssl' && $secure !== 'none' && $secure !== '') {
                throw new RuntimeException("Unsupported SMTP security mode: $secure");
            }

            $this->smtpCommand($socket, 'AUTH LOGIN', [334]);
            $this->smtpCommand($socket, base64_encode($this->smtpConfig['username']), [334]);
            $this->smtpCommand($socket, base64_encode($this->smtpConfig['password']), [235]);
        } catch (Throwable $exception) {
            fclose($socket);
            throw $exception;
        }

        return $socket;
    }

    private function encodeHeader($value) {
        $value = trim(str_replace(["\r", "\n"], '', (string) $value));
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
    
    private function getServerIPs() {
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
    
    public function sendEmail($to, $toName, $subject, $message) {
        $socket = null;

        try {
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Invalid recipient email address');
            }

            $from = $this->smtpConfig['from_email'];
            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Invalid sender email address');
            }
            $fromName = $this->smtpConfig['from_name'] ?? 'Server Monitor';
            $socket = $this->connectSmtp();
            $this->smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
            $this->smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->smtpCommand($socket, 'DATA', [354]);

            $body = str_replace(["\r\n", "\r"], "\n", (string) $message);
            $body = preg_replace('/^\./m', '..', $body);
            $headers = [
                'From: ' . $this->encodeHeader($fromName) . ' <' . $from . '>',
                'To: ' . $this->encodeHeader($toName) . ' <' . $to . '>',
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit'
            ];

            fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n.\r\n");
            $response = $this->readSmtpResponse($socket);
            if ((int) substr($response, 0, 3) !== 250) {
                throw new RuntimeException('SMTP message rejected: ' . trim($response));
            }
            $this->smtpCommand($socket, 'QUIT', [221, 250]);
            fclose($socket);
            $socket = null;
            error_log("Email sent to: $to - Subject: $subject");
            return true;
            
        } catch (Throwable $e) {
            if (is_resource($socket)) {
                fclose($socket);
            }
            error_log("Email Sending Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function sendServerStatusEmail($isOnline, $message = null) {
        $configFile = __DIR__ . '/../config/sendmail.json';
        
        if (!file_exists($configFile)) {
            error_log("Sendmail config not found: $configFile");
            return false;
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        
        if (!is_array($config) || empty($config['to_email'])) {
            error_log("Invalid sendmail configuration!");
            return false;
        }
        
        $status = $isOnline ? 'ONLINE' : 'OFFLINE';
        $subject = ($config['subject'] ?? 'Server Status') . " - " . $status;
        $body = $isOnline ? ($config['online_message'] ?? 'Server is ONLINE') : ($config['offline_message'] ?? 'Server is OFFLINE');
        
        $ips = $this->getServerIPs();
        $ipList = !empty($ips) ? implode(', ', $ips) : '127.0.0.1';
        
        $body .= "\n\n----------------------------------------";
        $body .= "\nTimestamp: " . date('d-m-Y h:i:s A');
        $body .= "\nStatus: " . $status;
        $body .= "\nHost: " . gethostname();
        $body .= "\nIPs: " . $ipList;
        
        if ($message) {
            $body .= "\n\nMessage: " . $message;
        }
        
        return $this->sendEmail(
            $config['to_email'],
            $config['to_name'] ?? 'Admin',
            $subject,
            $body
        );
    }
    
    // Daily summary from database
    public function sendDailySummary($date) {
        $configFile = __DIR__ . '/../config/sendmail.json';
        
        if (!file_exists($configFile)) {
            error_log("Sendmail config not found: $configFile");
            return false;
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        
        if (!$config) {
            error_log("Invalid sendmail configuration!");
            return false;
        }
        
        if (!$this->db) {
            error_log("Database not available!");
            return false;
        }
        
        $data = $this->db->getDataForDate($date);
        
        if (!$data) {
            error_log("No data found for requested summary date: $date");
            return false;
        }
        
        $subject = "📊 Daily Server Report - " . date('d-m-Y', strtotime($date));
        
        $ips = $this->getServerIPs();
        $ipList = !empty($ips) ? implode(', ', $ips) : '127.0.0.1';
        
        $body = "========================================\n";
        $body .= "     📊 DAILY SERVER REPORT\n";
        $body .= "========================================\n\n";
        $body .= "📅 Date: " . date('d-m-Y', strtotime($date)) . "\n";
        $body .= "🖥️ Host: " . gethostname() . "\n";
        $body .= "🌐 IPs: " . $ipList . "\n";
        $body .= "⏰ Generated: " . date('d-m-Y h:i:s A') . "\n";
        $body .= "📍 Timezone: " . date_default_timezone_get() . "\n\n";
        
        $body .= "────────────────────────────────────────\n";
        $body .= "  🖥️ SYSTEM STATISTICS\n";
        $body .= "────────────────────────────────────────\n";
        $systemMetrics = $this->calculateSummaryMetrics(
            $date,
            $data['total_uptime'] ?? '00:00:00',
            $data['total_uptime_offline'] ?? '00:00:00'
        );
        $internetMetrics = $this->calculateSummaryMetrics(
            $date,
            $data['total_internet_uptime'] ?? '00:00:00',
            $data['total_internet_offline'] ?? '00:00:00'
        );
        if ($date === date('Y-m-d')) {
            $body .= "  ⏱️ Current Session       : " . ($data['current_uptime'] ?? '00:00:00') . "\n";
        }
        $body .= "  ✅ System Online        : " . $this->secondsToTime($systemMetrics['online_seconds']) . "\n";
        $body .= "  ❌ System Offline       : " . $this->secondsToTime($systemMetrics['offline_seconds']) . "\n";
        $body .= "  🔄 PC ON Count          : " . ($data['pc_on_count'] ?? 0) . " times\n";
        $body .= "  🔴 PC OFF Count         : " . ($data['pc_off_count'] ?? 0) . " times\n";
        $body .= "  🔄 Reboot Count         : " . ($data['reboot_count'] ?? 0) . " times\n";
        $body .= "  ⏹️ Shutdown Count       : " . ($data['shutdown_count'] ?? 0) . " times\n\n";
        
        $body .= "────────────────────────────────────────\n";
        $body .= "  🌐 INTERNET STATISTICS\n";
        $body .= "────────────────────────────────────────\n";
        if ($date === date('Y-m-d')) {
            $body .= "  ⏱️ Current Internet     : " . ($data['current_internet_uptime'] ?? '00:00:00') . "\n";
        }
        $body .= "  ✅ Internet Online      : " . $this->secondsToTime($internetMetrics['online_seconds']) . "\n";
        $body .= "  ❌ Internet Offline     : " . $this->secondsToTime($internetMetrics['offline_seconds']) . "\n";
        $body .= "  🔴 Internet Offline Count: " . ($data['internet_offline_count'] ?? 0) . " times\n\n";
        
        $body .= "────────────────────────────────────────\n";
        $body .= "  📊 SUMMARY\n";
        $body .= "────────────────────────────────────────\n";

        $systemPercent = 0.00;
        $internetPercent = 0.00;

        if ($systemMetrics['base_seconds'] > 0) {
            $systemPercent = round(($systemMetrics['online_seconds'] / $systemMetrics['base_seconds']) * 100, 2);
            $body .= "  📈 Server Availability: " . $systemPercent . "%\n";
        }

        if ($internetMetrics['base_seconds'] > 0) {
            $internetPercent = round(($internetMetrics['online_seconds'] / $internetMetrics['base_seconds']) * 100, 2);
            $body .= "  📈 Internet Availability: " . $internetPercent . "%\n";
        }

        $body .= "\n";
        $body .= "========================================\n";
        $body .= "  ✅ Report generated successfully\n";
        $body .= "========================================\n";
        
        return $this->sendEmail(
            $config['to_email'],
            $config['to_name'] ?? 'Admin',
            $subject,
            $body
        );
    }
    
    private function timeToSeconds($time) {
        if (!$time) return 0;
        $parts = explode(':', $time);
        if (count($parts) == 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }
        return 0;
    }

    private function secondsToTime($seconds) {
        $seconds = max(0, (int) $seconds);
        return sprintf('%02d:%02d:%02d', floor($seconds / 3600), floor(($seconds % 3600) / 60), $seconds % 60);
    }

    private function calculateSummaryMetrics($date, $onlineTime, $recordedOfflineTime) {
        $onlineSeconds = max(0, $this->timeToSeconds($onlineTime));
        $recordedOfflineSeconds = max(0, $this->timeToSeconds($recordedOfflineTime));
        $baseSeconds = max(1, $onlineSeconds + $recordedOfflineSeconds);

        if ($date === date('Y-m-d')) {
            $elapsedTodaySeconds = max(0, min(time() - strtotime('today'), 86400));
            $baseSeconds = max($baseSeconds, $elapsedTodaySeconds);
        } else {
            $baseSeconds = max($baseSeconds, 86400);
        }

        return [
            'online_seconds' => $onlineSeconds,
            'offline_seconds' => max(0, $baseSeconds - $onlineSeconds),
            'base_seconds' => $baseSeconds
        ];
    }
}
?>