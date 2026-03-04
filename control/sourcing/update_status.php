<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Update sourcing call status (multi-item)
 * POST /sourcing/update_status.php
 * Body: { "items": [ { "id": <sourcing_call id>, "status": "<new status>" }, ... ] }
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../util/check_permission.php';
require_once __DIR__ . '/../util/order_tracker.php';
require_once __DIR__ . '/../util/notification_logger.php';
require_once __DIR__ . '/../util/firebase_messaging.php';

requireJwtAuth();

header('Content-Type: application/json');

$authUser = $GLOBALS['auth_user'] ?? null;
$userId = $authUser['admin_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
    exit;
}

if (!checkUserPermission($userId, 'orders', 'update')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to update orders.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST or PUT.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON in request body.']);
    exit;
}

if (!isset($input['items']) || !is_array($input['items']) || empty($input['items'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'items is required and must be a non-empty array.']);
    exit;
}

$items = $input['items'];

foreach ($items as $idx => $item) {
    if (!isset($item['id']) || !is_numeric($item['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "items[{$idx}]: id is required and must be a number."]);
        exit;
    }
    if (!isset($item['status']) || trim((string)$item['status']) === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "items[{$idx}]: status is required and cannot be empty."]);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    $updateStmt = $pdo->prepare("UPDATE sourcing_calls SET status = ?, updated_at = NOW() WHERE id = ?");

    $updated = [];
    $errors = [];

    foreach ($items as $idx => $item) {
        $id = (int)$item['id'];
        $status = trim((string)$item['status']);

        $updateStmt->execute([$status, $id]);
        $affected = $updateStmt->rowCount();

        if ($affected > 0) {
            $updated[] = ['id' => $id, 'status' => $status];
        } else {
            $errors[] = ['index' => $idx, 'id' => $id, 'error' => 'Row not found or no change.'];
        }
    }

    $pdo->commit();

    // Collect affected order_ids for background in-transit check (only if we updated any)
    $affectedOrderIds = [];
    if (!empty($updated)) {
        $updatedIds = array_column($updated, 'id');
        $placeholders = implode(',', array_fill(0, count($updatedIds), '?'));
        $orderIdStmt = $pdo->prepare("SELECT DISTINCT order_id FROM sourcing_calls WHERE id IN ($placeholders)");
        $orderIdStmt->execute($updatedIds);
        $affectedOrderIds = array_values(array_unique(array_column($orderIdStmt->fetchAll(PDO::FETCH_ASSOC), 'order_id')));
    }

    $responseBody = [
        'success' => true,
        'message' => count($updated) . ' item(s) updated.',
        'data' => [
            'updated' => $updated,
            'updated_count' => count($updated),
            'errors' => $errors
        ]
    ];

    http_response_code(200);
    echo json_encode($responseBody);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }

    // Background: per affected order, if all items sourced/in-house, add track action and notify user (errors only logged)
    $warehouseLogFile = dirname(__DIR__) . '/logs/warehouse_check.log';
    $warehouseLogDir = dirname($warehouseLogFile);
    if (!is_dir($warehouseLogDir)) {
        @mkdir($warehouseLogDir, 0755, true);
    }
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($warehouseLogFile, "[{$ts}] " . json_encode(['event' => 'background_start', 'affected_order_ids' => $affectedOrderIds], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

    foreach ($affectedOrderIds as $order_id) {
        // Run warehouse check first so we always log the per-order item check (even if in-transit block would continue)
        $warehouseLogFile = dirname(__DIR__) . '/logs/warehouse_check.log';
        $warehouseLogDir = dirname($warehouseLogFile);
        if (!is_dir($warehouseLogDir)) {
            @mkdir($warehouseLogDir, 0755, true);
        }
        $logLine = function ($data) use ($warehouseLogFile) {
            $ts = date('Y-m-d H:i:s');
            $entry = "[{$ts}] " . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n";
            @file_put_contents($warehouseLogFile, $entry, FILE_APPEND | LOCK_EX);
        };

        try {
            $notReceivedStmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt FROM sourcing_calls
                WHERE order_id = ? AND type = 'sourcing' AND status != 'received'
            ");
            $notReceivedStmt->execute([$order_id]);
            $notReceivedRow = $notReceivedStmt->fetch(PDO::FETCH_ASSOC);
            $notReceivedCount = (int)($notReceivedRow['cnt'] ?? 0);

            $allSourcingStmt = $pdo->prepare("SELECT id, order_item_id, type, status FROM sourcing_calls WHERE order_id = ?");
            $allSourcingStmt->execute([$order_id]);
            $allSourcingRows = $allSourcingStmt->fetchAll(PDO::FETCH_ASSOC);

            $logLine([
                'check' => 'Order in Our Warehouse',
                'order_id' => $order_id,
                'sourcing_not_received_count' => $notReceivedCount,
                'all_sourcing_calls' => $allSourcingRows,
                'passes_check' => $notReceivedCount === 0
            ]);

            if ($notReceivedCount > 0) {
                $logLine(['order_id' => $order_id, 'skipped' => 'some sourcing items not yet received']);
            } else {
                $warehouseExistsStmt = $pdo->prepare("
                    SELECT 1 FROM order_track
                    WHERE order_id = ? AND action = 'Order in Our Warehouse'
                    LIMIT 1
                ");
                $warehouseExistsStmt->execute([$order_id]);
                $warehouseAlreadyExists = (bool)$warehouseExistsStmt->fetch();
                $logLine([
                    'order_id' => $order_id,
                    'warehouse_track_already_exists' => $warehouseAlreadyExists
                ]);

                if (!$warehouseAlreadyExists) {
                    trackOrderAction($pdo, $order_id, 'Order in Our Warehouse');
                    $logLine(['order_id' => $order_id, 'action' => 'track added', 'action_text' => 'Order in Our Warehouse']);

            $orderStmt = $pdo->prepare("SELECT user_id, order_no FROM orders WHERE id = ? LIMIT 1");
            $orderStmt->execute([$order_id]);
            $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
            $orderOwnerUserId = $orderRow ? (int)($orderRow['user_id'] ?? 0) : 0;
            $orderNoStr = $orderRow ? ($orderRow['order_no'] ?? (string)$order_id) : (string)$order_id;

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
                        $order_id,
                        ['order_id' => (string)$order_id],
                        'push'
                    );
                    $notifData = [
                        'route' => 'order',
                        'order_id' => (string)$order_id,
                        'notification_id' => (string)($loggedIds['notification_id'] ?? ''),
                        'notification_user_id' => (string)($loggedIds['notification_user_id'] ?? '')
                    ];
                    sendPushNotificationToUser($orderOwnerUserId, $notifTitle, $notifBody, $notifData, null, $pdo);
                    $logLine(['order_id' => $order_id, 'action' => 'notification_sent', 'user_id' => $orderOwnerUserId]);
                }
            } else {
                $logLine(['order_id' => $order_id, 'skipped' => 'order owner user_id <= 0', 'order_owner_user_id' => $orderOwnerUserId]);
            }
                } else {
                    $logLine(['order_id' => $order_id, 'skipped' => 'Order in Our Warehouse already in order_track']);
                }
            }
        } catch (Throwable $e) {
            logException('sourcing_update_status_warehouse', $e, ['order_id' => $order_id]);
            $ts = date('Y-m-d H:i:s');
            $logFile = dirname(__DIR__) . '/logs/warehouse_check.log';
            @file_put_contents($logFile, "[{$ts}] " . json_encode(['order_id' => $order_id, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        }

        // In-transit: if all items sourced/in-house (no pending/not found), add track and notify. Runs regardless of warehouse result.
        try {
            $pendingStmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt FROM sourcing_calls
                WHERE order_id = ? AND status IN ('pending', 'not found')
            ");
            $pendingStmt->execute([$order_id]);
            $pendingRow = $pendingStmt->fetch(PDO::FETCH_ASSOC);
            $pendingCount = (int)($pendingRow['cnt'] ?? 0);
            if ($pendingCount === 0) {
                $existsStmt = $pdo->prepare("
                    SELECT 1 FROM order_track
                    WHERE order_id = ? AND action = 'Order in-transit from Supplier'
                    LIMIT 1
                ");
                $existsStmt->execute([$order_id]);
                if (!$existsStmt->fetch()) {
                    trackOrderAction($pdo, $order_id, 'Order in-transit from Supplier');
                    $orderStmt = $pdo->prepare("SELECT user_id, order_no FROM orders WHERE id = ? LIMIT 1");
                    $orderStmt->execute([$order_id]);
                    $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
                    $orderOwnerUserId = $orderRow ? (int)($orderRow['user_id'] ?? 0) : 0;
                    $orderNo = $orderRow ? ($orderRow['order_no'] ?? (string)$order_id) : (string)$order_id;
                    if ($orderOwnerUserId > 0) {
                        $prefStmt = $pdo->prepare("SELECT push_notifications FROM user_notification_preferences WHERE user_id = ?");
                        $prefStmt->execute([$orderOwnerUserId]);
                        $prefs = $prefStmt->fetch(PDO::FETCH_ASSOC);
                        $pushEnabled = $prefs ? (int)$prefs['push_notifications'] === 1 : true;
                        if ($pushEnabled) {
                            $notifTitle = 'Order in-transit';
                            $notifBody = "Your order {$orderNo} is in-transit from supplier.";
                            $loggedIds = logUserNotification($pdo, $orderOwnerUserId, 'order_in_transit', $notifTitle, $notifBody, 'order', $order_id, ['order_id' => (string)$order_id], 'push');
                            $notifData = ['route' => 'order', 'order_id' => (string)$order_id, 'notification_id' => (string)($loggedIds['notification_id'] ?? ''), 'notification_user_id' => (string)($loggedIds['notification_user_id'] ?? '')];
                            sendPushNotificationToUser($orderOwnerUserId, $notifTitle, $notifBody, $notifData, null, $pdo);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            logException('sourcing_update_status_in_transit', $e, ['order_id' => $order_id]);
        }
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('sourcing_update_status', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating sourcing status.',
        'error_details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('sourcing_update_status', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating sourcing status.'
    ]);
}
