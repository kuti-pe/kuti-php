<?php

declare(strict_types=1);

namespace Kuti;

/** Cliente del cobro. Si viene `$id`, se ignora el resto. */
final class PaymentIntentCustomer
{
    /**
     * @param array{type?: string, number?: string}|null $document
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $type = null,
        public readonly ?string $givenName = null,
        public readonly ?string $familyName = null,
        public readonly ?string $legalName = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $externalId = null,
        public readonly ?array $document = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = array_filter(
            [
                'id' => $this->id,
                'type' => $this->type,
                'given_name' => $this->givenName,
                'family_name' => $this->familyName,
                'legal_name' => $this->legalName,
                'email' => $this->email,
                'phone' => $this->phone,
                'external_id' => $this->externalId,
            ],
            static fn (mixed $v): bool => $v !== null && $v !== '',
        );

        if ($this->document !== null) {
            $doc = array_filter(
                [
                    'type' => $this->document['type'] ?? null,
                    'number' => $this->document['number'] ?? null,
                ],
                static fn (mixed $v): bool => $v !== null && $v !== '',
            );
            if ($doc !== []) {
                $out['document'] = $doc;
            }
        }

        return $out;
    }
}
