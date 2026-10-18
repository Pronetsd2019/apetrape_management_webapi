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
 * Get All Items Endpoint (server-side pagination)
 * GET /item/get_all.php?page=1&page_size=10&search=&sort=created_at&order=desc
 *
 * Also accepts DataTables-style params: draw, start, length, search[value]
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../util/check_permission.php';

requireJwtAuth();

header('Content-Type: application/json');

$authUser = $GLOBALS['auth_user'] ?? null;
$userId = $authUser['admin_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
    exit;
}

if (!checkUserPermission($userId, 'stock management', 'read')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to read stock items.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    // DataTables draw (optional echo-back)
    $draw = isset($_GET['draw']) ? (int)$_GET['draw'] : null;

    // Pagination: prefer page/page_size; fall back to start/length
    if (isset($_GET['page']) || isset($_GET['page_size'])) {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $pageSize = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10;
    } elseif (isset($_GET['start']) || isset($_GET['length'])) {
        $start = max(0, (int)($_GET['start'] ?? 0));
        $pageSize = isset($_GET['length']) ? (int)$_GET['length'] : 10;
        if ($pageSize < 1) {
            $pageSize = 10;
        }
        $page = (int)floor($start / $pageSize) + 1;
    } else {
        $page = 1;
        $pageSize = 25;
    }

    if ($page < 1) {
        $page = 1;
    }
    if ($pageSize < 1) {
        $pageSize = 10;
    }
    if ($pageSize > 100) {
        $pageSize = 100;
    }
    $offset = ($page - 1) * $pageSize;

    // Search
    $search = '';
    if (isset($_GET['search']) && is_string($_GET['search'])) {
        $search = trim($_GET['search']);
    } elseif (isset($_GET['search']) && is_array($_GET['search']) && isset($_GET['search']['value'])) {
        $search = trim((string)$_GET['search']['value']);
    }
    if (strlen($search) > 200) {
        $search = substr($search, 0, 200);
    }

    // Sorting whitelist
    $sortMap = [
        'id' => 'i.id',
        'name' => 'i.name',
        'supplier_name' => 's.name',
        'sku' => 'i.sku',
        'price' => 'i.price',
        'discount' => 'i.discount',
        'sale_price' => 'i.sale_price',
        'cost_price' => 'i.cost_price',
        'created_at' => 'i.created_at',
        'updated_at' => 'i.updated_at',
    ];

    $sortKey = strtolower(trim((string)($_GET['sort'] ?? $_GET['order_by'] ?? 'created_at')));
    if (!isset($sortMap[$sortKey])) {
        $sortKey = 'created_at';
    }
    $orderDir = strtolower(trim((string)($_GET['order'] ?? $_GET['order_dir'] ?? 'desc')));
    if ($orderDir !== 'asc' && $orderDir !== 'desc') {
        $orderDir = 'desc';
    }
    $orderSql = $sortMap[$sortKey] . ' ' . strtoupper($orderDir);

    $fromSql = "
        FROM items i
        LEFT JOIN suppliers s ON i.supplier_id = s.id
    ";

    $whereSql = ' WHERE 1=1 ';
    $params = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $whereSql .= "
            AND (
                i.name LIKE ?
                OR i.sku LIKE ?
                OR i.description LIKE ?
                OR s.name LIKE ?
                OR EXISTS (
                    SELECT 1
                    FROM store_items si
                    INNER JOIN stores st ON si.store_id = st.id
                    WHERE si.item_id = i.id
                      AND st.name LIKE ?
                )
                OR EXISTS (
                    SELECT 1
                    FROM item_vehicle_models ivm
                    INNER JOIN vehicle_models vm ON ivm.vehicle_model_id = vm.id
                    INNER JOIN manufacturers m ON vm.manufacturer_id = m.id
                    WHERE ivm.item_id = i.id
                      AND (vm.model_name LIKE ? OR m.name LIKE ?)
                )
            )
        ";
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
    }

    // Total (unfiltered)
    $total = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();

    // Filtered count
    $countStmt = $pdo->prepare('SELECT COUNT(*) ' . $fromSql . $whereSql);
    $countStmt->execute($params);
    $filtered = (int)$countStmt->fetchColumn();

    // Page of items
    $listSql = "
        SELECT
            i.id,
            i.supplier_id,
            s.name AS supplier_name,
            i.name,
            i.description,
            i.sku,
            i.is_universal,
            i.created_at,
            i.updated_at,
            i.price,
            i.discount,
            i.sale_price,
            i.cost_price,
            (
                SELECT src
                FROM item_images ii
                WHERE ii.item_id = i.id
                ORDER BY ii.id ASC
                LIMIT 1
            ) AS image_url
        {$fromSql}
        {$whereSql}
        ORDER BY {$orderSql}
        LIMIT ? OFFSET ?
    ";

    $listParams = array_merge($params, [$pageSize, $offset]);
    $stmt = $pdo->prepare($listSql);
    // PDO may bind LIMIT/OFFSET as strings; cast as ints via ATTR emulation — bind explicitly
    foreach ($listParams as $idx => $value) {
        $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($idx + 1, $value, $type);
    }
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        $response = [
            'success' => true,
            'message' => 'No items found.',
            'data' => [],
            'count' => 0,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'filtered' => $filtered,
                'total_pages' => $pageSize > 0 ? (int)ceil($filtered / $pageSize) : 0,
            ],
        ];
        if ($draw !== null) {
            $response['draw'] = $draw;
            $response['recordsTotal'] = $total;
            $response['recordsFiltered'] = $filtered;
        }
        http_response_code(200);
        echo json_encode($response);
        exit;
    }

    $itemIds = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

    $stmtModels = $pdo->prepare("
        SELECT
            ivm.item_id,
            vm.id AS vehicle_model_id,
            vm.model_name,
            vm.variant,
            vm.year_from,
            vm.year_to,
            m.id AS manufacturer_id,
            m.name AS manufacturer_name
        FROM item_vehicle_models ivm
        INNER JOIN vehicle_models vm ON ivm.vehicle_model_id = vm.id
        INNER JOIN manufacturers m ON vm.manufacturer_id = m.id
        WHERE ivm.item_id IN ($placeholders)
        ORDER BY m.name ASC, vm.model_name ASC
    ");
    $stmtModels->execute($itemIds);
    $links = $stmtModels->fetchAll(PDO::FETCH_ASSOC);

    $modelsByItem = [];
    foreach ($links as $link) {
        $itemId = $link['item_id'];
        unset($link['item_id']);
        $modelsByItem[$itemId][] = $link;
    }

    $stmtImages = $pdo->prepare("
        SELECT item_id, id, alt, src, created_at, updated_at
        FROM item_images
        WHERE item_id IN ($placeholders)
        ORDER BY item_id ASC, id ASC
    ");
    $stmtImages->execute($itemIds);
    $images = $stmtImages->fetchAll(PDO::FETCH_ASSOC);

    $imagesByItem = [];
    foreach ($images as $image) {
        $itemId = $image['item_id'];
        unset($image['item_id']);
        $imagesByItem[$itemId][] = $image;
    }

    $stmtStores = $pdo->prepare("
        SELECT
            si.item_id,
            si.store_id,
            s.name AS store_name,
            s.physical_address
        FROM store_items si
        INNER JOIN stores s ON si.store_id = s.id
        WHERE si.item_id IN ($placeholders)
        ORDER BY si.item_id ASC
    ");
    $stmtStores->execute($itemIds);
    $stores = $stmtStores->fetchAll(PDO::FETCH_ASSOC);

    $storesByItem = [];
    foreach ($stores as $store) {
        $itemId = $store['item_id'];
        unset($store['item_id']);
        $storesByItem[$itemId][] = $store;
    }

    $stmtCategories = $pdo->prepare("
        SELECT
            ic.item_id,
            c.id AS category_id,
            c.name AS category_name
        FROM item_category ic
        INNER JOIN categories c ON ic.category_id = c.id
        WHERE ic.item_id IN ($placeholders)
        ORDER BY ic.item_id ASC, c.name ASC
    ");
    $stmtCategories->execute($itemIds);
    $categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

    $categoriesByItem = [];
    foreach ($categories as $category) {
        $itemId = $category['item_id'];
        unset($category['item_id']);
        $categoriesByItem[$itemId][] = $category;
    }

    foreach ($items as &$item) {
        $itemId = $item['id'];
        $item['supported_models'] = $modelsByItem[$itemId] ?? [];
        $item['images'] = $imagesByItem[$itemId] ?? [];
        $item['stores'] = $storesByItem[$itemId] ?? [];
        $item['categories'] = $categoriesByItem[$itemId] ?? [];
    }
    unset($item);

    $response = [
        'success' => true,
        'message' => 'Items fetched successfully.',
        'data' => $items,
        'count' => count($items),
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'filtered' => $filtered,
            'total_pages' => $pageSize > 0 ? (int)ceil($filtered / $pageSize) : 0,
        ],
    ];
    if ($draw !== null) {
        $response['draw'] = $draw;
        $response['recordsTotal'] = $total;
        $response['recordsFiltered'] = $filtered;
    }

    http_response_code(200);
    echo json_encode($response);
} catch (PDOException $e) {
    logException('item_get_all', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching items: ' . $e->getMessage(),
    ]);
}
