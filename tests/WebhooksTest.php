<?php

declare(strict_types=1);

namespace Kuti\Tests;

use Kuti\Exception\KutiSignatureVerificationException;
use Kuti\Webhooks;
use PHPUnit\Framework\TestCase;

final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test_1234567890';

    private static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public function testAcceptsASignatureComputedWithTheSameAlgorithmAsTheServer(): void
    {
        $body = json_encode(['type' => 'payment.succeeded', 'id' => 'evt_1']);
        $now = time();
        $signature = self::sign(self::SECRET, $now, $body);

        Webhooks::verifySignature($body, $signature, (string) $now, self::SECRET);
        $this->addToAssertionCount(1); // no exception = pass
    }

    public function testRejectsASignatureComputedWithTheWrongSecret(): void
    {
        $body = '{}';
        $now = time();
        $signature = self::sign('wrong-secret', $now, $body);

        $this->expectException(KutiSignatureVerificationException::class);
        Webhooks::verifySignature($body, $signature, (string) $now, self::SECRET);
    }

    public function testRejectsIfTheBodyWasTamperedWithAfterSigning(): void
    {
        $now = time();
        $signature = self::sign(self::SECRET, $now, json_encode(['amount' => '10.00']));

        $this->expectException(KutiSignatureVerificationException::class);
        Webhooks::verifySignature(json_encode(['amount' => '999.00']), $signature, (string) $now, self::SECRET);
    }

    public function testRejectsATimestampOutsideTheToleranceWindow(): void
    {
        $body = '{}';
        $staleTimestamp = time() - 3600;
        $signature = self::sign(self::SECRET, $staleTimestamp, $body);

        $this->expectException(KutiSignatureVerificationException::class);
        Webhooks::verifySignature($body, $signature, (string) $staleTimestamp, self::SECRET);
    }

    public function testAcceptsAStaleTimestampWhenToleranceIsWidenedExplicitly(): void
    {
        $body = '{}';
        $staleTimestamp = time() - 3600;
        $signature = self::sign(self::SECRET, $staleTimestamp, $body);

        Webhooks::verifySignature($body, $signature, (string) $staleTimestamp, self::SECRET, 7200);
        $this->addToAssertionCount(1);
    }

    public function testRejectsAMalformedSignatureHeader(): void
    {
        $this->expectException(KutiSignatureVerificationException::class);
        Webhooks::verifySignature('{}', 'not-a-valid-signature', (string) time(), self::SECRET);
    }
}
