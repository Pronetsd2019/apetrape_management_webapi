<?php
/**
 * Get All Contact Persons for Supplier Endpoint
 * GET /supplier/contact_person/get_all.php
 */

require_once __DIR__ . '/../../control/util/connect.php';
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
    // Get all contact persons for this supplier
    $stmt = $pdo->prepare("
        SELECT
            id, supplier_id, name, surname, email, cell, created_at, updated_at
        FROM contact_persons
        WHERE supplier_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$supplierId]);
    $contactPersons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Contact persons retrieved successfully.',
        'data' => $contactPersons,
        'count' => count($contactPersons)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving contact persons: ' . $e->getMessage()
    ]);
}
?>
