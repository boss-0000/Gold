<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Package;
use App\Models\WorkItem;
use App\Services\Recharge\NaiveRechargeService;
use App\Services\Recharge\RechargeRequest;
use App\Services\Recharge\SafeRechargeService;
use Illuminate\Console\Command;

/**
 * Un proceso worker. Procesa la porción de work_items que le corresponde
 * (id % workers == slot), de forma que varios workers corren en paralelo y
 * compiten realmente por el saldo compartido y el proveedor.
 *
 * No se invoca a mano normalmente: demo:hammer lanza N de estos.
 */
class DemoWorkerCommand extends Command
{
    protected $signature = 'demo:worker {--mode=safe} {--workers=1} {--slot=0}';

    protected $description = 'Procesa una porción de la carga con el servicio ingenuo o seguro';

    public function handle(): int
    {
        $mode = (string) $this->option('mode');
        $workers = max(1, (int) $this->option('workers'));
        $slot = (int) $this->option('slot');

        $service = $mode === 'naive'
            ? app(NaiveRechargeService::class)
            : app(SafeRechargeService::class);

        $packages = Package::all()->keyBy('id');

        $items = WorkItem::whereRaw('MOD(id, ?) = ?', [$workers, $slot])
            ->where('processed', false)
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $package = $packages[$item->package_id];

            $req = new RechargeRequest(
                playerId: $item->player_id,
                package: $package,
                reference: $item->reference,
                intentKey: $item->intent_key,
                // La ruta ingenua NO usa clave de idempotencia; la segura sí.
                idempotencyKey: $mode === 'naive' ? null : $item->intent_key,
                storeKey: $item->store_key,
            );

            try {
                $result = $service->process($req);
                $outcome = $result->status;
            } catch (\Throwable $e) {
                $outcome = 'error:'.substr($e->getMessage(), 0, 40);
            }

            $item->update(['processed' => true, 'outcome' => $outcome]);
        }

        return self::SUCCESS;
    }
}
