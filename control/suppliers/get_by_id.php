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
 * Get Supplier by ID Endpoint
 * GET /suppliers/get_by_id.php?id={supplier_id}
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
 if (!checkUserPermission($userId, 'suppliers', 'read')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to read suppliers.']);
     exit;
 }

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    // Get supplier ID from query parameters
    $supplierId = $_GET['id'] ?? null;

    // Validate supplier ID
    if (!$supplierId || !is_numeric($supplierId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Valid supplier ID is required as query parameter: ?id={supplier_id}'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            s.id AS supplier_id,
            s.name AS supplier_name,
            s.cellphone,
            s.telephone,
            s.email,
            s.reg,
            s.status,
            s.locked_until,
            s.created_at AS entry_date,
            COUNT(DISTINCT st.id) AS number_of_stores,
            COUNT(DISTINCT i.id) AS total_items
        FROM suppliers s
        LEFT JOIN stores st ON st.supplier_id = s.id
        LEFT JOIN items i ON i.supplier_id = s.id
        WHERE s.id = ?
        GROUP BY s.id, s.name, s.cellphone, s.email, s.reg, s.status, s.locked_until, s.created_at
    ");

    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$supplier) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Supplier not found with ID: ' . $supplierId
        ]);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Supplier fetched successfully.',
        'data' => $supplier
    ]);

} catch (PDOException $e) {
    logException('suppliers_get_by_id', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching supplier: ' . $e->getMessage()
    ]);
}
?>
