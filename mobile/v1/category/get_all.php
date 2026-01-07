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
 * Mobile Get All Categories Endpoint (Flat List)
 * GET /mobile/v1/category/get_all.php
 * Public endpoint - no authentication required
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';

header('Content-Type: application/json');

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    $stmt = $pdo->query("
        SELECT 
            c.id,
            c.name,
            c.parent_id,
            pc.name AS parent_name,
            c.slug,
            c.sort_order,
            c.img,
            c.created_at,
            c.updated_at,
            (SELECT COUNT(*) FROM categories WHERE parent_id = c.id) AS children_count
        FROM categories c
        LEFT JOIN categories pc ON c.parent_id = pc.id
        ORDER BY c.sort_order ASC, c.name ASC
    ");
    
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response
    $formatted_categories = [];
    foreach ($categories as $category) {
        $formatted_categories[] = [
            'id' => (int)$category['id'],
            'name' => $category['name'],
            'parent_id' => $category['parent_id'] ? (int)$category['parent_id'] : null,
            'parent_name' => $category['parent_name'] ? $category['parent_name'] : null,
            'slug' => $category['slug'],
            'sort_order' => (int)$category['sort_order'],
            'img' => $category['img'] ? $category['img'] : null,
            'children_count' => (int)$category['children_count'],
            'created_at' => $category['created_at'],
            'updated_at' => $category['updated_at']
        ];
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Categories fetched successfully.',
        'data' => $formatted_categories,
        'count' => count($formatted_categories)
    ]);

} catch (PDOException $e) {
    logException('mobile_category_get_all', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching categories. Please try again later.',
        'error_details' => 'Error fetching categories: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_category_get_all', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error fetching categories: ' . $e->getMessage()
    ]);
}
?>

