<?php
/**
 * Test script: get_assigned_to_me logic with hardcoded user id 3
 * Run via: php test_assigned_to_me.php or open in browser (e.g. .../control/orders/test_assigned_to_me.php)
 * No JWT required; for local debugging only. Remove or restrict in production.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../util/connect.php';

$userId = 3; // hardcoded for testing

try {
    // Same query as get_assigned_to_me.php
    $stmtAssigned = $pdo->prepare("
        SELECT DISTINCT order_id
        FROM order_assignments
        WHERE assigned_to = ?
        ORDER BY order_id DESC
    ");
    $stmtAssigned->execute([$userId]);
    $assignedOrderIds = array_column($stmtAssigned->fetchAll(PDO::FETCH_ASSOC), 'order_id');

    // Raw rows from order_assignments for assigned_to = 3
    $stmtRaw = $pdo->prepare("SELECT * FROM order_assignments WHERE assigned_to = ?");
    $stmtRaw->execute([$userId]);
    $rawRows = $stmtRaw->fetchAll(PDO::FETCH_ASSOC);

    $result = [
        'success' => true,
        'hardcoded_user_id' => $userId,
        'order_assignments_rows' => $rawRows,
        'assigned_order_ids' => $assignedOrderIds,
        'count' => count($assignedOrderIds)
    ];

    if (!empty($assignedOrderIds)) {
        $placeholders = implode(',', array_fill(0, count($assignedOrderIds), '?'));
        $stmtOrders = $pdo->prepare("
            SELECT id, order_no, status, user_id, confirm_date
            FROM orders
            WHERE id IN ($placeholders)
        ");
        $stmtOrders->execute($assignedOrderIds);
        $result['orders_preview'] = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $result['orders_preview'] = [];
    }

    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'hardcoded_user_id' => $userId
    ], JSON_PRETTY_PRINT);
}
