<?php

declare(strict_types=1);

namespace Kuti\Exception;

/** 403 — la key es válida pero no tiene permiso para esta operación. */
class KutiPermissionException extends KutiApiException
{
}
