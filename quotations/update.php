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
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Quotation ID is required.']);
    exit;
}

$quote_id = $input['id'];

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

    if (isset($input['description'])) {
        $update_fields[] = "description = ?";
        $params[] = $input['description'];
    }
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
            if (!isset($item['quantity']) || !isset($item['price'])) {
                throw new Exception('Each item must have quantity and price.');
            }

            $sku = $item['sku'] ?? null;
            $item_description = $item['description'] ?? null;
            $quantity = (int)$item['quantity'];
            $price = (float)$item['price'];
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
        SELECT id, description, user_id, status, entry, created_at, updated_at
        FROM quotations WHERE id = ?
    ");
    $stmt->execute([$quote_id]);
    $quotation = $stmt->fetch();

    // Fetch quotation items
    $stmt = $pdo->prepare("
        SELECT id, quote_id, sku, description, quantity, price, total
        FROM quotation_items
        WHERE quote_id = ?
    ");
    $stmt->execute([$quote_id]);
    $quotation['items'] = $stmt->fetchAll();

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

