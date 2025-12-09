<?php
/**
 * Reject Supplier Application Endpoint
 * PUT /supplier_application/reject.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../util/check_permission.php';

// Ensure the request is authenticated
requireJwtAuth();

// Get the authenticated user's ID from the JWT payload
$authUser = $GLOBALS['auth_user'] ?? null;
$userId = $authUser['admin_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
    exit;
}

// Check if the user has permission to reject supplier applications
if (!checkUserPermission($userId, 'suppliers', 'update')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to reject supplier applications.']);
    exit;
}

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['application_id']) || !is_numeric($input['application_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Valid application_id is required.'
    ]);
    exit;
}

$applicationId = (int)$input['application_id'];
$reason = isset($input['reason']) ? trim($input['reason']) : null;

try {
    // Check if application exists and is pending
    $stmt = $pdo->prepare("
        SELECT id, name, email, status
        FROM supplier_application
        WHERE id = ?
    ");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$application) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Supplier application not found.'
        ]);
        exit;
    }

    if ($application['status'] != 1) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Only pending applications can be rejected.',
            'current_status' => $application['status']
        ]);
        exit;
    }

    // Update application status to rejected (2)
    $stmt = $pdo->prepare("
        UPDATE supplier_application
        SET status = 2, updated_at = NOW()
        WHERE id = ?
    ");
    $result = $stmt->execute([$applicationId]);

    if ($result && $stmt->rowCount() > 0) {
        // Fetch updated application
        $stmt = $pdo->prepare("
            SELECT id, name, email, cell, telephone, address, reg, status, created_at, updated_at
            FROM supplier_application
            WHERE id = ?
        ");
        $stmt->execute([$applicationId]);
        $updatedApplication = $stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Supplier application rejected successfully.',
            'data' => [
                'application' => $updatedApplication,
                'reason' => $reason,
                'rejected_at' => date('Y-m-d H:i:s'),
                'rejected_by' => $userId
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to reject supplier application.'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error rejecting supplier application: ' . $e->getMessage()
    ]);
}
?>
