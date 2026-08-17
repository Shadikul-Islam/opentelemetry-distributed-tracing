<h1 align="center">Production-Grade Distributed Tracing</h1>

<p align="center">
  <b>OpenTelemetry | OpenTelemetry Collector | Grafana Tempo | Prometheus | Grafana</b>
</p>

<p align="center">
  <img alt="OpenTelemetry" src="https://img.shields.io/badge/OpenTelemetry-Java%20Agent%202.10-blue">
  <img alt="Collector" src="https://img.shields.io/badge/Collector-contrib%200.111.0-blueviolet">
  <img alt="Tempo" src="https://img.shields.io/badge/Grafana%20Tempo-2.4.1-orange">
  <img alt="Prometheus" src="https://img.shields.io/badge/Prometheus-v2.54.1-critical">
  <img alt="Grafana" src="https://img.shields.io/badge/Grafana-10.4.2-yellow">
  <img alt="Docker" src="https://img.shields.io/badge/Docker%20Compose-v2-2496ED">
</p>

A centralized distributed tracing platform built with OpenTelemetry, plus two working
microservices that prove it handles different technology stacks. A Java service and a
PHP service call each other, both talk to MySQL, and the whole request shows up as one
trace in Grafana.

---

## Table of Contents

| SL | Topic |
| :---: | --- |
| 01 | [Overview](#01) |
| 02 | [The Problem](#02) |
| 03 | [How It Works](#03) |
| 04 | [Why This Counts as Production-Grade](#04) |
| 05 | [Architecture](#05) |
| 06 | [Versions Used](#06) |
| 07 | [Project Structure](#07) |
| 08 | [order-service (Java)](#08) |
| 09 | [inventory-service (PHP)](#09) |
| 10 | [Database Design](#10) |
| 11 | [Telemetry Pipeline](#11) |
| 12 | [What a Trace Looks Like](#12) |
| 13 | [What You Can Do With It](#13) |
| 14 | [Running the Stack](#14) |
| 15 | [API Reference](#15) |
| 16 | [Test Results](#16) |
| 17 | [Screenshots](#17) |
| 18 | [Problems Hit and How They Were Fixed](#18) |
| 19 | [Before Going to Production](#19) |
| 20 | [Troubleshooting](#20) |
| 21 | [Adding More Services](#21) |
| 22 | [Conclusion](#22) |

<br>

## <a name="01">01. Overview</a>

One HTTP request into this system travels through:

```
Client -> Java service -> MySQL -> PHP service -> MySQL -> back to client
```

and produces a single trace with every hop, every SQL query, and every service
boundary visible in one waterfall view.

What this project shows:

| Area | Detail |
| --- | --- |
| Instrumentation | Two opposite approaches, zero-code for Java and hand-written for PHP, producing the same output |
| Context propagation | W3C Trace Context (`traceparent`), which is the open standard |
| Runtime coverage | Includes PHP 7.2, which the official OpenTelemetry SDK does not support at all |
| Metrics | Rate, error and duration metrics built from spans, so a service with no metrics endpoint is still measurable |
| Correlation | Jump from a metric spike to the exact trace, and from a trace back to its metrics |
| Deployment | One `docker-compose up`, seven containers, everything provisioned |

The stack was built, deployed and tested end to end. Results are in [Test Results](#16).

<br>

## <a name="02">02. The Problem</a>

Applications are no longer single programs. In production a system is usually split
across many services running on different servers in different languages, such as
Java, PHP, Node.js, Python, Go, and frontend apps.

When one user request passes through several services, normal logging stops being
enough. Logs cannot easily answer:

- Where is the request slow?
- Which service caused the failure?
- How much time did each service take?
- Which downstream dependency is the bottleneck?
- What does the full request path actually look like?

Without central tracing, this is what happens day to day:

| Problem | What it costs you |
| --- | --- |
| No cross-service view | Finding a root cause means checking several log systems and asking several teams |
| Bottlenecks stay hidden | You optimise by guessing |
| Dependencies are undocumented | You find out what breaks only after it breaks |
| Every stack monitored differently | Java, PHP and Node each need their own tools and knowledge |
| Old services left out | The oldest and least understood services stay invisible |

That last row is the hard one. Observability tools are written for current runtimes,
but real systems contain services that are older than those tools. If your tracing
platform cannot include them, you have blind spots exactly where problems tend to
start.

<br>

## <a name="03">03. How It Works</a>

Every service, whatever language it uses and however old it is, sends data to one
backend using one protocol.

```
Applications  --OTLP-->  Collector (gateway)  -->  Tempo       (traces)
                                              -->  Prometheus  (metrics from spans)
                                                        -->  Grafana (dashboards)
```

Three ideas make this work:

**1. OTLP is a data format, not a library.**
Any service that can send an HTTP POST with a JSON body can join. That is how PHP 7.2,
which has no official SDK, still participates fully.

**2. The Collector sits in the middle as a gateway.**
Services only know one address. Filtering, metric generation and backend routing all
happen in the Collector, so you can change the trace backend without redeploying any
application.

**3. Both services use the same attribute names.**
Because they follow the same semantic conventions, one dashboard query covers both
without any special handling.

<br>

## <a name="04">04. Why This Counts as Production-Grade</a>

These are deliberate choices, not defaults.

| # | Practice | How it is done | Why it matters |
| :---: | --- | --- | --- |
| 1 | Collector as a gateway | Services send to one OTLP endpoint and never talk to Tempo directly | You can swap the backend without touching application code |
| 2 | Health traffic filtered out | `filter` processor drops `/actuator/health`, `/actuator/prometheus` and `/health` | Health checks run every 10 seconds and would otherwise flood the traces and distort the metrics |
| 3 | Cardinality kept under control | Span names and `http.route` use route templates like `/api/inventory/{sku}` | Using the raw path would create a separate metric series for every SKU and eventually break Prometheus |
| 4 | Standard attribute names | Stable HTTP conventions (`http.request.method`, `url.path`, `http.route`) on both services | Dashboards and alerts work across services with no translation |
| 5 | Correct span status rules | Only 5xx marks a span as an error, 4xx stays unset on both services | A 404 is a caller mistake, not a service failure, so mixing them makes error alerts useless |
| 6 | Tracing fails safely | The PHP exporter uses a 500 ms connect timeout and a 2 s total timeout, and never raises errors | If the Collector goes down, your application must keep serving requests |
| 7 | Metrics for services without metrics | The `spanmetrics` connector builds RED metrics from spans | PHP 7.2 has no metrics endpoint but still gets rate, error and latency data |
| 8 | Two-way correlation | Exemplars put `trace_id` into metric samples, and `tracesToMetrics` links back the other way | Click a latency spike and open the exact trace that caused it |
| 9 | Real service boundaries | Separate databases and separate database users per service | Forces the network call that the trace is meant to show, and stops hidden coupling |
| 10 | Prepared statements everywhere | JDBC, and PDO with `ATTR_EMULATE_PREPARES => false` | `db.statement` shows the real parameterised SQL and there is no injection risk |
| 11 | Correct locking | `SELECT ... FOR UPDATE` before changing stock | Two orders for the last item cannot both succeed |
| 12 | Every image version-pinned | Including Grafana | `:latest` changed behaviour during this project and broke dashboard loading, see [section 18](#18) |
| 13 | Logs linked to traces | `trace_id` and `span_id` added to the Java log pattern through MDC | Any log line can be traced back to the request that produced it |
| 14 | Startup order handled | `depends_on` with `condition: service_healthy` | No race between database setup and application start |

**One important note.** The list above is about architecture and instrumentation, and
those are production-grade. Several deployment settings in this repository are
simplified on purpose because it runs locally: passwords in plain text, TLS turned off,
anonymous Grafana access, traces on local disk, and 100% sampling. All of those are
listed in [Before Going to Production](#19).

<br>

## <a name="05">05. Architecture</a>

### 5.1 Request and telemetry flow

```
                            HTTP request
                                 |
                                 v
                  +----------------------------+
                  |       order-service        |   Java 11, Spring Boot 2.7
                  |   OpenTelemetry Java agent |   no tracing code written
                  +----------------------------+
                     |                       |
                JDBC |                       | HTTP with traceparent header
                     v                       v
            +--------------+   +----------------------------+
            |    MySQL     |   |     inventory-service      |   PHP 7.2, Apache
            |   orderdb    |   |    hand-written tracer     |   tracer written by hand
            +--------------+   +----------------------------+
                                             |
                                         PDO |
                                             v
                                   +------------------+
                                   |      MySQL       |
                                   |   inventorydb    |
                                   +------------------+

              both services send OTLP spans over HTTP port 4318
                                 |
                                 v
                  +----------------------------+
                  |   OpenTelemetry Collector  |
                  |          (gateway)         |
                  | filter -> batch -> connect |
                  +----------------------------+
                     |                       |
              traces |                       | spanmetrics and servicegraph
                     v                       v
            +--------------+        +----------------+
            |    Tempo     |        |   Prometheus   |
            +--------------+        +----------------+
                     |                       |
                     +-----------+-----------+
                                 v
                        +-----------------+
                        |     Grafana     |
                        +-----------------+
```

### 5.2 The two services side by side

The two services are instrumented in opposite ways on purpose, to show the platform
does not care how the spans were produced.

| | order-service | inventory-service |
| --- | --- | --- |
| Runtime | Java 11 (Temurin) | PHP 7.2.24 |
| Framework | Spring Boot 2.7.18 | Apache with mod_php, custom front controller |
| Instrumentation | OpenTelemetry Java agent 2.10.0 | About 450 lines of hand-written tracer |
| Tracing code in the source | None | `src/Tracing/` |
| Spans produced | HTTP server, HTTP client, JDBC | HTTP server, PDO connect, query and transaction, business logic |
| Metrics endpoint | `/actuator/prometheus` via Micrometer | None, metrics come from spans |
| Role in the trace | Starts the trace | Continues the trace |

<br>

## <a name="06">06. Versions Used</a>

| Layer | Component | Version | Purpose |
| --- | --- | --- | --- |
| Application | Java | 11 (Eclipse Temurin, jammy) | order-service runtime |
| | Spring Boot | 2.7.18 | Last version that supports Java 11 |
| | Maven | 3.6.3 | Build tool |
| | PHP | 7.2.24 | inventory-service runtime |
| | Apache HTTP Server | 2.4 with mod_php | Runs the PHP service |
| | MySQL | 8.0 | Database for both services |
| Instrumentation | OpenTelemetry Java Agent | 2.10.0 | Automatic instrumentation, no code changes |
| | Custom OTLP tracer | 1.0.0 | PHP 7.2 instrumentation |
| Telemetry | OpenTelemetry Collector (contrib) | 0.111.0 | Gateway, filtering, metric generation |
| | Grafana Tempo | 2.4.1 | Trace storage and TraceQL search |
| | Prometheus | v2.54.1 | Metric storage with exemplars |
| | Grafana | 10.4.2 | Dashboards and trace viewing |
| Platform | Docker Compose | v2 | Runs everything |

The `contrib` build of the Collector is required. The `spanmetrics` and `servicegraph`
connectors and the Prometheus exporter are not in the core build.

<br>

## <a name="07">07. Project Structure</a>

Every component owns everything it needs in one folder: its code, its Dockerfile,
its config, and its own compose file. The root `compose.yaml` does nothing except
stitch the components together, so a component can be moved, versioned, or extracted
into its own repository without hunting for pieces elsewhere.

```
.
+-- compose.yaml                      root, includes the four components
+-- .env.example                      copy to .env and fill in
|
+-- micro-services/
|   +-- order-service/                Java 11, Spring Boot 2.7
|   |   +-- compose.yaml
|   |   +-- Dockerfile                multi-stage build, attaches the agent
|   |   +-- pom.xml
|   |   \-- src/main/
|   |       +-- java/com/tracing/order/
|   |       |   +-- client/           outbound call to the PHP service
|   |       |   +-- config/           RestTemplate and properties
|   |       |   +-- exception/
|   |       |   +-- model/
|   |       |   +-- repository/       JdbcTemplate, plain SQL
|   |       |   +-- service/
|   |       |   \-- web/              controller and error mapping
|   |       \-- resources/application.properties
|   |
|   \-- inventory-service/            PHP 7.2, Apache
|       +-- compose.yaml
|       +-- Dockerfile
|       +-- bootstrap.php             autoloader, no Composer needed
|       +-- docker/vhost.conf
|       +-- public/index.php          front controller
|       \-- src/
|           +-- Config.php
|           +-- Controller/
|           +-- Db/Database.php       PDO wrapper that emits spans
|           +-- Domain/
|           +-- Http/Router.php       route templates
|           +-- Repository/
|           \-- Tracing/              the hand-written tracer
|               +-- TraceContext.php  reads the traceparent header
|               +-- Span.php
|               +-- Tracer.php        span lifecycle and flushing
|               \-- OtlpHttpExporter.php   OTLP JSON over HTTP
|
+-- infrastructure/
|   \-- mysql/                        shared dependency, not a service
|       +-- compose.yaml
|       \-- init/01-schema.sql        databases, users, tables, seed rows
|
+-- tracing/                          the observability stack
|   +-- compose.yaml                  tempo, collector, prometheus, grafana
|   +-- collector/config.yaml
|   +-- tempo/tempo.yaml
|   +-- prometheus/prometheus.yml
|   \-- grafana/provisioning/         datasources and the dashboard
|
+-- scripts/
|   +-- generate-traffic.sh           load generator for real dashboard data
|   \-- capture-screenshots.sh        captures the README images
|
+-- docs/screenshots/
\-- README.md
```

### Why it is laid out this way

| Folder | Owns | Rule |
| --- | --- | --- |
| `micro-services/<name>/` | One deployable service: code, Dockerfile, compose file | Nothing about a service lives outside its folder |
| `infrastructure/` | Shared stateful dependencies | A service never reaches in to change the schema |
| `tracing/` | The observability platform | Contains no application code and no service names in its config |
| `compose.yaml` | Composition only | No service definitions of its own |

The root file uses the Compose `include` directive, so each component file stands on
its own rather than being a fragment.

The two components with no cross-component dependencies can be run entirely alone:

```bash
docker-compose -f tracing/compose.yaml --env-file .env up -d            # observability only
docker-compose -f infrastructure/mysql/compose.yaml --env-file .env up -d
```

The two services cannot, because each one declares `depends_on` against `mysql` and
`otel-collector`, and Compose rejects a project referencing a service it cannot see.
That is the correct trade-off: the dependency is real, and declaring it is what gives
health-gated startup ordering. Run them either from the root file, which is the normal
path:

```bash
docker-compose up -d                          # everything
docker-compose up -d --build order-service    # one service, dependencies resolved
docker-compose build order-service            # rebuild one image
```

or by passing the files the dependencies live in:

```bash
docker-compose \
  -f infrastructure/mysql/compose.yaml \
  -f tracing/compose.yaml \
  -f micro-services/inventory-service/compose.yaml \
  -f micro-services/order-service/compose.yaml \
  --env-file .env up -d
```

One detail that is easy to get wrong: each include sets `env_file: .env` explicitly.
An included compose file resolves variables against its own directory by default, so
without that line every component would look for a `.env` beside itself and every
`${VAR}` would silently resolve to empty.

<br>

## <a name="08">08. order-service (Java)</a>

### 8.1 What it does

This is the entry point and the service that starts every trace. It owns the `orderdb`
database.

### 8.2 Request flow for `POST /api/orders`

| Step | What happens | Span created |
| :---: | --- | --- |
| 1 | Validate the request body | none |
| 2 | Read the customer from `orderdb` | JDBC client span |
| 3 | Build an order reference like `ORD-1A2B3C4D` | none |
| 4 | Ask inventory-service to reserve stock | HTTP client span, sends `traceparent` |
| 5 | Insert the order into `orderdb` | JDBC client span |
| 6 | Return `201 Created` | status recorded on the server span |

If any step fails, the rest is skipped and a specific HTTP status is returned.

### 8.3 How it is instrumented

There is no tracing code in this service. The agent is attached when the container
starts:

```dockerfile
ENTRYPOINT ["sh", "-c", "exec java -javaagent:/app/opentelemetry-javaagent.jar $JAVA_OPTS -jar /app/order-service.jar"]
```

and everything is configured with environment variables:

```yaml
OTEL_SERVICE_NAME: order-service
OTEL_EXPORTER_OTLP_ENDPOINT: http://otel-collector:4318
OTEL_EXPORTER_OTLP_PROTOCOL: http/protobuf     # required on port 4318
OTEL_TRACES_EXPORTER: otlp
OTEL_METRICS_EXPORTER: none                    # Micrometer handles metrics
OTEL_LOGS_EXPORTER: none
OTEL_PROPAGATORS: tracecontext,baggage
OTEL_RESOURCE_ATTRIBUTES: service.version=1.0.0,deployment.environment=local
```

The agent instruments the servlet container, `RestTemplate` and the JDBC driver by
itself, and adds `trace_id` and `span_id` to the logging context.

The repository uses `JdbcTemplate` with plain SQL instead of JPA, so the query you see
in the trace as `db.statement` is exactly the query written in the code. No ORM sits in
between.

### 8.4 Error handling

Getting status codes right matters for tracing, because the agent decides span status
from the HTTP response.

| Situation | Status | Span status |
| --- | :---: | --- |
| Bad request body | 400 | unset |
| Customer or order not found | 404 | unset |
| Not enough stock (came from PHP) | 409 | unset |
| inventory-service unreachable | 503 | error |
| Anything unexpected | 500 | error |

<br>

## <a name="09">09. inventory-service (PHP)</a>

### 9.1 The constraint

The official `open-telemetry/opentelemetry-php` SDK needs PHP 8.0 or newer. This
service runs on PHP 7.2.24. There is no library that works.

This situation is common in real systems. The oldest services are the hardest to
upgrade and often the most important. Leaving them out of tracing means having no
visibility exactly where you need it most.

The approach taken here is to write the necessary part of the OpenTelemetry
specification directly. OTLP is a documented format, so you do not need a vendor
library to produce valid data.

### 9.2 What the tracer contains

| File | Approx. lines | What it does |
| --- | :---: | --- |
| `TraceContext.php` | 150 | Reads and validates the `traceparent` header, generates IDs |
| `Span.php` | 170 | Holds timings, attributes, status and exception events |
| `Tracer.php` | 180 | Starts and ends spans, keeps parent-child nesting, flushes the batch |
| `OtlpHttpExporter.php` | 200 | Converts spans to OTLP JSON and posts them to the Collector |

### 9.3 Continuing the trace

```
traceparent: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
             |  |                                |                |
             |  trace id (16 bytes)              |                sampled flag
             version                             parent span id (8 bytes)
```

Reusing the incoming trace ID is what joins the two services together. Without it the
PHP spans would form their own separate trace and the request would look like two
unrelated operations.

If the header is missing or malformed it is ignored and a new trace is started.
Tracing must never break a request.

### 9.4 Three details that are easy to get wrong

Each of these fails silently if you get it wrong.

| Detail | What is required | What happens otherwise |
| --- | --- | --- |
| ID encoding | `traceId` and `spanId` are hex strings in OTLP JSON, even though they are bytes in protobuf. The spec makes this an explicit exception | The Collector rejects the payload |
| 64-bit numbers | Timestamps are sent as strings | JSON parsers round them through a float and corrupt them |
| Timestamp precision | Built from the string form of `microtime()`, not `microtime(true) * 1e9` | A float carries about 15 digits and a nanosecond timestamp needs 19, so the last digits become garbage. `hrtime()` would be easier but only exists from PHP 7.3 |

### 9.5 Spans it produces

| Span | Kind | Note |
| --- | --- | --- |
| `POST /api/inventory/{sku}/reserve` | server | Named with the route template, not the real path |
| `inventory.reserve` | internal | A business logic span added by hand |
| `connect inventorydb` | client | mod_php opens a new connection on every request, so this cost is kept visible instead of hidden inside the first query |
| `transaction inventorydb` | client | Makes the lock window measurable |
| `SELECT`, `UPDATE`, `INSERT` | client | Full `db.*` attributes |

### 9.6 How spans are sent

Spans are flushed from a `register_shutdown_function` handler, so they are still sent
if a fatal error ends the request early. The send is synchronous with short timeouts,
500 ms to connect and 2 s in total. If the Collector is down you lose spans, never
requests.

The response includes an `X-Trace-Id` header so you can paste the ID straight into
Grafana.

<br>

## <a name="10">10. Database Design</a>

One MySQL instance holds two databases with separate users. Neither service can read
the other's tables. This keeps the service boundary real and forces the network call
that the trace is there to show.

**orderdb**, owned by `order_user`

| Table | Columns |
| --- | --- |
| `customers` | `id`, `name`, `email`, `tier`, `created_at` |
| `orders` | `id`, `order_ref`, `customer_id`, `sku`, `quantity`, `unit_price`, `total_amount`, `status`, `created_at` |

**inventorydb**, owned by `inventory_user`

| Table | Columns |
| --- | --- |
| `inventory` | `sku` (primary key), `product_name`, `unit_price`, `available_qty`, `reserved_qty`, `warehouse`, `updated_at` |
| `reservations` | `id`, `sku`, `order_ref`, `quantity`, `created_at` |

**Seed data**

| Customers | Stock |
| --- | --- |
| 1 Ayesha Rahman (GOLD) | `SKU-1001` Mechanical Keyboard, 120 units |
| 2 Tanvir Hasan (STANDARD) | `SKU-1002` USB-C Docking Station, 45 |
| 3 Nusrat Jahan (PLATINUM) | `SKU-1003` 27-inch 4K Monitor, 18 |
| | `SKU-1004` Noise Cancelling Headset, 3 |
| | `SKU-1005` Ergonomic Mouse, 260 |

`SKU-1004` is seeded with only 3 units on purpose, so you can trigger a real failure
across both services whenever you want to see an error trace.

<br>

## <a name="11">11. Telemetry Pipeline</a>

### 11.1 Collector layout

```
receivers      otlp (gRPC 4317, HTTP 4318)
    |
processors     memory_limiter -> filter/drop_monitoring -> batch
    |
    +-- exporter    otlp/tempo      trace storage
    +-- connector   spanmetrics     RED metrics
    +-- connector   servicegraph    dependency edges
                        |
                        +-- exporter  prometheus (port 8889)
```

### 11.2 What each stage does

| Stage | Setting | Reason |
| --- | --- | --- |
| `memory_limiter` | 512 MiB limit, 128 MiB spike | The Collector should drop data rather than run out of memory |
| `filter/drop_monitoring` | Drops `/actuator/health`, `/actuator/prometheus`, `/health` | Placed before the connectors, so health checks pollute neither Tempo nor the metrics |
| `batch` | 1 s or 1024 spans | Reduces export overhead |
| `spanmetrics` | Buckets from 5 ms to 10 s, dimensions `http.request.method`, `http.response.status_code`, `http.route`, exemplars on | Produces metrics for every service, including ones with no metrics endpoint |
| `servicegraph` | 10 s store TTL | Matches client spans to server spans to build the dependency map |
| `prometheus` exporter | `enable_open_metrics`, `resource_to_telemetry_conversion` | OpenMetrics is needed for exemplars to survive the scrape, and the conversion turns `service.name` into a `service_name` label |

Both the old dimension names (`http.method`) and the current ones
(`http.request.method`) are listed, so a service still running an older agent does not
silently lose its labels.

### 11.3 What Prometheus scrapes

| Job | Target | Contents |
| --- | --- | --- |
| `otel-collector-spanmetrics` | `otel-collector:8889` | RED and service graph metrics for all services |
| `otel-collector-internal` | `otel-collector:8888` | Collector health, spans received, refused and dropped |
| `order-service` | `order-service:8080/actuator/prometheus` | JVM, GC, threads and connection pool |

Prometheus runs with `--enable-feature=exemplar-storage`. Without it you cannot jump
from a metric to a trace.

<br>

## <a name="12">12. What a Trace Looks Like</a>

One `POST /api/orders` creates 11 spans across 2 services and 2 databases. Indentation
shows the parent-child nesting.

```
POST /api/orders                            order-service      SERVER     20.65 ms
  SELECT orderdb.customers                  order-service      CLIENT      0.68 ms
  POST                                      order-service      CLIENT     11.72 ms
    POST /api/inventory/{sku}/reserve       inventory-service  SERVER      5.92 ms
      inventory.reserve                     inventory-service  INTERNAL    5.86 ms
        connect inventorydb                 inventory-service  CLIENT      1.44 ms
        transaction inventorydb             inventory-service  CLIENT      4.35 ms
          SELECT inventorydb.inventory      inventory-service  CLIENT      0.55 ms
          UPDATE inventorydb.inventory      inventory-service  CLIENT      0.48 ms
          INSERT inventorydb.reservations   inventory-service  CLIENT      0.39 ms
  INSERT orderdb.orders                     order-service      CLIENT      2.57 ms
```

Things worth noticing here:

- The PHP spans sit underneath a Java span. One trace, two languages, joined only by an
  HTTP header.
- Automatic spans and hand-written spans mix together and look the same in Grafana.
- Database work is attributed to the service that did it, with the real SQL visible on
  `db.statement`.
- The `transaction` span makes the lock window something you can measure instead of
  guess at.

<br>

## <a name="13">13. What You Can Do With It</a>

| Capability | How | Practical use |
| --- | --- | --- |
| Search traces | Tempo and TraceQL | `{ resource.service.name = "order-service" && duration > 250ms }` |
| RED metrics per service | `spanmetrics` connector | Rate, errors and duration, including for PHP |
| Latency per route | `span_name` and `http.route` labels | See which endpoint got slower |
| Database latency | Client span metrics | Separate slow queries from slow application code |
| Dependency map | `servicegraph` connector | `order-service -> inventory-service -> mysql` |
| Metric to trace | Prometheus exemplars | Click a spike, open the trace behind it |
| Trace to metric | `tracesToMetrics` on the Tempo datasource | From a span, see its rate and latency context |
| Log to trace | `trace_id` in the Java log pattern | Go from a log line to the whole request |
| JVM internals | Micrometer and Actuator | Heap, GC, threads, connection pool usage |

The provisioned dashboard is called **Distributed Tracing Overview** and has 10 panels:

| Row | Panels |
| --- | --- |
| Golden signals | Request rate, Error rate, p95 latency, Services reporting |
| Per service | Request rate by service, Errors by service, p95 latency by route, Database call latency |
| Traces | Recent traces, Slow traces over 250 ms |

<br>

## <a name="14">14. Running the Stack</a>

### 14.1 What you need

Docker and Docker Compose v2. You do not need Java, Maven or PHP installed. Both
services build inside containers, pinned to versions that match a typical Ubuntu 18.04
machine so you can also build them natively if you prefer.

### 14.2 Start it

`.env` holds the passwords and is deliberately not in git, so create it first:

```bash
cp .env.example .env
```

Then edit it and replace every `changeme`. The two database passwords must match the
users in `infrastructure/mysql/init/01-schema.sql`. Skipping this step does not fail
loudly: Compose only warns about unset variables and then starts MySQL with an empty
root password, which fails later in a confusing way.

Then, from the repository root:

```bash
docker-compose up -d --build
```

The first build downloads the Spring Boot dependencies, the OpenTelemetry Java agent
and the base images, so it takes a while. After that it is quick.

```bash
docker-compose ps
```

Wait until `order-service` and `inventory-service` show as healthy.

### 14.3 Addresses

| Service | URL | Note |
| --- | --- | --- |
| Grafana | http://localhost:3000 | Dashboards and trace search |
| Prometheus | http://localhost:9090 | Metric queries |
| Tempo | http://localhost:3200 | Trace API |
| order-service | http://localhost:8080 | Java API |
| inventory-service | http://localhost:8081 | PHP API |
| MySQL | localhost:3307 | Port 3306 inside the network. 3307 avoids clashing with a MySQL already on your machine |
| Collector OTLP HTTP | http://localhost:4318 | |
| Collector OTLP gRPC | localhost:4317 | |

### 14.4 Login

Set in `.env` at the repository root:

```bash
ADMIN_USER=sadik
ADMIN_PASSWORD=adminpass
```

Anonymous access is on with the Admin role, so you can skip the login screen when
showing the dashboards to someone.

Do not leave trailing spaces after values in `.env`. Docker Compose keeps them exactly
as written, so `sadik ` and `sadik` are two different logins.

### 14.5 Stopping and logs

```bash
docker-compose down          # stop but keep data
docker-compose down -v       # stop and delete volumes, schema will run again next time
docker-compose logs -f order-service inventory-service
```

<br>

## <a name="15">15. API Reference</a>

### order-service, http://localhost:8080

<details open>
<summary><b>POST /api/orders</b> place an order, produces the full two-service trace</summary>

```bash
curl -s -X POST http://localhost:8080/api/orders \
  -H 'Content-Type: application/json' \
  -d '{"customerId": 1, "sku": "SKU-1001", "quantity": 2}'
```

```json
{
  "orderRef": "ORD-560A513C",
  "customerId": 1,
  "customerName": "Ayesha Rahman",
  "sku": "SKU-1001",
  "productName": "Mechanical Keyboard 87-key",
  "quantity": 2,
  "unitPrice": 89.00,
  "totalAmount": 178.00,
  "status": "CONFIRMED",
  "availableQty": 118
}
```
</details>

<details>
<summary><b>GET /api/orders/{orderRef}</b> read an order back with current stock</summary>

```bash
curl -s http://localhost:8080/api/orders/ORD-560A513C
```
</details>

### inventory-service, http://localhost:8081

<details>
<summary><b>GET /api/inventory/{sku}</b> current stock for one SKU</summary>

```bash
curl -s -i http://localhost:8081/api/inventory/SKU-1003
```

```
HTTP/1.1 200 OK
X-Trace-Id: ce82a1c0e5db18c1e225dbf043d2f509
```
```json
{
  "sku": "SKU-1003",
  "productName": "27-inch 4K Monitor",
  "unitPrice": 329.99,
  "availableQty": 18,
  "reservedQty": 0,
  "warehouse": "CTG-02"
}
```

Called directly like this it starts its own trace, because no `traceparent` arrives.
</details>

<details>
<summary><b>POST /api/inventory/{sku}/reserve</b> reserve stock inside a transaction</summary>

```bash
curl -s -X POST http://localhost:8081/api/inventory/SKU-1001/reserve \
  -H 'Content-Type: application/json' \
  -d '{"orderRef": "ORD-MANUAL1", "quantity": 1}'
```
</details>

### Producing a failure trace

`SKU-1004` only has 3 units, so this returns 409 and shows how the error travels back
across the service boundary:

```bash
curl -s -i -X POST http://localhost:8080/api/orders \
  -H 'Content-Type: application/json' \
  -d '{"customerId": 2, "sku": "SKU-1004", "quantity": 999}'
```

```json
{
  "timestamp": "2026-08-16T12:00:12.117012Z",
  "status": 409,
  "error": "Conflict",
  "message": "Not enough stock for sku SKU-1004"
}
```

### Generating traffic

A few curl calls are enough to see one trace, but the dashboard panels use
`rate()` over a 5 minute window, so they need continuous traffic before they show
anything meaningful. Use the load generator:

```bash
./scripts/generate-traffic.sh                      # 15 minutes at about 4 req/s
DURATION=1800 RPS=6 ./scripts/generate-traffic.sh  # 30 minutes, busier
WITH_OUTAGE=1 ./scripts/generate-traffic.sh        # also creates real 5xx errors
```

`WITH_OUTAGE=1` stops inventory-service for 25 seconds halfway through. order-service
then returns 503, which produces genuine `STATUS_CODE_ERROR` server spans and a clear
incident shape on the graphs. This is the only way to get real server error data,
because 4xx deliberately does not mark a span as an error.

The script also tops the stock back up every 60 seconds. Without that the seeded
quantities sell out within a couple of minutes and every order starts returning 409.

<br>

## <a name="16">16. Test Results</a>

Everything below is real output captured from the running stack.

### 16.1 The trace

One `POST /api/orders` produced 11 spans across 2 services in a single trace. The full
waterfall is in [section 12](#12).

### 16.2 Metrics built from spans

```
service_name         span_kind              count
inventory-service    SPAN_KIND_CLIENT       152
inventory-service    SPAN_KIND_INTERNAL      32
inventory-service    SPAN_KIND_SERVER        34
order-service        SPAN_KIND_CLIENT        92
order-service        SPAN_KIND_SERVER        33
```

inventory-service has no metrics endpoint at all, yet it has complete rate, error and
latency data, all derived from its spans.

### 16.3 Dependency map

```
client              ->  server                 type
inventory-service   ->  inventorydb            database
order-service       ->  inventory-service      direct
order-service       ->  orderdb                database
user                ->  order-service          virtual_node
user                ->  inventory-service      virtual_node
```

### 16.4 Prometheus targets

```
job                             health
order-service                   up
otel-collector-internal         up
otel-collector-spanmetrics      up
```

### 16.5 Functional tests

| Test | Expected | Result |
| --- | --- | :---: |
| Place an order | 201, stock drops from 120 to 118 | Pass |
| Read the order back | 200 with current stock included | Pass |
| Order more than available | 409 passed from PHP through Java | Pass |
| Call PHP directly | 200 with `X-Trace-Id`, starts a new trace | Pass |
| Trace continuity | PHP spans nested under Java spans | Pass |
| Span status agreement | Both services leave 2xx unset | Pass |
| Dashboard provisioning | 10 panels and 2 datasources loaded | Pass |

<br>

## <a name="17">17. Screenshots</a>

These are captured automatically from the running stack with headless Chrome. Grafana
runs with anonymous admin access, so no login step is involved.

```bash
./scripts/generate-traffic.sh &     # start traffic
sleep 90                            # let the 5m rate window fill
./scripts/capture-screenshots.sh    # writes into docs/screenshots/
```

That captures five of the six. The span detail image is the exception: expanding a span
is a click rather than a URL, so open the same trace in Grafana, click a `SELECT` span
and screenshot it by hand.

One `POST /api/orders` across order-service, inventory-service and both databases.

<p align="center">
  <img src="docs/screenshots/trace-waterfall.png" alt="Distributed trace waterfall across Java and PHP services" width="900">
</p>

RED metrics for both services, built from spans.

<p align="center">
  <img src="docs/screenshots/dashboard-overview.png" alt="Grafana RED metrics dashboard" width="900">
</p>

order-service calling inventory-service, and both services calling their databases.

<p align="center">
  <img src="docs/screenshots/service-graph.png" alt="Service dependency graph" width="900">
</p>

`db.statement`, `db.operation` and `db.system` on a PDO span from the PHP service.

<p align="center">
  <img src="docs/screenshots/span-detail-db.png" alt="Database span attributes" width="900">
</p>

TraceQL filtering by service name and duration.

<p align="center">
  <img src="docs/screenshots/tempo-search.png" alt="TraceQL trace search" width="900">
</p>

A 409 out-of-stock failure travelling back across the service boundary.

<p align="center">
  <img src="docs/screenshots/error-trace.png" alt="Error trace across two services" width="900">
</p>

<br>

## <a name="18">18. Problems Hit and How They Were Fixed</a>

Real problems that came up while building this, not hypothetical ones. Most of them
fail silently, which is what makes them worth writing down.

### 18.1 Spring Boot no longer manages the old MySQL driver

The build failed with `'dependencies.dependency.version' for
mysql:mysql-connector-java:jar is missing`. Spring Boot 2.7.8 followed MySQL's rename
and dropped `mysql:mysql-connector-java` from its managed dependencies, so on 2.7.18
the old coordinates inherit no version.

Use `com.mysql:mysql-connector-j`. The driver class name is unchanged.

### 18.2 Wrong OTLP protocol throws away every span

The Java agent defaults to gRPC. Point it at port 4318, which is HTTP, and it sends
gRPC frames to an HTTP endpoint: every span is dropped and nothing reports an error.

Always set `OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf` with port 4318, or use 4317 and
leave the default. This is the most common OpenTelemetry misconfiguration there is.

### 18.3 Debian Buster reached end of life

`apt-get update` returns 404 inside both `php:7.2-apache` and `openjdk:11-jre-slim`,
because both are built on Debian Buster and its repositories have been retired.

The PHP image now installs nothing through apt, and its health check uses PHP's own
curl extension instead of the curl binary. The Java runtime moved to
`eclipse-temurin:11-jre-jammy`, which is better anyway since the `openjdk` images are
deprecated.

### 18.4 MySQL 8 authentication does not work with PHP 7.2

`The server requested authentication method unknown to the client`. MySQL 8 defaults to
`caching_sha2_password` and the mysqlnd driver in PHP 7.2 cannot negotiate it.

Start the server with `--default-authentication-plugin=mysql_native_password` and create
users with an explicit `IDENTIFIED WITH mysql_native_password` clause.

### 18.5 PDO rejects a repeated named parameter

`SQLSTATE[HY093]: Invalid parameter number`. With `ATTR_EMULATE_PREPARES => false`, PDO
rewrites named parameters to positional ones and cannot bind the same name twice.

Use two placeholder names for the same value:

```sql
UPDATE inventory
   SET available_qty = available_qty - :taken,
       reserved_qty  = reserved_qty  + :held
 WHERE sku = :sku
```

### 18.6 An unpinned Grafana image broke dashboard loading

Grafana reported healthy while serving a stale dashboard. The only clue was in its log:

```
failed to save dashboard ... deprecatedInternalID=3771418254188544 is already in use
```

`grafana/grafana:latest` now resolves to Grafana 12, whose rewritten dashboard API
refuses to save a provisioned dashboard when an older one in a persisted volume holds
the same internal id.

Pinned to `grafana/grafana:10.4.2`, which matches the dashboard's `schemaVersion`, and
recreated the volume. Every image in the stack is pinned now.

### 18.7 The two services disagreed on span status

inventory-service reported `STATUS_CODE_OK` on success while order-service reported
`STATUS_CODE_UNSET`. The PHP tracer was setting OK on 2xx, but the HTTP conventions
require status to be left unset for 1xx, 2xx and 3xx; OK is reserved for an explicit
application override. Any metric query grouped by `status_code` would have split across
two label values and undercounted both services.

PHP now leaves 2xx unset, matching the Java agent exactly.

### 18.8 Health checks would have drowned out real traffic

Prometheus scrapes every 15 seconds and the container health checks run every 10, and
each one is a traced HTTP request.

A `filter` processor drops health and metrics paths, and it runs before the
`spanmetrics` and `servicegraph` connectors so those requests reach neither storage nor
metrics. The PHP service also skips creating spans for `/health` entirely.

### 18.9 Renaming the compose project orphaned its volumes

After renaming the project, startup failed with `volume "tempo_data" already exists but
was created for project ...`. Compose refuses to reuse volumes labelled for a different
project, and `docker-compose down` under the new name cannot see the old resources.

Run `docker-compose down -v` **before** changing the project name, or remove the old
containers, network and volumes by name afterwards.

## <a name="19">19. Before Going to Production</a>

The architecture and instrumentation are production-grade. The settings below are
simplified so the stack runs easily on one machine, and each one needs to change before
a real deployment.

| # | Area | What it is now | What it should be |
| :---: | --- | --- | --- |
| 1 | Credentials | Plain text in `.env` and `01-schema.sql` | A secrets manager such as Vault, AWS Secrets Manager or Kubernetes Secrets. Never commit passwords |
| 2 | Transport security | `insecure: true` on OTLP, `useSSL=false` on JDBC | mTLS between services and the Collector, TLS to the database |
| 3 | Collector access | Open OTLP endpoint | An authenticator extension or network policy. Never expose OTLP publicly without auth |
| 4 | Grafana access | Anonymous with Admin role | SSO or OAuth, least-privilege roles, anonymous access off |
| 5 | Trace storage | Tempo on local disk, 24 hour retention, single instance | Object storage such as S3, GCS or Azure Blob, replicated, retention set by your policy |
| 6 | Sampling | Everything traced | Tail-based sampling in the Collector. Keep all errors and slow traces, sample the rest |
| 7 | Collector availability | One instance | Several instances behind a load balancer, with an agent tier and a gateway tier |
| 8 | Database auth | `mysql_native_password` | `caching_sha2_password` wherever the client supports it. Keep native only for PHP 7.2 |
| 9 | Database privileges | `GRANT ALL` on each database | Only the specific privileges each service needs |
| 10 | PHP export path | Synchronous, inside the request | A local Collector agent over a Unix socket, or an async handoff, so export latency is out of the request path |
| 11 | Sensitive data | Only statement sanitising | A proper redaction policy. Review `db.statement` and HTTP attributes for personal data |
| 12 | Resource limits | Containers unbounded | CPU and memory limits, plus Collector queue sizing and backpressure settings |
| 13 | Alerting | None | Alerts on error rate and latency against your SLOs, plus Collector `refused_spans` and `dropped_spans` |
| 14 | PHP version | 7.2, end of life | Plan the upgrade to a supported PHP so you can use the official SDK. The hand-written tracer is a bridge, not a permanent answer |

<br>

## <a name="20">20. Troubleshooting</a>

<details>
<summary><b>No traces at all</b></summary>

```bash
docker-compose logs otel-collector | tail -50
curl -s http://localhost:8888/metrics | grep receiver_accepted_spans
```

If `otelcol_receiver_accepted_spans` is not going up, the services are not reaching the
Collector. For Java the usual cause is the protocol mismatch in [section 18.2](#18).
</details>

<details>
<summary><b>Java traces work but PHP spans are missing</b></summary>

```bash
docker-compose logs inventory-service | grep '\[tracing\]'
```

The exporter logs failures through `error_log`, which Apache sends to the container's
stderr.
</details>

<details>
<summary><b>You get two separate traces instead of one</b></summary>

The `traceparent` header is not arriving. Check that the agent is attached:

```bash
docker-compose logs order-service | head -20
```

You should see `opentelemetry-javaagent` mentioned. Also check that
`OTEL_PROPAGATORS` includes `tracecontext`.
</details>

<details>
<summary><b>inventory-service cannot connect to MySQL</b></summary>

`The server requested authentication method unknown to the client` means the
`mysql_native_password` setting is missing. Recreate the volume so the init script runs
again:

```bash
docker-compose down -v && docker-compose up -d
```
</details>

<details>
<summary><b>Dashboard panels are empty</b></summary>

Metrics built from spans only exist after some traffic, and the Collector flushes them
every 15 seconds. Send a few requests and wait a moment.
</details>

<details>
<summary><b>Grafana is showing an old dashboard</b></summary>

```bash
docker logs grafana 2>&1 | grep "failed to save dashboard"
```

If you see an internal ID conflict, recreate the volume:

```bash
docker-compose stop grafana && docker-compose rm -f grafana
docker volume rm grafana_data && docker-compose up -d grafana
```

Background is in [section 18.6](#18).
</details>

<br>

## <a name="21">21. Adding More Services</a>

Nothing in the Collector, Tempo or Grafana config is tied to a particular service. To
add one:

1. Point any OpenTelemetry SDK at `http://<collector-host>:4318` for HTTP, or `:4317`
   for gRPC.
2. Set `OTEL_SERVICE_NAME`, and on HTTP also set
   `OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf`.
3. Make sure `tracecontext` propagation is enabled.

The new service then shows up on its own in the traces, the dependency map, the metrics
and the dashboard. Nothing on the platform side needs to change.

| Stack | How to instrument |
| --- | --- |
| Java, Kotlin, Scala | Java agent, no code changes |
| Node.js | `@opentelemetry/auto-instrumentations-node` |
| Python | `opentelemetry-instrument` |
| Go | SDK with `otelhttp` middleware |
| .NET | `OpenTelemetry.Instrumentation.*` packages |
| PHP 8.0 and newer | Official `opentelemetry-php` SDK |
| PHP older than 8.0 | The tracer in this repository |
| Frontend | `@opentelemetry/sdk-trace-web`, with CORS enabled on the Collector |

<br>

## <a name="22">22. Conclusion</a>

This project is a working starting point for distributed tracing using OpenTelemetry
and the Grafana stack. It shows that services can be brought into one observability
pipeline no matter what language they use or how old they are.

The two services here make that concrete. A modern Java service is instrumented without
writing a single line of tracing code. An end-of-life PHP 7.2 service, which no
official SDK supports, is instrumented by hand against the OTLP specification. Their
spans end up in the same trace, use the same attribute names, and look identical in
Grafana. Both are covered by the same dashboards even though only one of them can
expose a metrics endpoint.

That is the main point of this setup. Observability depends on agreeing to a protocol,
not on installing a particular library. Anything that can make an HTTP request can take
part, which means old services, usually the least visible and the most likely to cause
incidents, do not have to stay in the dark.

With this in place, tracing is consistent and centralised, root cause analysis is
faster, bottlenecks across services become obvious, and live dependencies document
themselves. New services and new stacks can be added without changing the core setup,
which is what makes it suitable for a system that keeps growing.
