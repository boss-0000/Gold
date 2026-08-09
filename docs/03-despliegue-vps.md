# Despliegue en un VPS (demo en vivo)

Objetivo: dejar la demo interactiva accesible en `http://SU_SERVIDOR/` con un
solo comando. Pensado para un VPS pequeño (Ubuntu 22.04+, **2 GB RAM / 1–2
vCPU** mínimo — la demo lanza procesos concurrentes).

## 1. Instalar Docker (una vez)

```bash
curl -fsSL https://get.docker.com | sh
```

## 2. Clonar y configurar

```bash
git clone <TU_REPO>.git recharge-lab && cd recharge-lab
cp .env.example .env
```

Edite `.env`:

```dotenv
APP_URL=http://SU_IP_O_DOMINIO
DB_PASSWORD=una-clave-fuerte
DEMO_REPO_URL=https://github.com/<tu-usuario>/<tu-repo>
```

`APP_KEY` se genera solo en el arranque (servicio `init`).

## 3. Levantar

```bash
docker compose up -d --build
```

El servicio `init` corre `composer install`, genera la clave y aplica las
migraciones; luego arrancan `app` (php-fpm), `web` (nginx), `worker` (cola) con
`db` (MySQL 8) y `redis`. Abra **`http://SU_SERVIDOR/`**.

## 4. Operación

```bash
docker compose logs -f worker          # ver corridas en vivo
docker compose exec app php artisan demo:hammer --scenario=oversell --mode=both
docker compose down                    # apagar (conserva datos)
docker compose down -v                 # apagar y borrar datos
```

## 5. HTTPS (opcional)

Para un dominio con certificado, lo más simple es poner **Caddy** delante (TLS
automático). Ejemplo de `Caddyfile`:

```
demo.tudominio.com {
    reverse_proxy localhost:80
}
```

Para la evaluación, `http://IP/` es suficiente.

## 6. Notas de seguridad

- Es un **laboratorio público**: los botones están limitados por *rate-limit*
  (20/min) y sólo se ejecuta **una corrida a la vez** (candado). Un visitante no
  puede dejarlo en un estado roto: cada corrida reinicia el estado.
- No hay datos ni credenciales reales. El proveedor es simulado.
- Recursos acotados: `DEMO_WEB_WORKERS` (por defecto 12) controla la
  concurrencia por corrida; ajústelo si el VPS tiene más núcleos.

## 7. Si prefiere no usar Docker

Es una app Laravel estándar. Sirve `public/` con nginx + php-fpm (PHP 8.3+,
extensiones `pdo_mysql`, `bcmath`, `zip`, `mbstring`, `redis`), una base MySQL,
y un worker permanente:

```bash
php artisan queue:work --queue=demo --tries=1 --timeout=300
```
