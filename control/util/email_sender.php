<?php
/**
 * Email Sender Utility (PHPMailer)
 *
 * Used for sending OTP emails on registration.
 */

require_once __DIR__ . '/error_logger.php';
require_once __DIR__ . '/comm_logger.php';
require_once __DIR__ . '/email_config.php';

require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends a branded OTP email.
 *
 * @return array{ok:bool, error?:string}
 */
function sendOtpEmail(string $toEmail, string $toName, string $otpCode, array $context = []): array {
    $emailConfig = getEmailConfig();

    // Log attempt (do not log OTP code)
    logComm('comm_email_attempt', 'Attempting to send OTP email', array_merge($context, [
        'to' => $toEmail,
        'subject' => 'Your Apetrape verification code'
    ]));

    // Use hosted image URLs (more reliable than base64; many clients block data URIs)
    $baseUrl = $emailConfig['assets_base_url'] ?? '';
    $logoPath = '/logo/';
    $wordUrl = $baseUrl ? (rtrim($baseUrl, '/') . $logoPath . 'apetrape-word.png') : '';

    $safeName = htmlspecialchars($toName ?: 'Customer', ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8');

    $subject = 'Your Apetrape verification code';

    // Build header with logos if base URL is configured
    $headerHtml = '';
    if ($wordUrl) {
        $headerHtml .= '<img src="' . htmlspecialchars($wordUrl, ENT_QUOTES, 'UTF-8') . '" alt="Apetrape" style="height:26px;width:auto;display:block;" />';
    }
    if ($headerHtml === '') {
        $headerHtml = '<div style="font-size:18px;font-weight:bold;color:#5d0808;">Apetrape</div>';
    }

    $html = <<<HTML
<!doctype html>
<html>
  <body style="margin:0;padding:0;background:#f6f6f6;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
      <div style="background:#ffffff;border-radius:12px;padding:24px;border:1px solid #eee;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
          {$headerHtml}
        </div>
        <h2 style="margin:0 0 8px 0;color:#222;">Verify your account</h2>
        <p style="margin:0 0 16px 0;color:#444;line-height:1.5;">
          Hi {$safeName}, your one-time verification code is:
        </p>

        <div style="font-size:28px;letter-spacing:6px;font-weight:700;color:#111;background:#f2f2f2;padding:14px 18px;border-radius:10px;display:inline-block;">
          {$safeOtp}
        </div>

        <p style="margin:16px 0 0 0;color:#666;line-height:1.5;font-size:13px;">
          This code expires in 10 minutes. If you didn’t request this, you can ignore this email.
        </p>
      </div>

      <p style="margin:12px 0 0 0;color:#888;font-size:12px;text-align:center;">
        APE Trape PTY Ltd
      </p>
    </div>
  </body>
</html>
HTML;

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $emailConfig['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $emailConfig['smtp_username'];
        $mail->Password = $emailConfig['smtp_password'];
        $mail->SMTPSecure = $emailConfig['smtp_secure'];
        $mail->Port = $emailConfig['smtp_port'];
        $mail->CharSet = 'UTF-8';
        // Prevent long hangs on bad SMTP (critical for API responsiveness)
        $mail->Timeout = 10;
        $mail->SMTPTimeout = 10;
        $mail->SMTPKeepAlive = false;

        $mail->setFrom($emailConfig['from_email'], $emailConfig['from_name']);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo($emailConfig['reply_to_email'], $emailConfig['reply_to_name']);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = "Hi {$toName}, your Apetrape verification code is: {$otpCode}. It expires in 10 minutes.";

        $mail->send();
        logComm('comm_email_success', 'OTP email sent successfully', array_merge($context, [
            'to' => $toEmail
        ]));
        return ['ok' => true];
    } catch (Throwable $e) {
        logCommException('comm_email_error', $e, array_merge($context, [
            'to' => $toEmail,
            'phpmailer_error' => isset($mail) ? ($mail->ErrorInfo ?? null) : null
        ]));
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Sends a "payment received" transactional email.
 *
 * @param string $toEmail Recipient email.
 * @param string $toName Recipient name (e.g. "John" or "John Doe").
 * @param string $orderNo Order number for display.
 * @param string $amount Amount paid.
 * @param string $payStatus Payment status (e.g. paid, partial paid, over paid).
 * @param array $context Optional context for logging.
 * @return array{ok:bool, error?:string}
 */
function sendPaymentReceivedEmail(string $toEmail, string $toName, string $orderNo, string $amount, string $payStatus, array $context = []): array {
    $emailConfig = getEmailConfig();

    $subject = 'Payment received – Order ' . $orderNo;
    logComm('comm_email_attempt', 'Attempting to send payment received email', array_merge($context, [
        'to' => $toEmail,
        'subject' => $subject
    ]));

    $baseUrl = $emailConfig['assets_base_url'] ?? '';
    $logoPath = '/logo/';
    $wordUrl = $baseUrl ? (rtrim($baseUrl, '/') . $logoPath . 'apetrape-word.png') : '';

    $safeName = htmlspecialchars($toName ?: 'Customer', ENT_QUOTES, 'UTF-8');
    $safeOrderNo = htmlspecialchars($orderNo, ENT_QUOTES, 'UTF-8');
    $safeAmount = htmlspecialchars($amount, ENT_QUOTES, 'UTF-8');
    $safePayStatus = htmlspecialchars($payStatus, ENT_QUOTES, 'UTF-8');

    $headerHtml = '';
    if ($wordUrl) {
        $headerHtml .= '<img src="' . htmlspecialchars($wordUrl, ENT_QUOTES, 'UTF-8') . '" alt="Apetrape" style="height:26px;width:auto;display:block;" />';
    }
    if ($headerHtml === '') {
        $headerHtml = '<div style="font-size:18px;font-weight:bold;color:#5d0808;">Apetrape</div>';
    }

    $html = <<<HTML
<!doctype html>
<html>
  <body style="margin:0;padding:0;background:#f6f6f6;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
      <div style="background:#ffffff;border-radius:12px;padding:24px;border:1px solid #eee;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
          {$headerHtml}
        </div>
        <h2 style="margin:0 0 8px 0;color:#222;">Payment received</h2>
        <p style="margin:0 0 16px 0;color:#444;line-height:1.5;">
          Hi {$safeName},
        </p>
        <p style="margin:0 0 16px 0;color:#444;line-height:1.5;">
          We have received your payment for order <strong>{$safeOrderNo}</strong>.
        </p>
        <p style="margin:0 0 8px 0;color:#444;line-height:1.5;">
          Amount: <strong>{$safeAmount}</strong><br />
          Status: {$safePayStatus}
        </p>
        <p style="margin:16px 0 0 0;color:#666;line-height:1.5;font-size:13px;">
          Thank you for your order.
        </p>
      </div>
      <p style="margin:12px 0 0 0;color:#888;font-size:12px;text-align:center;">
        APE Trape PTY Ltd
      </p>
    </div>
  </body>
</html>
HTML;

    $plainBody = "Hi {$toName}, we have received your payment for order {$orderNo}. Amount: {$amount}. Status: {$payStatus}. Thank you for your order.";

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $emailConfig['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $emailConfig['smtp_username'];
        $mail->Password = $emailConfig['smtp_password'];
        $mail->SMTPSecure = $emailConfig['smtp_secure'];
        $mail->Port = $emailConfig['smtp_port'];
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 10;
        $mail->SMTPTimeout = 10;
        $mail->SMTPKeepAlive = false;

        $mail->setFrom($emailConfig['from_email'], $emailConfig['from_name']);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo($emailConfig['reply_to_email'], $emailConfig['reply_to_name']);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $plainBody;

        $mail->send();
        logComm('comm_email_success', 'Payment received email sent successfully', array_merge($context, [
            'to' => $toEmail
        ]));
        return ['ok' => true];
    } catch (Throwable $e) {
        logCommException('comm_email_error', $e, array_merge($context, [
            'to' => $toEmail,
            'phpmailer_error' => isset($mail) ? ($mail->ErrorInfo ?? null) : null
        ]));
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

