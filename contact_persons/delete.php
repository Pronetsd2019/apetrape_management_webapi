<?php
/**
 * Delete Contact Person Endpoint
 * DELETE /contact_persons/delete.php
 */

require_once __DIR__ . '/../util/connect.php';
header('Content-Type: application/json');

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Get contact person ID from query string or JSON body
$contact_person_id = null;
if (isset($_GET['id'])) {
    $contact_person_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $contact_person_id = $input['id'] ?? null;
}

if (!$contact_person_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Contact person ID is required.']);
    exit;
}

try {
    // Check if contact person exists
    $stmt = $pdo->prepare("SELECT id, name, surname, email, supplier_id FROM contact_persons WHERE id = ?");
    $stmt->execute([$contact_person_id]);
    $contact_person = $stmt->fetch();

    if (!$contact_person) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Contact person not found.']);
        exit;
    }

    // Check if contact person is being used by any stores
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM stores WHERE contact_person_id = ?");
    $stmt->execute([$contact_person_id]);
    $result = $stmt->fetch();
    if ($result['count'] > 0) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Cannot delete contact person. It is being used by ' . $result['count'] . ' store(s). Remove the contact person from stores first.'
        ]);
        exit;
    }

    // Delete contact person
    $stmt = $pdo->prepare("DELETE FROM contact_persons WHERE id = ?");
    $stmt->execute([$contact_person_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Contact person deleted successfully.',
        'data' => $contact_person
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting contact person: ' . $e->getMessage()
    ]);
}
?>

