<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Run;
use App\Services\Lab\LabRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Orquesta una corrida disparada desde la web: ejecuta el escenario en modo
 * ingenuo y seguro (vía LabRunner) y persiste las métricas en la fila `runs`,
 * que la página consulta por polling.
 */
class RunDemoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(public int $runId, public string $scenario, public int $workers) {}

    public function handle(LabRunner $runner): void
    {
        $run = Run::find($this->runId);
        if (! $run) {
            return;
        }

        $run->update(['status' => Run::STATUS_RUNNING, 'started_at' => now()]);

        try {
            $results = $runner->run($this->scenario, $this->workers);

            $run->update([
                'status' => Run::STATUS_DONE,
                'result_naive' => $results['naive'],
                'result_safe' => $results['safe'],
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => Run::STATUS_ERROR,
                'error' => substr($e->getMessage(), 0, 250),
                'finished_at' => now(),
            ]);
        }
    }
}
