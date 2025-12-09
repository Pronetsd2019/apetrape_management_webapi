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

// Validate required fields - support both single ID and array of IDs
// Also support both 'application_id' and 'application_ids' field names
$applicationIds = [];

// Check for application_ids (plural) first, then application_id (singular)
if (isset($input['application_ids'])) {
    if (is_array($input['application_ids'])) {
        // Array of IDs
        foreach ($input['application_ids'] as $id) {
            if (is_numeric($id)) {
                $applicationIds[] = (int)$id;
            }
        }
    } elseif (is_numeric($input['application_ids'])) {
        // Single ID in plural field
        $applicationIds[] = (int)$input['application_ids'];
    }
} elseif (isset($input['application_id'])) {
    // Single ID
    if (is_numeric($input['application_id'])) {
        $applicationIds[] = (int)$input['application_id'];
    } elseif (is_array($input['application_id'])) {
        // Array of IDs
        foreach ($input['application_id'] as $id) {
            if (is_numeric($id)) {
                $applicationIds[] = (int)$id;
            }
        }
    }
}

if (empty($applicationIds)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Valid application_id(s) or application_ids are required. Provide either a single ID or an array of IDs.'
    ]);
    exit;
}

$reason = isset($input['reason']) ? trim($input['reason']) : null;

try {
    $results = [
        'successful' => [],
        'failed' => []
    ];

    foreach ($applicationIds as $applicationId) {
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
                $results['failed'][] = [
                    'application_id' => $applicationId,
                    'reason' => 'Supplier application not found.'
                ];
                continue;
            }

            if ($application['status'] != 1) {
                $results['failed'][] = [
                    'application_id' => $applicationId,
                    'reason' => 'Only pending applications can be rejected.',
                    'current_status' => $application['status']
                ];
                continue;
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

                $results['successful'][] = [
                    'application_id' => $applicationId,
                    'application' => $updatedApplication,
                    'rejected_at' => date('Y-m-d H:i:s'),
                    'rejected_by' => $userId
                ];
            } else {
                $results['failed'][] = [
                    'application_id' => $applicationId,
                    'reason' => 'Failed to update application status.'
                ];
            }

        } catch (PDOException $e) {
            $results['failed'][] = [
                'application_id' => $applicationId,
                'reason' => 'Database error: ' . $e->getMessage()
            ];
        }
    }

    $totalProcessed = count($applicationIds);
    $totalSuccessful = count($results['successful']);
    $totalFailed = count($results['failed']);

    if ($totalSuccessful > 0 && $totalFailed == 0) {
        // All successful
        http_response_code(200);
        $message = $totalSuccessful === 1
            ? 'Supplier application rejected successfully.'
            : "{$totalSuccessful} supplier applications rejected successfully.";
    } elseif ($totalSuccessful > 0 && $totalFailed > 0) {
        // Partial success
        http_response_code(207); // Multi-Status
        $message = "{$totalSuccessful} applications rejected, {$totalFailed} failed.";
    } elseif ($totalSuccessful == 0 && $totalFailed > 0) {
        // All failed
        http_response_code(400);
        $message = "Failed to reject supplier applications.";
    } else {
        // Should not happen
        http_response_code(500);
        $message = "Unexpected error occurred.";
    }

    echo json_encode([
        'success' => $totalSuccessful > 0,
        'message' => $message,
        'data' => $results,
        'summary' => [
            'total_processed' => $totalProcessed,
            'successful' => $totalSuccessful,
            'failed' => $totalFailed
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error processing supplier applications: ' . $e->getMessage()
    ]);
}
?>
