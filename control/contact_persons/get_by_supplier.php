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
 * Get Contact Persons by Supplier Endpoint
 * GET /contact_persons/get_by_supplier.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
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
    $stmt = $pdo->prepare("SELECT 
    cp.id,
    cp.supplier_id,
    cp.name,
    cp.surname,
    cp.email,
    cp.cell,
    cp.created_at,
    cp.updated_at,
    COUNT(s.id) AS store_count
FROM contact_persons cp
LEFT JOIN stores s ON cp.id = s.contact_person_id
WHERE cp.supplier_id = ?
GROUP BY 
    cp.id,
    cp.supplier_id,
    cp.name,
    cp.surname,
    cp.email,
    cp.cell,
    cp.created_at,
    cp.updated_at
ORDER BY cp.name ASC, cp.surname ASC;
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
    logException('contact_persons_get_by_supplier', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching contact persons: ' . $e->getMessage()
    ]);
}
?>


