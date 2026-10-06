#!/bin/bash
# Owner runs this (a CHIM core change): the plugins' gameplay tables join CHIM's Playthrough Save, so loading an
# older save brings no "memories of the future". Backup first, then a local core commit kept by tools/restore_core.sh.
#   wsl -d DwemerAI4Skyrim3 -u root -- bash /home/dwemer/TES-Speech-Adapter/tools/apply_playthrough_patch.sh
# Undo: cp /home/dwemer/backups/playthrough_policy.php.bak-2026-10-06 /var/www/html/HerikaServer/lib/playthrough_policy.php
set -e
REPO=/home/dwemer/TES-Speech-Adapter
cd "$REPO"
runuser -u www-data -- php tools/ensure_playthrough_tables.php 2>&1 | grep -v '^\[REL\]' | tail -1
cd /var/www/html/HerikaServer
G="git -c safe.directory=* -c user.name=tes -c user.email=tes@local"
if grep -q 'function pts_ext_playthrough_tables' lib/playthrough_policy.php; then echo "already applied"; exit 0; fi
mkdir -p /home/dwemer/backups
cp lib/playthrough_policy.php /home/dwemer/backups/playthrough_policy.php.bak-2026-10-06
patch -p1 < "$REPO/patches/herika-playthrough-ext-tables.patch"
php -l lib/playthrough_policy.php
chown dwemer:www-data lib/playthrough_policy.php
$G add lib/playthrough_policy.php
$G commit -q -m "TES: plugins declare playthrough tables in ext/*/playthrough_tables.txt"
$G branch -f tes-local HEAD
$G format-patch origin/aiagent..HEAD --stdout > "$REPO/patches/herika-core-local.mbox"
runuser -u www-data -- php -r 'chdir("/var/www/html/HerikaServer"); require "lib/playthrough_policy.php"; $t = pts_playthrough_tables(); echo "Playthrough Save: ", count($t), " tables, of them the plugins: ", count(array_filter($t, fn($x) => str_starts_with($x, "tes_"))), PHP_EOL;'
echo "done"
