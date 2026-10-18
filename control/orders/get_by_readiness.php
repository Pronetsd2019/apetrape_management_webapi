<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Get orders by sourcing readiness (ready / not_ready / failed)
 * GET /orders/get_by_readiness.php?readiness=ready&page=1&page_size=10&search=
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

$validReadiness = ['ready', 'not_ready', 'failed'];
$readinessParam = $_GET['readiness'] ?? null;
$requestedBuckets = [];
if ($readinessParam !== null && $readinessParam !== '') {
    $raw = is_array($readinessParam) ? implode(',', $readinessParam) : (string)$readinessParam;
    $candidates = array_map('trim', explode(',', $raw));
    foreach ($candidates as $r) {
        if ($r !== '' && in_array($r, $validReadiness, true)) {
            $requestedBuckets[$r] = true;
        }
    }
    if (!empty($candidates) && empty($requestedBuckets)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid readiness. Use one or more (comma-separated): ' . implode(', ', $validReadiness),
        ]);
        exit;
    }
}
if (empty($requestedBuckets)) {
    $requestedBuckets = array_fill_keys($validReadiness, true);
}

try {
    $pagination = parseAdminListPagination(25, 100);
    $sortInfo = parseOrderListSort();

    $stmtOrders = $pdo->query("
        SELECT id FROM orders o
        WHERE o.status != 'draft'
        AND NOT EXISTS (SELECT 1 FROM order_assignments oa WHERE oa.order_id = o.id)
    ");
    $orderIds = $stmtOrders ? array_column($stmtOrders->fetchAll(PDO::FETCH_ASSOC), 'id') : [];

    $stmtSc = $pdo->query('SELECT order_id, type, status FROM sourcing_calls');
    $sourcingRows = $stmtSc ? $stmtSc->fetchAll(PDO::FETCH_ASSOC) : [];

    $byOrder = [];
    foreach ($sourcingRows as $row) {
        $oid = (int)$row['order_id'];
        if (!isset($byOrder[$oid])) {
            $byOrder[$oid] = ['has_not_found' => false, 'has_sourcing_not_received' => false];
        }
        $type = trim((string)($row['type'] ?? ''));
        $status = trim((string)($row['status'] ?? ''));
        if ($status === 'not found') {
            $byOrder[$oid]['has_not_found'] = true;
        }
        if ($type === 'sourcing' && $status !== 'received' && $status !== 'not found') {
            $byOrder[$oid]['has_sourcing_not_received'] = true;
        }
    }

    $ready = [];
    $not_ready = [];
    $failed = [];
    $readinessByOrderId = [];

    foreach ($orderIds as $oid) {
        $oid = (int)$oid;
        if (!isset($byOrder[$oid])) {
            $not_ready[] = $oid;
            $readinessByOrderId[$oid] = 'not_ready';
            continue;
        }
        $info = $byOrder[$oid];
        if ($info['has_not_found']) {
            $failed[] = $oid;
            $readinessByOrderId[$oid] = 'failed';
        } elseif ($info['has_sourcing_not_received']) {
            $not_ready[] = $oid;
            $readinessByOrderId[$oid] = 'not_ready';
        } else {
            $ready[] = $oid;
            $readinessByOrderId[$oid] = 'ready';
        }
    }

    $counts = [];
    if (!empty($requestedBuckets['ready'])) {
        $counts['ready'] = count($ready);
    }
    if (!empty($requestedBuckets['not_ready'])) {
        $counts['not_ready'] = count($not_ready);
    }
    if (!empty($requestedBuckets['failed'])) {
        $counts['failed'] = count($failed);
    }

    $idsToFetch = [];
    if (!empty($requestedBuckets['ready'])) {
        $idsToFetch = array_merge($idsToFetch, $ready);
    }
    if (!empty($requestedBuckets['not_ready'])) {
        $idsToFetch = array_merge($idsToFetch, $not_ready);
    }
    if (!empty($requestedBuckets['failed'])) {
        $idsToFetch = array_merge($idsToFetch, $failed);
    }
    $idsToFetch = array_values(array_unique(array_map('intval', $idsToFetch)));
    $total = count($idsToFetch);

    if (empty($idsToFetch)) {
        http_response_code(200);
        echo json_encode(orderListResponse(
            [],
            0,
            0,
            $pagination,
            'Orders by readiness retrieved.',
            ['counts' => $counts]
        ));
        exit;
    }

    $fromSql = "
        FROM orders o
        INNER JOIN users u ON o.user_id = u.id
        LEFT JOIN pickup_points pp ON o.pickup_point = pp.id
    ";

    $idPlaceholders = implode(',', array_fill(0, count($idsToFetch), '?'));
    $filterConditions = ["o.id IN ($idPlaceholders)"];
    $filterParams = $idsToFetch;
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

    foreach ($orders as &$order) {
        $order['readiness'] = $readinessByOrderId[(int)$order['id']] ?? 'not_ready';
    }
    unset($order);

    http_response_code(200);
    echo json_encode(orderListResponse(
        $orders,
        $total,
        $filtered,
        $pagination,
        'Orders by readiness retrieved.',
        ['counts' => $counts]
    ));
} catch (PDOException $e) {
    logException('orders_get_by_readiness', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving orders by readiness.',
        'error_details' => $e->getMessage(),
    ]);
} catch (Exception $e) {
    logException('orders_get_by_readiness', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving orders by readiness.',
    ]);
}
