<?php

// CORS headers for subdomain support and localhost
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';
$isLocalhostOrigin = isset($_SERVER['HTTP_ORIGIN']) && (
    strpos($_SERVER['HTTP_ORIGIN'], 'http://localhost') === 0 ||
    strpos($_SERVER['HTTP_ORIGIN'], 'http://127.0.0.1') === 0
);

if ((isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) || $isLocalhostOrigin) {
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
 * Mobile Part Find Request Create Endpoint
 * POST /mobile/v1/part_find/create.php
 * Requires JWT authentication - creates a new part find request with optional images
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

// Ensure the request is authenticated
$authUser = requireMobileJwtAuth();
$user_id = (int)($authUser['user_id'] ?? null);

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Unable to identify authenticated user.'
    ]);
    exit;
}

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

try {
    // Get form data - support both multipart/form-data and JSON
    $part_name = null;
    $message = null;

    // Check if multipart/form-data (file uploads) or JSON
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'multipart/form-data') !== false) {
        // Multipart form data
        $part_name = isset($_POST['part_name']) ? trim($_POST['part_name']) : null;
        $message = isset($_POST['message']) ? trim($_POST['message']) : null;
    } else {
        // JSON data
        $input = json_decode(file_get_contents('php://input'), true);
        if ($input !== null) {
            $part_name = isset($input['part_name']) ? trim($input['part_name']) : null;
            $message = isset($input['message']) ? trim($input['message']) : null;
        }
    }

    // Validate required fields
    if (empty($part_name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Part name is required.']);
        exit;
    }

    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Message is required.']);
        exit;
    }

    // Handle image uploads (optional)
    $uploaded_images = [];

    if (!empty($_FILES['images'])) {
        $uploadDir = dirname(dirname(__DIR__, 3)) . '/uploads/request';

        // Create upload directory if it doesn't exist
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Failed to create uploads directory: ' . $uploadDir);
        }

        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB
        $maxImages = 5;

        // Handle multiple files (images[] array)
        $imageFiles = isset($_FILES['images']['name']) && is_array($_FILES['images']['name'])
            ? $_FILES['images']
            : [$_FILES['images']]; // Single file as array

        // Normalize array structure for single file uploads
        if (!is_array($imageFiles['name'])) {
            $imageFiles = [
                'name' => [$imageFiles['name']],
                'type' => [$imageFiles['type']],
                'tmp_name' => [$imageFiles['tmp_name']],
                'error' => [$imageFiles['error']],
                'size' => [$imageFiles['size']]
            ];
        }

        $fileCount = count($imageFiles['name']);
        if ($fileCount > $maxImages) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Maximum {$maxImages} images allowed per request."]);
            exit;
        }

        foreach ($imageFiles['name'] as $index => $filename) {
            $error = $imageFiles['error'][$index];

            // Skip empty uploads
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'File upload error: ' . $error]);
                exit;
            }

            $tmp_name = $imageFiles['tmp_name'][$index];
            $size = $imageFiles['size'][$index];
            $type = $imageFiles['type'][$index];

            // Validate file type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tmp_name);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedTypes)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid file type for image. Allowed types: jpg, jpeg, png, gif, webp']);
                exit;
            }

            // Validate file size
            if ($size > $maxFileSize) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Image file size must be less than 5MB']);
                exit;
            }

            // Security check
            if (!is_uploaded_file($tmp_name)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Potential file upload attack detected']);
                exit;
            }

            // Generate unique filename
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $safeExtension = strtolower($extension);
            if (empty($safeExtension)) {
                // Try to determine from MIME type
                $extensionMap = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp'
                ];
                $safeExtension = $extensionMap[$mimeType] ?? 'jpg';
            }

            $timestamp = time();
            $unique_id = uniqid('', true);
            $newFilename = "request_{$timestamp}_{$unique_id}.{$safeExtension}";
            $targetPath = $uploadDir . '/' . $newFilename;

            // Move uploaded file
            if (!move_uploaded_file($tmp_name, $targetPath)) {
                throw new RuntimeException('Failed to move uploaded image to destination');
            }

            $uploaded_images[] = [
                'original_name' => $filename,
                'file_path' => 'uploads/request/' . $newFilename,
                'mime_type' => $mimeType,
                'file_size' => $size
            ];
        }
    }

    // Insert part find request into database
    $pdo->beginTransaction();

    try {
        // Insert main request
        $stmt = $pdo->prepare("
            INSERT INTO part_find_requests (user_id, message, part_name, status, created_at, updated_at)
            VALUES (?, ?, ?, 0, NOW(), NOW())
        ");
        $stmt->execute([$user_id, $message, $part_name]);
        $request_id = $pdo->lastInsertId();

        // Insert uploaded images
        if (!empty($uploaded_images)) {
            $stmt = $pdo->prepare("
                INSERT INTO find_part_img (request_id, img_src)
                VALUES (?, ?)
            ");

            foreach ($uploaded_images as $image) {
                $stmt->execute([$request_id, $image['file_path']]);
                $image['id'] = $pdo->lastInsertId();
                $uploaded_images[array_search($image, $uploaded_images)] = $image;
            }
        }

        // Fetch the created request with images
        $stmt = $pdo->prepare("
            SELECT
                pfr.id,
                pfr.user_id,
                pfr.message,
                pfr.part_name,
                pfr.status,
                pfr.created_at,
                pfr.updated_at
            FROM part_find_requests pfr
            WHERE pfr.id = ?
        ");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get associated images
        $stmt = $pdo->prepare("
            SELECT id, request_id, img_src
            FROM find_part_img
            WHERE request_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$request_id]);
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pdo->commit();

        // Format response
        $response_data = [
            'id' => (int)$request['id'],
            'user_id' => (int)$request['user_id'],
            'part_name' => $request['part_name'],
            'message' => $request['message'],
            'status' => (int)$request['status'],
            'images' => array_map(function($img) {
                return [
                    'id' => (int)$img['id'],
                    'request_id' => (int)$img['request_id'],
                    'img_src' => $img['img_src']
                ];
            }, $images),
            'created_at' => $request['created_at'],
            'updated_at' => $request['updated_at']
        ];

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Part find request created successfully.',
            'data' => $response_data
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e; // Re-throw to be caught by outer catch block
    }

} catch (PDOException $e) {
    logException('mobile_part_find_create', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while creating your part find request. Please try again later.',
        'error_details' => 'Error creating part find request: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_part_find_create', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error creating part find request: ' . $e->getMessage()
    ]);
}
?>
