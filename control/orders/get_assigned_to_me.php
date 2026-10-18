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
 * Get Orders Assigned to Me (current driver/admin) — server-side pagination
 * GET /orders/get_assigned_to_me.php?status=assigned&page=1&page_size=10&search=
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
    $statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : null;

    $fromSql = "
        FROM orders o
        INNER JOIN users u ON o.user_id = u.id
        LEFT JOIN pickup_points pp ON o.pickup_point = pp.id
        INNER JOIN (
            SELECT order_id, MAX(id) AS assignment_id
            FROM order_assignments
            WHERE assigned_to = ?
    ";
    $baseParams = [$userId];
    if ($statusFilter !== null && $statusFilter !== '') {
        $fromSql .= ' AND status = ?';
        $baseParams[] = $statusFilter;
    }
    $fromSql .= '
            GROUP BY order_id
        ) latest_oa ON latest_oa.order_id = o.id
        INNER JOIN order_assignments oa ON oa.id = latest_oa.assignment_id
    ';

    $baseConditions = ["o.status != 'draft'"];
    $whereSql = ' WHERE ' . implode(' AND ', $baseConditions);

    $totalStmt = $pdo->prepare('SELECT COUNT(*) ' . $fromSql . $whereSql);
    $totalStmt->execute($baseParams);
    $total = (int)$totalStmt->fetchColumn();

    $filterConditions = $baseConditions;
    $filterParams = $baseParams;
    appendOrderSearchFilter($pagination['search'], $filterConditions, $filterParams);
    $filterWhereSql = ' WHERE ' . implode(' AND ', $filterConditions);

    $countStmt = $pdo->prepare('SELECT COUNT(*) ' . $fromSql . $filterWhereSql);
    $countStmt->execute($filterParams);
    $filtered = (int)$countStmt->fetchColumn();

    if ($filtered === 0) {
        $extra = ['current_user_id' => (int)$userId];
        if ($statusFilter !== null && $statusFilter !== '') {
            $extra['status_filter_applied'] = $statusFilter;
        }
        http_response_code(200);
        echo json_encode(orderListResponse(
            [],
            $total,
            0,
            $pagination,
            ($statusFilter ? 'No orders match. Try without ?status= or use ?status=assigned.' : 'Orders retrieved successfully.'),
            $extra
        ));
        exit;
    }

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
            pp.address AS pickup_point_address,
            oa.id AS assignment_id,
            oa.status AS assignment_status,
            oa.updated_at AS assignment_updated_at
        {$fromSql}
        {$filterWhereSql}
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

    $extra = ['current_user_id' => (int)$userId];
    if ($statusFilter !== null && $statusFilter !== '') {
        $extra['status_filter_applied'] = $statusFilter;
    }

    http_response_code(200);
    echo json_encode(orderListResponse(
        $orders,
        $total,
        $filtered,
        $pagination,
        'Orders retrieved successfully.',
        $extra
    ));
} catch (PDOException $e) {
    logException('orders_get_assigned_to_me', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving assigned orders: ' . $e->getMessage(),
    ]);
}
