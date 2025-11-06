<?php
/**
 * Get All Part Find Requests Endpoint
 * GET /part_find_requests/get_all.php
 */

require_once __DIR__ . '/../util/connect.php';
header('Content-Type: application/json');

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    // Get optional query parameters for filtering
    $status = $_GET['status'] ?? null;
    $user_id = $_GET['user_id'] ?? null;

    // Build query
    $sql = "
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
            u.email as user_email,
            u.cell as user_cell
        FROM part_find_requests pfr
        INNER JOIN users u ON pfr.user_id = u.id
    ";

    $params = [];
    $conditions = [];

    if ($status) {
        $conditions[] = "pfr.status = ?";
        $params[] = $status;
    }

    if ($user_id) {
        $conditions[] = "pfr.user_id = ?";
        $params[] = $user_id;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY pfr.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Part find requests retrieved successfully.',
        'data' => $requests,
        'count' => count($requests)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving part find requests: ' . $e->getMessage()
    ]);
}
?>

