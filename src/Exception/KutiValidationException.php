<?php

declare(strict_types=1);

namespace Kuti\Exception;

/** 400 / 422 — el request no pasó validación. Revisa getDetails() para el campo exacto. */
class KutiValidationException extends KutiApiException
{
}
