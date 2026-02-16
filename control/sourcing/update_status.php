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

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => count($updated) . ' item(s) updated.',
        'data' => [
            'updated' => $updated,
            'updated_count' => count($updated),
            'errors' => $errors
        ]
    ]);

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
