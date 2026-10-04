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
            $tesCrimeCell = 'Ты арестован и заперт в камере темницы: на тебе тюремная одежда, вещи отобраны, дверь заперта. Ты никуда не идёшь и ни за кем не следуешь — только говоришь. Выйти можно, лишь когда отпустит правитель или выйдет срок.';
            chimRegisterPromptInjection('prompt_bottom', 'tes_crime_cell', $tesCrimeCell, 97);
            if (isset($GLOBALS['request']) && is_string($GLOBALS['request'])) {
                $GLOBALS['request'] = '(' . $tesCrimeCell . ') ' . $GLOBALS['request'];
            }
        }
        $tesCrimeSaid = mb_strtolower(strval($GLOBALS['gameRequest'][3] ?? ''));
        if (tesCrimeIsAuthority($tesCrimeSpeaker) && preg_match('/сади|сажа|арест|темниц|тюрьм|тюрьгу|за реш[её]тк|штраф|оштраф|накаж/u', $tesCrimeSaid)) {
            $tesCrimePlayer = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
            $tesCrimeLine = "Тебе велят посадить или оштрафовать КОГО-ТО ДРУГОГО. Делается это одним действием, сразу, без хождения: "
                . "Arrest_Person (target: имя того, кого сажаешь; item: число суток) или Fine_Person (target: имя; item: сумма золотом). "
                . "Действие Arrest_{$tesCrimePlayer} арестовывает САМОГО {$tesCrimePlayer} — для других его НЕ используй. Move_To и Travel_To тут не нужны.";
            chimRegisterPromptInjection('prompt_bottom', 'tes_crime', $tesCrimeLine, 96);
            if (isset($GLOBALS['request']) && is_string($GLOBALS['request'])) {
                $GLOBALS['request'] = '(' . $tesCrimeLine . ') ' . $GLOBALS['request'];
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_crime context_pre] ' . $e->getMessage());
}
