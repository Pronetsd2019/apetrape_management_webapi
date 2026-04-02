<?php
/**
 * Authentication audit logger.
 *
 * Writes login/signout/password-reset audit events into database.
 */

require_once __DIR__ . '/error_logger.php';

/**
 * Persist an auth audit event.
 *
 * @param PDO $pdo
 * @param string $eventType login_success|login_failed|signout|password_reset
 * @param int|null $userId
 * @param string|null $identifier
 * @param array $metadata
 * @return void
 */
function logAuthAudit(PDO $pdo, string $eventType, ?int $userId = null, ?string $identifier = null, array $metadata = []): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_auth_audit_logs
                (user_id, event_type, identifier, ip_address, user_agent, metadata)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $userId,
            $eventType,
            $identifier,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null
        ]);
    } catch (Throwable $e) {
        // Audit logging must never break auth endpoints.
        logException('auth_audit_log', $e, [
            'event_type' => $eventType,
            'user_id' => $userId,
            'identifier' => $identifier
        ]);
    }
}

