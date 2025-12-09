<?php
/**
 * Send Quotation as PDF via Email
 * POST /quotations/send_quote.php
 * Body: { "quote_id": 123, "email": "customer@example.com" }
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
    http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed. Use POST.'
        ]);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['quote_id']) || !is_numeric($input['quote_id'])) {
        ob_end_clean();
    http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or missing quotation ID.'
        ]);
    exit;
}

    if (!isset($input['email']) || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        ob_end_clean();
    http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or missing email address.'
        ]);
    exit;
}

    $quoteId = (int)$input['quote_id'];
    $email = trim($input['email']);

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

    // Generate PDF content
    $pdfContent = $pdf->Output('Quotation_' . $quotation['quote_no'] . '.pdf', 'S'); // Return as string

    // Create temporary file for attachment
    $tempFile = tempnam(sys_get_temp_dir(), 'quote_');
    file_put_contents($tempFile, $pdfContent);

    // Email headers
    $boundary = md5(uniqid(time()));
    $subject = 'Quotation #' . $quotation['quote_no'] . ' from APE Trape PTY Ltd';
    $message = "Dear {$quotation['customer_name']},\n\nPlease find attached your quotation from APE Trape PTY Ltd.\n\nIf you have any questions, please contact us at info@apetrape.com or +268 76 000 000.\n\nBest regards,\nAPE Trape PTY Ltd";

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        'From: APE Trape PTY Ltd <sales@apetrape.com>',
        'Reply-To: sales@apetrape.com',
        'X-Mailer: PHP/' . phpversion()
    ];

    // Email body
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= $message . "\r\n\r\n";

    // Attachment
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: application/pdf; name=\"Quotation_{$quotation['quote_no']}.pdf\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"Quotation_{$quotation['quote_no']}.pdf\"\r\n\r\n";
    $body .= chunk_split(base64_encode($pdfContent)) . "\r\n";
    $body .= "--{$boundary}--";

    // Send email
    $mailSent = mail($email, $subject, $body, implode("\r\n", $headers));

    // Clean up temporary file
    unlink($tempFile);

    // Clear any buffered output
    ob_end_clean();

    if ($mailSent) {
        // Update quotation sent_date if not already set
        if (empty($quotation['sent_date'])) {
            $stmtUpdate = $pdo->prepare("UPDATE quotations SET sent_date = NOW() WHERE id = ?");
            $stmtUpdate->execute([$quoteId]);
    }

    http_response_code(200);
        header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
            'message' => 'Quotation sent successfully to ' . $email,
            'quote_id' => $quoteId,
            'email' => $email
        ]);
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send email. Please check server configuration.'
        ]);
    }
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
?>