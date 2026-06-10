<?php

function logIotec($message, $type = 'INFO', $context = []) {
    $logFile = __DIR__ . '/logs/iotec_' . date('Y-m-d') . '.txt';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0755, true)) {
            error_log("IOTEC Logger: Failed to create logs directory: $logDir");
            return;
        }
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' | Context: ' . json_encode($context) : '';
    $logEntry = "[$timestamp] [$type] $message$contextStr\n";
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    error_log("IOTEC [$type]: $message");
}
?>
