<?php
/**
 * Create Category Endpoint
 * POST /category/create.php
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

function generateSlug($name, $pdo, $excludeId = null) {
    // Convert to lowercase and replace spaces/special chars with hyphens
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    
    // Check if slug exists
    $baseSlug = $slug;
    $counter = 1;
    
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE slug = ?" . ($excludeId ? " AND id != ?" : ""));
        if ($excludeId) {
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt->execute([$slug]);
        }
        
        if (!$stmt->fetch()) {
            break;
        }
        
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
    
    return $slug;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($input['name']) || empty(trim($input['name']))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Field "name" is required.']);
        exit;
    }
    
    $name = trim($input['name']);
    $parent_id = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int)$input['parent_id'] : null;
    $sort_order = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;
    
    // Validate parent_id exists if provided
    if ($parent_id !== null) {
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
        $stmt->execute([$parent_id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Parent category not found.']);
            exit;
        }
    }
    
    // Check if category name already exists under the same parent
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND " . 
                          ($parent_id !== null ? "parent_id = ?" : "parent_id IS NULL"));
    if ($parent_id !== null) {
        $stmt->execute([$name, $parent_id]);
    } else {
        $stmt->execute([$name]);
    }
    
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Category with this name already exists under the same parent.']);
        exit;
    }
    
    // Generate unique slug
    $slug = generateSlug($name, $pdo);
    
    // Insert category
    $stmt = $pdo->prepare("
        INSERT INTO categories (name, parent_id, slug, sort_order)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$name, $parent_id, $slug, $sort_order]);
    
    $category_id = $pdo->lastInsertId();
    
    // Fetch created category
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            c.parent_id,
            pc.name AS parent_name,
            c.slug,
            c.sort_order,
            c.created_at,
            c.updated_at
        FROM categories c
        LEFT JOIN categories pc ON c.parent_id = pc.id
        WHERE c.id = ?
    ");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Category created successfully.',
        'data' => $category
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating category: ' . $e->getMessage()
    ]);
}
?>

