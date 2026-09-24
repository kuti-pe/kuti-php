<?php

declare(strict_types=1);

namespace Kuti;

final class Customer
{
    /**
     * @param array{type?: string, number?: string, country?: string}|null $document
     * @param array<string, string>|null $metadata
     * @param array<string, mixed> $customFields
     */
    public function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        public readonly string $type,
        public readonly string $createdAt,
        public readonly ?string $externalId = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $companyName = null,
        public readonly ?array $document = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?array $metadata = null,
        public readonly array $customFields = [],
        /** Only in Customers::retrieve. */
        public readonly ?int $paymentIntentsCount = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            merchantId: $data['merchant_id'],
            type: $data['type'],
            createdAt: $data['created_at'],
            externalId: $data['external_id'] ?? null,
            firstName: $data['first_name'] ?? null,
            lastName: $data['last_name'] ?? null,
            companyName: $data['company_name'] ?? null,
            document: $data['document'] ?? null,
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            metadata: $data['metadata'] ?? null,
            customFields: $data['custom_fields'] ?? [],
            paymentIntentsCount: $data['payment_intents_count'] ?? null,
        );
    }
}
