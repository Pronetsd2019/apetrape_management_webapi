<?php
/**
 * Delete Admin Endpoint
 * DELETE /admin/delete.php
 */

require_once __DIR__ . '/../util/connect.php';
header('Content-Type: application/json');

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Get admin ID from query string or JSON body
$admin_id = null;
if (isset($_GET['id'])) {
    $admin_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $admin_id = $input['id'] ?? null;
}

if (!$admin_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Admin ID is required.']);
    exit;
}

try {
    // Check if admin exists
    $stmt = $pdo->prepare("SELECT id, name, surname, email FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();

    if (!$admin) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Admin not found.']);
        exit;
    }

    // Delete admin
    $stmt = $pdo->prepare("DELETE FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Admin deleted successfully.',
        'data' => $admin
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting admin: ' . $e->getMessage()
    ]);
}
?>

