<?php
/**
 * Get All Quotations Endpoint
 * GET /quotations/get_all.php
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
 if (!checkUserPermission($userId, 'quotations', 'read')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to read quotations.']);
     exit;
 }

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    // Get all quotations
    $stmt = $pdo->query("
        SELECT 
            q.id,
            q.user_id,
            q.status,
            q.quote_no,
            q.customer_name,
            q.customer_cell,
            q.customer_address,
            q.sent_date,
            q.created_at,
            q.updated_at
        FROM quotations q
        WHERE q.status != 'deleted'
        ORDER BY q.created_at DESC
    ");
    
    $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($quotations)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No quotations found.',
            'data' => [],
            'count' => 0
        ]);
        exit;
    }
    
    // Get all quotation IDs
    $quoteIds = array_column($quotations, 'id');
    $placeholders = implode(',', array_fill(0, count($quoteIds), '?'));
    
    // Fetch all items for these quotations
    $stmtItems = $pdo->prepare("
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
        WHERE quote_id IN ($placeholders)
        ORDER BY quote_id ASC, id ASC
    ");
    $stmtItems->execute($quoteIds);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    
    // Group items by quote_id
    $itemsByQuote = [];
    foreach ($items as $item) {
        $quoteId = $item['quote_id'];
        unset($item['quote_id']);
        $itemsByQuote[$quoteId][] = $item;
    }
    
    // Attach items and calculate totals for each quotation
    foreach ($quotations as &$quotation) {
        $quoteId = $quotation['id'];
        $quotation['items'] = $itemsByQuote[$quoteId] ?? [];
        $quotation['item_count'] = count($quotation['items']);
        $quotation['quote_total'] = array_sum(array_column($quotation['items'], 'total'));
    }
    unset($quotation);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Quotations fetched successfully.',
        'data' => $quotations,
        'count' => count($quotations)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching quotations: ' . $e->getMessage()
    ]);
}
?>

