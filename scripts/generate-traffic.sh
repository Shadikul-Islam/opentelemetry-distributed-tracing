#!/usr/bin/env bash
#
# Generates varying traffic against both services so the Grafana panels show
# real, uneven data instead of a flat line.
#
#   ./generate-traffic.sh                       15 minutes, default load
#   DURATION=1800 RPS=6 ./generate-traffic.sh   longer and busier
#   WITH_OUTAGE=1 ./generate-traffic.sh         adds a real 5xx incident
#   ./generate-traffic.sh --help

set -u

ORDER_URL=${ORDER_URL:-http://localhost:8080}
INVENTORY_URL=${INVENTORY_URL:-http://localhost:8081}
DURATION=${DURATION:-900}
RPS=${RPS:-4}
WITH_OUTAGE=${WITH_OUTAGE:-0}

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'
    echo
    echo "Environment variables:"
    echo "  DURATION      total seconds to run          (default 900)"
    echo "  RPS           baseline requests per second  (default 4)"
    echo "  WITH_OUTAGE   1 = stop inventory-service midway for real 5xx errors"
    echo "  ORDER_URL     default http://localhost:8080"
    echo "  INVENTORY_URL default http://localhost:8081"
    exit 0
fi

SKUS=(SKU-1001 SKU-1002 SKU-1003 SKU-1005)
LAST_REF=""

# Load phases, cycled for the whole run: label, multiplier of RPS, seconds.
# Real traffic is never flat, so neither is this.
PHASES=(
    "normal  1.0  75"
    "low     0.3  55"
    "ramp    1.7  40"
    "spike   3.2  35"
    "busy    2.1  50"
    "normal  0.9  70"
    "quiet   0.25 45"
    "spike   3.6  30"
    "average 1.3  60"
)

CYCLE=0
for p in "${PHASES[@]}"; do
    set -- $p
    CYCLE=$(( CYCLE + $3 ))
done

phase_for() {
    local t=$(( $1 % CYCLE )) acc=0
    for p in "${PHASES[@]}"; do
        set -- $p
        acc=$(( acc + $3 ))
        if [ "$t" -lt "$acc" ]; then echo "$1 $2"; return; fi
    done
}

ord_ok=0; ord_404=0; ord_409=0; ord_5xx=0
inv_get=0; inv_post=0; inv_404=0
total=0

replenish() {
    # SKU-1004 stays short so the out-of-stock path keeps returning 409.
    docker exec mysql mysql -uroot -prootpass inventorydb -e "
        UPDATE inventory SET available_qty = 100000, reserved_qty = 0 WHERE sku <> 'SKU-1004';
        UPDATE inventory SET available_qty = 3,      reserved_qty = 0 WHERE sku  = 'SKU-1004';
    " >/dev/null 2>&1
}

# --- order-service, which fans out to inventory-service ---------------------
post_order() {
    local sku=$1 qty=$2 customer=$3 resp code
    resp=$(curl -s -m 10 -w '\n%{http_code}' -X POST "$ORDER_URL/api/orders" \
        -H 'Content-Type: application/json' \
        -d "{\"customerId\": $customer, \"sku\": \"$sku\", \"quantity\": $qty}" 2>/dev/null)
    code=$(printf '%s' "$resp" | tail -1)
    case "$code" in
        201) ord_ok=$((ord_ok+1))
             LAST_REF=$(printf '%s' "$resp" | head -1 | sed -n 's/.*"orderRef":"\([^"]*\)".*/\1/p') ;;
        404) ord_404=$((ord_404+1)) ;;
        409) ord_409=$((ord_409+1)) ;;
        5*)  ord_5xx=$((ord_5xx+1)) ;;
    esac
    total=$((total+1))
}

get_order() {
    [ -z "$LAST_REF" ] && return
    curl -s -m 10 -o /dev/null "$ORDER_URL/api/orders/$LAST_REF" 2>/dev/null
    total=$((total+1))
}

# --- inventory-service directly, so it is a root service too ----------------
get_inventory() {
    curl -s -m 10 -o /dev/null "$INVENTORY_URL/api/inventory/$1" 2>/dev/null
    inv_get=$((inv_get+1)); total=$((total+1))
}

reserve_inventory() {
    curl -s -m 10 -o /dev/null -X POST "$INVENTORY_URL/api/inventory/$1/reserve" \
        -H 'Content-Type: application/json' \
        -d "{\"orderRef\": \"DIRECT-$RANDOM\", \"quantity\": 1}" 2>/dev/null
    inv_post=$((inv_post+1)); total=$((total+1))
}

