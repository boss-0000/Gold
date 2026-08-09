<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RunDemoJob;
use App\Models\Run;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DemoController extends Controller
{
    /** Página del laboratorio. */
    public function index(): View
    {
        return view('demo', [
            'currency' => config('demo.currency'),
            'razerBalance' => (float) config('demo.razer_initial_balance'),
            'duplicate' => config('demo.scenarios.duplicate'),
            'oversell' => config('demo.scenarios.oversell'),
            'packages' => config('demo.packages'),
            'lastRun' => Run::latest('id')->first(),
            'repoUrl' => config('demo.repo_url'),
        ]);
    }

    /** Dispara una corrida (ambos modos). Protegida por rate-limit + candado. */
    public function run(Request $request): JsonResponse
    {
        $scenario = (string) $request->input('scenario', 'duplicate');
        if (! in_array($scenario, ['duplicate', 'oversell'], true)) {
            return response()->json(['error' => 'escenario inválido'], 422);
        }

        // Candado: una sola corrida activa a la vez. Si hay una en curso,
        // devolvemos su id para que la página siga su progreso.
        $active = Run::where('status', Run::STATUS_RUNNING)
            ->where('created_at', '>', now()->subMinutes(3))
            ->latest('id')
            ->first();

        if ($active) {
            return response()->json(['run_id' => $active->id, 'busy' => true]);
        }

        $workers = (int) config('demo.web_workers');

        $run = Run::create([
            'scenario' => $scenario,
            'status' => Run::STATUS_RUNNING,
            'workers' => $workers,
        ]);

        RunDemoJob::dispatch($run->id, $scenario, $workers)->onQueue('demo');

        return response()->json(['run_id' => $run->id, 'busy' => false]);
    }

    /** Estado + métricas de una corrida (polling). */
    public function status(Run $run): JsonResponse
    {
        return response()->json([
            'id' => $run->id,
            'scenario' => $run->scenario,
            'status' => $run->status,
            'naive' => $run->result_naive,
            'safe' => $run->result_safe,
            'error' => $run->error,
        ]);
    }
}
