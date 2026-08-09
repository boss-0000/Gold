<?php

declare(strict_types=1);

namespace App\Services\Recharge;

use App\Models\Package;

/**
 * Una solicitud de recarga.
 *
 * - `intentKey`  identifica la COMPRA lógica (varios clics comparten intentKey).
 * - `reference`  es única por intento/clic.
 * - `idempotencyKey` sólo lo usa la ruta segura para deduplicar.
 */
final readonly class RechargeRequest
{
    public function __construct(
        public string $playerId,
        public Package $package,
        public string $reference,
        public string $intentKey,
        public ?string $idempotencyKey = null,
        public ?string $storeKey = null,
    ) {}
}
