<?php
/**
 * Notification Logger Utility
 *
 * Creates DB notifications for in-app notification feeds.
 *
 * Tables used:
 * - notifications(id, type, title, message, entity_type, entity_id, data, created_at)
 * - notification_users(id, notification_id, user_id, is_read, read_at, delivered_via, created_at)
 */

require_once __DIR__ . '/error_logger.php';

/**
 * Creates a notification record in `notifications`.
 *
 * @return int notification_id
 */
function createNotification(PDO $pdo, string $type, string $title, string $message, ?string $entityType = null, ?int $entityId = null, array $data = []): int {
    $dataJson = !empty($data) ? json_encode($data) : null;

    $stmt = $pdo->prepare("
        INSERT INTO notifications (type, title, message, entity_type, entity_id, data, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $type,
        $title,
        $message,
        $entityType,
        $entityId,
        $dataJson
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Assigns a notification to a user by inserting into `notification_users`.
 *
 * @return int notification_user_id (this is what mark_as_read.php expects as "id")
 */
function assignNotificationToUser(PDO $pdo, int $notificationId, int $userId, string $deliveredVia = 'push'): int {
    $stmt = $pdo->prepare("
        INSERT INTO notification_users (notification_id, user_id, is_read, read_at, delivered_via, created_at)
        VALUES (?, ?, 0, NULL, ?, NOW())
    ");
    $stmt->execute([$notificationId, $userId, $deliveredVia]);

    return (int)$pdo->lastInsertId();
}

/**
 * Convenience wrapper:
 * - Creates notification in `notifications`
 * - Creates mapping row in `notification_users`
 * Returns both IDs so the frontend/app can mark-as-read later.
 *
 * @return array{notification_id:int, notification_user_id:int}
 */
function logUserNotification(PDO $pdo, int $userId, string $type, string $title, string $message, ?string $entityType = null, ?int $entityId = null, array $data = [], string $deliveredVia = 'push'): array {
    $pdo->beginTransaction();
    try {
        $notificationId = createNotification($pdo, $type, $title, $message, $entityType, $entityId, $data);
        $notificationUserId = assignNotificationToUser($pdo, $notificationId, $userId, $deliveredVia);

        $pdo->commit();
        return [
            'notification_id' => $notificationId,
            'notification_user_id' => $notificationUserId
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        logException('notification_logger', $e, [
            'user_id' => $userId,
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);
        throw $e;
    }
}

