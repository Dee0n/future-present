<?php
/*
 * tes_player_action: the NPC answering a typed "(action)" is told it was a deed, not speech
 * (see preprocessing.php). Also repeated in the last message, where the model looks first.
 */

try {
    $tesPaDone = strval($GLOBALS['TES_PLAYER_ACTION'] ?? '');
    if ($tesPaDone !== '' && function_exists('chimRegisterPromptInjection')) {
        $tesPaWho = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
        $tesPaLine = "{$tesPaWho} сейчас НЕ ГОВОРИЛ, а СДЕЛАЛ: «{$tesPaDone}». Это поступок, случившийся у тебя на глазах (или с тобой). "
            . 'Реагируй на сам поступок и его последствия — телом, чувствами, действием и словами о том, что произошло; '
            . 'не отвечай так, будто он это только сказал («что ты несёшь», «таким словам тут не место»).';
        chimRegisterPromptInjection('prompt_bottom', 'tes_player_action', $tesPaLine, 97);
        if (isset($GLOBALS['request']) && is_string($GLOBALS['request'])) {
            $GLOBALS['request'] = '(' . $tesPaLine . ') ' . $GLOBALS['request'];
        }
    }
} catch (Throwable $e) {
    error_log('[tes_player_action context_pre] ' . $e->getMessage());
}
