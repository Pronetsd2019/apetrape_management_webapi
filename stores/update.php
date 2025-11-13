<?php
/**
 * Update Store Endpoint
 * PUT /stores/update.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

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
    $pdo->beginTransaction();

    // Check if store exists
    $stmt = $pdo->prepare("SELECT id FROM stores WHERE id = ?");
    $stmt->execute([$store_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Store not found.']);
        $pdo->rollBack();
        exit;
    }

    // Get current supplier_id for validation
    $stmt = $pdo->prepare("SELECT supplier_id, contact_person_id FROM stores WHERE id = ?");
    $stmt->execute([$store_id]);
    $current_store = $stmt->fetch();
    $current_supplier_id = $current_store['supplier_id'];
    $current_contact_person_id = $current_store['contact_person_id'];

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
            $pdo->rollBack();
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
    if (isset($input['name'])) {
        if ($input['name'] === null || $input['name'] === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Store name cannot be empty.']);
            $pdo->rollBack();
            exit;
        }
        $update_fields[] = "name = ?";
        $params[] = $input['name'];
    }
    if (isset($input['coordinates'])) {
        $update_fields[] = "coordinates = ?";
        $params[] = $input['coordinates'];
    }
    if (isset($input['city_id'])) {
        if ($input['city_id'] === null || $input['city_id'] === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'City ID cannot be empty.']);
            $pdo->rollBack();
            exit;
        }

        // Validate city exists
        $stmt = $pdo->prepare("SELECT id FROM city WHERE id = ?");
        $stmt->execute([$input['city_id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'City not found.']);
            $pdo->rollBack();
            exit;
        }

        $update_fields[] = "city_id = ?";
        $params[] = $input['city_id'];
    }

    $create_new_contact = isset($input['create_new_contact']) ? filter_var($input['create_new_contact'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : false;
    $contact_person_changed = isset($input['contact_person_changed']) ? filter_var($input['contact_person_changed'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : false;
    $contact_person_changed_and_edited = isset($input['contact_person_changed_and_edited']) ? filter_var($input['contact_person_changed_and_edited'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : false;
    $contact_person_details_edited = isset($input['contact_person_details_edited']) ? filter_var($input['contact_person_details_edited'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : false;

    $create_new_contact = $create_new_contact === null ? false : $create_new_contact;
    $contact_person_changed = $contact_person_changed === null ? false : $contact_person_changed;
    $contact_person_changed_and_edited = $contact_person_changed_and_edited === null ? false : $contact_person_changed_and_edited;
    $contact_person_details_edited = $contact_person_details_edited === null ? false : $contact_person_details_edited;

    $contact_person_id_input = $input['contact_person_id'] ?? null;
    $updated_contact_person = $input['updated_contact_person'] ?? null;
    $new_contact_person = $input['new_contact_person'] ?? null;

    $contact_person_id_to_set = null;
    $should_update_contact_field = false;

    if ($create_new_contact) {
        if (!is_array($new_contact_person)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'New contact person details are required.']);
            $pdo->rollBack();
            exit;
        }

        $contact_required_fields = ['name', 'surname'];
        foreach ($contact_required_fields as $field) {
            if (!isset($new_contact_person[$field]) || empty($new_contact_person[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Contact person field '$field' is required."]);
                $pdo->rollBack();
                exit;
            }
        }

        if (!empty($new_contact_person['email'])) {
            $stmt = $pdo->prepare("SELECT id FROM contact_persons WHERE email = ? AND supplier_id = ?");
            $stmt->execute([$new_contact_person['email'], $current_supplier_id]);
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
            $current_supplier_id,
            $new_contact_person['name'],
            $new_contact_person['surname'],
            $new_contact_person['email'] ?? null,
            $new_contact_person['cell'] ?? null
        ]);

        $contact_person_id_to_set = $pdo->lastInsertId();
        $should_update_contact_field = true;

    } else {
        if ($contact_person_changed) {
            if ($contact_person_id_input === null || $contact_person_id_input === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Contact person ID is required when changing contact person.']);
                $pdo->rollBack();
                exit;
            }

            $stmt = $pdo->prepare("SELECT id, supplier_id, email FROM contact_persons WHERE id = ?");
            $stmt->execute([$contact_person_id_input]);
            $contact_person = $stmt->fetch();

            if (!$contact_person) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Contact person not found.']);
                $pdo->rollBack();
                exit;
            }

            if ($contact_person['supplier_id'] != $current_supplier_id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Contact person does not belong to this supplier.']);
                $pdo->rollBack();
                exit;
            }

            if ($contact_person_changed_and_edited) {
                if (!is_array($updated_contact_person)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Updated contact person details are required.']);
                    $pdo->rollBack();
                    exit;
                }

                $contact_required_fields = ['name', 'surname'];
                foreach ($contact_required_fields as $field) {
                    if (!isset($updated_contact_person[$field]) || empty($updated_contact_person[$field])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => "Updated contact person field '$field' is required."]);
                        $pdo->rollBack();
                        exit;
                    }
                }

                if (isset($updated_contact_person['email']) && $updated_contact_person['email'] !== $contact_person['email']) {
                    if (!empty($updated_contact_person['email'])) {
                        $stmt = $pdo->prepare("SELECT id FROM contact_persons WHERE email = ? AND supplier_id = ? AND id != ?");
                        $stmt->execute([$updated_contact_person['email'], $current_supplier_id, $contact_person_id_input]);
                        if ($stmt->fetch()) {
                            http_response_code(409);
                            echo json_encode(['success' => false, 'message' => 'Contact person email already exists for this supplier.']);
                            $pdo->rollBack();
                            exit;
                        }
                    }
                }

                $contact_update_fields = [];
                $contact_params = [];

                $contact_update_fields[] = "name = ?";
                $contact_params[] = $updated_contact_person['name'];

                $contact_update_fields[] = "surname = ?";
                $contact_params[] = $updated_contact_person['surname'];

                if (array_key_exists('email', $updated_contact_person)) {
                    $contact_update_fields[] = "email = ?";
                    $contact_params[] = $updated_contact_person['email'] ?? null;
                }

                if (array_key_exists('cell', $updated_contact_person)) {
                    $contact_update_fields[] = "cell = ?";
                    $contact_params[] = $updated_contact_person['cell'] ?? null;
                }

                $contact_update_fields[] = "updated_at = NOW()";

                $contact_params[] = $contact_person_id_input;

                $stmt = $pdo->prepare("UPDATE contact_persons SET " . implode(', ', $contact_update_fields) . " WHERE id = ?");
                $stmt->execute($contact_params);
            }

            $contact_person_id_to_set = $contact_person_id_input;
            $should_update_contact_field = true;

        } else {
            if (array_key_exists('contact_person_id', $input)) {
                if ($contact_person_id_input === null || $contact_person_id_input === '') {
                    $contact_person_id_to_set = null;
                    $should_update_contact_field = true;
                } else {
                    $stmt = $pdo->prepare("SELECT id, supplier_id, email FROM contact_persons WHERE id = ?");
                    $stmt->execute([$contact_person_id_input]);
                    $contact_person = $stmt->fetch();

                    if (!$contact_person) {
                        http_response_code(404);
                        echo json_encode(['success' => false, 'message' => 'Contact person not found.']);
                        $pdo->rollBack();
                        exit;
                    }

                    if ($contact_person['supplier_id'] != $current_supplier_id) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Contact person does not belong to this supplier.']);
                        $pdo->rollBack();
                        exit;
                    }

                    if ($contact_person_details_edited) {
                        if (!is_array($updated_contact_person)) {
                            http_response_code(400);
                            echo json_encode(['success' => false, 'message' => 'Updated contact person details are required.']);
                            $pdo->rollBack();
                            exit;
                        }

                        $contact_required_fields = ['name', 'surname'];
                        foreach ($contact_required_fields as $field) {
                            if (!isset($updated_contact_person[$field]) || empty($updated_contact_person[$field])) {
                                http_response_code(400);
                                echo json_encode(['success' => false, 'message' => "Updated contact person field '$field' is required."]);
                                $pdo->rollBack();
                                exit;
                            }
                        }

                        if (isset($updated_contact_person['email']) && $updated_contact_person['email'] !== $contact_person['email']) {
                            if (!empty($updated_contact_person['email'])) {
                                $stmt = $pdo->prepare("SELECT id FROM contact_persons WHERE email = ? AND supplier_id = ? AND id != ?");
                                $stmt->execute([$updated_contact_person['email'], $current_supplier_id, $contact_person_id_input]);
                                if ($stmt->fetch()) {
                                    http_response_code(409);
                                    echo json_encode(['success' => false, 'message' => 'Contact person email already exists for this supplier.']);
                                    $pdo->rollBack();
                                    exit;
                                }
                            }
                        }

                        $contact_update_fields = [];
                        $contact_params = [];

                        $contact_update_fields[] = "name = ?";
                        $contact_params[] = $updated_contact_person['name'];

                        $contact_update_fields[] = "surname = ?";
                        $contact_params[] = $updated_contact_person['surname'];

                        if (array_key_exists('email', $updated_contact_person)) {
                            $contact_update_fields[] = "email = ?";
                            $contact_params[] = $updated_contact_person['email'] ?? null;
                        }

                        if (array_key_exists('cell', $updated_contact_person)) {
                            $contact_update_fields[] = "cell = ?";
                            $contact_params[] = $updated_contact_person['cell'] ?? null;
                        }

                        $contact_update_fields[] = "updated_at = NOW()";

                        $contact_params[] = $contact_person_id_input;

                        $stmt = $pdo->prepare("UPDATE contact_persons SET " . implode(', ', $contact_update_fields) . " WHERE id = ?");
                        $stmt->execute($contact_params);
                    }

                    $contact_person_id_to_set = $contact_person_id_input;
                    if ($contact_person_id_input != $current_contact_person_id) {
                        $should_update_contact_field = true;
                    }
                }
            } elseif ($contact_person_details_edited) {
                if (!$current_contact_person_id) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'No contact person to edit.']);
                    $pdo->rollBack();
                    exit;
                }

                if (!is_array($updated_contact_person)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Updated contact person details are required.']);
                    $pdo->rollBack();
                    exit;
                }

                $contact_required_fields = ['name', 'surname'];
                foreach ($contact_required_fields as $field) {
                    if (!isset($updated_contact_person[$field]) || empty($updated_contact_person[$field])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => "Updated contact person field '$field' is required."]);
                        $pdo->rollBack();
                        exit;
                    }
                }

                $stmt = $pdo->prepare("SELECT id, supplier_id, email FROM contact_persons WHERE id = ?");
                $stmt->execute([$current_contact_person_id]);
                $contact_person = $stmt->fetch();

                if (!$contact_person || $contact_person['supplier_id'] != $current_supplier_id) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Contact person not found.']);
                    $pdo->rollBack();
                    exit;
                }

                if (isset($updated_contact_person['email']) && $updated_contact_person['email'] !== $contact_person['email']) {
                    if (!empty($updated_contact_person['email'])) {
                        $stmt = $pdo->prepare("SELECT id FROM contact_persons WHERE email = ? AND supplier_id = ? AND id != ?");
                        $stmt->execute([$updated_contact_person['email'], $current_supplier_id, $current_contact_person_id]);
                        if ($stmt->fetch()) {
                            http_response_code(409);
                            echo json_encode(['success' => false, 'message' => 'Contact person email already exists for this supplier.']);
                            $pdo->rollBack();
                            exit;
                        }
                    }
                }

                $contact_update_fields = [];
                $contact_params = [];

                $contact_update_fields[] = "name = ?";
                $contact_params[] = $updated_contact_person['name'];

                $contact_update_fields[] = "surname = ?";
                $contact_params[] = $updated_contact_person['surname'];

                if (array_key_exists('email', $updated_contact_person)) {
                    $contact_update_fields[] = "email = ?";
                    $contact_params[] = $updated_contact_person['email'] ?? null;
                }

                if (array_key_exists('cell', $updated_contact_person)) {
                    $contact_update_fields[] = "cell = ?";
                    $contact_params[] = $updated_contact_person['cell'] ?? null;
                }

                $contact_update_fields[] = "updated_at = NOW()";

                $contact_params[] = $current_contact_person_id;

                $stmt = $pdo->prepare("UPDATE contact_persons SET " . implode(', ', $contact_update_fields) . " WHERE id = ?");
                $stmt->execute($contact_params);
            }
        }
    }

    if ($should_update_contact_field) {
        if ($contact_person_id_to_set === null) {
            $update_fields[] = "contact_person_id = NULL";
        } else {
            $update_fields[] = "contact_person_id = ?";
            $params[] = $contact_person_id_to_set;
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

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Store updated successfully.',
        'data' => $store
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating store: ' . $e->getMessage()
    ]);
}
?>

