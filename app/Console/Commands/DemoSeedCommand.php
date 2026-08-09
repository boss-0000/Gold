<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Package;
use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reinicia el laboratorio y genera la carga de un escenario.
 *
 *   php artisan demo:seed --scenario=duplicate
 *   php artisan demo:seed --scenario=oversell
 */
class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed {--scenario=duplicate}';

    protected $description = 'Reinicia el estado y genera work_items para un escenario del lab';

    public function handle(): int
    {
        $scenario = (string) $this->option('scenario');

        foreach (['recharges', 'ledger_entries', 'provider_charges', 'work_items', 'wallets', 'packages'] as $t) {
            DB::table($t)->truncate();
        }

        // Paquetes
        $packages = [];
        foreach (config('demo.packages') as $p) {
            $packages[$p['code']] = Package::create($p);
        }

        // Saldo prepago del proveedor (saldo compartido).
        Wallet::create([
            'key' => 'razer_master',
            'label' => 'Saldo prepago proveedor',
            'balance' => (float) config('demo.razer_initial_balance'),
        ]);

        $rows = [];
        $seq = 0;
        $now = now();

        if ($scenario === 'duplicate') {
            $cfg = config('demo.scenarios.duplicate');
            $pkg = $packages[$cfg['package']];
            for ($g = 1; $g <= $cfg['intents']; $g++) {
                $intentKey = 'INT-'.str_pad((string) $g, 5, '0', STR_PAD_LEFT);
                $player = (string) random_int(1_000_000_000, 9_999_999_999);
                $clicks = random_int($cfg['clicks_min'], $cfg['clicks_max']);
                for ($c = 1; $c <= $clicks; $c++) {
                    $seq++;
                    $rows[] = [
                        'scenario' => 'duplicate',
                        'intent_key' => $intentKey,
                        'reference' => 'RGRH-2026-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
                        'store_key' => null,
                        'player_id' => $player,
                        'package_id' => $pkg->id,
                        'processed' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        } elseif ($scenario === 'oversell') {
            $cfg = config('demo.scenarios.oversell');
            $pkg = $packages[$cfg['package']];
            $cost = (float) $pkg->cost;

            // Saldo interno financiado para EXACTAMENTE `funded` recargas.
            Wallet::create([
                'key' => 'store:LAB',
                'label' => 'Saldo interno tienda LAB',
                'balance' => $cfg['funded'] * $cost,
            ]);

            for ($i = 1; $i <= $cfg['intents']; $i++) {
                $seq++;
                $rows[] = [
                    'scenario' => 'oversell',
                    'intent_key' => 'INT-OVS-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                    'reference' => 'RGRH-2026-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
                    'store_key' => 'store:LAB',
                    'player_id' => (string) random_int(1_000_000_000, 9_999_999_999),
                    'package_id' => $pkg->id,
                    'processed' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        } else {
            $this->error("Escenario desconocido: {$scenario}");

            return self::FAILURE;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('work_items')->insert($chunk);
        }

        $this->info("Sembrado escenario '{$scenario}': ".count($rows).' work_items.');

        return self::SUCCESS;
    }
}
