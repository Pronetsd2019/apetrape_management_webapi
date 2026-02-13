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
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Mobile Order – Get delivery stages
 * GET /mobile/v1/order/get_delivery_stages.php
 * Returns delivery stages sorted by order (requires JWT).
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    $stmt = $pdo->query("
        SELECT `id`, `name`, `order`, `created_at`
        FROM `delivery_stages`
        ORDER BY `order`
    ");
    $stages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = array_map(function ($row) {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'order' => (int)$row['order'],
            'create_at' => $row['created_at']
        ];
    }, $stages);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Delivery stages retrieved.',
        'data' => $data
    ]);

} catch (PDOException $e) {
    logException('mobile_order_get_delivery_stages', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching delivery stages.'
    ]);
} catch (Exception $e) {
    logException('mobile_order_get_delivery_stages', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching delivery stages.'
    ]);
}
