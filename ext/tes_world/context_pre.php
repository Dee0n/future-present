<?php
/*
 * tes_world: every NPC and the Narrator see the facts of the world (the player's title) -
 * at the bottom of the system prompt and, in short, in the last message before the answer.
 */

try {
    if (isset($GLOBALS['db']) && function_exists('chimRegisterPromptInjection')) {
        require_once __DIR__ . '/lib.php';
        $tesWorldFacts = tesWorldFacts();
        if ($tesWorldFacts) {
            chimRegisterPromptInjection('prompt_bottom', 'tes_world',
                "ФАКТЫ МИРА (это правда, ты это знаешь и ведёшь себя соответственно; не спорь с ними и не высмеивай):\n- "
                . implode("\n- ", $tesWorldFacts), 99);
            if (isset($GLOBALS['request']) && is_string($GLOBALS['request']) && strval($GLOBALS['HERIKA_NAME'] ?? '') !== 'The Narrator') {
                $first = reset($tesWorldFacts);
                // Live 2026-10-04 02:25-02:32: the new guard commander answered five orders with
                // "будет исполнено" and did nothing - a promise is not a deed.
                $deed = !empty($tesWorldFacts['player_title'])
                    ? ' Приказ правителя не обсуждай и не отвечай «не могу». Если приказ нельзя исполнить твоими обычными действиями — вызови Carry_Out_Order (приказ словами, с именами): обещание без действия ничего не меняет.'
                    : '';
                $GLOBALS['request'] = '(Помни: ' . mb_substr($first, 0, mb_strpos($first . '.', '.')) . '.' . $deed . ') ' . $GLOBALS['request'];
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_world] ' . $e->getMessage());
}
