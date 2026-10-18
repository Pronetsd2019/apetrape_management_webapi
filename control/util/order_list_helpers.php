<?php
/**
 * Shared helpers for admin order list endpoints (pagination + enrichment).
 */

/**
 * @return array{page:int,page_size:int,offset:int,search:string,draw:?int}
 */
function parseAdminListPagination(int $defaultPageSize = 25, int $maxPageSize = 100): array
{
    $draw = isset($_GET['draw']) ? (int)$_GET['draw'] : null;

    if (isset($_GET['page']) || isset($_GET['page_size'])) {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $pageSize = isset($_GET['page_size']) ? (int)$_GET['page_size'] : $defaultPageSize;
    } elseif (isset($_GET['start']) || isset($_GET['length'])) {
        $start = max(0, (int)($_GET['start'] ?? 0));
        $pageSize = isset($_GET['length']) ? (int)$_GET['length'] : $defaultPageSize;
        if ($pageSize < 1) {
            $pageSize = $defaultPageSize;
        }
        $page = (int)floor($start / $pageSize) + 1;
    } else {
        $page = 1;
        $pageSize = $defaultPageSize;
    }

    if ($page < 1) {
        $page = 1;
    }
    if ($pageSize < 1) {
        $pageSize = $defaultPageSize;
    }
    if ($pageSize > $maxPageSize) {
        $pageSize = $maxPageSize;
    }

    $search = '';
    if (isset($_GET['search']) && is_string($_GET['search'])) {
        $search = trim($_GET['search']);
    } elseif (isset($_GET['search']) && is_array($_GET['search']) && isset($_GET['search']['value'])) {
        $search = trim((string)$_GET['search']['value']);
    }
    if (strlen($search) > 200) {
        $search = substr($search, 0, 200);
    }

    return [
        'page' => $page,
        'page_size' => $pageSize,
        'offset' => ($page - 1) * $pageSize,
        'search' => $search,
        'draw' => $draw,
    ];
}

/**
 * @return array{sql:string,dir:string,key:string}
 */
function parseOrderListSort(): array
{
    $sortMap = [
        'id' => 'o.id',
        'confirm_date' => 'o.confirm_date',
        'created_at' => 'o.created_at',
        'order_no' => 'o.order_no',
        'status' => 'o.status',
        'pay_status' => 'o.pay_status',
        'pay_method' => 'o.pay_method',
        'user_name' => 'u.surname',
        'name' => 'u.name',
    ];

    $key = strtolower(trim((string)($_GET['sort'] ?? $_GET['order_by'] ?? 'confirm_date')));
    if (!isset($sortMap[$key])) {
        $key = 'confirm_date';
    }

    $dir = strtolower(trim((string)($_GET['order'] ?? $_GET['order_dir'] ?? $_GET['sort_dir'] ?? 'desc')));
    // Legacy get_all used ?sort=asc|desc for confirm_date direction
    if (in_array($key, ['asc', 'desc'], true)) {
        $dir = $key;
        $key = 'confirm_date';
    }
    if ($dir !== 'asc' && $dir !== 'desc') {
        $dir = 'desc';
    }

    return [
        'key' => $key,
        'dir' => $dir,
        'sql' => $sortMap[$key] . ' ' . strtoupper($dir) . ', o.id DESC',
    ];
}

/**
 * SQL expression for order total (items + delivery + pickup).
 */
function orderTotalAmountSql(): string
{
    return '(
        COALESCE((SELECT SUM(oi.total) FROM order_items oi WHERE oi.order_id = o.id), 0)
        + COALESCE((SELECT SUM(df.fee) FROM delivery_fee df WHERE df.order_id = o.id), 0)
        + COALESCE((SELECT SUM(pf.fee) FROM pickup_order_fees pf WHERE pf.order_id = o.id), 0)
    )';
}

function orderPaidAmountSql(): string
{
    return 'COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id = o.id), 0)';
}

/**
 * Append pay_status filter conditions. Mutates $conditions / $params.
 */
