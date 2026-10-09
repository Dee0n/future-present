<?php
/*
 * tes_player_action: the NPC answering a typed "(action)" is told it was a deed, not speech
 * (see preprocessing.php). Also repeated in the last message, where the model looks first.
 */

try {
    $tesPaDone = strval($GLOBALS['TES_PLAYER_ACTION'] ?? '');
    if ($tesPaDone !== '' && function_exists('chimRegisterPromptInjection')) {
        $tesPaWho = strval($GLOBALS['PLAYER_NAME'] ?? 'The player');
        $tesPaLine = "{$tesPaWho} just did NOT SPEAK but DID: «{$tesPaDone}». It is a deed that happened before your eyes (or to you). "
            . 'React to the deed itself and its consequences - with body, feelings, action and words about what happened; '
            . 'do not answer as if he only said it («что ты несёшь», «таким словам тут не место»).';
        chimRegisterPromptInjection('prompt_bottom', 'tes_player_action', $tesPaLine, 97);
        if (isset($GLOBALS['request']) && is_string($GLOBALS['request'])) {
            $GLOBALS['request'] = '(' . $tesPaLine . ') ' . $GLOBALS['request'];
        }
    }
} catch (Throwable $e) {
    error_log('[tes_player_action context_pre] ' . $e->getMessage());
}
