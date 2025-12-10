<?php
/**
 * Update User Endpoint
 * PUT /users/update.php
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
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update a user.']);
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
    if (!isset($input['id']) || !isset($input['name']) || !isset($input['surname']) || !isset($input['email']) || !isset($input['cell'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields: id, name, surname, email, and cell are required.'
        ]);
        exit;
    }

    $userId = trim($input['id']);
    $name = trim($input['name']);
    $surname = trim($input['surname']);
    $email = trim($input['email']);
    $cell = trim($input['cell']);

    // Validate input
    if (empty($userId) || !is_numeric($userId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Valid user ID is required.'
        ]);
        exit;
    }

    if (empty($name)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Name cannot be empty.'
        ]);
        exit;
    }

    if (empty($surname)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Surname cannot be empty.'
        ]);
        exit;
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Valid email address is required.'
        ]);
        exit;
    }

    if (empty($cell)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Cell phone number cannot be empty.'
        ]);
        exit;
    }

    // Check if user exists
    $checkUserStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $checkUserStmt->execute([$userId]);
    $existingUser = $checkUserStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingUser) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'User not found with ID: ' . $userId
        ]);
        exit;
    }

    // Check for email conflicts (excluding current user)
    $checkEmailStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $checkEmailStmt->execute([$email, $userId]);
    $emailConflict = $checkEmailStmt->fetch(PDO::FETCH_ASSOC);

    if ($emailConflict) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Email address is already in use by another user.'
        ]);
        exit;
    }

    // Check for cell conflicts (excluding current user)
    $checkCellStmt = $pdo->prepare("SELECT id FROM users WHERE cell = ? AND id != ?");
    $checkCellStmt->execute([$cell, $userId]);
    $cellConflict = $checkCellStmt->fetch(PDO::FETCH_ASSOC);

    if ($cellConflict) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Cell phone number is already in use by another user.'
        ]);
        exit;
    }

    // Update the user
    $updateStmt = $pdo->prepare("
        UPDATE users
        SET name = ?, surname = ?, email = ?, cell = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $result = $updateStmt->execute([$name, $surname, $email, $cell, $userId]);

    if ($result && $updateStmt->rowCount() > 0) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'User updated successfully.',
            'data' => [
                'id' => (int)$userId,
                'name' => $name,
                'surname' => $surname,
                'email' => $email,
                'cell' => $cell,
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update user. No changes were made.'
        ]);
    }

} catch (PDOException $e) {
    logException('users_update', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating user: ' . $e->getMessage()
    ]);
}
?>
