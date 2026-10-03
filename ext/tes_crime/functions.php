<?php
/*
 * tes_crime actions.
 *
 * 1. tesCrimeFine()/tesCrimeJail() for tes_god_guard's "{npc:X}.fine N" / ".jail" (lib.php).
 * 2. Arrest_Person / Fine_Person (core_action ArrestNPC / FineNPC, settings/crime.sql): a guard,
 *    commander, housecarl, steward or jarl jails or fines ANOTHER NPC.
 * 3. A safety net. Live 2026-10-04 02:01 and 02:06: the player, now jarl, told a guard to jail
 *    Хеймскр and Люсия; the only arrest action a guard had was Arrest_<player>, the model
 *    called it with target "Хеймскр" - and the game began arresting THE PLAYER. An ArrestPlayer /
 *    AddBounty aimed at an NPC is turned into that NPC's arrest / fine and never reaches the game.
 */

require_once __DIR__ . '/lib.php';

if (!function_exists('tesCrimeNumberNear')) {
    /** "сто суток", "на 3 дня", "100" in the player's line -> number, or 0. */
    function tesCrimeNumberNear(string $text, string $unitPattern): int
    {
        $words = ['один' => 1, 'одни' => 1, 'сутки' => 1, 'два' => 2, 'двое' => 2, 'три' => 3, 'трое' => 3, 'четыре' => 4, 'пять' => 5,
            'шесть' => 6, 'семь' => 7, 'восемь' => 8, 'девять' => 9, 'десять' => 10, 'двадцать' => 20, 'тридцать' => 30,
            'пятьдесят' => 50, 'сто' => 100, 'сотню' => 100, 'сотня' => 100, 'двести' => 200, 'триста' => 300, 'тысячу' => 1000, 'тысяча' => 1000];
        $text = mb_strtolower($text);
        if (preg_match('/(\d[\d\s]*)\s*(тысяч\w*\s*)?' . $unitPattern . '/u', $text, $m)) {
            return intval(preg_replace('/\s+/u', '', $m[1])) * (trim(strval($m[2] ?? '')) !== '' ? 1000 : 1);
        }
        foreach ($words as $w => $n) {
            if (preg_match('/' . $w . '\s+(тысяч\w*\s*)?' . $unitPattern . '/u', $text, $m)) {
                return $n * (trim(strval($m[1] ?? '')) !== '' ? 1000 : 1);
            }
        }
        return 0;
    }

    /** May this actor arrest or fine people? Guards, commanders, housecarls, stewards, jarls. */
    function tesCrimeIsAuthority(string $actor): bool
    {
        return (bool)preg_match('/стражник|командир|хускарл|ярл|управит|guard|housecarl|jarl|авениччи|айрилет|кай\b/iu', $actor);
    }
}

