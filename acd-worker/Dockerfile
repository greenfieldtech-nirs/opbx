# Build stage
FROM maven:3.9-eclipse-temurin-21-alpine AS builder
WORKDIR /build
COPY pom.xml ./
RUN mvn dependency:go-offline -B
COPY src ./src
RUN mvn package -DskipTests -B

# Runtime stage
FROM eclipse-temurin:23-jre
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY --from=builder /build/target/acd-worker-1.0.0.jar ./app.jar
RUN useradd -r -u 10001 app && chown -R app /app
USER app
EXPOSE 8084 8085
ENTRYPOINT ["java", "-jar", "app.jar"]
