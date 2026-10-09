#!/bin/bash
# Restart the F5-TTS RU server (the watchdog starts it; this only stops the running one first).
R=/home/dwemer/f5-tts
"$R/venv/bin/python" -m py_compile "$R/f5_server.py" || { echo "syntax error, not restarted"; exit 1; }
for pid in $(pgrep -f "venv/bin/python $R/f5_server.py"); do
    kill "$pid"
done
sleep 3
runuser -u dwemer -- bash "$R/watchdog.sh"
for i in $(seq 1 60); do
    curl -s -m 3 -o /dev/null "http://127.0.0.1:8025/" && { echo "up after ${i}x2 s"; exit 0; }
    sleep 2
done
echo "not up after 120 s"
exit 1
