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
            tesCrimeEnsureJailTable();
            tesCrimeEscortTick();
            $tesCrimeNow = tesCrimeGamets();
            $rows = $db->fetchAll("SELECT * FROM public.tes_crime_jail WHERE status = 'jailed' ORDER BY id LIMIT 20");
            $near = null;
            foreach (is_array($rows) ? $rows : [] as $row) {
                // a sentence is counted in REAL time (a game day = 72 real minutes at timescale 20): the Narrator's
                // "set gamehour" jumps served whole sentences in minutes and everybody "escaped"
                $db->execQuery("ALTER TABLE public.tes_crime_jail ADD COLUMN IF NOT EXISTS release_at timestamptz");
                $realDue = $db->fetchOne("SELECT release_at IS NOT NULL AS has, release_at <= now() AS due FROM public.tes_crime_jail WHERE id = " . intval($row['id']));
                $served = !empty($realDue['has']) ? ($realDue['due'] === 't' || $realDue['due'] === true)
                    : ($tesCrimeNow > 0 && intval($row['release_gamets']) > 0 && $tesCrimeNow >= intval($row['release_gamets']));
                if ($served) {
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
                    $near = $inJail ? [] : array_map(fn($x) => trim(preg_replace('/(\s*\((?:busy|restrained|far away|sleeping|sitting|[a-z ]+)\))+\s*$/u', '', trim($x)) ?? ''), explode('/', strval($n['data'] ?? '')));
                }
                if (strval($row['stage'] ?? 'in') !== 'in') {
                    continue;
                }
                $state = $db->fetchOne("SELECT metadata->'activity_status'->>'is_dead' AS d FROM public.core_npc_master WHERE upper(refid) = '" . $db->escape(strtoupper(strval($row['refid']))) . "' LIMIT 1");
                if (strval($state['d'] ?? '') === 'true') {
                    $db->execQuery("UPDATE public.tes_crime_jail SET status = 'released' WHERE id = " . intval($row['id']));
                    continue;
                }
                // not only when he shows up next to the player: every prisoner is put back into
                // his cell every 2 minutes, wherever he has got to - also while the player is in
                // the jail himself (owner, 2026-10-04: "из решетки все сбегают… все кто в тюрьме
                // должен быть тпшни их в тюрьму")
                // Owner, 17:15: "они из клетки телепортируются и ходят на месте". The old warden sent every
                // prisoner to the marker every 45 s (a visible teleport inside the cell, with speedmult 0 they
                // "walked in place"). Now: ask the game how far he is from the cell; only a man who really
                // left (or sits in another cell/world) is put back. Probe, then judge in the next round.
                $db->execQuery("ALTER TABLE public.tes_crime_jail ADD COLUMN IF NOT EXISTS probe_at timestamptz, ADD COLUMN IF NOT EXISTS probe_id bigint NOT NULL DEFAULT 0");
                $pr = $db->fetchOne("SELECT probe_at IS NOT NULL AS has, extract(epoch FROM now() - probe_at)::int AS age, probe_id FROM public.tes_crime_jail WHERE id = " . intval($row['id']));
                if (!empty($pr['has']) && intval($pr['age']) >= 5 && intval($pr['age']) < 120) {
                    $out = null;
                    $seen = false;
                    foreach ($db->fetchAll("SELECT command, output FROM public.tes_god_console_log WHERE id > " . intval($pr['probe_id']) . " ORDER BY id LIMIT 60") ?: [] as $l) {
                        $c = strtolower(trim(strval($l['command'])));
                        if ($c === 'prid ' . strtolower(strval($row['refid']))) {
                            $seen = true;
                        } elseif ($seen && strpos($c, 'getdistance') === 0) {
                            $out = strval($l['output']);
                            break;
                        }
                    }
                    if ($out !== null) {
                        $db->execQuery("UPDATE public.tes_crime_jail SET probe_at = NULL, last_hold = now() WHERE id = " . intval($row['id']));
                        $far = !preg_match('/>>\s*(-?\d+(?:\.\d+)?)/', $out, $dm) || floatval($dm[1]) > 600.0;
                        if ($far) {
                            tesCrimeQueue(tesCrimeHoldCommands(strval($row['refid']), strval($row['inside_ref'])));
                            error_log("[tes_crime] {$row['npc']} was out of the cell ({$out}) - put back");
                        }
                        continue;
                    }
                }
                $stale = $db->fetchOne("SELECT 1 AS x FROM public.tes_crime_jail WHERE id = " . intval($row['id']) . " AND (last_hold IS NULL OR last_hold < now() - interval '40 seconds') AND (probe_at IS NULL OR probe_at < now() - interval '120 seconds')");
                if (!empty($stale)) {
                    $mx = $db->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_god_console_log");
                    tesCrimeQueue(['prid ' . strval($row['refid']), 'getdistance ' . strval($row['inside_ref'])]);
                    $db->execQuery("UPDATE public.tes_crime_jail SET probe_at = now(), probe_id = " . intval($mx['m'] ?? 0) . " WHERE id = " . intval($row['id']));
                    continue;
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
