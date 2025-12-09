<?php
/**
 * Create Item for Supplier Endpoint
 * POST /supplier/items/create.php
 */

require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get the authenticated supplier's ID from the JWT payload
$authUser = $GLOBALS['auth_user'] ?? null;
$supplierId = $authUser['supplier_id'] ?? null;

if (!$supplierId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated supplier.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$requiredFields = ['name', 'description', 'sku', 'price', 'cost_price'];
// Note: sale_price is now automatically calculated as cost_price + 10%
foreach ($requiredFields as $field) {
    if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '') || (is_numeric($input[$field]) && $input[$field] < 0)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "Field '{$field}' is required and cannot be empty."
        ]);
        exit;
    }
}

$name = trim($input['name']);
$description = trim($input['description']);
$sku = trim($input['sku']);
$price = (float)$input['price'];
$cost_price = (float)$input['cost_price'];
$discount = isset($input['discount']) ? (float)$input['discount'] : 0;
// Automatically calculate sale price as cost price + 10% markup
$sale_price = $cost_price * 1.10;
$is_universal = isset($input['is_universal']) ? (int)$input['is_universal'] : 0;

// Validate data
if (strlen($name) < 2) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Item name must be at least 2 characters long.'
    ]);
    exit;
}

if (strlen($sku) < 3) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'SKU must be at least 3 characters long.'
    ]);
    exit;
}

if ($price <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Price must be greater than 0.'
    ]);
    exit;
}

if ($cost_price <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Cost price must be greater than 0.'
    ]);
    exit;
}

if ($discount < 0 || $discount > 100) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Discount must be between 0 and 100.'
    ]);
    exit;
}

try {
    // Check for duplicate SKU
    $stmt = $pdo->prepare("SELECT id FROM items WHERE sku = ?");
    $stmt->execute([$sku]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'An item with this SKU already exists.'
        ]);
        exit;
    }

    // Insert the item
    $stmt = $pdo->prepare("
        INSERT INTO items (supplier_id, name, description, sku, price, discount, sale_price, cost_price, is_universal)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $result = $stmt->execute([
        $supplierId,
        $name,
        $description,
        $sku,
        $price,
        $discount,
        $sale_price,
        $cost_price,
        $is_universal
    ]);

    if ($result) {
        $itemId = $pdo->lastInsertId();

        // Fetch the created item
        $stmt = $pdo->prepare("
            SELECT
                id, supplier_id, name, description, sku, price, discount,
                sale_price, cost_price, is_universal, created_at, updated_at
            FROM items
            WHERE id = ?
        ");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Item created successfully.',
            'data' => $item
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create item.'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating item: ' . $e->getMessage()
    ]);
}
?>
