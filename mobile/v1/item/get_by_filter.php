<?php

// CORS headers for subdomain support and localhost
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';
$isLocalhostOrigin = isset($_SERVER['HTTP_ORIGIN']) && (
    strpos($_SERVER['HTTP_ORIGIN'], 'http://localhost') === 0 ||
    strpos($_SERVER['HTTP_ORIGIN'], 'http://127.0.0.1') === 0
);

if ((isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) || $isLocalhostOrigin) {
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
 * Mobile Items by Filter Endpoint (category and/or manufacturer)
 * GET /mobile/v1/item/get_by_filter.php?category_id={id}&manufacturer_id={id}&model_ids=1,2&page=1&page_size=10
 * Public endpoint - accepts category_id and/or manufacturer_id; returns items matching the filter(s).
 * When both are provided, items must match both (in category AND manufacturer/universal).
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$manufacturer_id = isset($_GET['manufacturer_id']) ? (int)$_GET['manufacturer_id'] : null;
$model_ids_param = isset($_GET['model_ids']) ? $_GET['model_ids'] : null;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page_size = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10;

if (!$category_id && !$manufacturer_id) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Provide at least one of category_id or manufacturer_id.'
    ]);
    exit;
}

if ($category_id !== null && $category_id <= 0) {
    $category_id = null;
}
if ($manufacturer_id !== null && $manufacturer_id <= 0) {
    $manufacturer_id = null;
}
if (!$category_id && !$manufacturer_id) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Provide at least one valid category_id or manufacturer_id.'
    ]);
    exit;
}

if ($page < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Page must be 1 or greater.']);
    exit;
}

if ($page_size < 1 || $page_size > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Page size must be between 1 and 100.']);
    exit;
}

$offset = ($page - 1) * $page_size;

$model_ids = [];
if ($model_ids_param) {
    if (is_array($model_ids_param)) {
        $model_ids = array_map('intval', array_filter($model_ids_param, 'is_numeric'));
    } else {
        $model_ids = array_map('intval', array_filter(explode(',', $model_ids_param), 'is_numeric'));
    }
    $model_ids = array_filter($model_ids, function($id) { return $id > 0; });
}

/**
 * Get cached category tree or build it if not exists
 */
function getCategoryTree() {
    global $pdo;
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = [];
    try {
        $stmt = $pdo->query("SELECT id, parent_id FROM categories ORDER BY id");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $tree = [];
        foreach ($categories as $c) {
            $id = (int)$c['id'];
            $parent_id = $c['parent_id'] ? (int)$c['parent_id'] : null;
            if (!isset($tree[$id])) $tree[$id] = [];
            if ($parent_id !== null) {
                if (!isset($tree[$parent_id])) $tree[$parent_id] = [];
                $tree[$parent_id][] = $id;
            }
        }
        $getDescendants = function($cid) use (&$tree, &$getDescendants) {
            $out = [$cid];
            if (isset($tree[$cid])) {
                foreach ($tree[$cid] as $childId) {
                    $out = array_merge($out, $getDescendants($childId));
                }
            }
            return $out;
        };
        foreach ($categories as $c) {
            $id = (int)$c['id'];
            $cached[$id] = $getDescendants($id);
        }
    } catch (Exception $e) {
        logException('mobile_item_get_by_filter_tree', $e);
    }
    return $cached;
}

