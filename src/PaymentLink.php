<?php

declare(strict_types=1);

namespace Kuti;

/**
 * Link de pago: un enlace permanente que pagan muchas personas (curso, entrada, donación).
 * Cada pago es un PaymentIntent normal con paymentLinkId.
 */
final class PaymentLink
{
    /**
     * @param list<string> $suggestedAmounts
     * @param list<string> $paymentMethodTypes
     * @param list<array<string, mixed>> $customerFields Preguntas a quien paga (copia): id, key, label, type, options, required, help_text.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        public readonly bool $livemode,
        public readonly string $slug,
        /** URL pública para compartir (pay.kuti.pe/l/{slug}). */
        public readonly string $url,
        public readonly string $title,
        public readonly string $template,
        public readonly string $pricing,
        public readonly string $currency,
        public readonly string $status,
        public readonly array $paymentMethodTypes,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $description = null,
        public readonly ?string $imageUrl = null,
        public readonly ?string $amount = null,
        public readonly ?string $minAmount = null,
        public readonly ?string $maxAmount = null,
        public readonly array $suggestedAmounts = [],
        public readonly ?string $categoryId = null,
        public readonly ?string $expiresAt = null,
        public readonly array $customerFields = [],
        public readonly ?string $buttonLabel = null,
        public readonly ?string $successMessage = null,
        public readonly ?string $successButtonLabel = null,
        public readonly ?string $successButtonUrl = null,
        /** Pagos confirmados. */
        public readonly ?int $paymentsCount = null,
        /** Personas que llenaron sus datos (cobros creados desde el link). */
        public readonly ?int $checkoutsCount = null,
        /** Visitas a la página pública. */
        public readonly ?int $viewsCount = null,
        public readonly ?string $amountCollected = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            merchantId: $data['merchant_id'],
            livemode: (bool) ($data['livemode'] ?? false),
            slug: $data['slug'],
            url: $data['url'],
            title: $data['title'],
            template: $data['template'] ?? 'GENERIC',
            pricing: $data['pricing'],
            currency: $data['currency'] ?? 'PEN',
            status: $data['status'],
            paymentMethodTypes: $data['payment_method_types'] ?? [],
            createdAt: $data['created_at'],
            updatedAt: $data['updated_at'] ?? $data['created_at'],
            description: $data['description'] ?? null,
            imageUrl: $data['image_url'] ?? null,
            amount: $data['amount'] ?? null,
            minAmount: $data['min_amount'] ?? null,
            maxAmount: $data['max_amount'] ?? null,
            suggestedAmounts: $data['suggested_amounts'] ?? [],
            categoryId: $data['category_id'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            customerFields: $data['customer_fields'] ?? [],
            buttonLabel: $data['button_label'] ?? null,
            successMessage: $data['success_message'] ?? null,
            successButtonLabel: $data['success_button_label'] ?? null,
            successButtonUrl: $data['success_button_url'] ?? null,
            paymentsCount: $data['payments_count'] ?? null,
            checkoutsCount: $data['checkouts_count'] ?? null,
            viewsCount: $data['views_count'] ?? null,
            amountCollected: $data['amount_collected'] ?? null,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
