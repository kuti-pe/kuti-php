<?php

declare(strict_types=1);

namespace Kuti;

/** Datos del cliente para una checkout session — todos opcionales. */
final class CheckoutSessionCustomer
{
    public function __construct(
        /** Id de un Customer ya existente (cus_…). Si viene, se ignora el resto. */
        public readonly ?string $id = null,
        public readonly ?string $externalId = null,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        /** Formato E.164 (+<código país><número>). */
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
