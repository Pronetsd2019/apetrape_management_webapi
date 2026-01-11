<?php

// CORS headers for subdomain support and localhost
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';
$isLocalhostOrigin = isset($_SERVER['HTTP_ORIGIN']) && (
    strpos($_SERVER['HTTP_ORIGIN'], 'http://localhost') === 0 ||
    strpos($_SERVER['HTTP_ORIGIN'], 'http://127.0.0.1') === 0
);

if ((isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) || $isLocalhostOrigin) {
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
 * Mobile Part Find Requests Get Endpoint
 * GET /mobile/v1/part_find/get.php
 * Requires JWT authentication - retrieves user's part find requests with images
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

// Ensure the request is authenticated
$authUser = requireMobileJwtAuth();
$user_id = (int)($authUser['user_id'] ?? null);

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Unable to identify authenticated user.'
    ]);
    exit;
}

header('Content-Type: application/json');

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    // Parse query parameters
    $status = isset($_GET['status']) ? (int)$_GET['status'] : null;
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20; // Max 100, default 20
    $offset = isset($_GET['offset']) ? max((int)$_GET['offset'], 0) : 0;

    // Build WHERE clause
    $where_conditions = ['pfr.user_id = ?', 'pfr.status != \'deleted\''];
    $params = [$user_id];

    if ($status !== null) {
        $where_conditions[] = 'pfr.status = ?';
        $params[] = $status;
    }

    $where_clause = implode(' AND ', $where_conditions);

    // Get total count for pagination info
    $count_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM part_find_requests pfr WHERE {$where_clause}");
    $count_stmt->execute($params);
    $total_count = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get requests with images and quotes
    $stmt = $pdo->prepare("
        SELECT
            pfr.id,
            pfr.user_id,
            pfr.message,
            pfr.part_name,
            pfr.status,
            pfr.created_at,
            pfr.updated_at,
            fpi.id as image_id,
            fpi.img_src,
            pfq.id as quotation_id,
            pfq.quote_id
        FROM part_find_requests pfr
        LEFT JOIN find_part_img fpi ON pfr.id = fpi.request_id
        LEFT JOIN part_find_qoutations pfq ON pfr.id = pfq.part_find_id
        WHERE {$where_clause}
        ORDER BY pfr.created_at DESC, fpi.id ASC, pfq.id ASC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group results by request
    $requests = [];
    foreach ($rows as $row) {
        $request_id = $row['id'];

        if (!isset($requests[$request_id])) {
            $requests[$request_id] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'message' => $row['message'],
                'part_name' => $row['part_name'],
                'status' => (int)$row['status'],
                'images' => [],
                'quotations' => [],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }

        // Add image if exists
        if ($row['image_id']) {
            $requests[$request_id]['images'][] = [
                'id' => (int)$row['image_id'],
                'request_id' => (int)$row['id'],
                'img_src' => $row['img_src']
            ];
        }

        // Add quotation if exists
        if ($row['quotation_id']) {
            $requests[$request_id]['quotations'][] = [
                'id' => (int)$row['quotation_id'],
                'part_find_id' => (int)$row['id'],
                'quote_id' => (int)$row['quote_id']
            ];
        }
    }

    $requests_array = array_values($requests);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Part find requests retrieved successfully.',
        'data' => $requests_array,
        'pagination' => [
            'total_count' => $total_count,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total_count
        ],
        'count' => count($requests_array)
    ]);

} catch (PDOException $e) {
    logException('mobile_part_find_get', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while retrieving your part find requests. Please try again later.',
        'error_details' => 'Error retrieving part find requests: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_part_find_get', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error retrieving part find requests: ' . $e->getMessage()
    ]);
}
?>
