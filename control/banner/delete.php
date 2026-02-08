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
 * Delete Banner Endpoint
 * DELETE /banner/delete.php
 *
 * Expects JSON body: { "id": <int> }
 * Deletes the banner row and, if url points to an uploaded file, removes the file from disk.
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

if (!checkUserPermission($userId, 'banners', 'delete')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to delete banners.']);
    exit;
}

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

    $banner_id = (int)$input['id'];

    $stmt = $pdo->prepare("SELECT id, url FROM banner WHERE id = ?");
    $stmt->execute([$banner_id]);
    $banner = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$banner) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Banner not found.']);
        exit;
    }

    // Delete uploaded file if it is a local path (uploads/banners/...)
    if (!empty($banner['url'])) {
        $url = $banner['url'];
        if (!filter_var($url, FILTER_VALIDATE_URL) && strpos($url, 'uploads/') === 0) {
            $file_path = dirname(dirname(__DIR__, 2)) . '/' . $url;
            if (file_exists($file_path)) {
                @unlink($file_path);
            }
        }
    }

    $stmt = $pdo->prepare("DELETE FROM banner WHERE id = ?");
    $stmt->execute([$banner_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Banner deleted successfully.'
    ]);

} catch (PDOException $e) {
    logException('banner_delete', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting banner: ' . $e->getMessage()
    ]);
}
