#!/bin/bash
# 2026-10-04 22:00: clothes errands ("иди купи себе…", also via the Narrator), "оденься (богато)", clothes swap,
# "побираешься" is no longer an order to beg. Also frees Бренуин (500000 gold, was sent to beg by mistake).
# Run: wsl -d DwemerAI4Skyrim3 -u root -- bash /home/dwemer/TES-Speech-Adapter/tools/apply_fixes_2026-10-04c.sh
set -e
REPO=/home/dwemer/TES-Speech-Adapter
HS=/var/www/html/HerikaServer
FILES="errand.php lib.php preprocessing.php postrequest.php functions.php realm.php context_pre.php talk.php"
for f in $FILES; do php -l "$REPO/ext/tes_world/$f" >/dev/null; done
B=/home/dwemer/backups/tes_world_$(date +%Y%m%d_%H%M%S)
mkdir -p "$B" && cp -a "$HS/ext/tes_world/." "$B/"
for f in $FILES; do cp "$REPO/ext/tes_world/$f" "$HS/ext/tes_world/$f"; done
chown dwemer:www-data "$HS"/ext/tes_world/*.php && chmod 664 "$HS"/ext/tes_world/*.php
for f in $FILES; do php -l "$HS/ext/tes_world/$f" | grep -v "No syntax" || true; done
echo "ext/tes_world deployed (backup $B)"

# Бренуин (0002C90F): off the beggar's routine, dressed as a rich man. Bridge in the running game may still be v9:
# tesroutine reset and tesoutfit exist there; the rest are plain console commands.
cat > /tmp/tes_free_brenuin.php <<'PHP'
<?php
chdir('/var/www/html/HerikaServer');
require 'lib/postgresql.class.php';
$GLOBALS['db'] = new sql();
require '/var/www/html/HerikaServer/ext/tes_world/lib.php';
tesWorldQueue(['prid 0002C90F', 'tesroutine reset', 'unequipall', 'additem 000CEE76 1', 'equipitem 000CEE76', 'additem 000CEE78 1', 'equipitem 000CEE78', 'tesoutfit ' . hexdec('000DAB7A')]);
echo "queued\n";
PHP
runuser -u www-data -- php /tmp/tes_free_brenuin.php
rm -f /tmp/tes_free_brenuin.php
echo "== OK"
