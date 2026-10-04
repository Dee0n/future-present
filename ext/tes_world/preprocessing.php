<?php
/*
 * tes_world, before the request is answered:
 *  1. names the speech recognition mangled are put right in the player's line itself, from the
 *     people standing around ("Будален, казните рилет!" -> "Будолен, казните Айрилет!"), so the
 *     NPC, the Narrator and every plugin after this one read the right name;
 *  2. "хватит за мной ходить" really stops the follow (bridge tesunfollow) for the one addressed
 *     and for anyone around named in the line - saying "да, ярл" changed nothing (13:12, 2026-10-04).
 */

try {
    $tesWorldType = strval($GLOBALS['gameRequest'][0] ?? '');
    if (isset($GLOBALS['db']) && in_array($tesWorldType, ['inputtext', 'inputtext_s', 'narrator_inputtext', 'ginputtext'], true)
        && empty($GLOBALS['TES_WORLD_PRE'])) {
        $GLOBALS['TES_WORLD_PRE'] = true;
        require_once __DIR__ . '/lib.php';
        $tesWorldLine = strval($GLOBALS['gameRequest'][3] ?? '');
        $tesWorldTail = '';
        if (preg_match('/^(.*?)(\s*\(Talking to [^)]*\)\s*)$/us', $tesWorldLine, $tm)) {
            $tesWorldLine = $tm[1];
            $tesWorldTail = $tm[2];
        }
        $tesWorldHead = '';
        if (preg_match('/^([^:]{1,40}:\s*)(.*)$/us', $tesWorldLine, $hm)) {
            $tesWorldHead = $hm[1];
            $tesWorldLine = $hm[2];
        }
        // whom the line is said to: the tail CHIM adds, or the NPC this request is for
        $tesWorldTo = preg_match('/\(Talking to ([^)]+)\)/u', strval($GLOBALS['gameRequest'][3] ?? ''), $wm) ? trim($wm[1]) : '';
        if ($tesWorldTo === '') {
            // The plugin sends the addressee apart from the text, in the request's 5th field (base64
            // JSON, "listener"). At this point the line has no "(Talking to …)" yet - CHIM adds it
            // later - which is why "Бери полмиллиона" and "Раздевайся!" never fired in the game
            // though they passed every test fed from the event log (found 2026-10-04 14:20).
            $tesWorldMeta = json_decode(strval(base64_decode(strval($GLOBALS['gameRequest'][4] ?? ''), true)), true);
            $tesWorldTo = is_array($tesWorldMeta) ? trim(strval($tesWorldMeta['listener'] ?? '')) : '';
        }
        if ($tesWorldTo === '' && strval($GLOBALS['HERIKA_NAME'] ?? '') !== 'The Narrator') {
            $tesWorldTo = trim(strval($GLOBALS['HERIKA_NAME'] ?? ''));
        }
        $tesWorldNames = tesWorldNearbyNames(30);
        if ($tesWorldNames && $tesWorldLine !== '' && mb_substr(ltrim($tesWorldLine), 0, 1) !== '*') {
            [$tesWorldFixed, $tesWorldChanges] = tesWorldFixHeardNames($tesWorldLine, $tesWorldNames);
            if ($tesWorldChanges) {
                $GLOBALS['gameRequest'][3] = $tesWorldHead . $tesWorldFixed . $tesWorldTail;
                $tesWorldLine = $tesWorldFixed;
                error_log('[tes_world] heard names: ' . implode('; ', $tesWorldChanges));
            }
        }
        // "Бери полмиллиона": the gold really changes hands (the NPC only talked about it, 13:23)
        if (preg_match('/(?<![\p{L}])(бери|возьми|держи|забирай|получай)(?![\p{L}])/iu', $tesWorldLine)) {
            // live 2026-10-04 13:50: this block did not fire in the game though the same line passes by hand
            error_log('[tes_world] gold? type=' . $tesWorldType . ' to=' . $tesWorldTo . ' amount=' . tesWorldSpokenAmount($tesWorldLine) . ' raw=' . mb_substr(strval($GLOBALS['gameRequest'][3] ?? ''), 0, 140));
        }
        if ($tesWorldType !== 'narrator_inputtext' && $tesWorldTo !== '' && ($gm = [0, $tesWorldTo])
            && preg_match('/(?<![\p{L}])(бери|возьми|держи|забирай|получай|на тебе|вот тебе|дарю|даю|жалую|плачу|заплачу)(?![\p{L}])/iu', $tesWorldLine)
            && !preg_match('/(?<![\p{L}])(штраф|отдай|отдавай|верни|плати|заплати)(?![\p{L}])/iu', $tesWorldLine)) {
            $tesWorldGold = tesWorldSpokenAmount($tesWorldLine);
            $tesWorldGoldRef = tesWorldRefOf(trim($gm[1]));  // not into \$tesWorldTo: the blocks below need the name
            if ($tesWorldGold > 0 && $tesWorldGoldRef !== '' && (preg_match('/септим|золот|монет|деньг|денег/iu', $tesWorldLine) || $tesWorldGold >= 100)) {
                tesWorldQueue(['player.removeitem 0000000F ' . $tesWorldGold, 'prid ' . $tesWorldGoldRef, 'additem 0000000F ' . $tesWorldGold]);
                $GLOBALS['gameRequest'][3] = $tesWorldHead . $tesWorldLine . " *отдаёт {$tesWorldGold} септимов — золото уже у тебя в кошеле*" . $tesWorldTail;
                error_log("[tes_world] gold: {$tesWorldGold} to " . trim($gm[1]));
            }
        }
        // the treasury and the court: the ruler's words about them are done at once
        if (($tesWorldTo !== '' || $tesWorldType === 'narrator_inputtext') && function_exists('tesCourtSpoken')) {
            // said to the Narrator ("Суд над Оранверном идёт…" 16:53 - went nowhere): the same court
            $tesWorldAddr = $tesWorldTo !== '' ? $tesWorldTo : 'The Narrator';
            $tesWorldCourt = tesCourtSpoken($tesWorldLine, $tesWorldAddr);
            if ($tesWorldCourt === '' && function_exists('tesRealmSpoken')) {
                $tesWorldCourt = tesRealmSpoken($tesWorldLine, $tesWorldAddr);
            }
            if ($tesWorldCourt !== '') {
                $GLOBALS['gameRequest'][3] = $tesWorldHead . $tesWorldLine . $tesWorldCourt . $tesWorldTail;
                error_log("[tes_world] court/treasury: {$tesWorldCourt}");
            }
        }
        // what the ruler plainly ordered is done at once, whether or not the NPC passes it on
        if ($tesWorldType !== 'narrator_inputtext' && !empty(tesWorldFacts()['player_title'])) {
            $tesWorldWhom = $tesWorldTo;
            $tesWorldOrder = tesWorldSpokenOrder($tesWorldLine, $tesWorldWhom);
            if ($tesWorldOrder) {
                $tesWorldDone = tesWorldRunFast($tesWorldOrder, $tesWorldWhom, $tesWorldLine);
                if ($tesWorldDone !== '') {
                    $GLOBALS['gameRequest'][3] = $tesWorldHead . $tesWorldLine . " *приказ уже исполняется ({$tesWorldDone}) — не обещай, а подтверди, что делается*" . $tesWorldTail;
                    error_log("[tes_world] spoken order to {$tesWorldWhom}: {$tesWorldDone} | {$tesWorldLine}");
                }
            } elseif ($tesWorldWhom !== '' && !preg_match('/(?<![\p{L}])(трахн\p{L}*|трахай\p{L}*|выеби\p{L}*|отсоси\p{L}*|минет\p{L}*|секс\p{L}*|развлек\p{L}*|займись|займитесь|ублажа\p{L}*)(?![\p{L}])/iu', $tesWorldLine)) {
                // a plain order that is none of the quick kinds: the agent takes it from the words
                // (sex lines are the scene code's business below)
                // group orders ("Срывай одежду со всех молодых женщин") are the quick kind too:
                // the line without its vocative ("Стража!", "Торгар,") goes through the fast parser
                $tesWorldBody = trim(preg_replace('/^[^:]{1,40}:\s*/u', '', $tesWorldLine) ?? $tesWorldLine);
                $tesWorldBody = trim(preg_replace('/^(?:\p{Lu}[\p{L}\-]*[,!]\s*)+/u', '', $tesWorldBody) ?? $tesWorldBody);
                $tesWorldQuick = $tesWorldBody !== '' ? tesWorldFastOrder(mb_strtolower(mb_substr($tesWorldBody, 0, 1)) . mb_substr($tesWorldBody, 1), $tesWorldWhom) : null;
                if ($tesWorldQuick) {
                    $tesWorldDone = tesWorldRunFast($tesWorldQuick, $tesWorldWhom, $tesWorldBody);
                    $tesWorldAgent = $tesWorldDone !== '' ? "быстро: {$tesWorldDone}" : '';
                } else {
                    $tesWorldAgent = tesWorldAgentOrder($tesWorldLine, $tesWorldWhom);
                }
                if ($tesWorldAgent !== '') {
                    $GLOBALS['gameRequest'][3] = $tesWorldHead . $tesWorldLine . " *приказ уже исполняется — не обещай, а подтверди, что делается*" . $tesWorldTail;
                    error_log("[tes_world] spoken order to {$tesWorldWhom}: {$tesWorldAgent} | {$tesWorldLine}");
                }
            }
        }
        // "Стоп!", "Остановись!" said to someone ends the scene with him (live 14:36-14:37: said
        // twice, nothing happened). With no scene running the bridge just answers "no scene to end".
        // A line said to nobody (it goes to the Narrator) is about the one the player is with:
        // the partner of the last scene, else the only living person around (live 14:35:
        // "Как будто бы и лизать должен, нет?" went to the Narrator and changed nothing).
        $tesWorldWith = $tesWorldType !== 'narrator_inputtext' ? $tesWorldTo : tesWorldCompanion();
        if ($tesWorldWith !== ''
            && preg_match('/^[\s\p{P}]*(стоп|стой|остановись|остановитесь|прекрати\p{L}*|хватит|довольно|достаточно|закончи\p{L}*|все,? хватит)([\s\p{P}]+(секс\p{L}*|трах\p{L}*|еб\p{L}*|это|уже|сцен\p{L}*|все))*[\s\p{P}]*$/iu', $tesWorldLine)) {
            $tesWorldStopped = true;  // "Стоп секс!" (live 15:01) is a stop, not a new scene for the word "секс"
            $tesWorldStopRef = tesWorldRefOf($tesWorldWith);
            if ($tesWorldStopRef !== '') {
                tesWorldQueue(['prid ' . $tesWorldStopRef, 'teslove stop']);
                error_log("[tes_world] scene stop asked: {$tesWorldWith}");
            }
        }
        // The scene starts from the player's own words. Live 2026-10-04 14:34: "буду лизать тебе…",
        // "пора бы мне начинать" - Сигрид answered "я готова" three times and never chose the
        // action that starts it. What kind: from this line; "начинаем" alone - from what the
        // player said to the same person in the last 10 minutes.
        if ($tesWorldWith !== '' && empty($tesWorldStopped) && !tesWorldIsChild($tesWorldWith)) {
            $tesWorldLove = tesWorldLoveTags($tesWorldLine);
            // "Займись самоудовлетворением", "Драчи себе" (live 15:16): one person, no partner - a solo scene
            $tesWorldSolo = (bool)preg_match('/(мастурб\p{L}*|самоудовлетвор\p{L}*|(?:дроч\p{L}*|драч\p{L}*|потр\p{L}+|поласкай|ласкай|трогай)\s+(?:себе|себя)|себе\s+(?:клитор|писю|пизду|член|сиськи))/iu', $tesWorldLine);
            if ($tesWorldSolo) {
                $tesWorldLove = 'solo';
            }
            $tesWorldGo = (bool)preg_match('/(?<![\p{L}])(начина\p{L}*|начн\p{L}*|начать|приступ\p{L}*|давай уже|поехали)(?![\p{L}])/iu', $tesWorldLine);
            if ($tesWorldLove === '' && $tesWorldGo) {
                $tesWorldPrev = $GLOBALS['db']->fetchAll("SELECT data FROM eventlog WHERE type IN ('inputtext', 'inputtext_s') AND localts > " . (time() - 600)
                    . " AND data LIKE '%(Talking to " . $GLOBALS['db']->escape($tesWorldWith) . ")%' ORDER BY rowid DESC LIMIT 4");
                foreach (is_array($tesWorldPrev) ? $tesWorldPrev : [] as $pr) {
                    $tesWorldLove = $tesWorldLove ?: tesWorldLoveTags(strval($pr['data']));
                }
            }
            $tesWorldLoveRef = $tesWorldLove !== '' ? tesWorldRefOf($tesWorldWith) : '';
            if ($tesWorldLoveRef !== '' && !preg_match('/(?<![\p{L}])(не\s+(буду|хочу|надо|будем)|потом|позже|завтра)(?![\p{L}])/iu', $tesWorldLine)) {
                // "Сигрид, трахни Айрилет": the scene is of the two of them, the player only watches.
                // The one spoken to is the one who acts - first in the scene.
                $tesWorldThird = tesWorldThirdPerson($tesWorldLine, $tesWorldWith);
                $tesWorldThirdRef = ($tesWorldThird !== '' && !tesWorldIsChild($tesWorldThird)) ? tesWorldRefOf($tesWorldThird) : '';
                $tesWorldKey = "love: {$tesWorldWith} + " . ($tesWorldThirdRef !== '' ? $tesWorldThird : 'игрок') . " [{$tesWorldLove}]";
                $tesWorldOnce = $GLOBALS['db']->fetchOne("SELECT 1 AS x FROM public.tes_agent_tasks WHERE created_at > now() - interval '20 seconds' AND status = 'fast' AND goal = '" . $GLOBALS['db']->escape($tesWorldKey) . "' LIMIT 1");
                if (empty($tesWorldOnce)) {
                    $GLOBALS['db']->execQuery("INSERT INTO public.tes_agent_tasks (goal, status, result) VALUES ('" . $GLOBALS['db']->escape($tesWorldKey) . "', 'fast', 'со слов игрока')");
                    if ($tesWorldSolo) {
                        // one person alone: not "with the player" - the scene is only hers
                        if (function_exists('tesBridgeVersion') && tesBridgeVersion() >= 3) {
                            tesWorldQueue(['prid ' . $tesWorldLoveRef, 'unequipall', 'teslove solo masturbation,femalemasturbation,malemasturbation']);
                        } else {
                            error_log("[tes_world] solo scene asked, the game's bridge is old: {$tesWorldWith}");
                        }
                    } elseif ($tesWorldThirdRef !== '' && $tesWorldThirdRef !== $tesWorldLoveRef) {
                        tesWorldQueue(['prid ' . $tesWorldLoveRef, 'unequipall', 'prid ' . $tesWorldThirdRef, 'unequipall',
                            'teslove ' . hexdec($tesWorldLoveRef) . ' ' . tesWorldLoveArg($tesWorldLove)]);
                    } else {
                        tesWorldQueue(['prid ' . $tesWorldLoveRef, 'unequipall', 'teslove 20 ' . tesWorldLoveArg($tesWorldLove)]);
                    }
                    $GLOBALS['gameRequest'][3] = rtrim(strval($GLOBALS['gameRequest'][3])) . ($tesWorldThirdRef !== '' ? " *ты уже делаешь это с {$tesWorldThird}, на самом деле; игрок только смотрит — отвечай как участница, а не обещай*" : " *это уже происходит на самом деле — отвечай как участница, а не обещай*") . $tesWorldTail;
                    error_log("[tes_world] scene from the player's words: {$tesWorldKey}");
                }
            }
        }
        // "отменяю закон" clears the standing laws
        if (preg_match('/(отмен\p{L}+|снима\p{L}+|упраздн\p{L}+)\s+(все\s+|мой\s+|этот\s+)?(закон|указ)/iu', $tesWorldLine)) {
            error_log('[tes_world] laws cleared: ' . tesWorldClearLaws());
        }
        if (preg_match('/(хватит|перестань\p{L}*|прекрати\p{L}*|не надо|не нужно|не)\s+(\p{L}+\s+){0,3}?(ходить|ходи\p{L}*|следовать|следуй\p{L}*|таскаться|плестись)|отстань\p{L}*|отвали\p{L}*|отвяжи\p{L}*/iu', $tesWorldLine)
        ) {
            $tesWorldStop = [];
            if ($tesWorldTo !== '') {
                $tesWorldStop[$tesWorldTo] = true;
            }
            foreach (preg_split('/[^\p{L}]+/u', $tesWorldLine) as $w) {
                $hit = $w !== '' ? tesWorldHeardName($w, $tesWorldNames) : '';
                if ($hit !== '') {
                    $tesWorldStop[$hit] = true;
                }
            }
            $tesWorldQuest = $GLOBALS['db']->fetchOne("SELECT quest_key FROM public.skyrim_quest_instances ORDER BY quest_key LIMIT 1");
            foreach (array_keys($tesWorldStop) as $who) {
                $ref = tesWorldRefOf($who);
                if ($ref !== '' && !empty($tesWorldQuest['quest_key'])) {
                    $GLOBALS['db']->insert('skyrim_quest_action_outbox', [
                        'quest_key' => $tesWorldQuest['quest_key'], 'beat_id' => 'tes_unfollow', 'action_type' => 'console_command_sequence',
                        'payload_json' => json_encode(['type' => 'console_command_sequence', 'commands' => ['prid ' . $ref, 'tesunfollow']]),
                    ]);
                    error_log("[tes_world] {$who}: told to stop following - follow flag cleared");
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_world pre] ' . $e->getMessage());
}
