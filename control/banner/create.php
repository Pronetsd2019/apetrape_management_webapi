<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Create Banner Endpoint
 * POST /banner/create.php
 *
 * Expects multipart/form-data:
 *   - image (file, required): banner image to upload
 *   - filter (string, optional): filter/category
 *   - status (int, optional): default 1
 *   - weight (int, optional): sort order, default 0
 * Uploaded image is saved under uploads/banners; its URL path is stored in banner.url.
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../util/check_permission.php';

requireJwtAuth();

header('Content-Type: application/json');

$authUser = $GLOBALS['auth_user'] ?? null;
$userId = $authUser['admin_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
    exit;
}

if (!checkUserPermission($userId, 'banner', 'create')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to create banners.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

function respondBadRequest(string $message): never
{
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Form fields (multipart/form-data)
$filter = trim($_POST['filter'] ?? '');
$status = isset($_POST['status']) ? (int)$_POST['status'] : 1;
$weight = isset($_POST['weight']) ? (int)$_POST['weight'] : 0;

try {
    $url = null;

    // Require image upload
    if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        respondBadRequest('Field "image" is required. Please upload a banner image.');
    }

    $file = $_FILES['image'];
    $error = $file['error'];

    if ($error !== UPLOAD_ERR_OK) {
        respondBadRequest('File upload error: ' . $error);
    }

    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        respondBadRequest('Invalid file type. Allowed: jpg, jpeg, png, gif, webp.');
    }

    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        respondBadRequest('File size exceeds maximum allowed (5MB).');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        respondBadRequest('Invalid file upload.');
    }

    $uploadDir = dirname(dirname(__DIR__, 2)) . '/uploads/banners';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Failed to create uploads directory: ' . $uploadDir);
    }

    $originalName = basename($file['name'] ?? '');
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $safeName = uniqid('banner_', true) . ($extension ? '.' . $extension : '');
    $targetPath = $uploadDir . '/' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Failed to move uploaded image.');
    }

    $url = 'uploads/banners/' . $safeName;

    // Insert banner
    $stmt = $pdo->prepare("
        INSERT INTO banner (url, filter, status, weight, create_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([$url, $filter !== '' ? $filter : null, $status, $weight]);

    $banner_id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("
        SELECT id, url, filter, create_at, updated_at, status, weight
        FROM banner
        WHERE id = ?
    ");
    $stmt->execute([$banner_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $row = [
            'id' => $banner_id,
            'url' => $url,
            'filter' => $filter,
            'status' => $status,
            'weight' => $weight,
            'create_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Banner created successfully.',
        'data' => $row
    ]);

} catch (PDOException $e) {
    logException('banner_create', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating banner: ' . $e->getMessage()
    ]);
}
