<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LedgerEntry;
use App\Models\Recharge;
use App\Models\Wallet;
use App\Services\Provider\MockRazerProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliación de una recarga que quedó en PENDING (timeout).
 *
 * Regla de oro: NUNCA se vuelve a cobrar sin confirmar antes que el primer
 * cargo no ocurrió. Se consulta al proveedor por la referencia estable:
 *  - Si el cargo existe  → se confirma (sin cobrar de nuevo).
 *  - Si no existe        → se libera la reserva y se marca fallida.
 */
class ReconcileRechargeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $rechargeId) {}

    public function handle(MockRazerProvider $provider): void
    {
        $recharge = Recharge::find($this->rechargeId);

        if (! $recharge || $recharge->status !== Recharge::STATUS_PENDING) {
            return; // ya resuelta o inexistente
        }

        $charge = $provider->getChargeByRef((string) $recharge->provider_ref);

        if ($charge) {
            // El cargo sí ocurrió: confirmamos, sin cobrar otra vez.
            $recharge->update([
                'status' => Recharge::STATUS_CONFIRMED,
                'provider_tx_id' => $charge->provider_tx_id,
            ]);

            return;
        }

        // El proveedor confirma que NO cobró: liberamos la reserva (si la hubo).
        if ($recharge->store_key) {
            DB::transaction(function () use ($recharge): void {
                $wallet = Wallet::where('key', $recharge->store_key)->lockForUpdate()->first();
                if ($wallet) {
                    $newBalance = (float) $wallet->balance + (float) $recharge->amount_cost;
                    $wallet->update(['balance' => $newBalance]);

                    LedgerEntry::create([
                        'wallet_key' => $recharge->store_key,
                        'recharge_id' => $recharge->id,
                        'type' => 'credit',
                        'amount' => (float) $recharge->amount_cost,
                        'balance_after' => $newBalance,
                        'meta' => ['stage' => 'reconcile-release'],
                        'created_at' => now(),
                    ]);
                }
            });
        }

        $recharge->update(['status' => Recharge::STATUS_REVERSED]);
    }
}
