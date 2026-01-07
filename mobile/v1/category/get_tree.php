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
 * Mobile Get Category Tree Endpoint (Hierarchical Structure)
 * GET /mobile/v1/category/get_tree.php
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

function buildTree($categories, $parentId = null) {
    $branch = [];
    
    foreach ($categories as $category) {
        if ($category['parent_id'] == $parentId) {
            $children = buildTree($categories, $category['id']);
            
            $node = [
                'id' => (int)$category['id'],
                'name' => $category['name'],
                'img' => $category['img'] ? $category['img'] : null,
                'parent_id' => $category['parent_id'] ? (int)$category['parent_id'] : null,
                'slug' => $category['slug'],
                'sort_order' => (int)$category['sort_order'],
                'created_at' => $category['created_at'],
                'updated_at' => $category['updated_at']
            ];
            
            if (!empty($children)) {
                $node['children'] = $children;
            }
            
            $branch[] = $node;
        }
    }
    
    return $branch;
}

try {
    $stmt = $pdo->query("
        SELECT 
            id,
            name,
            img,
            parent_id,
            slug,
            sort_order,
            created_at,
            updated_at
        FROM categories
        ORDER BY sort_order ASC, name ASC
    ");
    
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build hierarchical tree
    $tree = buildTree($categories);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Category tree fetched successfully.',
        'data' => $tree,
        'total_categories' => count($categories)
    ]);

} catch (PDOException $e) {
    logException('mobile_category_get_tree', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching category tree. Please try again later.',
        'error_details' => 'Error fetching category tree: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_category_get_tree', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error fetching category tree: ' . $e->getMessage()
    ]);
}
?>

