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
 * Delete Category Endpoint
 * DELETE /category/delete.php
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
 if (!checkUserPermission($userId, 'categories', 'delete')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to delete category.']);
     exit;
 }


// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id']) || empty($input['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Field "id" is required.']);
        exit;
    }
    
    $category_id = (int)$input['id'];
    
    // Check if category exists
    $stmt = $pdo->prepare("SELECT id, name, img FROM categories WHERE id = ?");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$category) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Category not found.']);
        exit;
    }
    
    // Check if category has children
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM categories WHERE parent_id = ?");
    $stmt->execute([$category_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['total'] > 0) {
        http_response_code(409);
        echo json_encode([
            'success' => false, 
            'message' => 'Cannot delete category with subcategories. Please delete or reassign subcategories first.',
            'children_count' => (int)$result['total']
        ]);
        exit;
    }
    
    // Note: You may want to add checks for related items/products here
    // Example: Check if any items are linked to this category
    // $stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE category_id = ?");
    
    // Delete image file if it exists and is a local file
    if (!empty($category['img'])) {
        $img_path = $category['img'];
        // Check if it's a local file (not a URL)
        if (!filter_var($img_path, FILTER_VALIDATE_URL) && strpos($img_path, 'uploads/') === 0) {
            $file_path = dirname(dirname(__DIR__, 2)) . '/' . $img_path;
            if (file_exists($file_path)) {
                @unlink($file_path); // Suppress errors for unlinking
            }
        }
    }
    
    // Delete category
    $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
    $stmt->execute([$category_id]);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Category deleted successfully.'
    ]);

} catch (PDOException $e) {
    logException('category_delete', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting category: ' . $e->getMessage()
    ]);
}
?>

