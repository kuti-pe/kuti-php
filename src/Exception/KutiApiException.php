<?php

declare(strict_types=1);

namespace Kuti\Exception;

use RuntimeException;
use Throwable;

/**
 * Error base de la API — cualquier respuesta HTTP != 2xx de payments-core lanza esto o una subclase.
 */
class KutiApiException extends RuntimeException
{
    /** @param array<int, array{field?: string, code?: string, message?: string}> $details */
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $kutiCode,
        private readonly ?string $requestId = null,
        private readonly ?string $docUrl = null,
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getKutiCode(): string
    {
        return $this->kutiCode;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getDocUrl(): ?string
    {
        return $this->docUrl;
    }

    /** @return array<int, array{field?: string, code?: string, message?: string}> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public static function forStatus(
        int $status,
        string $message,
        string $kutiCode,
        ?string $requestId,
        ?string $docUrl,
        array $details,
    ): self {
        $class = match ($status) {
            401 => KutiAuthenticationException::class,
            403 => KutiPermissionException::class,
            404 => KutiNotFoundException::class,
            400, 422 => KutiValidationException::class,
            409 => KutiConflictException::class,
            429 => KutiRateLimitException::class,
            default => self::class,
        };
        return new $class($message, $status, $kutiCode, $requestId, $docUrl, $details);
    }
}
