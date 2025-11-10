<?php
/**
 * Reset Supplier Password Endpoint
 * POST /suppliers/password_reset.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['id', 'admin_id', 'password', 'password_confirmation'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

if ($input['password'] !== $input['password_confirmation']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password confirmation does not match.']);
    exit;
}

$supplier_id = $input['id'];
$admin_id = $input['admin_id'];

try {
    // Ensure requesting admin exists
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Requesting admin not found.']);
        exit;
    }

    // Find supplier
    $stmt = $pdo->prepare("
        SELECT id, name, email
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

    // Hash new password
    $password_hash = password_hash($input['password'], PASSWORD_DEFAULT);

    // Update supplier password and reset lock stats
    $stmt = $pdo->prepare("
        UPDATE suppliers
        SET password_hash = ?, failed_attempts = 0, locked_until = NULL, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$password_hash, $supplier_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Supplier password reset successfully.',
        'data' => [
            'id' => $supplier['id'],
            'name' => $supplier['name'],
            'email' => $supplier['email'],
            'reset_by_admin_id' => $admin_id
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error resetting supplier password: ' . $e->getMessage()
    ]);
}
?>


