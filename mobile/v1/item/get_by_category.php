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

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Mobile Items by Category Endpoint
 * GET /mobile/v1/item/get_by_category.php?category_id={id}&page=1&page_size=10
 * Public endpoint - returns items filtered by category (including subcategories)
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';

header('Content-Type: application/json');

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

// Parse and validate parameters
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page_size = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10;

// Validate parameters
if (!$category_id || $category_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid category ID. Please provide a valid category_id parameter.'
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

/**
 * Get cached category tree or build it if not exists
 * @return array Category tree mapping category_id => [descendant_ids]
 */
function getCategoryTree() {
    global $pdo;
    static $cached_category_tree = null;

    if ($cached_category_tree !== null) {
        return $cached_category_tree;
    }

    $cached_category_tree = [];

    try {
        // Get all categories
        $stmt = $pdo->query("SELECT id, parent_id FROM categories ORDER BY id");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build tree structure
        $tree = [];
        foreach ($categories as $category) {
            $id = (int)$category['id'];
            $parent_id = $category['parent_id'] ? (int)$category['parent_id'] : null;

            if (!isset($tree[$id])) {
                $tree[$id] = [];
            }

            if ($parent_id !== null) {
                if (!isset($tree[$parent_id])) {
                    $tree[$parent_id] = [];
                }
                $tree[$parent_id][] = $id;
            }
        }

        // Function to get all descendants recursively
        $getDescendants = function($categoryId) use (&$tree, &$getDescendants) {
            $descendants = [$categoryId]; // Include self

            if (isset($tree[$categoryId])) {
                foreach ($tree[$categoryId] as $childId) {
                    $descendants = array_merge($descendants, $getDescendants($childId));
                }
            }

            return $descendants;
        };

        // Build cached tree for all categories
        foreach ($categories as $category) {
            $id = (int)$category['id'];
            $cached_category_tree[$id] = $getDescendants($id);
        }

    } catch (Exception $e) {
        // If category tree building fails, log and return empty array
        logException('mobile_item_get_by_category_tree', $e);
        $cached_category_tree = [];
    }

    return $cached_category_tree;
}

try {
    // Get category tree and find all descendant category IDs
    $category_tree = getCategoryTree();
    $descendant_ids = $category_tree[$category_id] ?? [$category_id];

    if (empty($descendant_ids)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No items found in this category.',
            'data' => [],
            'pagination' => [
                'current_page' => $page,
                'page_size' => $page_size,
                'total_items' => 0,
                'total_pages' => 0,
                'has_more' => false
            ],
            'category_info' => [
                'category_id' => $category_id,
                'descendant_categories' => []
            ]
        ]);
        exit;
    }

    // Build query to get items from category and its descendants
    $placeholders = implode(',', array_fill(0, count($descendant_ids), '?'));

    // First, get total count for pagination
    $countStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT i.id) as total
        FROM items i
        INNER JOIN item_category ic ON i.id = ic.item_id
        WHERE ic.category_id IN ({$placeholders})
    ");
    $countStmt->execute($descendant_ids);
    $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
    $total_items = (int)$totalResult['total'];
    $total_pages = ceil($total_items / $page_size);

    // Get items with pagination
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            i.id,
            i.name,
            i.description,
            i.sku,
            i.is_universal,
            i.price,
            i.discount,
            i.sale_price,
            i.cost_price,
            i.lead_time,
            i.created_at,
            i.updated_at
        FROM items i
        INNER JOIN item_category ic ON i.id = ic.item_id
        WHERE ic.category_id IN ({$placeholders})
        ORDER BY i.created_at DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->execute(array_merge($descendant_ids, [$page_size, $offset]));
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No items found in this category.',
            'data' => [],
            'pagination' => [
                'current_page' => $page,
                'page_size' => $page_size,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'has_more' => false
            ],
            'category_info' => [
                'category_id' => $category_id,
                'descendant_categories' => $descendant_ids
            ]
        ]);
        exit;
    }

    // Fetch related data for the items
    if (!empty($items)) {
        $itemIds = array_column($items, 'id');
        $itemPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));

        // Fetch supported models for each item
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

        // Fetch categories for items
        $stmtCategories = $pdo->prepare("
            SELECT
                ic.item_id,
                c.id AS category_id,
                c.name AS category_name
            FROM item_category ic
            INNER JOIN categories c ON ic.category_id = c.id
            WHERE ic.item_id IN ({$itemPlaceholders})
            ORDER BY ic.item_id ASC, c.name ASC
        ");
        $stmtCategories->execute($itemIds);
        $categoryLinks = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

        $categoriesByItem = [];
        foreach ($categoryLinks as $category) {
            $itemId = $category['item_id'];
            unset($category['item_id']);
            $categoriesByItem[$itemId][] = $category;
        }

        // Get images for items
        $stmtImages = $pdo->prepare("
            SELECT
                ii.item_id,
                ii.id as image_id,
                ii.src,
                ii.alt as alt_text,
                ii.created_at
            FROM item_images ii
            WHERE ii.item_id IN ({$itemPlaceholders})
            ORDER BY ii.item_id ASC, ii.id ASC
        ");
        $stmtImages->execute($itemIds);
        $imageLinks = $stmtImages->fetchAll(PDO::FETCH_ASSOC);

        $imagesByItem = [];
        foreach ($imageLinks as $image) {
            $itemId = $image['item_id'];
            unset($image['item_id']);
            $imagesByItem[$itemId][] = $image;
        }

        // Format items with related data
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
                'cost_price' => $item['cost_price'] ? (float)$item['cost_price'] : null,
                'lead_time' => $item['lead_time'] ?: null,
                'images' => $imagesByItem[$itemId] ?? [],
                'supported_models' => $modelsByItem[$itemId] ?? [],
                'categories' => $categoriesByItem[$itemId] ?? [],
                'created_at' => $item['created_at'],
                'updated_at' => $item['updated_at']
            ];
        }
    } else {
        $formatted_items = [];
    }

    $has_more = ($page * $page_size) < $total_items;

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
        'category_info' => [
            'category_id' => $category_id,
            'descendant_categories' => $descendant_ids,
            'total_descendants' => count($descendant_ids)
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_item_get_by_category', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while retrieving items. Please try again later.',
        'error_details' => 'Error retrieving items by category: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_item_get_by_category', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error retrieving items by category: ' . $e->getMessage()
    ]);
}
?>
