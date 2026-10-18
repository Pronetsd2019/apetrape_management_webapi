<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Get All Orders Endpoint (server-side pagination)
 * GET /orders/get_all.php?page=1&page_size=10&search=&status=&pay_status=&sort=confirm_date&order=desc
 *
 * Legacy: ?sort=asc|desc still controls confirm_date direction.
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../util/check_permission.php';
require_once __DIR__ . '/../util/order_list_helpers.php';

requireJwtAuth();

header('Content-Type: application/json');

$authUser = $GLOBALS['auth_user'] ?? null;
$userId = $authUser['admin_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
    exit;
}

if (!checkUserPermission($userId, 'orders', 'read')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to read orders.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    $pagination = parseAdminListPagination(25, 100);
    $sortInfo = parseOrderListSort();

    // Legacy: ?sort=asc|desc meant confirm_date direction (no field param)
    $legacySort = strtolower(trim((string)($_GET['sort'] ?? '')));
    if (in_array($legacySort, ['asc', 'desc'], true) && !isset($_GET['order']) && !isset($_GET['order_dir'])) {
        $sortInfo = [
            'key' => 'confirm_date',
            'dir' => $legacySort,
            'sql' => 'o.confirm_date ' . strtoupper($legacySort) . ', o.id DESC',
        ];
    }

    $statusParam = $_GET['status'] ?? null;
    $user_id = $_GET['user_id'] ?? null;
    $pay_status = isset($_GET['pay_status']) ? strtolower(trim($_GET['pay_status'])) : null;

    $validOrderStatuses = ['pending', 'confirmed', 'processed', 'delivered', 'cancelled', 'deleted'];
    $statuses = [];
    if ($statusParam !== null && $statusParam !== '') {
        $raw = is_array($statusParam) ? implode(',', $statusParam) : (string)$statusParam;
        $candidates = array_map('trim', explode(',', $raw));
        foreach ($candidates as $s) {
            if ($s !== '' && in_array($s, $validOrderStatuses, true)) {
                $statuses[] = $s;
            }
        }
        if (!empty($candidates) && empty($statuses)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid status. Use one or more (comma-separated): ' . implode(', ', $validOrderStatuses),
            ]);
            exit;
        }
    }

    $validPayStatuses = ['full_paid', 'partial', 'full_and_partial', 'no_payment'];
    if ($pay_status !== null && $pay_status !== '' && !in_array($pay_status, $validPayStatuses, true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid pay_status. Use: full_paid, partial, full_and_partial, no_payment',
        ]);
        exit;
    }

    $fromSql = "
        FROM orders o
        INNER JOIN users u ON o.user_id = u.id
        LEFT JOIN pickup_points pp ON o.pickup_point = pp.id
    ";

    $baseConditions = ["o.status != 'draft'"];
    $baseParams = [];

    if (!empty($statuses)) {
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
        $baseConditions[] = "o.status IN ($statusPlaceholders)";
        $baseParams = array_merge($baseParams, $statuses);
    }

    if ($user_id) {
        $baseConditions[] = 'o.user_id = ?';
        $baseParams[] = $user_id;
    }

    // Total (unfiltered by search/pay_status, but still scoped by status/user + no drafts)
    $totalSql = 'SELECT COUNT(*) ' . $fromSql . ' WHERE ' . implode(' AND ', $baseConditions);
    $totalStmt = $pdo->prepare($totalSql);
    $totalStmt->execute($baseParams);
    $total = (int)$totalStmt->fetchColumn();

    $filterConditions = $baseConditions;
    $filterParams = $baseParams;
    appendOrderPayStatusFilter($pay_status, $filterConditions);
    appendOrderSearchFilter($pagination['search'], $filterConditions, $filterParams);

    $whereSql = ' WHERE ' . implode(' AND ', $filterConditions);

    $countStmt = $pdo->prepare('SELECT COUNT(*) ' . $fromSql . $whereSql);
    $countStmt->execute($filterParams);
    $filtered = (int)$countStmt->fetchColumn();

    $listSql = "
        SELECT
            o.id,
            o.user_id,
            o.status,
            o.pay_status,
            o.created_at,
            o.updated_at,
            o.order_no,
            o.confirm_date,
            o.pay_method,
            o.delivery_method,
            o.delivery_address,
            o.pickup_point,
            u.name,
            u.surname,
            u.email,
            u.cell,
            pp.name AS pickup_point_name,
            pp.address AS pickup_point_address
        {$fromSql}
        {$whereSql}
        ORDER BY {$sortInfo['sql']}
        LIMIT ? OFFSET ?
    ";

    $listStmt = $pdo->prepare($listSql);
    $listParams = array_merge($filterParams, [$pagination['page_size'], $pagination['offset']]);
    foreach ($listParams as $idx => $value) {
        $listStmt->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $listStmt->execute();
    $orders = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    $orders = enrichOrdersWithAggregations($pdo, $orders);

    http_response_code(200);
    echo json_encode(orderListResponse(
        $orders,
        $total,
        $filtered,
        $pagination,
        'Orders retrieved successfully.'
    ));
} catch (PDOException $e) {
    logException('orders_get_all', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving orders: ' . $e->getMessage(),
    ]);
}
