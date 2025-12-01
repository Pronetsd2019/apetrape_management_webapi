<?php
/**
 * Update Quotation Endpoint
 * PUT /quotations/update.php
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

// Validate quote_id
if (!isset($input['quotation_id']) || empty($input['quotation_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Quotation ID is required.']);
    exit;
}

$quote_id = $input['quotation_id'];

try {
    // Check if quotation exists
    $stmt = $pdo->prepare("SELECT id, status FROM quotations WHERE id = ?");
    $stmt->execute([$quote_id]);
    $existing = $stmt->fetch();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Quotation not found.']);
        exit;
    }

    // Start transaction
    $pdo->beginTransaction();

    // Build update query for quotation
    $update_fields = [];
    $params = [];

    if (isset($input['status'])) {
        $update_fields[] = "status = ?";
        $params[] = $input['status'];
    }
    if (isset($input['user_id'])) {
        // Validate user_id exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$input['user_id']]);
        if (!$stmt->fetch()) {
            throw new Exception('User not found.');
        }
        $update_fields[] = "user_id = ?";
        $params[] = $input['user_id'];
    }
    if (isset($input['customer_name'])) {
        $update_fields[] = "customer_name = ?";
        $params[] = trim($input['customer_name']);
    }
    if (isset($input['customer_cell'])) {
        $update_fields[] = "customer_cell = ?";
        $params[] = trim($input['customer_cell']);
    }
    if (isset($input['customer_address'])) {
        $update_fields[] = "customer_address = ?";
        $params[] = trim($input['customer_address']);
    }

    // Update quotation if fields provided
    if (!empty($update_fields)) {
        $params[] = $quote_id;
        $sql = "UPDATE quotations SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    // Update items if provided
    if (isset($input['items']) && is_array($input['items'])) {
        // Delete existing items
        $stmt = $pdo->prepare("DELETE FROM quotation_items WHERE quote_id = ?");
        $stmt->execute([$quote_id]);

        // Insert new items
        $stmt_item = $pdo->prepare("
            INSERT INTO quotation_items (quote_id, sku, description, quantity, price, total)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($input['items'] as $item) {
            if (!isset($item['quantity']) || !isset($item['unit_price'])) {
                throw new Exception('Each item must have quantity and price.');
            }

            $sku = $item['sku'] ?? null;
            $item_description = $item['description'] ?? null;
            $quantity = (int)$item['quantity'];
            $price = (float)$item['unit_price'];
            $total = $quantity * $price;

            $stmt_item->execute([
                $quote_id,
                $sku,
                $item_description,
                $quantity,
                $price,
                $total
            ]);
        }
    }

    // Commit transaction
    $pdo->commit();

    // Fetch updated quotation with items
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
        FROM quotations WHERE id = ?
    ");
    $stmt->execute([$quote_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch quotation items
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            quote_id, 
            sku, 
            description, 
            quantity, 
            price, 
            total, 
            created_at, 
            updated_at
        FROM quotation_items
        WHERE quote_id = ?
    ");
    $stmt->execute([$quote_id]);
    $quotation['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate grand total
    $grand_total = array_sum(array_column($quotation['items'], 'total'));
    $quotation['grand_total'] = $grand_total;

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Quotation updated successfully.',
        'data' => $quotation
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating quotation: ' . $e->getMessage()
    ]);
}
?>

