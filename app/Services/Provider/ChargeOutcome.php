<?php

declare(strict_types=1);

namespace App\Services\Provider;

/**
 * Resultado exitoso de un cargo en el proveedor.
 */
final readonly class ChargeOutcome
{
    public function __construct(
        public string $providerTxId,
        public float $amount,
        /** true si la ref ya había sido cobrada antes (idempotencia del proveedor). */
        public bool $alreadyCharged,
    ) {}
}
