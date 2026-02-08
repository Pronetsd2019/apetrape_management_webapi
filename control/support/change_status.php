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
 * Change Support Contact Status Endpoint
 * POST /support/change_status.php
 *
 * Expects JSON body: { "id": <int>, "status": <int> }
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

if (!checkUserPermission($userId, 'support', 'update')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to update support contacts.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['id']) || empty($input['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Field "id" is required.']);
        exit;
    }

    if (!array_key_exists('status', $input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Field "status" is required.']);
        exit;
    }

    $contact_id = (int)$input['id'];
    $status = (int)$input['status'];

    $stmt = $pdo->prepare("SELECT id, status FROM support_contacts WHERE id = ?");
    $stmt->execute([$contact_id]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contact) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Support contact not found.']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE support_contacts
        SET status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$status, $contact_id]);

    $stmt = $pdo->prepare("
        SELECT
            id,
            user_id,
            name,
            cell,
            email,
            category,
            message,
            status,
            created_at,
            updated_at
        FROM support_contacts
        WHERE id = ?
    ");
    $stmt->execute([$contact_id]);
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Support contact status updated successfully.',
        'data' => $updated
    ]);

} catch (PDOException $e) {
    logException('support_change_status', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating support contact status: ' . $e->getMessage()
    ]);
}
