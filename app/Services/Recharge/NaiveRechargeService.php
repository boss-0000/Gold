<?php

declare(strict_types=1);

namespace App\Services\Recharge;

use App\Models\Recharge;
use App\Models\Wallet;
use App\Services\Provider\MockRazerProvider;
use App\Services\Provider\ProviderFailedException;
use App\Services\Provider\ProviderTimeoutException;

/**
 * Implementación INGENUA — representa lo que suele existir hoy.
 *
 * Fallos deliberados (todos causan pérdida económica bajo concurrencia):
 *
 *  1. Sin idempotencia: cada clic/solicitud crea una recarga y un cargo nuevos.
 *  2. Reintento a ciegas ante timeout, con una referencia NUEVA cada vez → el
 *     proveedor cobra otra vez (el primer cargo sí había ocurrido).
 *  3. Débito del saldo interno con lectura-modificación-escritura SIN bloqueo
 *     → actualizaciones perdidas → sobreventa (saldo por debajo de lo real).
 *
 * NO USAR EN PRODUCCIÓN. Es el "antes".
 */
class NaiveRechargeService
{
    public function __construct(private readonly MockRazerProvider $provider) {}

    public function process(RechargeRequest $req): RechargeResult
    {
        $cost = (float) $req->package->cost;

        // Se crea la recarga sin ninguna verificación de duplicidad.
        $recharge = Recharge::create([
            'reference' => $req->reference,
            'idempotency_key' => null,           // <-- el problema
            'intent_key' => $req->intentKey,     // sólo para medición
            'store_key' => $req->storeKey,
            'player_id' => $req->playerId,
            'package_id' => $req->package->id,
            'amount_cost' => $cost,
            'amount_price' => (float) $req->package->price,
            'status' => Recharge::STATUS_PENDING,
        ]);

        // (Escenario sobreventa) Verificación de saldo con lectura previa.
        // La lectura y la escritura no están protegidas: dos procesos leen el
        // mismo saldo y ambos escriben → se pierde un débito.
        $wallet = null;
        if ($req->storeKey) {
            $wallet = Wallet::where('key', $req->storeKey)->first();
            if (! $wallet || (float) $wallet->balance < $cost) {
                $recharge->update(['status' => Recharge::STATUS_FAILED, 'error' => 'saldo insuficiente']);

                return RechargeResult::failed($req->reference, 'saldo insuficiente');
            }
        }

        $attempt = 0;
        $maxRetries = (int) config('demo.naive.max_retries');
        $providerRef = $req->reference; // sin ref estable

        while (true) {
            $attempt++;
            try {
                $outcome = $this->provider->charge($providerRef, $req->playerId, $req->package);

                // Débito ingenuo: lectura-modificación-escritura (carrera).
                if ($wallet) {
                    $wallet->balance = (float) $wallet->balance - $cost;
                    $wallet->save(); // sobrescribe cambios concurrentes = actualización perdida
                }

                $recharge->update([
                    'status' => Recharge::STATUS_CONFIRMED,
                    'provider_ref' => $providerRef,
                    'provider_tx_id' => $outcome->providerTxId,
                    'attempts' => $attempt,
                ]);

                return RechargeResult::confirmed($req->reference, $outcome->providerTxId);
            } catch (ProviderTimeoutException) {
                if ($attempt <= $maxRetries) {
                    // Reintento a ciegas con ref NUEVA → el proveedor cobra de nuevo.
                    $providerRef = $req->reference.'-r'.$attempt;
                    continue;
                }
                // Se rinde: pero uno o más cargos ya ocurrieron en el proveedor.
                $recharge->update(['status' => Recharge::STATUS_PENDING, 'attempts' => $attempt, 'error' => 'timeout']);

                return RechargeResult::pending($req->reference, 'timeout tras reintentos');
            } catch (ProviderFailedException) {
                $recharge->update(['status' => Recharge::STATUS_FAILED, 'attempts' => $attempt, 'error' => 'fallo proveedor']);

                return RechargeResult::failed($req->reference, 'fallo proveedor');
            }
        }
    }
}
