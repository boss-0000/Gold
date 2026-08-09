<?php

declare(strict_types=1);

namespace App\Services\Recharge;

use App\Jobs\ReconcileRechargeJob;
use App\Models\LedgerEntry;
use App\Models\Recharge;
use App\Models\Wallet;
use App\Services\Provider\MockRazerProvider;
use App\Services\Provider\ProviderFailedException;
use App\Services\Provider\ProviderTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Implementación SEGURA — el "después".
 *
 * Capas de defensa (cada una mapea a un punto del pliego):
 *
 *  1. IDEMPOTENCIA  — se persiste la clave ANTES de llamar al proveedor con un
 *     índice UNIQUE. Un segundo clic/reintento con la misma clave devuelve el
 *     resultado previo, sin volver a cobrar.  [idempotencia, duplicidad, botón]
 *
 *  2. RESERVA ATÓMICA con SELECT … FOR UPDATE — el saldo interno se descuenta
 *     bajo bloqueo de fila, brevísimo, SIN llamar al proveedor dentro del
 *     bloqueo. Evita la sobreventa por condición de carrera.  [race conditions]
 *
 *  3. REFERENCIA ESTABLE hacia el proveedor derivada de la clave idempotente:
 *     aunque se llame dos veces, el proveedor cobra una sola vez.  [duplicidad]
 *
 *  4. TIMEOUT → estado PENDING + reconciliación asíncrona; NUNCA se reintenta a
 *     ciegas. Un job verifica el estado real por referencia antes de decidir.
 *     [timeouts, reintentos, pérdidas de saldo]
 *
 *  5. LIBRO MAYOR append-only para cada movimiento del saldo interno.
 *     [consistencia de datos, auditoría]
 */
class SafeRechargeService
{
    public function __construct(private readonly MockRazerProvider $provider) {}

    public function process(RechargeRequest $req): RechargeResult
    {
        $cost = (float) $req->package->cost;
        $providerRef = $this->stableProviderRef($req->idempotencyKey ?? $req->intentKey);

        // 1) IDEMPOTENCIA — insertar con clave única ANTES de tocar al proveedor.
        try {
            $recharge = Recharge::create([
                'reference' => $req->reference,
                'idempotency_key' => $req->idempotencyKey ?? $req->intentKey,
                'intent_key' => $req->intentKey,
                'store_key' => $req->storeKey,
                'player_id' => $req->playerId,
                'package_id' => $req->package->id,
                'amount_cost' => $cost,
                'amount_price' => (float) $req->package->price,
                'status' => Recharge::STATUS_PENDING,
                'provider_ref' => $providerRef,
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                // Solicitud duplicada: devolvemos el resultado existente. No se cobra otra vez.
                $existing = Recharge::where('idempotency_key', $req->idempotencyKey ?? $req->intentKey)->first();

                return RechargeResult::deduped($existing->reference, $existing->status, $existing->provider_tx_id);
            }
            throw $e;
        }

        // 2) RESERVA ATÓMICA del saldo interno (sólo si aplica un saldo de tienda).
        if ($req->storeKey) {
            $reserved = $this->reserve($req->storeKey, $cost, $recharge->id);
            if (! $reserved) {
                $recharge->update(['status' => Recharge::STATUS_FAILED, 'error' => 'saldo insuficiente']);

                return RechargeResult::failed($req->reference, 'saldo insuficiente');
            }
        }

        // 3) y 4) Un solo cargo con ref estable; el timeout NO se reintenta.
        try {
            $outcome = $this->provider->charge($providerRef, $req->playerId, $req->package);

            $recharge->update([
                'status' => Recharge::STATUS_CONFIRMED,
                'provider_tx_id' => $outcome->providerTxId,
                'attempts' => DB::raw('attempts + 1'),
            ]);

            return RechargeResult::confirmed($req->reference, $outcome->providerTxId);
        } catch (ProviderTimeoutException) {
            // No sabemos si cobró. NO reintentamos: dejamos PENDING y reconciliamos.
            $recharge->update(['status' => Recharge::STATUS_PENDING, 'attempts' => DB::raw('attempts + 1'), 'error' => 'timeout']);
            ReconcileRechargeJob::dispatch($recharge->id);

            return RechargeResult::pending($req->reference, 'timeout: en reconciliación');
        } catch (ProviderFailedException) {
            // Fallo confirmado: liberamos la reserva (si la hubo).
            if ($req->storeKey) {
                $this->release($req->storeKey, $cost, $recharge->id);
            }
            $recharge->update(['status' => Recharge::STATUS_FAILED, 'attempts' => DB::raw('attempts + 1'), 'error' => 'fallo proveedor']);

            return RechargeResult::failed($req->reference, 'fallo proveedor');
        }
    }

    /** Reserva atómica bajo bloqueo de fila. Devuelve false si no hay saldo. */
    private function reserve(string $storeKey, float $cost, int $rechargeId): bool
    {
        return (bool) DB::transaction(function () use ($storeKey, $cost, $rechargeId) {
            $wallet = Wallet::where('key', $storeKey)->lockForUpdate()->first(); // SELECT … FOR UPDATE

            if (! $wallet || (float) $wallet->balance < $cost) {
                return false;
            }

            $newBalance = (float) $wallet->balance - $cost;
            $wallet->update(['balance' => $newBalance]);

            LedgerEntry::create([
                'wallet_key' => $storeKey,
                'recharge_id' => $rechargeId,
                'type' => 'debit',
                'amount' => $cost,
                'balance_after' => $newBalance,
                'meta' => ['stage' => 'reserve'],
                'created_at' => now(),
            ]);

            return true;
        });
    }

    /** Reversa de la reserva (crédito) cuando el proveedor confirma que no cobró. */
    private function release(string $storeKey, float $cost, int $rechargeId): void
    {
        DB::transaction(function () use ($storeKey, $cost, $rechargeId): void {
            $wallet = Wallet::where('key', $storeKey)->lockForUpdate()->first();
            $newBalance = (float) $wallet->balance + $cost;
            $wallet->update(['balance' => $newBalance]);

            LedgerEntry::create([
                'wallet_key' => $storeKey,
                'recharge_id' => $rechargeId,
                'type' => 'credit',
                'amount' => $cost,
                'balance_after' => $newBalance,
                'meta' => ['stage' => 'release'],
                'created_at' => now(),
            ]);
        });
    }

    private function stableProviderRef(string $key): string
    {
        return 'PR-'.strtoupper(substr(hash('sha1', $key), 0, 16));
    }

    private function isDuplicate(QueryException $e): bool
    {
        return $e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate');
    }
}
