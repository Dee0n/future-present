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
        if ($tesWorldType !== 'narrator_inputtext' && preg_match('/\(Talking to ([^)]+)\)/u', $tesWorldTail, $gm)
            && preg_match('/(?<![\p{L}])(бери|возьми|держи|забирай|получай|на тебе|вот тебе|дарю|даю|жалую|плачу|заплачу)(?![\p{L}])/iu', $tesWorldLine)
            && !preg_match('/(?<![\p{L}])(штраф|отдай|отдавай|верни|плати|заплати)(?![\p{L}])/iu', $tesWorldLine)) {
            $tesWorldGold = tesWorldSpokenAmount($tesWorldLine);
            $tesWorldTo = tesWorldRefOf(trim($gm[1]));
            if ($tesWorldGold > 0 && $tesWorldTo !== '' && (preg_match('/септим|золот|монет|деньг|денег/iu', $tesWorldLine) || $tesWorldGold >= 100)) {
                tesWorldQueue(['player.removeitem 0000000F ' . $tesWorldGold, 'prid ' . $tesWorldTo, 'additem 0000000F ' . $tesWorldGold]);
                $GLOBALS['gameRequest'][3] = $tesWorldHead . $tesWorldLine . " *отдаёт {$tesWorldGold} септимов — золото уже у тебя в кошеле*" . $tesWorldTail;
                error_log("[tes_world] gold: {$tesWorldGold} to " . trim($gm[1]));
            }
        }
        // what the ruler plainly ordered is done at once, whether or not the NPC passes it on
        if ($tesWorldType !== 'narrator_inputtext' && !empty(tesWorldFacts()['player_title'])) {
            $tesWorldWhom = preg_match('/\(Talking to ([^)]+)\)/u', $tesWorldTail, $om) ? trim($om[1]) : '';
            $tesWorldOrder = tesWorldSpokenOrder($tesWorldLine, $tesWorldWhom);
            if ($tesWorldOrder) {
                $tesWorldDone = tesWorldRunFast($tesWorldOrder, $tesWorldWhom, $tesWorldLine);
                if ($tesWorldDone !== '') {
                    $GLOBALS['gameRequest'][3] = $tesWorldHead . $tesWorldLine . " *приказ уже исполняется ({$tesWorldDone}) — не обещай, а подтверди, что делается*" . $tesWorldTail;
                    error_log("[tes_world] spoken order to {$tesWorldWhom}: {$tesWorldDone} | {$tesWorldLine}");
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
            if (preg_match('/\(Talking to ([^)]+)\)/u', $tesWorldTail, $am)) {
                $tesWorldStop[trim($am[1])] = true;
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
