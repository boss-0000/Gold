#!/usr/bin/env bash
# Elimina la demo por completo, SIN tocar los demás proyectos.
# Uso (como root):   bash deploy/teardown.sh
set -uo pipefail
cd "$(dirname "$0")/.."

docker compose down -v --rmi local || true
echo ">> Demo eliminada (contenedores, red, volumen e imagen construida)."
echo ">> n8n, odoo y postgres quedan intactos."
echo ""
echo ">> El swap de 2G se dejó puesto (beneficia a los demás servicios)."
echo ">> Si quiere quitarlo también:"
echo "     swapoff /swapfile && sed -i '\\|/swapfile|d' /etc/fstab && rm -f /swapfile"
