#!/bin/bash
# Point the local-model connector (id 20) at the Windows host as seen from WSL right now and
# check that LM Studio answers. Usage (inside the distro): bash tools/local_llm.sh
H=$(ip route | awk '/default/ {print $3; exit}')
psql --no-password -U dwemer -d dwemer -At -c "UPDATE core_llm_connector SET url = 'http://$H:1234/v1/chat/completions' WHERE id = 20 RETURNING url"
curl -s -m 5 "http://$H:1234/v1/models" | grep -o '"id": *"[^"]*"' || echo "LM Studio не отвечает: на Windows запусти tools/local_llm.ps1"
