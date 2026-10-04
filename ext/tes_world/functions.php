<?php
/*
 * tes_world actions.
 *
 * tesWorldSetTitle() for tes_god_guard's "player.title …" (lib.php), and Carry_Out_Order
 * (core_action CarryOutOrder, settings/world.sql): when the player holds a title, an NPC he
 * orders about can pass the order on to be REALLY done.
 *
 * Live 2026-10-04 02:10: the player-jarl dismissed commander Кай and promoted the guard
 * Вонгвилд; the guard said "Благодарю, ярл" and nothing in the world changed - an NPC has no
 * action for "appoint", "dismiss", "hand over", "release"… and never will have one per wish.
 * The order goes to the goal agent (ext/tes_agent), which has the tools and checks the result.
 */

require_once __DIR__ . '/lib.php';

if (empty($GLOBALS['TES_WORLD_HOOK'])) {
    $GLOBALS['TES_WORLD_HOOK'] = true;
    $GLOBALS['action_post_process_fnct_ex'][] = function ($actions) {
        if (!is_array($actions) || !isset($GLOBALS['db'])) {
            return $actions;
        }
        foreach ($actions as $n => $action) {
            try {
                $parts = explode('|', strval($action));
                $call = explode('@', strval($parts[2] ?? ''));
                $code = function_exists('getFunctionCodeName') ? getFunctionCodeName($call[0]) : false;
                if (($code ?: $call[0]) !== 'CarryOutOrder') {
                    continue;
                }
                unset($actions[$n]);
                $raw = implode('@', array_slice($call, 1));
                $payload = json_decode($raw, true);
                $order = trim(is_array($payload) ? strval($payload['target'] ?? '') : $raw);
                $actor = trim(strval($parts[0] ?? ''));
                $facts = tesWorldFacts();
                if (empty($facts['player_title']) || mb_strlen($order) < 8 || !function_exists('tesAgentStart')) {
                    continue;  // no authority to act on, or nothing to do
                }
                $db = $GLOBALS['db'];
                // the same order is not started twice (the model repeats actions in follow-up lines)
                $dup = $db->fetchOne("SELECT 1 AS x FROM public.tes_agent_tasks WHERE created_at > now() - interval '3 minutes' AND goal LIKE '%" . $db->escape(mb_substr($order, 0, 60)) . "%' LIMIT 1");
                if (!empty($dup)) {
                    continue;
                }
                // ...nor queued again in other words while it waits or runs ("Раздеть Ольфину",
                // "Раздеть Ольфину догола", "…до конца" stood in line four times, 02:54-03:00)
                $same = false;
                $pending = $db->fetchAll("SELECT goal FROM public.tes_agent_tasks WHERE status IN ('waiting', 'queued', 'running') AND created_at > now() - interval '15 minutes'");
                foreach (is_array($pending) ? $pending : [] as $p) {
                    if (preg_match('/отданный через [^:]+:\s*(.+?)\.+\s*(Дословно|Исполни)/us', strval($p['goal']), $pm)) {
                        similar_text(mb_strtolower(trim($pm[1])), mb_strtolower($order), $pct);
                        $same = $same || $pct >= 70;
                    }
                }
                if ($same) {
                    continue;
                }
                $player = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
                $title = mb_substr($facts['player_title'], 0, mb_strpos($facts['player_title'] . '.', '.'));
                // the order as the NPC retold it is often vaguer than what was said: give both,
                // and the people around - the spoken names come mangled from speech recognition
                $said = trim(preg_replace('/\s*\(Talking to [^)]*\)\s*$/u', '', strval($GLOBALS['gameRequest'][3] ?? '')) ?? '');
                $said = trim(preg_replace('/^[^:]{1,40}:\s*/u', '', $said) ?? $said);
                $around = implode(', ', tesWorldNearbyNames());
                $context = ($said !== '' ? " Дословно ярл сказал (распознано с голоса, имена могут быть исковерканы): «" . mb_substr($said, 0, 300) . "»." : '')
                    . ($around !== '' ? " Рядом сейчас: {$around} — искажённое имя это тот из них, чьё имя ближе по звучанию." : '');
                [$ok, $message] = tesAgentStart("Приказ правителя ({$title}), отданный через {$actor}: {$order}.{$context} Правитель — {$player}; «Ярл» в имени NPC — лишь имя прежнего ярла, приказ исходит не от него. Исполни его в мире: должности и занятия — change_character (field occupation) и remember всем причастным; тюрьма, штраф, имущество, отношения — своими инструментами; раздеть взрослого — console «{npc:Имя}.unequipall», одеть — «{npc:Имя}.equipitem {item:Название}»; привести — move_npc. Исполнитель {$actor} получает order: что он сделал. Делай РОВНО приказанное и ничего сверх: не сажай, не штрафуй, не меняй занятие и отношения, если этого нет в приказе. Не выходит с двух попыток — give_up с причиной, не перебирай варианты.", false, false, true);
                error_log("[tes_world] order via {$actor}: " . ($ok ? 'started' : 'not started') . " - {$message} | {$order}");
            } catch (Throwable $e) {
                error_log('[tes_world actions] ' . $e->getMessage());
            }
        }
        return $actions;
    };
}
