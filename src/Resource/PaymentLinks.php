<?php

declare(strict_types=1);

namespace Kuti\Resource;

use Kuti\KutiClient;
use Kuti\PaymentLink;

/**
 * Links de pago. Parámetros en camelCase:
 * title, pricing (FIXED | CUSTOMER_CHOOSES), paymentMethodTypes, amount, minAmount, maxAmount,
 * suggestedAmounts, slug, template (COURSE | EVENT | DONATION | GENERIC), description, imageUrl,
 * currency, categoryId, expiresAt, customerFieldIds, buttonLabel, successMessage,
 * successButtonLabel, successButtonUrl.
 */
final class PaymentLinks
{
    private const FIELDS = [
        'slug' => 'slug',
        'title' => 'title',
        'description' => 'description',
        'imageUrl' => 'image_url',
        'template' => 'template',
        'pricing' => 'pricing',
        'amount' => 'amount',
        'minAmount' => 'min_amount',
        'maxAmount' => 'max_amount',
        'suggestedAmounts' => 'suggested_amounts',
        'currency' => 'currency',
        'paymentMethodTypes' => 'payment_method_types',
        'categoryId' => 'category_id',
        'expiresAt' => 'expires_at',
        'customerFieldIds' => 'customer_field_ids',
        'buttonLabel' => 'button_label',
        'successMessage' => 'success_message',
        'successButtonLabel' => 'success_button_label',
        'successButtonUrl' => 'success_button_url',
    ];

    public function __construct(private readonly KutiClient $client)
    {
    }

    /**
     * POST /payment-links. customerFieldIds sin enviar = los "pedir también al pagar";
     * [] = solo nombre, apellido y correo.
     *
     * @param array<string, mixed> $params
     */
    public function create(array $params): PaymentLink
    {
        $response = $this->client->request('POST', '/payment-links', self::toBody($params));

        return PaymentLink::fromArray($response['data']);
    }

    /** GET /payment-links/{id} */
    public function retrieve(string $id): PaymentLink
    {
        $response = $this->client->request('GET', '/payment-links/' . rawurlencode($id));

        return PaymentLink::fromArray($response['data']);
    }

    /**
     * PUT /payment-links/{id} — reemplaza el link completo (los cobros ya creados no cambian).
     *
     * @param array<string, mixed> $params
     */
    public function update(string $id, array $params): PaymentLink
    {
        $response = $this->client->request('PUT', '/payment-links/' . rawurlencode($id), self::toBody($params));

        return PaymentLink::fromArray($response['data']);
    }

    /**
     * GET /payment-links
     *
     * @param array{status?: string, q?: string, page?: int, perPage?: int|string} $params
     * @return array{data: list<PaymentLink>, pagination: array<string, mixed>}
     */
    public function list(array $params = []): array
    {
        $query = array_filter([
            'status' => $params['status'] ?? null,
            'q' => $params['q'] ?? null,
            'page' => isset($params['page']) ? (string) $params['page'] : null,
            'per_page' => isset($params['perPage']) ? (string) $params['perPage'] : null,
        ], static fn (mixed $v): bool => $v !== null);
        $path = '/payment-links' . ($query !== [] ? '?' . http_build_query($query) : '');

        $response = $this->client->request('GET', $path);
        $data = array_map(
            static fn (array $row): PaymentLink => PaymentLink::fromArray($row),
            $response['data'] ?? [],
        );
        $p = $response['pagination'] ?? [];

        return [
            'data' => $data,
            'pagination' => [
                'page' => $p['page'] ?? 1,
                'perPage' => $p['per_page'] ?? count($data),
                'total' => $p['total'] ?? count($data),
                'totalPages' => $p['total_pages'] ?? 1,
                'hasMore' => $p['has_more'] ?? false,
            ],
        ];
    }

    /** POST /payment-links/{id}/activate */
    public function activate(string $id): PaymentLink
    {
        $response = $this->client->request('POST', '/payment-links/' . rawurlencode($id) . '/activate');

        return PaymentLink::fromArray($response['data']);
    }

    /** POST /payment-links/{id}/deactivate — deja de aceptar pagos. */
    public function deactivate(string $id): PaymentLink
    {
        $response = $this->client->request('POST', '/payment-links/' . rawurlencode($id) . '/deactivate');

        return PaymentLink::fromArray($response['data']);
    }

    /**
     * GET /payment-links/slug-availability — si está ocupado, suggestion trae uno libre.
     *
     * @return array{slug: string, available: bool, suggestion: string}
     */
    public function checkSlug(string $slug, ?string $exceptId = null): array
    {
        $query = ['slug' => $slug];
        if ($exceptId !== null) {
            $query['except_id'] = $exceptId;
        }
        $response = $this->client->request('GET', '/payment-links/slug-availability?' . http_build_query($query));

        return $response['data'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function toBody(array $params): array
    {
        $body = [];
        foreach (self::FIELDS as $param => $field) {
            // array_key_exists: [] en customerFieldIds significa "ninguna pregunta" y debe viajar.
            if (array_key_exists($param, $params) && $params[$param] !== null) {
                $body[$field] = $params[$param];
            }
        }

        return $body;
    }
}
