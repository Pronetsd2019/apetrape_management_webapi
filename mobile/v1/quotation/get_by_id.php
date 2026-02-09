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
 * Mobile Get Quotation by ID Endpoint
 * GET /mobile/v1/quotation/get_by_id.php?id={id}
 * Returns the quotation and its line items if it belongs to the authenticated user.
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

$quotation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$quotation_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Query parameter "id" (quotation id) is required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, user_id, status, created_at, updated_at, quote_no, sent_date,
               customer_name, customer_cell, customer_address, viewed
        FROM quotations
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$quotation_id, $user_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Quotation not found or you do not have access to it.'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, quote_id, sku, description, quantity, price, total, created_at, updated_at
        FROM quotation_items
        WHERE quote_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$quotation_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $quotation['items'] = $items;
    $quotation['item_count'] = count($items);
    $quotation['quote_total'] = array_sum(array_column($items, 'total'));
    $subtotal = $quotation['quote_total'];
    $quotation['subtotal'] = $subtotal;
    $quotation['vat'] = $subtotal * 0.15;
    $quotation['grand_total'] = $subtotal + $quotation['vat'];

    // Optional: part find request link (if table exists)
    $stmt = $pdo->prepare("
        SELECT pfq.part_find_id, pfr.id AS part_request_id, pfr.message AS part_request_message, pfr.status AS part_request_status
        FROM part_find_qoutations pfq
        INNER JOIN part_find_requests pfr ON pfq.part_find_id = pfr.id
        WHERE pfq.quote_id = ?
    ");
    try {
        $stmt->execute([$quotation_id]);
        $partFindLink = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($partFindLink) {
            $quotation['part_find_request'] = [
                'id' => $partFindLink['part_request_id'],
                'message' => $partFindLink['part_request_message'],
                'status' => $partFindLink['part_request_status']
            ];
        }
    } catch (PDOException $e) {
        // Table or column may not exist; leave part_find_request out
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Quotation retrieved successfully.',
        'data' => $quotation
    ]);

} catch (PDOException $e) {
    logException('mobile_quotation_get_by_id', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching quotation: ' . $e->getMessage()
    ]);
}
