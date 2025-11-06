<?php
/**
 * Revoke Quotation Endpoint
 * PUT /quotations/revoke.php
 * Revokes a quotation and sends email notification to user
 */

require_once __DIR__ . '/../util/connect.php';
header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Quotation ID is required.']);
    exit;
}

$quote_id = $input['id'];
$revoke_reason = $input['reason'] ?? 'This quotation has been revoked.';

try {
    // Get quotation and user details
    $stmt = $pdo->prepare("
        SELECT 
            q.id,
            q.description,
            q.user_id,
            q.status,
            q.entry,
            u.name as user_name,
            u.surname as user_surname,
            u.email as user_email
        FROM quotations q
        INNER JOIN users u ON q.user_id = u.id
        WHERE q.id = ?
    ");
    $stmt->execute([$quote_id]);
    $quotation = $stmt->fetch();

    if (!$quotation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Quotation not found.']);
        exit;
    }

    if ($quotation['status'] === 'revoked') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Quotation is already revoked.']);
        exit;
    }

    // Get quotation items
    $stmt = $pdo->prepare("
        SELECT id, quote_id, sku, description, quantity, price, total
        FROM quotation_items
        WHERE quote_id = ?
    ");
    $stmt->execute([$quote_id]);
    $items = $stmt->fetchAll();
    $grand_total = array_sum(array_column($items, 'total'));

    // Update quotation status to revoked
    $stmt = $pdo->prepare("UPDATE quotations SET status = 'revoked' WHERE id = ?");
    $stmt->execute([$quote_id]);

    // Send email to user
    $to = $quotation['user_email'];
    $user_full_name = $quotation['user_name'] . ' ' . $quotation['user_surname'];
    $subject = 'Quotation Revoked - #' . $quote_id;
    
    // Email headers
    $headers = [
        'From: noreply@apetrape.com',
        'Reply-To: noreply@apetrape.com',
        'X-Mailer: PHP/' . phpversion(),
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8'
    ];

    // Build items HTML
    $items_html = '';
    foreach ($items as $item) {
        $items_html .= '
        <tr>
            <td>' . htmlspecialchars($item['sku'] ?? 'N/A') . '</td>
            <td>' . htmlspecialchars($item['description'] ?? '') . '</td>
            <td>' . $item['quantity'] . '</td>
            <td>$' . number_format($item['price'], 2) . '</td>
            <td>$' . number_format($item['total'], 2) . '</td>
        </tr>';
    }

    // Email body
    $email_body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #f44336; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background-color: #f9f9f9; }
            .quote-details { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #f44336; }
            table { width: 100%; border-collapse: collapse; margin: 15px 0; }
            th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
            th { background-color: #f44336; color: white; }
            .total { font-weight: bold; font-size: 18px; text-align: right; }
            .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Quotation Revoked</h2>
            </div>
            <div class='content'>
                <p>Dear " . htmlspecialchars($user_full_name) . ",</p>
                <p>We regret to inform you that quotation #" . $quote_id . " has been revoked.</p>
                <div class='quote-details'>
                    <p><strong>Reason:</strong> " . nl2br(htmlspecialchars($revoke_reason)) . "</p>
                    <p><strong>Quotation Description:</strong> " . htmlspecialchars($quotation['description'] ?? 'N/A') . "</p>
                    <p><strong>Date:</strong> " . date('Y-m-d H:i:s', strtotime($quotation['entry'])) . "</p>
                </div>
                <h3>Quotation Items:</h3>
                <table>
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Description</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        " . $items_html . "
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan='4' class='total'>Grand Total:</td>
                            <td class='total'>$" . number_format($grand_total, 2) . "</td>
                        </tr>
                    </tfoot>
                </table>
                <p>If you have any questions, please contact us.</p>
            </div>
            <div class='footer'>
                <p>This is an automated message from Apetrape Management System.</p>
                <p>Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    // Send email
    $mail_sent = mail($to, $subject, $email_body, implode("\r\n", $headers));

    // Fetch updated quotation
    $stmt = $pdo->prepare("
        SELECT id, description, user_id, status, entry, created_at, updated_at
        FROM quotations WHERE id = ?
    ");
    $stmt->execute([$quote_id]);
    $updated_quotation = $stmt->fetch();

    $updated_quotation['items'] = $items;

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $mail_sent ? 'Quotation revoked and email sent successfully.' : 'Quotation revoked. Email may not have been sent.',
        'email_sent' => $mail_sent,
        'data' => $updated_quotation
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error revoking quotation: ' . $e->getMessage()
    ]);
}
?>

