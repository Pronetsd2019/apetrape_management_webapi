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
 * Mobile Order Download Invoice as PDF
 * GET /mobile/v1/order/download_invoice.php?order_id={order_id}
 * Requires JWT authentication - generates and downloads invoice PDF for an order
 */

// Disable all error output to prevent interference with PDF generation
ini_set('display_errors', 0);
error_reporting(0);

ob_start(); // Start output buffering to prevent any output before PDF

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';
require_once __DIR__ . '/../../../vendor/tecnickcom/tcpdf/tcpdf.php';

try {
    // Ensure the request is authenticated
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
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed. Use GET.'
        ]);
        exit;
    }

    if (!isset($_GET['order_id']) || !is_numeric($_GET['order_id'])) {
        ob_end_clean();
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or missing order ID.'
        ]);
        exit;
    }

    $order_id = (int)$_GET['order_id'];

    // Fetch order - verify ownership
    $stmt = $pdo->prepare("
        SELECT 
            id, user_id, order_no, status, 
            confirm_date, pay_method, pay_status,
            delivery_method, delivery_address, pickup_address, delivery_date,
            created_at, updated_at
        FROM orders
        WHERE id = ? AND user_id = ? AND status != 'deleted'
        LIMIT 1
    ");
    $stmt->execute([$order_id, $user_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ob_end_clean();
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Order not found or does not belong to you.'
        ]);
        exit;
    }

    // Fetch order items
    $stmtItems = $pdo->prepare("
        SELECT sku, name, quantity, price, total
        FROM order_items
        WHERE order_id = ?
        ORDER BY id ASC
    ");
    $stmtItems->execute([$order_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Fetch delivery fee
    $stmtDeliveryFee = $pdo->prepare("
        SELECT fee
        FROM delivery_fee
        WHERE order_id = ?
        LIMIT 1
    ");
    $stmtDeliveryFee->execute([$order_id]);
    $deliveryFeeRow = $stmtDeliveryFee->fetch(PDO::FETCH_ASSOC);
    $deliveryFee = $deliveryFeeRow ? (float)$deliveryFeeRow['fee'] : 0;

    // Fetch payments
    $stmtPayments = $pdo->prepare("
        SELECT amount, pay_method, pay_date, create_At as payment_date
        FROM payments
        WHERE order_id = ?
        ORDER BY create_At ASC
    ");
    $stmtPayments->execute([$order_id]);
    $payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

    // Get user details
    $stmtUser = $pdo->prepare("
        SELECT name, surname, email, cell
        FROM users
        WHERE id = ?
    ");
    $stmtUser->execute([$user_id]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    // Create TCPDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('APE Trape Order System');
    $pdf->SetAuthor('APE Trape PTY Ltd');
    $pdf->SetTitle('Invoice #' . $order['order_no']);
    $pdf->SetMargins(15, 25, 15);
    $pdf->AddPage();

    // Company Logo and Info
    $logoPath = __DIR__ . '/../../../control/assets/images/logo-ct-dark.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 15, 12, 30);
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

    // Invoice title
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, 'INVOICE #' . $order['order_no'], 0, 1, 'C');
    $pdf->Ln(5);

    // Customer and Order info
    $pdf->SetFont('helvetica', '', 11);
    $customerName = htmlspecialchars(trim($user['name'] . ' ' . ($user['surname'] ?? '')));
    $customerCell = htmlspecialchars($user['cell'] ?? 'N/A');
    $customerEmail = htmlspecialchars($user['email'] ?? 'N/A');
    $orderDate = htmlspecialchars($order['created_at']);
    $orderStatus = htmlspecialchars(ucfirst($order['status']));
    $paymentStatus = htmlspecialchars(ucfirst($order['pay_status'] ?? 'pending'));

    $htmlCustomer = <<<EOD
<table cellpadding="3">
<tr>
    <td width="50%"><b>Bill To:</b><br/>
    {$customerName}<br/>
    {$customerCell}<br/>
    {$customerEmail}</td>
    <td width="50%"><b>Invoice Details:</b><br/>
    <b>Date:</b> {$orderDate}<br/>
    <b>Status:</b> {$orderStatus}<br/>
    <b>Payment:</b> {$paymentStatus}</td>
</tr>
</table>
EOD;
    $pdf->writeHTML($htmlCustomer, true, false, false, false, '');
    
    // Delivery info
    if ($order['delivery_method'] && $order['delivery_address']) {
        $pdf->Ln(3);
        $deliveryMethod = htmlspecialchars(ucfirst($order['delivery_method']));
        $deliveryAddress = htmlspecialchars($order['delivery_address']);
        $htmlDelivery = <<<EOD
<b>Delivery Method:</b> {$deliveryMethod}<br/>
<b>Delivery Address:</b> {$deliveryAddress}
EOD;
        $pdf->writeHTML($htmlDelivery, true, false, false, false, '');
    }
    
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
        $tblRows .= '<td>' . htmlspecialchars($item['name']) . '</td>';
        $tblRows .= '<td align="center">' . $item['quantity'] . '</td>';
        $tblRows .= '<td align="right">' . number_format($item['price'], 2) . '</td>';
        $tblRows .= '<td align="right">' . number_format($item['total'], 2) . '</td>';
        $tblRows .= '</tr>';
        $subtotal += $item['total'];
    }

    // Add delivery fee row if exists
    if ($deliveryFee > 0) {
        $tblRows .= '<tr>';
        $tblRows .= '<td colspan="4" align="right"><i>Delivery Fee</i></td>';
        $tblRows .= '<td align="right"><i>' . number_format($deliveryFee, 2) . '</i></td>';
        $tblRows .= '</tr>';
    }

    $vat = 0; // No VAT for now
    $grandTotal = $subtotal + $deliveryFee + $vat;

    // Calculate total paid and due
    $totalPaid = 0;
    foreach ($payments as $payment) {
        $totalPaid += $payment['amount'];
    }
    $amountDue = $grandTotal - $totalPaid;

    // Format currency values
    $formattedSubtotal = number_format($subtotal, 2);
    $formattedDeliveryFee = number_format($deliveryFee, 2);
    $formattedVat = number_format($vat, 2);
    $formattedGrandTotal = number_format($grandTotal, 2);
    $formattedTotalPaid = number_format($totalPaid, 2);
    $formattedAmountDue = number_format($amountDue, 2);

    // Table footer
    $tblFooter = <<<EOD
<tr>
    <td colspan="4" align="right"><b>Subtotal</b></td>
    <td align="right"><b>{$formattedSubtotal}</b></td>
</tr>
EOD;

    if ($deliveryFee > 0) {
        $tblFooter .= <<<EOD
<tr>
    <td colspan="4" align="right"><b>Delivery Fee</b></td>
    <td align="right"><b>{$formattedDeliveryFee}</b></td>
</tr>
EOD;
    }

    $tblFooter .= <<<EOD
<tr>
    <td colspan="4" align="right"><b>Grand Total</b></td>
    <td align="right"><b>{$formattedGrandTotal}</b></td>
</tr>
<tr style="background-color:#e8f5e9;">
    <td colspan="4" align="right"><b>Total Paid</b></td>
    <td align="right"><b>{$formattedTotalPaid}</b></td>
</tr>
<tr style="background-color:#fff3e0;">
    <td colspan="4" align="right"><b>Amount Due</b></td>
    <td align="right"><b>{$formattedAmountDue}</b></td>
</tr>
</table>
EOD;

    $pdf->writeHTML($tblHeader . $tblRows . $tblFooter, true, false, false, false, '');

    // Payment history
    if (!empty($payments)) {
        $pdf->Ln(8);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 6, 'Payment History', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        
        $paymentTable = '<table cellspacing="0" cellpadding="4" border="1">';
        $paymentTable .= '<tr style="background-color:#f2f2f2;">';
        $paymentTable .= '<th width="25%">Date</th>';
        $paymentTable .= '<th width="25%">Method</th>';
        $paymentTable .= '<th width="25%">Amount (E)</th>';
        $paymentTable .= '</tr>';
        
        foreach ($payments as $payment) {
            $paymentDate = htmlspecialchars($payment['pay_date'] ?? $payment['payment_date']);
            $paymentMethod = htmlspecialchars(ucfirst($payment['pay_method'] ?? 'N/A'));
            $paymentAmount = number_format($payment['amount'], 2);
            
            $paymentTable .= '<tr>';
            $paymentTable .= '<td>' . $paymentDate . '</td>';
            $paymentTable .= '<td>' . $paymentMethod . '</td>';
            $paymentTable .= '<td align="right">' . $paymentAmount . '</td>';
            $paymentTable .= '</tr>';
        }
        
        $paymentTable .= '</table>';
        $pdf->writeHTML($paymentTable, true, false, false, false, '');
    }

    // Footer note
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->MultiCell(0, 5, "Thank you for your business! For any inquiries, please contact us at info@apetrape.com or +268 76 000 000.", 0, 'C');

    // Clear any buffered output before sending PDF
    ob_end_clean();

    // Output PDF
    $pdf->Output('Invoice_' . $order['order_no'] . '.pdf', 'D'); // Force download

    exit;

} catch (PDOException $e) {
    logException('mobile_order_download_invoice', $e);
    ob_end_clean(); // Clear any output before error
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit;
} catch (Exception $e) {
    logException('mobile_order_download_invoice', $e);
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
