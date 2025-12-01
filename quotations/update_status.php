<?php
/**
 * Update Quotation Status Endpoint
 * PUT /quotations/update_status.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

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
if (!isset($input['quotation_id']) || empty($input['quotation_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "quote id" is required.']);
    exit;
}

if (!isset($input['status']) || empty(trim($input['status']))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "status" is required.']);
    exit;
}

$quote_id = $input['quotation_id'];
$status = trim($input['status']);

try {
    // Check if quotation exists
    $stmt = $pdo->prepare("SELECT id, status FROM quotations WHERE id = ?");
    $stmt->execute([$quote_id]);
    $existing_quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing_quote) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Quotation not found.']);
        exit;
    }

    // Update status
    $stmt = $pdo->prepare("UPDATE quotations SET status = ? WHERE id = ?");
    $stmt->execute([$status, $quote_id]);

    // Fetch updated quotation
    $stmt = $pdo->prepare("
        SELECT 
            id,
            user_id,
            status,
            quote_no,
            customer_name,
            customer_cell,
            customer_address,
            sent_date,
            created_at,
            updated_at
        FROM quotations
        WHERE id = ?
    ");
    $stmt->execute([$quote_id]);
    $updated_quote = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Quotation status updated successfully.',
        'data' => $updated_quote
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating quotation status: ' . $e->getMessage()
    ]);
}
?>

