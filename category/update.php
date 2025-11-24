<?php
/**
 * Update Category Endpoint
 * PUT /category/update.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

function generateSlug($name, $pdo, $excludeId = null) {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    
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
    if (!isset($input['id']) || empty($input['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Field "id" is required.']);
        exit;
    }
    
    $category_id = (int)$input['id'];
    
    // Check if category exists
    $stmt = $pdo->prepare("SELECT id, name, parent_id FROM categories WHERE id = ?");
    $stmt->execute([$category_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Category not found.']);
        exit;
    }
    
    $update_fields = [];
    $params = [];
    
    if (isset($input['name']) && trim($input['name']) !== '') {
        $name = trim($input['name']);
        
        // Check for duplicate name under same parent
        $parent_id = $input['parent_id'] ?? $current['parent_id'];
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND id != ? AND " . 
                              ($parent_id !== null ? "parent_id = ?" : "parent_id IS NULL"));
        if ($parent_id !== null) {
            $stmt->execute([$name, $category_id, $parent_id]);
        } else {
            $stmt->execute([$name, $category_id]);
        }
        
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Category with this name already exists under the same parent.']);
            exit;
        }
        
        $update_fields[] = "name = ?";
        $params[] = $name;
        
        // Regenerate slug if name changed
        $slug = generateSlug($name, $pdo, $category_id);
        $update_fields[] = "slug = ?";
        $params[] = $slug;
    }
    
    if (isset($input['parent_id'])) {
        $parent_id = $input['parent_id'] !== '' && $input['parent_id'] !== null ? (int)$input['parent_id'] : null;
        
        // Prevent setting self as parent
        if ($parent_id === $category_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Category cannot be its own parent.']);
            exit;
        }
        
        // Prevent circular reference (setting parent to own descendant)
        if ($parent_id !== null) {
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
            $stmt->execute([$parent_id]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Parent category not found.']);
                exit;
            }
            
            // Check if parent_id is a descendant of current category
            $checkId = $parent_id;
            while ($checkId !== null) {
                if ($checkId === $category_id) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Cannot set a descendant category as parent (circular reference).']);
                    exit;
                }
                
                $stmt = $pdo->prepare("SELECT parent_id FROM categories WHERE id = ?");
                $stmt->execute([$checkId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $checkId = $result ? $result['parent_id'] : null;
            }
        }
        
        $update_fields[] = "parent_id = ?";
        $params[] = $parent_id;
    }
    
    if (isset($input['sort_order'])) {
        $update_fields[] = "sort_order = ?";
        $params[] = (int)$input['sort_order'];
    }
    
    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update.']);
        exit;
    }
    
    // Update category
    $params[] = $category_id;
    $sql = "UPDATE categories SET " . implode(', ', $update_fields) . ", updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // Fetch updated category
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
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Category updated successfully.',
        'data' => $category
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating category: ' . $e->getMessage()
    ]);
}
?>

