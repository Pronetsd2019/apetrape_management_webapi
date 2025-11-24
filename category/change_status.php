<?php
/**
 * Change Category Status Endpoint
 * POST /category/change_status.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($input['id']) || empty($input['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Field "id" is required.']);
        exit;
    }
    
    if (!isset($input['status']) || $input['status'] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Field "status" is required.']);
        exit;
    }
    
    $category_id = (int)$input['id'];
    $status = (int)$input['status'];
    
    // Validate status is numeric
    if (!is_numeric($input['status'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Field "status" must be numeric.']);
        exit;
    }
    
    // Check if category exists
    $stmt = $pdo->prepare("SELECT id, name, status FROM categories WHERE id = ?");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$category) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Category not found.']);
        exit;
    }
    
    // Update status
    $stmt = $pdo->prepare("
        UPDATE categories
        SET status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$status, $category_id]);
    
    // Fetch updated category
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            c.parent_id,
            pc.name AS parent_name,
            c.slug,
            c.status,
            c.sort_order,
            c.created_at,
            c.updated_at
        FROM categories c
        LEFT JOIN categories pc ON c.parent_id = pc.id
        WHERE c.id = ?
    ");
    $stmt->execute([$category_id]);
    $updatedCategory = $stmt->fetch(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Category status updated successfully.',
        'data' => $updatedCategory
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating category status: ' . $e->getMessage()
    ]);
}
?>

