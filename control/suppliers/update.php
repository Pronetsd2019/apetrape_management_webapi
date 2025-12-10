<?php
/**
 * Update Supplier Endpoint
 * PUT /suppliers/update.php
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

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
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
    $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
    $stmt->execute([$supplier_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        exit;
    }

    // Build update query dynamically based on provided fields
    $update_fields = [];
    $params = [];

    if (isset($input['name'])) {
        $update_fields[] = "name = ?";
        $params[] = $input['name'];
    }
    if (isset($input['email'])) {
        // Check if email already exists for another supplier
        $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE email = ? AND id != ?");
        $stmt->execute([$input['email'], $supplier_id]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Email already exists.']);
            exit;
        }
        $update_fields[] = "email = ?";
        $params[] = $input['email'];
    }
    if (isset($input['cellphone'])) {
        $update_fields[] = "cellphone = ?";
        $params[] = $input['cellphone'];
    }
    if (isset($input['telephone'])) {
        $update_fields[] = "telephone = ?";
        $params[] = $input['telephone'];
    }

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update.']);
        exit;
    }

    // Add supplier_id to params
    $params[] = $supplier_id;

    // Execute update
    $sql = "UPDATE suppliers SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated supplier
    $stmt = $pdo->prepare("
        SELECT id, name, email, cellphone, telephone, created_at, updated_at
        FROM suppliers WHERE id = ?
    ");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Supplier updated successfully.',
        'data' => $supplier
    ]);

} catch (PDOException $e) {
    logException('suppliers_update', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating supplier: ' . $e->getMessage()
    ]);
}
?>

