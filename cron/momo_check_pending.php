<?php
/**
 * Hourly MoMo status recovery cron
 *
 * Polls MTN Collection for momo_transactions still in new/pending that may
 * have missed the callback. Safe to run concurrently with status.php /
 * callback.php — payment insert is idempotent.
 *
 * Crontab (every hour):
 *   0 * * * * /usr/bin/php /path/to/apetrape_management_webapi/cron/momo_check_pending.php >> /path/to/logs/momo_cron.log 2>&1
 *
 * CLI only (exits if hit via HTTP without MOMO_CRON_ALLOW_HTTP=1).
 */

declare(strict_types=1);

date_default_timezone_set('Africa/Johannesburg');

if (PHP_SAPI !== 'cli') {
    // Allow optional HTTP trigger for Herd/cron-as-URL setups
    $envAllow = getenv('MOMO_CRON_ALLOW_HTTP') ?: ($_ENV['MOMO_CRON_ALLOW_HTTP'] ?? '');
    if ($envAllow !== '1') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'CLI only']);
        exit(1);
    }
}

require_once __DIR__ . '/../control/util/connect.php';
require_once __DIR__ . '/../control/util/error_logger.php';
require_once __DIR__ . '/../control/util/momo_collection.php';

$logDir = __DIR__ . '/../control/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/momo_cron.log';

$cronLog = static function (string $message) use ($logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if (PHP_SAPI === 'cli') {
        echo $line;
    }
};

$lockPath = sys_get_temp_dir() . '/apetrape_momo_check_pending.lock';
$lockHandle = fopen($lockPath, 'c+');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $cronLog('SKIP | another momo cron run is already in progress');
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Skipped; already running']);
    }
    exit(0);
}

$summary = [
    'checked' => 0,
    'updated' => 0,
    'still_pending' => 0,
    'successful' => 0,
    'failed' => 0,
    'recorded_payments' => 0,
    'skipped_already_paid' => 0,
    'errors' => 0,
];

$startedAt = date('Y-m-d H:i:s');
$cronLog('START | momo_check_pending');

try {
    if (!momoIsConfigured()) {
        throw new RuntimeException('MoMo is not configured (missing MOMO_* env vars).');
    }

    ensureMomoTransactionsTable($pdo);

    // Ignore brand-new rows (app may still be polling) and very old abandoned ones.
    $minAgeMinutes = 2;
    $maxAgeDays = 7;

    $stmt = $pdo->prepare("
        SELECT mt.*
        FROM momo_transactions mt
        WHERE LOWER(mt.status) IN ('new', 'pending')
          AND mt.created_at <= DATE_SUB(NOW(), INTERVAL {$minAgeMinutes} MINUTE)
          AND mt.created_at >= DATE_SUB(NOW(), INTERVAL {$maxAgeDays} DAY)
        ORDER BY mt.id ASC
        LIMIT 200
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $txn) {
        $summary['checked']++;
        $id = (int)$txn['id'];
        $orderId = (int)$txn['order_id'];
        $ref = (string)$txn['reference_id'];
        $externalId = (string)$txn['external_id'];

        try {
            // If this external_id was already posted to payments, mark successful and skip API
            $paidCheck = $pdo->prepare("
                SELECT id FROM payments
                WHERE order_id = ? AND ref = ?
                LIMIT 1
            ");
            $paidCheck->execute([$orderId, $externalId]);
            if ($paidCheck->fetch(PDO::FETCH_ASSOC)) {
                updateMomoTransactionStatus($pdo, $id, 'successful', 'already recorded in payments');
                $summary['skipped_already_paid']++;
                $summary['updated']++;
                $cronLog("SKIP_PAID | id={$id} order={$orderId} external={$externalId}");
                continue;
            }

            $response = getMomoPaymentStatus($ref);
            $httpCode = (int)($response['http_code'] ?? 0);
            if ($httpCode !== 200 || !is_array($response['json'])) {
                $summary['errors']++;
                $cronLog("API_ERROR | id={$id} http={$httpCode} body=" . substr((string)($response['raw'] ?? ''), 0, 200));
                continue;
            }

            $applied = applyMomoStatusPayload($pdo, $txn, $response['json']);
            $status = momoNormalizeStatus($applied['status']);
            $summary['updated']++;

            if (momoIsSuccessfulStatus($status)) {
                $summary['successful']++;
                if (!empty($applied['recorded']['inserted'])) {
                    $summary['recorded_payments']++;
                }
                $cronLog("SUCCESS | id={$id} order={$orderId} external={$externalId} inserted=" . (!empty($applied['recorded']['inserted']) ? '1' : '0'));
            } elseif (momoIsTerminalStatus($status)) {
                $summary['failed']++;
                $cronLog("TERMINAL | id={$id} order={$orderId} status={$status}");
            } else {
                $summary['still_pending']++;
                $cronLog("PENDING | id={$id} order={$orderId} status={$status}");
            }
        } catch (Throwable $e) {
            $summary['errors']++;
            $cronLog("ERROR | id={$id} " . $e->getMessage());
            logException('momo_check_pending_cron_row', $e);
        }

        // Small delay to avoid hammering MTN
        usleep(150000);
    }

    $cronLog('END | ' . json_encode(array_merge($summary, ['started_at' => $startedAt, 'finished_at' => date('Y-m-d H:i:s')])));

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'summary' => $summary]);
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(0);
} catch (Throwable $e) {
    $summary['errors']++;
    $cronLog('FATAL | ' . $e->getMessage());
    logException('momo_check_pending_cron', $e);

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'summary' => $summary]);
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(1);
}
