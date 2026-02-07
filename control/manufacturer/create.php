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
 * Create Manufacturer Endpoint
 * POST /manufacturers/create.php
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
 if (!checkUserPermission($userId, 'manufacturers', 'create')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to create manufacturers.']);
     exit;
 }


// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input (for backward compatibility)
$input = json_decode(file_get_contents('php://input'), true);
if ($input === null) {
    $input = [];
}

// Get manufacturer name from JSON or form data
$manufacturer_name = null;
if (isset($input['manufacturer_name']) && !empty($input['manufacturer_name'])) {
    $manufacturer_name = trim($input['manufacturer_name']);
} elseif (isset($_POST['manufacturer_name']) && !empty($_POST['manufacturer_name'])) {
    $manufacturer_name = trim($_POST['manufacturer_name']);
}

// Validate required fields
if (empty($manufacturer_name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "manufacturer_name" is required.']);
    exit;
}

try {
    // Check if manufacturer name already exists
    $stmt = $pdo->prepare("SELECT id FROM manufacturers WHERE name = ?");
    $stmt->execute([$manufacturer_name]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Manufacturer name already exists.']);
        exit;
    }

    // Handle image upload
    $img = null;
    
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
        
        // Create upload directory if it doesn't exist
        $uploadDir = dirname(dirname(__DIR__, 2)) . '/uploads/brands';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Failed to create uploads directory: ' . $uploadDir);
        }
        
        // Generate unique filename
        $originalName = basename($file['name'] ?? '');
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        // Use temporary prefix since we don't have manufacturer_id yet
        $safeName = uniqid('brand_', true) . ($extension ? '.' . $extension : '');
        $targetPath = $uploadDir . '/' . $safeName;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Failed to move uploaded image to destination.');
        }
        
        // Store relative path
        $img = 'uploads/brands/' . $safeName;
    } 
    // Fallback to JSON input for backward compatibility
    elseif (isset($input['img']) && !empty($input['img'])) {
        $img = trim($input['img']);
    }
    
    // Insert manufacturer
    $stmt = $pdo->prepare("
        INSERT INTO manufacturers (name, img)
        VALUES (?, ?)
    ");

    $stmt->execute([
        $manufacturer_name,
        $img
    ]);

    $manufacturer_id = $pdo->lastInsertId();
    
    // If we used a temporary filename, rename it with manufacturer_id
    if ($img && strpos($img, 'uploads/brands/brand_') === 0) {
        $oldPath = dirname(dirname(__DIR__, 2)) . '/' . $img;
        $extension = pathinfo($img, PATHINFO_EXTENSION);
        $newName = 'brand_' . $manufacturer_id . '_' . uniqid('', true) . ($extension ? '.' . $extension : '');
        $newPath = dirname(dirname(__DIR__, 2)) . '/uploads/brands/' . $newName;
        
        if (file_exists($oldPath) && rename($oldPath, $newPath)) {
            $img = 'uploads/brands/' . $newName;
            // Update the database with the new filename
            $stmt = $pdo->prepare("UPDATE manufacturers SET img = ? WHERE id = ?");
            $stmt->execute([$img, $manufacturer_id]);
        }
    }

    // Fetch created manufacturer
    $stmt = $pdo->prepare("SELECT id, name, img, created_at, updated_at FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);
    $manufacturer = $stmt->fetch();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Manufacturer created successfully.',
        'data' => $manufacturer
    ]);

} catch (PDOException $e) {
    logException('manufacturer_create', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating manufacturer: ' . $e->getMessage()
    ]);
}
?>

