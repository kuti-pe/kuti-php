<?php

declare(strict_types=1);

namespace Kuti;

/**
 * Suscripción: KUTI le cobra solo a tu cliente, cada periodo, sobre su Yape afiliado. Cada periodo
 * es un PaymentIntent normal.
 *
 * status: INCOMPLETE (falta que el cliente afilie su Yape; en monto fijo, además pagar el primer
 * periodo) | ACTIVE | PAST_DUE (hay un periodo sin pagar) | PAUSED | CANCELLED | COMPLETED.
 */
final class Subscription
{
    /**
     * @param array{id: string, name?: ?string, email?: ?string, phone?: ?string}|null $customer
     * @param list<array{description: ?string, unitAmount: string, quantity: ?int, amount: ?string}> $items
     * @param array{id: string, type: ?string, phoneLast4: ?string, status: ?string}|null $paymentMethod
     * @param array{intervalDays: list<int>, onExhausted: string}|null $retryPolicy
     * @param array<string, mixed>|null $latestCycle Periodo más reciente: id, billingPeriod, dueDate,
     *     amount (Money|null), status (AWAITING_AMOUNT | OPEN | PROCESSING | PAID | UNCOLLECTIBLE |
     *     SKIPPED), attempts, lastFailureCode, nextAttemptAt, paymentIntentId, checkoutUrl, paidAt.
     * @param array<string, string>|null $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        public readonly string $status,
        /** fixed | variable (tú envías el monto de cada periodo). */
        public readonly string $billingMode,
        public readonly string $description,
        public readonly string $frequency,
        public readonly int $interval,
        public readonly string $startDate,
        public readonly string $createdAt,
        /** null en monto variable. */
        public readonly ?Money $amount = null,
        /** Monto variable e INCOMPLETE: enlace para que el cliente afilie su Yape sin pagar. */
        public readonly ?string $setupUrl = null,
        public readonly ?array $customer = null,
        public readonly array $items = [],
        /** Hora de cobro, hora de Perú (HH:mm). */
        public readonly ?string $chargeTime = null,
        public readonly ?string $nextChargeAt = null,
        public readonly ?string $endDate = null,
        public readonly ?array $paymentMethod = null,
        public readonly ?array $retryPolicy = null,
        public readonly ?array $latestCycle = null,
        public readonly ?string $externalReference = null,
        public readonly ?array $metadata = null,
        public readonly ?bool $livemode = null,
        public readonly ?string $cancelledAt = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $pm = $data['payment_method'] ?? null;
        $retry = $data['retry_policy'] ?? null;

        return new self(
            id: $data['id'],
            merchantId: $data['merchant_id'],
            status: $data['status'],
            billingMode: $data['billing_mode'] ?? 'fixed',
            description: $data['description'],
            frequency: $data['frequency'],
            interval: (int) ($data['interval'] ?? 1),
            startDate: $data['start_date'],
            createdAt: $data['created_at'],
            amount: isset($data['amount']) && is_array($data['amount']) ? Money::fromArray($data['amount']) : null,
            setupUrl: $data['setup_url'] ?? null,
            customer: isset($data['customer']) && is_array($data['customer']) ? $data['customer'] : null,
            items: array_map(
                static fn (array $i): array => [
                    'description' => $i['description'] ?? null,
                    'unitAmount' => (string) $i['unit_amount'],
                    'quantity' => isset($i['quantity']) ? (int) $i['quantity'] : null,
                    'amount' => $i['amount'] ?? null,
                ],
                $data['items'] ?? [],
            ),
            chargeTime: $data['charge_time'] ?? null,
            nextChargeAt: $data['next_charge_at'] ?? null,
            endDate: $data['end_date'] ?? null,
            paymentMethod: is_array($pm) ? [
                'id' => $pm['id'],
                'type' => $pm['type'] ?? null,
                'phoneLast4' => $pm['phone_last4'] ?? null,
                'status' => $pm['status'] ?? null,
            ] : null,
            retryPolicy: is_array($retry) ? [
                'intervalDays' => array_map('intval', $retry['interval_days'] ?? []),
                'onExhausted' => $retry['on_exhausted'] ?? 'past_due',
            ] : null,
            latestCycle: isset($data['latest_cycle']) && is_array($data['latest_cycle'])
                ? self::cycleFromArray($data['latest_cycle']) : null,
            externalReference: $data['external_reference'] ?? null,
            metadata: $data['metadata'] ?? null,
            livemode: $data['livemode'] ?? null,
            cancelledAt: $data['cancelled_at'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    public static function cycleFromArray(array $c): array
    {
        return [
            'id' => $c['id'],
            'billingPeriod' => $c['billing_period'],
            'dueDate' => $c['due_date'],
            'amount' => isset($c['amount']) && is_array($c['amount']) ? Money::fromArray($c['amount']) : null,
            'status' => $c['status'],
            'attempts' => (int) ($c['attempts'] ?? 0),
            'lastFailureCode' => $c['last_failure_code'] ?? null,
            'nextAttemptAt' => $c['next_attempt_at'] ?? null,
            'paymentIntentId' => $c['payment_intent_id'] ?? null,
            'checkoutUrl' => $c['checkout_url'] ?? null,
            'paidAt' => $c['paid_at'] ?? null,
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
