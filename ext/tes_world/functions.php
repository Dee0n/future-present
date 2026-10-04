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

/*
 * OpenAI-family models through OpenRouter (connector 20 "GPT 6 LUNA", live 2026-10-04 15:xx: 70
 * failures an hour) refuse a json_schema whose "required" does not list every property - the
 * core schema leaves "amount" out. Every reply of that connector failed with 400. All properties
 * are made required (the model then sends amount 0 where it means nothing).
 */
if (!function_exists('tesWorldStrictSchema')) {
    function tesWorldStrictSchema(): void
    {
        $props = $GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties'] ?? null;
        if (is_array($props) && $props) {
            $GLOBALS['structuredOutputTemplate']['json_schema']['schema']['required'] = array_keys($props);
        }
    }
}
if (!isset($GLOBALS['HOOKS']['JSON_TEMPLATE']) || !in_array('tesWorldStrictSchema', (array)$GLOBALS['HOOKS']['JSON_TEMPLATE'], true)) {
    $GLOBALS['HOOKS']['JSON_TEMPLATE'][] = 'tesWorldStrictSchema';
}
tesWorldStrictSchema();

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
                // MinAI's adult actions (ExtCmd…): never by a child and never at a child. MinAI's own
                // server check looks for the word "child" in the race - the race here is «Ребенок».
                if (strpos(strval($code ?: $call[0]), 'ExtCmd') === 0) {
                    $extActor = trim(strval($parts[0] ?? ''));
                    $extRaw = json_decode(implode('@', array_slice($call, 1)), true);
                    $extTarget = trim(is_array($extRaw) ? strval($extRaw['target'] ?? '') : implode('@', array_slice($call, 1)));
                    if (tesWorldIsChild($extActor) || ($extTarget !== '' && tesWorldIsChild($extTarget))) {
                        unset($actions[$n]);
                        error_log("[tes_world] adult action dropped (a child): {$extActor} -> {$extTarget}");
                        continue;
                    }
                    // MinAI's game scripts do not load in this build (missing SexLab/DD/SunHelm types,
                    // Papyrus log 2026-10-04), so its commands reach the game and nobody runs them.
                    // The two that matter are done by this mod's own bridge instead:
                    // undress -> unequipall, any "start …" -> an OStim scene (teslove).
                    $extCode = strval($code ?: $call[0]);
                    $extRef = tesWorldRefOf($extActor);
                    if ($extRef !== '' && $extCode === 'ExtCmdRemoveClothes') {
                        tesWorldQueue(['prid ' . $extRef, 'unequipall']);
                        unset($actions[$n]);
                    } elseif ($extRef !== '' && preg_match('/^ExtCmdStart(?!Looting)/', $extCode)) {
                        $player = strval($GLOBALS['PLAYER_NAME'] ?? '');
                        $partnerRef = ($extTarget === '' || mb_strtolower($extTarget) === mb_strtolower($player) || $extTarget === $extActor) ? '' : tesWorldRefOf($extTarget);
                        $partner = $partnerRef !== '' ? hexdec($partnerRef) : 20;  // 20 = the player
                        $db0 = $GLOBALS['db'];
                        $key = "love: {$extActor} + " . ($partnerRef !== '' ? $extTarget : $player);
                        // what kind of scene: from the action the NPC chose, else from the player's words
                        $tags = tesWorldLoveTags($extCode) ?: tesWorldLoveTags(strval($GLOBALS['gameRequest'][3] ?? ''));
                        $key .= " [{$tags}]";
                        // the same kind for the same two is not restarted for 20 s; another kind switches the scene
                        $again = $db0->fetchOne("SELECT 1 AS x FROM public.tes_agent_tasks WHERE created_at > now() - interval '20 seconds' AND status = 'fast' AND goal = '" . $db0->escape($key) . "' LIMIT 1");
                        // The player has just set this NPC a scene with someone else by his own words
                        // ("Сигрид, трахни Айрилет"): her model then picks "sex with the player" out of
                        // habit and would pull her out of it (live 15:10:51) - the player's words win.
                        $other = $partner === 20 ? $db0->fetchOne("SELECT goal FROM public.tes_agent_tasks WHERE created_at > now() - interval '90 seconds' AND status = 'fast' AND result = 'со слов игрока'"
                            . " AND goal LIKE '" . $db0->escape("love: {$extActor} + ") . "%' AND goal NOT LIKE '%+ игрок [%' ORDER BY id DESC LIMIT 1") : null;
                        if (!empty($other['goal'])) {
                            error_log("[tes_world] {$extCode} with the player dropped: {$other['goal']} is set by the player's words");
                        } elseif (empty($again)) {
                            $db0->execQuery("INSERT INTO public.tes_agent_tasks (goal, status, result) VALUES ('" . $db0->escape($key) . "', 'fast', '" . $db0->escape($extCode) . "')");
                            tesWorldQueue(['prid ' . $extRef, trim('teslove ' . $partner . ' ' . tesWorldLoveArg($tags))]);
                            error_log("[tes_world] {$extCode}: scene {$key}");
                        }
                        unset($actions[$n]);
                    } elseif ($extRef !== '' && $extCode === 'ExtCmdEndSex') {
                        tesWorldQueue(['prid ' . $extRef, 'teslove stop']);
                        unset($actions[$n]);
                    }
                    continue;
                }
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
                // An order comes from the player's own line. Live 2026-10-04 13:13: the agent told the
                // executor "ты подвёл Айрилет…", he answered that instruction with Carry_Out_Order
                // again - and his own report became the next order in line.
                if (!in_array(strval($GLOBALS['gameRequest'][0] ?? ''), ['inputtext', 'inputtext_s', 'ginputtext'], true)) {
                    error_log("[tes_world] order via {$actor}: ignored (not the player's line) | {$order}");
                    continue;
                }
                // a standing law ("отныне все …", "закон: …") is kept: every character knows it and
                // the patrol (postrequest.php) keeps enforcing it
                if (preg_match('/(?<![\p{L}])(закон\p{L}*|указ\p{L}*|отныне|впредь|всегда|кажд(ый|ая|ого|ую)|все\s+\p{L}+\s+(должны|обязаны)|запрещ\p{L}+)(?![\p{L}])/iu', $order)) {
                    tesWorldAddLaw($order);
                }
                // bring / undress / execute / take everything: done at once, no agent, no queue
                $fast = tesWorldFastOrder($order, $actor);
                if ($fast) {
                    $doneNow = tesWorldRunFast($fast, $actor, $order);
                    error_log("[tes_world] order via {$actor}: " . ($doneNow !== '' ? "done at once - {$doneNow}" : 'already done') . " | {$order}");
                    continue;
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
