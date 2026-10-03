<?php
/**
 * Remove a saved Peach card token (called by Embedded Checkout onRemoveCard).
 * POST /mobile/v1/payment/remove_card.php
 * Body: { "checkoutId": "...", "registrationId": "..." }
 *
 * The embed page has no mobile JWT, so ownership is proven by an open checkout:
 * the token is only removed if it belongs to the user who owns that checkout's order.
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../../../control/util/peach_checkout.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON in request body.']);
    exit;
}

$checkoutId = trim((string)($input['checkoutId'] ?? ''));
$registrationId = trim((string)($input['registrationId'] ?? ''));

if (!preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $checkoutId) || !preg_match('/^[a-zA-Z0-9]{16,64}$/', $registrationId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'checkoutId and registrationId are required.']);
    exit;
}

try {
    ensurePeachCheckoutsTable($pdo);

    $stmt = $pdo->prepare("
        SELECT o.user_id
        FROM peach_checkouts pc
        INNER JOIN orders o ON o.id = pc.order_id
        WHERE pc.checkout_id = ?
          AND pc.status NOT IN ('successful', 'paid', 'cancelled', 'expired')
          AND pc.created_at >= (NOW() - INTERVAL 60 MINUTE)
        LIMIT 1
    ");
    $stmt->execute([$checkoutId]);
    $userId = (int)($stmt->fetch(PDO::FETCH_ASSOC)['user_id'] ?? 0);

    if ($userId <= 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Checkout is no longer active.']);
        exit;
    }

    $removed = removePeachCardToken($pdo, $userId, $registrationId);

    echo json_encode([
        'success' => true,
        'removed' => $removed,
        'message' => $removed ? 'Card removed.' : 'Card was already removed.',
    ]);
} catch (Throwable $e) {
    logException('payment_remove_card', $e);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to remove card.']);
}
