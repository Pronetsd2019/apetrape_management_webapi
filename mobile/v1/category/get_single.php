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
 * Mobile Get Single Category with Children Endpoint
 * GET /mobile/v1/category/get_single.php?id=1
 * GET /mobile/v1/category/get_single.php?slug=electronics
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
    $id = $_GET['id'] ?? null;
    $slug = $_GET['slug'] ?? null;
    
    if (!$id && !$slug) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Either "id" or "slug" parameter is required.']);
        exit;
    }
    
    // Fetch category by id or slug
    if ($id) {
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.name,
                c.parent_id,
                pc.name AS parent_name,
                pc.slug AS parent_slug,
                c.slug,
                c.img,
                c.sort_order,
                c.created_at,
                c.updated_at
            FROM categories c
            LEFT JOIN categories pc ON c.parent_id = pc.id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.name,
                c.parent_id,
                pc.name AS parent_name,
                pc.slug AS parent_slug,
                c.slug,
                c.img,
                c.sort_order,
                c.created_at,
                c.updated_at
            FROM categories c
            LEFT JOIN categories pc ON c.parent_id = pc.id
            WHERE c.slug = ?
        ");
        $stmt->execute([$slug]);
    }
    
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$category) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Category not found.']);
        exit;
    }
    
    // Fetch direct children
    $stmt = $pdo->prepare("
        SELECT 
            id,
            name,
            parent_id,
            slug,
            img,
            sort_order,
            created_at,
            updated_at,
            (SELECT COUNT(*) FROM categories WHERE parent_id = c.id) AS children_count
        FROM categories c
        WHERE parent_id = ?
        ORDER BY sort_order ASC, name ASC
    ");
    $stmt->execute([$category['id']]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format children
    $formatted_children = [];
    foreach ($children as $child) {
        $formatted_children[] = [
            'id' => (int)$child['id'],
            'name' => $child['name'],
            'parent_id' => (int)$child['parent_id'],
            'slug' => $child['slug'],
            'img' => $child['img'] ? $child['img'] : null,
            'sort_order' => (int)$child['sort_order'],
            'children_count' => (int)$child['children_count'],
            'created_at' => $child['created_at'],
            'updated_at' => $child['updated_at']
        ];
    }
    
    // Fetch breadcrumb path
    $breadcrumb = [];
    $currentId = $category['parent_id'];
    
    while ($currentId !== null) {
        $stmt = $pdo->prepare("SELECT id, name, slug, parent_id FROM categories WHERE id = ?");
        $stmt->execute([$currentId]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($parent) {
            array_unshift($breadcrumb, [
                'id' => (int)$parent['id'],
                'name' => $parent['name'],
                'slug' => $parent['slug']
            ]);
            $currentId = $parent['parent_id'];
        } else {
            break;
        }
    }
    
    // Format category response
    $formatted_category = [
        'id' => (int)$category['id'],
        'name' => $category['name'],
        'parent_id' => $category['parent_id'] ? (int)$category['parent_id'] : null,
        'parent_name' => $category['parent_name'] ? $category['parent_name'] : null,
        'parent_slug' => $category['parent_slug'] ? $category['parent_slug'] : null,
        'slug' => $category['slug'],
        'img' => $category['img'] ? $category['img'] : null,
        'sort_order' => (int)$category['sort_order'],
        'children' => $formatted_children,
        'breadcrumb' => $breadcrumb,
        'created_at' => $category['created_at'],
        'updated_at' => $category['updated_at']
    ];
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Category fetched successfully.',
        'data' => $formatted_category
    ]);

} catch (PDOException $e) {
    logException('mobile_category_get_single', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching category. Please try again later.',
        'error_details' => 'Error fetching category: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_category_get_single', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error fetching category: ' . $e->getMessage()
    ]);
}
?>

