<?php
/**
 * Shared quotation PDF design.
 * Centralizes layout so control and mobile download endpoints use the same design.
 * Requires: TCPDF (vendor/tecnickcom/tcpdf/tcpdf.php).
 *
 * @param array $quotation Row with: id, user_id, status, quote_no, customer_name, customer_cell, customer_address, sent_date, created_at, updated_at
 * @param array $items Rows with: sku, description, quantity, price, total
 * @return TCPDF Configured PDF ready for Output()
 */
function generateQuotationPdf(array $quotation, array $items) {
    $tcpdfPath = dirname(__DIR__, 2) . '/vendor/tecnickcom/tcpdf/tcpdf.php';
    if (!file_exists($tcpdfPath)) {
        throw new RuntimeException('TCPDF not found. Install via composer.');
    }
    require_once $tcpdfPath;

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Quotation System');
    $pdf->SetAuthor('APE Trape PTY Ltd');
    $pdf->SetTitle('Quotation #' . $quotation['quote_no']);
    $pdf->SetMargins(15, 25, 15);
    $pdf->AddPage();

    $logoPath = __DIR__ . '/../assets/images/logo-ct-dark.png';
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

    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, 'Quotation #' . $quotation['quote_no'], 0, 1, 'C');
    $pdf->Ln(5);

    $pdf->SetFont('helvetica', '', 12);
    $htmlCustomer = <<<EOD
<b>Customer Name:</b> {$quotation['customer_name']}<br/>
<b>Cell Number:</b> {$quotation['customer_cell']}<br/>
<b>Address:</b> {$quotation['customer_address']}<br/>
<b>Created at:</b> {$quotation['created_at']}<br/>
EOD;
    $pdf->writeHTML($htmlCustomer, true, false, false, false, '');
    $pdf->Ln(5);

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

    $tblRows = '';
    $subtotal = 0;
    foreach ($items as $item) {
        $tblRows .= '<tr>';
        $tblRows .= '<td>' . htmlspecialchars($item['sku']) . '</td>';
        $tblRows .= '<td>' . htmlspecialchars($item['description']) . '</td>';
        $tblRows .= '<td align="center">' . $item['quantity'] . '</td>';
        $tblRows .= '<td align="right">' . number_format((float)$item['price'], 2) . '</td>';
        $tblRows .= '<td align="right">' . number_format((float)$item['total'], 2) . '</td>';
        $tblRows .= '</tr>';
        $subtotal += (float)$item['total'];
    }

    $vat = $subtotal * 0;
    $grandTotal = $subtotal + $vat;
    $formattedSubtotal = number_format($subtotal, 2);
    $formattedVat = number_format($vat, 2);
    $formattedGrandTotal = number_format($grandTotal, 2);

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

    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->MultiCell(0, 5, "Thank you for your business! Please contact us for any inquiries.", 0, 'C');

    return $pdf;
}
