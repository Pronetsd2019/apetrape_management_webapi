<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
/**
 * Get Quotations by Status Endpoint
 * GET /quotations/get_by_status.php?status[]=pending&status[]=sent
 * or
 * GET /quotations/get_by_status.php?status=pending,sent,accepted
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

try {
    // Get status array from query parameters
    // Supports both ?status[]=pending&status[]=sent and ?status=pending,sent
    $statusArray = [];
    
    if (isset($_GET['status'])) {
        if (is_array($_GET['status'])) {
            // Array format: ?status[]=pending&status[]=sent
            $statusArray = $_GET['status'];
        } else {
            // Comma-separated format: ?status=pending,sent,accepted
            $statusArray = array_map('trim', explode(',', $_GET['status']));
        }
    }

    // Validate that status array is not empty
    if (empty($statusArray)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'At least one status is required. Use ?status[]=pending&status[]=sent or ?status=pending,sent'
        ]);
        exit;
    }

    // Remove duplicates and empty values
    $statusArray = array_values(array_unique(array_filter($statusArray)));

    // Get optional query parameters for additional filtering
    $user_id = $_GET['user_id'] ?? null;
    $sort = strtolower($_GET['sort'] ?? 'desc');

    // Validate sort parameter
    if (!in_array($sort, ['asc', 'desc'])) {
        $sort = 'desc';
    }

    // Build query with IN clause for multiple statuses
    $sql = "
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
    ";

    $params = [];
    $conditions = [];

    // Add status filter with IN clause
    $statusPlaceholders = implode(',', array_fill(0, count($statusArray), '?'));
    $conditions[] = "q.status IN ($statusPlaceholders)";
    $params = array_merge($params, $statusArray);

    // Add user_id filter if provided
    if ($user_id) {
        $conditions[] = "q.user_id = ?";
        $params[] = $user_id;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY q.created_at " . strtoupper($sort);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($quotations)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No quotations found with the specified statuses.',
            'filters' => [
                'statuses' => $statusArray,
                'user_id' => $user_id,
                'sort' => $sort
            ],
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
        'filters' => [
            'statuses' => $statusArray,
            'user_id' => $user_id,
            'sort' => $sort
        ],
        'data' => $quotations,
        'count' => count($quotations)
    ]);

} catch (PDOException $e) {
    logException('quotations_get_by_status', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching quotations: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('quotations_get_by_status', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching quotations: ' . $e->getMessage()
    ]);
}
?>
