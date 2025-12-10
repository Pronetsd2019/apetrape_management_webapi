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
 * Get All Admins Endpoint
 * GET /admin/get_all.php
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
 if (!checkUserPermission($userId, 'administration', 'read')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to read administrators.']);
     exit;
 }


// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    $status = $_GET['status'] ?? null; // active / inactive
    $role_id = $_GET['role_id'] ?? null;
    $search = $_GET['search'] ?? null;

    $sql = "
        SELECT 
            a.id,
            a.name,
            a.surname,
            a.email,
            a.cell,
            a.role_id,
            r.role_name,
            a.is_active,
            a.created_at,
            a.locked_until
        FROM admins a
        LEFT JOIN roles r ON a.role_id = r.id
    ";

    $conditions = [];
    $params = [];

    if ($status !== null && $status !== '') {
        $conditions[] = 'a.is_active = ?';
        $params[] = (int) $status;
    }

    if ($role_id !== null && $role_id !== '') {
        $conditions[] = 'a.role_id = ?';
        $params[] = (int) $role_id;
    }

    if ($search) {
        $conditions[] = '(a.name LIKE ? OR a.surname LIKE ? OR a.email LIKE ? OR a.cell LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY a.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $admins = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Admins retrieved successfully.',
        'data' => $admins,
        'count' => count($admins)
    ]);

} catch (PDOException $e) {
    logException('admin_get_all', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving admins: ' . $e->getMessage()
    ]);
}
?>
