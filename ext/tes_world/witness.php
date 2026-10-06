<?php
/*
 * tes_world: witnesses (roadmap stage E, "свидетели").
 *
 * When a person (not a creature) dies, the game writes "X died" and often "A has killed/defeated B with W".
 * Those who were around at that second (the "beings in range" line the game sends: alive, not "far away") are
 * the witnesses: each remembers what he saw in his own memory block ([Помнит] in the bio - every prompt of his
 * carries it), and the hold gets a rumour naming the witnesses. What a witness does with it - tells, keeps
 * quiet, blackmails - is his character's business in the talk. No model call is made here.
 */

if (!function_exists('tesWitnessTick')) {
    /** A person of the world (in the NPC table, not a creature), by the name the game uses. */
    function tesWitnessPerson(string $name): array
    {
        $db = $GLOBALS['db'];
        $row = $db->fetchOne("SELECT id, npc_name, race FROM public.core_npc_master WHERE npc_name = '" . $db->escape(trim($name)) . "' LIMIT 1");
        return is_array($row) && !empty($row['id']) ? $row : [];
    }

    /** Names in a "(beings in range:A,B (dead),C (far away),…)" line who could see: alive and near. */
    function tesWitnessSeers(string $line): array
    {
        if (!preg_match('/beings in range:(.*)\)\s*$/us', $line, $m)) {
            return [];
        }
        $out = [];
        foreach (explode(',', $m[1]) as $part) {
            $p = trim($part);
            if ($p === '' || preg_match('/\((dead|far away|unconscious)\)/u', $p)) {
                continue;
            }
            $out[] = trim(preg_replace('/\s*\((hostile|ally|friend|neutral|in combat)\)\s*$/u', '', $p) ?? $p);
        }
        return array_values(array_unique($out));
    }

    /** Every minute: deaths since the last look -> witnesses remember, a rumour is born. */
    function tesWitnessTick(): void
    {
        $db = $GLOBALS['db'];
        $mark = sys_get_temp_dir() . '/tes_witness.ts';
        if (time() - intval(@file_get_contents($mark)) < 60) {
            return;
        }
        @file_put_contents($mark, strval(time()));
        tesWatchEnsure();
        $last = intval(tesWatchGet('witness_rowid')['value']);
        tesWorldNeedGuard();
        if ($last <= 0) {
            // the first run starts from now: old deaths are not dug up
            $r = $db->fetchOne("SELECT coalesce(max(rowid), 0) AS m FROM eventlog");
            tesWatchSet('witness_rowid', strval(intval($r['m'] ?? 0)));
            return;
        }
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_witness_seen (key text PRIMARY KEY, deed text NOT NULL DEFAULT '', created_at timestamptz NOT NULL DEFAULT now())");
        $rows = $db->fetchAll("SELECT rowid, localts, data FROM eventlog WHERE type = 'death' AND rowid > {$last} ORDER BY rowid LIMIT 40");
        $max = $last;
        $player = trim(strval($GLOBALS['PLAYER_NAME'] ?? ''));
        // pass 1: one story per victim ("X died" comes before "A has killed X", and each line twice)
        $deaths = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $max = max($max, intval($r['rowid']));
            $data = strval($r['data']);
            $text = trim(preg_replace('/^\([^)]*\)\s*/u', '', $data) ?? $data);
            $killer = '';
            $weapon = '';
            if (preg_match('/^(.+?) has (?:killed|defeated) (.+?)(?: with (.+))?$/u', $text, $km)) {
                [$killer, $victim, $weapon] = [trim($km[1]), trim($km[2]), trim(strval($km[3] ?? ''))];
            } elseif (preg_match('/^(.+?) died$/u', $text, $dm)) {
                $victim = trim($dm[1]);
            } else {
                continue;
            }
            $d = $deaths[$victim] ?? ['rowid' => intval($r['rowid']), 'localts' => intval($r['localts']), 'killer' => '', 'weapon' => '', 'place' => ''];
            if ($killer !== '' && $d['killer'] === '') {
                $d['killer'] = $killer;
                $d['weapon'] = $weapon;
            }
            if ($d['place'] === '' && preg_match('/Context location:\s*([^,]+?)\s*,\s*Hold:/u', $data, $lm)) {
                $d['place'] = trim($lm[1]);
            }
            $deaths[$victim] = $d;
        }
        // pass 2: witnesses remember, the hold hears
        foreach ($deaths as $victim => $d) {
            $person = tesWitnessPerson($victim);
            if (!$person) {
                continue;  // a rat, a goblin, a nameless bandit
            }
            $key = md5($victim . '|' . intdiv($d['localts'], 600));
            $done = $db->fetchOne("SELECT 1 AS x FROM public.tes_witness_seen WHERE key = '{$key}' LIMIT 1");
            if (!empty($done)) {
                continue;
            }
            $killer = $d['killer'];
            if ($killer === '') {
                // who was fighting him in the last minute
                $fight = $db->fetchOne("SELECT data FROM eventlog WHERE type = 'infoaction' AND rowid < {$d['rowid']} AND localts > " . ($d['localts'] - 60)
                    . " AND data LIKE '%engages combat with " . $db->escape($victim) . "' ORDER BY rowid DESC LIMIT 1");
                if (!empty($fight['data']) && preg_match('/(?:\)\s*)?([^)]+?) engages combat with /u', strval($fight['data']), $fm)) {
                    $killer = trim($fm[1]);
                }
            }
            // who saw it: the "beings in range" the game sent around that second
            $near = $db->fetchOne("SELECT data FROM eventlog WHERE data LIKE '(beings in range:%' AND localts BETWEEN " . ($d['localts'] - 8) . " AND " . ($d['localts'] + 2) . " ORDER BY rowid DESC LIMIT 1");
            $witnesses = [];
            foreach (tesWitnessSeers(strval($near['data'] ?? '')) as $w) {
                if ($w === $victim || $w === $killer || ($player !== '' && $w === $player) || count($witnesses) >= 6) {
                    continue;
                }
                $wp = tesWitnessPerson($w);
                if ($wp) {
                    $witnesses[$w] = $wp;
                }
            }
            $place = $d['place'] !== '' ? " ({$d['place']})" : '';
            $deed = $killer !== '' ? "{$killer} убил {$victim}" . ($d['weapon'] !== '' ? " ({$d['weapon']})" : '') : "{$victim} погиб";
            foreach ($witnesses as $w => $wp) {
                if (function_exists('tesGodGuardRemember')) {
                    $isChild = (bool)preg_match('/реб[её]нок|child/iu', strval($wp['race'] ?? ''));
                    tesGodGuardRemember(intval($wp['id']), ($isChild ? 'Видел и испугался: ' : 'Видел своими глазами: ') . $deed . $place . '.');
                }
            }
            // a rumour of the hold: only a death that is a story - someone killed someone, or there were witnesses
            if (function_exists('tesGodGuardAddRumor') && ($killer !== '' || $witnesses)) {
                $names = array_slice(array_keys($witnesses), 0, 3);
                tesGodGuardAddRumor('Говорят, ' . $deed . $place . ($names ? '; это видели ' . implode(', ', $names) : '') . '.');
            }
            $db->execQuery("INSERT INTO public.tes_witness_seen (key, deed) VALUES ('{$key}', '" . $db->escape(mb_substr($deed, 0, 200)) . "') ON CONFLICT (key) DO NOTHING");
            error_log("[tes_world witness] {$deed}{$place}; witnesses: " . implode(', ', array_keys($witnesses)));
        }
        tesWatchSet('witness_rowid', strval($max));
    }
}
