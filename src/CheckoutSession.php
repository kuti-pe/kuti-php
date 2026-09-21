<?php

declare(strict_types=1);

namespace Kuti;

final class CheckoutSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        public readonly string $paymentIntentId,
        public readonly Money $amount,
        public readonly CheckoutSessionStatus $status,
        public readonly string $createdAt,
        public readonly ?string $customerId = null,
        public readonly ?string $description = null,
        public readonly ?string $successUrl = null,
        /** Token de un solo recurso — vive corto, no expone credenciales. */
        public readonly ?string $clientSecret = null,
        /** Pásala directo al frontend para abrir con KUTI.js: Kuti.open({ checkoutUrl }). */
        public readonly ?string $checkoutUrl = null,
        public readonly ?string $expiresAt = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            merchantId: $data['merchant_id'],
            paymentIntentId: $data['payment_intent_id'],
            amount: Money::fromArray($data['amount']),
            status: CheckoutSessionStatus::from($data['status']),
            createdAt: $data['created_at'],
            customerId: $data['customer_id'] ?? null,
            description: $data['description'] ?? null,
            successUrl: $data['success_url'] ?? null,
            clientSecret: $data['client_secret'] ?? null,
            checkoutUrl: $data['checkout_url'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
        );
    }
}
