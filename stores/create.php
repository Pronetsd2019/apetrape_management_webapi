<?php
/**
 * Create Store Endpoint
 * POST /stores/create.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['supplier_id', 'name', 'physical_address', 'city_id'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // Validate supplier_id exists
    $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
    $stmt->execute([$input['supplier_id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        $pdo->rollBack();
        exit;
    }

    // Validate city_id exists
    $stmt = $pdo->prepare("SELECT id FROM city WHERE id = ?");
    $stmt->execute([$input['city_id']]);

    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'City not found.']);
        $pdo->rollBack();
        exit;
    }

    $create_new_contact = isset($input['create_new_contact']) ? filter_var($input['create_new_contact'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : false;

    // Validate contact_person_id if provided
    $contact_person_id = null;
    if ($create_new_contact) {
        $new_contact = $input['new_contact_person'] ?? null;
        if (!$new_contact || !is_array($new_contact)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'New contact person details are required.']);
            $pdo->rollBack();
            exit;
        }

        $contact_required_fields = ['name', 'surname'];
        foreach ($contact_required_fields as $field) {
            if (!isset($new_contact[$field]) || empty($new_contact[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Contact person field '$field' is required."]);
                $pdo->rollBack();
                exit;
            }
        }

        // Check email uniqueness if provided
        if (!empty($new_contact['email'])) {
            $stmt = $pdo->prepare("SELECT id FROM contact_persons WHERE email = ? AND supplier_id = ?");
            $stmt->execute([$new_contact['email'], $input['supplier_id']]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Contact person email already exists for this supplier.']);
                $pdo->rollBack();
                exit;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO contact_persons (supplier_id, name, surname, email, cell)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $input['supplier_id'],
            $new_contact['name'],
            $new_contact['surname'],
            $new_contact['email'] ?? null,
            $new_contact['cell'] ?? null
        ]);

        $contact_person_id = $pdo->lastInsertId();

    } elseif (isset($input['contact_person_id']) && !empty($input['contact_person_id'])) {
        $stmt = $pdo->prepare("SELECT id, supplier_id FROM contact_persons WHERE id = ?");
        $stmt->execute([$input['contact_person_id']]);
        $contact_person = $stmt->fetch();
        
        if (!$contact_person) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Contact person not found.']);
            $pdo->rollBack();
            exit;
        }
        
        // Verify contact person belongs to the same supplier
        if ($contact_person['supplier_id'] != $input['supplier_id']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Contact person does not belong to this supplier.']);
            $pdo->rollBack();
            exit;
        }
        
        $contact_person_id = $input['contact_person_id'];
    }

    // Insert store
    $stmt = $pdo->prepare("
        INSERT INTO stores (supplier_id, name, physical_address, coordinates, contact_person_id, city_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $coordinates = $input['coordinates'] ?? null;

    $stmt->execute([
        $input['supplier_id'],
        $input['name'],
        $input['physical_address'],
        $coordinates,
        $contact_person_id,
        $input['city_id']
    ]);

    $store_id = $pdo->lastInsertId();

    // Add operating hours if provided
    if (isset($input['operating_hours']) && is_array($input['operating_hours'])) {
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

    // Fetch created store with contact person details
    $stmt = $pdo->prepare("
        SELECT s.id, s.supplier_id, s.name, s.physical_address, s.coordinates, s.contact_person_id,
               s.city_id, s.created_at, s.updated_at,
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

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Store created successfully.',
        'data' => $store
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating store: ' . $e->getMessage()
    ]);
}
?>

