<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>RechargeCore — Laboratorio de concurrencia</title>
    <style>
        :root{
            --bg:#0d0b14; --panel:#161320; --panel2:#1e1a2b; --line:#2a2540;
            --txt:#e8e6f0; --mut:#9a93b3; --accent:#7c3aed; --accent2:#a78bfa;
            --red:#ef4444; --redbg:#2a1417; --green:#22c55e; --greenbg:#0f2318;
        }
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--txt);
            font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;line-height:1.5}
        a{color:var(--accent2);text-decoration:none}
        .wrap{max-width:960px;margin:0 auto;padding:28px 20px 60px}
        header{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:22px}
        .brand{font-weight:700;font-size:20px}
        .brand span{color:var(--accent2)}
        .btn{background:var(--accent);color:#fff;border:0;border-radius:10px;padding:11px 18px;
            font-size:15px;font-weight:600;cursor:pointer;transition:.15s}
        .btn:hover{filter:brightness(1.1)}
        .btn:disabled{opacity:.5;cursor:not-allowed}
        .btn.ghost{background:transparent;border:1px solid var(--line);color:var(--txt)}
        .card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:20px;margin-bottom:18px}
        .lead{color:var(--mut);font-size:15px}
        .lead b{color:var(--txt)}
        .balance{display:flex;align-items:baseline;gap:10px;margin-top:6px}
        .balance .num{font-size:30px;font-weight:700;font-variant-numeric:tabular-nums}
        .balance .lbl{color:var(--mut);font-size:13px;text-transform:uppercase;letter-spacing:.05em}
        .scen{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        @media(max-width:640px){.scen{grid-template-columns:1fr}}
        .scen .card{margin:0;display:flex;flex-direction:column;gap:12px}
        .scen h3{margin:0;font-size:17px}
        .scen p{margin:0;color:var(--mut);font-size:14px;flex:1}
        .status{color:var(--mut);font-size:14px;margin:10px 0 0}
        .spin{display:inline-block;width:15px;height:15px;border:2px solid var(--line);
            border-top-color:var(--accent2);border-radius:50%;animation:sp 0.8s linear infinite;vertical-align:-2px;margin-right:8px}
        @keyframes sp{to{transform:rotate(360deg)}}
        .cols{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:6px}
        @media(max-width:640px){.cols{grid-template-columns:1fr}}
        .mode{border-radius:14px;border:1px solid var(--line);overflow:hidden}
        .mode.naive{border-color:#4a2230}
        .mode.safe{border-color:#1f4030}
        .mode .head{padding:12px 16px;font-weight:700;display:flex;justify-content:space-between;align-items:center}
        .mode.naive .head{background:var(--redbg);color:#fca5a5}
        .mode.safe .head{background:var(--greenbg);color:#86efac}
        table{width:100%;border-collapse:collapse}
        td{padding:9px 16px;border-top:1px solid var(--line);font-size:14px;font-variant-numeric:tabular-nums}
        td:last-child{text-align:right;font-weight:600}
        td.lbl{color:var(--mut);font-weight:400}
        .loss{padding:14px 16px;font-size:15px;font-weight:700;display:flex;justify-content:space-between}
        .mode.naive .loss{background:var(--redbg);color:#fca5a5}
        .mode.safe .loss{background:var(--greenbg);color:#86efac}
        .hidden{display:none}
        footer{color:var(--mut);font-size:13px;margin-top:24px;border-top:1px solid var(--line);padding-top:16px}
        code{background:var(--panel2);padding:2px 6px;border-radius:5px;font-size:13px}
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div class="brand">Recharge<span>Core</span> · laboratorio de concurrencia</div>
        <a class="btn ghost" href="{{ $repoUrl }}" target="_blank" rel="noopener">Ver código (GitHub)</a>
    </header>

    <div class="card">
        <p class="lead">
            Esta página reproduce, con procesos concurrentes reales, cómo un sistema de recargas
            <b>pierde dinero</b> por condiciones de carrera, timeouts y duplicidad — y cómo esa pérdida
            se elimina con <b>idempotencia</b>, <b>bloqueo de fila</b> y <b>reconciliación asíncrona</b>.
            Cada escenario se ejecuta dos veces con la misma carga: la versión <b>ingenua</b> (lo que suele
            existir hoy) y la <b>segura</b>. Pulse un botón y compare la pérdida.
        </p>
        <p class="lead" style="margin-top:10px">
            No usa datos ni marca de terceros: es un laboratorio neutro. Los números varían en cada corrida
            (la carga es aleatoria); lo que no varía es el resultado: <b>la ruta ingenua pierde saldo, la segura pierde cero.</b>
        </p>
    </div>

    <div class="card">
        <div class="balance">
            <span class="num" id="balance">{{ $currency }} {{ number_format($razerBalance, 2) }}</span>
            <span class="lbl">saldo prepago del proveedor (simulado)</span>
        </div>
    </div>

    <div class="scen">
        <div class="card">
            <h3>① Doble cobro</h3>
            <p>Una compra que se dispara varias veces (doble clic, reintento) contra una API que a veces
                <b>cobra pero responde timeout</b>. La ingenua reintenta a ciegas y cobra de más.</p>
            <button class="btn" data-scenario="duplicate">Ejecutar escenario</button>
        </div>
        <div class="card">
            <h3>② Sobreventa</h3>
            <p>Muchas recargas concurrentes contra un saldo interno limitado ({{ $oversell['funded'] }} financiadas
                de {{ $oversell['intents'] }} intentos). La ingenua vende de más y corrompe el saldo.</p>
            <button class="btn" data-scenario="oversell">Ejecutar escenario</button>
        </div>
    </div>

    <p class="status hidden" id="status"></p>

    <div class="cols hidden" id="results">
        <div class="mode naive">
            <div class="head"><span>❌ INGENUA</span><span id="n-elapsed"></span></div>
            <table><tbody id="n-body"></tbody></table>
            <div class="loss"><span>Pérdida</span><span id="n-loss"></span></div>
        </div>
        <div class="mode safe">
            <div class="head"><span>✅ SEGURA</span><span id="s-elapsed"></span></div>
            <table><tbody id="s-body"></tbody></table>
            <div class="loss"><span>Pérdida</span><span id="s-loss"></span></div>
        </div>
    </div>

    <footer>
        Laboratorio de demostración · misma pila del cliente (Laravel + MySQL + Redis) ·
        <a href="{{ $repoUrl }}" target="_blank" rel="noopener">código y documentación en GitHub</a>.
        La lógica de idempotencia, bloqueo y reconciliación es la que se aplicaría a una plataforma real.
    </footer>
</div>

<script>
const CURRENCY = @json($currency);
const CSRF = document.querySelector('meta[name=csrf-token]').content;
const buttons = document.querySelectorAll('button[data-scenario]');
const statusEl = document.getElementById('status');
const resultsEl = document.getElementById('results');

const money = v => CURRENCY + ' ' + Number(v).toLocaleString('es-BO', {minimumFractionDigits:2, maximumFractionDigits:2});

const ROWS = {
    duplicate: [
        ['Solicitudes (clics)', m => m.clicks],
        ['Compras reales', m => m.intents],
        ['Duplicadas rechazadas', m => m.dedupe_rejected],
        ['Cargos al proveedor', m => m.provider_charges],
        ['Saldo debitado', m => money(m.razer_debited)],
        ['Débito esperado', m => money(m.expected_debit)],
    ],
    oversell: [
        ['Intentos de compra', m => m.attempts],
        ['Financiadas', m => m.funded],
        ['Confirmadas', m => m.confirmed],
        ['Sobrevendidas', m => m.oversold],
        ['Saldo interno final', m => money(m.store_now)],
        ['Saldo interno esperado', m => money(m.store_expected)],
    ],
};

function renderBody(id, scenario, m){
    document.getElementById(id).innerHTML = ROWS[scenario]
        .map(([label, fn]) => `<tr><td class="lbl">${label}</td><td>${fn(m)}</td></tr>`).join('');
}

function render(run){
    resultsEl.classList.remove('hidden');
    const sc = run.scenario;
    renderBody('n-body', sc, run.naive);
    renderBody('s-body', sc, run.safe);
    document.getElementById('n-elapsed').textContent = run.naive.elapsed + 's';
    document.getElementById('s-elapsed').textContent = run.safe.elapsed + 's';
    document.getElementById('n-loss').textContent = money(run.naive.loss);
    document.getElementById('s-loss').textContent = money(run.safe.loss);
}

let polling = false;
async function poll(id){
    const r = await fetch(`/demo/run/${id}`);
    const run = await r.json();
    if(run.status === 'done'){
        statusEl.classList.add('hidden');
        render(run);
        setEnabled(true);
        polling = false;
        return;
    }
    if(run.status === 'error'){
        statusEl.textContent = 'Error: ' + (run.error || 'desconocido');
        setEnabled(true);
        polling = false;
        return;
    }
    setTimeout(() => poll(id), 1200);
}

function setEnabled(on){ buttons.forEach(b => b.disabled = !on); }

async function start(scenario){
    if(polling) return;
    polling = true;
    setEnabled(false);
    resultsEl.classList.add('hidden');
    statusEl.classList.remove('hidden');
    statusEl.innerHTML = '<span class="spin"></span> Ejecutando procesos concurrentes (modo ingenuo y seguro)…';
    try {
        const r = await fetch('/demo/run', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},
            body: JSON.stringify({scenario}),
        });
        const data = await r.json();
        if(data.run_id){ poll(data.run_id); }
        else { statusEl.textContent = 'No se pudo iniciar la corrida.'; setEnabled(true); polling = false; }
    } catch(e){
        statusEl.textContent = 'Error de red.'; setEnabled(true); polling = false;
    }
}

buttons.forEach(b => b.addEventListener('click', () => start(b.dataset.scenario)));

@if($lastRun && $lastRun->status === 'done')
render(@json(['scenario' => $lastRun->scenario, 'naive' => $lastRun->result_naive, 'safe' => $lastRun->result_safe]));
@endif
</script>
</body>
</html>
