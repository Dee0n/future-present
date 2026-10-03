<?php
/*
 * tes_crime warden, after every request:
 *  - a prisoner whose game day has passed is let out at the jail's street marker (own clothes back);
 *  - a prisoner who shows up next to the player outside a jail (another command or an AI package
 *    pulled him out) is put back - at most once a minute per prisoner.
 */

try {
    require_once __DIR__ . '/lib.php';
    if (isset($GLOBALS['db']) && function_exists('tesCrimeQueue')) {
        $db = $GLOBALS['db'];
        $tesCrimeHas = $db->fetchOne("SELECT to_regclass('public.tes_crime_jail') AS t");
        if (!empty($tesCrimeHas['t'])) {
            $tesCrimeNow = tesCrimeGamets();
            $rows = $db->fetchAll("SELECT * FROM public.tes_crime_jail WHERE status = 'jailed' ORDER BY id LIMIT 20");
            $near = null;
            foreach (is_array($rows) ? $rows : [] as $row) {
                if ($tesCrimeNow > 0 && intval($row['release_gamets']) > 0 && $tesCrimeNow >= intval($row['release_gamets'])) {
                    tesCrimeRelease($row, false);
                    tesCrimeNotify("{$row['npc']} отсидел срок и вышел из темницы");
                    error_log("[tes_crime] released {$row['npc']} (served)");
                    continue;
                }
                // a save from before the arrest was loaded: the game no longer has him in jail
                if ($tesCrimeNow > 0 && $tesCrimeNow < intval($row['jailed_gamets']) - 100000) {
                    $db->execQuery("UPDATE public.tes_crime_jail SET status = 'released' WHERE id = " . intval($row['id']));
                    continue;
                }
                if ($near === null) {
                    $n = $db->fetchOne("SELECT data FROM eventlog WHERE type = 'infonpc_close' AND localts > " . (time() - 30) . " ORDER BY rowid DESC LIMIT 1");
                    $l = $db->fetchOne("SELECT data FROM eventlog WHERE type IN ('infoloc', 'request') AND data LIKE '%Context location:%' ORDER BY rowid DESC LIMIT 1");
                    $inJail = (bool)preg_match('/подземель|тюрьм|темниц|казарм|холодн|сидна|кровав/iu', strval($l['data'] ?? ''));
                    $near = $inJail ? [] : array_map(fn($x) => trim(preg_replace('/\s*\((?:busy)\)\s*$/u', '', trim($x)) ?? ''), explode('/', strval($n['data'] ?? '')));
                }
                if (in_array(strval($row['npc']), $near, true)) {
                    $held = $db->fetchOne("SELECT 1 AS x FROM public.tes_crime_jail WHERE id = " . intval($row['id']) . " AND (last_hold IS NULL OR last_hold < now() - interval '60 seconds')");
                    if (!empty($held)) {
                        tesCrimeQueue(tesCrimeHoldCommands(strval($row['refid']), strval($row['inside_ref'])));
                        $db->execQuery("UPDATE public.tes_crime_jail SET last_hold = now() WHERE id = " . intval($row['id']));
                        error_log("[tes_crime] {$row['npc']} was out of jail - put back");
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_crime warden] ' . $e->getMessage());
}
