<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Lab\LabRunner;
use Illuminate\Console\Command;

/**
 * Carga de prueba concurrente + scoreboard "antes/después".
 *
 *   php artisan demo:hammer --scenario=duplicate --mode=both
 *   php artisan demo:hammer --scenario=oversell  --mode=both --workers=30
 */
class DemoHammerCommand extends Command
{
    protected $signature = 'demo:hammer {--scenario=duplicate} {--mode=both} {--workers=}';

    protected $description = 'Ejecuta la carga concurrente y muestra la pérdida económica por modo';

    public function handle(LabRunner $runner): int
    {
        $scenario = (string) $this->option('scenario');
        $workers = (int) ($this->option('workers') ?: config('demo.workers'));
        $modes = $this->option('mode') === 'both' ? ['naive', 'safe'] : [(string) $this->option('mode')];

        if (! in_array($scenario, ['duplicate', 'oversell'], true)) {
            $this->error("Escenario inválido: {$scenario} (use duplicate | oversell)");

            return self::FAILURE;
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>RechargeCore — laboratorio de concurrencia</>');
        $this->line("  escenario: <options=bold>{$scenario}</>   workers: <options=bold>{$workers}</>");
        $this->line('');

        $results = [];
        foreach ($modes as $mode) {
            $label = $mode === 'naive' ? '<fg=red;options=bold>NAIVE</>' : '<fg=green;options=bold>SAFE</>';
            $this->line("  ▶ ejecutando {$label} …");
            $results[$mode] = $runner->runOnce($scenario, $mode, $workers);
        }

        $scenario === 'duplicate'
            ? $this->renderDuplicate($results)
            : $this->renderOversell($results);

        return self::SUCCESS;
    }

    private function renderDuplicate(array $r): void
    {
        $cur = config('demo.currency');
        $money = fn (float $v) => $cur.' '.number_format($v, 2);
        $modes = array_keys($r);

        $rows = [
            ['Solicitudes (clics)', ...array_map(fn ($m) => (string) $r[$m]['clicks'], $modes)],
            ['Compras reales (intents)', ...array_map(fn ($m) => (string) $r[$m]['intents'], $modes)],
            ['Duplicadas rechazadas', ...array_map(fn ($m) => (string) $r[$m]['dedupe_rejected'], $modes)],
            ['Cargos al proveedor', ...array_map(fn ($m) => (string) $r[$m]['provider_charges'], $modes)],
            ['Cargos esperados', ...array_map(fn ($m) => (string) $r[$m]['expected_charges'], $modes)],
            ['Saldo debitado', ...array_map(fn ($m) => $money((float) $r[$m]['razer_debited']), $modes)],
            ['Debito esperado', ...array_map(fn ($m) => $money((float) $r[$m]['expected_debit']), $modes)],
        ];

        $this->line('');
        $this->table(array_merge(['Métrica'], array_map('strtoupper', $modes)), $rows);
        $this->renderLossLine($r, $money);
    }

    private function renderOversell(array $r): void
    {
        $cur = config('demo.currency');
        $money = fn (float $v) => $cur.' '.number_format($v, 2);
        $modes = array_keys($r);

        $rows = [
            ['Intentos de compra', ...array_map(fn ($m) => (string) $r[$m]['attempts'], $modes)],
            ['Financiadas (funded)', ...array_map(fn ($m) => (string) $r[$m]['funded'], $modes)],
            ['Confirmadas', ...array_map(fn ($m) => (string) $r[$m]['confirmed'], $modes)],
            ['Sobrevendidas', ...array_map(fn ($m) => (string) $r[$m]['oversold'], $modes)],
            ['Saldo interno final', ...array_map(fn ($m) => $money((float) $r[$m]['store_now']), $modes)],
            ['Saldo interno esperado', ...array_map(fn ($m) => $money((float) $r[$m]['store_expected']), $modes)],
        ];

        $this->line('');
        $this->table(array_merge(['Métrica'], array_map('strtoupper', $modes)), $rows);
        $this->renderLossLine($r, $money);
    }

    private function renderLossLine(array $r, callable $money): void
    {
        $this->line('');
        foreach ($r as $mode => $m) {
            $loss = (float) $m['loss'];
            $tag = $mode === 'naive' ? 'NAIVE' : 'SAFE ';
            if ($loss > 0.001) {
                $this->line("  <fg=red;options=bold>✗ {$tag}</>  pérdida: <fg=red;options=bold>{$money($loss)}</>   (".$m['elapsed'].'s)');
            } else {
                $this->line("  <fg=green;options=bold>✓ {$tag}</>  pérdida: <fg=green;options=bold>{$money(0)}</>   (".$m['elapsed'].'s)');
            }
        }
        $this->line('');
    }
}
