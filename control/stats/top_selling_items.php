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
 * Top Selling Items Statistics Endpoint
 * GET /stats/top_selling_items.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    // Get top 5 selling items by aggregating order_items data
    // Exclude orders with draft status
    $stmt = $pdo->prepare("
        SELECT
            oi.sku,
            oi.name,
            SUM(oi.quantity) as total_quantity_sold,
            SUM(oi.total) as total_revenue,
            COUNT(DISTINCT o.id) as orders_count,
            AVG(oi.price) as average_selling_price,
            AVG(oi.cost) as average_cost,
            SUM(oi.total - (oi.cost * oi.quantity)) as total_profit,
            SUM(oi.cost * oi.quantity) as total_cost
        FROM order_items oi
        INNER JOIN orders o ON oi.order_id = o.id
        WHERE o.status != 'draft'
        GROUP BY oi.sku, oi.name
        HAVING total_quantity_sold > 0
        ORDER BY total_quantity_sold DESC
        LIMIT 5
    ");

    $stmt->execute();
    $sellingItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If we have selling items, get additional item details
    if (!empty($sellingItems)) {
        $skus = array_column($sellingItems, 'sku');
        $placeholders = implode(',', array_fill(0, count($skus), '?'));

        // Get detailed item information
        $stmtDetails = $pdo->prepare("
            SELECT
                i.id,
                i.sku,
                i.name,
                i.description,
                i.supplier_id,
                s.name as supplier_name,
                i.price,
                i.cost_price,
                i.sale_price,
                i.discount,
                (
                    SELECT src
                    FROM item_images ii
                    WHERE ii.item_id = i.id
                    ORDER BY ii.id ASC
                    LIMIT 1
                ) AS image_url
            FROM items i
            LEFT JOIN suppliers s ON i.supplier_id = s.id
            WHERE i.sku IN ($placeholders)
        ");

        $stmtDetails->execute($skus);
        $itemDetails = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

        // Create a map of SKU to item details
        $itemDetailsMap = [];
        foreach ($itemDetails as $item) {
            $itemDetailsMap[$item['sku']] = $item;
        }

        // Calculate profit margins using historical cost data
        foreach ($sellingItems as &$item) {
            $sku = $item['sku'];

            // Use historical cost data from order_items for accurate profit calculation
            $averageCost = (float)($item['average_cost'] ?? 0);
            $averageSellingPrice = (float)($item['average_selling_price'] ?? 0);
            $totalProfit = (float)($item['total_profit'] ?? 0);
            $totalCost = (float)($item['total_cost'] ?? 0);

            // Calculate profit margin using historical data
            $item['profit_margin'] = $averageCost > 0 ? round((($averageSellingPrice - $averageCost) / $averageCost) * 100, 2) : 0;
            $item['total_profit'] = round($totalProfit, 2);
            $item['total_cost'] = round($totalCost, 2);

            // Try to get item name from items table
            if (isset($itemDetailsMap[$sku])) {
                $itemDetails = $itemDetailsMap[$sku];
                $item = array_merge($itemDetails, $item);
                $item['item_name'] = $itemDetails['name'];
            } else {
                // Item not found in items table, use order_items name as item_name
                $item['item_name'] = $item['name'] ?? '';
            }

            // Format numeric values
            $item['total_quantity_sold'] = (int)$item['total_quantity_sold'];
            $item['total_revenue'] = round((float)$item['total_revenue'], 2);
            $item['average_selling_price'] = round($averageSellingPrice, 2);
            $item['average_cost'] = round($averageCost, 2);
            $item['orders_count'] = (int)$item['orders_count'];

            // Remove the raw average_price field since we renamed it
            unset($item['average_price']);
        }
        unset($item);
    }

    // Calculate total quantity sold across all top items
    $totalQuantitySold = array_sum(array_column($sellingItems, 'total_quantity_sold'));

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Top selling items retrieved successfully.',
        'data' => $sellingItems,
        'count' => $totalQuantitySold
    ]);

} catch (PDOException $e) {
    logException('stats_top_selling_items', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving top selling items: ' . $e->getMessage()
    ]);
}
?>
