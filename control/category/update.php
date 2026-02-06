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
 * Update Category Endpoint
 * PUT /category/update.php
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
 if (!checkUserPermission($userId, 'categories', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update category.']);
     exit;
 }


// Allow PUT method or POST (for multipart/form-data file uploads)
// PHP $_FILES only works with POST, so we allow POST for file uploads
$isPutRequest = false;
$isPostWithFiles = false;

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $isPutRequest = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Allow POST if it's multipart/form-data (indicated by $_FILES or Content-Type)
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'multipart/form-data') !== false || !empty($_FILES)) {
        $isPostWithFiles = true;
    } else {
        // Check for RESTful method override for non-file POST requests
        $methodOverride = $_POST['_method'] ?? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
        if (strtoupper($methodOverride) === 'PUT') {
            $isPutRequest = true;
        }
    }
}

if (!$isPutRequest && !$isPostWithFiles) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT for JSON or POST for multipart/form-data.']);
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
    // Get input data - check Content-Type to determine if JSON or form data
    $input = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        // Parse JSON input
        $jsonInput = file_get_contents('php://input');
        if (!empty($jsonInput)) {
            $input = json_decode($jsonInput, true);
            if ($input === null) {
                $input = [];
            }
        }
    }
    
    // Get category_id from JSON or form data
    $category_id = null;
    if (isset($input['id']) && $input['id'] !== '' && $input['id'] !== null) {
        $category_id = (int)$input['id'];
    } elseif (isset($_POST['id']) && $_POST['id'] !== '' && $_POST['id'] !== null) {
        $category_id = (int)$_POST['id'];
    }
    
    // Validate required fields
    if (!$category_id || $category_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Field "id" is required.']);
        exit;
    }
    
    // Check if category exists and get current data (including old img path)
    $stmt = $pdo->prepare("SELECT id, name, parent_id, img FROM categories WHERE id = ?");
    $stmt->execute([$category_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Category not found.']);
        exit;
    }
    
    $update_fields = [];
    $params = [];
    $old_img_path = $current['img'] ?? null;
    
    // Get name from JSON or form data
    $name = null;
    if (isset($input['name']) && !empty(trim($input['name']))) {
        $name = trim($input['name']);
    } elseif (isset($_POST['name']) && !empty(trim($_POST['name']))) {
        $name = trim($_POST['name']);
    }
    
    if ($name) {
        // Check for duplicate name under same parent
        // Get parent_id from input or current value
        $parent_id = null;
        if (isset($input['parent_id'])) {
            $parent_id = $input['parent_id'] !== '' && $input['parent_id'] !== null ? (int)$input['parent_id'] : null;
        } elseif (isset($_POST['parent_id'])) {
            $parent_id = $_POST['parent_id'] !== '' && $_POST['parent_id'] !== null ? (int)$_POST['parent_id'] : null;
        } else {
            $parent_id = $current['parent_id'];
        }
        
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
    
    // Get parent_id from JSON or form data
    if (isset($input['parent_id']) || isset($_POST['parent_id'])) {
        $parent_id = null;
        if (isset($input['parent_id'])) {
            $parent_id = $input['parent_id'] !== '' && $input['parent_id'] !== null ? (int)$input['parent_id'] : null;
        } elseif (isset($_POST['parent_id'])) {
            $parent_id = $_POST['parent_id'] !== '' && $_POST['parent_id'] !== null ? (int)$_POST['parent_id'] : null;
        }
        
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
    
    // Get sort_order from JSON or form data
    if (isset($input['sort_order']) || isset($_POST['sort_order'])) {
        $sort_order = null;
        if (isset($input['sort_order'])) {
            $sort_order = (int)$input['sort_order'];
        } elseif (isset($_POST['sort_order'])) {
            $sort_order = (int)$_POST['sort_order'];
        }
        
        if ($sort_order !== null) {
            $update_fields[] = "sort_order = ?";
            $params[] = $sort_order;
        }
    }
    
    // Handle image update
    $new_img = null;
    $should_update_img = false;
    
    // Check for file upload first (multipart form-data)
    if (isset($_FILES['img']) && $_FILES['img']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['img'];
        $error = $file['error'];
        
        if ($error !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'File upload error: ' . $error]);
            exit;
        }
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed types: jpg, jpeg, png, gif, webp']);
            exit;
        }
        
        // Validate file size (max 5MB)
        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxSize) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'File size exceeds maximum allowed size of 5MB']);
            exit;
        }
        
        // Security check
        if (!is_uploaded_file($file['tmp_name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Potential file upload attack detected.']);
            exit;
        }
        
        // Delete old image file if it exists and is a local file
        if ($old_img_path && !filter_var($old_img_path, FILTER_VALIDATE_URL) && strpos($old_img_path, 'uploads/') === 0) {
            $old_file_path = dirname(__DIR__, 2) . '/' . $old_img_path;
            if (file_exists($old_file_path)) {
                @unlink($old_file_path); // Suppress errors for unlinking
            }
        }
        
        // Create upload directory if it doesn't exist
        $uploadDir = dirname(__DIR__, 2) . '/uploads/category';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Failed to create uploads directory: ' . $uploadDir);
        }
        
        // Generate unique filename
        $originalName = basename($file['name'] ?? '');
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeName = 'category_' . $category_id . '_' . uniqid('', true) . ($extension ? '.' . $extension : '');
        $targetPath = $uploadDir . '/' . $safeName;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Failed to move uploaded image to destination.');
        }
        
        // Store relative path
        $new_img = 'uploads/category/' . $safeName;
        $should_update_img = true;
    } 
    // Check for JSON input (backward compatibility)
    elseif (isset($input['img'])) {
        // Allow empty string to clear the image
        if ($input['img'] === '') {
            // Delete old image file if it exists and is a local file
            if ($old_img_path && !filter_var($old_img_path, FILTER_VALIDATE_URL) && strpos($old_img_path, 'uploads/') === 0) {
                $old_file_path = dirname(__DIR__, 2) . '/' . $old_img_path;
                if (file_exists($old_file_path)) {
                    @unlink($old_file_path); // Suppress errors for unlinking
                }
            }
            $new_img = null;
        } else {
            $new_img = trim($input['img']);
            // If updating img and old img exists and is a local file, delete it
            if ($old_img_path && $new_img !== $old_img_path) {
                // Check if old image is a local file path (not a URL)
                if (!filter_var($old_img_path, FILTER_VALIDATE_URL) && strpos($old_img_path, 'uploads/') === 0) {
                    $old_file_path = dirname(__DIR__, 2) . '/' . $old_img_path;
                    if (file_exists($old_file_path)) {
                        @unlink($old_file_path);
                    }
                }
            }
        }
        $should_update_img = true;
    }
    
    if ($should_update_img) {
        $update_fields[] = "img = ?";
        $params[] = $new_img;
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
            c.img,
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
    logException('category_update', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating category: ' . $e->getMessage()
    ]);
}
?>

