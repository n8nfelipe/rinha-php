#!/usr/bin/env bash
# Roda o perfil de bancada do k6 e mede CPU/ throttling de cada container.
#
# A amostragem lê direto os arquivos de cgroup (/sys/fs/cgroup/.../cpu.stat) e
# /proc/stat. Nada de `docker exec` ou psql aqui: o amostrador não pode entrar
# na cota de CPU do banco nem atrasar justamente quando o banco satura — foi
# assim que uma medição anterior reportou "4 cores" de um container limitado a
# 0,89 (o intervalo real entre amostras tinha crescido, e o delta foi lido como
# se fosse 1 s).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$HERE")"

COMPOSE=(docker compose
    -f "$ROOT/docker-compose.yml"
    -f "$ROOT/docker-compose.build.yml"
    -f "$ROOT/docker-compose.local.yml")
BASE_URL="${BASE_URL:-http://127.0.0.1:19999}"
PROFILE="${PROFILE:-bench}"
DURATION="${DURATION:-30s}"
POST_RATE="${POST_RATE:-600}"
SEARCH_RATE="${SEARCH_RATE:-100}"
INVALID_RATE="${INVALID_RATE:-40}"

LOG="${LOG:-/tmp/opencode/rinha-cpu.log}"

# Estado limpo: identidade reiniciada para que os payloads do TSV comecem do 0.
"${COMPOSE[@]}" exec -T db psql -U rinha -d rinha -q \
    -c "TRUNCATE pessoas, pessoas_busca RESTART IDENTITY;" \
    -c "UPDATE estado_busca SET last_seq = 0;"

amostra() {
    local pares=() par c p id
    for c in api1 api2 nginx db indexer; do
        id=$(docker inspect -f '{{.Id}}' "rinha-php85-$c-1" 2>/dev/null) || continue
        p=$(ls -d /sys/fs/cgroup/system.slice/docker-$id.scope 2>/dev/null \
            || echo "/sys/fs/cgroup/docker/$id")
        [ -r "$p/cpu.stat" ] || continue
        pares+=("$c=$p")
    done

    local ts u n t cpu linha
    while true; do
        ts=$(date +%s%N)
        for par in "${pares[@]}"; do
            c=${par%%=*}
            p=${par#*=}
            read -r u n t < <(awk '
                /^usage_usec/     { u = $2 }
                /^nr_throttled/   { n = $2 }
                /^throttled_usec/ { t = $2 }
                END { print u + 0, n + 0, t + 0 }' "$p/cpu.stat")
            echo "$ts $c $u $n $t"
        done

        # CPU do host inteiro (8 cores), para separar gargalo do stack de
        # ruído externo — a máquina é compartilhada com desktop e outros stacks.
        read -r cpu linha < <(head -1 /proc/stat)
        echo "$ts host $(echo "$linha" | awk '{ total = 0; for (i = 1; i <= NF; i++) total += $i;
                                               print total, $4 + $5 }')"
        sleep 1
    done
}

amostra > "$LOG" &
SAMPLER=$!
trap 'kill "$SAMPLER" 2>/dev/null || true' EXIT

k6 run \
    --summary-trend-stats "avg,min,med,p(90),p(95),p(99),p(99.9),max" \
    -e PROFILE="$PROFILE" \
    -e DURATION="$DURATION" \
    -e POST_RATE="$POST_RATE" \
    -e SEARCH_RATE="$SEARCH_RATE" \
    -e INVALID_RATE="$INVALID_RATE" \
    -e BASE_URL="$BASE_URL" \
    "$HERE/rinha.js"

kill "$SAMPLER" 2>/dev/null || true
trap - EXIT

echo
echo "=== CPU e throttling (delta entre a 1a e a ultima amostra) ==="
awk -v hz="$(getconf CLK_TCK)" -v ncpu="$(nproc)" '
    {
        ts = $1; c = $2
        if (!(c in inicio)) { inicio[c] = ts; u0[c] = $3; n0[c] = $4; t0[c] = $5 }
        fim[c] = ts; u1[c] = $3; n1[c] = $4; t1[c] = $5
    }
    END {
        for (c in inicio) {
            seg = (fim[c] - inicio[c]) / 1e9
            if (seg <= 0) continue
            if (c == "host") {
                # /proc/stat vem em ticks (USER_HZ), nao em microssegundos.
                printf "%-8s cpu=%5.2f de %d cores  (janela=%.1fs)\n",
                       c, ((u1[c] - u0[c]) - (n1[c] - n0[c])) / hz / seg, ncpu, seg
                continue
            }
            printf "%-8s cpu=%5.2f cores  eventos=%-5d congelado=%.2fs  janela=%.1fs\n",
                   c, (u1[c] - u0[c]) / 1e6 / seg, n1[c] - n0[c],
                   (t1[c] - t0[c]) / 1e6, seg
        }
    }' "$LOG" | sort

echo
echo "=== contagem final ==="
curl -sS "$BASE_URL/contagem-pessoas"
echo
