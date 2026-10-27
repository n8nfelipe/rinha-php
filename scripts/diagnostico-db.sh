#!/usr/bin/env bash
# Amostra o mecanismo interno do banco durante uma carga: backends ativos, em
# que esperam, e o atraso do indexador.
#
# ATENÇÃO: este script roda `docker compose exec db psql` a cada iteração, e o
# psql executa DENTRO do container do banco — logo, consome a cota de CPU dele.
# Use para entender o mecanismo (quem espera o quê, o atraso cresce?), NÃO para
# medir CPU. Para CPU/throttling sem perturbar, use stress/bench.sh, que lê os
# cgroups direto do host.
#
# Os deltas de CPU aqui são normalizados pelo tempo REAL entre amostras: quando
# o banco satura, cada `exec psql` demora, e tratar o delta como se fosse 1 s
# produz números impossíveis (já apareceu "4 cores" num container limitado a
# 0,89).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE=(docker compose
    -f "$ROOT/docker-compose.yml"
    -f "$ROOT/docker-compose.build.yml"
    -f "$ROOT/docker-compose.local.yml")

DUR="${1:-60}"
SAIDA="${2:-/tmp/opencode/rinha-diagnostico.log}"

id=$(docker inspect -f '{{.Id}}' rinha-php85-db-1)
cgroup=$(ls -d /sys/fs/cgroup/system.slice/docker-$id.scope 2>/dev/null \
    || echo "/sys/fs/cgroup/docker/$id")

ler_cpu() {
    awk '/^usage_usec/ {u=$2} /^nr_throttled/ {n=$2} /^throttled_usec/ {t=$2}
         END {print u+0, n+0, t+0}' "$cgroup/cpu.stat"
}

agora() { date +%s%N; }

read -r uso_ant thr_ant congelado_ant < <(ler_cpu)
ts_ant=$(agora)

: > "$SAIDA"
for i in $(seq 1 "$DUR"); do
    sleep 1

    # Backends de cliente: quantos ativos, quantos ociosos e onde os ativos
    # esperam. active sem wait_event = rodando (falta CPU); Client/IO/Lock =
    # presos em outra coisa.
    atividade=$("${COMPOSE[@]}" exec -T db psql -U rinha -d rinha -tAF' ' -c "
        SELECT
            count(*) FILTER (WHERE state = 'active'),
            count(*) FILTER (WHERE state = 'idle'),
            coalesce(max(wait_event_type) FILTER (WHERE state = 'active'), '-')
        FROM pg_stat_activity
        WHERE backend_type = 'client backend'
    " 2>/dev/null | tr -d '\r')

    # Atraso do indexador: quantas linhas ainda não estão no índice.
    atraso=$("${COMPOSE[@]}" exec -T db psql -U rinha -d rinha -tAc "
        SELECT coalesce(max(seq),0) - (SELECT last_seq FROM estado_busca) FROM pessoas
    " 2>/dev/null | tr -d '\r')

    # Texto das queries ativas, só quando há fila: mostra QUAL statement
    # saturou, em vez de deixar no palpite.
    detalhe=''
    ativos=${atividade%% *}
    if [ "${ativos:-0}" -ge 6 ]; then
        detalhe=$("${COMPOSE[@]}" exec -T db psql -U rinha -d rinha -tAF' ' -c "
            SELECT count(*) || 'x ' || left(regexp_replace(query, '\s+', ' ', 'g'), 60)
            FROM pg_stat_activity
            WHERE backend_type = 'client backend' AND state = 'active'
            GROUP BY 2 ORDER BY count(*) DESC LIMIT 3
        " 2>/dev/null | tr '\n' '|' | tr -d '\r')
    fi

    ts=$(agora)
    read -r uso thr congelado < <(ler_cpu)

    # Normaliza pelo tempo real decorrido, não por 1 s.
    seg=$(( ts - ts_ant ))
    [ "$seg" -gt 0 ] || seg=1

    printf '%3d janela=%4.1fs cpu=%.2f cores thr=%3d congelado=%5dms ativos=%s atraso=%s %s\n' \
        "$i" "$(awk -v s="$seg" 'BEGIN{printf "%.1f", s/1e9}')" \
        "$(awk -v d=$(( uso - uso_ant )) -v s="$seg" 'BEGIN{printf "%.2f", d/1e6/(s/1e9)}')" \
        $(( thr - thr_ant )) $(( (congelado - congelado_ant) / 1000 )) \
        "$atividade" "$atraso" "$detalhe" >> "$SAIDA"

    uso_ant=$uso
    thr_ant=$thr
    congelado_ant=$congelado
    ts_ant=$ts
done
