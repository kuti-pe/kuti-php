<?php

declare(strict_types=1);

namespace Kuti;

/** Decimal como string — nunca float, para no perder precisión con dinero. */
final class Money
{
    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
    ) {
    }

    /** @param array{amount: string, currency: string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['amount'], $data['currency']);
    }

    /** @return array{amount: string, currency: string} */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }
}
