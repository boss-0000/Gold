<?php

declare(strict_types=1);

namespace App\Services\Provider;

use App\Models\Package;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Proveedor externo simulado (modela una API tipo Razer Gold).
 *
 * Reglas que hacen realista la demostración:
 *  - Latencia por llamada (ensancha la ventana de carrera).
 *  - Idempotencia por `provider_ref`: llamar dos veces con la MISMA ref
 *    produce UN solo cargo (como una API de pagos seria).
 *  - Inyección de timeout: con cierta probabilidad la llamada SÍ cobra en
 *    el proveedor pero devuelve timeout al cliente. Reintentar con una ref
 *    NUEVA (ruta ingenua) produce un segundo cargo real = pérdida.
 *
 * El proveedor es "correcto": debita su saldo de forma atómica. El riesgo
 * vive en cómo el CLIENTE lo usa.
 */
class MockRazerProvider
{
    /** Verifica el ID de jugador (paso "Verificar Jugador"). */
    public function verifyPlayer(string $playerId): bool
    {
        $this->latency();

        return $playerId !== ''; // en el lab, cualquier ID no vacío es válido
    }

    /**
     * Efectúa (o recupera, si es idempotente) un cargo.
     *
     * @throws ProviderTimeoutException  El cliente no recibió respuesta a tiempo (el cargo PUDO ocurrir).
     * @throws ProviderFailedException   Fallo confirmado: el cargo NO ocurrió.
     */
    public function charge(string $providerRef, string $playerId, Package $package): ChargeOutcome
    {
        $amount = (float) $package->cost;

        $this->latency();

        // 1) Idempotencia del proveedor: si esta ref ya se cobró, la devolvemos
        //    sin volver a debitar.
        if ($existing = $this->getChargeByRef($providerRef)) {
            return new ChargeOutcome($existing->provider_tx_id, (float) $existing->amount, alreadyCharged: true);
        }

        // 2) Fallo real (no se cobra): reintentar es seguro.
        if ($this->roll((float) config('demo.provider.hard_fail_rate'))) {
            throw new ProviderFailedException($providerRef);
        }

        // 3) Cargo atómico: insertar el cargo (ref única) y debitar el saldo.
        $txId = 'RZ-'.strtoupper(bin2hex(random_bytes(8)));

        try {
            DB::transaction(function () use ($providerRef, $playerId, $package, $amount, $txId): void {
                DB::table('provider_charges')->insert([
                    'provider_ref' => $providerRef,
                    'player_id' => $playerId,
                    'package_id' => $package->id,
                    'amount' => $amount,
                    'provider_tx_id' => $txId,
                    'created_at' => now(),
                ]);

                DB::table('wallets')->where('key', 'razer_master')->decrement('balance', $amount);
            });
        } catch (QueryException $e) {
            // Carrera con la MISMA ref: otro proceso ya la cobró. Idempotente.
            if ($this->isDuplicate($e) && ($existing = $this->getChargeByRef($providerRef))) {
                return new ChargeOutcome($existing->provider_tx_id, (float) $existing->amount, alreadyCharged: true);
            }
            throw $e;
        }

        // 4) El cargo YA se efectuó. Con cierta probabilidad el cliente igual
        //    recibe timeout: este es el escenario que arruina a los ingenuos.
        if ($this->roll((float) config('demo.provider.timeout_rate'))) {
            throw new ProviderTimeoutException($providerRef);
        }

        return new ChargeOutcome($txId, $amount, alreadyCharged: false);
    }

    /** Consulta el estado real de una ref (usada por la reconciliación segura). */
    public function getChargeByRef(string $providerRef): ?object
    {
        return DB::table('provider_charges')->where('provider_ref', $providerRef)->first();
    }

    private function latency(): void
    {
        $min = (int) config('demo.provider.latency_min_ms');
        $max = (int) config('demo.provider.latency_max_ms');
        usleep(random_int($min, max($min, $max)) * 1000);
    }

    private function roll(float $probability): bool
    {
        if ($probability <= 0) {
            return false;
        }

        return (random_int(1, 10_000) / 10_000) <= $probability;
    }

    private function isDuplicate(QueryException $e): bool
    {
        return $e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate');
    }
}
