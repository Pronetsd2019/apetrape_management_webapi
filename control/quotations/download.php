<?php
/**
 * Download Quotation as PDF with company info and logo using TCPDF
 * GET /quotations/download_pdf.php?id={quote_id}
 */

// Disable all error output to prevent interference with PDF generation
ini_set('display_errors', 0);
error_reporting(0);

ob_start(); // Start output buffering to prevent any output before PDF

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';

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

    // Create TCPDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Quotation System');
    $pdf->SetAuthor('APE Trape PTY Ltd');
    $pdf->SetTitle('Quotation #' . $quotation['quote_no']);
    $pdf->SetMargins(15, 25, 15);
    $pdf->AddPage();

    // Company Logo and Info
    if (file_exists(__DIR__ . '/../assets/images/logo-ct-dark.png')) {
        $pdf->Image(__DIR__ . '/../assets/images/logo-ct-dark.png', 15, 12, 30); // Company logo
    }

    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetXY(55, 14);
    $pdf->Cell(0, 6, 'APE Trape PTY Ltd', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetX(55);
    $pdf->Cell(0, 5, '123 Moneni Street', 0, 1);
    $pdf->SetX(55);
    $pdf->Cell(0, 5, 'Manzini, Eswatini', 0, 1);
    $pdf->SetX(55);
    $pdf->Cell(0, 5, 'Phone: +268 76 000 000', 0, 1);
    $pdf->SetX(55);
    $pdf->Cell(0, 5, 'Email: info@apetrape.com', 0, 1);

    $pdf->Ln(10);

    // Quotation title
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, 'Quotation #' . $quotation['quote_no'], 0, 1, 'C');
    $pdf->Ln(5);

    // Customer info
    $pdf->SetFont('helvetica', '', 12);
    $htmlCustomer = <<<EOD
<b>Customer Name:</b> {$quotation['customer_name']}<br/>
<b>Cell Number:</b> {$quotation['customer_cell']}<br/>
<b>Address:</b> {$quotation['customer_address']}<br/>
<b>Created at:</b> {$quotation['created_at']}<br/>
EOD;
    $pdf->writeHTML($htmlCustomer, true, false, false, false, '');
    $pdf->Ln(5);

    // Table header
    $tblHeader = <<<EOD
<table cellspacing="0" cellpadding="6" border="1">
    <tr style="background-color:#f2f2f2;">
        <th width="15%">SKU</th>
        <th width="45%">Description</th>
        <th width="10%">Qty</th>
        <th width="15%">Price (E)</th>
        <th width="15%">Total (E)</th>
    </tr>
EOD;

    // Table rows
    $tblRows = '';
    $subtotal = 0;
    foreach ($items as $item) {
        $tblRows .= '<tr>';
        $tblRows .= '<td>' . htmlspecialchars($item['sku']) . '</td>';
        $tblRows .= '<td>' . htmlspecialchars($item['description']) . '</td>';
        $tblRows .= '<td align="center">' . $item['quantity'] . '</td>';
        $tblRows .= '<td align="right">' . number_format($item['price'], 2) . '</td>';
        $tblRows .= '<td align="right">' . number_format($item['total'], 2) . '</td>';
        $tblRows .= '</tr>';
        $subtotal += $item['total'];
    }

    $vat = $subtotal * 0; // 15% VAT
    $grandTotal = $subtotal + $vat;

    // Format currency values
    $formattedSubtotal = number_format($subtotal, 2);
    $formattedVat = number_format($vat, 2);
    $formattedGrandTotal = number_format($grandTotal, 2);

    // Table footer
    $tblFooter = <<<EOD
<tr>
    <td colspan="4" align="right"><b>Subtotal</b></td>
    <td align="right"><b>{$formattedSubtotal}</b></td>
</tr>
<tr>
    <td colspan="4" align="right"><b>VAT (15%)</b></td>
    <td align="right"><b>{$formattedVat}</b></td>
</tr>
<tr>
    <td colspan="4" align="right"><b>Grand Total</b></td>
    <td align="right"><b>{$formattedGrandTotal}</b></td>
</tr>
</table>
EOD;

    $pdf->writeHTML($tblHeader . $tblRows . $tblFooter, true, false, false, false, '');

    // Footer note
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->MultiCell(0, 5, "Thank you for your business! Please contact us for any inquiries.", 0, 'C');

    // Clear any buffered output before sending PDF
    ob_clean();

    // Output PDF
    $pdf->Output('Quotation_' . $quotation['quote_no'] . '.pdf', 'D'); // Force download

    ob_end_flush(); // Send buffered content
    exit;

} catch (PDOException $e) {
    ob_end_clean(); // Clear any output before error
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit;
} catch (Exception $e) {
    ob_end_clean(); // Clear any output before error
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error generating PDF: ' . $e->getMessage()
    ]);
    exit;
}
