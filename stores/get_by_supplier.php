<?php
/**
 * Get Stores by Supplier Endpoint
 * GET /stores/get_by_suppplier.php
 */

require_once __DIR__ . '/../util/connect.php';
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

$supplier_id = $_GET['supplier_id'] ?? null;

if (!$supplier_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Supplier ID is required.']);
    exit;
}

try {
    // Validate supplier exists
    $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
    $stmt->execute([$supplier_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        exit;
    }

    // Fetch stores for supplier
    $stmt = $pdo->prepare("
        SELECT 
            s.id AS store_id,
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
            COALESCE(SUM(si.quantity), 0) AS total_stock_quantity
        FROM stores s
        LEFT JOIN contact_persons cp ON s.contact_person_id = cp.id
        LEFT JOIN city c ON s.city_id = c.id
        LEFT JOIN region r ON c.region_id = r.id
        LEFT JOIN country co ON r.country_id = co.id
        LEFT JOIN store_items si ON si.store_id = s.id
        WHERE s.supplier_id = ?
        GROUP BY 
        s.id, s.physical_address,
        cp.id, cp.name, cp.email, cp.cell,
        c.id, c.name,
        r.id, r.name,
        co.id, co.name
        ORDER BY s.id
    ");
    $stmt->execute([$supplier_id]);
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($stores)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No stores found for this supplier.',
            'data' => []
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
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching stores: ' . $e->getMessage()
    ]);
}
?>


