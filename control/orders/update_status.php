<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
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
 * Update Order Status Endpoint
 * POST /orders/update_status.php
 */

 require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
 require_once __DIR__ . '/../middleware/auth_middleware.php';
 require_once __DIR__ . '/../util/check_permission.php';
 require_once __DIR__ . '/../util/order_tracker.php';
 require_once __DIR__ . '/../util/notification_logger.php';
 require_once __DIR__ . '/../util/firebase_messaging.php';
 
 // Ensure the request is authenticated
 requireJwtAuth();
 
 header('Content-Type: application/json');
 
 // Get the authenticated user's ID from the JWT payload
 $authUser = $GLOBALS['auth_user'] ?? null;
 $userId = $authUser['admin_id'] ?? null;
 
 if (!$userId) {
     http_response_code(401);
     echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
     exit;
 }
 
 // Check if the user has permission to create a country
 if (!checkUserPermission($userId, 'orders', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update orders.']);
     exit;
 }

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    if (!isset($input['order_id']) || !isset($input['status'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields: order_no and status are required.'
        ]);
        exit;
    }

    $orderNo = trim($input['order_id']);
    $status = trim($input['status']);

    // Validate input
    if (empty($orderNo)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Order number cannot be empty.'
        ]);
        exit;
    }

    if (empty($status)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Status cannot be empty.'
        ]);
        exit;
    }

    // Check if order exists
    $checkStmt = $pdo->prepare("SELECT id FROM orders WHERE id = ?");
    $checkStmt->execute([$orderNo]);
    $existingOrder = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingOrder) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Order not found with order number: ' . $orderNo
        ]);
        exit;
    }

    // Update the order status
    $updateStmt = $pdo->prepare("
        UPDATE orders
        SET status = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $result = $updateStmt->execute([$status, $orderNo]);

    if ($result && $updateStmt->rowCount() > 0) {
        $responseBody = [
            'success' => true,
            'message' => 'Order status updated successfully.',
            'data' => [
                'order_no' => $orderNo,
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ];
        http_response_code(200);
        echo json_encode($responseBody);

        // When order is marked received: background check if all items received or inhouse, then track and notify
        if (strtolower($status) === 'received') {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                flush();
            }
            try {
                $notReceivedStmt = $pdo->prepare("
                    SELECT COUNT(*) AS cnt FROM sourcing_calls
                    WHERE order_id = ? AND type = 'sourcing' AND status != 'received'
                ");
                $notReceivedStmt->execute([$orderNo]);
                $notReceivedRow = $notReceivedStmt->fetch(PDO::FETCH_ASSOC);
                if ((int)($notReceivedRow['cnt'] ?? 0) > 0) {
                    // Not all items received yet
                } else {
                    $existsStmt = $pdo->prepare("
                        SELECT 1 FROM order_track
                        WHERE order_id = ? AND action = 'Order in Our Warehouse'
                        LIMIT 1
                    ");
                    $existsStmt->execute([$orderNo]);
                    if (!$existsStmt->fetch()) {
                        trackOrderAction($pdo, $orderNo, 'Order in Our Warehouse');

                        $orderStmt = $pdo->prepare("SELECT user_id, order_no FROM orders WHERE id = ? LIMIT 1");
                        $orderStmt->execute([$orderNo]);
                        $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
                        $orderOwnerUserId = $orderRow ? (int)($orderRow['user_id'] ?? 0) : 0;
                        $orderNoStr = $orderRow ? ($orderRow['order_no'] ?? (string)$orderNo) : (string)$orderNo;

                        if ($orderOwnerUserId > 0) {
                            $prefStmt = $pdo->prepare("SELECT push_notifications FROM user_notification_preferences WHERE user_id = ?");
                            $prefStmt->execute([$orderOwnerUserId]);
                            $prefs = $prefStmt->fetch(PDO::FETCH_ASSOC);
                            $pushEnabled = $prefs ? (int)$prefs['push_notifications'] === 1 : true;

                            if ($pushEnabled) {
                                $notifTitle = 'Order in Our Warehouse';
                                $notifBody = "Your order {$orderNoStr} is now in our warehouse.";
                                $loggedIds = logUserNotification(
                                    $pdo,
                                    $orderOwnerUserId,
                                    'order_in_warehouse',
                                    $notifTitle,
                                    $notifBody,
                                    'order',
                                    $orderNo,
                                    ['order_id' => (string)$orderNo],
                                    'push'
                                );
                                $notifData = [
                                    'route' => 'order',
                                    'order_id' => (string)$orderNo,
                                    'notification_id' => (string)($loggedIds['notification_id'] ?? ''),
                                    'notification_user_id' => (string)($loggedIds['notification_user_id'] ?? '')
                                ];
                                sendPushNotificationToUser($orderOwnerUserId, $notifTitle, $notifBody, $notifData, null, $pdo);
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                logException('orders_update_status_warehouse', $e, ['order_id' => $orderNo]);
            }
        }
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update order status. No rows were affected.'
        ]);
    }

} catch (PDOException $e) {
    logException('orders_update_status', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating order status: ' . $e->getMessage()
    ]);
}
?>
