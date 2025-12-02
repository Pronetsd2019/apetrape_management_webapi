<?php
/**
 * Get All Part Find Requests Endpoint
 * GET /part_find_requests/get_all.php
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
    $user_id = $_GET['user_id'] ?? null;

    // Build query
    $sql = "
        SELECT 
            pfr.id,
            pfr.user_id,
            pfr.message,
            pfr.status,
            pfr.created_at,
            pfr.updated_at,
            u.name as user_name,
            loc.plot as location_plot,
            loc.street as location_street,
            ct.name as  location_city,
            rg.name as location_region,
            co.name as location_country,
            u.surname as user_surname,
            u.email as user_email,
            u.cell as user_cell
        FROM part_find_requests pfr
        INNER JOIN users u ON pfr.user_id = u.id
        INNER JOIN user_address loc ON pfr.user_id = loc.user_id
        INNER JOIN city ct ON loc.city = ct.id
        INNER JOIN region rg ON ct.region_id = rg.id
        INNER JOIN country co ON rg.country_id = co.id
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
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($requests)) {
        // Get all request IDs
        $requestIds = array_column($requests, 'id');
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));

        // Fetch images for all requests
        $stmtImages = $pdo->prepare("
            SELECT id, request_id, img_src
            FROM find_part_img
            WHERE request_id IN ($placeholders)
            ORDER BY request_id ASC, id ASC
        ");
        $stmtImages->execute($requestIds);
        $images = $stmtImages->fetchAll(PDO::FETCH_ASSOC);

        // Group images by request_id
        $imagesByRequest = [];
        foreach ($images as $image) {
            $requestId = $image['request_id'];
            unset($image['request_id']);
            $imagesByRequest[$requestId][] = $image;
        }

        // Attach images to each request
        foreach ($requests as &$request) {
            $request['images'] = $imagesByRequest[$request['id']] ?? [];
        }
        unset($request);
    }

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

