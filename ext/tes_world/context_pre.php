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
        // The laws and the "obey the ruler" lines went into EVERY request of EVERY NPC and were echoed back
        // ("воля ярла… закон… закон… " - owner, 22:0x: "заебали повторяться"; even Lilith and the weaver
        // chatted about "the new law"). Now: the title is a fact for everyone; the laws and the duty to obey are
        // told only to those who enforce or are ordered (guards, the court, a steward) and only when the ruler
        // speaks to them - the rest hear of the law as rumour, not as a script.
        $tesWorldType0 = strval($GLOBALS['gameRequest'][0] ?? '');
        $tesWorldMe0 = strval($GLOBALS['HERIKA_NAME'] ?? '');
        $tesWorldSpoken0 = in_array($tesWorldType0, ['inputtext', 'inputtext_s', 'ginputtext'], true);
        $tesWorldEnforcer = (bool)preg_match('/Стражник|Хускарл|Командир|Капитан|Легат|Ярл\s|Стюард|Управляющ|Судья|Палач/u', $tesWorldMe0);
        $tesWorldPlain = $tesWorldFacts;
        if (!$tesWorldEnforcer) {
            foreach (array_keys($tesWorldPlain) as $k0) {
                if (strpos($k0, 'law_') === 0) {
                    unset($tesWorldPlain[$k0]);
                }
            }
        }
        $tesWorldFacts = $tesWorldPlain ? $tesWorldPlain : [];
        if ($tesWorldFacts) {
            chimRegisterPromptInjection('prompt_bottom', 'tes_world',
                "ФАКТЫ МИРА (это правда):\n- " . implode("\n- ", $tesWorldFacts), 99);
            if ($tesWorldMe0 !== 'The Narrator' && $tesWorldSpoken0) {
                $first = reset($tesWorldFacts);
                // Live 2026-10-04 02:25-02:32: the new guard commander answered five orders with
                // "будет исполнено" and did nothing - a promise is not a deed.
                // short and only when the line is an order: the long list of reactions was repeated word for word
                $tesWorldLineNow = strval($GLOBALS['gameRequest'][3] ?? '');
                $tesWorldLineNow = trim(preg_replace('/^[^:]{1,40}:\s*/u', '', preg_replace('/\s*\(Talking to [^)]*\)\s*$/u', '', $tesWorldLineNow) ?? $tesWorldLineNow) ?? $tesWorldLineNow);
                $deed = (!empty($tesWorldFacts['player_title']) && function_exists('tesWorldLooksLikeOrder') && tesWorldLooksLikeOrder($tesWorldLineNow))
                    ? ' Это приказ: исполни его действием (Carry_Out_Order, если подходящего нет), реагируй в характере, своими словами, без громких слов про «волю» и «закон».'
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
            if (function_exists('tesRealmCrowdLine') && ($tesWorldCrowd = tesRealmCrowdLine($tesWorldMe)) !== '') {
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
