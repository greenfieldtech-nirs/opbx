#!/usr/bin/env bash
#
# Load Testing Script for Auto Dialer Worker
#
# This script simulates high load on the dialer worker by creating
# multiple campaigns with various CAC settings and monitoring performance.
#
# Usage: ./load_test.sh [duration_minutes] [concurrent_campaigns]
#   duration_minutes: How long to run the test (default: 10)
#   concurrent_campaigns: Number of concurrent campaigns (default: 3)
#

set -e

# Configuration
DURATION_MINUTES=${1:-10}
CONCURRENT_CAMPAIGNS=${2:-3}
LARAVEL_API_URL="${LARAVEL_API_URL:-http://localhost:8000}"
API_TOKEN="${API_TOKEN:-test-token}"
REDIS_URL="${REDIS_URL:-redis://localhost:6379}"

# CAC values to test
CAC_VALUES=(2 5 10 15)

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  Auto Dialer Load Test${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo "Duration: ${DURATION_MINUTES} minutes"
echo "Concurrent Campaigns: ${CONCURRENT_CAMPAIGNS}"
echo "CAC Values: ${CAC_VALUES[*]}"
echo ""

# Check prerequisites
check_prerequisites() {
    echo -e "${BLUE}Checking prerequisites...${NC}"
    
    # Check if Redis is accessible
    if ! redis-cli -u "$REDIS_URL" ping > /dev/null 2>&1; then
        echo -e "${RED}Error: Cannot connect to Redis at $REDIS_URL${NC}"
        exit 1
    fi
    echo -e "${GREEN}✓ Redis accessible${NC}"
    
    # Check if Laravel API is accessible
    if ! curl -s -o /dev/null -w "%{http_code}" "${LARAVEL_API_URL}/api/v1/health" | grep -q "200"; then
        echo -e "${YELLOW}Warning: Laravel API not accessible at $LARAVEL_API_URL${NC}"
    else
        echo -e "${GREEN}✓ Laravel API accessible${NC}"
    fi
    
    echo ""
}

# Initialize test data in Redis
initialize_redis() {
    echo -e "${BLUE}Initializing Redis test data...${NC}"
    
    # Clear any existing test data
    redis-cli -u "$REDIS_URL" --scan --pattern "campaign:*" | xargs -r redis-cli -u "$REDIS_URL" del
    
    echo -e "${GREEN}✓ Redis cleared${NC}"
    echo ""
}

# Simulate concurrent calls for a campaign
simulate_campaign() {
    local campaign_id=$1
    local cac=$2
    local duration_seconds=$3
    
    local start_time=$(date +%s)
    local end_time=$((start_time + duration_seconds))
    local api_interval=$(echo "scale=2; 60 / $cac" | bc)
    
    echo -e "${YELLOW}Campaign $campaign_id:${NC} CAC=$cac, API interval=${api_interval}s"
    
    local active_calls=0
    local total_calls=0
    
    while [ $(date +%s) -lt $end_time ]; do
        # Check if we can start a new call (respect CAC)
        if [ $active_calls -lt $cac ]; then
            # Simulate API call to Cloudonix
            ((active_calls++))
            ((total_calls++))
            
            # Store in Redis
            redis-cli -u "$REDIS_URL" incr "campaign:$campaign_id:concurrency_counter" > /dev/null
            redis-cli -u "$REDIS_URL" sadd "campaign:$campaign_id:active_sessions" "session_${campaign_id}_${total_calls}" > /dev/null
            
            echo -e "  Campaign $campaign_id: Started call $total_calls (active: $active_calls)"
            
            # Simulate call duration (random 5-30 seconds)
            local call_duration=$((5 + RANDOM % 25))
            
            # Schedule call completion in background
            (
                sleep $call_duration
                redis-cli -u "$REDIS_URL" decr "campaign:$campaign_id:concurrency_counter" > /dev/null
                redis-cli -u "$REDIS_URL" srem "campaign:$campaign_id:active_sessions" "session_${campaign_id}_${total_calls}" > /dev/null
                echo -e "  Campaign $campaign_id: Completed call (duration: ${call_duration}s)"
            ) &
        fi
        
        # Wait for API interval
        sleep $api_interval
        
        # Update active calls count from Redis
        active_calls=$(redis-cli -u "$REDIS_URL" get "campaign:$campaign_id:concurrency_counter" 2>/dev/null || echo 0)
    done
    
    echo -e "${GREEN}Campaign $campaign_id completed: $total_calls total calls${NC}"
}

# Monitor Redis metrics
monitor_metrics() {
    local duration_seconds=$1
    local start_time=$(date +%s)
    local end_time=$((start_time + duration_seconds))
    
    echo -e "${BLUE}Starting metrics monitoring...${NC}"
    
    while [ $(date +%s) -lt $end_time ]; do
        clear
        echo -e "${BLUE}========================================${NC}"
        echo -e "${BLUE}  Load Test Metrics${NC}"
        echo -e "${BLUE}========================================${NC}"
        echo ""
        echo "Time remaining: $((end_time - $(date +%s))) seconds"
        echo ""
        
        # Show metrics for each campaign
        for cac in "${CAC_VALUES[@]}"; do
            local campaign_id="test_${cac}"
            local counter=$(redis-cli -u "$REDIS_URL" get "campaign:$campaign_id:concurrency_counter" 2>/dev/null || echo 0)
            local sessions=$(redis-cli -u "$REDIS_URL" scard "campaign:$campaign_id:active_sessions" 2>/dev/null || echo 0)
            
            local utilization=0
            if [ "$cac" -gt 0 ]; then
                utilization=$(echo "scale=1; ($counter / $cac) * 100" | bc)
            fi
            
            printf "CAC=%2d | Active: %2d/%2d | Utilization: %5.1f%% | Sessions: %2d\n" \
                $cac $counter $cac $utilization $sessions
        done
        
        echo ""
        echo -e "${BLUE}Press Ctrl+C to stop${NC}"
        
        sleep 1
    done
}

# Run the load test
run_load_test() {
    local duration_seconds=$((DURATION_MINUTES * 60))
    
    echo -e "${BLUE}Starting load test...${NC}"
    echo ""
    
    # Start campaign simulations in background
    local pids=()
    
    for cac in "${CAC_VALUES[@]}"; do
        if [ ${#pids[@]} -lt $CONCURRENT_CAMPAIGNS ]; then
            simulate_campaign "test_${cac}" $cac $duration_seconds &
            pids+=($!)
            sleep 1  # Stagger starts
        fi
    done
    
    # Monitor metrics
    monitor_metrics $duration_seconds
    
    # Wait for all background jobs
    echo -e "${BLUE}Waiting for campaigns to complete...${NC}"
    for pid in "${pids[@]}"; do
        wait $pid 2>/dev/null || true
    done
}

# Generate final report
generate_report() {
    echo ""
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}  Load Test Report${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""
    
    for cac in "${CAC_VALUES[@]}"; do
        local campaign_id="test_${cac}"
        local final_counter=$(redis-cli -u "$REDIS_URL" get "campaign:$campaign_id:concurrency_counter" 2>/dev/null || echo 0)
        local sessions=$(redis-cli -u "$REDIS_URL" smembers "campaign:$campaign_id:active_sessions" 2>/dev/null || echo "")
        local session_count=$(echo "$sessions" | grep -c "session" || echo 0)
        
        echo "Campaign CAC=$cac:"
        echo "  Final counter: $final_counter"
        echo "  Remaining sessions: $session_count"
        
        if [ "$final_counter" -ne 0 ] || [ "$session_count" -ne 0 ]; then
            echo -e "  ${YELLOW}Warning: Expected counter and sessions to be 0${NC}"
        else
            echo -e "  ${GREEN}✓ Clean state${NC}"
        fi
        echo ""
    done
}

# Cleanup
cleanup() {
    echo ""
    echo -e "${BLUE}Cleaning up...${NC}"
    
    # Kill any remaining background jobs
    jobs -p | xargs -r kill 2>/dev/null || true
    
    # Clean up Redis
    redis-cli -u "$REDIS_URL" --scan --pattern "campaign:test_*" | xargs -r redis-cli -u "$REDIS_URL" del
    
    echo -e "${GREEN}✓ Cleanup complete${NC}"
}

# Main execution
trap cleanup EXIT

check_prerequisites
initialize_redis
run_load_test
generate_report

echo ""
echo -e "${GREEN}Load test completed!${NC}"
