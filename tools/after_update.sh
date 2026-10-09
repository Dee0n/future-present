#!/bin/bash
# Run after EVERY CHIM / DwemerDistro update (the launcher resets what we changed):
#   wsl -d DwemerAI4Skyrim3 -u root -- bash /home/dwemer/TES-Speech-Adapter/tools/after_update.sh
# Puts back: core patches (restore_core.sh), ext plugins, the Russian speech recognition (GigaAM + name
# correction), the F5 voice server, the CHIM-MCP fix, the cron watchdogs. Then says what is alive.
# Writes nothing to the database and nothing to the save. Safe to run twice.
# (install.sh is for a first install: it also loads settings/*.sql over the live settings - do not use it here.)
set -u
R=/home/dwemer/TES-Speech-Adapter
H=/var/www/html/HerikaServer
W=/home/dwemer/remote-faster-whisper
GIGA=/home/dwemer/gigaam
F5=/home/dwemer/f5-tts
MCP=/home/dwemer/CHIM-MCP
G="git -c safe.directory=* -c core.fileMode=false"
BAD=0
say() { printf '%-9s %s\n' "$1" "$2"; }

echo "== core =="
if bash "$R/tools/restore_core.sh"; then :; else say FAIL "core patches not restored - see above"; BAD=1; fi

echo "== ext plugins =="
for d in "$R"/ext/*/; do
    n=$(basename "$d")
    ok=1
    for f in "$d"*.php; do
        [ -e "$f" ] || continue
        php -l "$f" > /dev/null 2>&1 || { say FAIL "ext/$n: $(basename "$f") does not lint, plugin not copied"; ok=0; BAD=1; break; }
    done
    [ $ok = 1 ] || continue
    if diff -rq "$d" "$H/ext/$n" > /dev/null 2>&1; then
        say same "ext/$n"
    else
        mkdir -p "$H/ext/$n" && cp -r "$d". "$H/ext/$n/" && chown -R dwemer:www-data "$H/ext/$n" && say restored "ext/$n"
    fi
done

echo "== speech recognition =="
if [ -d "$W" ]; then
    if cmp -s "$R/server/remote_faster_whisper.py" "$W/remote_faster_whisper.py"; then
        say same "remote_faster_whisper.py"
    else
        cp "$W/remote_faster_whisper.py" "$W/remote_faster_whisper.py.bak-stock-$(date +%F)" 2>/dev/null
        cp "$R/server/remote_faster_whisper.py" "$W/remote_faster_whisper.py"
        say restored "remote_faster_whisper.py (GigaAM, name correction)"
        RESTART_W=1
    fi
    cp -n "$R"/configs/config-Large-GPU-RU*.yaml "$W/" 2>/dev/null
    [ -e "$W/config.yaml" ] || { ln -sf "$W/config-Large-GPU-RU-int8.yaml" "$W/config.yaml"; say restored "config.yaml -> RU int8"; RESTART_W=1; }
    cmp -s "$R/watchdog/watchdog.sh" "$W/watchdog.sh" || { cp "$R/watchdog/watchdog.sh" "$W/watchdog.sh"; chmod +x "$W/watchdog.sh"; say restored "whisper watchdog"; }
    chown dwemer:dwemer "$W/remote_faster_whisper.py" "$W/watchdog.sh" "$W"/config-Large-GPU-RU*.yaml 2>/dev/null
    [ "${RESTART_W:-0}" = 1 ] && pkill -f 'remote_faster_whisper[.]py' 2>/dev/null
else
    say skip "$W is not installed"
fi
if [ -d "$GIGA" ]; then
    cmp -s "$R/server/gigaam_server.py" "$GIGA/server.py" && say same "gigaam server.py" || {
        cp "$R/server/gigaam_server.py" "$GIGA/server.py"; chown dwemer:dwemer "$GIGA/server.py"
        pkill -f '[g]igaam/server.py' 2>/dev/null; say restored "gigaam server.py"; }
fi

echo "== voice (F5) =="
if [ -d "$F5" ]; then
    cmp -s "$R/tts/f5_watchdog.sh" "$F5/watchdog.sh" || { cp "$R/tts/f5_watchdog.sh" "$F5/watchdog.sh"; chmod +x "$F5/watchdog.sh"; say restored "f5 watchdog"; }
    cp "$R/tts/f5_restart.sh" "$F5/restart.sh"
    if cmp -s "$R/tts/f5_server.py" "$F5/f5_server.py"; then
        say same "f5_server.py"
    else
        cp "$R/tts/f5_server.py" "$F5/f5_server.py"; say restored "f5_server.py"
        bash "$F5/restart.sh" || { say FAIL "F5 did not come up"; BAD=1; }
    fi
    n=$(ls "$F5"/voices/*.wav 2>/dev/null | wc -l)
    [ "$n" -ge 100 ] && say ok "voices: $n" || { say FAIL "voices: only $n - run tts/install_f5.sh"; BAD=1; }
fi

echo "== CHIM-MCP =="
if [ -d "$MCP/src" ]; then
    P="$R/patches/chimmcp-sse-message.patch"
    if (cd "$MCP" && $G apply --reverse --check "$P" 2>/dev/null); then
        say same "sse /message fix"
    elif (cd "$MCP" && $G apply --check "$P" 2>/dev/null); then
        (cd "$MCP" && $G apply "$P") && chown -R dwemer:dwemer "$MCP/src"
        if runuser -u dwemer -- bash -c "cd $MCP && npm run build > /tmp/chimmcp_build.log 2>&1"; then
            pkill -f 'node .*CHIM-MCP/dist/index.js' 2>/dev/null
            say restored "sse /message fix (rebuilt)"
        else
            say FAIL "CHIM-MCP build failed: /tmp/chimmcp_build.log"; BAD=1
        fi
    else
        say FAIL "CHIM-MCP changed upstream - the fix does not apply, check $P"; BAD=1
    fi
    [ -e "$MCP/watchdog.sh" ] || { cp "$R/watchdog/chimmcp_watchdog.sh" "$MCP/watchdog.sh"; chmod +x "$MCP/watchdog.sh"; chown dwemer:dwemer "$MCP/watchdog.sh"; say restored "mcp watchdog"; }
fi

echo "== cron =="
CUR=$(crontab -u dwemer -l 2>/dev/null)
NEW="$CUR"
add() { echo "$NEW" | grep -qF "$1" || { NEW="$NEW"$'\n'"$2"; say restored "cron: $1"; }; }
add 'remote-faster-whisper/watchdog.sh' '* * * * * /home/dwemer/remote-faster-whisper/watchdog.sh >/dev/null 2>&1'
add 'f5-tts/watchdog.sh' '* * * * * /home/dwemer/f5-tts/watchdog.sh >/dev/null 2>&1'
add 'CHIM-MCP/watchdog.sh' '* * * * * /home/dwemer/CHIM-MCP/watchdog.sh >/dev/null 2>&1'
[ -s /home/dwemer/.local/share/tes-adapter/npc_map.tsv ] && add 'fill_bios.py' "*/2 * * * * python3 $R/tools/fill_bios.py /home/dwemer/.local/share/tes-adapter/npc_map.tsv >> /home/dwemer/.local/share/tes-adapter/fill_bios.log 2>&1"
[ "$NEW" = "$CUR" ] && say same "crontab" || echo "$NEW" | sed '/^$/d' | crontab -u dwemer -
service cron status > /dev/null 2>&1 || service cron start > /dev/null 2>&1

