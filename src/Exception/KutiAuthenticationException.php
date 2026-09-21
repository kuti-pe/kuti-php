<?php

declare(strict_types=1);

namespace Kuti\Exception;

/** 401 — la secret key es inválida, está revocada, o falta. */
class KutiAuthenticationException extends KutiApiException
{
}
