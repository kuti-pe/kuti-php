<?php

declare(strict_types=1);

namespace Kuti\Resource;

use Kuti\KutiClient;
use Kuti\PaymentException;

/** Pagos para revisar. Aviso por webhook: payment.exception_created. */
final class PaymentExceptions
{
    public function __construct(private readonly KutiClient $client)
    {
    }

    /**
     * GET /payment-exceptions. Filtros: status, paymentIntentId, page, perPage.
     *
     * @param array{status?: string, paymentIntentId?: string, page?: int, perPage?: int} $params
     * @return array{data: list<PaymentException>, pagination: array<string, mixed>}
     */
    public function list(array $params = []): array
    {
        $query = array_filter([
            'status' => $params['status'] ?? null,
            'payment_intent_id' => $params['paymentIntentId'] ?? null,
            'page' => $params['page'] ?? null,
            'per_page' => $params['perPage'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');
        $path = '/payment-exceptions' . ($query ? '?' . http_build_query($query) : '');
        $response = $this->client->request('GET', $path);

        return [
            'data' => array_map(static fn (array $row) => PaymentException::fromArray($row), $response['data'] ?? []),
            'pagination' => $response['pagination'] ?? [],
        ];
    }

    /**
     * POST /payment-exceptions/{id}/resolve — deja constancia de qué hiciste (no mueve dinero).
     *
     * @param string $status REFUNDED | APPLIED | DISMISSED
     */
    public function resolve(string $id, string $status, ?string $note = null): PaymentException
    {
        $response = $this->client->request(
            'POST',
            '/payment-exceptions/' . rawurlencode($id) . '/resolve',
            array_filter(['status' => $status, 'note' => $note], static fn ($v) => $v !== null),
        );

        return PaymentException::fromArray($response['data']);
    }
}
