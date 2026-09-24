<?php

declare(strict_types=1);

namespace Kuti\Resource;

use Kuti\CheckoutSession;
use Kuti\CustomerInput;
use Kuti\KutiClient;
use Kuti\Money;
use Kuti\PaymentMethodType;

final class CheckoutSessions
{
    public function __construct(private readonly KutiClient $client)
    {
    }

    /**
     * POST /checkout-sessions. Pass $idempotencyKey to safely retry.
     *
     * @param PaymentMethodType[] $paymentMethodTypes
     * @param array<string, string>|null $metadata
     */
    public function create(
        Money $amount,
        array $paymentMethodTypes,
        ?CustomerInput $customer = null,
        ?string $description = null,
        ?string $externalReference = null,
        ?string $successUrl = null,
        ?string $expiresAt = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
    ): CheckoutSession {
        $body = array_filter(
            [
                'amount' => $amount->toArray(),
                'payment_method_types' => array_map(
                    static fn (PaymentMethodType $t): string => $t->value,
                    $paymentMethodTypes,
                ),
                'customer' => $customer?->toArray(),
                'description' => $description,
                'external_reference' => $externalReference,
                'success_url' => $successUrl,
                'expires_at' => $expiresAt,
                'metadata' => $metadata,
            ],
            static fn (mixed $v): bool => $v !== null && $v !== [],
        );

        $response = $this->client->request('POST', '/checkout-sessions', $body, $idempotencyKey);

        return CheckoutSession::fromArray($response['data']);
    }
}
