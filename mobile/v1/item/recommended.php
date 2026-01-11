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
 * Mobile Recommended Items Endpoint
 * GET /mobile/v1/item/recommended.php?page=1&page_size=10
 * Public endpoint - returns ALL items sorted by recommendation score (popular + fresh + profitable)
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

// Parse and validate pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page_size = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10;

// Validate pagination parameters
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

try {
    // First, get sales data for popularity scoring
    $salesData = [];
    try {
        $salesStmt = $pdo->query("
            SELECT
                oi.sku,
                SUM(oi.quantity) as total_sold,
                COUNT(DISTINCT o.id) as order_count
            FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE o.status != 'draft'
            GROUP BY oi.sku
        ");
        while ($row = $salesStmt->fetch(PDO::FETCH_ASSOC)) {
            $salesData[$row['sku']] = [
                'total_sold' => (int)$row['total_sold'],
                'order_count' => (int)$row['order_count']
            ];
        }
    } catch (Exception $e) {
        // If sales data query fails, continue with empty sales data
        logException('mobile_item_recommended_sales_data', $e);
    }

    // Get all items with recommendation scores
    $stmt = $pdo->prepare("
        SELECT
            i.id,
            i.name,
            i.description,
            i.sku,
            i.price,
            i.discount,
            i.sale_price,
            i.cost_price,
            i.lead_time,
            i.is_universal,
            i.created_at,
            i.updated_at,
            -- Calculate recommendation score components
            COALESCE(sales.total_sold, 0) as sales_volume,
            DATEDIFF(CURDATE(), DATE(i.created_at)) as days_old,
            CASE
                WHEN i.cost_price IS NOT NULL AND i.cost_price > 0
                THEN ((COALESCE(i.sale_price, i.price) - i.cost_price) / i.cost_price) * 100
                ELSE 0
            END as profit_margin
        FROM items i
        LEFT JOIN (
            SELECT
                oi.sku,
                SUM(oi.quantity) as total_sold
            FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE o.status != 'draft'
            GROUP BY oi.sku
        ) sales ON i.sku = sales.sku
        ORDER BY (
            -- Popularity score (50%): normalize sales volume (0-1 scale)
            (COALESCE(sales.total_sold, 0) / GREATEST(1, (
                SELECT MAX(total_sold) FROM (
                    SELECT SUM(oi.quantity) as total_sold
                    FROM order_items oi
                    INNER JOIN orders o ON oi.order_id = o.id
                    WHERE o.status != 'draft'
                    GROUP BY oi.sku
                ) max_sales
            ))) * 0.5 +

            -- Freshness score (30%): exponential decay over time (newer = higher score)
            (1 / (1 + DATEDIFF(CURDATE(), DATE(i.created_at)) / 30.0)) * 0.3 +

            -- Profitability score (20%): normalize profit margin (0-1 scale, cap at 200% margin)
            (LEAST(CASE
                WHEN i.cost_price IS NOT NULL AND i.cost_price > 0
                THEN ((COALESCE(i.sale_price, i.price) - i.cost_price) / i.cost_price)
                ELSE 0
            END, 2.0) / 2.0) * 0.2
        ) DESC,
        i.created_at DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->execute([$page_size, $offset]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $countStmt = $pdo->query("SELECT COUNT(*) as total FROM items");
    $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
    $total_items = (int)$totalResult['total'];
    $total_pages = ceil($total_items / $page_size);

    if (empty($items) && $page > 1) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No more items available.',
            'data' => [],
            'pagination' => [
                'current_page' => $page,
                'page_size' => $page_size,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'has_more' => false
            ]
        ]);
        exit;
    }

    // Fetch related data for the items
    if (!empty($items)) {
        $itemIds = array_column($items, 'id');
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

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
            WHERE ivm.item_id IN ({$placeholders})
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
            WHERE ic.item_id IN ({$placeholders})
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

        // Format items with related data and recommendation scores
        $formatted_items = [];
        foreach ($items as $item) {
            $itemId = $item['id'];

            // Get image URL (first image)
            $imageStmt = $pdo->prepare("
                SELECT src FROM item_images
                WHERE item_id = ?
                ORDER BY id ASC
                LIMIT 1
            ");
            $imageStmt->execute([$itemId]);
            $imageResult = $imageStmt->fetch(PDO::FETCH_ASSOC);
            $imageUrl = $imageResult ? $imageResult['src'] : null;

            // Calculate recommendation score components for debugging
            $salesVolume = (int)($item['sales_volume'] ?? 0);
            $daysOld = (int)($item['days_old'] ?? 0);
            $profitMargin = (float)($item['profit_margin'] ?? 0);

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
                'image_url' => $imageUrl,
                'supported_models' => $modelsByItem[$itemId] ?? [],
                'categories' => $categoriesByItem[$itemId] ?? [],
                'created_at' => $item['created_at'],
                'updated_at' => $item['updated_at'],
                'recommendation_score' => [
                    'popularity' => $salesVolume,
                    'freshness_days' => $daysOld,
                    'profit_margin_percent' => round($profitMargin, 2)
                ]
            ];
        }
    } else {
        $formatted_items = [];
    }

    $has_more = ($page * $page_size) < $total_items;

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Recommended items retrieved successfully.',
        'data' => $formatted_items,
        'pagination' => [
            'current_page' => $page,
            'page_size' => $page_size,
            'total_items' => $total_items,
            'total_pages' => $total_pages,
            'has_more' => $has_more
        ],
        'recommendation_params' => [
            'weights' => [
                'popularity' => 50,
                'freshness' => 30,
                'profitability' => 20
            ],
            'algorithm' => 'weighted_scoring'
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_item_recommended', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while retrieving recommended items. Please try again later.',
        'error_details' => 'Error retrieving recommended items: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_item_recommended', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error retrieving recommended items: ' . $e->getMessage()
    ]);
}
?>
