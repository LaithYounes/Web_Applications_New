# Build stage
FROM maven:3.9.9-eclipse-temurin-21 AS build

WORKDIR /workspace/inventory-service

# Copy Maven configuration and source code
COPY inventory-service/pom.xml .
COPY inventory-service/src ./src

# The proto directory is outside inventory-service
COPY proto ../proto

# Build the Spring Boot application
RUN mvn -B clean package -DskipTests

# Runtime stage
FROM eclipse-temurin:21-jre

WORKDIR /app

COPY --from=build /workspace/inventory-service/target/*.jar app.jar

EXPOSE 9090

ENTRYPOINT ["java", "-jar", "app.jar"]
