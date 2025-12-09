<?php
/**
 * Create Quotation Endpoint
 * POST /quotations/create.php
 */

 require_once __DIR__ . '/../util/connect.php';
 require_once __DIR__ . '/../middleware/auth_middleware.php';
 require_once __DIR__ . '/../util/check_permission.php';
 
 // Ensure the request is authenticated
 requireJwtAuth();
 
 header('Content-Type: application/json');
 
 // Get the authenticated user's ID from the JWT payload
 $authUser = $GLOBALS['auth_user'] ?? null;
 $userId = $authUser['admin_id'] ?? null;
 
 if (!$userId) {
     http_response_code(401);
     echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
     exit;
 }
 
 // Check if the user has permission to create a country
 if (!checkUserPermission($userId, 'quotations', 'create')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to create quotations.']);
     exit;
 }
 
 // Get the authenticated user's ID from the JWT payload
 $authUser = $GLOBALS['auth_user'] ?? null;
 $userId = $authUser['admin_id'] ?? null;
 
 if (!$userId) {
     http_response_code(401);
     echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
     exit;
 }
 
 // Check if the user has permission to create a country
 if (!checkUserPermission($userId, 'parts finder', 'write')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to create part finds.']);
     exit;
 }

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

if (!isset($input['customer_name']) || empty(trim($input['customer_name']))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "customer_name" is required.']);
    exit;
}

if (!isset($input['customer_cell']) || empty(trim($input['customer_cell']))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "customer_cell" is required.']);
    exit;
}

if (!isset($input['customer_address']) || empty(trim($input['customer_address']))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "customer_address" is required.']);
    exit;
}

if (!isset($input['items']) || !is_array($input['items']) || empty($input['items'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "items" is required and must be a non-empty array.']);
    exit;
}

$pdo->beginTransaction();

try {
    // Validate user_id exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$input['user_id']]);
    if (!$stmt->fetch()) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    // Insert quotation without quote_no first
    $stmt = $pdo->prepare("
        INSERT INTO quotations (user_id, status, customer_name, customer_cell, customer_address)
        VALUES (?, ?, ?, ?, ?)
    ");

    $status = $input['status'] ?? 'pending';

    $stmt->execute([
        $input['user_id'],
        $status,
        trim($input['customer_name']),
        trim($input['customer_cell']),
        trim($input['customer_address'])
    ]);

    $quote_id = $pdo->lastInsertId();

    // Generate quote_no as YYYYMMDD + quote_id (e.g., 2025112545)
    $quote_no = date('Ymd') . $quote_id;

    // Update quotation with quote_no
    $stmt = $pdo->prepare("UPDATE quotations SET quote_no = ? WHERE id = ?");
    $stmt->execute([$quote_no, $quote_id]);

    // Insert quotation items
    $stmt_item = $pdo->prepare("
        INSERT INTO quotation_items (quote_id, sku, description, quantity, price, total)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($input['items'] as $item) {
        // Validate required item fields
        if (!isset($item['quantity']) || !isset($item['unit_price'])) {
            throw new Exception('Each item must have "quantity" and "unit_price".');
        }

        $sku = $item['sku'] ?? null;
        $item_description = $item['description'] ?? null;
        $quantity = (int)$item['quantity'];
        $unit_price = (float)$item['unit_price'];
        $line_total = $item['line_total'] ?? ($quantity * $unit_price);

        $stmt_item->execute([
            $quote_id,
            $sku,
            $item_description,
            $quantity,
            $unit_price,
            $line_total
        ]);
    }

    // Commit transaction
    $pdo->commit();

    // Fetch created quotation with items
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

