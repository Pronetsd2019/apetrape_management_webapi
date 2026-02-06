<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit test for email sender (payment received email).
 * Asserts return structure; actual delivery depends on SMTP config in .env.
 */
class EmailSenderTest extends TestCase
{
    private static function loadEmailSender(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/control/util/email_sender.php';
    }

    public function testSendPaymentReceivedEmailReturnsArrayWithOkKey(): void
    {
        self::loadEmailSender();

        $result = sendPaymentReceivedEmail(
            'test@example.com',
            'Test User',
            'ORD-001',
            '100.00',
            'paid',
            ['test' => true]
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertIsBool($result['ok']);
        if (isset($result['error'])) {
            $this->assertIsString($result['error']);
        }
    }
}
