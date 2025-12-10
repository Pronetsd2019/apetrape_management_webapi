<?php
/**
 * Update User Status Endpoint
 * PUT /users/update_status.php
 */

 require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
 require_once __DIR__ . '/../middleware/auth_middleware.php';
 require_once __DIR__ . '/../util/check_permission.php';
 
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
 if (!checkUserPermission($userId, 'users', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update user status.']);
     exit;
 }
 
// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    if (!isset($input['user_id']) || !isset($input['status'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields: user_id and status are required.'
        ]);
        exit;
    }

    $userId = trim($input['user_id']);
    $status = trim($input['status']);

    // Validate input
    if (empty($userId) || !is_numeric($userId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Valid user ID is required.'
        ]);
        exit;
    }

    if (!is_numeric($status)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Status must be a number.'
        ]);
        exit;
    }

    // Convert status to integer
    $status = (int)$status;

    // Check if user exists
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $checkStmt->execute([$userId]);
    $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingUser) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'User not found with ID: ' . $userId
        ]);
        exit;
    }

    // Update the user status
    $updateStmt = $pdo->prepare("
        UPDATE users
        SET status = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $result = $updateStmt->execute([$status, $userId]);

    if ($result && $updateStmt->rowCount() > 0) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'User status updated successfully.',
            'data' => [
                'user_id' => (int)$userId,
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update user status. No rows were affected.'
        ]);
    }

} catch (PDOException $e) {
    logException('users_update_status', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating user status: ' . $e->getMessage()
    ]);
}
?>
