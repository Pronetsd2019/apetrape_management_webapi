<?php
/**
 * Get Single Category with Children Endpoint
 * GET /category/get_single.php?id=1
 * GET /category/get_single.php?slug=electronics
 */

 require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
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
    
    $category['children'] = $children;
    $category['breadcrumb'] = $breadcrumb;
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Category fetched successfully.',
        'data' => $category
    ]);

} catch (PDOException $e) {
    logException('category_get_single', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching category: ' . $e->getMessage()
    ]);
}
?>

