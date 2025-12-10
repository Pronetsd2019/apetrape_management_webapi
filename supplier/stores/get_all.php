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
 * Get All Stores for Supplier Endpoint
 * GET /supplier/stores/get_all.php
 */

require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/util/error_logger.php';
require_once __DIR__ . '/../../control/middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

// Get the authenticated supplier's ID from the JWT payload
$authUser = $GLOBALS['auth_user'] ?? null;
$supplierId = $authUser['supplier_id'] ?? null;

if (!$supplierId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated supplier.']);
    exit;
}

try {
    // Fetch stores for supplier
    $stmt = $pdo->prepare("
        SELECT
            s.id AS store_id,
            s.name AS store_name,
            s.physical_address,
            cp.id AS contact_person_id,
            cp.name AS contact_person_name,
            cp.email AS contact_person_email,
            cp.cell AS contact_person_cellphone,
            c.id AS city_id,
            c.name AS city_name,
            r.id AS region_id,
            r.name AS region_name,
            co.id AS country_id,
            co.name AS country_name,
            s.status,
            COUNT(si.id) AS total_items_quantity
        FROM stores s
        LEFT JOIN contact_persons cp ON s.contact_person_id = cp.id
        LEFT JOIN city c ON s.city_id = c.id
        LEFT JOIN region r ON c.region_id = r.id
        LEFT JOIN country co ON r.country_id = co.id
        LEFT JOIN store_items si ON si.store_id = s.id
        WHERE s.supplier_id = ?
        GROUP BY
        s.id, s.name, s.physical_address,
        cp.id, cp.name, cp.email, cp.cell,
        c.id, c.name,
        r.id, r.name,
        co.id, co.name
        ORDER BY s.id
    ");
    $stmt->execute([$supplierId]);
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($stores)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No stores found for this supplier.',
            'data' => [],
            'count' => 0
        ]);
        exit;
    }

    // Fetch operating hours for the stores
    $storeIds = array_column($stores, 'store_id');
    $placeholders = implode(',', array_fill(0, count($storeIds), '?'));

    $stmt = $pdo->prepare("
        SELECT store_id, day_of_week, open_time, close_time, is_closed
        FROM store_operating_hours
        WHERE store_id IN ($placeholders)
        ORDER BY store_id, day_of_week
    ");
    $stmt->execute($storeIds);
    $hours = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hoursByStore = [];
    foreach ($hours as $row) {
        $storeId = $row['store_id'];
        unset($row['store_id']);
        if (!isset($hoursByStore[$storeId])) {
            $hoursByStore[$storeId] = [];
        }
        $hoursByStore[$storeId][] = $row;
    }

    // Attach operating hours to each store
    foreach ($stores as &$store) {
        $store_id = $store['store_id'];
        $store['operating_hours'] = $hoursByStore[$store_id] ?? [];
    }
    unset($store); // break reference

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Stores fetched successfully.',
        'data' => $stores,
        'count' => count($stores)
    ]);

} catch (PDOException $e) {
    logException('supplier_stores_get_all', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching stores: ' . $e->getMessage()
    ]);
}
?>
