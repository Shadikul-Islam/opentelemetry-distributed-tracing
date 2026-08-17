FROM maven:3.6.3-jdk-11 AS build

WORKDIR /build

COPY pom.xml .
RUN mvn -B -DskipTests dependency:go-offline

COPY src ./src
RUN mvn -B -DskipTests clean package

FROM eclipse-temurin:11-jre-jammy

ARG OTEL_AGENT_VERSION=2.10.0

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

ADD https://github.com/open-telemetry/opentelemetry-java-instrumentation/releases/download/v${OTEL_AGENT_VERSION}/opentelemetry-javaagent.jar /app/opentelemetry-javaagent.jar
RUN chmod 0644 /app/opentelemetry-javaagent.jar

COPY --from=build /build/target/order-service.jar /app/order-service.jar

# http/protobuf is required on port 4318; the agent defaults to grpc and would
# silently drop every span.
ENV OTEL_SERVICE_NAME=order-service \
    OTEL_TRACES_EXPORTER=otlp \
    OTEL_METRICS_EXPORTER=none \
    OTEL_LOGS_EXPORTER=none \
    OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf \
    OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318 \
    OTEL_PROPAGATORS=tracecontext,baggage \
    JAVA_OPTS=""

EXPOSE 8080

ENTRYPOINT ["sh", "-c", "exec java -javaagent:/app/opentelemetry-javaagent.jar $JAVA_OPTS -jar /app/order-service.jar"]
