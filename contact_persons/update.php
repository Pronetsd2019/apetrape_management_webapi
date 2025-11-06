<?php
/**
 * Update Contact Person Endpoint
 * PUT /contact_persons/update.php
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

// Validate contact_person_id
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Contact person ID is required.']);
    exit;
}

$contact_person_id = $input['id'];

try {
    // Check if contact person exists
    $stmt = $pdo->prepare("SELECT id, supplier_id FROM contact_persons WHERE id = ?");
    $stmt->execute([$contact_person_id]);
    $existing = $stmt->fetch();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Contact person not found.']);
        exit;
    }

    $supplier_id = $existing['supplier_id'];

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
        $supplier_id = $input['supplier_id']; // Update for email check
    }
    if (isset($input['name'])) {
        $update_fields[] = "name = ?";
        $params[] = $input['name'];
    }
    if (isset($input['surname'])) {
        $update_fields[] = "surname = ?";
        $params[] = $input['surname'];
    }
    if (isset($input['email'])) {
        // Check if email already exists for another contact person in the same supplier
        $stmt = $pdo->prepare("SELECT id FROM contact_persons WHERE email = ? AND supplier_id = ? AND id != ?");
        $stmt->execute([$input['email'], $supplier_id, $contact_person_id]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Email already exists for another contact person in this supplier.']);
            exit;
        }
        $update_fields[] = "email = ?";
        $params[] = $input['email'];
    }
    if (isset($input['cell'])) {
        $update_fields[] = "cell = ?";
        $params[] = $input['cell'];
    }

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update.']);
        exit;
    }

    // Add contact_person_id to params
    $params[] = $contact_person_id;

    // Execute update
    $sql = "UPDATE contact_persons SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated contact person
    $stmt = $pdo->prepare("
        SELECT id, supplier_id, name, surname, email, cell, created_at, updated_at
        FROM contact_persons WHERE id = ?
    ");
    $stmt->execute([$contact_person_id]);
    $contact_person = $stmt->fetch();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Contact person updated successfully.',
        'data' => $contact_person
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating contact person: ' . $e->getMessage()
    ]);
}
?>

