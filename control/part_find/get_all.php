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
 * Get All Part Find Requests Endpoint
 * GET /part_find_requests/get_all.php
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
 
 // Check if the user has permission to create a country
 if (!checkUserPermission($userId, 'parts finder', 'read')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to read part finds.']);
     exit;
 }

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
    $sort = strtolower($_GET['sort'] ?? 'desc');

    // Validate sort parameter
    if (!in_array($sort, ['asc', 'desc'])) {
        $sort = 'desc';
    }

    // Build query (user_addresses has formatted_address, street, city, region, country - no plot; city/region/country are text)
    $sql = "
        SELECT 
            pfr.id,
            pfr.user_id,
            pfr.message,
            pfr.status,
            pfr.created_at,
            pfr.updated_at,
            u.name as user_name,
            loc.formatted_address as location_plot,
            loc.street as location_street,
            loc.city as location_city,
            loc.region as location_region,
            loc.country as location_country,
            u.surname as user_surname,
            u.email as user_email,
            u.cell as user_cell,
            pfq.quote_id,
            q.quote_no
        FROM part_find_requests pfr
        INNER JOIN users u ON pfr.user_id = u.id
        LEFT JOIN user_addresses loc ON pfr.user_id = loc.user_id
        LEFT JOIN part_find_qoutations pfq ON pfr.id = pfq.part_find_id
        LEFT JOIN quotations q ON pfq.quote_id = q.id
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

    $sql .= " ORDER BY pfr.updated_at " . strtoupper($sort);

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
    logException('part_find/get_all', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving part find requests: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('part_find/get_all', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving part find requests: ' . $e->getMessage()
    ]);
}
?>

