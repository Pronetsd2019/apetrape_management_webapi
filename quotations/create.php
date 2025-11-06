<?php
/**
 * Create Quotation Endpoint
 * POST /quotations/create.php
 */

require_once __DIR__ . '/../util/connect.php';
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
if (!isset($input['user_id']) || empty($input['user_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "user_id" is required.']);
    exit;
}

if (!isset($input['items']) || !is_array($input['items']) || empty($input['items'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "items" is required and must be a non-empty array.']);
    exit;
}

try {
    // Validate user_id exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$input['user_id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    // Start transaction
    $pdo->beginTransaction();

    // Insert quotation
    $stmt = $pdo->prepare("
        INSERT INTO quotations (description, user_id, status)
        VALUES (?, ?, ?)
    ");

    $description = $input['description'] ?? null;
    $status = $input['status'] ?? 'draft';

    $stmt->execute([
        $description,
        $input['user_id'],
        $status
    ]);

    $quote_id = $pdo->lastInsertId();

    // Insert quotation items
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

    // Commit transaction
    $pdo->commit();

    // Fetch created quotation with items
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

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Quotation created successfully.',
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
        'message' => 'Error creating quotation: ' . $e->getMessage()
    ]);
}
?>

