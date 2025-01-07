#!/bin/bash

# Set the API endpoint URL
API_URL="https://checkybot.com/api/v1/server-history"

# Set the token-id variable
TOKEN_ID="your_token_id"

# Get CPU information and calculate true usage percentage
CPU_CORES=$(nproc)
CPU_LOAD=$(uptime | grep -oP '(?<=average:).*' | awk '{print $1}' | sed 's/,//')
CPU_USE=$(awk "BEGIN {printf \"%.2f\", ($CPU_LOAD/$CPU_CORES)*100}")

# Get RAM information
RAM_FREE_PERCENTAGE=$(free | awk '/Mem/ {print $7*100/$2"%"}' )
RAM_FREE=$(free | awk '/Mem/ {print $7}')

# Get Disk information
DISK_FREE_PERCENTAGE=$(df --output=pcent / | awk 'NR==2{print 100-$1"%"}')
DISK_FREE_BYTES=$(df --output=avail / | awk 'NR==2{print $1}')

# Send data to API
curl -s -X POST \
 $API_URL \
 -H 'Authorization: Bearer '$TOKEN_ID \
 -H 'Content-Type: application/json' \
 -H 'Accept: application/json' \
 -d '{
    "cpu_load": "'$CPU_LOAD'",
    "cpu_cores": "'$CPU_CORES'",
    "server_id": "'$SERVER_ID'",
    "ram_free_percentage": "'$RAM_FREE_PERCENTAGE'",
    "ram_free": "'$RAM_FREE'",
    "disk_free_percentage": "'$DISK_FREE_PERCENTAGE'",
    "disk_free_bytes": "'$DISK_FREE_BYTES'"
}'