try {
    $descendant_ids = null;
    if ($category_id) {
        $tree = getCategoryTree();
        $descendant_ids = $tree[$category_id] ?? [$category_id];
        if (empty($descendant_ids)) {
            $descendant_ids = [$category_id];
        }
    }

    $where_parts = [];
    $params = [];
    $joins = '';

    // Category filter: item must be in category (or descendants)
    if ($category_id && !empty($descendant_ids)) {
        $joins .= " INNER JOIN item_category ic ON i.id = ic.item_id AND ic.category_id IN (" . implode(',', array_fill(0, count($descendant_ids), '?')) . ") ";
        $params = array_merge($params, $descendant_ids);
    }

    // Manufacturer filter: universal OR from this manufacturer
    if ($manufacturer_id) {
        $joins .= "
            LEFT JOIN item_vehicle_models ivm ON i.id = ivm.item_id
            LEFT JOIN vehicle_models vm ON ivm.vehicle_model_id = vm.id
            LEFT JOIN manufacturers m ON vm.manufacturer_id = m.id
        ";
        $where_parts[] = " (i.is_universal = 1 OR m.id = ?) ";
        $params[] = $manufacturer_id;

        if (!empty($model_ids)) {
            $model_placeholders = implode(',', array_fill(0, count($model_ids), '?'));
            $where_parts[] = " vm.id IN ({$model_placeholders}) ";
            $params = array_merge($params, $model_ids);
        }
    }

    $where_clause = count($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';

    $countSql = "
        SELECT COUNT(DISTINCT i.id) as total
        FROM items i
        {$joins}
        {$where_clause}
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total_items = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_items / $page_size);

    $itemsSql = "
        SELECT DISTINCT
            i.id,
            i.name,
            i.description,
            i.sku,
            i.is_universal,
            i.price,
            i.discount,
            i.sale_price,
            i.lead_time,
            i.created_at,
            i.updated_at
        FROM items i
        {$joins}
        {$where_clause}
        ORDER BY i.is_universal DESC, i.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $itemsStmt = $pdo->prepare($itemsSql);
    $itemsStmt->execute(array_merge($params, [$page_size, $offset]));
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No items found for the given filter.',
            'data' => [],
            'pagination' => [
                'current_page' => $page,
                'page_size' => $page_size,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'has_more' => false
            ],
            'filter_info' => [
                'category_id' => $category_id,
                'manufacturer_id' => $manufacturer_id,
                'model_ids' => $model_ids
            ]
        ]);
        exit;
    }

    $itemIds = array_column($items, 'id');
    $itemPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));

    $stmtModels = $pdo->prepare("
        SELECT ivm.item_id, vm.id AS vehicle_model_id, vm.model_name, vm.variant, vm.year_from, vm.year_to, m.id AS manufacturer_id, m.name AS manufacturer_name
        FROM item_vehicle_models ivm
        INNER JOIN vehicle_models vm ON ivm.vehicle_model_id = vm.id
        INNER JOIN manufacturers m ON vm.manufacturer_id = m.id
        WHERE ivm.item_id IN ({$itemPlaceholders})
        ORDER BY m.name ASC, vm.model_name ASC
    ");
    $stmtModels->execute($itemIds);
    $modelLinks = $stmtModels->fetchAll(PDO::FETCH_ASSOC);
    $modelsByItem = [];
    foreach ($modelLinks as $link) {
        $itemId = $link['item_id'];
        unset($link['item_id']);
        $modelsByItem[$itemId][] = $link;
    }

    $stmtCategories = $pdo->prepare("
        SELECT ic.item_id, c.id AS category_id, c.name AS category_name
        FROM item_category ic
        INNER JOIN categories c ON ic.category_id = c.id
        WHERE ic.item_id IN ({$itemPlaceholders})
        ORDER BY ic.item_id ASC, c.name ASC
    ");
    $stmtCategories->execute($itemIds);
    $categoryLinks = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);
    $categoriesByItem = [];
    foreach ($categoryLinks as $c) {
        $itemId = $c['item_id'];
        unset($c['item_id']);
        $categoriesByItem[$itemId][] = $c;
    }

    $stmtImages = $pdo->prepare("
        SELECT ii.item_id, ii.id as image_id, ii.src, ii.alt as alt_text, ii.created_at
        FROM item_images ii
        WHERE ii.item_id IN ({$itemPlaceholders})
        ORDER BY ii.item_id ASC, ii.id ASC
    ");
    $stmtImages->execute($itemIds);
    $imageLinks = $stmtImages->fetchAll(PDO::FETCH_ASSOC);
    $imagesByItem = [];
    foreach ($imageLinks as $img) {
        $itemId = $img['item_id'];
        unset($img['item_id']);
        $imagesByItem[$itemId][] = $img;
    }

    $formatted_items = [];
    foreach ($items as $item) {
        $itemId = $item['id'];
        $formatted_items[] = [
            'id' => (int)$item['id'],
            'name' => $item['name'],
            'description' => $item['description'] ?: null,
            'sku' => $item['sku'] ?: null,
            'is_universal' => (bool)$item['is_universal'],
            'price' => $item['price'] ? (float)$item['price'] : null,
            'discount' => $item['discount'] ? (float)$item['discount'] : null,
            'sale_price' => $item['sale_price'] ? (float)$item['sale_price'] : null,
            'lead_time' => $item['lead_time'] ?: null,
            'images' => $imagesByItem[$itemId] ?? [],
            'supported_models' => $modelsByItem[$itemId] ?? [],
            'categories' => $categoriesByItem[$itemId] ?? [],
            'created_at' => $item['created_at'],
            'updated_at' => $item['updated_at']
        ];
    }

    $has_more = ($page * $page_size) < $total_items;

    $filter_info = [
        'category_id' => $category_id,
        'manufacturer_id' => $manufacturer_id,
        'model_ids' => $model_ids
    ];
    if ($category_id && isset($descendant_ids)) {
        $filter_info['descendant_categories'] = $descendant_ids;
    }
    if ($manufacturer_id) {
        $filter_info['includes_universal'] = true;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Items retrieved successfully.',
        'data' => $formatted_items,
        'pagination' => [
            'current_page' => $page,
            'page_size' => $page_size,
            'total_items' => $total_items,
            'total_pages' => $total_pages,
            'has_more' => $has_more
        ],
        'filter_info' => $filter_info
    ]);

} catch (PDOException $e) {
    logException('mobile_item_get_by_filter', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while retrieving items.',
        'error_details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_item_get_by_filter', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred.',
        'error_details' => $e->getMessage()
    ]);
}
