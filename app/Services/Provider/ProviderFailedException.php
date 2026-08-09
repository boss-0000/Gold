<?php

declare(strict_types=1);

namespace App\Services\Provider;

use RuntimeException;

/**
 * Fallo real y confirmado del proveedor: el cargo NO se efectuó.
 * Aquí sí es seguro reintentar.
 */
class ProviderFailedException extends RuntimeException
{
    public function __construct(public readonly string $providerRef)
    {
        parent::__construct("Provider hard failure for ref {$providerRef}");
    }
}
