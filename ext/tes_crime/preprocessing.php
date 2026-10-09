<?php
/*
 * tes_crime: the game's answer to "how much gold does the fined NPC carry"
 * ("getitemcount 0000000F@@GetItemCount >> 15.00", a tes_god_console request; this file runs
 * before ext/tes_god_console, which stores the line and ends the request).
 * Enough gold -> the fine is taken. Not enough -> jail.
 */

if (strtolower(strval($GLOBALS['gameRequest'][0] ?? '')) === 'tes_god_console') {
    try {
        $tesCrimeMsg = implode('|', array_slice($GLOBALS['gameRequest'], 3));
        if (stripos($tesCrimeMsg, 'getitemcount 0000000F@@') === 0 && isset($GLOBALS['db'])) {
            require_once __DIR__ . '/lib.php';
            tesCrimeEnsureTable();
            $db = $GLOBALS['db'];
            $fine = $db->fetchOne("SELECT * FROM public.tes_crime_fines WHERE status = 'asked' AND created_at > now() - interval '2 minutes' ORDER BY id LIMIT 1");
            if (!empty($fine['id']) && preg_match('/>>\s*(-?\d+(?:\.\d+)?)/', $tesCrimeMsg, $gm)) {
                $gold = intval(floatval($gm[1]));
                $amount = intval($fine['amount']);
                $npc = strval($fine['npc']);
                $ref = strval($fine['refid']);
                $guard = strval($fine['guard']);
                if ($gold >= $amount) {
                    tesCrimeQueue(['prid ' . $ref, 'removeitem 0000000F ' . $amount]);
                    $db->execQuery("UPDATE public.tes_crime_fines SET status = 'paid', gold = {$gold} WHERE id = " . intval($fine['id']));
                    if (function_exists('tesTreasuryAdd')) {
                        tesTreasuryAdd($amount, 'штраф: ' . $npc);  // a paid fine goes to the treasury
                    }
                    tesCrimeNotify("{$npc} заплатил штраф {$amount} септимов");
                    tesCrimeTell($npc, "(A fine of {$amount} septims was just taken from you; you paid - there was no way out. One short line: anger, vexation or resignation.)");
                } else {
                    tesCrimeJail($npc, $ref, "не заплатил штраф {$amount} септимов (было {$gold})");
                    $db->execQuery("UPDATE public.tes_crime_fines SET status = 'jailed', gold = {$gold} WHERE id = " . intval($fine['id']));
                    tesCrimeNotify("{$npc} не смог заплатить {$amount} (при себе {$gold}) — в темницу");
                    if ($guard !== '') {
                        tesCrimeTell($guard, "({$npc} could not pay the fine of {$amount} septims - only {$gold} on him. He was taken to jail. Say so in one sentence.)");
                    }
                    // he is in a cell now: he says why when somebody talks to him next
                    tesCrimeTell($npc, "(You were fined {$amount} septims and had only {$gold} on you. You could not pay, and the guards put you in jail. One short line.)");
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[tes_crime] ' . $e->getMessage());
    }
}
