<?php
/**
 * Communications Logger Utility
 *
 * Stores communication logs under:
 *   control/logs/opt_Send/YYYY/MM/DD/logs.log
 *
 * Note: Do NOT log secrets (OTP code, API keys, passwords).
 */

/**
 * Write a structured log entry to the opt_Send daily log file.
 *
 * @param string $event e.g. comm_email_attempt, comm_sms_success
 * @param string $message Human readable message
 * @param array $context Extra context (user_id, otp_id, channel, etc)
 * @param string $level info|error
 * @return void
 */
function logComm(string $event, string $message, array $context = [], string $level = 'info'): void {
    $baseDir = dirname(__DIR__) . '/logs/opt_Send';
    $datePath = date('Y/m/d');
    $dir = $baseDir . '/' . $datePath;

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $logFile = $dir . '/logs.log';

    $entry = [
        'timestamp' => date('c'),
        'level' => $level,
        'event' => $event,
        'message' => $message,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
        'context' => $context
    ];

    @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Log an exception into the same communication log file.
 */
function logCommException(string $event, Throwable $e, array $context = []): void {
    $ctx = array_merge($context, [
        'error' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    logComm($event, 'Exception', $ctx, 'error');
}

