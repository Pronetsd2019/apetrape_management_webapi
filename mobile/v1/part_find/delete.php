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
 * Mobile Part Find Request Delete Endpoint
 * DELETE /mobile/v1/part_find/delete.php?id={request_id}
 * Requires JWT authentication - soft deletes a part find request by setting status to 'deleted'
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

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

try {
    // Validate request ID
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or missing request ID. Use ?id={request_id}'
        ]);
        exit;
    }

    $request_id = (int)$_GET['id'];

    // Check if request exists and belongs to authenticated user
    $check_stmt = $pdo->prepare("
        SELECT id, status FROM part_find_requests
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $check_stmt->execute([$request_id, $user_id]);
    $request = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Part find request not found or does not belong to you.'
        ]);
        exit;
    }

    // Check if already deleted
    if ($request['status'] === 'deleted') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Part find request is already deleted.'
        ]);
        exit;
    }

    // Soft delete by updating status
    $update_stmt = $pdo->prepare("
        UPDATE part_find_requests
        SET status = 'deleted', updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $update_stmt->execute([$request_id, $user_id]);

    // Verify the update was successful
    if ($update_stmt->rowCount() > 0) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Part find request deleted successfully.',
            'data' => [
                'id' => $request_id,
                'status' => 'deleted'
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete part find request. Please try again.'
        ]);
    }

} catch (PDOException $e) {
    logException('mobile_part_find_delete', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while deleting your part find request. Please try again later.',
        'error_details' => 'Error deleting part find request: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_part_find_delete', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error deleting part find request: ' . $e->getMessage()
    ]);
}
?>
