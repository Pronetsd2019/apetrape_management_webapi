<?php

// CORS headers for subdomain support and localhost
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';
$isLocalhostOrigin = isset($_SERVER['HTTP_ORIGIN']) && (
    strpos($_SERVER['HTTP_ORIGIN'], 'http://localhost') === 0 ||
    strpos($_SERVER['HTTP_ORIGIN'], 'http://127.0.0.1') === 0
);

if ((isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) || $isLocalhostOrigin) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Mobile Notifications Get Read (History) Endpoint
 * GET /mobile/v1/notifications/get_read.php?limit=20&offset=0
 * Requires JWT authentication - returns read notifications (history) for the authenticated user
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

// Ensure the request is authenticated
$authUser = requireMobileJwtAuth();
$user_id = (int)($authUser['user_id'] ?? null);

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Unable to identify authenticated user.'
    ]);
    exit;
}

header('Content-Type: application/json');

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    // Parse query parameters
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20; // Max 100, default 20
    $offset = isset($_GET['offset']) ? max((int)$_GET['offset'], 0) : 0;

    // Verify user exists
    $stmt = $pdo->prepare("SELECT id, status FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Not found',
            'message' => 'User not found.'
        ]);
        exit;
    }

    // Get total count of read notifications
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM notification_users nu
        WHERE nu.user_id = ? AND nu.is_read = 1
    ");
    $count_stmt->execute([$user_id]);
    $total_count = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get read notifications with full notification details
    $stmt = $pdo->prepare("
        SELECT 
            nu.id as notification_user_id,
            nu.notification_id,
            nu.user_id,
            nu.is_read,
            nu.read_at,
            nu.delivered_via,
            nu.created_at as received_at,
            n.id as notification_id_full,
            n.type,
            n.title,
            n.message,
            n.entity_type,
            n.entity_id,
            n.data,
            n.created_at as notification_created_at
        FROM notification_users nu
        INNER JOIN notifications n ON nu.notification_id = n.id
        WHERE nu.user_id = ? AND nu.is_read = 1
        ORDER BY nu.read_at DESC, nu.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format notifications for response
    $formatted_notifications = [];
    foreach ($notifications as $notif) {
        $formatted_notifications[] = [
            'id' => (int)$notif['notification_user_id'],
            'notification_id' => (int)$notif['notification_id'],
            'user_id' => (int)$notif['user_id'],
            'is_read' => (bool)$notif['is_read'],
            'read_at' => $notif['read_at'],
            'delivered_via' => $notif['delivered_via'],
            'received_at' => $notif['received_at'],
            'notification' => [
                'id' => (int)$notif['notification_id_full'],
                'type' => $notif['type'],
                'title' => $notif['title'],
                'message' => $notif['message'],
                'entity_type' => $notif['entity_type'],
                'entity_id' => $notif['entity_id'] ? (int)$notif['entity_id'] : null,
                'data' => $notif['data'] ? json_decode($notif['data'], true) : null,
                'created_at' => $notif['notification_created_at']
            ]
        ];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Read notifications (history) retrieved successfully.',
        'data' => $formatted_notifications,
        'pagination' => [
            'total_count' => $total_count,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total_count
        ],
        'count' => count($formatted_notifications)
    ]);

} catch (PDOException $e) {
    logException('mobile_notifications_get_read', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while retrieving read notifications. Please try again later.',
        'error_details' => 'Error retrieving read notifications: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_notifications_get_read', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error retrieving read notifications: ' . $e->getMessage()
    ]);
}
?>
