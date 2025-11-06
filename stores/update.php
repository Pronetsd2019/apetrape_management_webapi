<?php
/**
 * Update Store Endpoint
 * PUT /stores/update.php
 */

require_once __DIR__ . '/../util/connect.php';
header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate store_id
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Store ID is required.']);
    exit;
}

$store_id = $input['id'];

try {
    // Check if store exists
    $stmt = $pdo->prepare("SELECT id FROM stores WHERE id = ?");
    $stmt->execute([$store_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Store not found.']);
        exit;
    }

    // Get current supplier_id for validation
    $stmt = $pdo->prepare("SELECT supplier_id FROM stores WHERE id = ?");
    $stmt->execute([$store_id]);
    $current_store = $stmt->fetch();
    $current_supplier_id = $current_store['supplier_id'];

    // Build update query dynamically based on provided fields
    $update_fields = [];
    $params = [];

    if (isset($input['supplier_id'])) {
        // Validate supplier_id exists
        $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
        $stmt->execute([$input['supplier_id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
            exit;
        }
        $update_fields[] = "supplier_id = ?";
        $params[] = $input['supplier_id'];
        $current_supplier_id = $input['supplier_id']; // Update for contact person validation
    }
    if (isset($input['physical_address'])) {
        $update_fields[] = "physical_address = ?";
        $params[] = $input['physical_address'];
    }
    if (isset($input['coordinates'])) {
        $update_fields[] = "coordinates = ?";
        $params[] = $input['coordinates'];
    }
    if (isset($input['contact_person_id'])) {
        // Allow setting to null to remove contact person
        if ($input['contact_person_id'] === null || $input['contact_person_id'] === '') {
            $update_fields[] = "contact_person_id = NULL";
        } else {
            // Validate contact_person_id exists
            $stmt = $pdo->prepare("SELECT id, supplier_id FROM contact_persons WHERE id = ?");
            $stmt->execute([$input['contact_person_id']]);
            $contact_person = $stmt->fetch();
            
            if (!$contact_person) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Contact person not found.']);
                exit;
            }
            
            // Verify contact person belongs to the same supplier
            if ($contact_person['supplier_id'] != $current_supplier_id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Contact person does not belong to this supplier.']);
                exit;
            }
            
            $update_fields[] = "contact_person_id = ?";
            $params[] = $input['contact_person_id'];
        }
    }

    // Update store fields if any
    if (!empty($update_fields)) {
        $params[] = $store_id;
        $sql = "UPDATE stores SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    // Update operating hours if provided
    if (isset($input['operating_hours']) && is_array($input['operating_hours'])) {
        // Delete existing operating hours
        $stmt = $pdo->prepare("DELETE FROM store_operating_hours WHERE store_id = ?");
        $stmt->execute([$store_id]);

        // Insert new operating hours
        $stmt_hours = $pdo->prepare("
            INSERT INTO store_operating_hours (store_id, day_of_week, open_time, close_time, is_closed)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($input['operating_hours'] as $hours) {
            $day_of_week = $hours['day_of_week'] ?? null;
            $is_closed = isset($hours['is_closed']) ? (int)$hours['is_closed'] : 0;
            $open_time = ($is_closed || !isset($hours['open_time'])) ? null : $hours['open_time'];
            $close_time = ($is_closed || !isset($hours['close_time'])) ? null : $hours['close_time'];

            if ($day_of_week) {
                $stmt_hours->execute([
                    $store_id,
                    $day_of_week,
                    $open_time,
                    $close_time,
                    $is_closed
                ]);
            }
        }
    }

    // Fetch updated store with contact person details
    $stmt = $pdo->prepare("
        SELECT s.id, s.supplier_id, s.physical_address, s.coordinates, s.contact_person_id,
               s.created_at, s.updated_at,
               cp.name as contact_person_name, cp.surname as contact_person_surname,
               cp.email as contact_person_email, cp.cell as contact_person_cell
        FROM stores s
        LEFT JOIN contact_persons cp ON s.contact_person_id = cp.id
        WHERE s.id = ?
    ");
    $stmt->execute([$store_id]);
    $store = $stmt->fetch();

    // Fetch operating hours
    $stmt = $pdo->prepare("
        SELECT id, day_of_week, open_time, close_time, is_closed
        FROM store_operating_hours
        WHERE store_id = ?
        ORDER BY day_of_week
    ");
    $stmt->execute([$store_id]);
    $store['operating_hours'] = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Store updated successfully.',
        'data' => $store
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating store: ' . $e->getMessage()
    ]);
}
?>

