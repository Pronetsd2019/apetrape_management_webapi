<?php
/**
 * Shopper result / Embedded Checkout event landing page.
 * Flutter WebView detects this URL to close the payment flow.
 * GET /mobile/v1/payment/result.php?status=completed|cancelled|expired&order_id=
 */

$status = isset($_GET['status']) ? preg_replace('/[^a-z_]/', '', strtolower((string)$_GET['status'])) : 'unknown';
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$checkoutId = isset($_GET['checkoutId']) ? htmlspecialchars((string)$_GET['checkoutId'], ENT_QUOTES, 'UTF-8') : '';

$titles = [
    'completed' => 'Payment submitted',
    'cancelled' => 'Payment cancelled',
    'expired' => 'Payment expired',
];
$title = $titles[$status] ?? 'Payment update';
$message = $status === 'completed'
    ? 'You can return to the app. We are confirming your payment.'
    : 'You can close this screen and return to the app.';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 32px 20px; text-align: center; color: #111; }
    h1 { font-size: 1.25rem; margin-bottom: 8px; }
    p { color: #555; }
    .meta { margin-top: 24px; font-size: 12px; color: #999; word-break: break-all; }
  </style>
</head>
<body data-peach-result="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"
      data-order-id="<?php echo (int)$orderId; ?>"
      data-checkout-id="<?php echo $checkoutId; ?>">
  <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
  <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
  <div class="meta">peach_result=<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></div>
</body>
</html>
