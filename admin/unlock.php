<?php
/**
 * Unlock Admin Account Endpoint
 * POST /admin/unlock.php
 * Unlocks a locked admin account by resetting failed attempts and lockout time
 */

require_once __DIR__ . '/../util/connect.php';
header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate admin_id
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Admin ID is required.']);
    exit;
}

$admin_id = $input['id'];

try {
    // Check if admin exists
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, failed_attempts, locked_until 
        FROM admins 
        WHERE id = ?
    ");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();

    if (!$admin) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Admin not found.']);
        exit;
    }

    // Check if account is actually locked
    $is_locked = $admin['locked_until'] && strtotime($admin['locked_until']) > time();
    $has_failed_attempts = $admin['failed_attempts'] > 0;

    if (!$is_locked && !$has_failed_attempts) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Account is not locked.',
            'data' => [
                'id' => $admin['id'],
                'name' => $admin['name'],
                'surname' => $admin['surname'],
                'email' => $admin['email'],
                'failed_attempts' => 0,
                'locked_until' => null
            ]
        ]);
        exit;
    }

    // Unlock the account
    $stmt = $pdo->prepare("
        UPDATE admins 
        SET failed_attempts = 0, locked_until = NULL 
        WHERE id = ?
    ");
    $stmt->execute([$admin_id]);

    // Fetch updated admin
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, failed_attempts, locked_until 
        FROM admins 
        WHERE id = ?
    ");
    $stmt->execute([$admin_id]);
    $unlocked_admin = $stmt->fetch();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Account unlocked successfully.',
        'data' => [
            'id' => $unlocked_admin['id'],
            'name' => $unlocked_admin['name'],
            'surname' => $unlocked_admin['surname'],
            'email' => $unlocked_admin['email'],
            'failed_attempts' => 0,
            'locked_until' => null
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error unlocking account: ' . $e->getMessage()
    ]);
}
?>

