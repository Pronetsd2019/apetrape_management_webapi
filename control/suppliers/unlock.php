<?php
/**
 * Unlock Supplier Account Endpoint
 * POST /suppliers/unlock.php
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
 if (!checkUserPermission($userId, 'suppliers', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update suppliers.']);
     exit;
 }

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate supplier_id
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Supplier ID is required.']);
    exit;
}

$supplier_id = $input['id'];

try {
    // Check if supplier exists
    $stmt = $pdo->prepare("
        SELECT id, name, email, failed_attempts, locked_until
        FROM suppliers
        WHERE id = ?
    ");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch();

    if (!$supplier) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        exit;
    }

    // Check if account is actually locked
    $is_locked = $supplier['locked_until'] && strtotime($supplier['locked_until']) > time();
    $has_failed_attempts = $supplier['failed_attempts'] > 0;

    if (!$is_locked && !$has_failed_attempts) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Account is not locked.',
            'data' => [
                'id' => $supplier['id'],
                'name' => $supplier['name'],
                'email' => $supplier['email'],
                'failed_attempts' => 0,
                'locked_until' => null
            ]
        ]);
        exit;
    }

    // Unlock the account
    $stmt = $pdo->prepare("
        UPDATE suppliers
        SET failed_attempts = 0, locked_until = NULL, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$supplier_id]);

    // Fetch updated supplier
    $stmt = $pdo->prepare("
        SELECT id, name, email, failed_attempts, locked_until
        FROM suppliers
        WHERE id = ?
    ");
    $stmt->execute([$supplier_id]);
    $unlocked_supplier = $stmt->fetch();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Account unlocked successfully.',
        'data' => [
            'id' => $unlocked_supplier['id'],
            'name' => $unlocked_supplier['name'],
            'email' => $unlocked_supplier['email'],
            'failed_attempts' => 0,
            'locked_until' => null
        ]
    ]);

} catch (PDOException $e) {
    logException('suppliers_unlock', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error unlocking account: ' . $e->getMessage()
    ]);
}
?>


