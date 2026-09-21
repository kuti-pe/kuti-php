<?php

declare(strict_types=1);

namespace Kuti\Exception;

use RuntimeException;
use Throwable;

/** Fallo de red o timeout — nunca llegó a haber una respuesta HTTP de KUTI. */
class KutiConnectionException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
