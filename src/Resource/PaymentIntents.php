<?php

declare(strict_types=1);

namespace Kuti\Resource;

use Kuti\KutiClient;
use Kuti\Money;
use Kuti\PaymentIntent;
use Kuti\PaymentIntentCustomer;
use Kuti\PaymentMethodType;

final class PaymentIntents
{
    public function __construct(private readonly KutiClient $client)
    {
    }

    /**
     * Crea un payment intent (cobro). Devuelve QR, código de pago de servicios y checkoutUrl.
     * El monto SIEMPRE debe resolverse en tu backend. Pasa $idempotencyKey para no duplicar cobros.
     *
     * @param PaymentMethodType[] $paymentMethodTypes
     * @param array<string, string>|null $metadata
     */
    public function create(
        Money $amount,
        array $paymentMethodTypes,
        ?PaymentIntentCustomer $customer = null,
        ?string $customerId = null,
        ?string $receivableId = null,
        ?string $categoryId = null,
        ?bool $requiresCustomerInfo = null,
        ?string $description = null,
        ?string $externalReference = null,
        ?string $expiresAt = null,
        ?string $merchantId = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
    ): PaymentIntent {
        $body = array_filter(
            [
                'amount' => $amount->toArray(),
                'payment_method_types' => array_map(
                    static fn (PaymentMethodType $t): string => $t->value,
                    $paymentMethodTypes,
                ),
                'customer' => $customer?->toArray(),
                'customer_id' => $customerId,
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

        $response = $this->client->request('POST', '/payment-intents', $body, $idempotencyKey);

        return PaymentIntent::fromArray($response['data']);
    }

    /**
     * Consulta el estado real de un cobro. Es la fuente de verdad — nunca confíes en un callback del
     * frontend (`onSuccess` de KUTI.js) para confirmar un pago; el navegador del comprador se puede
     * falsificar. Verifica isPaid() aquí antes de entregar un producto o servicio.
     */
    public function retrieve(string $id): PaymentIntent
    {
        $response = $this->client->request('GET', '/payment-intents/' . rawurlencode($id));

        return PaymentIntent::fromArray($response['data']);
    }
}
