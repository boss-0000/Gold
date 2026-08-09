#!/usr/bin/env bash
# Despliega la demo en un VPS pequeño (2 GB) SIN tocar los proyectos existentes.
# Uso (como root):   bash deploy/vps.sh
set -euo pipefail
cd "$(dirname "$0")/.."

# 1) Swap: protege a los servicios en ejecución (n8n, odoo, postgres) de un OOM.
#    Una caja de 2 GB sin swap no tiene margen; esto lo agrega. Es reversible.
if ! swapon --show 2>/dev/null | grep -q /swapfile; then
  echo ">> Creando swap de 2G..."
  fallocate -l 2G /swapfile 2>/dev/null || dd if=/dev/zero of=/swapfile bs=1M count=2048
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null
  swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  echo ">> swap activo."
fi

# 2) .env: puerto libre (8090), pocos workers y clave de BD aleatoria.
if [ ! -f .env ]; then
  cp .env.example .env
  sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 16)|" .env
  printf '\nWEB_PORT=8090\nDEMO_WEB_WORKERS=6\n' >> .env
  echo ">> .env creado (WEB_PORT=8090)."
fi

# 3) Override para caja chica: MariaDB ligero + límites de memoria por contenedor
#    (techos que impiden que la demo consuma de más y afecte a los demás).
cat > docker-compose.override.yml <<'YAML'
services:
  db:
    image: mariadb:11
    command: ["--innodb-buffer-pool-size=64M"]
    # MariaDB 11 no trae 'mysqladmin'; usar su healthcheck propio + margen de arranque.
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 40
      start_period: 60s
    mem_limit: 512m
  redis:
    mem_limit: 96m
  app:
    mem_limit: 256m
  web:
    mem_limit: 96m
  worker:
    mem_limit: 512m
YAML

# 4) Abrir el puerto si ufw está activo (el firewall de DigitalOcean, si lo usa,
#    se abre desde el panel).
if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw allow 8090/tcp || true
fi

# 5) Levantar.
echo ">> docker compose up (la primera vez descarga imágenes; puede tardar unos minutos)..."
docker compose up -d --build

IP=$(curl -s ifconfig.me 2>/dev/null || echo TU_IP)
echo ""
echo "======================================================"
echo ">> LISTO.  Abra:  http://$IP:8090"
echo ">> Progreso:      docker compose logs -f worker"
echo ">> Memoria:       free -m   (debe quedar margen + swap)"
echo "======================================================"
