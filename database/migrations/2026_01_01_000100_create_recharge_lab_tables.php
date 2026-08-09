<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saldos (prepago del proveedor y saldos internos por tienda).
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // 'razer_master' | 'store:GAME_ONE'
            $table->string('label')->nullable();
            $table->decimal('balance', 18, 4)->default(0);
            $table->timestamps();
        });

        // Catálogo de paquetes.
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->decimal('cost', 12, 2);           // debitado del proveedor por cargo
            $table->decimal('price', 12, 2);          // cobrado al cliente/tienda
            $table->timestamps();
        });

        // Recargas (intención + estado del lado de nuestra plataforma).
        Schema::create('recharges', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();            // RGRH-2026-xxxxxx (por recarga)
            $table->string('idempotency_key')->nullable();    // presente en la ruta segura
            $table->string('intent_key')->nullable();         // compra lógica (solo para medición)
            $table->string('store_key')->nullable();
            $table->string('player_id');
            $table->foreignId('package_id');
            $table->decimal('amount_cost', 12, 2);
            $table->decimal('amount_price', 12, 2);
            $table->string('status')->default('pending');     // pending|confirmed|failed|reversed
            $table->string('provider_ref')->nullable();       // ref enviada al proveedor
            $table->string('provider_tx_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error')->nullable();
            $table->timestamps();

            // Clave de la idempotencia: dos solicitudes con la misma clave
            // NO pueden crear dos recargas. En la ruta ingenua es null.
            $table->unique('idempotency_key');
            $table->index('status');
        });

        // Libro mayor append-only. El saldo es DERIVABLE de la suma de asientos.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_key');
            $table->foreignId('recharge_id')->nullable();
            $table->string('type');                    // debit | credit
            $table->decimal('amount', 18, 4);
            $table->decimal('balance_after', 18, 4)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['wallet_key', 'id']);
        });

        // Cargos del lado del PROVEEDOR (fuente de verdad de cuántas veces se
        // cobró realmente). La unicidad de provider_ref modela la idempotencia
        // del proveedor: la ruta segura reutiliza la misma ref -> un solo cargo.
        Schema::create('provider_charges', function (Blueprint $table) {
            $table->id();
            $table->string('provider_ref')->unique();
            $table->string('player_id');
            $table->foreignId('package_id');
            $table->decimal('amount', 12, 2);
            $table->string('provider_tx_id')->unique();
            $table->timestamp('created_at')->nullable();
        });

        // Generador de carga: cada fila es un "clic" a procesar por un worker.
        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->string('scenario');                 // duplicate | oversell
            $table->string('intent_key');               // clave estable por compra lógica
            $table->string('reference');                // ref única por clic/intento
            $table->string('store_key')->nullable();
            $table->string('player_id');
            $table->foreignId('package_id');
            $table->boolean('processed')->default(false);
            $table->string('outcome')->nullable();
            $table->timestamps();
            $table->index(['processed', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_items');
        Schema::dropIfExists('provider_charges');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('recharges');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('wallets');
    }
};
