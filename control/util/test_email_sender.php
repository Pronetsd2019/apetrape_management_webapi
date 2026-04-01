<?php
/**
 * Email sending test script.
 *
 * Usage (CLI):
 *   php control/util/test_email_sender.php --to="you@example.com" --name="Your Name" --type=otp
 *   php control/util/test_email_sender.php --to="you@example.com" --name="Your Name" --type=payment
 *
 * Usage (Browser):
 *   /control/util/test_email_sender.php?to=you@example.com&name=Your%20Name&type=otp
 */

require_once __DIR__ . '/email_sender.php';
require_once __DIR__ . '/comm_logger.php';

/**
 * Get argument value from CLI opts or query params.
 */
function getInputValue(string $key, $default = null) {
    if (PHP_SAPI === 'cli') {
        static $cliOptions = null;
        if ($cliOptions === null) {
            $cliOptions = getopt('', ['to:', 'name:', 'type:']);
        }
        return $cliOptions[$key] ?? $default;
    }
    return $_GET[$key] ?? $default;
}

$toEmail = trim((string)getInputValue('to', ''));
$toName = trim((string)getInputValue('name', 'Email Test User'));
$type = strtolower(trim((string)getInputValue('type', 'otp')));

if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    $msg = "Invalid or missing recipient email. Provide --to (CLI) or ?to= (browser).";
    echo $msg . PHP_EOL;
    logComm('comm_email_test_invalid_input', $msg, ['to' => $toEmail, 'type' => $type], 'error');
    exit(1);
}

$context = [
    'source' => 'test_email_sender',
    'sapi' => PHP_SAPI,
    'type' => $type
];

logComm('comm_email_test_start', 'Starting email send test', array_merge($context, [
    'to' => $toEmail,
    'name' => $toName
]));

if ($type === 'payment') {
    $result = sendPaymentReceivedEmail(
        $toEmail,
        $toName,
        'TEST-' . date('YmdHis'),
        'R 100.00',
        'paid',
        $context
    );
} else {
    $testOtp = (string)random_int(100000, 999999);
    $result = sendOtpEmail($toEmail, $toName, $testOtp, $context);
}

$logBase = dirname(__DIR__) . '/logs';
$commLogHint = $logBase . '/opt_Send/' . date('Y/m/d') . '/logs.log';
$errorLogHint = $logBase . '/errors.log';

if (!empty($result['ok'])) {
    echo "SUCCESS: Test email sent to {$toEmail}" . PHP_EOL;
    echo "Communication log: {$commLogHint}" . PHP_EOL;
    logComm('comm_email_test_success', 'Email send test completed successfully', array_merge($context, [
        'to' => $toEmail
    ]));
    exit(0);
}

$errorMessage = $result['error'] ?? 'Unknown error';
echo "FAILED: Could not send email." . PHP_EOL;
echo "Error: {$errorMessage}" . PHP_EOL;
echo "Communication log: {$commLogHint}" . PHP_EOL;
echo "Error log: {$errorLogHint}" . PHP_EOL;

logComm('comm_email_test_failed', 'Email send test failed', array_merge($context, [
    'to' => $toEmail,
    'error' => $errorMessage
]), 'error');

exit(1);