if (empty($GLOBALS['TES_CRIME_HOOK'])) {  // context_pre.php may include this file as well
$GLOBALS['TES_CRIME_HOOK'] = true;
$GLOBALS['action_post_process_fnct_ex'][] = function ($actions) {
    if (!is_array($actions) || !isset($GLOBALS['db'])) {
        return $actions;
    }
    $player = mb_strtolower(trim(strval($GLOBALS['PLAYER_NAME'] ?? '')));
    $said = strval($GLOBALS['gameRequest'][3] ?? '');
    foreach ($actions as $n => $action) {
        try {
            $parts = explode('|', strval($action));
            $call = explode('@', strval($parts[2] ?? ''));
            $code = function_exists('getFunctionCodeName') ? getFunctionCodeName($call[0]) : false;
            $code = $code ?: $call[0];
            if (!in_array($code, ['ArrestPlayer', 'AddBounty', 'ArrestNPC', 'FineNPC'], true)) {
                continue;
            }
            $raw = implode('@', array_slice($call, 1));
            $payload = json_decode($raw, true);
            $target = trim(is_array($payload) ? strval($payload['target'] ?? '') : $raw);
            $item = trim(is_array($payload) ? strval($payload['item'] ?? '') : '');
            $target = trim(preg_replace('/\s*\[refid:[^\]]*\]/iu', '', $target) ?? $target);
            $targetLc = mb_strtolower($target);
            // AddBounty's target is a crime type for the PLAYER ("Assault"...) - leave those alone
            $crimeTypes = ['assault', 'murder', 'theft', 'pickpocketing', 'trespassing', 'jailbreak', 'custom', ''];
            if (in_array($code, ['ArrestPlayer', 'AddBounty'], true)
                && ($targetLc === $player || in_array($targetLc, ['player', 'игрок'], true) || in_array($targetLc, $crimeTypes, true))) {
                continue;
            }
            $actor = trim(strval($parts[0] ?? ''));
            if (!class_exists('RelationshipManager') && is_readable('/var/www/html/HerikaServer/lib/relationship_manager.php')) {
                require_once '/var/www/html/HerikaServer/lib/relationship_manager.php';
            }
            $npc = function_exists('tesGodGuardResolveNpcLoose') && class_exists('RelationshipManager') ? tesGodGuardResolveNpcLoose($target) : null;
            unset($actions[$n]);  // from here on it never reaches the game as an arrest of the player
            if (!$npc || !preg_match('/^[0-9A-Fa-f]{8}$/', strval($npc['refid'] ?? ''))) {
                tesCrimeTell($actor, "(Ты не нашёл, кого арестовать: «{$target}» — такого здесь нет. Скажи это одной фразой.)");
                continue;
            }
            if (!tesCrimeIsAuthority($actor)) {
                tesCrimeTell($actor, '(У тебя нет власти арестовывать и штрафовать — это дело стражи и ярла. Скажи это одной фразой.)');
                continue;
            }
            $name = strval($npc['npc_name']);
            $fine = in_array($code, ['AddBounty', 'FineNPC'], true);
            // LOOP GUARD (live 2026-10-04 02:09-02:11: Люсия was "arrested" 11 times in 80 s).
            // The arrest sent the guard an instruction to report; his report carried the arrest
            // action again; that sent another instruction... Now: no instruction after an arrest
            // (the guard already said his line with the action), and a second arrest or fine of
            // the same person within 10 minutes is dropped silently.
            tesCrimeEnsureTable();
            tesCrimeEnsureJailTable();
            $refEsc = $GLOBALS['db']->escape(strtoupper(strval($npc['refid'])));
            $again = $fine
                ? $GLOBALS['db']->fetchOne("SELECT 1 AS x FROM public.tes_crime_fines WHERE refid = '{$refEsc}' AND created_at > now() - interval '10 minutes' LIMIT 1")
                : $GLOBALS['db']->fetchOne("SELECT 1 AS x FROM public.tes_crime_jail WHERE refid = '{$refEsc}' AND status = 'jailed' LIMIT 1");
            if (!empty($again)) {
                continue;
            }
            if ($fine) {
                $amount = intval(preg_replace('/\D+/', '', $item));
                if ($amount <= 0) {
                    $amount = tesCrimeNumberNear($said, '(?:септим|золот|монет|штраф)') ?: 1000;
                }
                [$ok, $msg] = tesCrimeFine($name, strval($npc['refid']), $amount);
            } else {
                $days = intval(preg_replace('/\D+/', '', $item));
                if ($days <= 0) {
                    $days = tesCrimeNumberNear($said, '(?:сут|дн|день|дня)') ?: 1;
                }
                [$ok, $msg] = tesCrimeJail($name, strval($npc['refid']), "арестован: {$actor}", max(1, min(365, $days)));
            }
            error_log("[tes_crime] {$actor}: {$code} -> {$name}: " . ($ok ? 'ok' : 'failed') . " - {$msg}");
        } catch (Throwable $e) {
            error_log('[tes_crime actions] ' . $e->getMessage());
        }
    }
    return $actions;
};
}
