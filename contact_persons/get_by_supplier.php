<?php
/**
 * Get Contact Persons by Supplier Endpoint
 * GET /contact_persons/get_by_supplier.php
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

    // Fetch contact persons for the supplier
    $stmt = $pdo->prepare("
        SELECT id, supplier_id, name, surname, email, cell, created_at, updated_at
        FROM contact_persons
        WHERE supplier_id = ?
        ORDER BY name ASC, surname ASC
    ");
    $stmt->execute([$supplier_id]);
    $contact_persons = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Contact persons fetched successfully.',
        'data' => $contact_persons
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching contact persons: ' . $e->getMessage()
    ]);
}
?>


