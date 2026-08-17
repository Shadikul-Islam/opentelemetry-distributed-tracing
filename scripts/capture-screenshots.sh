#!/usr/bin/env bash
#
# Captures the README screenshots from Grafana using headless Chrome.
# Grafana runs with anonymous admin access, so no login step is needed.
#
#   ./scripts/generate-traffic.sh &     start traffic first
#   sleep 90                            let the 5m rate window fill
#   ./scripts/capture-screenshots.sh

set -u

GRAFANA=${GRAFANA:-http://localhost:3000}
TEMPO=${TEMPO:-http://localhost:3200}
OUT=${OUT:-docs/screenshots}
CHROME=${CHROME:-google-chrome}
WAIT=${WAIT:-25000}

mkdir -p "$OUT"

shot() {
    local name=$1 w=$2 h=$3 url=$4 wait=${WAIT:-25000}
    printf '  %-26s ' "$name"
    rm -f "$OUT/$name"
    timeout 120 "$CHROME" --headless --disable-gpu --no-sandbox --disable-dev-shm-usage \
        --hide-scrollbars --force-device-scale-factor=1 \
        --window-size="$w,$h" --virtual-time-budget="$wait" \
        --screenshot="$OUT/$name" "$url" >/dev/null 2>&1
    if [ -s "$OUT/$name" ]; then
        echo "ok  $(du -h "$OUT/$name" | cut -f1)"
    else
        echo "FAILED"
    fi
}

# Builds a Grafana Explore URL with a URL-encoded pane definition.
explore_url() {
    python3 - "$1" <<'PY'
import json, sys, urllib.parse
pane = json.loads(sys.argv[1])
print("?orgId=1&left=" + urllib.parse.quote(json.dumps(pane)))
PY
}

trace_by() {
    curl -s -G "$TEMPO/api/search" --data-urlencode "q=$1" --data-urlencode 'limit=20' \
    | python3 -c "
import json,sys
ts=json.load(sys.stdin).get('traces') or []
ts=[t for t in ts if t.get('rootServiceName') and 'not yet' not in t.get('rootServiceName','')]
ts.sort(key=lambda t: -t.get('durationMs',0))
print(ts[0]['traceID'] if ts else '')"
}

echo "Capturing to $OUT"

# 1. Dashboard
shot dashboard-overview.png 1800 2100 \
  "$GRAFANA/d/traces-overview/distributed-tracing-overview?from=now-15m&to=now&kiosk"

# 2. Trace waterfall: pick the richest recent successful order trace
TID=$(trace_by '{name="POST /api/orders" && status!=error}')
[ -z "$TID" ] && TID=$(trace_by '{name="POST /api/orders"}')
if [ -n "$TID" ]; then
    q=$(explore_url "{\"datasource\":\"Tempo\",\"queries\":[{\"refId\":\"A\",\"datasource\":{\"type\":\"tempo\",\"uid\":\"Tempo\"},\"queryType\":\"traceql\",\"query\":\"$TID\"}],\"range\":{\"from\":\"now-1h\",\"to\":\"now\"}}")
    shot trace-waterfall.png 1800 1300 "$GRAFANA/explore$q"
    # span-detail-db.png is not captured here: expanding a span is a click, not a
    # URL. Open the same trace in Grafana, click a SELECT span, and screenshot.
else
    echo "  trace-waterfall.png        SKIPPED (no trace found)"
fi

# 3. Service graph. Needs a longer budget than the rest: the node graph computes its
# layout asynchronously and screenshots as "Computing layout" if rushed.
q=$(explore_url '{"datasource":"Tempo","queries":[{"refId":"A","datasource":{"type":"tempo","uid":"Tempo"},"queryType":"serviceMap"}],"range":{"from":"now-30m","to":"now"}}')
WAIT=60000 shot service-graph.png 1800 1250 "$GRAFANA/explore$q"

# 4. TraceQL search
q=$(explore_url '{"datasource":"Tempo","queries":[{"refId":"A","datasource":{"type":"tempo","uid":"Tempo"},"queryType":"traceql","query":"{ duration > 20ms }","limit":20,"tableType":"traces"}],"range":{"from":"now-30m","to":"now"}}')
shot tempo-search.png 1800 1200 "$GRAFANA/explore$q"

# 5. Error trace: prefer a 5xx, fall back to a 409
ETID=$(trace_by '{status=error}')
[ -z "$ETID" ] && ETID=$(trace_by '{span.http.response.status_code=409}')
if [ -n "$ETID" ]; then
    q=$(explore_url "{\"datasource\":\"Tempo\",\"queries\":[{\"refId\":\"A\",\"datasource\":{\"type\":\"tempo\",\"uid\":\"Tempo\"},\"queryType\":\"traceql\",\"query\":\"$ETID\"}],\"range\":{\"from\":\"now-1h\",\"to\":\"now\"}}")
    shot error-trace.png 1800 1300 "$GRAFANA/explore$q"
else
    echo "  error-trace.png           SKIPPED (no error trace found)"
fi

echo
ls -la "$OUT"/*.png 2>/dev/null || echo "no screenshots produced"
