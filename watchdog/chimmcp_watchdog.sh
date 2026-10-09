#!/bin/bash
# Starts the CHIM-MCP server if nothing is listening on its port (cron, every minute).
R=/home/dwemer/CHIM-MCP
PORT=3100
[ -f "$R/disabled" ] && exit 0
curl -s -m 3 -o /dev/null "http://127.0.0.1:$PORT/status" && exit 0
pgrep -f "node .*CHIM-MCP/dist/index.js" >/dev/null && exit 0
cd "$R" || exit 1
setsid nohup node "$R/dist/index.js" >> "$R/server.log" 2>&1 < /dev/null &
