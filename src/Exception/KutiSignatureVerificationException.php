<?php

declare(strict_types=1);

namespace Kuti\Exception;

use RuntimeException;

/** La firma de un webhook no coincide, o el timestamp está fuera de tolerancia. */
class KutiSignatureVerificationException extends RuntimeException
{
}
