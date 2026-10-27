#!/usr/bin/env bash
# Executa o teste de stress local (réplica do Gatling oficial) e mostra a
# contagem final de pessoas.
#
# O perfil é idêntico ao do RinhaBackendSimulation.scala:
#   POST  2/s por 10s -> 5/s por 15s -> ramp 6/s..600/s por 3min
#   BUSCA 2/s por 25s -> ramp 6/s..100/s por 3min
#   INVÁLIDA 2/s por 25s -> ramp 6/s..40/s por 3min
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_URL="${BASE_URL:-http://127.0.0.1:19999}"

if [ ! -f "$HERE/data/pessoas-payloads.tsv" ] || [ ! -f "$HERE/data/termos-busca.tsv" ]; then
    python3 "$HERE/gerar_recursos.py"
fi

k6 run \
    --summary-trend-stats "avg,min,med,p(90),p(95),p(99),p(99.9),max" \
    -e BASE_URL="$BASE_URL" \
    "$HERE/rinha.js"

echo
echo "=== contagem final ==="
curl -sS "$BASE_URL/contagem-pessoas"
echo
