#!/bin/bash
# tests/Performance/fpm-peak-monitor.sh
#
# Măsurătoarea de memorie pentru OPS-07 (vezi docblock-ul din fpm-peak.js) — k6 nu poate
# citi RSS-ul unui container, deci rulează ÎN PARALEL cu `k6 run tests/Performance/fpm-peak.js`,
# nu în locul lui. Scrie DOUĂ fișiere, la interval de 1s, cât timp rulează:
#
#   1. `docker stats` pe containerul dat — memoria CGROUP a întregului container `app`
#      (`pm.max_children=4` copii + master + OPcache SHM), exact plafonul din OPS-07.
#   2. `VmHWM` (vârful de RSS, "high water mark", per proces — nu se resetează la fiecare
#      citire, spre deosebire de VmRSS) din `/proc/<pid>/status` pentru FIECARE proces
#      php-fpm worker (`pool www`) găsit în container. Ăsta e felul de măsurătoare deja
#      folosit pe acest proiect pentru pragul de export PDF (`config/throughput.php`,
#      comentariul de la `export_pdf_max_rows`: „Vârful e RSS-ul procesului citit din
#      /proc/<pid>/status (VmHWM), nu memory_get_peak_usage()") — reprodus aici pentru
#      `app`, nu `horizon`.
#
# Utilizare:
#   tests/Performance/fpm-peak-monitor.sh <container> <fișier_prefix> <durată_secunde>
#
# Exemplu (rulat ÎNAINTE de a porni k6, cu durata scenariului + marjă):
#   tests/Performance/fpm-peak-monitor.sh throughput-peak-app-1 /tmp/peak-4vu 220 &
#   BASE_URL=http://127.0.0.1:8099 k6 run -e VUS=4 -e DURATION=3m tests/Performance/fpm-peak.js
#   wait
#
# După rulare, verifică separat (NU face parte din acest script, ca semnificația „a fost
# ucis" să rămână explicită, nu îngropată într-un log):
#   docker inspect -f '{{.State.OOMKilled}}' throughput-peak-app-1
#   docker events --since <T0> --until <T1> --filter event=oom --filter container=throughput-peak-app-1
set -euo pipefail

CONTAINER="${1:?Usage: fpm-peak-monitor.sh <container> <fisier_prefix> <durata_secunde>}"
PREFIX="${2:?Usage: fpm-peak-monitor.sh <container> <fisier_prefix> <durata_secunde>}"
DURATION="${3:?Usage: fpm-peak-monitor.sh <container> <fisier_prefix> <durata_secunde>}"

STATS_FILE="${PREFIX}-docker-stats.tsv"
WORKERS_FILE="${PREFIX}-worker-vmhwm.tsv"

echo -e "timestamp\tmem_usage\tmem_perc\tcpu_perc" > "$STATS_FILE"
echo -e "timestamp\tpid\tvmhwm_kb\tvmrss_kb" > "$WORKERS_FILE"

echo "==> Monitorizez $CONTAINER timp de ${DURATION}s → $STATS_FILE / $WORKERS_FILE"

END=$((SECONDS + DURATION))

while [ $SECONDS -lt $END ]; do
    ts="$(date -u +%Y-%m-%dT%H:%M:%S)"

    # `docker stats --no-stream`: o citire punctuală, nu fluxul live (mai simplu de
    # parsat într-un TSV). `MemUsage` are forma "123.4MiB / 256MiB" — păstrată întreagă,
    # parsarea numerică se face la analiză, nu aici (nu vrem să pierdem precizie la o
    # rotunjire greșită într-un `awk` scris în grabă).
    docker stats --no-stream --format '{{.MemUsage}}\t{{.MemPerc}}\t{{.CPUPerc}}' "$CONTAINER" 2>/dev/null \
        | while IFS=$'\t' read -r mem_usage mem_perc cpu_perc; do
            echo -e "${ts}\t${mem_usage}\t${mem_perc}\t${cpu_perc}" >> "$STATS_FILE"
        done

    # Fiecare worker FPM găsit ÎN ACEST MOMENT — `pm=ondemand` înseamnă că numărul de
    # workeri variază (0 la idle, până la `pm.max_children=4` sub concurență); un worker
    # care a murit între două citiri își pierde rândul din TSV, dar VmHWM-ul lui rămâne
    # oricum sub cel al unui worker încă viu la sfârșitul ferestrei — nepierdut la
    # analiză (`sort -k3 -n` pe fișier ia maximul din TOATE rândurile, nu doar din ultimul).
    docker exec "$CONTAINER" sh -c "ps aux | grep 'pool www' | grep -v grep | awk '{print \$1}'" 2>/dev/null \
        | while read -r pid; do
            [ -z "$pid" ] && continue
            status="$(docker exec "$CONTAINER" cat "/proc/$pid/status" 2>/dev/null || true)"
            [ -z "$status" ] && continue
            vmhwm="$(echo "$status" | awk '/VmHWM/{print $2}')"
            vmrss="$(echo "$status" | awk '/VmRSS/{print $2}')"
            echo -e "${ts}\t${pid}\t${vmhwm:-0}\t${vmrss:-0}" >> "$WORKERS_FILE"
        done

    sleep 1
done

echo "==> Gata. Vârfuri:"
echo "    container (docker stats, coloana MemUsage): $(tail -n +2 "$STATS_FILE" | awk -F'\t' '{print $2}' | sort -t/ -k1 -h | tail -1)"
echo "    worker php-fpm (VmHWM cel mai mare observat, kB): $(tail -n +2 "$WORKERS_FILE" | awk -F'\t' '{print $3}' | sort -n | tail -1)"
