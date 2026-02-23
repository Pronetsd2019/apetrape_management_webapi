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
 * Update Assignment Status Endpoint
 * POST /orders/update_assignment_status.php
 * Body: { "assignment_id": <int>, "status": "<string>" } or { "order_id": <int>, "status": "<string>" }
 * Updates the order_assignments.status for the given assignment (by id or by order_id; order_id updates latest assignment for that order).
 * Only the driver (assigned_to) can update their own assignment.
 * Allowed status values: assigned, in_progress, delivered, cancelled.
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../util/check_permission.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$validStatuses = ['assigned', 'in_progress', 'delivered', 'cancelled'];

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['status'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required field: status. Provide assignment_id or order_id.'
        ]);
        exit;
    }

    $status = trim((string) $input['status']);
    if ($status === '' || !in_array($status, $validStatuses)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid status. Allowed: ' . implode(', ', $validStatuses)
        ]);
        exit;
    }

    $assignment_id = null;
    if (isset($input['assignment_id'])) {
        $assignment_id = filter_var($input['assignment_id'], FILTER_VALIDATE_INT);
        if ($assignment_id === false || $assignment_id < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'assignment_id must be a positive integer.']);
            exit;
        }
    } elseif (isset($input['order_id'])) {
        $order_id = filter_var($input['order_id'], FILTER_VALIDATE_INT);
        if ($order_id === false || $order_id < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'order_id must be a positive integer.']);
            exit;
        }
        $findStmt = $pdo->prepare("
            SELECT id FROM order_assignments
            WHERE order_id = ? AND assigned_to = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $findStmt->execute([$order_id, $userId]);
        $row = $findStmt->fetch(PDO::FETCH_ASSOC);
        $assignment_id = $row ? (int) $row['id'] : null;
    }

    if ($assignment_id === null) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Provide assignment_id or order_id.'
        ]);
        exit;
    }

    // Only the driver (assigned_to) can update their own assignment
    $stmt = $pdo->prepare("
        UPDATE order_assignments
        SET status = ?, updated_at = NOW()
        WHERE id = ? AND assigned_to = ?
    ");
    $stmt->execute([$status, $assignment_id, $userId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Assignment not found or you are not assigned to this order.'
        ]);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Assignment status updated successfully.',
        'data' => [
            'assignment_id' => (int) $assignment_id,
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (PDOException $e) {
    logException('orders_update_assignment_status', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating assignment status: ' . $e->getMessage()
    ]);
}
