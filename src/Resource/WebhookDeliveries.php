<?php

declare(strict_types=1);

namespace Kuti\Resource;

use Kuti\KutiClient;

/**
 * Entregas de webhook. Devuelve el objeto tal cual la API (snake_case): status, attempts,
 * last_http_status, attempt_history (cada intento con http_status y response_body).
 */
final class WebhookDeliveries
{
    public function __construct(private readonly KutiClient $client)
    {
    }

    /**
     * GET /webhook-deliveries/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', '/webhook-deliveries/' . rawurlencode($id))['data'];
    }

    /**
     * POST /webhook-deliveries/{id}/retry — la reencola para envío inmediato.
     *
     * @return array<string, mixed>
     */
    public function retry(string $id, ?string $idempotencyKey = null): array
    {
        return $this->client->request(
            'POST',
            '/webhook-deliveries/' . rawurlencode($id) . '/retry',
            null,
            $idempotencyKey,
        )['data'];
    }
}
