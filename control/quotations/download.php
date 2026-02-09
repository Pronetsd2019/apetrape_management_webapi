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
 * Download Quotation as PDF with company info and logo using TCPDF
 * GET /quotations/download_pdf.php?id={quote_id}
 */

// Disable all error output to prevent interference with PDF generation
ini_set('display_errors', 0);
error_reporting(0);

ob_start(); // Start output buffering to prevent any output before PDF

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
require_once __DIR__ . '/../util/quote_pdf.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

try {
    // Ensure the request is authenticated
    requireJwtAuth();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        ob_end_clean();
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed. Use GET.'
        ]);
        exit;
    }

    if (!isset($_GET['quote_id']) || !is_numeric($_GET['quote_id'])) {
        ob_end_clean();
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or missing quotation ID.'
        ]);
        exit;
    }

    $quoteId = (int)$_GET['quote_id'];

    // Fetch quotation
    $stmt = $pdo->prepare("
        SELECT id, user_id, status, quote_no, customer_name, customer_cell, customer_address, sent_date, created_at, updated_at
        FROM quotations
        WHERE id = ? AND status != 'deleted'
        LIMIT 1
    ");
    $stmt->execute([$quoteId]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) {
        ob_end_clean();
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Quotation not found.'
        ]);
        exit;
    }

    // Fetch items
    $stmtItems = $pdo->prepare("
        SELECT sku, description, quantity, price, total
        FROM quotation_items
        WHERE quote_id = ?
        ORDER BY id ASC
    ");
    $stmtItems->execute([$quoteId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $pdf = generateQuotationPdf($quotation, $items);

    // Clear any buffered output before sending PDF
    ob_clean();

    // Output PDF
    $pdf->Output('Quotation_' . $quotation['quote_no'] . '.pdf', 'D'); // Force download

    ob_end_flush(); // Send buffered content
    exit;

} catch (PDOException $e) {
    logException('quotations_download', $e);
    ob_end_clean(); // Clear any output before error
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit;
} catch (Exception $e) {
    logException('quotations_download', $e);
    ob_end_clean(); // Clear any output before error
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error generating PDF: ' . $e->getMessage()
    ]);
    exit;
}
