<?php

declare(strict_types=1);

namespace Kuti;

final class PaymentIntent
{
    /**
     * @param array<string, string>|null $metadata
     * @param array{methodType?: string, paidAt?: string}|null $paidWith
     */
    public function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        public readonly Money $amount,
        public readonly PaymentIntentStatus $status,
        public readonly string $createdAt,
        public readonly ?bool $livemode = null,
        public readonly ?string $customerId = null,
        public readonly ?string $externalReference = null,
        public readonly ?string $description = null,
        public readonly ?string $categoryId = null,
        public readonly ?array $metadata = null,
        public readonly ?bool $requiresCustomerInfo = null,
        public readonly ?string $checkoutUrl = null,
        public readonly ?string $expiresAt = null,
        public readonly ?array $paidWith = null,
        /**
         * Customer frozen on the payment intent: id, type, first_name, last_name, company_name,
         * name, document {type, number, country}, email, custom_fields.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $customer = null,
        /** Link de pago del que salió este cobro (plink_…), si aplica. */
        public readonly ?string $paymentLinkId = null,
        /**
         * Canales por los que se envió el cobro al crearlo (EMAIL, WHATSAPP).
         *
         * @var list<string>|null
         */
        public readonly ?array $sendVia = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $customerId = null;
        if (isset($data['customer']) && is_array($data['customer']) && isset($data['customer']['id'])) {
            $customerId = (string) $data['customer']['id'];
        } elseif (isset($data['customer_id'])) {
            $customerId = (string) $data['customer_id'];
        }

        $paidWith = null;
        if (isset($data['paid_with']) && is_array($data['paid_with'])) {
            $paidWith = array_filter(
                [
                    'methodType' => $data['paid_with']['method_type'] ?? null,
                    'paidAt' => $data['paid_with']['paid_at'] ?? null,
                ],
                static fn (mixed $v): bool => $v !== null,
            );
            if ($paidWith === []) {
                $paidWith = null;
            }
        }

        return new self(
            id: $data['id'],
            merchantId: $data['merchant_id'],
            amount: Money::fromArray($data['amount']),
            status: PaymentIntentStatus::from($data['status']),
            createdAt: $data['created_at'],
            livemode: $data['livemode'] ?? null,
            customerId: $customerId,
            externalReference: $data['external_reference'] ?? null,
            description: $data['description'] ?? null,
            categoryId: $data['category_id'] ?? null,
            metadata: $data['metadata'] ?? null,
            requiresCustomerInfo: $data['requires_customer_info'] ?? null,
            checkoutUrl: $data['checkout_url'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            paidWith: $paidWith,
            customer: isset($data['customer']) && is_array($data['customer']) ? $data['customer'] : null,
            paymentLinkId: $data['payment_link_id'] ?? null,
            sendVia: $data['send_via'] ?? null,
        );
    }

    /** True when status is SUCCEEDED. */
    public function isPaid(): bool
    {
        return $this->status === PaymentIntentStatus::Succeeded;
    }
}
