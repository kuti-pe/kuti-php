<?php

declare(strict_types=1);

namespace Kuti\Resource;

use Kuti\KutiClient;

/**
 * Diagnóstico (permiso diagnostics:read): qué pasó con una petición, un cobro o un webhook. Ideal con
 * una key de Solo lectura en un asistente de IA. Nunca expone al proveedor de pago. Devuelve el
 * objeto tal cual la API (snake_case).
 */
final class Diagnostics
{
    public function __construct(private readonly KutiClient $client)
    {
    }

    /**
     * GET /diagnostics/requests/{id} — usa getRequestId() de una excepción o el header X-Request-Id.
     *
     * @return array{request: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function getRequest(string $requestId): array
    {
        return $this->client->request('GET', '/diagnostics/requests/' . rawurlencode($requestId))['data'];
    }

    /**
     * GET /diagnostics/requests?correlation_id= — tus llamadas con el mismo X-Request-Id.
     *
     * @return list<array<string, mixed>>
     */
    public function listByCorrelationId(string $correlationId): array
    {
        return $this->client->request('GET', '/diagnostics/requests?' . http_build_query(['correlation_id' => $correlationId]))['data'];
    }

    /**
     * GET /diagnostics/payment-intents/{id}/trace — la historia completa de un cobro.
     *
     * @return array<string, mixed>
     */
    public function tracePaymentIntent(string $paymentIntentId): array
    {
        return $this->client->request('GET', '/diagnostics/payment-intents/' . rawurlencode($paymentIntentId) . '/trace')['data'];
    }
}
