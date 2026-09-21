<?php

declare(strict_types=1);

namespace Kuti\Exception;

/** 429 — demasiadas requests. El SDK ya reintenta automáticamente antes de lanzar esto. */
class KutiRateLimitException extends KutiApiException
{
}
