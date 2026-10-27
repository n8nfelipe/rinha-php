#!/usr/bin/env bash
# Valida o orçamento de CPU e memória do docker-compose.yml de entrega.
#
# Regra da Rinha 2023 Q3:
#   "Você terá 1.5 CPUs e 3.0GB para distribuir como quiser entre seus
#    contêineres!"
#
# A soma dos `deploy.resources.limits` de TODOS os serviços precisa caber
# nesses dois tetos. Sai com código 1 se estourar.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

docker compose -f "$ROOT/docker-compose.yml" config --format json > "$TMP"

python3 - "$TMP" <<'PY'
import json
import sys

LIMITE_CPU = 1.5
LIMITE_MEM = 3 * 1024 ** 3

cfg = json.load(open(sys.argv[1]))

cpu = 0.0
mem = 0

print(f"{'servico':10s} {'CPU':>6s} {'memoria':>10s}")
print("-" * 28)
for nome, svc in sorted(cfg["services"].items()):
    lim = svc.get("deploy", {}).get("resources", {}).get("limits", {})
    c = float(lim.get("cpus", 0) or 0)
    m = int(lim.get("memory", 0) or 0)
    cpu += c
    mem += m
    print(f"{nome:10s} {c:6.2f} {m / 1024 ** 3:9.2f}G")
print("-" * 28)
print(f"{'TOTAL':10s} {cpu:6.2f} {mem / 1024 ** 3:9.2f}G")
print(f"{'LIMITE':10s} {LIMITE_CPU:6.2f} {LIMITE_MEM / 1024 ** 3:9.2f}G")

erros = []
EPS = 1e-9
if cpu > LIMITE_CPU + EPS:
    erros.append(f"CPU {cpu:.2f} > {LIMITE_CPU:.2f}")
if mem > LIMITE_MEM:
    erros.append(f"memoria {mem / 1024 ** 3:.2f}GB > {LIMITE_MEM / 1024 ** 3:.2f}GB")

print()
if erros:
    print("FORA DAS REGRAS: " + "; ".join(erros))
    sys.exit(1)

print("OK: dentro do orcamento de 1.5 CPU e 3 GB.")
PY
