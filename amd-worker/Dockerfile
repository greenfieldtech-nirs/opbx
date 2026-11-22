# Build stage
FROM maven:3.9-eclipse-temurin-21-alpine AS builder
WORKDIR /build
COPY pom.xml ./
RUN mvn dependency:go-offline -B
COPY src ./src
RUN mvn package -DskipTests -B

# Runtime stage
FROM eclipse-temurin:21-jre
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates && rm -rf /var/lib/apt/lists/*
WORKDIR /app
# onnxruntime's 1DS telemetry starts inside OrtEnvironment creation, before setTelemetry(false) can run.
ENV ORT_DISABLE_TELEMETRY=1
COPY --from=builder /build/target/amd-worker-1.0.0.jar ./app.jar
COPY models ./models
RUN useradd -r -u 10001 app && chown -R app /app
USER app
EXPOSE 8082 8083
ENTRYPOINT ["java", "-jar", "app.jar"]
