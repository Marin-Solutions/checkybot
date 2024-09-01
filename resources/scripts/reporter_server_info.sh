#!/bin/bash

# Set the API endpoint URL
API_URL="https://example.com/api/endpoint"

# Set the token-id variable
TOKEN_ID="your_token_id"

# Get the IP address of the server
IP_SERVER=$(ip addr show | awk '/inet / {print $2}' | cut -d/ -f1)

# Get the RAM usage
RAM_USE=$(free -h | awk '/Mem/ {print $3}' | sed 's/%//g')

# Get the CPU usage
CPU_USE=$(top -b -n 1 | awk '/Cpu/ {print $2}' | sed 's/%//g')

# Get the disk usage
DISK_USE=$(df -h | awk '/\/$/ {print $5}' | sed 's/%//g')

# Send the request to the API endpoint
curl -X POST \
  $API_URL \
  -H 'Authorization: Bearer '$TOKEN_ID \
  -H 'Content-Type: application/json' \
  -d '{"ip_server": "'$IP_SERVER'", "ram_use": "'$RAM_USE'", "cpu_use": "'$CPU_USE'", "disk_use": "'$DISK_USE'"}'
