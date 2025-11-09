<?php
/**
 * Get All Admins Endpoint
 * GET /admin/get_all.php
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
            a.created_at
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
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving admins: ' . $e->getMessage()
    ]);
}
?>
