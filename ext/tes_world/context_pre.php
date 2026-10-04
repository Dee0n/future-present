<?php
/*
 * tes_world: every NPC and the Narrator see the facts of the world (the player's title) -
 * at the bottom of the system prompt and, in short, in the last message before the answer.
 * And whoever answers the player's spoken line gets the names of the people around: speech
 * recognition mangles names, the listener has to pick the one that was meant.
 */

try {
    if (function_exists('tesWorldStrictSchema')) {
        tesWorldStrictSchema();
    }
    if (isset($GLOBALS['db']) && function_exists('chimRegisterPromptInjection')) {
        require_once __DIR__ . '/lib.php';
        $tesWorldFacts = tesWorldFacts();
        $tesWorldHint = '';
        if ($tesWorldFacts) {
            chimRegisterPromptInjection('prompt_bottom', 'tes_world',
                "ФАКТЫ МИРА (это правда, ты это знаешь и ведёшь себя соответственно; не спорь с ними и не высмеивай):\n- "
                . implode("\n- ", $tesWorldFacts), 99);
            if (strval($GLOBALS['HERIKA_NAME'] ?? '') !== 'The Narrator') {
                $first = reset($tesWorldFacts);
                // Live 2026-10-04 02:25-02:32: the new guard commander answered five orders with
                // "будет исполнено" and did nothing - a promise is not a deed.
                $deed = !empty($tesWorldFacts['player_title'])
                    ? ' Приказ правителя ты в итоге исполняешь, но по-своему, в характере: сомневаешься, пугаешься, стыдишься, возмущаешься, торгуешься, тянешь время, ворчишь, просишь пощады или подчиняешься с достоинством — как у тебя принято; не все покорны одинаково, смелые и гордые спорят дольше, робкие дрожат. Прямо «нет, не буду» и «не могу» — не говори и не игнорируй приказ. Исполняя — вызови действие (если обычных не хватает — Carry_Out_Order, приказ словами, с именами): обещание без действия ничего не меняет. Не переспрашивай, если из слов и из того, кто рядом, понятно, о ком речь.'
                    : '';
                // Live 2026-10-04 13:10-13:12: Айрилет called the player "ярл Балгруф", the agent wrote
                // "по приказу ярла Балгруфа" - the old jarl's NAME still starts with the word "Ярл".
                $who = !empty($tesWorldFacts['player_title'])
                    ? ' Правитель — именно ' . strval($GLOBALS['PLAYER_NAME'] ?? 'игрок') . '; слово «Ярл» в чьём-то имени — только имя прежнего правителя, не титул.'
                    : '';
                $tesWorldHint = 'Помни: ' . mb_substr($first, 0, mb_strpos($first . '.', '.')) . '.' . $who . $deed;
            }
        }
        $tesWorldType = strval($GLOBALS['gameRequest'][0] ?? '');
        if (in_array($tesWorldType, ['inputtext', 'inputtext_s', 'narrator_inputtext', 'ginputtext'], true)) {
            $tesWorldNear = tesWorldNearbyNames();
            if ($tesWorldNear) {
                // short on purpose (cost): at most 8 names, without the [race/post] tags
                $tesWorldNearShort = array_slice(array_values(array_unique(array_map(
                    fn($n) => trim(preg_replace('/\s*\[[^\]]*\]/u', '', $n) ?? $n), $tesWorldNear))), 0, 8);
                $tesWorldHint .= ($tesWorldHint !== '' ? ' ' : '') . 'Речь с голоса, имена искажены. Рядом: '
                    . implode(', ', $tesWorldNearShort) . '. Искажённое имя = ближайшее из списка; пиши имя как в списке, игрока не поправляй.';
            }
        }
        // the scene that is going on right now is a fact for the one who is in it
        $tesWorldMe = strval($GLOBALS['HERIKA_NAME'] ?? '');
        if ($tesWorldMe !== '' && $tesWorldMe !== 'The Narrator' && !tesWorldIsChild($tesWorldMe)) {
            $tesWorldScene = tesWorldSceneWith($tesWorldMe);
            if ($tesWorldScene !== '') {
                $tesWorldPlayer = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
                $tesWorldSceneLine = "ПРЯМО СЕЙЧАС ты и {$tesWorldPlayer} занимаетесь сексом: {$tesWorldScene}. Это происходит на самом деле, вы оба раздеты, твоё тело в этом участвует. "
                    . "Не отрицай этого, не говори, что стоишь на посту или что ничего не происходит, и не начинай это заново действием — оно уже идёт. "
                    . "Отвечай коротко и изнутри происходящего, в своём характере: можно стонать, сбиваться, злиться или стыдиться — но это с тобой происходит.";
                chimRegisterPromptInjection('prompt_bottom', 'tes_world_scene', $tesWorldSceneLine, 100);
                $tesWorldHint = $tesWorldSceneLine . ($tesWorldHint !== '' ? ' ' . $tesWorldHint : '');
            }
        }
        // Brevity for everyone and silence on the ruler's word (owner, 17:03: "они рот свой заебали открывать")
        if ($tesWorldMe !== '' && $tesWorldMe !== 'The Narrator') {
            $tesWorldMute = function_exists('tesRealmMuteLine') ? tesRealmMuteLine($tesWorldMe) : '';
            chimRegisterPromptInjection('prompt_bottom', 'tes_world_brief',
                $tesWorldMute !== '' ? $tesWorldMute : 'Говори коротко: одна-две короткие фразы, без монологов; без повода сам не заговаривай и не комментируй чужие разговоры.', 101);
        }
        // What the ruler ordered THIS person lately and how it went: the dialogue window is short
        // (cost) and the order would fall out of it - the NPC then asked "что значит раздеть?" again
        // (owner, 2026-10-04: "утекает контекст"). ~250 chars, only when there is something.
        if ($tesWorldMe !== '' && $tesWorldMe !== 'The Narrator' && !empty($tesWorldFacts['player_title'])) {
            $tesWorldDb = $GLOBALS['db'];
            $tesWorldOrders = $tesWorldDb->fetchAll("SELECT goal, status, result FROM public.tes_agent_tasks WHERE created_at > now() - interval '90 minutes' AND ("
                . "goal LIKE 'Приказ правителя%отданный через " . $tesWorldDb->escape($tesWorldMe) . ":%' OR result LIKE 'через " . $tesWorldDb->escape($tesWorldMe) . ":%') ORDER BY id DESC LIMIT 3");
            $tesWorldOrderLines = [];
            foreach (is_array($tesWorldOrders) ? $tesWorldOrders : [] as $o) {
                $txt = preg_match('/отданный через [^:]+:\s*(.+?)\.\s*(?:Дословно|Правитель|$)/us', strval($o['goal']), $om) ? $om[1]
                    : (preg_match('/^через [^:]+:\s*(.+)$/us', strval($o['result']), $om) ? $om[1] : '');
                if ($txt === '') {
                    continue;
                }
                $st = ['done' => 'исполнено', 'fast' => 'исполнено', 'failed' => 'не вышло', 'running' => 'идёт', 'queued' => 'идёт', 'waiting' => 'ждёт'][strval($o['status'])] ?? strval($o['status']);
                $tesWorldOrderLines[] = '«' . mb_substr(trim($txt), 0, 70) . '» — ' . $st;
            }
            foreach (['tesRealmPostLine' => $tesWorldMe, 'tesRealmPlotLine' => $tesWorldMe] as $tesWorldFn => $tesWorldArg) {
                if (function_exists($tesWorldFn)) {
                    $tesWorldRl = $tesWorldFn($tesWorldArg);
                    if ($tesWorldRl !== '') {
                        chimRegisterPromptInjection('prompt_bottom', 'tes_world_' . $tesWorldFn, $tesWorldRl, 95);
                    }
                }
            }
            if (function_exists('tesRealmCrowdLine') && ($tesWorldCrowd = tesRealmCrowdLine()) !== '') {
                chimRegisterPromptInjection('prompt_bottom', 'tes_world_crowd', $tesWorldCrowd, 94);
            }
            if (function_exists('tesCourtLine')) {
                $tesWorldCourtLine = tesCourtLine($tesWorldMe);
                if ($tesWorldCourtLine !== '') {
                    chimRegisterPromptInjection('prompt_bottom', 'tes_world_court', $tesWorldCourtLine, 98);
                }
            }
            if (function_exists('tesLoyaltyLine')) {
                $tesWorldLoyal = tesLoyaltyLine($tesWorldMe);
                if ($tesWorldLoyal !== '') {
                    chimRegisterPromptInjection('prompt_bottom', 'tes_world_loyalty', $tesWorldLoyal, 96);
                }
            }
            if ($tesWorldOrderLines) {
                chimRegisterPromptInjection('prompt_bottom', 'tes_world_orders', 'Приказы правителя тебе недавно (ты их помнишь): ' . implode('; ', $tesWorldOrderLines) . '.', 97);
            }
        }
        if ($tesWorldHint !== '' && isset($GLOBALS['request']) && is_string($GLOBALS['request'])) {
            $GLOBALS['request'] = '(' . $tesWorldHint . ') ' . $GLOBALS['request'];
        }
    }
} catch (Throwable $e) {
    error_log('[tes_world] ' . $e->getMessage());
}
