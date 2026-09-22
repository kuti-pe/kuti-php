<?php

declare(strict_types=1);

namespace Kuti;

/** Customer for checkout session. If $id is set, other fields are ignored. */
final class CheckoutSessionCustomer
{
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $externalId = null,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        /** E.164 */
        public readonly ?string $phone = null,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter(
            [
                'id' => $this->id,
                'external_id' => $this->externalId,
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
            ],
            static fn (?string $v): bool => $v !== null,
        );
    }
}