function appendOrderPayStatusFilter(?string $payStatus, array &$conditions): void
{
    if ($payStatus === null || $payStatus === '') {
        return;
    }

    $total = orderTotalAmountSql();
    $paid = orderPaidAmountSql();

    switch ($payStatus) {
        case 'full_paid':
            $conditions[] = "$total > 0 AND $paid >= $total";
            break;
        case 'partial':
            $conditions[] = "$paid > 0 AND $paid < $total";
            break;
        case 'full_and_partial':
            $conditions[] = "$paid > 0";
            break;
        case 'no_payment':
            $conditions[] = "$paid = 0";
            break;
    }
}

/**
 * Append free-text search across order/user fields.
 */
function appendOrderSearchFilter(string $search, array &$conditions, array &$params): void
{
    if ($search === '') {
        return;
    }

    $like = '%' . $search . '%';
    $conditions[] = '(
        o.order_no LIKE ?
        OR o.status LIKE ?
        OR o.pay_status LIKE ?
        OR o.pay_method LIKE ?
        OR o.delivery_address LIKE ?
        OR u.name LIKE ?
        OR u.surname LIKE ?
        OR u.email LIKE ?
        OR u.cell LIKE ?
        OR CONCAT(COALESCE(u.surname, \'\'), \' \', COALESCE(u.name, \'\')) LIKE ?
        OR pp.name LIKE ?
        OR pp.address LIKE ?
    )';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like]);
}

/**
 * Attach order_items, fees, aggregations, and address fields to each order row.
 *
 * @param array<int,array<string,mixed>> $orders
 * @return array<int,array<string,mixed>>
 */
function enrichOrdersWithAggregations(PDO $pdo, array $orders): array
{
    if (empty($orders)) {
        return $orders;
    }

    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $stmtItems = $pdo->prepare("
        SELECT id, sku, name, quantity, price, total, created_at, updated_at, order_id
        FROM order_items
        WHERE order_id IN ($placeholders)
        ORDER BY order_id ASC, id ASC
    ");
    $stmtItems->execute($orderIds);
    $allItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $itemsByOrder = [];
    foreach ($allItems as $item) {
        $orderId = $item['order_id'];
        if (!isset($itemsByOrder[$orderId])) {
            $itemsByOrder[$orderId] = [
                'items' => [],
                'unique_items_count' => 0,
                'total_quantity' => 0,
                'total_price' => 0.0,
                'unique_skus' => [],
            ];
        }
        $itemsByOrder[$orderId]['items'][] = $item;
        $itemsByOrder[$orderId]['total_quantity'] += (int)$item['quantity'];
        $itemsByOrder[$orderId]['total_price'] += (float)$item['total'];
        if (!in_array($item['sku'], $itemsByOrder[$orderId]['unique_skus'], true)) {
            $itemsByOrder[$orderId]['unique_skus'][] = $item['sku'];
            $itemsByOrder[$orderId]['unique_items_count']++;
        }
    }

    $stmtPayments = $pdo->prepare("
        SELECT order_id, SUM(amount) AS total_paid
        FROM payments
        WHERE order_id IN ($placeholders)
        GROUP BY order_id
    ");
    $stmtPayments->execute($orderIds);
    $paymentsMap = [];
    foreach ($stmtPayments->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $paymentsMap[$payment['order_id']] = (float)$payment['total_paid'];
    }

    $stmtDeliveryFee = $pdo->prepare("
        SELECT order_id, id, cost_map_id, fee, created_at, updated_at
        FROM delivery_fee
        WHERE order_id IN ($placeholders)
    ");
    $stmtDeliveryFee->execute($orderIds);
    $deliveryFeeMap = [];
    $deliveryFeeRowByOrder = [];
    foreach ($stmtDeliveryFee->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $deliveryFeeMap[$row['order_id']] = (float)$row['fee'];
        $deliveryFeeRowByOrder[$row['order_id']] = $row;
    }

    $stmtPickupFee = $pdo->prepare("
        SELECT order_id, id, pickup_id, fee, create_At
        FROM pickup_order_fees
        WHERE order_id IN ($placeholders)
    ");
    $stmtPickupFee->execute($orderIds);
    $pickupFeeMap = [];
    $pickupFeeRowByOrder = [];
    foreach ($stmtPickupFee->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pickupFeeMap[$row['order_id']] = (float)$row['fee'];
        $pickupFeeRowByOrder[$row['order_id']] = $row;
    }

    foreach ($orders as &$order) {
        $orderId = $order['id'];
        $paidAmount = $paymentsMap[$orderId] ?? 0.0;
        $deliveryFeeAmount = $deliveryFeeMap[$orderId] ?? 0.0;
        $pickupFeeAmount = $pickupFeeMap[$orderId] ?? 0.0;

        $dbDeliveryMethod = $order['delivery_method'] ?? null;
        $order['address'] = ($dbDeliveryMethod === 'delivery')
            ? ($order['delivery_address'] ?? '')
            : trim(($order['pickup_point_name'] ?? '') . ($order['pickup_point_address'] ? ' - ' . $order['pickup_point_address'] : ''));
        $order['delivery_method'] = ($dbDeliveryMethod === 'pickup') ? 'collection' : ($dbDeliveryMethod ?: 'delivery');

        $deliveryFeeRow = isset($deliveryFeeRowByOrder[$orderId])
            ? [
                'id' => (int)$deliveryFeeRowByOrder[$orderId]['id'],
                'cost_map_id' => (int)$deliveryFeeRowByOrder[$orderId]['cost_map_id'],
                'fee' => (float)$deliveryFeeRowByOrder[$orderId]['fee'],
                'order_id' => (int)$deliveryFeeRowByOrder[$orderId]['order_id'],
                'created_at' => $deliveryFeeRowByOrder[$orderId]['created_at'],
                'updated_at' => $deliveryFeeRowByOrder[$orderId]['updated_at'],
            ]
            : null;
        $pickupFeeRow = isset($pickupFeeRowByOrder[$orderId])
            ? [
                'id' => (int)$pickupFeeRowByOrder[$orderId]['id'],
                'pickup_id' => (int)$pickupFeeRowByOrder[$orderId]['pickup_id'],
                'order_id' => (int)$pickupFeeRowByOrder[$orderId]['order_id'],
                'fee' => (float)$pickupFeeRowByOrder[$orderId]['fee'],
                'create_At' => $pickupFeeRowByOrder[$orderId]['create_At'],
            ]
            : null;

        $order['delivery_fee'] = $deliveryFeeRow;
        $order['pickup_fee'] = $pickupFeeRow;

        if (isset($itemsByOrder[$orderId])) {
            $order['order_items'] = $itemsByOrder[$orderId]['items'];
            $totalPrice = $itemsByOrder[$orderId]['total_price'];
            $totalAmount = $totalPrice + $deliveryFeeAmount + $pickupFeeAmount;
            $order['aggregations'] = [
                'unique_items_count' => $itemsByOrder[$orderId]['unique_items_count'],
                'total_quantity' => $itemsByOrder[$orderId]['total_quantity'],
                'total_price' => round($totalPrice, 2),
                'delivery_fee' => round($deliveryFeeAmount, 2),
                'pickup_fee' => round($pickupFeeAmount, 2),
                'total_amount' => round($totalAmount, 2),
                'paid_amount' => $paidAmount,
                'due_amount' => round($totalAmount - $paidAmount, 2),
            ];
        } else {
            $order['order_items'] = [];
            $totalAmount = $deliveryFeeAmount + $pickupFeeAmount;
            $order['aggregations'] = [
                'unique_items_count' => 0,
                'total_quantity' => 0,
                'total_price' => 0.0,
                'delivery_fee' => round($deliveryFeeAmount, 2),
                'pickup_fee' => round($pickupFeeAmount, 2),
                'total_amount' => round($totalAmount, 2),
                'paid_amount' => $paidAmount,
                'due_amount' => round($totalAmount - $paidAmount, 2),
            ];
        }
    }
    unset($order);

    return $orders;
}

/**
 * Build a standard list response with pagination metadata.
 */
function orderListResponse(
    array $orders,
    int $total,
    int $filtered,
    array $pagination,
    string $message = 'Orders retrieved successfully.',
    array $extra = []
): array {
    $page = $pagination['page'];
    $pageSize = $pagination['page_size'];
    $payload = array_merge([
        'success' => true,
        'message' => $message,
        'data' => array_values($orders),
        'count' => count($orders),
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'filtered' => $filtered,
            'total_pages' => $pageSize > 0 ? (int)ceil($filtered / $pageSize) : 0,
        ],
    ], $extra);

    if ($pagination['draw'] !== null) {
        $payload['draw'] = $pagination['draw'];
        $payload['recordsTotal'] = $total;
        $payload['recordsFiltered'] = $filtered;
    }

    return $payload;
}
