<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una "corrida" del laboratorio disparada desde la web (ambos modos).
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->string('scenario');                 // duplicate | oversell
            $table->string('status')->default('running'); // running | done | error
            $table->unsignedInteger('workers')->default(12);
            $table->json('result_naive')->nullable();
            $table->json('result_safe')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
