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
        $tesWorldRealLaws = tesWorldLaws();  // without the agent's "приказать исполнить закон…" junk (worn.php)
        foreach (array_keys($tesWorldPlain) as $k0) {
            if (strpos($k0, 'law_') === 0 && !isset($tesWorldRealLaws[$k0])) {
                unset($tesWorldPlain[$k0]);
            }
        }
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
                "WORLD FACTS (true):\n- " . implode("\n- ", $tesWorldFacts), 99);
            if ($tesWorldMe0 !== 'The Narrator' && $tesWorldSpoken0) {
                $first = reset($tesWorldFacts);
                // Live 2026-10-04 02:25-02:32: the new guard commander answered five orders with
                // "будет исполнено" and did nothing - a promise is not a deed.
                // short and only when the line is an order: the long list of reactions was repeated word for word
                $tesWorldLineNow = strval($GLOBALS['gameRequest'][3] ?? '');
                $tesWorldLineNow = trim(preg_replace('/^[^:]{1,40}:\s*/u', '', preg_replace('/\s*\(Talking to [^)]*\)\s*$/u', '', $tesWorldLineNow) ?? $tesWorldLineNow) ?? $tesWorldLineNow);
                $deed = (!empty($tesWorldFacts['player_title']) && function_exists('tesWorldLooksLikeOrder') && tesWorldLooksLikeOrder($tesWorldLineNow))
                    ? ' This is an order: carry it out with an action (Carry_Out_Order if none fits), react in character, in your own words, no grand words about «воля» and «закон».'
                    : '';
                // Live 2026-10-04 13:10-13:12: Айрилет called the player "ярл Балгруф", the agent wrote
                // "по приказу ярла Балгруфа" - the old jarl's NAME still starts with the word "Ярл".
                $who = !empty($tesWorldFacts['player_title'])
                    ? ' The ruler is precisely ' . strval($GLOBALS['PLAYER_NAME'] ?? 'the player') . '; the word «Ярл» inside a name is just the name of the former ruler, not a title.'
                    : '';
                $tesWorldHint = 'Remember: ' . mb_substr($first, 0, mb_strpos($first . '.', '.')) . '.' . $who . $deed;
            }
        }
        // The Narrator answered DIFFERENT requests with the same three lines word for word (live 19:10:21 and
        // 19:10:44: "пониже" / "чуть ниже того, что было"): the model copies its own last replies from the history,
        // and starts nearly every one with «Что ж, ярл…» (owner: "наратор заебал повторятся"). It is shown what it
        // said lately and told not to say it again.
        if ($tesWorldMe0 === 'The Narrator') {
            $tesWorldLast = $GLOBALS['db']->fetchAll("SELECT DISTINCT ON (data) data, rowid FROM eventlog WHERE type = 'chat' AND data LIKE 'The Narrator:%' AND localts > " . (time() - 2400) . " ORDER BY data, rowid DESC");
            usort($tesWorldLast, fn($a, $b) => intval($b['rowid']) <=> intval($a['rowid']));
            $tesWorldSaid = [];
            foreach (array_slice(is_array($tesWorldLast) ? $tesWorldLast : [], 0, 8) as $tl) {
                $line = trim(preg_replace('/^The Narrator:\s*|\s*\(talking to [^)]*\)\s*$/u', '', strval($tl['data'])) ?? '');
                if ($line !== '') {
                    $tesWorldSaid[] = '«' . mb_substr($line, 0, 90) . '»';
                }
            }
            // the system block alone was ignored (live 19:19: the same three lines again) - the same words ride in
            // the last message, right before the answer
            $tesWorldHint .= ($tesWorldHint !== '' ? ' ' : '') . ($tesWorldSaid ? 'FORBIDDEN to repeat your past lines: ' . implode(' ', array_slice($tesWorldSaid, 0, 6)) . '. ' : '')
                . 'Answer in new words, one or two short sentences, not starting with «Что ж»; if the request is an action, do it and describe the result in one sentence.';
            chimRegisterPromptInjection('prompt_bottom', 'tes_narrator_fresh',
                ($tesWorldSaid ? 'You already said: ' . implode(' ', $tesWorldSaid) . ' - do not repeat these lines, their imagery or openings. ' : '')
                . 'Say something new and to the point, briefly. Do not start with «Что ж», «Ох», «Ах», «Ну что»; do not call the player «ярл» in every line; do not retell his request or comment on how «переменчив» he is.', 102);
        }
        $tesWorldType = strval($GLOBALS['gameRequest'][0] ?? '');
        if (in_array($tesWorldType, ['inputtext', 'inputtext_s', 'narrator_inputtext', 'ginputtext'], true)) {
            $tesWorldNear = tesWorldNearbyNames();
            if ($tesWorldNear) {
                // short on purpose (cost): at most 8 names, without the [race/post] tags
                $tesWorldNearShort = array_slice(array_values(array_unique(array_map(
                    fn($n) => trim(preg_replace('/\s*\[[^\]]*\]/u', '', $n) ?? $n), $tesWorldNear))), 0, 8);
                $tesWorldHint .= ($tesWorldHint !== '' ? ' ' : '') . 'Speech is voice-recognised, names are garbled. Nearby: '
                    . implode(', ', $tesWorldNearShort) . '. A garbled name = the closest one in the list; write the name as listed, do not correct the player.';
            }
        }
        // the scene that is going on right now is a fact for the one who is in it
        $tesWorldMe = strval($GLOBALS['HERIKA_NAME'] ?? '');
        if ($tesWorldMe !== '' && $tesWorldMe !== 'The Narrator' && !tesWorldIsChild($tesWorldMe)) {
            $tesWorldScene = tesWorldSceneWith($tesWorldMe);
            if ($tesWorldScene !== '') {
                $tesWorldPlayer = strval($GLOBALS['PLAYER_NAME'] ?? 'the player');
                $tesWorldSceneLine = "RIGHT NOW you and {$tesWorldPlayer} are having sex: {$tesWorldScene}. It is really happening, you are both undressed, your body takes part in it. "
                    . "Do not deny it, do not say you are on duty or that nothing is happening, and do not start it again with an action - it is already going on. "
                    . "Answer briefly and from inside what is happening, in character: you may moan, falter, be angry or ashamed - but it is happening to you.";
                chimRegisterPromptInjection('prompt_bottom', 'tes_world_scene', $tesWorldSceneLine, 100);
                $tesWorldHint = $tesWorldSceneLine . ($tesWorldHint !== '' ? ' ' . $tesWorldHint : '');
            }
        }
        // what is really on the body (worn.php): the model said "я разделась" / "я уже оделся" by mood
        if ($tesWorldMe !== '' && $tesWorldMe !== 'The Narrator' && function_exists('tesWornLine') && !tesWorldIsChild($tesWorldMe)
            && empty($tesWorldScene)) {
            $tesWorldWornLine = tesWornLine($tesWorldMe);
            if ($tesWorldWornLine !== '') {
                chimRegisterPromptInjection('prompt_bottom', 'tes_world_worn', $tesWorldWornLine, 100);
            }
        }
        // Brevity for everyone and silence on the ruler's word (owner, 17:03: "они рот свой заебали открывать")
        if ($tesWorldMe !== '' && $tesWorldMe !== 'The Narrator') {
            $tesWorldMute = function_exists('tesRealmMuteLine') ? tesRealmMuteLine($tesWorldMe) : '';
            // owner, 23:30: "че они про честь заладили. ебут голову" - the models moralise about honour, shame and
            // the ruler's power in every other line (9 of the last hour's lines, the same people over and over)
            $tesWorldNoHonor = ' Do not talk about honour, shame, dignity, humiliation, sanctity or what kind of ruler the jarl is; no sighing or lecturing - speak of the matter at hand, of what is before you now, of your own affairs.'
                // owner, 00:09: "заебали про приказы и про раздевание мозг ебать"
                . ' Do not bring up orders, the will and word of the jarl, obedience, undressing, clothes or nakedness - neither your own nor that of others; unless the jarl himself asked about it, these topics do not exist.';
            chimRegisterPromptInjection('prompt_bottom', 'tes_world_brief',
                $tesWorldMute !== '' ? $tesWorldMute : (((function_exists('tesRealmPartyActive') && tesRealmPartyActive($tesWorldMe))
                    ? 'You are at a feast: drinking, eating, laughing, chatting with neighbours, joking, singing, raising your mug; speak up yourself, unprompted, lively and short - one or two sentences.'
                    : 'Speak briefly: one or two short sentences, no monologues; do not speak up unprompted and do not comment on other people talking.') . $tesWorldNoHonor), 101);
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
                $st = ['done' => 'done', 'fast' => 'done', 'failed' => 'failed', 'running' => 'in progress', 'queued' => 'in progress', 'waiting' => 'waiting'][strval($o['status'])] ?? strval($o['status']);
                $tesWorldOrderLines[] = '«' . mb_substr(trim($txt), 0, 70) . '» — ' . $st;
            }
            foreach (['tesRealmPostLine' => $tesWorldMe, 'tesRealmPlotLine' => $tesWorldMe, 'tesCompanionLine' => $tesWorldMe, 'tesFameLine' => $tesWorldMe, 'tesEconomyLine' => $tesWorldMe] as $tesWorldFn => $tesWorldArg) {
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
                chimRegisterPromptInjection('prompt_bottom', 'tes_world_orders', 'Recent orders of the ruler to you (you remember them): ' . implode('; ', $tesWorldOrderLines) . '.', 97);
            }
        }
        // the place remembers what happened here (world.php) - for the Narrator and everyone
        if (function_exists('tesWorldPlaceLine') && ($tesWorldPlace = tesWorldPlaceLine()) !== '') {
            chimRegisterPromptInjection('prompt_bottom', 'tes_world_place', $tesWorldPlace, 93);
        }
        if ($tesWorldHint !== '' && isset($GLOBALS['request']) && is_string($GLOBALS['request'])) {
            $GLOBALS['request'] = '(' . $tesWorldHint . ') ' . $GLOBALS['request'];
        }
    }
} catch (Throwable $e) {
    error_log('[tes_world] ' . $e->getMessage());
}
