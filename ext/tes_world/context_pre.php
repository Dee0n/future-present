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
                $GLOBALS['request'] = '(Помни: ' . mb_substr($first, 0, mb_strpos($first . '.', '.')) . '.) ' . $GLOBALS['request'];
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_world] ' . $e->getMessage());
}
