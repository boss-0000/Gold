<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Package;
use App\Models\ProviderCharge;
use App\Models\Recharge;
use App\Models\Wallet;
use App\Services\Recharge\NaiveRechargeService;
use App\Services\Recharge\RechargeRequest;
use App\Services\Recharge\SafeRechargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConcurrencySafetyTest extends TestCase
{
    use RefreshDatabase;

    private Package $pkg;

    protected function setUp(): void
    {
        parent::setUp();

        // Comportamiento determinista del proveedor para los tests.
        config([
            'demo.provider.timeout_rate' => 0.0,
            'demo.provider.hard_fail_rate' => 0.0,
            'demo.provider.latency_min_ms' => 0,
            'demo.provider.latency_max_ms' => 0,
        ]);

        Wallet::create(['key' => 'razer_master', 'label' => 'proveedor', 'balance' => 1_000_000]);
        $this->pkg = Package::create(['code' => 'FF-310', 'name' => '310 + 31 Bonos', 'cost' => 32.00, 'price' => 35.00]);
    }

    private function request(string $intent, string $reference, ?string $storeKey = null, bool $safe = true): RechargeRequest
    {
        return new RechargeRequest(
            playerId: '1385228088',
            package: $this->pkg,
            reference: $reference,
            intentKey: $intent,
            idempotencyKey: $safe ? $intent : null,
            storeKey: $storeKey,
        );
    }

    public function test_safe_dedupes_duplicate_requests_and_charges_once(): void
    {
        $safe = app(SafeRechargeService::class);

        // Mismo intento (doble clic), referencias distintas.
        $first = $safe->process($this->request('INT-1', 'REF-A'));
        $second = $safe->process($this->request('INT-1', 'REF-B'));

        $this->assertSame('confirmed', $first->status);
        $this->assertTrue($second->deduped, 'La segunda solicitud debe reconocerse como duplicada');
        $this->assertSame(1, Recharge::count(), 'Sólo debe existir una recarga');
        $this->assertSame(1, ProviderCharge::count(), 'El proveedor debe cobrar una sola vez');
    }

    public function test_naive_double_charges_on_duplicate_requests(): void
    {
        $naive = app(NaiveRechargeService::class);

        $naive->process($this->request('INT-1', 'REF-A', safe: false));
        $naive->process($this->request('INT-1', 'REF-B', safe: false));

        // Demuestra el bug que la ruta segura corrige: dos cargos por una compra.
        $this->assertSame(2, ProviderCharge::count(), 'La ruta ingenua cobra dos veces');
    }

    public function test_safe_never_oversells_a_limited_balance(): void
    {
        Wallet::create(['key' => 'store:LAB', 'label' => 'tienda', 'balance' => 32.00]); // financia 1 recarga
        $safe = app(SafeRechargeService::class);

        $r1 = $safe->process($this->request('INT-1', 'REF-1', storeKey: 'store:LAB'));
        $r2 = $safe->process($this->request('INT-2', 'REF-2', storeKey: 'store:LAB'));
        $r3 = $safe->process($this->request('INT-3', 'REF-3', storeKey: 'store:LAB'));

        $this->assertSame(1, Recharge::where('status', 'confirmed')->count(), 'Sólo una recarga financiada');
        $this->assertSame(2, Recharge::where('status', 'failed')->count(), 'Las demás se rechazan');
        $this->assertEquals(0.0, (float) Wallet::where('key', 'store:LAB')->value('balance'), 'El saldo nunca queda negativo');
        $this->assertSame(1, ProviderCharge::count());
    }

    public function test_timeout_is_reconciled_without_double_charge(): void
    {
        // El proveedor SIEMPRE cobra y luego devuelve timeout al cliente.
        config(['demo.provider.timeout_rate' => 1.0]);
        $safe = app(SafeRechargeService::class);

        // QUEUE_CONNECTION=sync ⇒ el job de reconciliación corre en línea.
        $result = $safe->process($this->request('INT-1', 'REF-1'));

        $this->assertSame(1, ProviderCharge::count(), 'Un solo cargo real, pese al timeout');
        $this->assertSame('confirmed', Recharge::first()->status, 'La reconciliación confirma sin recobrar');
    }
}
