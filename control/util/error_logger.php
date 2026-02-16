<?php

// Set timezone for consistent datetime handling
require_once __DIR__ . '/timezone_config.php';
/**
 * Error Logger Utility
 * Centralized error logging for all endpoints
 */

/**
 * Log error to file
 * 
 * @param string $endpoint The endpoint name (e.g., 'login', 'create', 'update')
 * @param string $message Error message
 * @param array $context Additional context data
 * @return void
 */
function logError($endpoint, $message, $context = []) {
    $logFile = dirname(__DIR__) . '/logs/errors.log';
    $logDir = dirname($logFile);
    
    // Create logs directory if it doesn't exist
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $requestUri = $_SERVER['REQUEST_URI'] ?? 'unknown';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
    $host = $_SERVER['HTTP_HOST'] ?? 'unknown';
    
    // Get authenticated user if available
    $authUser = $GLOBALS['auth_user'] ?? null;
    $userId = null;
    $userType = null;
    
    if ($authUser) {
        if (isset($authUser['admin_id'])) {
            $userId = $authUser['admin_id'];
            $userType = 'admin';
        } elseif (isset($authUser['supplier_id'])) {
            $userId = $authUser['supplier_id'];
            $userType = 'supplier';
        }
    }
    
    $logData = [
        'timestamp' => $timestamp,
        'endpoint' => $endpoint,
        'message' => $message,
        'ip_address' => $ip,
        'user_agent' => $userAgent,
        'request_uri' => $requestUri,
        'method' => $method,
        'host' => $host,
        'user_id' => $userId,
        'user_type' => $userType,
        'context' => $context
    ];
    
    $logEntry = "[{$timestamp}] " . json_encode($logData, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 80) . "\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Log exception/error with full details
 * 
 * @param string $endpoint The endpoint name
 * @param Exception|Throwable $exception The exception object
 * @param array $context Additional context data
 * @return void
 */
function logException($endpoint, $exception, $context = []) {
    $errorContext = array_merge($context, [
        'error' => $exception->getMessage(),
        'error_code' => $exception->getCode(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ]);
    
    logError($endpoint, 'Exception: ' . get_class($exception), $errorContext);
}

/**
 * Log fatal errors (E_ERROR, E_PARSE, etc.) that occur after this file is loaded.
 * Only runs if error_logger was required before the fatal occurred.
 */
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];
    if (in_array($err['type'], $fatals, true)) {
        logError('php_fatal', $err['message'], [
            'file' => $err['file'] ?? null,
            'line' => $err['line'] ?? null,
            'type' => $err['type']
        ]);
    }
});

/**
 * Log uncaught exceptions so they are written to the app log instead of only PHP/server log.
 */
set_exception_handler(function (Throwable $e) {
    logException('uncaught_exception', $e);
});
