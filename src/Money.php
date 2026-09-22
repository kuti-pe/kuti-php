<?php

declare(strict_types=1);

namespace Kuti;

/** Decimal string — never float. Currency: PEN only for now. */
final class Money
{
    public function __construct(
        public readonly string $amount,
        public readonly string $currency = 'PEN',
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
