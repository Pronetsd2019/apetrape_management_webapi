<?php
/**
 * Get Category Tree Endpoint (Hierarchical Structure)
 * GET /category/get_tree.php
 */

 require_once __DIR__ . '/../util/connect.php';
 require_once __DIR__ . '/../middleware/auth_middleware.php';
 require_once __DIR__ . '/../util/check_permission.php';
 
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
 if (!checkUserPermission($userId, 'categories', 'read')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to read category.']);
     exit;
 }


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
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching category tree: ' . $e->getMessage()
    ]);
}
?>

