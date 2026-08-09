<?php

declare(strict_types=1);

namespace App\Services\Lab;

use App\Models\Package;
use App\Models\ProviderCharge;
use App\Models\Recharge;
use App\Models\Wallet;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/**
 * Motor del laboratorio, compartido por la línea de comandos (demo:hammer) y la
 * web (RunDemoJob). Ejecuta un escenario en modo ingenuo y seguro, lanzando
 * procesos worker concurrentes reales, y devuelve las métricas de cada modo.
 */
class LabRunner
{
    /** Ejecuta ambos modos de un escenario y devuelve las métricas. */
    public function run(string $scenario, int $workers): array
    {
        return [
            'scenario' => $scenario,
            'naive' => $this->runOnce($scenario, 'naive', $workers),
            'safe' => $this->runOnce($scenario, 'safe', $workers),
        ];
    }

    public function runOnce(string $scenario, string $mode, int $workers): array
    {
        Artisan::call('demo:seed', ['--scenario' => $scenario]);

        $procs = [];
        for ($slot = 0; $slot < $workers; $slot++) {
            $p = new Process(
                [PHP_BINARY, 'artisan', 'demo:worker', "--mode={$mode}", "--workers={$workers}", "--slot={$slot}"],
                base_path()
            );
            $p->setTimeout(600);
            $p->start();
            $procs[] = $p;
        }

        $start = microtime(true);
        foreach ($procs as $p) {
            $p->wait();
        }
        $elapsed = microtime(true) - $start;

        // Reconciliación asíncrona (procesa los jobs encolados por timeouts).
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

        return $scenario === 'duplicate'
            ? $this->metricsDuplicate($mode, $elapsed)
            : $this->metricsOversell($mode, $elapsed);
    }

    public function metricsDuplicate(string $mode, float $elapsed): array
    {
        $cost = (float) Package::where('code', config('demo.scenarios.duplicate.package'))->value('cost');
        $clicks = WorkItem::where('scenario', 'duplicate')->count();
        $intents = WorkItem::where('scenario', 'duplicate')->distinct('intent_key')->count('intent_key');
        $rechargesCreated = Recharge::count();
        $providerCharges = ProviderCharge::count();
        $razerDebited = (float) config('demo.razer_initial_balance') - (float) Wallet::where('key', 'razer_master')->value('balance');
        $confirmedIntents = Recharge::where('status', 'confirmed')->distinct('intent_key')->count('intent_key');
        $expectedDebit = $confirmedIntents * $cost;

        return [
            'mode' => $mode,
            'scenario' => 'duplicate',
            'cost' => $cost,
            'clicks' => $clicks,
            'intents' => $intents,
            'dedupe_rejected' => max(0, $clicks - $rechargesCreated),
            'provider_charges' => $providerCharges,
            'expected_charges' => $confirmedIntents,
            'razer_debited' => $razerDebited,
            'expected_debit' => $expectedDebit,
            'loss' => round($razerDebited - $expectedDebit, 2),
            'elapsed' => round($elapsed, 1),
        ];
    }

    public function metricsOversell(string $mode, float $elapsed): array
    {
        $cost = (float) Package::where('code', config('demo.scenarios.oversell.package'))->value('cost');
        $funded = (int) config('demo.scenarios.oversell.funded');
        $attempts = WorkItem::where('scenario', 'oversell')->count();
        $confirmed = Recharge::where('status', 'confirmed')->count();
        $oversold = max(0, $confirmed - $funded);
        $storeInitial = $funded * $cost;
        $storeNow = (float) Wallet::where('key', 'store:LAB')->value('balance');

        return [
            'mode' => $mode,
            'scenario' => 'oversell',
            'cost' => $cost,
            'attempts' => $attempts,
            'funded' => $funded,
            'confirmed' => $confirmed,
            'oversold' => $oversold,
            'loss' => round($oversold * $cost, 2),
            'store_now' => round($storeNow, 2),
            'store_expected' => round($storeInitial - $confirmed * $cost, 2),
            'elapsed' => round($elapsed, 1),
        ];
    }
}
