<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

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
 * Update Manufacturer Endpoint
 * PUT /manufacturers/update.php
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
 if (!checkUserPermission($userId, 'manufacturers', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update manufacturers.']);
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

// Get manufacturer_id from JSON or form data
$manufacturer_id = null;
if (isset($input['id']) && $input['id'] !== '' && $input['id'] !== null) {
    $manufacturer_id = (int)$input['id'];
} elseif (isset($_POST['id']) && $_POST['id'] !== '' && $_POST['id'] !== null) {
    $manufacturer_id = (int)$_POST['id'];
}

// Validate manufacturer_id
if (!$manufacturer_id || $manufacturer_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Manufacturer ID is required 2.'] ,
$input, $_POST);
    exit;
}

try {
    // Check if manufacturer exists and get current data (including old img path)
    $stmt = $pdo->prepare("SELECT id, name, img FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);
    $current_manufacturer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current_manufacturer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Manufacturer not found.']);
        exit;
    }
    
    $old_img = $current_manufacturer['img'] ?? null;

    // Build update query dynamically
    $update_fields = [];
    $params = [];

    // Get manufacturer_name from JSON or form data
    $manufacturer_name = null;
    if (isset($input['manufacturer_name']) && !empty(trim($input['manufacturer_name']))) {
        $manufacturer_name = trim($input['manufacturer_name']);
    } elseif (isset($_POST['manufacturer_name']) && !empty(trim($_POST['manufacturer_name']))) {
        $manufacturer_name = trim($_POST['manufacturer_name']);
    }
    
    if ($manufacturer_name) {
        // Check if name already exists for another manufacturer
        $stmt = $pdo->prepare("SELECT id FROM manufacturers WHERE name = ? AND id != ?");
        $stmt->execute([$manufacturer_name, $manufacturer_id]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Manufacturer name already exists.']);
            exit;
        }
        $update_fields[] = "name = ?";
        $params[] = $manufacturer_name;
    }

    // Handle image update
    $img = null;
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
        if ($old_img && !filter_var($old_img, FILTER_VALIDATE_URL) && strpos($old_img, 'uploads/') === 0) {
            $old_file_path = dirname(__DIR__) . '/' . $old_img;
            if (file_exists($old_file_path)) {
                @unlink($old_file_path); // Suppress errors for unlinking
            }
        }
        
        // Create upload directory if it doesn't exist
        $uploadDir = dirname(__DIR__) . '/uploads/brands';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Failed to create uploads directory: ' . $uploadDir);
        }
        
        // Generate unique filename
        $originalName = basename($file['name'] ?? '');
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeName = 'brand_' . $manufacturer_id . '_' . uniqid('', true) . ($extension ? '.' . $extension : '');
        $targetPath = $uploadDir . '/' . $safeName;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Failed to move uploaded image to destination.');
        }
        
        // Store relative path
        $img = 'uploads/brands/' . $safeName;
        $should_update_img = true;
    } 
    // Check for JSON input (backward compatibility)
    elseif (isset($input['img'])) {
        // Allow empty string to clear the logo
        if ($input['img'] === '') {
            // Delete old image file if it exists and is a local file
            if ($old_img && !filter_var($old_img, FILTER_VALIDATE_URL) && strpos($old_img, 'uploads/') === 0) {
                $old_file_path = dirname(__DIR__) . '/' . $old_img;
                if (file_exists($old_file_path)) {
                    @unlink($old_file_path); // Suppress errors for unlinking
                }
            }
            $img = null;
        } else {
            $img = trim($input['img']);
        }
        $should_update_img = true;
    }
    
    if ($should_update_img) {
        $update_fields[] = "img = ?";
        $params[] = $img;
    }

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update.']);
        exit;
    }

    $params[] = $manufacturer_id;

    // Execute update
    $sql = "UPDATE manufacturers SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated manufacturer
    $stmt = $pdo->prepare("SELECT id, name, img, created_at, updated_at FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);
    $manufacturer = $stmt->fetch();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Manufacturer updated successfully.',
        'data' => $manufacturer
    ]);

} catch (PDOException $e) {
    logException('manufacturer_update', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating manufacturer: ' . $e->getMessage()
    ]);
}
?>

