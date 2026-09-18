<?php
/**
 * Embedded Checkout host page for Flutter WebView.
 * GET /mobile/v1/payment/embed.php?checkoutId=...
 *
 * Must be served over HTTPS on an allowlisted domain (not localhost).
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/peach_checkout.php';

$checkoutId = isset($_GET['checkoutId']) ? trim((string)$_GET['checkoutId']) : '';
if ($checkoutId === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $checkoutId)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid checkoutId';
    exit;
}

$entityId = peachEnv('PEACH_ENTITY_ID', '');
if ($entityId === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Peach not configured';
    exit;
}

$orderId = null;
try {
    ensurePeachCheckoutsTable($pdo);
    $stmt = $pdo->prepare("SELECT order_id FROM peach_checkouts WHERE checkout_id = ? LIMIT 1");
    $stmt->execute([$checkoutId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $orderId = (int)$row['order_id'];
    }
} catch (Throwable $e) {
    // Still render checkout; result redirect may omit order_id
}

$embedBase = htmlspecialchars(peachEmbedBaseUrl(), ENT_QUOTES, 'UTF-8');
$jsUrl = htmlspecialchars(peachCheckoutJsUrl(), ENT_QUOTES, 'UTF-8');
$entityIdEsc = htmlspecialchars($entityId, ENT_QUOTES, 'UTF-8');
$checkoutIdEsc = htmlspecialchars($checkoutId, ENT_QUOTES, 'UTF-8');
$orderIdQs = $orderId ? ('&order_id=' . (int)$orderId) : '';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <title>Apetrape Checkout</title>
  <style>
    html, body { margin: 0; padding: 0; height: 100%; background: #fff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    #payment-form, #checkout-root { min-height: 100vh; height: 100%; width: 100%; }
    .loading { padding: 24px; text-align: center; color: #444; }
  </style>
</head>
<body>
  <div id="payment-form">
    <p class="loading">Loading secure payment…</p>
  </div>
  <script src="<?php echo $jsUrl; ?>"></script>
  <script>
    (function () {
      var entityId = <?php echo json_encode($entityId); ?>;
      var checkoutId = <?php echo json_encode($checkoutId); ?>;
      var resultBase = <?php echo json_encode(peachEmbedBaseUrl() . '/mobile/v1/payment/result.php'); ?>;
      var orderQs = <?php echo json_encode($orderId ? ('order_id=' . (int)$orderId) : ''); ?>;

      function go(status) {
        var qs = 'status=' + encodeURIComponent(status);
        if (orderQs) qs += '&' + orderQs;
        qs += '&checkoutId=' + encodeURIComponent(checkoutId);
        window.location.href = resultBase + '?' + qs;
      }

      function start() {
        if (typeof Checkout === 'undefined' || !Checkout.initiate) {
          document.querySelector('.loading').textContent = 'Unable to load payment form. Please try again.';
          return;
        }
        var checkout = Checkout.initiate({
          key: entityId,
          checkoutId: checkoutId,
          events: {
            onCompleted: function () { go('completed'); },
            onCancelled: function () { go('cancelled'); },
            onExpired: function () { go('expired'); }
          }
        });
        checkout.render('#payment-form');
      }

      if (document.readyState === 'complete') {
        start();
      } else {
        window.addEventListener('load', start);
      }
    })();
  </script>
</body>
</html>
