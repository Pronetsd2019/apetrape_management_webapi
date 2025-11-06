<?php
/**
 * Update Part Find Request Status Endpoint
 * PUT /part_find_requests/update_status.php
 */

require_once __DIR__ . '/../util/connect.php';
header('Content-Type: application/json');

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
$valid_statuses = ['pending', 'in_progress', 'completed', 'cancelled'];
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

    // Build update query
    $update_fields = ["status = ?"];
    $params = [$status];

    // Optional: Update admin response
    if (isset($input['admin_response'])) {
        $update_fields[] = "admin_response = ?";
        $params[] = $input['admin_response'];
    }

    $params[] = $request_id;

    // Update status
    $sql = "UPDATE part_find_requests SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated request with user details
    $stmt = $pdo->prepare("
        SELECT 
            pfr.id,
            pfr.user_id,
            pfr.message,
            pfr.status,
            pfr.admin_response,
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

