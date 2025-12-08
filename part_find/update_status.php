<?php
/**
 * Update Part Find Request Status Endpoint
 * PUT /part_find_requests/update_status.php
 */

 require_once __DIR__ . '/../util/connect.php';
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
 if (!checkUserPermission($userId, 'parts finder', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update part finds.']);
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

// Validate required fields
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Request ID is required.']);
    exit;
}

if (!isset($input['status']) || empty($input['status'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Status is required.']);
    exit;
}

$request_id = $input['id'];
$status = $input['status'];

// Validate status value
$valid_statuses = ['pending','failed', 'in_progress', 'completed', 'cancelled','thrown_out'];
if (!in_array($status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid status. Valid statuses: ' . implode(', ', $valid_statuses)
    ]);
    exit;
}

try {
    // Check if request exists
    $stmt = $pdo->prepare("SELECT id FROM part_find_requests WHERE id = ?");
    $stmt->execute([$request_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Part find request not found.']);
        exit;
    }

    // Update status
    $stmt = $pdo->prepare("UPDATE part_find_requests SET status = ? WHERE id = ?");
    $stmt->execute([$status, $request_id]);

    // Fetch updated request with user details
    $stmt = $pdo->prepare("
        SELECT 
            pfr.id,
            pfr.user_id,
            pfr.message,
            pfr.status,
            pfr.created_at,
            pfr.updated_at,
            u.name as user_name,
            u.surname as user_surname,
            u.email as user_email
        FROM part_find_requests pfr
        INNER JOIN users u ON pfr.user_id = u.id
        WHERE pfr.id = ?
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully.',
        'data' => $request
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating status: ' . $e->getMessage()
    ]);
}
?>

