<?php
/*
 * tes_world: companions with a will of their own (roadmap stage F).
 *
 * Who travels with the ruler is what CHIM writes into speech.companions ("|Назим|Шаман|"). Each companion keeps:
 *  - trust in the ruler (0-100, starts at 55) that the ruler's deeds move - by the companion's own character:
 *    a decent one hates executions and stripping, a cruel or greedy one likes them, a plain one is in between;
 *  - grudges: what hurt him, remembered and brought up in talk;
 *  - a personal goal (his CHIM "goals", else one fitting him) - now and then he asks the ruler to help with it;
 *  - below 15 he leaves the ruler (tesunfollow), and the hold hears of it.
 * His prompt gets one line with all that; the deeds are read from the tables tes_world already keeps
 * (quick orders, court sentences, feasts). One model call at most per companion in 40 minutes (the request).
 */

if (!function_exists('tesCompanionTick')) {
    function tesCompanionEnsure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $GLOBALS['db']->execQuery("CREATE TABLE IF NOT EXISTS public.tes_companion (npc text PRIMARY KEY, trust int NOT NULL DEFAULT 55, grudges text NOT NULL DEFAULT '',
            goal text NOT NULL DEFAULT '', task_mark bigint NOT NULL DEFAULT 0, court_mark bigint NOT NULL DEFAULT 0, left_at timestamptz,
            asked_at timestamptz, updated_at timestamptz NOT NULL DEFAULT now())");
    }

    /** The ruler's companions now (the last 10 minutes of talk), grown people only. */
    function tesCompanionList(): array
    {
        $db = $GLOBALS['db'];
        $r = $db->fetchOne("SELECT companions FROM speech WHERE companions IS NOT NULL AND companions <> '' AND localts > " . (time() - 600) . " ORDER BY rowid DESC LIMIT 1");
        $player = trim(strval($GLOBALS['PLAYER_NAME'] ?? ''));
        $out = [];
        foreach (explode('|', strval($r['companions'] ?? '')) as $n) {
            $n = trim($n);
            if ($n === '' || $n === $player || stripos($n, 'Narrator') !== false || preg_match('/Курьер/u', $n)) {
                continue;
            }
            $ref = function_exists('tesWorldRefOf') ? tesWorldRefOf($n) : '';
            if ($ref !== '' && tesChildSafeIsChildRef($ref)) {
                continue;
            }
            $out[] = $n;
        }
        return array_values(array_unique($out));
    }

    /** decent / cruel / plain - from his personality and biography. */
    function tesCompanionNature(string $npc): string
    {
        $db = $GLOBALS['db'];
        $r = $db->fetchOne("SELECT coalesce(personality::text, '') || ' ' || coalesce(npc_static_bio, '') || ' ' || coalesce(occupation::text, '') AS t FROM public.core_npc_master WHERE npc_name = '" . $db->escape($npc) . "' LIMIT 1");
        $t = mb_strtolower(strval($r['t'] ?? ''));
        $cruel = preg_match_all('/(жесток|кровож|наемн|наёмн|вор(?![оa])|бандит|циничн|алчн|жадн|беспощад|убийц|разбойн|контрабанд|ruthless|cruel|greedy|thief|mercenar)/u', $t);
        $decent = preg_match_all('/(честн|благород|добр|милосерд|справедлив|набожн|жриц|жрец|кинарет|сострада|верн(?:ый|ая)\s+долгу|honest|noble|kind|merciful|pious|compassion)/u', $t);
        return $cruel > $decent ? 'cruel' : ($decent > $cruel ? 'decent' : 'plain');
    }

    /** His goal: CHIM's "goals" when it has one, else one fitting a traveller. */
    function tesCompanionGoal(string $npc): string
    {
        $db = $GLOBALS['db'];
        $r = $db->fetchOne("SELECT coalesce(goals::text, '') AS g FROM public.core_npc_master WHERE npc_name = '" . $db->escape($npc) . "' LIMIT 1");
        $g = trim(preg_replace('/[\[\]{}"]+/u', ' ', strval($r['g'] ?? '')) ?? '');
        // CHIM keeps goals as a "* one * two" list: the first one is his goal
        $first = array_values(array_filter(array_map('trim', preg_split('/(?:^|\s)[*•\-]\s+|\n+/u', $g) ?: [])));
        $g = $first[0] ?? $g;
        if (mb_strlen($g) >= 12) {
            return mb_substr(preg_replace('/\s+/u', ' ', $g) ?? $g, 0, 160);
        }
        $pool = ['скопить на собственный дом в Вайтране', 'найти родича, пропавшего на войне', 'отомстить разбойникам, что ограбили его когда-то',
            'добыть хорошее оружие и прославиться в бою', 'увидеть Высокий Хротгар и Седобородых', 'вернуть долг старому другу в Рифтене',
            'найти редкую книгу о двемерах', 'получить место при дворе ярла'];
        return $pool[crc32($npc) % count($pool)];
    }

    /** What a deed does to the trust of each nature: [decent, cruel, plain]. */
    function tesCompanionDeedWeight(string $kind): array
    {
        return [
            'kill' => [-8, 3, -3], 'strip' => [-6, 1, -3], 'jail' => [-3, 1, -1], 'beg' => [-3, 1, -1], 'take' => [-4, 3, -1],
            'free' => [4, -2, 1], 'fine' => [0, 1, 0], 'feast' => [1, 3, 2],
        ][$kind] ?? [0, 0, 0];
    }

    /** Every 2 minutes: the ruler's new deeds move the companions; who has had enough leaves. */
    function tesCompanionTick(): void
    {
        $mark = sys_get_temp_dir() . '/tes_companion.ts';
        if (time() - intval(@file_get_contents($mark)) < 120) {
            return;
        }
        @file_put_contents($mark, strval(time()));
        $list = tesCompanionList();
        if (!$list) {
            return;
        }
        tesCompanionEnsure();
        $db = $GLOBALS['db'];
        $maxTask = $db->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_agent_tasks");
        $hasCourt = $db->fetchOne("SELECT to_regclass('public.tes_court') AS t");
        $maxCourt = !empty($hasCourt['t']) ? $db->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_court") : ['m' => 0];
        $feast = $db->fetchOne("SELECT 1 AS x FROM public.tes_gatherings WHERE party AND NOT released AND created_at > now() - interval '144 minutes' LIMIT 1");
        foreach ($list as $npc) {
            $row = $db->fetchOne("SELECT * FROM public.tes_companion WHERE npc = '" . $db->escape($npc) . "'");
            if (empty($row)) {
                // a new companion starts from now: what was done before he joined is not his business
                $db->execQuery("INSERT INTO public.tes_companion (npc, goal, task_mark, court_mark) VALUES ('" . $db->escape($npc) . "', '" . $db->escape(tesCompanionGoal($npc))
                    . "', " . intval($maxTask['m'] ?? 0) . ', ' . intval($maxCourt['m'] ?? 0) . ') ON CONFLICT DO NOTHING');
                continue;
            }
            if (!empty($row['left_at'])) {
                continue;
            }
            $nature = tesCompanionNature($npc);
            $col = ['decent' => 0, 'cruel' => 1, 'plain' => 2][$nature];
            $trust = intval($row['trust']);
            $grudges = array_values(array_filter(explode(' | ', strval($row['grudges']))));
            $deeds = $db->fetchAll("SELECT id, goal FROM public.tes_agent_tasks WHERE id > " . intval($row['task_mark']) . " AND status = 'fast' ORDER BY id LIMIT 20");
            foreach (is_array($deeds) ? $deeds : [] as $d) {
                if (!preg_match('/^(\w+): (.+)$/u', strval($d['goal']), $dm) || trim($dm[2]) === $npc) {
                    continue;  // done to himself: tes_loyalty (fear/anger) already carries that
                }
                $w = tesCompanionDeedWeight($dm[1])[$col];
                $trust += $w;
                if ($w <= -3) {
                    $grudges[] = ['kill' => 'казнь', 'strip' => 'раздели', 'jail' => 'в темницу', 'beg' => 'выгнали побираться', 'take' => 'отобрали всё'][$dm[1]] . ': ' . trim($dm[2]);
                }
            }
            if (!empty($hasCourt['t'])) {
                tesTreasuryEnsure();  // the verdict column
                $cs = $db->fetchAll("SELECT id, defendant, coalesce(verdict, '') AS verdict FROM public.tes_court WHERE id > " . intval($row['court_mark']) . " AND closed ORDER BY id LIMIT 10");
                foreach (is_array($cs) ? $cs : [] as $c) {
                    $v = strval($c['verdict']);
                    $kind = mb_strpos($v, 'казн') !== false ? 'kill' : (mb_strpos($v, 'оправдан') !== false ? 'free' : (mb_strpos($v, 'штраф') !== false ? 'fine' : (mb_strpos($v, 'темниц') !== false ? 'jail' : '')));
                    $trust += $kind !== '' ? intdiv(tesCompanionDeedWeight($kind)[$col], 2) : 0;  // a trial is fairer than a bare order
                }
            }
            if (!empty($feast)) {
                $trust += tesCompanionDeedWeight('feast')[$col] > 0 ? 1 : 0;
            }
            $trust = max(0, min(100, $trust));
            $grudges = array_slice(array_values(array_unique($grudges)), -5);
            $db->execQuery("UPDATE public.tes_companion SET trust = {$trust}, grudges = '" . $db->escape(implode(' | ', $grudges)) . "', task_mark = " . intval($maxTask['m'] ?? 0)
                . ', court_mark = ' . intval($maxCourt['m'] ?? 0) . ", updated_at = now() WHERE npc = '" . $db->escape($npc) . "'");
            if ($trust < 15) {
                // he has had enough: leaves the ruler
                $ref = tesWorldRefOf($npc);
                if ($ref !== '') {
                    tesWorldQueue(['prid ' . $ref, 'tesunfollow', 'tesfollow 0']);
                }
                $db->execQuery("UPDATE public.tes_companion SET left_at = now() WHERE npc = '" . $db->escape($npc) . "'");
                tesWatchNotify("{$npc} больше не идёт с тобой: доверие исчерпано");
                if (function_exists('tesWorldLetter')) {
                    tesWorldLetter($npc, 'Прощай', 'Я ушёл. ' . ($grudges ? 'Не смог забыть: ' . implode('; ', array_slice($grudges, -2)) . '. ' : '') . 'Если когда-нибудь захочешь всё исправить — ты знаешь, где меня искать.', 20);
                }
                if (function_exists('tesWorldNeedGuard') && tesWorldNeedGuard()) {
                    tesGodGuardAddRumor("Говорят, {$npc} ушёл от " . strval($GLOBALS['PLAYER_NAME'] ?? 'ярла') . ($grudges ? ' — не простил: ' . end($grudges) : '') . '.');
                }
                if (function_exists('tesFestSay')) {
                    tesFestSay($npc, 'You can no longer travel with the ruler' . ($grudges ? ' after what happened (' . implode('; ', array_slice($grudges, -2)) . ')' : '') . '. Tell him so to his face and leave.', 1);
                }
                continue;
            }
            // now and then he asks for help with his goal (one model call per 40 minutes at most)
            $asked = empty($row['asked_at']) ? 99999 : time() - strtotime(strval($row['asked_at']));
            if ($trust >= 40 && $asked > 2400 && random_int(1, 100) <= 25 && function_exists('tesFestSay') && !(function_exists('tesWorldQueueBusy') && tesWorldQueueBusy())) {
                $db->execQuery("UPDATE public.tes_companion SET asked_at = now() WHERE npc = '" . $db->escape($npc) . "'");
                tesFestSay($npc, 'You have a goal of your own: ' . strval($row['goal']) . '. Briefly ask the ruler to help with it - in character, in one or two sentences.', 1);
            }
        }
    }

    /** One line for a companion's prompt: trust, grudges, goal. */
    function tesCompanionLine(string $me): string
    {
        if ($me === '' || stripos($me, 'Narrator') !== false) {
            return '';
        }
        $db = $GLOBALS['db'];
        $has = $db->fetchOne("SELECT to_regclass('public.tes_companion') AS t");
        if (empty($has['t'])) {
            return '';
        }
        $r = $db->fetchOne("SELECT trust, grudges, goal, left_at FROM public.tes_companion WHERE npc = '" . $db->escape($me) . "'");
        if (empty($r)) {
            return '';
        }
        if (!empty($r['left_at'])) {
            return 'You left the ruler because you no longer trust him' . (strval($r['grudges']) !== '' ? ' (' . strval($r['grudges']) . ')' : '') . '. You return only if he sincerely makes amends.';
        }
        $t = intval($r['trust']);
        $level = $t >= 75 ? 'high - you are devoted to him' : ($t >= 45 ? 'ordinary' : ($t >= 25 ? 'low - you doubt him and argue' : 'almost gone - a little more and you leave'));
        return 'You are the ruler\'s companion. Your trust in him: ' . $level . '.'
            . (strval($r['grudges']) !== '' ? ' What hurt you: ' . strval($r['grudges']) . ' - you may bring it up in passing.' : '')
            . ' Your goal: ' . strval($r['goal']) . '. If you disagree with the ruler - say so in character.';
    }
}
