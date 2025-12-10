<?php
/**
 * Get Quotation by ID Endpoint
 * GET /quotations/get_by_id.php?quote_id=123
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../util/check_permission.php';
require_once __DIR__ . '/../util/error_logger.php';

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

// Check if the user has permission to read quotations
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

// Get quote_id from query parameters
$quote_id = $_GET['quote_id'] ?? null;

if (!$quote_id || !is_numeric($quote_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing quote_id parameter.']);
    exit;
}

$quote_id = (int)$quote_id;

try {
    // Fetch quotation details
    $stmt = $pdo->prepare("
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
        WHERE q.id = ? AND q.status != 'deleted'
    ");
    $stmt->execute([$quote_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Quotation not found or has been deleted.'
        ]);
        exit;
    }

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
        ORDER BY id ASC
    ");
    $stmt->execute([$quote_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach items to quotation
    $quotation['items'] = $items;
    $quotation['item_count'] = count($items);
    
    // Calculate totals
    $quotation['quote_total'] = array_sum(array_column($items, 'total'));
    
    // Calculate subtotal (before VAT)
    $subtotal = $quotation['quote_total'];
    $quotation['subtotal'] = $subtotal;
    
    // Calculate VAT (15%)
    $vat = $subtotal * 0.15;
    $quotation['vat'] = $vat;
    
    // Calculate grand total
    $grand_total = $subtotal + $vat;
    $quotation['grand_total'] = $grand_total;

    // Check if quotation is linked to a part find request
    $stmt = $pdo->prepare("
        SELECT 
            pfq.part_find_id,
            pfr.id as part_request_id,
            pfr.message as part_request_message,
            pfr.status as part_request_status
        FROM part_find_qoutations pfq
        INNER JOIN part_find_requests pfr ON pfq.part_find_id = pfr.id
        WHERE pfq.quote_id = ?
    ");
    $stmt->execute([$quote_id]);
    $partFindLink = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($partFindLink) {
        $quotation['part_find_request'] = [
            'id' => $partFindLink['part_request_id'],
            'message' => $partFindLink['part_request_message'],
            'status' => $partFindLink['part_request_status']
        ];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Quotation retrieved successfully.',
        'data' => $quotation
    ]);

} catch (PDOException $e) {
    logException('quotations/get_by_id', $e, ['quote_id' => $quote_id]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching quotation: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('quotations/get_by_id', $e, ['quote_id' => $quote_id]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching quotation: ' . $e->getMessage()
    ]);
}
?>
