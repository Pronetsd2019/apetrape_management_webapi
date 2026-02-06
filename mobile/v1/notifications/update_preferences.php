<?php

// CORS headers for subdomain support and localhost
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';
$isLocalhostOrigin = isset($_SERVER['HTTP_ORIGIN']) && (
    strpos($_SERVER['HTTP_ORIGIN'], 'http://localhost') === 0 ||
    strpos($_SERVER['HTTP_ORIGIN'], 'http://127.0.0.1') === 0
);

if ((isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) || $isLocalhostOrigin) {
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
 * Mobile User Update Notification Preferences Endpoint
 * PUT /mobile/v1/notifications/update_preferences.php
 * Requires JWT authentication - updates notification preferences for the authenticated user
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

// Ensure the request is authenticated
requireMobileJwtAuth();

header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get the authenticated user's ID from the JWT payload
$authUser = $GLOBALS['auth_user'] ?? null;
$user_id = (int)($authUser['user_id'] ?? null);

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Unable to identify authenticated user.'
    ]);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$input = $input ?? [];

// Define allowed preference fields with defaults
$allowed_fields = [
    'push_notifications' => 1,
    'email_notifications' => 0,
    'sms_notifications' => 0,
    'promotions' => 1,
    'security' => 1,
    'general' => 1
];

// Extract and validate preference values
$updates = [];
$values = [];
$set_clauses = [];

foreach ($allowed_fields as $field => $default) {
    if (isset($input[$field])) {
        // Convert boolean/string to integer (0 or 1)
        $value = $input[$field];
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        } elseif (is_string($value)) {
            $value = (strtolower($value) === 'true' || $value === '1') ? 1 : 0;
        } elseif (is_numeric($value)) {
            $value = (int)$value;
            $value = ($value == 1) ? 1 : 0;
        } else {
            $value = $default;
        }
        
        $updates[$field] = $value;
        $set_clauses[] = "{$field} = ?";
        $values[] = $value;
    }
}

// Optional FCM params: when provided and non-empty, add/update or remove device for push
$fcm_token_raw = isset($input['fcm_token']) ? trim((string)$input['fcm_token']) : '';
$fcm_token = $fcm_token_raw !== '' ? $fcm_token_raw : null;
$device_id = (isset($input['device_id']) && trim((string)$input['device_id']) !== '')
    ? trim((string)$input['device_id'])
    : null;

$platform = null;
if (isset($input['platform']) && trim((string)$input['platform']) !== '') {
    $platformInput = strtolower(trim((string)$input['platform']));
    $allowedPlatforms = ['android', 'ios', 'web'];
    if (!in_array($platformInput, $allowedPlatforms)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'Invalid platform. Must be one of: android, ios, web.'
        ]);
        exit;
    }
    $platform = $platformInput;
}

$fcm_add_update = $fcm_token !== null;
$fcm_remove = !$fcm_add_update && $device_id !== null;

if (empty($updates) && !$fcm_add_update && !$fcm_remove) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'At least one preference field or fcm_token/device_id is required for update.'
    ]);
    exit;
}