get_inventory_missing() {
    curl -s -m 10 -o /dev/null "$INVENTORY_URL/api/inventory/SKU-DOES-NOT-EXIST" 2>/dev/null
    inv_404=$((inv_404+1)); total=$((total+1))
}

outage() {
    echo
    echo ">> stopping inventory-service for 25s to produce real 5xx errors"
    docker stop inventory-service >/dev/null 2>&1
    local until_ts=$(( $(date +%s) + 25 ))
    while [ "$(date +%s)" -lt "$until_ts" ]; do
        post_order "SKU-1001" 1 1
        sleep 1
    done
    docker start inventory-service >/dev/null 2>&1
    echo ">> back up, waiting for healthy"
    while [ "$(docker inspect -f '{{.State.Health.Status}}' inventory-service 2>/dev/null)" != "healthy" ]; do
        sleep 3
    done
    echo ">> recovered"
    echo
}

echo "Traffic generator"
echo "  order-service      $ORDER_URL"
echo "  inventory-service  $INVENTORY_URL"
echo "  duration           ${DURATION}s"
echo "  baseline           ${RPS} req/s, varied across ${#PHASES[@]} phases on a ${CYCLE}s cycle"
echo "  outage scenario    $([ "$WITH_OUTAGE" = "1" ] && echo yes || echo no)"
echo

replenish

start=$(date +%s)
end=$(( start + DURATION ))
outage_at=$(( start + DURATION / 2 ))
outage_done=0
last_replenish=$start
last_label=""
i=0

while [ "$(date +%s)" -lt "$end" ]; do
    now=$(date +%s)
    elapsed=$(( now - start ))

    if [ "$WITH_OUTAGE" = "1" ] && [ "$outage_done" = "0" ] && [ "$now" -ge "$outage_at" ]; then
        outage; outage_done=1; last_replenish=$(date +%s)
    fi

    if [ $(( now - last_replenish )) -ge 60 ]; then
        replenish; last_replenish=$now
    fi

    set -- $(phase_for "$elapsed")
    label=$1 mult=$2

    if [ "$label" != "$last_label" ]; then
        printf '\r%*s\r' 100 ''
        echo "  [${elapsed}s] phase: $label (~$(awk -v r="$RPS" -v m="$mult" 'BEGIN{printf "%.1f", r*m}') req/s)"
        last_label=$label
    fi

    # Under load, error share rises. Weighted mix out of 20 draws.
    case $(( RANDOM % 20 )) in
        0|1|2|3|4|5|6|7|8)
            post_order "${SKUS[$((RANDOM % ${#SKUS[@]}))]}" $(( RANDOM % 3 + 1 )) $(( RANDOM % 3 + 1 )) ;;
        9|10|11)  get_order ;;
        12|13|14) get_inventory "${SKUS[$((RANDOM % ${#SKUS[@]}))]}" ;;
        15)       reserve_inventory "${SKUS[$((RANDOM % ${#SKUS[@]}))]}" ;;
        16|17)    post_order "SKU-1004" 999 2 ;;
        18)       post_order "SKU-1001" 1 9999 ;;
        19)       get_inventory_missing ;;
    esac

    i=$((i+1))
    if [ $(( i % 25 )) = 0 ]; then
        printf '\r  %ds/%ds %-8s | %d reqs | order %d ok %d 409 %d 404 %d 5xx | inventory %d get %d post %d 404   ' \
            "$elapsed" "$DURATION" "$label" "$total" \
            "$ord_ok" "$ord_409" "$ord_404" "$ord_5xx" "$inv_get" "$inv_post" "$inv_404"
    fi

    # Jitter of 0.7x to 1.3x on top of the phase, so even one phase is not flat.
    jitter=$(( 70 + RANDOM % 60 ))
    sleep "$(awk -v r="$RPS" -v m="$mult" -v j="$jitter" 'BEGIN{d=(j/100.0)/(r*m); if(d<0.02)d=0.02; printf "%.3f", d}')"
done

printf '\r%*s\r' 120 ''
echo "Done. $total requests in ${DURATION}s"
echo
echo "  order-service      $((ord_ok+ord_409+ord_404+ord_5xx)) requests"
echo "    201 created      $ord_ok"
echo "    409 conflict     $ord_409"
echo "    404 not found    $ord_404"
echo "    5xx error        $ord_5xx"
echo "  inventory-service  $((inv_get+inv_post+inv_404)) direct requests"
echo "    200 GET stock    $inv_get"
echo "    201 POST reserve $inv_post"
echo "    404 unknown sku  $inv_404"
echo
echo "inventory-service also served every order-service request above as a child span."
echo "Metrics flush every 15s, so give Grafana a moment before screenshotting."
