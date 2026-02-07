<?php
/**
 * Log token operations (e.g. refresh) to control/logs/tokens.
 * Same base as rest of control logs (control/util → control/logs).
 * - logTokenEvent: all events → control/logs/tokens/year/month/day/refresh.log
 * - logFailedToken: failures only → control/logs/tokens/year/month/day/failed.log
 */

function _tokenLogDir() {
    $base = dirname(__DIR__) . '/logs/tokens';
    return $base . '/' . date('Y') . '/' . date('m') . '/' . date('d');
}

function logTokenEvent($source, $success, $message, $context = []) {
    $logDir = _tokenLogDir();
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $entry = [
        'timestamp' => $timestamp,
        'source' => $source,
        'success' => $success,
        'message' => $message,
        'ip' => $ip,
        'context' => $context
    ];
    $line = $timestamp . ' [' . $source . '] success=' . ($success ? 'true' : 'false') . ' ' . $message . ' ' . json_encode($entry) . "\n";
    @file_put_contents($logDir . '/refresh.log', $line, FILE_APPEND | LOCK_EX);
    if (!$success) {
        logFailedToken($source, $message, $context);
    }
}

function logFailedToken($source, $message, $context = []) {
    $logDir = _tokenLogDir();
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
