<?php
/**
 * OTP Storage Utility
 *
 * Stores OTP codes hashed in DB table `user_otps` with expiry.
 */

require_once __DIR__ . '/error_logger.php';

/**
 * Generates a 6-digit OTP and stores it (hashed) for registration.
 *
 * @return array{otp_code:string, otp_id:int, expires_at:string}
 */
function createRegistrationOtp(PDO $pdo, int $userId, string $channel, string $destination): array {
    // 6 digits
    $otp = (string)random_int(100000, 999999);
    $codeHash = password_hash($otp, PASSWORD_DEFAULT);

    $expiresAtTs = time() + 600; // 10 minutes
    $expiresAt = date('Y-m-d H:i:s', $expiresAtTs);

    // Optional: remove old unconsumed register OTPs for this user/channel
    try {
        $cleanup = $pdo->prepare("
            DELETE FROM user_otps
            WHERE user_id = ? AND purpose = 'register' AND consumed_at IS NULL AND expires_at < NOW()
        ");
        $cleanup->execute([$userId]);
    } catch (Throwable $e) {
        // best-effort cleanup
        logException('otp_store_cleanup', $e, ['user_id' => $userId]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_otps (user_id, channel, destination, purpose, code_hash, expires_at, consumed_at, created_at)
        VALUES (?, ?, ?, 'register', ?, ?, NULL, NOW())
    ");
    $stmt->execute([$userId, $channel, $destination, $codeHash, $expiresAt]);

    return [
        'otp_code' => $otp,
        'otp_id' => (int)$pdo->lastInsertId(),
        'expires_at' => $expiresAt
    ];
}

