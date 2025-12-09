<?php
/**
 * Get All Payment Methods Endpoint
 * GET /pay_method/get_all.php
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
    $status = $_GET['status'] ?? null;

    // Build query
    $sql = "
        SELECT
            id,
            name,
            status,
            create_At
        FROM pay_method
    ";

    $params = [];
    $conditions = [];

    if ($status) {
        $conditions[] = "status = ?";
        $params[] = $status;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY create_At DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $paymentMethods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Payment methods retrieved successfully.',
        'data' => $paymentMethods,
        'count' => count($paymentMethods)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving payment methods: ' . $e->getMessage()
    ]);
}
?>
