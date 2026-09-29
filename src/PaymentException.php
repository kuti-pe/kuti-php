<?php

declare(strict_types=1);

namespace Kuti;

/**
 * Un pago que entró pero no correspondía (pagaron dos veces, un cobro anulado o fallido, una cuota ya
 * pagada, otro monto). El dinero ya está en tu saldo; decide si lo devuelves o lo aplicas.
 *
 * reason: DUPLICATE | ON_CANCELLED | ON_FAILED | RECEIVABLE_ALREADY_PAID | AMOUNT_MISMATCH
 * status: OPEN | REFUNDED | APPLIED | DISMISSED
 */
final class PaymentException
{
    public function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        public readonly bool $livemode,
        public readonly string $paymentIntentId,
        public readonly Money $amount,
        public readonly string $reason,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly ?string $paymentMethodType = null,
        public readonly ?string $balanceTransactionId = null,
        public readonly ?string $resolutionNote = null,
        public readonly ?string $resolvedAt = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            merchantId: $data['merchant_id'],
            livemode: (bool) $data['livemode'],
            paymentIntentId: $data['payment_intent_id'],
            amount: Money::fromArray($data['amount']),
            reason: $data['reason'],
            status: $data['status'],
            createdAt: $data['created_at'],
            paymentMethodType: $data['payment_method_type'] ?? null,
            balanceTransactionId: $data['balance_transaction_id'] ?? null,
            resolutionNote: $data['resolution_note'] ?? null,
            resolvedAt: $data['resolved_at'] ?? null,
        );
    }
}
