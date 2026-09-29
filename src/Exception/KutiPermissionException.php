<?php

declare(strict_types=1);

namespace Kuti\Exception;

/**
 * 403 — la key es válida pero no puede hacer esto. Revisa getKutiCode():
 * INSUFFICIENT_SCOPE (a la key le falta el permiso), API_KEY_NOT_ALLOWED (endpoint solo del panel de
 * KUTI) o KYB_REQUIRED (falta el sello KUTI habilitado para producción).
 */
class KutiPermissionException extends KutiApiException
{
    /** A la API key le falta el permiso de este endpoint. */
    public function isInsufficientScope(): bool
    {
        return $this->getKutiCode() === 'INSUFFICIENT_SCOPE';
    }

    /** El endpoint es solo del panel: ninguna API key puede usarlo. */
    public function isDashboardOnly(): bool
    {
        return $this->getKutiCode() === 'API_KEY_NOT_ALLOWED';
    }
}
