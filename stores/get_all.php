<?php
/**
 * Get All Stores Endpoint
 * GET /stores/get_all.php
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

try {
    $stmt = $pdo->query("
        SELECT 
            s.id AS store_id,
            s.supplier_id,
            sup.name AS supplier_name,
            s.name AS store_name,
            s.physical_address,
            s.coordinates,
            s.contact_person_id,
            cp.name AS contact_person_name,
            cp.surname AS contact_person_surname,
            cp.email AS contact_person_email,
            cp.cell AS contact_person_cellphone,
            c.id AS city_id,
            c.name AS city_name,
            r.id AS region_id,
            r.name AS region_name,
            s.status,
            co.id AS country_id,
            co.name AS country_name,
            COUNT(DISTINCT si.item_id) AS total_items_quantity,
            s.created_at,
            s.updated_at
        FROM stores s
        LEFT JOIN contact_persons cp ON s.contact_person_id = cp.id
        LEFT JOIN suppliers sup ON s.supplier_id = sup.id
        LEFT JOIN city c ON s.city_id = c.id
        LEFT JOIN region r ON c.region_id = r.id
        LEFT JOIN country co ON r.country_id = co.id
        LEFT JOIN store_items si ON si.store_id = s.id
        GROUP BY 
        s.id, s.name, s.physical_address,
        sup.id, sup.name,
        cp.id, cp.name, cp.email, cp.cell,
        c.id, c.name,
        r.id, r.name,
        co.id, co.name
        ORDER BY s.id
    ");

    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($stores)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No stores found.',
            'data' => [],
            'count' => 0
        ]);
        exit;
    }

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

    foreach ($stores as &$store) {
        $store['operating_hours'] = $hoursByStore[$store['store_id']] ?? [];
    }
    unset($store);

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


