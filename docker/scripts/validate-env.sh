#!/bin/bash

# Environment Variable Validation Script
# This script validates critical security variables before allowing the application to start
# It should be run as part of the Docker container startup process

set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}=== Environment Variable Validation ===${NC}"

# Critical security variables that must not be default values
REQUIRED_VARS=(
    "DB_PASSWORD"
    "APP_KEY"
    "CLOUDONIX_API_TOKEN"
    "CLOUDONIX_WEBHOOK_SECRET"
)

# Track validation status
VALIDATION_FAILED=0

# Function to check if a variable is set to a default/placeholder value
check_placeholder() {
    local var_name=$1
    local var_value="${!var_name}"

    # Check for common placeholder values
    local placeholders=("CHANGE_ME" "GENERATE_32_CHAR" "64_CHAR_SECRET" "default123" "password" "secret")

    for placeholder in "${placeholders[@]}"; do
        if [[ "$var_value" == *"$placeholder"* ]]; then
            echo -e "${RED}✗ CRITICAL: ${var_name} is set to a placeholder value${NC}"
            echo -e "${RED}  Please set a secure value for ${var_name}${NC}"
            VALIDATION_FAILED=1
            return 1
        fi
    done

    # Check for empty values
    if [[ -z "$var_value" ]]; then
        echo -e "${RED}✗ CRITICAL: ${var_name} is not set${NC}"
        echo -e "${RED}  Please provide a value for ${var_name}${NC}"
        VALIDATION_FAILED=1
        return 1
    fi

    return 0
}

# Check critical variables
for var_name in "${REQUIRED_VARS[@]}"; do
    check_placeholder "$var_name"
done

# Additional security checks
echo -e "${GREEN}Security Checks:${NC}"

# Check if running in production mode with debug enabled
if [[ "$APP_ENV" == "production" ]] && [[ "$APP_DEBUG" == "true" ]]; then
    echo -e "${YELLOW}⚠ WARNING: APP_DEBUG is true in production${NC}"
    echo -e "${YELLOW}  This is a security risk and should be set to false${NC}"
    VALIDATION_FAILED=1
fi

# Check for weak passwords (basic check for common patterns)
if [[ -n "$DB_PASSWORD" ]]; then
    PASSWORD_LENGTH=${#DB_PASSWORD}
    if [[ $PASSWORD_LENGTH -lt 8 ]]; then
        echo -e "${YELLOW}⚠ WARNING: DB_PASSWORD is less than 8 characters${NC}"
        echo -e "${YELLOW}  Consider using a stronger password${NC}"
        VALIDATION_FAILED=1
    fi
fi

# Check Redis port exposure warning
if [[ -n "$REDIS_EXPOSE_PORT" ]] && [[ "$REDIS_EXPOSE_PORT" != "false" ]]; then
    echo -e "${YELLOW}⚠ WARNING: REDIS_EXPOSE_PORT is set to ${REDIS_EXPOSE_PORT}${NC}"
    echo -e "${YELLOW}  Redis port will be exposed to the network${NC}"
    echo -e "${YELLOW}  Ensure this is intended for production deployments${NC}"
fi

# Final status
echo -e "${GREEN}========================================${NC}"
if [[ $VALIDATION_FAILED -eq 0 ]]; then
    echo -e "${GREEN}✓ All environment variables are properly configured${NC}"
    echo -e "${GREEN}✓ Security checks passed${NC}"
    exit 0
else
    echo -e "${RED}✗ Environment validation failed${NC}"
    echo -e "${RED}  Please fix the issues above before starting the application${NC}"
    exit 1
fi
