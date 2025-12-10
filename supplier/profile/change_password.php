<?php
/**
 * Change Supplier Password Endpoint
 * POST /supplier/profile/change_password.php
 */

require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/util/error_logger.php';
require_once __DIR__ . '/../../control/middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get the authenticated supplier's ID from the JWT payload
$authUser = $GLOBALS['auth_user'] ?? null;
$supplierId = $authUser['supplier_id'] ?? null;

if (!$supplierId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated supplier.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['current_password', 'new_password', 'confirm_password'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

// Validate password confirmation matches
if ($input['new_password'] !== $input['confirm_password']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New password confirmation does not match.']);
    exit;
}

// Validate new password is different from current password
if ($input['current_password'] === $input['new_password']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New password must be different from the current password.']);
    exit;
}

// Validate password length (minimum 6 characters)
if (strlen($input['new_password']) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters long.']);
    exit;
}

try {
    // Get supplier details including password hash
    $stmt = $pdo->prepare("
        SELECT id, email, password_hash, status
        FROM suppliers
        WHERE id = ?
    ");
    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$supplier) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        exit;
    }

    // Check if supplier is active
    if ($supplier['status'] !== 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Account is not active.']);
        exit;
    }

    // Verify current password
    $password_correct = password_verify($input['current_password'], $supplier['password_hash']);

    if (!$password_correct) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        exit;
    }

    // Hash new password
    $new_password_hash = password_hash($input['new_password'], PASSWORD_DEFAULT);

    // Update password and reset lock stats
    $stmt = $pdo->prepare("
        UPDATE suppliers
        SET password_hash = ?, failed_attempts = 0, locked_until = NULL, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$new_password_hash, $supplierId]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Password changed successfully.'
    ]);

} catch (PDOException $e) {
    logException('supplier_profile_change_password', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error changing password: ' . $e->getMessage()
    ]);
}
?>
