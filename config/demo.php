<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RechargeCore – Demo / concurrency lab configuration
|--------------------------------------------------------------------------
|
| Todos los parámetros del laboratorio de concurrencia viven aquí para que
| el escenario sea reproducible y fácil de ajustar. Los valores modelan un
| sistema de recargas digitales con un saldo prepago compartido con el
| proveedor (p. ej. el saldo Razer Gold) y una API externa con latencia y
| timeouts. NINGÚN dato real de terceros se utiliza aquí.
|
*/

return [

    // Etiqueta de moneda usada solo para presentación en el scoreboard.
    // El saldo del proveedor representa, de forma abstracta, el saldo
    // prepago (CLP) desde el cual se debita cada recarga real.
    'currency' => env('DEMO_CURRENCY', 'Bs'),

    // URL del repositorio, mostrada en la página.
    'repo_url' => env('DEMO_REPO_URL', 'https://github.com/'),

    // Saldo inicial del proveedor (saldo prepago compartido). Cada cargo
    // real efectuado por el proveedor debita de aquí. Si el sistema hace
    // cargos duplicados, este saldo se drena de más = pérdida económica.
    'razer_initial_balance' => (float) env('DEMO_RAZER_BALANCE', 5_000_000),

    // Paquetes de diamantes. `cost` = monto debitado del saldo del proveedor
    // por cada cargo; `price` = monto cobrado al cliente/tienda.
    // Los montos coinciden con los mostrados en la plataforma de referencia.
    'packages' => [
        ['code' => 'FF-310',  'name' => '310 + 31 Bonos',   'cost' => 32.00,  'price' => 35.00],
        ['code' => 'FF-1080', 'name' => '1080 + 106 Bonos', 'cost' => 90.00,  'price' => 98.00],
        ['code' => 'FF-2180', 'name' => '2180 + 218 Bonos', 'cost' => 179.00, 'price' => 190.00],
    ],

    // Comportamiento simulado de la API externa (proveedor).
    'provider' => [
        // Ventana de latencia por llamada (ms). Ensancha la ventana de carrera.
        'latency_min_ms' => (int) env('DEMO_LAT_MIN', 25),
        'latency_max_ms' => (int) env('DEMO_LAT_MAX', 90),

        // Probabilidad de que una llamada QUE SÍ SE PROCESÓ en el proveedor
        // devuelva timeout al cliente. Este es el caso clásico que provoca
        // cargos duplicados cuando el cliente reintenta a ciegas.
        'timeout_rate' => (float) env('DEMO_TIMEOUT_RATE', 0.20),

        // Probabilidad de un fallo real (el proveedor NO cobró). Reintentar
        // aquí es seguro.
        'hard_fail_rate' => (float) env('DEMO_FAIL_RATE', 0.03),
    ],

    // Comportamiento del cliente ingenuo (lo que suele existir hoy).
    'naive' => [
        // Reintentos a ciegas ante timeout, cada uno con una referencia nueva.
        'max_retries' => (int) env('DEMO_NAIVE_RETRIES', 1),
    ],

    // Parámetros por escenario de la carga de prueba (demo:hammer).
    'scenarios' => [
        // Doble cobro por duplicidad de solicitud / reintento ante timeout.
        'duplicate' => [
            'intents' => (int) env('DEMO_DUP_INTENTS', 120),   // compras lógicas
            'clicks_min' => 1,                                  // clics por compra
            'clicks_max' => 3,                                  // (doble/triple clic)
            'package' => 'FF-310',
        ],
        // Sobreventa de un saldo interno limitado bajo concurrencia.
        'oversell' => [
            'intents' => (int) env('DEMO_OVS_INTENTS', 200),   // intentos de compra
            'funded' => (int) env('DEMO_OVS_FUNDED', 100),      // recargas realmente financiadas
            'package' => 'FF-310',
        ],
    ],

    // Número de procesos worker concurrentes por defecto (línea de comandos).
    'workers' => (int) env('DEMO_WORKERS', 20),

    // Workers para las corridas disparadas desde la web (acotado por recursos
    // del servidor; subir si el VPS tiene más núcleos).
    'web_workers' => (int) env('DEMO_WEB_WORKERS', 12),
];
