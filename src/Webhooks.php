<?php

declare(strict_types=1);

namespace Kuti;

use Kuti\Exception\KutiSignatureVerificationException;

final class Webhooks
{
    private const SIGNATURE_PREFIX = 'v1=';
    private const DEFAULT_TOLERANCE_SECONDS = 300;

    private function __construct()
    {
    }

    /**
     * Verifica la firma de un webhook de KUTI. Recomputa
     * HMAC_SHA256(secret, "<timestamp>.<body>") con el $payload crudo (el string exacto del body,
     * SIN parsear a JSON primero — un solo espacio de diferencia invalida la firma) y lo compara en
     * tiempo constante contra el header X-Kuti-Signature. También rechaza timestamps viejos para
     * evitar ataques de replay.
     *
     * @param string $payload Body crudo del request tal como llegó (nunca el array ya decodificado).
     * @param string $signatureHeader Valor del header X-Kuti-Signature (formato v1=<hex>).
     * @param string $timestampHeader Valor del header X-Kuti-Timestamp (segundos Unix, como string).
     * @param string $secret El signing_secret de tu webhook endpoint (dashboard de KUTI).
     * @param int $toleranceSeconds Ventana de tiempo aceptada entre el timestamp firmado y ahora. Default 300s (5 min).
     * @throws KutiSignatureVerificationException Si la firma no coincide o el timestamp está fuera de tolerancia.
     */
    public static function verifySignature(
        string $payload,
        string $signatureHeader,
        string $timestampHeader,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): void {
        if (!str_starts_with($signatureHeader, self::SIGNATURE_PREFIX)) {
            throw new KutiSignatureVerificationException('Unexpected signature format (expected v1=<hex>).');
        }

        if (!ctype_digit($timestampHeader)) {
            throw new KutiSignatureVerificationException('Invalid timestamp header.');
        }
        $timestampSeconds = (int) $timestampHeader;

        $now = time();
        if (abs($now - $timestampSeconds) > $toleranceSeconds) {
            throw new KutiSignatureVerificationException(
                'Timestamp outside of tolerance — possible replay attack, or your clock is out of sync.',
            );
        }

        $expected = self::computeSignature($secret, $timestampSeconds, $payload);
        $received = substr($signatureHeader, strlen(self::SIGNATURE_PREFIX));

        if (!hash_equals($expected, $received)) {
            throw new KutiSignatureVerificationException('Signature mismatch.');
        }
    }

    private static function computeSignature(string $secret, int $timestampSeconds, string $body): string
    {
        return hash_hmac('sha256', $timestampSeconds . '.' . $body, $secret);
    }
}
