#!/bin/bash
# Fixes of 2026-10-04 evening (owner: "полный бред который нейронка не только говорила, но и делала"):
#  1. LLM gibberish ("чтбттбттттт", mangled names): frequency/presence penalty off on connectors 2 and 11
#     (a repetition penalty on Cyrillic JSON pushes the model into alternate spellings); rows backed up.
#  2. ext/tes_world: the law-patrol agent is off (9 of 10 rounds failed half-done), the one spoken to
#     stands still (bridge v9 testalk), gathered people stay at the place and go home after 20 min
#     or on "разойдитесь".
# Run: wsl -d DwemerAI4Skyrim3 -u root -- bash /home/dwemer/TES-Speech-Adapter/tools/apply_fixes_2026-10-04.sh
set -e
REPO=/home/dwemer/TES-Speech-Adapter
HS=/var/www/html/HerikaServer
q() { runuser -u dwemer -- psql --no-password -U dwemer -d dwemer -At -c "$1"; }

echo "== 1. penalties"
q "CREATE TABLE IF NOT EXISTS tes_backup_penalty_conn AS SELECT now() AS saved_at, * FROM core_llm_connector WHERE id IN (2, 11)"
q "UPDATE core_llm_connector SET frequency_penalty = 0, presence_penalty = 0 WHERE id IN (2, 11)"
q "SELECT id, label, frequency_penalty, presence_penalty FROM core_llm_connector WHERE id IN (2, 11)"
echo "   rollback: UPDATE core_llm_connector c SET frequency_penalty=b.frequency_penalty, presence_penalty=b.presence_penalty FROM tes_backup_penalty_conn b WHERE c.id=b.id"

echo "== 2. ext/tes_world"
for f in talk.php realm.php preprocessing.php postrequest.php lib.php; do
    php -l "$REPO/ext/tes_world/$f" >/dev/null
done
mkdir -p /home/dwemer/backups/tes_world_$(date +%Y%m%d_%H%M)
cp -a "$HS/ext/tes_world/." /home/dwemer/backups/tes_world_$(date +%Y%m%d_%H%M)/
cp "$REPO"/ext/tes_world/{talk.php,realm.php,preprocessing.php,postrequest.php,lib.php} "$HS/ext/tes_world/"
chown dwemer:www-data "$HS"/ext/tes_world/*.php
chmod 664 "$HS"/ext/tes_world/*.php
echo "   done (backup in /home/dwemer/backups/)"
echo "== OK. The bridge pex (v9) goes into MO2 from Windows; restart the game to load it."
