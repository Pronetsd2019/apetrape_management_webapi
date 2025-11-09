<?php
/**
 * Get All Users Endpoint
 * GET /users/get_all.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    // Get optional query parameters for filtering
    $search = $_GET['search'] ?? null;
    $email = $_GET['email'] ?? null;

    // Build query
    $sql = "
        SELECT 
            id,
            name,
            surname,
            email,
            cell,
            created_at,
            updated_at
        FROM users
    ";

    $params = [];
    $conditions = [];

    if ($email) {
        $conditions[] = "email = ?";
        $params[] = $email;
    }

    if ($search) {
        $conditions[] = "(name LIKE ? OR surname LIKE ? OR email LIKE ?)";
        $search_term = '%' . $search . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Users retrieved successfully.',
        'data' => $users,
        'count' => count($users)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving users: ' . $e->getMessage()
    ]);
}
?>

