<?php
/*
 * tes_player_action: what the player types in (parentheses) is something he DOES, not says.
 *
 * Live 2026-10-04 01:52-01:56: typed "(…)" lines reached the NPCs as a bare, unattributed
 * "# (text)" history line, and every NPC answered as if the player had SAID it ("таким словам
 * здесь не место", "он признаёт, что извергал эту мерзость"). Owner: "почему действие как
 * слова распознаёт".
 * The line is rewritten, before it is logged and shown to the model, into an attributed
 * action: "<Игрок>: *<действие>* [это действие …, не слова]". context_pre.php then tells the
 * answering NPC to react to the deed.
 */

try {
    $tesPaType = strtolower(strval($GLOBALS['gameRequest'][0] ?? ''));
    if (in_array($tesPaType, ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'], true)) {
        $tesPaRaw = strval($GLOBALS['gameRequest'][3] ?? '');
        if (preg_match('/^\s*\(\s*(.+?)\s*\)\s*(\(Talking to [^)]*\))?\s*$/su', $tesPaRaw, $tesPaM)
            && !preg_match('/^(?:Talking to|Context location|beings in range)/iu', $tesPaM[1])) {
            $tesPaPlayer = trim(strval($GLOBALS['PLAYER_NAME'] ?? ''));
            if ($tesPaPlayer === '' && isset($GLOBALS['db'])) {
                $tesPaRow = $GLOBALS['db']->fetchOne("SELECT value FROM public.core_player WHERE id = 'player_name'");
                $tesPaPlayer = trim(strval($tesPaRow['value'] ?? ''));
            }
            if ($tesPaPlayer !== '') {
                $tesPaAction = trim($tesPaM[1]);
                $GLOBALS['TES_PLAYER_ACTION'] = $tesPaAction;
                $GLOBALS['gameRequest'][3] = $tesPaPlayer . ': *' . $tesPaAction . '* [это ДЕЙСТВИЕ ' . $tesPaPlayer
                    . ' — он это делает на глазах у всех, а не произносит вслух]' . (isset($tesPaM[2]) && $tesPaM[2] !== '' ? ' ' . $tesPaM[2] : '');
                if (isset($gameRequest) && is_array($gameRequest)) {
                    $gameRequest[3] = $GLOBALS['gameRequest'][3];
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_player_action] ' . $e->getMessage());
}
