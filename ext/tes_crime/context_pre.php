<?php
/*
 * tes_crime: a guard / commander / housecarl / steward / jarl who is being told to jail or fine
 * someone is pointed at the right action, in the system block and in the last message.
 * Live 2026-10-04 02:01-02:06: Вонгвилд answered "Так точно, ярл!" four times and only walked
 * to Хеймскр (Move_To), then used Arrest_<player> on him - which arrests the player.
 */

try {
    $tesCrimeSpeaker = strval($GLOBALS['HERIKA_NAME'] ?? '');
    if ($tesCrimeSpeaker !== '' && $tesCrimeSpeaker !== 'The Narrator' && function_exists('chimRegisterPromptInjection')) {
        require_once __DIR__ . '/functions.php';
        if (tesCrimeIsJailed($tesCrimeSpeaker)) {
            $tesCrimeCell = 'You are under arrest and locked in a jail cell: you wear prison clothes, your things are taken, the door is locked. You go nowhere and follow no one - you only talk. You get out only when the ruler frees you or the term ends.';
            chimRegisterPromptInjection('prompt_bottom', 'tes_crime_cell', $tesCrimeCell, 97);
            if (isset($GLOBALS['request']) && is_string($GLOBALS['request'])) {
                $GLOBALS['request'] = '(' . $tesCrimeCell . ') ' . $GLOBALS['request'];
            }
        }
        $tesCrimeSaid = mb_strtolower(strval($GLOBALS['gameRequest'][3] ?? ''));
        if (tesCrimeIsAuthority($tesCrimeSpeaker) && preg_match('/сади|сажа|арест|темниц|тюрьм|тюрьгу|за реш[её]тк|штраф|оштраф|накаж/u', $tesCrimeSaid)) {
            $tesCrimePlayer = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
            $tesCrimeLine = "You are told to jail or fine SOMEONE ELSE. It is done with one action, at once, no walking: "
                . "Arrest_Person (target: name of the one you jail; item: number of days) or Fine_Person (target: name; item: sum in gold). "
                . "The action Arrest_{$tesCrimePlayer} arrests {$tesCrimePlayer} HIMSELF - do NOT use it for others. Move_To and Travel_To are not needed here.";
            chimRegisterPromptInjection('prompt_bottom', 'tes_crime', $tesCrimeLine, 96);
            if (isset($GLOBALS['request']) && is_string($GLOBALS['request'])) {
                $GLOBALS['request'] = '(' . $tesCrimeLine . ') ' . $GLOBALS['request'];
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_crime context_pre] ' . $e->getMessage());
}
