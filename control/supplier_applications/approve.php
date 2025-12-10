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
 * Approve Supplier Application Endpoint
 * PUT /suppliers/approve.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
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

// Check if the user has permission to approve suppliers
 if (!checkUserPermission($userId, 'suppliers', 'create')) {
    http_response_code(403);
    echo json_encode(['data'=> $userId, 'success' => false, 'message' => 'You do not have permission to create supplier.']);
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

try {
    $results = [
        'successful' => [],
        'failed' => []
    ];

    foreach ($applicationIds as $applicationId) {
        try {
            // Check if application exists and is pending (status = 1)
            $stmt = $pdo->prepare("
                SELECT id, name, email, cell, telephone, address, reg, password_hash, status, created_at, updated_at
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
                    'reason' => 'Application is not in pending status.',
                    'current_status' => $application['status']
                ];
                continue;
            }

            // Check if supplier with same email already exists
            $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE LOWER(email) = LOWER(?)");
            $stmt->execute([$application['email']]);
            if ($stmt->fetch()) {
                $results['failed'][] = [
                    'application_id' => $applicationId,
                    'reason' => 'A supplier with this email already exists.',
                    'email' => $application['email']
                ];
                continue;
            }

            // Insert approved supplier into suppliers table
            $stmt = $pdo->prepare("
                INSERT INTO suppliers (
                    name, email, cellphone, telephone, reg, password_hash,
                    status, locked_until, failed_attempts, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, 1, NULL, 0, ?, ?)
            ");

            $result = $stmt->execute([
                $application['name'],
                $application['email'],
                $application['cell'], // maps to cellphone
                $application['telephone'],
                $application['reg'],
                $application['password_hash'],
                $application['created_at'],
                $application['updated_at']
            ]);

            if (!$result) {
                $results['failed'][] = [
                    'application_id' => $applicationId,
                    'reason' => 'Failed to create supplier account.'
                ];
                continue;
            }

            $supplierId = $pdo->lastInsertId();

            // Update application status to approved (3)
            $stmt = $pdo->prepare("
                UPDATE supplier_application
                SET status = 3, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$applicationId]);

            // Fetch the created supplier (exclude sensitive data)
            $stmt = $pdo->prepare("
                SELECT id, name, email, cellphone, telephone, reg, status, created_at, updated_at
                FROM suppliers
                WHERE id = ?
            ");
            $stmt->execute([$supplierId]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

            $results['successful'][] = [
                'application_id' => $applicationId,
                'supplier' => $supplier,
                'approved_at' => date('Y-m-d H:i:s')
            ];

        } catch (PDOException $e) {
    logException('supplier_applications_approve', $e);
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
        http_response_code(201);
        $message = $totalSuccessful === 1
            ? 'Supplier application approved successfully. Supplier account created.'
            : "{$totalSuccessful} supplier applications approved successfully. Supplier accounts created.";
    } elseif ($totalSuccessful > 0 && $totalFailed > 0) {
        // Partial success
        http_response_code(207); // Multi-Status
        $message = "{$totalSuccessful} applications approved, {$totalFailed} failed.";
    } elseif ($totalSuccessful == 0 && $totalFailed > 0) {
        // All failed
        http_response_code(400);
        $message = "Failed to approve supplier applications.";
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
    logException('supplier_applications_approve', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error processing supplier applications: ' . $e->getMessage()
    ]);
}
?>
