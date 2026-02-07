<?php
/**
 * Log failed token operations (e.g. refresh) to logs/tokens.
 * Project root is assumed to be control/../..
 */

function logFailedToken($source, $message, $context = []) {
    $base = dirname(__DIR__, 2) . '/logs/tokens';
    $logDir = $base . '/' . date('Y') . '/' . date('m') . '/' . date('d');
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/failed.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $entry = [
        'timestamp' => $timestamp,
        'source' => $source,
        'message' => $message,
        'ip' => $ip,
        'context' => $context
    ];
    $line = $timestamp . ' [' . $source . '] ' . $message . ' ' . json_encode($entry) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
