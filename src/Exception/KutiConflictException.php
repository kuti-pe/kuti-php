<?php

declare(strict_types=1);

namespace Kuti\Exception;

/** 409 — conflicto de estado (ej. una sesión ya usada). */
class KutiConflictException extends KutiApiException
{
}
