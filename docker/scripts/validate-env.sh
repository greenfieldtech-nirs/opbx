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

# Load values from the application's .env file when they are not already present
# in the process environment. This project keeps secrets (APP_KEY, DB_PASSWORD,
# etc.) in .env — Laravel reads them at runtime, and they are NOT injected into
# the container's process environment. Process env takes precedence when set.
ENV_FILE="${ENV_FILE:-/var/www/html/.env}"
if [[ -f "$ENV_FILE" ]]; then
    while IFS= read -r line || [[ -n "$line" ]]; do
        # Skip comments and blank lines
        [[ "$line" =~ ^[[:space:]]*# ]] && continue
        [[ "$line" =~ ^[[:space:]]*$ ]] && continue
        # Only handle KEY=VALUE lines
        [[ "$line" != *"="* ]] && continue
        key="${line%%=*}"
        value="${line#*=}"
        # Trim surrounding whitespace from key
        key="${key//[[:space:]]/}"
        [[ -z "$key" ]] && continue
        # Strip matching surrounding quotes from the value
        value="${value%$'\r'}"
        if [[ "$value" == \"*\" ]]; then
            value="${value#\"}"; value="${value%\"}"
        elif [[ "$value" == \'*\' ]]; then
            value="${value#\'}"; value="${value%\'}"
        fi
        # Only set if not already present/non-empty in the process environment
        if [[ -z "${!key:-}" ]]; then
            export "$key=$value"
        fi
    done < "$ENV_FILE"
fi

# Critical security variables that must not be default values.
# NOTE: CLOUDONIX_API_TOKEN and CLOUDONIX_WEBHOOK_SECRET are intentionally NOT
# validated here — they are managed per-organization in the database
# (organization settings), not via global environment variables.
REQUIRED_VARS=(
    "DB_PASSWORD"
    "APP_KEY"
)

# Track validation status
VALIDATION_FAILED=0

# Function to check if a variable is set to a default/placeholder value
check_placeholder() {
    local var_name=$1
    local var_value="${!var_name:-}"

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
if [[ "${APP_ENV:-}" == "production" ]] && [[ "${APP_DEBUG:-}" == "true" ]]; then
    echo -e "${YELLOW}⚠ WARNING: APP_DEBUG is true in production${NC}"
    echo -e "${YELLOW}  This is a security risk and should be set to false${NC}"
    VALIDATION_FAILED=1
fi

# Check for weak passwords (basic check for common patterns)
if [[ -n "${DB_PASSWORD:-}" ]]; then
    PASSWORD_LENGTH=${#DB_PASSWORD}
    if [[ $PASSWORD_LENGTH -lt 8 ]]; then
        echo -e "${YELLOW}⚠ WARNING: DB_PASSWORD is less than 8 characters${NC}"
        echo -e "${YELLOW}  Consider using a stronger password${NC}"
        VALIDATION_FAILED=1
    fi
fi

# Check Redis port exposure warning
if [[ -n "${REDIS_EXPOSE_PORT:-}" ]]; then
    echo -e "${YELLOW}⚠ WARNING: REDIS_EXPOSE_PORT is set to ${REDIS_EXPOSE_PORT}${NC}"
    echo -e "${YELLOW}  Redis port will be exposed externally to host${NC}"
    echo -e "${YELLOW}  This increases attack surface and is NOT recommended for production${NC}"

    if [[ "${APP_ENV:-}" == "production" ]]; then
        echo -e "${RED}✗ CRITICAL: Redis port exposure in production is strongly discouraged${NC}"
        echo -e "${RED}  Please remove REDIS_EXPOSE_PORT or set it to empty${NC}"
        VALIDATION_FAILED=1
    fi
else
    echo -e "${GREEN}✓ Redis port exposure disabled (secure for production)${NC}"
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
