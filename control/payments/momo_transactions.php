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
 * MoMo Payments Transactions Endpoint
 * GET /payments/momo_transactions.php
 *
 * Query parameters (all optional):
 * - date_from, date_to (YYYY-MM-DD)
 * - status: new, pending, successful, failed, rejected, timeout, cancelled
 * - search: order no, email, cell, name, external_id, reference_id, financial_transaction_id
 * - page, limit
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../util/check_permission.php';
require_once __DIR__ . '/../util/momo_collection.php';

requireJwtAuth();

header('Content-Type: application/json');

$authUser = $GLOBALS['auth_user'] ?? null;
$userId = $authUser['admin_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
    exit;
}

if (!checkUserPermission($userId, 'payments', 'read')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to read payments.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

$parseDate = static function ($value) {
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $value = trim($value);
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        return false;
    }
    return $value;
};

$dateFrom = $parseDate($_GET['date_from'] ?? null);
$dateTo = $parseDate($_GET['date_to'] ?? null);

if ($dateFrom === false || $dateTo === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid date. Use YYYY-MM-DD for date_from and date_to.']);
    exit;
}

if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'date_from cannot be after date_to.']);
    exit;
}

$validStatuses = ['new', 'pending', 'successful', 'failed', 'rejected', 'timeout', 'cancelled', 'canceled'];
$statuses = [];
$statusParam = $_GET['status'] ?? null;
if ($statusParam !== null && $statusParam !== '') {
    $raw = is_array($statusParam) ? implode(',', $statusParam) : (string)$statusParam;
    foreach (array_map('trim', explode(',', strtolower($raw))) as $candidate) {
        if ($candidate === '') {
            continue;
        }
        if (!in_array($candidate, $validStatuses, true)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid status. Use one or more (comma-separated): ' . implode(', ', $validStatuses)
            ]);
            exit;
        }
        $statuses[] = $candidate;
    }
}

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 25;
$limit = min(100, max(1, $limit));