echo "== alive? (services need up to a minute after a restart) =="
sleep 5
[ "$(grep -c 'function herikaQueueGodCommands' "$H/functions/functions.php")" -ge 1 ] && say ok "core: god commands" || { say FAIL "core: herikaQueueGodCommands is missing"; BAD=1; }
say info "core: $(cd "$H" && $G rev-list --count origin/aiagent..HEAD) local commits over upstream $(cd "$H" && $G rev-parse --short origin/aiagent)"
[ -d "$W" ] && { [ "$(grep -c gigaam "$W/remote_faster_whisper.py")" -ge 1 ] && say ok "stt: our server file" || { say FAIL "stt: stock file"; BAD=1; }; }
for p in "9876 whisper" "8026 gigaam" "8025 f5-tts" "3100 chim-mcp"; do
    set -- $p
    (exec 3<>/dev/tcp/127.0.0.1/$1) 2>/dev/null && say ok "port $1 $2" || say wait "port $1 $2 - not listening yet (watchdog starts it within a minute)"
done
runuser -u dwemer -- psql -d dwemer -Atc "select 'ok        db: ' || count(*) || ' tes_* tables' from pg_tables where schemaname='public' and tablename like 'tes\_%'" 2>/dev/null || say wait "db is not up"
echo
[ $BAD = 0 ] && echo "ALL RESTORED" || echo "SOMETHING FAILED - read the FAIL lines above"
exit $BAD
