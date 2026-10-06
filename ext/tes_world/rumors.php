<?php
/*
 * tes_world: rumours travel (roadmap stage E, "молва с искажением по городам").
 *
 * A rumour is born in the hold where it happened (tesGodGuardAddRumor puts the current hold) and CHIM shows a
 * character only the rumours of his own hold. Here it travels on: 30 real minutes later it reaches the
 * neighbouring holds, 60 minutes later the holds next to those - each time told a little worse: who brought it,
 * numbers grown, words made stronger. No model is called (the key's budget is $2): the distortion is done by rules.
 * The copies are type 'Слух издалека' and never travel again.
 */

if (!function_exists('tesRumorSpreadTick')) {
    /** The holds of Skyrim as the Russian game names them, and who borders whom. */
    function tesRumorNeighbours(): array
    {
        return [
            'Вайтран' => ['Фолкрит', 'Хьялмарк', 'Белый Берег', 'Истмарк', 'Рифт', 'Предел'],
            'Хаафингар' => ['Хьялмарк', 'Предел'],
            'Хьялмарк' => ['Хаафингар', 'Предел', 'Вайтран', 'Белый Берег'],
            'Белый Берег' => ['Хьялмарк', 'Вайтран', 'Истмарк', 'Винтерхолд'],
            'Винтерхолд' => ['Белый Берег', 'Истмарк'],
            'Истмарк' => ['Винтерхолд', 'Белый Берег', 'Вайтран', 'Рифт'],
            'Рифт' => ['Истмарк', 'Вайтран', 'Фолкрит'],
            'Фолкрит' => ['Рифт', 'Вайтран', 'Предел'],
            'Предел' => ['Хаафингар', 'Хьялмарк', 'Вайтран', 'Фолкрит'],
        ];
    }

    /** The holds two steps away (not the hold itself, not its neighbours). */
    function tesRumorFarHolds(string $hold): array
    {
        $map = tesRumorNeighbours();
        $near = $map[$hold] ?? [];
        $far = [];
        foreach ($near as $n) {
            foreach ($map[$n] ?? [] as $f) {
                if ($f !== $hold && !in_array($f, $near, true)) {
                    $far[$f] = true;
                }
            }
        }
        return array_keys($far);
    }

    /**
     * The rumour as it is retold one hop further ($hop 1 = neighbours, 2 = farther). Deterministic for one
     * (text, hold, hop): the same rumour reaching the same hold reads the same.
     */
    function tesRumorDistort(string $text, string $from, int $hop, string $to = ''): string
    {
        $seed = crc32($text . '|' . $to . '|' . $hop);
        $pick = function (array $a, int $salt = 0) use ($seed) {
            return $a[($seed >> $salt) % count($a)];
        };
        $body = trim(preg_replace('/^(Говорят|Шепчутся|Слышно|Болтают|Поговаривают)(,)?\s*(что|будто)?\s*/u', '', trim($text)) ?? $text);
        $body = rtrim($body, " .!…");
        // numbers grow in the retelling: 500 -> 1000/1500/2000
        $body = preg_replace_callback('/\d[\d\s]{0,10}\d|\d/u', function ($m) use ($hop, $seed) {
            $n = intval(preg_replace('/\s+/u', '', $m[0]));
            if ($n < 3) {
                return $m[0];
            }
            $k = [2, 3, 4][($seed >> 3) % 3];
            return strval($n * ($hop >= 2 ? $k * 2 : $k));
        }, $body) ?? $body;
        // words get stronger
        $stronger = [
            '/(?<![\p{L}])убил(?![\p{L}])/u' => 'зарезал',
            '/(?<![\p{L}])убили(?![\p{L}])/u' => 'перебили',
            '/(?<![\p{L}])казнил(?![\p{L}])/u' => 'прилюдно казнил',
            '/(?<![\p{L}])казнён(?![\p{L}])/u' => 'казнён на площади',
            '/(?<![\p{L}])приговорён к казни(?![\p{L}])/u' => 'приговорён к казни и уже обезглавлен',
            '/(?<![\p{L}])посадил(?![\p{L}])/u' => 'бросил в темницу',
            '/(?<![\p{L}])раздел(?![\p{L}])/u' => 'раздел догола',
            '/(?<![\p{L}])недовольны(?![\p{L}])/u' => 'в ярости',
            '/(?<![\p{L}])пир(?![\p{L}])/u' => 'пир на весь город',
            '/(?<![\p{L}])оштрафован(?![\p{L}])/u' => 'разорён штрафом',
        ];
        foreach ($stronger as $re => $to2) {
            if ((($seed >> 5) + $hop) % 2 === 0 || $hop >= 2) {
                $body = preg_replace($re, $to2, $body, 1) ?? $body;
            }
        }
        $lead = $hop >= 2
            ? $pick(['Через десятые руки дошло, будто', 'Странники болтают, будто', 'Кто-то слышал от кого-то, что'])
            : $pick(["Купцы из края {$from} рассказывают, будто", "Дошли вести из края {$from}:", "Говорят, в краю {$from}"]);
        $tail = $hop >= 2
            ? $pick([' — но чего только не наболтают', ' — и это ещё не всё, что рассказывают', ''], 7)
            : $pick(['', ' — так говорят', ''], 7);
        $out = $lead . ' ' . $body . $tail . '.';
        return mb_substr(preg_replace('/\s+/u', ' ', $out) ?? $out, 0, 400);
    }

    /** Every 5 minutes: rumours of the holds that are old enough go one hop further. */
    function tesRumorSpreadTick(): void
    {
        $db = $GLOBALS['db'];
        $mark = sys_get_temp_dir() . '/tes_rumor_spread.ts';
        if (time() - intval(@file_get_contents($mark)) < 300) {
            return;
        }
        @file_put_contents($mark, strval(time()));
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_rumor_spread (src_id int NOT NULL, hold text NOT NULL, hop int NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (src_id, hold))");
        $map = tesRumorNeighbours();
        // our own rumours, born in a hold we know, 30 min - 6 h old (ts = real time of the insert)
        $rows = $db->fetchAll("SELECT id, hold, content, gamets, ts FROM public.rumors WHERE type = 'Local news' AND ts < " . (time() - 1800) . " AND ts > " . (time() - 6 * 3600) . " ORDER BY id DESC LIMIT 6");
        $made = 0;
        foreach (is_array($rows) ? $rows : [] as $r) {
            $from = trim(strval($r['hold']));
            if (!isset($map[$from])) {
                continue;
            }
            $age = time() - intval($r['ts']);
            $targets = [];
            foreach ($map[$from] as $h) {
                $targets[$h] = 1;
            }
            if ($age >= 3600) {
                foreach (tesRumorFarHolds($from) as $h) {
                    $targets[$h] = 2;
                }
            }
            foreach ($targets as $to => $hop) {
                $id = intval($r['id']);
                $done = $db->fetchOne("SELECT 1 AS x FROM public.tes_rumor_spread WHERE src_id = {$id} AND hold = '" . $db->escape($to) . "' LIMIT 1");
                if (!empty($done)) {
                    continue;
                }
                $db->execQuery("INSERT INTO public.tes_rumor_spread (src_id, hold, hop) VALUES ({$id}, '" . $db->escape($to) . "', {$hop}) ON CONFLICT DO NOTHING");
                $db->insert('rumors', [
                    'gamets' => intval($r['gamets']),
                    'ts' => time(),
                    'hold' => $to,
                    'content' => tesRumorDistort(strval($r['content']), $from, $hop, $to),
                    'type' => 'Слух издалека',
                    'rumor_length_days' => 7,
                ]);
                $made++;
            }
        }
        if ($made > 0) {
            error_log("[tes_world rumors] {$made} rumours went to other holds");
        }
    }
}
