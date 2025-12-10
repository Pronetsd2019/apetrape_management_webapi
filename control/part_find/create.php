<?php
/**
 * Create Part Find Request Endpoint
 * POST /part_find_requests/create.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../util/check_permission.php';
require_once __DIR__ . '/../util/error_logger.php';
 
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
 
 // Check if the user has permission to create part finds
 if (!checkUserPermission($userId, 'parts finder', 'create')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to create part finds.']);
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

// Validate required fields
$required_fields = ['user_id', 'message'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

try {
    // Validate user_id exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$input['user_id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    // Insert part find request
    $stmt = $pdo->prepare("
        INSERT INTO part_find_requests (user_id, message, status)
        VALUES (?, ?, ?)
    ");

    $status = $input['status'] ?? 'pending';

    $stmt->execute([
        $input['user_id'],
        $input['message'],
        $status
    ]);

    $request_id = $pdo->lastInsertId();

    // Fetch created request with user details
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

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Part find request created successfully.',
        'data' => $request
    ]);

} catch (PDOException $e) {
    logException('part_find/create', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating part find request: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('part_find/create', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating part find request: ' . $e->getMessage()
    ]);
}
?>

