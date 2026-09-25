<?php

declare(strict_types=1);

namespace Kuti\Resource;

use Kuti\KutiClient;
use Kuti\Money;
use Kuti\PaymentIntent;
use Kuti\CustomerInput;
use Kuti\PaymentMethodType;

final class PaymentIntents
{
    public function __construct(private readonly KutiClient $client)
    {
    }

    /**
     * POST /payment-intents — QR, bank code, checkoutUrl.
     *
     * @param PaymentMethodType[] $paymentMethodTypes
     * @param array<string, string>|null $metadata
     * @param list<string>|null $sendVia Por dónde se envía el cobro: 'EMAIL', 'WHATSAPP'. null = ['EMAIL'];
     *     [] = no enviar. WHATSAPP necesita teléfono del cliente (usa 1 moneda).
     */
    public function create(
        Money $amount,
        array $paymentMethodTypes,
        ?CustomerInput $customer = null,
        ?string $receivableId = null,
        ?string $categoryId = null,
        ?bool $requiresCustomerInfo = null,
        ?string $description = null,
        ?string $externalReference = null,
        ?string $expiresAt = null,
        ?string $merchantId = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
        ?array $sendVia = null,
    ): PaymentIntent {
        $body = array_filter(
            [
                'amount' => $amount->toArray(),
                'payment_method_types' => array_map(
                    static fn (PaymentMethodType $t): string => $t->value,
                    $paymentMethodTypes,
                ),
                'customer' => $customer?->toArray(),
                'receivable_id' => $receivableId,
                'category_id' => $categoryId,
                'requires_customer_info' => $requiresCustomerInfo,
                'description' => $description,
                'external_reference' => $externalReference,
                'expires_at' => $expiresAt,
                'merchant_id' => $merchantId,
                'metadata' => $metadata,
            ],
            static fn (mixed $v): bool => $v !== null && $v !== [],
        );
        // Fuera del filtro: [] es válido ("no enviar nada").
        if ($sendVia !== null) {
            $body['send_via'] = array_values($sendVia);
        }

        $response = $this->client->request('POST', '/payment-intents', $body, $idempotencyKey);

        return PaymentIntent::fromArray($response['data']);
    }

    /**
     * GET /payment-intents
     *
     * @param array{
     *   status?: string,
     *   q?: string,
     *   customerId?: string,
     *   source?: string,
     *   paymentLinkId?: string,
     *   createdFrom?: string,
     *   createdTo?: string,
     *   page?: int,
     *   perPage?: int|string
     * } $params
     * @return array{data: list<PaymentIntent>, pagination: array<string, mixed>}
     */
    public function list(array $params = []): array
    {
        $query = [];
        if (isset($params['status'])) {
            $query['status'] = $params['status'];
        }
        if (isset($params['q'])) {
            $query['q'] = $params['q'];
        }
        if (isset($params['customerId'])) {
            $query['customer_id'] = $params['customerId'];
        }
        if (isset($params['source'])) {
            $query['source'] = $params['source'];
        }
        if (isset($params['paymentLinkId'])) {
            $query['payment_link_id'] = $params['paymentLinkId'];
        }
        if (isset($params['createdFrom'])) {
            $query['created_from'] = $params['createdFrom'];
        }
        if (isset($params['createdTo'])) {
            $query['created_to'] = $params['createdTo'];
        }
        if (isset($params['page'])) {
            $query['page'] = (string) $params['page'];
        }
        if (isset($params['perPage'])) {
            $query['per_page'] = (string) $params['perPage'];
        }
        $path = '/payment-intents';
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        $response = $this->client->request('GET', $path);
        $data = array_map(
            static fn (array $row): PaymentIntent => PaymentIntent::fromArray($row),
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

    /** GET /payment-intents/:id */
    public function retrieve(string $id): PaymentIntent
    {
        $response = $this->client->request('GET', '/payment-intents/' . rawurlencode($id));

        return PaymentIntent::fromArray($response['data']);
    }

    /** POST /payment-intents/:id/cancel */
    public function cancel(string $id): PaymentIntent
    {
        $response = $this->client->request('POST', '/payment-intents/' . rawurlencode($id) . '/cancel');

        return PaymentIntent::fromArray($response['data']);
    }

    /** POST /payment-intents/:id/send-whatsapp — 204 on success. */
    public function sendWhatsApp(string $id, ?string $phone = null, ?string $customerName = null): void
    {
        $body = array_filter(
            [
                'phone' => $phone,
                'customer_name' => $customerName,
            ],
            static fn (mixed $v): bool => $v !== null && $v !== '',
        );
        $this->client->request(
            'POST',
            '/payment-intents/' . rawurlencode($id) . '/send-whatsapp',
            $body === [] ? new \stdClass() : $body,
        );
    }
}
