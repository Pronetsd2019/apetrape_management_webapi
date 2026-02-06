<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit test for SMS sender (generic sendSms).
 * Asserts return structure; actual delivery depends on SMS gateway config in .env.
 */
class SmsSenderTest extends TestCase
{
    private static function loadSmsSender(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/control/util/sms_sender.php';
    }

    public function testSendSmsReturnsExpectedStructure(): void
    {
        self::loadSmsSender();

        $result = sendSms('+26812345678', 'Test message from unit test.', ['test' => true]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('http_code', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('response_raw', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsInt($result['http_code']);
        if (isset($result['error'])) {
            $this->assertIsString($result['error']);
        }
    }
}
