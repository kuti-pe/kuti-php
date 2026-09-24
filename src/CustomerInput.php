<?php

declare(strict_types=1);

namespace Kuti;

/**
 * Customer data sent inline (payment intent, checkout session) — same shape as Customers::create.
 * An existing customer is reused by id → externalId → document; otherwise a new one is created.
 */
class CustomerInput
{
    /**
     * @param array{type?: string, number: string, country?: string}|null $document
     *        type is optional: inferred from the number (8 digits = DNI, 11 digits 10/15/17/20… = RUC).
     * @param array<string, mixed>|null $customFields custom fields defined by the merchant
     *        (Settings → Customers → Fields), keyed by field key.
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $type = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        /** Legal name, only for type COMPANY. */
        public readonly ?string $companyName = null,
        public readonly ?string $email = null,
        /** E.164 */
        public readonly ?string $phone = null,
        public readonly ?string $externalId = null,
        public readonly ?array $document = null,
        public readonly ?array $customFields = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = array_filter(
            [
                'id' => $this->id,
                'type' => $this->type,
                'first_name' => $this->firstName,
                'last_name' => $this->lastName,
                'company_name' => $this->companyName,
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
                    'country' => $this->document['country'] ?? null,
                ],
                static fn (mixed $v): bool => $v !== null && $v !== '',
            );
            if ($doc !== []) {
                $out['document'] = $doc;
            }
        }

        // Tal cual: un null en la edición significa "borrar ese valor".
        if ($this->customFields !== null && $this->customFields !== []) {
            $out['custom_fields'] = $this->customFields;
        }

        return $out;
    }
}
