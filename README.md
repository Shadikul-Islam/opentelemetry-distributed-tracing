# <p align=center>OpenTelemetry Distributed Tracing  <br> <br> </p> 

## Table of Contents

| **SL** | **Topic** |
| --- | --- |
| 01 | [Project Overview and Requirements](#01) |
| 02 | [Introduction](#02) |
| 03 | [Tools and Technologies Used](#03) |
| 04 | [Tools and Technologies Used](#04) |
| 05 | [Project Structure](#05) |
| 06 | [Components](#06)  |
| 07 | [Features](#07)  |
| 08 | [Network Flow](#08)  |
| 09 | [Run the Stack](#09)  |
| 10 | [Grafana Credentials](#10)  |
| 11 | [Java Instrumentation](#11)  |
| 12 | [Conclusion](#12)  |

<br>

## <a name="01">Project Overview and Requirements</a>

Modern applications are no longer monolithic. In production, systems are usually split into multiple services running across different servers, containers, and technology stacks like Java, PHP, Node.js, Python, and frontend applications.

As systems grow, a single user request often travels through multiple services before completing. This makes debugging and performance tracking difficult.

Traditional logging alone is not enough to answer key questions like:

- Where is the request getting delayed?
- Which service is causing failures or slowness?
- How much time is spent in each service?
- What downstream dependency is impacting performance?
- How does the full request flow look across services?

**Problem Statement**

In distributed environments, requests pass through multiple services and infrastructure layers. Without centralized tracing:

- Root cause analysis becomes slow and complex
- Service-to-service visibility is missing
- Performance bottlenecks are hard to identify
- Dependencies between services are unclear
- Each stack uses different monitoring approaches

As the system scales, these issues become more critical and harder to manage.

**Solution**

This project provides a centralized distributed tracing platform using OpenTelemetry, OpenTelemetry Collector, Grafana Tempo, and Grafana.

All services, regardless of stack or server, send trace data to a central observability backend where it is collected, stored, and visualized.


## <a name="02">Introduction</a>

Production-style distributed tracing stack using OpenTelemetry, Grafana Tempo, Grafana, and OpenTelemetry Collector.

This project provides a centralized tracing architecture where multiple services from different technology stacks can send traces to a single observability platform.

**Supported use cases**:

- Java services
- PHP services
- Node.js services
- Python services
- Go services
- Frontend applications
- Microservices environments
- Multi-server deployments

## Tools and Technologies Used

- OpenTelemetry
- OpenTelemetry Collector
- Grafana Tempo
- Grafana
- Docker
- Docker Compose

## <a name="03">Architecture</a>

```text
+-------------------+
| Application Layer |
+-------------------+
    Java Service
    PHP Service
    Node.js Service
    Python Service
    Frontend Apps
    Other Microservices
        |
        | OTLP Traces
        v
+--------------------------+
| OpenTelemetry Collector  |
|        (Gateway)         |
+--------------------------+
        |
        | Forward Traces
        v
+-------------------+
|   Grafana Tempo   |
+-------------------+
        |
        | Query Traces
        v
+-------------------+
|      Grafana      |
+-------------------+
```

## <a name="04">Project Structure</a>

``` text
├── docker
│   └── docker-compose.yml
│   └── .env
├── grafana
│   └── provisioning
│       ├── dashboards
│       │   ├── provider.yaml
│       │   └── traces-overview.json
│       └── datasources
│           └── tempo.yaml
├── otel
│   └── collector
│       └── config.yaml
├── README.md
└── tempo
    └── tempo.yaml
```

## <a name="05">Components</a>

### OpenTelemetry Collector

Acts as the centralized telemetry gateway.

**Responsibilities**:

- Receives traces from applications
- Processes telemetry data
- Exports traces to Tempo
- Supports OTLP HTTP and gRPC

**Ports**:

```text
Port	Purpose
4318	OTLP HTTP
4317	OTLP gRPC
```


### Grafana Tempo

Distributed tracing backend used for storing traces.

**Responsibilities**:

- Stores trace data
- Handles trace querying
- Integrates with Grafana

**Port**:

```text
Port	Purpose
3200	Tempo API
```


### Grafana

Visualization and trace exploration UI.

**Responsibilities**:

- Query traces from Tempo
- Visualize distributed tracing
- Dashboard management
- Trace search and filtering

**Port**:

```text
Port	Purpose
3000	Grafana UI
```


## <a name="06">Features</a>

- Centralized distributed tracing
- Multi-service tracing support
- Multi-stack observability support
- OpenTelemetry native architecture
- Grafana dashboard provisioning
- Tempo trace backend integration
- OTLP HTTP and gRPC support
- Docker Compose deployment
- Production-style observability workflow


## <a name="07">Network Flow</a>

```text
Application Services
        |
        v
  OTEL Collector
        |
        v
      Tempo
        |
        v
     Grafana
```


## <a name="08">Run the Stack</a>

1. Start Services ```cd docker && docker-compose up -d```

2. Access Services
```
Service	                    URL
Grafana	                http://localhost:3000
Tempo	                http://localhost:3200
OTEL Collector HTTP	    http://localhost:4318
OTEL Collector gRPC	    localhost:4317
```

## <a name="09">Grafana Credentials</a>

Credentials are configured from docker folder .env file.

```bash
ADMIN_USER=sadik
ADMIN_PASSWORD=adminpass
```

## <a name="10">Java Instrumentation</a>

**Download Java OpenTelemetry agent**:

```wget https://github.com/open-telemetry/opentelemetry-java-instrumentation/releases/latest/download/opentelemetry-javaagent.jar```

**Run Java application with OpenTelemetry agent**:

``` bash
java -javaagent:opentelemetry-javaagent.jar \
-Dotel.service.name=user-service \
-Dotel.exporter.otlp.endpoint=http://MONITORING_SERVER_IP:4318 \
-Dotel.traces.exporter=otlp \
-Dotel.metrics.exporter=none \
-Dotel.logs.exporter=otlp \
-jar ./target/*.jar
```

## <a name="10">Conclusion</a>

This project provides a practical foundation for building distributed tracing in real-world systems using OpenTelemetry and Grafana Tempo. It shows how multiple services, regardless of technology stack, can be connected into a single observability pipeline.

With this setup, tracing becomes consistent, centralized, and easier to manage across environments. It improves visibility into request flows, reduces debugging time, and helps identify performance issues across distributed systems.

The architecture is designed to be extensible, so new services and stacks can be added without changing the core observability setup, making it suitable for evolving production environments.
