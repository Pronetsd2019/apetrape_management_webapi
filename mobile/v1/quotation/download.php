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
 * Mobile Download Quotation as PDF
 * GET /mobile/v1/quotation/download.php?id={quotation_id}
 * Returns PDF for the quotation if it belongs to the authenticated user.
 * Uses shared quote design from control/util/quote_pdf.php.
 */

ini_set('display_errors', 0);
error_reporting(0);
ob_start();

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../../../control/util/quote_pdf.php';
require_once __DIR__ . '/../util/auth_middleware.php';

try {
    $authUser = requireMobileJwtAuth();
    $user_id = (int)($authUser['user_id'] ?? null);

    if (!$user_id) {
        ob_end_clean();
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'Unable to identify authenticated user.'
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        ob_end_clean();
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
        exit;
    }

    $quote_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$quote_id) {
        ob_end_clean();
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Query parameter "id" (quotation id) is required.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, user_id, status, quote_no, customer_name, customer_cell, customer_address, sent_date, created_at, updated_at
        FROM quotations
        WHERE id = ? AND user_id = ? AND status != 'deleted'
        LIMIT 1
    ");
    $stmt->execute([$quote_id, $user_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) {
        ob_end_clean();
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Quotation not found or you do not have access to it.'
        ]);
        exit;
    }

    $stmtItems = $pdo->prepare("
        SELECT sku, description, quantity, price, total
        FROM quotation_items
        WHERE quote_id = ?
        ORDER BY id ASC
    ");
    $stmtItems->execute([$quote_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $pdf = generateQuotationPdf($quotation, $items);
    ob_clean();
    $pdf->Output('Quotation_' . $quotation['quote_no'] . '.pdf', 'D');
    ob_end_flush();
    exit;

} catch (PDOException $e) {
    logException('mobile_quotation_download', $e);
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error generating PDF: ' . $e->getMessage()
    ]);
    exit;
} catch (Exception $e) {
    logException('mobile_quotation_download', $e);
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error generating PDF: ' . $e->getMessage()
    ]);
    exit;
}
