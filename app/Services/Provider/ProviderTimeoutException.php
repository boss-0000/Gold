<?php

declare(strict_types=1);

namespace App\Services\Provider;

use RuntimeException;

/**
 * El cliente no recibió respuesta a tiempo. OJO: esto NO significa que el
 * cargo no haya ocurrido en el proveedor. Reintentar a ciegas es lo que
 * provoca cargos duplicados y pérdida de saldo.
 */
class ProviderTimeoutException extends RuntimeException
{
    public function __construct(public readonly string $providerRef)
    {
        parent::__construct("Provider timeout for ref {$providerRef}");
    }
}
