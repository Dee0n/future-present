#!/bin/bash
# Fixes of 2026-10-04 late evening (owner: "все остальные проблемы решишь? все все все"):
#  1. NPC-to-NPC chatter back, moderate (owner: "болтовня то пусть будет, но не овермного"):
#     RECHAT_P 15, RECHAT_H 2, BORED_EVENT 5 (feasts raise RECHAT_P to 35 while they last, realm.php).
#  2. "больше действий": the prompt's ACTIONS part says a said deed is done by an action in the same
#     reply; Hug and HoldHands back for NPCs and followers (switched off for cost earlier today).
#  3. ext/tes_world: feasts (people already in the inn join, ale, chatter), the agent only on real
#     orders, misspelled action names understood, the feast line only for the gathered, scenes not
#     started by swearing.
# Run: wsl -d DwemerAI4Skyrim3 -u root -- bash /home/dwemer/TES-Speech-Adapter/tools/apply_fixes_2026-10-04b.sh
set -e
REPO=/home/dwemer/TES-Speech-Adapter
HS=/var/www/html/HerikaServer
q() { runuser -u dwemer -- psql --no-password -U dwemer -d dwemer -At -c "$1"; }

echo "== 1. chatter"
q "CREATE TABLE IF NOT EXISTS tes_backup_chatter_profile AS SELECT now() AS saved_at, id, metadata FROM core_profiles WHERE id = 1"
q "UPDATE core_profiles SET metadata = jsonb_set(jsonb_set(jsonb_set(metadata, '{RECHAT_P}', '15'::jsonb), '{RECHAT_H}', '2'::jsonb), '{BORED_EVENT}', '5'::jsonb) WHERE id = 1"
q "SELECT 'RECHAT_P=' || (metadata->>'RECHAT_P') || ' RECHAT_H=' || (metadata->>'RECHAT_H') || ' BORED_EVENT=' || (metadata->>'BORED_EVENT') FROM core_profiles WHERE id = 1"

echo "== 2. actions"
q "CREATE TABLE IF NOT EXISTS tes_backup_prompt_head_actions AS SELECT now() AS saved_at, id, value FROM general_settings WHERE id = 'PROMPT_HEAD'"
if [ "$(q "SELECT position('Сказал — сделал' in value) FROM general_settings WHERE id = 'PROMPT_HEAD'")" = "0" ]; then
q "UPDATE general_settings SET value = replace(value, E'### ДЕЙСТВИЯ\n', E'### ДЕЙСТВИЯ\nСказал — сделал: если в реплике ты что-то делаешь или соглашаешься сделать прямо сейчас («иду», «пойдём», «садись», «держи», «выпьем», «ну давай подерёмся», «обними меня») — в ЭТОМ ЖЕ ответе выбери подходящее действие (Travel_To, Follow, Take_A_Seat, Give_Item_To, Drink, Brawl, Hug…). Talk — только когда ты на самом деле лишь говоришь. Живой человек не стоит столбом: садится, пьёт, подходит, уходит по делам.\n') WHERE id = 'PROMPT_HEAD'"
fi
q "SELECT 'prompt rule: ' || (position('Сказал — сделал' in value) > 0)::text FROM general_settings WHERE id = 'PROMPT_HEAD'"
q "CREATE TABLE IF NOT EXISTS tes_backup_actions_hug AS SELECT now() AS saved_at, code_name, available_to_npc, available_to_followers FROM core_action WHERE code_name IN ('ExtCmdHug', 'ExtCmdHoldHands')"
q "UPDATE core_action SET available_to_npc = true, available_to_followers = true WHERE code_name IN ('ExtCmdHug', 'ExtCmdHoldHands')"
q "SELECT code_name || ' npc=' || available_to_npc FROM core_action WHERE code_name IN ('ExtCmdHug', 'ExtCmdHoldHands')"

echo "== 3. ext/tes_world"
FILES="talk.php realm.php preprocessing.php postrequest.php lib.php functions.php context_pre.php"
for f in $FILES; do php -l "$REPO/ext/tes_world/$f" >/dev/null; done
B=/home/dwemer/backups/tes_world_$(date +%Y%m%d_%H%M%S)
mkdir -p "$B" && cp -a "$HS/ext/tes_world/." "$B/"
for f in $FILES; do cp "$REPO/ext/tes_world/$f" "$HS/ext/tes_world/$f"; done
chown dwemer:www-data "$HS"/ext/tes_world/*.php && chmod 664 "$HS"/ext/tes_world/*.php
for f in $FILES; do php -l "$HS/ext/tes_world/$f" | grep -v "No syntax" || true; done
echo "   done (backup in $B)"
echo "== OK"
