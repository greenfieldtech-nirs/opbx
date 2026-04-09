#!/bin/bash
# OpenAPI Endpoint Generator Script
# Generates CRUD endpoint files from templates

set -e

BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
PATHS_DIR="$BASE_DIR/paths"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

# Template for basic CRUD endpoints
generate_crud() {
    local dir=$1
    local name=$2
    local tag=$3
    local schema_ref=$4
    local param_name=$5
    
    mkdir -p "$PATHS_DIR/$dir"
    
    cat > "$PATHS_DIR/$dir/index.yaml" << EOF
paths:
  /v1/${dir}:
    get:
      tags:
        - ${tag}
      summary: List ${name}s
      description: Get paginated list of ${name}s
      operationId: list$(echo "$name" | sed 's/ //g')s
      security:
        - bearerAuth: []
        - cookieAuth: []
      parameters:
        - \$ref: '../../components/parameters/query/Page.yaml'
        - \$ref: '../../components/parameters/query/PerPage.yaml'
      responses:
        '200':
          description: List of ${name}s
          content:
            application/json:
              schema:
                type: object
                properties:
                  data:
                    type: array
                    items:
                      \$ref: '../../components/schemas/${schema_ref}.yaml'
                  meta:
                    \$ref: '../../components/schemas/PaginationMeta.yaml'
        '401':
          \$ref: '../../components/responses/Unauthorized.yaml'

    post:
      tags:
        - ${tag}
      summary: Create ${name}
      operationId: create$(echo "$name" | sed 's/ //g')
      security:
        - bearerAuth: []
        - cookieAuth: []
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              properties:
                name:
                  type: string
      responses:
        '201':
          description: ${name} created
        '401':
          \$ref: '../../components/responses/Unauthorized.yaml'
        '422':
          \$ref: '../../components/responses/ValidationError.yaml'

  /v1/${dir}/{${param_name}}:
    parameters:
      - name: ${param_name}
        in: path
        required: true
        schema:
          type: integer

    get:
      tags:
        - ${tag}
      summary: Get ${name}
      operationId: get$(echo "$name" | sed 's/ //g')
      security:
        - bearerAuth: []
        - cookieAuth: []
      responses:
        '200':
          description: ${name} details
        '401':
          \$ref: '../../components/responses/Unauthorized.yaml'
        '404':
          \$ref: '../../components/responses/NotFound.yaml'

    put:
      tags:
        - ${tag}
      summary: Update ${name}
      operationId: update$(echo "$name" | sed 's/ //g')
      security:
        - bearerAuth: []
        - cookieAuth: []
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
      responses:
        '200':
          description: ${name} updated
        '401':
          \$ref: '../../components/responses/Unauthorized.yaml'
        '404':
          \$ref: '../../components/responses/NotFound.yaml'
        '422':
          \$ref: '../../components/responses/ValidationError.yaml'

    delete:
      tags:
        - ${tag}
      summary: Delete ${name}
      operationId: delete$(echo "$name" | sed 's/ //g')
      security:
        - bearerAuth: []
        - cookieAuth: []
      responses:
        '204':
          description: ${name} deleted
        '401':
          \$ref: '../../components/responses/Unauthorized.yaml'
        '404':
          \$ref: '../../components/responses/NotFound.yaml'
EOF

    log_info "Created: $dir/index.yaml"
}

# Generate all missing CRUD endpoints
generate_crud "business-hours" "Business Hours Schedule" "Business Hours" "BusinessHoursSchedule" "business_hour"
generate_crud "call-detail-records" "Call Detail Record" "Call Detail Records" "CallDetailRecord" "call_detail_record"
generate_crud "inbound-blacklist" "Inbound Blacklist Entry" "Inbound Blacklist" "InboundBlacklist" "inboundBlacklist"
generate_crud "ivr-menus" "IVR Menu" "IVR Menus" "IvrMenu" "ivr_menu"
generate_crud "outbound-whitelist" "Outbound Whitelist Entry" "Outbound Whitelist" "OutboundWhitelist" "outboundWhitelist"
generate_crud "phone-numbers" "Phone Number" "Phone Numbers" "PhoneNumber" "phone_number"
generate_crud "recordings" "Recording" "Recordings" "Recording" "recording"

log_info "CRUD endpoints generated successfully!"
EOF

chmod +x /Users/nirs/Documents/repos/opbx.cloudonix.com/docs/openapi/generate_endpoints.sh

echo "Generator script created. Running..."
/Users/nirs/Documents/repos/opbx.cloudonix.com/docs/openapi/generate_endpoints.sh
