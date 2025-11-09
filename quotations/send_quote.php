<?php
/**
 * Send Quotation to User Endpoint
 * POST /quotations/send_quote.php
 * Sends quotation via email or WhatsApp
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
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

if (!isset($input['method']) || !in_array($input['method'], ['email', 'whatsapp', 'both'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Method is required and must be: email, whatsapp, or both.']);
    exit;
}

$quote_id = $input['id'];
$method = $input['method'];
$custom_message = $input['message'] ?? 'Please find your quotation attached.';

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
            u.email as user_email,
            u.cell as user_cell
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

    // Get quotation items
    $stmt = $pdo->prepare("
        SELECT id, quote_id, sku, description, quantity, price, total
        FROM quotation_items
        WHERE quote_id = ?
    ");
    $stmt->execute([$quote_id]);
    $items = $stmt->fetchAll();
    $grand_total = array_sum(array_column($items, 'total'));

    $user_full_name = $quotation['user_name'] . ' ' . $quotation['user_surname'];
    $results = [];

    // Send via Email
    if ($method === 'email' || $method === 'both') {
        if (empty($quotation['user_email'])) {
            $results['email'] = [
                'success' => false,
                'message' => 'User email not found.'
            ];
        } else {
            $to = $quotation['user_email'];
            $subject = 'Your Quotation #' . $quote_id;
            
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
                    .header { background-color: #4CAF50; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background-color: #f9f9f9; }
                    .quote-details { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #4CAF50; }
                    table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                    th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
                    th { background-color: #4CAF50; color: white; }
                    .total { font-weight: bold; font-size: 18px; text-align: right; }
                    .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Your Quotation</h2>
                    </div>
                    <div class='content'>
                        <p>Dear " . htmlspecialchars($user_full_name) . ",</p>
                        <p>" . nl2br(htmlspecialchars($custom_message)) . "</p>
                        <div class='quote-details'>
                            <p><strong>Quotation #:</strong> " . $quote_id . "</p>
                            <p><strong>Description:</strong> " . htmlspecialchars($quotation['description'] ?? 'N/A') . "</p>
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
                        <p>Thank you for your business!</p>
                    </div>
                    <div class='footer'>
                        <p>This is an automated message from Apetrape Management System.</p>
                        <p>Please do not reply to this email.</p>
                    </div>
                </div>
            </body>
            </html>
            ";

            $mail_sent = mail($to, $subject, $email_body, implode("\r\n", $headers));
            
            $results['email'] = [
                'success' => $mail_sent,
                'message' => $mail_sent ? 'Email sent successfully.' : 'Failed to send email.',
                'email' => $to
            ];
        }
    }

    // Send via WhatsApp (returns message text for manual sending or API integration)
    if ($method === 'whatsapp' || $method === 'both') {
        if (empty($quotation['user_cell'])) {
            $results['whatsapp'] = [
                'success' => false,
                'message' => 'User cell number not found.'
            ];
        } else {
            // Build WhatsApp message
            $whatsapp_message = "Dear " . $user_full_name . ",\n\n";
            $whatsapp_message .= $custom_message . "\n\n";
            $whatsapp_message .= "Quotation #" . $quote_id . "\n";
            $whatsapp_message .= "Description: " . ($quotation['description'] ?? 'N/A') . "\n";
            $whatsapp_message .= "Date: " . date('Y-m-d H:i:s', strtotime($quotation['entry'])) . "\n\n";
            $whatsapp_message .= "Items:\n";
            
            foreach ($items as $item) {
                $whatsapp_message .= "- " . ($item['sku'] ?? 'N/A') . " | ";
                $whatsapp_message .= ($item['description'] ?? '') . " | ";
                $whatsapp_message .= "Qty: " . $item['quantity'] . " | ";
                $whatsapp_message .= "Price: $" . number_format($item['price'], 2) . " | ";
                $whatsapp_message .= "Total: $" . number_format($item['total'], 2) . "\n";
            }
            
            $whatsapp_message .= "\nGrand Total: $" . number_format($grand_total, 2) . "\n\n";
            $whatsapp_message .= "Thank you for your business!";

            $results['whatsapp'] = [
                'success' => true,
                'message' => 'WhatsApp message prepared.',
                'phone' => $quotation['user_cell'],
                'whatsapp_message' => $whatsapp_message,
                'whatsapp_url' => 'https://wa.me/' . preg_replace('/[^0-9]/', '', $quotation['user_cell']) . '?text=' . urlencode($whatsapp_message)
            ];
        }
    }

    // Update quotation status to 'sent' if not already
    if ($quotation['status'] !== 'sent') {
        $stmt = $pdo->prepare("UPDATE quotations SET status = 'sent' WHERE id = ?");
        $stmt->execute([$quote_id]);
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Quotation sent successfully.',
        'quote_id' => $quote_id,
        'results' => $results
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error sending quotation: ' . $e->getMessage()
    ]);
}
?>