try {
    ensureMomoTransactionsTable($pdo);

    $from = "
        FROM momo_transactions mt
        INNER JOIN orders o ON mt.order_id = o.id
        INNER JOIN users u ON o.user_id = u.id
    ";

    $baseConditions = [];
    $baseParams = [];

    if ($dateFrom) {
        $baseConditions[] = 'mt.created_at >= ?';
        $baseParams[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo) {
        $baseConditions[] = 'mt.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $baseParams[] = $dateTo;
    }
    if ($search !== '') {
        $like = '%' . addcslashes($search, '\\%_') . '%';
        $searchColumns = [
            'o.order_no',
            'u.email',
            'u.cell',
            'mt.cell',
            "CONCAT(COALESCE(u.name, ''), ' ', COALESCE(u.surname, ''))",
            'mt.external_id',
            'mt.reference_id',
            'mt.financial_transaction_id',
        ];
        $baseConditions[] = '(' . implode(' OR ', array_map(
            static fn($column) => "{$column} LIKE ?",
            $searchColumns
        )) . ')';
        foreach ($searchColumns as $_) {
            $baseParams[] = $like;
        }
    }

    $listConditions = $baseConditions;
    $listParams = $baseParams;
    if (!empty($statuses)) {
        $listConditions[] = 'LOWER(mt.status) IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        $listParams = array_merge($listParams, $statuses);
    }

    $baseWhere = $baseConditions ? ' WHERE ' . implode(' AND ', $baseConditions) : '';
    $listWhere = $listConditions ? ' WHERE ' . implode(' AND ', $listConditions) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) {$from} {$listWhere}");
    $countStmt->execute($listParams);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($total / $limit));
    $offset = ($page - 1) * $limit;

    $listStmt = $pdo->prepare("
        SELECT
            mt.id,
            mt.order_id,
            mt.user_id,
            mt.external_id,
            mt.reference_id,
            mt.cell AS momo_cell,
            mt.amount,
            mt.currency,
            mt.status,
            mt.reason,
            mt.financial_transaction_id,
            mt.created_at,
            mt.updated_at,
            o.order_no,
            o.status AS order_status,
            o.pay_status,
            u.id AS customer_id,
            u.name,
            u.surname,
            u.email,
            u.cell
        {$from}
        {$listWhere}
        ORDER BY mt.created_at DESC, mt.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $listStmt->execute($listParams);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $recordedByTransaction = [];
    $transactionIds = array_values(array_unique(array_filter(array_column($rows, 'financial_transaction_id'))));
    if (!empty($transactionIds)) {
        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
        $paymentsStmt = $pdo->prepare("
            SELECT id, order_id, ref, amount, pay_date, transaction_id
            FROM payments
            WHERE transaction_id IN ({$placeholders})
        ");
        $paymentsStmt->execute($transactionIds);
        foreach ($paymentsStmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            $recordedByTransaction[(string)$payment['transaction_id']] = [
                'id' => (int)$payment['id'],
                'ref' => $payment['ref'],
                'amount' => round((float)$payment['amount'], 2),
                'pay_date' => $payment['pay_date'],
            ];
        }
    }

    $data = array_map(static function (array $row) use ($recordedByTransaction) {
        $status = strtolower((string)$row['status']);
        $failureReason = null;
        if (in_array($status, ['failed', 'rejected', 'timeout', 'cancelled', 'canceled'], true)) {
            $description = trim((string)($row['reason'] ?? ''));
            $failureReason = $description !== '' ? $description : null;
        }

        $finId = $row['financial_transaction_id'];

        return [
            'id' => (int)$row['id'],
            'external_id' => $row['external_id'],
            'reference_id' => $row['reference_id'],
            'financial_transaction_id' => $finId,
            'cell' => $row['momo_cell'],
            'amount' => round((float)$row['amount'], 2),
            'currency' => $row['currency'],
            'status' => $status,
            'reason' => $row['reason'],
            'failure_reason' => $failureReason,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'order' => [
                'id' => (int)$row['order_id'],
                'order_no' => $row['order_no'],
                'status' => $row['order_status'],
                'pay_status' => $row['pay_status'],
            ],
            'customer' => [
                'id' => (int)$row['customer_id'],
                'name' => $row['name'],
                'surname' => $row['surname'],
                'email' => $row['email'],
                'cell' => $row['cell'],
            ],
            'recorded_payment' => $finId !== null
                ? ($recordedByTransaction[(string)$finId] ?? null)
                : null,
        ];
    }, $rows);

    $summaryStmt = $pdo->prepare("
        SELECT
            LOWER(mt.status) AS status,
            COUNT(*) AS total,
            SUM(mt.amount) AS amount
        {$from}
        {$baseWhere}
        GROUP BY LOWER(mt.status)
    ");
    $summaryStmt->execute($baseParams);

    $summary = [
        'total' => 0,
        'new' => 0,
        'pending' => 0,
        'successful' => 0,
        'failed' => 0,
        'rejected' => 0,
        'timeout' => 0,
        'cancelled' => 0,
        'successful_amount' => 0.0,
    ];
    foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $summaryRow) {
        $key = strtolower((string)$summaryRow['status']);
        if ($key === 'canceled') {
            $key = 'cancelled';
        }
        $count = (int)$summaryRow['total'];
        $summary['total'] += $count;
        $summary[$key] = ($summary[$key] ?? 0) + $count;
        if ($key === 'successful') {
            $summary['successful_amount'] = round((float)$summaryRow['amount'], 2);
        }
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $total > 0 ? 'MoMo transactions retrieved successfully.' : 'No MoMo transactions found.',
        'data' => $data,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
        'summary' => $summary,
    ]);
} catch (PDOException $e) {
    logException('control_momo_transactions', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while loading MoMo transactions.',
        'error_details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('control_momo_transactions', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred.',
        'error_details' => $e->getMessage()
    ]);
}
