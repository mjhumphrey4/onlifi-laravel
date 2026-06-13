<?php
// logger.php - Create this new file

// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

class PaymentLogger {
    private $logFile;
    private $logDir = __DIR__ . '/logs';

    public function __construct() {
        // Ensure logs directory exists
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
        $this->logFile = $this->logDir . '/paymentlogs.txt';
    }

    public function log($message, $data = null, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $pid = getmypid();
        $function = $this->getCallerFunction();
        
        $logMessage = "[$timestamp] [PID:$pid] [$level] [$function] $message";
        
        if ($data !== null) {
            $logMessage .= " | DATA: " . json_encode($data, JSON_PRETTY_PRINT);
        }
        
        $logMessage .= "\n" . str_repeat("=", 100) . "\n";
        
        error_log($logMessage, 3, $this->logFile);
    }

    public function error($message, $data = null) {
        $this->log($message, $data, 'ERROR');
    }

    public function info($message, $data = null) {
        $this->log($message, $data, 'INFO');
    }

    public function debug($message, $data = null) {
        $this->log($message, $data, 'DEBUG');
    }

    public function warning($message, $data = null) {
        $this->log($message, $data, 'WARNING');
    }

    public function success($message, $data = null) {
        $this->log($message, $data, 'SUCCESS');
    }

    private function getCallerFunction() {
        $trace = debug_backtrace();
        return isset($trace[2]['function']) ? $trace[2]['function'] : 'unknown';
    }
}

// Initialize logger globally
$logger = new PaymentLogger();
?>
