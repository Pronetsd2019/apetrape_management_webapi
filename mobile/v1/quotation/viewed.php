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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Mobile Quotation Mark as Viewed Endpoint
 * POST /mobile/v1/quotation/viewed.php
 *
 * Expects JSON body: { "id": <quotation_id> }
 * Sets quotations.viewed = true for the given quotation if it belongs to the authenticated user.
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

$authUser = requireMobileJwtAuth();
$user_id = (int)($authUser['user_id'] ?? null);

if (!$user_id) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Unable to identify authenticated user.'
    ]);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON in request body.']);
    exit;
}

$quotation_id = (int)($input['id'] ?? 0);

if (!$quotation_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "id" (quotation id) is required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE quotations
        SET viewed = 1, updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$quotation_id, $user_id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Quotation not found or you do not have access to it.'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, user_id, status, created_at, updated_at, quote_no, sent_date,
               customer_name, customer_cell, customer_address, viewed
        FROM quotations
        WHERE id = ?
    ");
    $stmt->execute([$quotation_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Quotation marked as viewed.',
        'data' => $quotation
    ]);

} catch (PDOException $e) {
    logException('mobile_quotation_viewed', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating quotation: ' . $e->getMessage()
    ]);
}