try {
    // Verify user exists
    $stmt = $pdo->prepare("SELECT id, status FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Not found',
            'message' => 'User not found.'
        ]);
        exit;
    }

    if ($user['status'] != 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Forbidden',
            'message' => 'Your account is not active. Please contact support.'
        ]);
        exit;
    }

    $pdo->beginTransaction();

    $result = true;
    if (!empty($updates)) {
        // Check if preferences exist
        $stmt = $pdo->prepare("SELECT id FROM user_notification_preferences WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        // Add updated_at to values
        $values[] = date('Y-m-d H:i:s');
        $values[] = $user_id;

        if ($existing) {
            // Update existing preferences
            $sql = "UPDATE user_notification_preferences SET " . implode(', ', $set_clauses) . ", updated_at = ? WHERE user_id = ?";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($values);
        } else {
            // Insert new preferences with defaults for fields not provided
            $insert_fields = array_keys($updates);
            $insert_fields[] = 'user_id';
            $insert_fields[] = 'updated_at';

            $insert_values = array_values($updates);
            $insert_values[] = $user_id;
            $insert_values[] = date('Y-m-d H:i:s');

            // Add defaults for fields not provided
            foreach ($allowed_fields as $field => $default) {
                if (!isset($updates[$field])) {
                    $insert_fields[] = $field;
                    $insert_values[] = $default;
                }
            }

            $placeholders = implode(', ', array_fill(0, count($insert_fields), '?'));
            $sql = "INSERT INTO user_notification_preferences (" . implode(', ', $insert_fields) . ") VALUES ({$placeholders})";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($insert_values);
        }

        if (!$result) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Server error',
                'message' => 'Failed to update notification preferences. Please try again later.',
                'error_details' => 'Failed to update preferences.'
            ]);
            exit;
        }
    }

    if ($fcm_add_update) {
        if ($device_id === null) {
            $checkStmt = $pdo->prepare("
                SELECT id, fcm_token, platform
                FROM user_fcm_tokens
                WHERE user_id = ? AND device_id IS NULL
                LIMIT 1
            ");
            $checkStmt->execute([$user_id]);
        } else {
            $checkStmt = $pdo->prepare("
                SELECT id, fcm_token, platform
                FROM user_fcm_tokens
                WHERE user_id = ? AND device_id = ?
                LIMIT 1
            ");
            $checkStmt->execute([$user_id, $device_id]);
        }
        $existingRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingRow) {
            $updateStmt = $pdo->prepare("
                UPDATE user_fcm_tokens
                SET fcm_token = ?, platform = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$fcm_token, $platform, $existingRow['id']]);
        } else {
            $tokenCheckStmt = $pdo->prepare("
                SELECT id FROM user_fcm_tokens WHERE fcm_token = ? LIMIT 1
            ");
            $tokenCheckStmt->execute([$fcm_token]);
            $existingToken = $tokenCheckStmt->fetch(PDO::FETCH_ASSOC);
            if ($existingToken) {
                $deleteStmt = $pdo->prepare("DELETE FROM user_fcm_tokens WHERE id = ?");
                $deleteStmt->execute([$existingToken['id']]);
            }
            $insertStmt = $pdo->prepare("
                INSERT INTO user_fcm_tokens (user_id, fcm_token, device_id, platform, updated_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $insertStmt->execute([$user_id, $fcm_token, $device_id, $platform]);
        }
    } elseif ($fcm_remove) {
        if ($device_id === null) {
            $delStmt = $pdo->prepare("DELETE FROM user_fcm_tokens WHERE user_id = ? AND device_id IS NULL");
            $delStmt->execute([$user_id]);
        } else {
            $delStmt = $pdo->prepare("DELETE FROM user_fcm_tokens WHERE user_id = ? AND device_id = ?");
            $delStmt->execute([$user_id, $device_id]);
        }
    }

    $pdo->commit();

    // Fetch updated preferences
    $stmt = $pdo->prepare("
        SELECT 
            push_notifications,
            email_notifications,
            sms_notifications,
            promotions,
            security,
            general,
            updated_at
        FROM user_notification_preferences
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$preferences) {
        $preferences = [
            'push_notifications' => 1,
            'email_notifications' => 0,
            'sms_notifications' => 0,
            'promotions' => 1,
            'security' => 1,
            'general' => 1
        ];
    }

    // Log the update
    logError('mobile_notifications_update_preferences', 'User notification preferences updated', [
        'user_id' => $user_id,
        'updated_fields' => array_keys($updates),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Notification preferences updated successfully.',
        'data' => [
            'push_notifications' => (bool)$preferences['push_notifications'],
            'email_notifications' => (bool)$preferences['email_notifications'],
            'sms_notifications' => (bool)$preferences['sms_notifications'],
            'promotions' => (bool)$preferences['promotions'],
            'security' => (bool)$preferences['security'],
            'general' => (bool)$preferences['general']
        ]
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('mobile_notifications_update_preferences', $e);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while updating your notification preferences. Please try again later.',
        'error_details' => 'Error updating notification preferences: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('mobile_notifications_update_preferences', $e);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while updating your notification preferences. Please try again later.',
        'error_details' => 'Error updating notification preferences: ' . $e->getMessage()
    ]);
}
?>

