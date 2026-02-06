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
 * Update Payment Endpoint
 * POST /orders/update_payment.php
 */

 require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
 require_once __DIR__ . '/../middleware/auth_middleware.php';
 require_once __DIR__ . '/../util/check_permission.php';
 require_once __DIR__ . '/../util/order_tracker.php';
 require_once __DIR__ . '/../util/firebase_messaging.php';
 require_once __DIR__ . '/../util/notification_logger.php';
 
 // Ensure the request is authenticated
 requireJwtAuth();
 
 header('Content-Type: application/json');
 
 // Get the authenticated user's ID from the JWT payload
 $authUser = $GLOBALS['auth_user'] ?? null;
 $userId = $authUser['admin_id'] ?? null;
 
 if (!$userId) {
     http_response_code(401);
     echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
     exit;
 }
 
 // Check if the user has permission to create a country
 if (!checkUserPermission($userId, 'orders', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update orders.']);
     exit;
 }

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    if (!isset($input['order_id']) || !isset($input['payment_method_id']) || !isset($input['reference']) || !isset($input['amount']) || !isset($input['transaction_id']) || !isset($input['payment_date'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields: order Id, payment method, referrance, amount, and transaction Id pay date are required.'
        ]);
        exit;
    }

    $orderId = trim($input['order_id']);
    $payMethod = trim($input['payment_method_id']);
    $ref = trim($input['reference']);
    $amount = trim($input['amount']);
    $transactionId = trim($input['transaction_id']);
    $payDate = isset($input['payment_date']) ? trim($input['payment_date']) : date('Y-m-d H:i:s');

    // Validate input
    if (empty($orderId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Order ID cannot be empty.'
        ]);
        exit;
    }

    if (empty($payMethod)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Payment method cannot be empty.'
        ]);
        exit;
    }

    if (empty($ref)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Reference cannot be empty.'
        ]);
        exit;
    }

    if (!is_numeric($amount) || $amount <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Amount must be a positive number.'
        ]);
        exit;
    }

    if (empty($transactionId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Transaction ID cannot be empty.'
        ]);
        exit;
    }

    // Check if order exists (also fetch order owner user_id for notifications)
    $checkOrderStmt = $pdo->prepare("SELECT id, user_id, order_no FROM orders WHERE id = ?");
    $checkOrderStmt->execute([$orderId]);
    $existingOrder = $checkOrderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingOrder) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Order not found with ID: ' . $orderId
        ]);
        exit;
    }

    // Check for duplicate transaction_id
    $checkTransactionStmt = $pdo->prepare("SELECT id FROM payments WHERE transaction_id = ?");
    $checkTransactionStmt->execute([$transactionId]);
    $existingTransaction = $checkTransactionStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingTransaction) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Payment already exists with the same transaction ID.'
        ]);
        exit;
    }

    // Insert the payment
    $insertStmt = $pdo->prepare("
        INSERT INTO payments (order_id, ref, pay_method, amount, pay_date, transaction_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $result = $insertStmt->execute([$orderId, $ref, $payMethod, $amount, $payDate, $transactionId]);

    if ($result) {
        $paymentId = $pdo->lastInsertId();

        // Calculate total paid for this order
        $totalPaidStmt = $pdo->prepare("
            SELECT SUM(amount) as total_paid
            FROM payments
            WHERE order_id = ?
        ");
        $totalPaidStmt->execute([$orderId]);
        $totalPaidResult = $totalPaidStmt->fetch(PDO::FETCH_ASSOC);
        $totalPaid = (float)($totalPaidResult['total_paid'] ?? 0);

        // Calculate order total from order_items
        $orderTotalStmt = $pdo->prepare("
            SELECT SUM(quantity * price) as order_total
            FROM order_items
            WHERE order_id = ?
        ");
        $orderTotalStmt->execute([$orderId]);
        $orderTotalResult = $orderTotalStmt->fetch(PDO::FETCH_ASSOC);
        $orderTotal = (float)($orderTotalResult['order_total'] ?? 0);

        // Determine payment status
        $payStatus = 'unpaid';
        if ($totalPaid == 0) {
            $payStatus = 'unpaid';
        } elseif ($totalPaid > 0 && $totalPaid < $orderTotal) {
            $payStatus = 'partial paid';
        } elseif ($totalPaid == $orderTotal) {
            $payStatus = 'paid';
        } elseif ($totalPaid > $orderTotal) {
            $payStatus = 'over paid';
        }

        // Update order payment status
        $updateStatusStmt = $pdo->prepare("
            UPDATE orders
            SET pay_status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $updateStatusStmt->execute([$payStatus, $orderId]);

        // Track payment recording action based on payment status
        $trackAction = 'payment_recorded';
        if ($payStatus === 'partial paid') {
            $trackAction = 'Payment recived: Partial payment';
        } elseif ($payStatus === 'paid') {
            $trackAction = 'Payment recived: Full payment';
        } elseif ($payStatus === 'over paid') {
            $trackAction = 'Payment recived: Overpaid';
        }
        
        // Log payment action first
        trackOrderAction($pdo, $orderId, $trackAction);
        
        // Then log order processing if fully paid or overpaid
        if ($payStatus === 'paid' || $payStatus === 'over paid') {
            trackOrderAction($pdo, $orderId, 'order being processed');
        }

        // Best-effort: send notifications to the order owner according to their preferences.
        // Do NOT fail the payment endpoint if any notification fails.
        try {
            $orderOwnerUserId = (int)($existingOrder['user_id'] ?? 0);
            if ($orderOwnerUserId <= 0) {
                // No order owner, skip notifications
            } else {
                $orderNo = $existingOrder['order_no'] ?? $orderId;
                $notifTitle = 'Payment Received';
                $notifBody = "Payment received for order {$orderNo}.";

                // Fetch notification preferences (defaults: push=1, email=0, sms=0)
                $prefStmt = $pdo->prepare("
                    SELECT push_notifications, email_notifications, sms_notifications
                    FROM user_notification_preferences
                    WHERE user_id = ?
                ");
                $prefStmt->execute([$orderOwnerUserId]);
                $prefs = $prefStmt->fetch(PDO::FETCH_ASSOC);
                $pushEnabled = $prefs ? (int)$prefs['push_notifications'] === 1 : true;
                $emailEnabled = $prefs ? (int)$prefs['email_notifications'] === 1 : false;
                $smsEnabled = $prefs ? (int)$prefs['sms_notifications'] === 1 : false;

                // Fetch order owner contact for email/SMS
                $userStmt = $pdo->prepare("SELECT email, cell, name, surname FROM users WHERE id = ?");
                $userStmt->execute([$orderOwnerUserId]);
                $orderOwner = $userStmt->fetch(PDO::FETCH_ASSOC);
                $toName = $orderOwner ? trim(($orderOwner['name'] ?? '') . ' ' . ($orderOwner['surname'] ?? '')) : 'Customer';
                if ($toName === '') $toName = 'Customer';
                $orderOwnerEmail = $orderOwner && !empty(trim((string)($orderOwner['email'] ?? ''))) ? trim($orderOwner['email']) : null;
                $orderOwnerCell = $orderOwner && !empty(trim((string)($orderOwner['cell'] ?? ''))) ? trim($orderOwner['cell']) : null;

                $deliveredVia = null;
                $loggedIds = null;

                if ($pushEnabled) {
                    $deliveredVia = 'push';
                    $loggedIds = logUserNotification(
                        $pdo,
                        $orderOwnerUserId,
                        'payment_received',
                        $notifTitle,
                        $notifBody,
                        'order',
                        (int)$orderId,
                        [
                            'order_id' => (string)$orderId,
                            'payment_status' => (string)$payStatus,
                            'amount' => (string)$amount,
                            'transaction_id' => (string)$transactionId
                        ],
                        'push'
                    );
                    $notifData = [
                        'route' => 'order',
                        'order_id' => (string)$orderId,
                        'payment_status' => (string)$payStatus,
                        'amount' => (string)$amount,
                        'transaction_id' => (string)$transactionId,
                        'notification_id' => (string)($loggedIds['notification_id'] ?? ''),
                        'notification_user_id' => (string)($loggedIds['notification_user_id'] ?? '')
                    ];
                    sendPushNotificationToUser($orderOwnerUserId, $notifTitle, $notifBody, $notifData, null, $pdo);
                }

                if ($emailEnabled && $orderOwnerEmail !== null && filter_var($orderOwnerEmail, FILTER_VALIDATE_EMAIL)) {
                    if ($deliveredVia === null) {
                        $deliveredVia = 'email';
                        $loggedIds = logUserNotification(
                            $pdo,
                            $orderOwnerUserId,
                            'payment_received',
                            $notifTitle,
                            $notifBody,
                            'order',
                            (int)$orderId,
                            [
                                'order_id' => (string)$orderId,
                                'payment_status' => (string)$payStatus,
                                'amount' => (string)$amount,
                                'transaction_id' => (string)$transactionId
                            ],
                            'email'
                        );
                    }
                    require_once __DIR__ . '/../util/email_sender.php';
                    sendPaymentReceivedEmail(
                        $orderOwnerEmail,
                        $toName,
                        $orderNo,
                        $amount,
                        $payStatus,
                        ['order_id' => $orderId, 'user_id' => $orderOwnerUserId]
                    );
                }

                if ($smsEnabled && $orderOwnerCell !== null) {
                    if ($deliveredVia === null) {
                        $deliveredVia = 'sms';
                        $loggedIds = logUserNotification(
                            $pdo,
                            $orderOwnerUserId,
                            'payment_received',
                            $notifTitle,
                            $notifBody,
                            'order',
                            (int)$orderId,
                            [
                                'order_id' => (string)$orderId,
                                'payment_status' => (string)$payStatus,
                                'amount' => (string)$amount,
                                'transaction_id' => (string)$transactionId
                            ],
                            'sms'
                        );
                    }
                    $smsMessage = "Payment received for order {$orderNo}. Amount: {$amount}. Thank you.";
                    require_once __DIR__ . '/../util/sms_sender.php';
                    sendSms(
                        $orderOwnerCell,
                        $smsMessage,
                        ['order_id' => $orderId, 'user_id' => $orderOwnerUserId]
                    );
                }
            }
        } catch (Throwable $e) {
            logException('orders_update_payment_notifications', $e, [
                'order_id' => $orderId,
                'pay_status' => $payStatus
            ]);
        }

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Payment recorded successfully.',
            'data' => [
                'id' => $paymentId,
                'order_id' => $orderId,
                'ref' => $ref,
                'pay_method' => $payMethod,
                'amount' => (float)$amount,
                'pay_date' => $payDate,
                'transaction_id' => $transactionId,
                'payment_status' => $payStatus,
                'total_paid' => $totalPaid,
                'order_total' => $orderTotal,
                'due_amount' => $orderTotal - $totalPaid
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to record payment.'
        ]);
    }

} catch (PDOException $e) {
    logException('orders_update_payment', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error recording payment: ' . $e->getMessage()
    ]);
}
?>
