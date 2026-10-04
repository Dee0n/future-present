<?php
/*
 * tes_world: every NPC and the Narrator see the facts of the world (the player's title) -
 * at the bottom of the system prompt and, in short, in the last message before the answer.
 * And whoever answers the player's spoken line gets the names of the people around: speech
 * recognition mangles names, the listener has to pick the one that was meant.
 */

try {
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
                    ? ' Приказ правителя не обсуждай и не отвечай «не могу». Если приказ нельзя исполнить твоими обычными действиями — вызови Carry_Out_Order (приказ словами, с именами): обещание без действия ничего не меняет. Не переспрашивай, если из слов и из того, кто рядом, понятно, о ком речь.'
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
                $tesWorldHint .= ($tesWorldHint !== '' ? ' ' : '') . 'Слова игрока распознаны с голоса: имена бывают исковерканы или разбиты на части. Рядом сейчас: '
                    . implode(', ', $tesWorldNear) . '. Искажённое имя — это тот из них, чьё имя ближе по звучанию; в действиях пиши имя точно как в этом списке, а игрока за оговорку не поправляй.';
            }
        }
        if ($tesWorldHint !== '' && isset($GLOBALS['request']) && is_string($GLOBALS['request'])) {
            $GLOBALS['request'] = '(' . $tesWorldHint . ') ' . $GLOBALS['request'];
        }
    }
} catch (Throwable $e) {
    error_log('[tes_world] ' . $e->getMessage());
}
