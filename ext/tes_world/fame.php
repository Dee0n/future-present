<?php
/*
 * tes_world: fame (roadmap E: "прозвища и репутация", "игрок сеет слухи голосом", "баллады о тебе").
 *  - "пусти слух, что …" / "пусть говорят, что …" - the ruler's own rumour is born in his hold (and travels, rumors.php);
 *  - a nickname from what the ruler has done (executions, mercy, gold given away, feasts, gods): "Шаман Кровавый",
 *    "Шаман Щедрый" … - every person knows it; when it changes the hold hears;
 *  - to a bard: "спой про меня / сложи балладу" - he sings of the ruler's real deeds (one line of material, his
 *    own words). No extra model call.
 */

if (!function_exists('tesFameTick')) {
    /** What the ruler has done, counted from the tables the plugins keep. */
    function tesFameDeeds(): array
    {
        $db = $GLOBALS['db'];
        $n = function (string $sql) use ($db): int {
            try {
                $r = $db->fetchOne($sql);
                return intval($r['n'] ?? 0);
            } catch (Throwable $e) {
                return 0;
            }
        };
        $has = fn(string $t) => !empty($db->fetchOne("SELECT to_regclass('public.{$t}') AS t")['t']);
        return [
            'cruel' => $n("SELECT count(*) AS n FROM public.tes_agent_tasks WHERE status = 'fast' AND goal ~ '^(kill|strip|beg|take): '")
                + ($has('tes_court') ? $n("SELECT count(*) AS n FROM public.tes_court WHERE verdict LIKE '%казн%'") * 2 : 0),
            'mercy' => ($has('tes_court') ? $n("SELECT count(*) AS n FROM public.tes_court WHERE verdict LIKE '%оправдан%'") * 2 : 0)
                + $n("SELECT count(*) AS n FROM public.tes_agent_tasks WHERE status = 'fast' AND goal ~ '^free: '"),
            'generous' => ($has('tes_treasury_log') ? $n("SELECT count(*) AS n FROM public.tes_treasury_log WHERE why LIKE 'выдано:%' OR why LIKE 'Игры:%'") : 0),
            'reveler' => ($has('tes_gatherings') ? $n("SELECT count(*) AS n FROM public.tes_gatherings WHERE party") * 3 : 0)
                + ($has('tes_sanguine_bets') ? $n("SELECT count(*) AS n FROM public.tes_sanguine_bets") : 0),
            'pious' => ($has('tes_legends') ? $n("SELECT count(*) AS n FROM public.tes_legends WHERE god IN ('Аркей', 'Кинарет', 'Мара')") : 0),
            'mad' => ($has('tes_legends') ? $n("SELECT count(*) AS n FROM public.tes_legends WHERE god = 'Шеогорат'") : 0),
        ];
    }

    /** The nickname the people give the ruler, or '' while nothing stands out. */
    function tesFameNick(array $d): string
    {
        $names = ['cruel' => 'Кровавый', 'mercy' => 'Милосердный', 'generous' => 'Щедрый', 'reveler' => 'Гуляка', 'pious' => 'Благочестивый', 'mad' => 'Безумный'];
        arsort($d);
        $top = array_key_first($d);
        $second = array_values($d)[1] ?? 0;
        // it must stand out: at least 5 deeds and more than the next one
        return ($top !== null && $d[$top] >= 5 && $d[$top] > $second) ? $names[$top] : '';
    }

    /** Every 10 minutes: the nickname follows the deeds; a new one is news. */
    function tesFameTick(): void
    {
        if (empty(tesWorldFacts()['player_title'])) {
            return;
        }
        $mark = sys_get_temp_dir() . '/tes_fame.ts';
        if (time() - intval(@file_get_contents($mark)) < 600) {
            return;
        }
        @file_put_contents($mark, strval(time()));
        tesWatchEnsure();
        $nick = tesFameNick(tesFameDeeds());
        $old = strval(tesWatchGet('player_nick')['value']);
        if ($nick !== '' && $nick !== $old) {
            tesWatchSet('player_nick', $nick);
            $player = strval($GLOBALS['PLAYER_NAME'] ?? 'ярл');
            tesWatchNotify("В народе тебя прозвали: {$player} {$nick}");
            if (tesWorldNeedGuard()) {
                tesGodGuardAddRumor("Говорят, ярла теперь зовут не иначе как {$player} {$nick}.");
            }
        }
    }

    /** One line for every person: how the people call the ruler. */
    function tesFameLine(string $me): string
    {
        if ($me === '' || empty(tesWorldFacts()['player_title'])) {
            return '';
        }
        $nick = strval(tesWatchGet('player_nick')['value']);
        if ($nick === '') {
            return '';
        }
        $why = ['Кровавый' => 'за казни и жестокость', 'Милосердный' => 'за помилования', 'Щедрый' => 'за щедрость из казны', 'Гуляка' => 'за пиры и пари',
            'Благочестивый' => 'за милость богов', 'Безумный' => 'за безумные чудеса'][$nick] ?? '';
        return 'В народе правителя зовут «' . strval($GLOBALS['PLAYER_NAME'] ?? '') . " {$nick}» {$why} — так о нём и думаешь, можешь так и назвать (за глаза или в лицо, по характеру).";
    }

    /** "пусти слух, что …", and "спой про меня" to a bard. Returns a note or ''. */
    function tesFameSpoken(string $line, string $to): string
    {
        $t = mb_strtolower(str_replace('ё', 'е', $line));
        if (preg_match('/(?:пусти|распусти|запусти|разнеси|пустите)\s+(?:\p{L}+\s+)?(?:слух|слухи|молву|сплетню)\s*,?\s*(?:что|будто|о\s+том,?\s+что)\s+(.{8,240})|пусть\s+(?:все\s+)?говорят,?\s+(?:что|будто)\s+(.{8,240})/u', $t, $m)) {
            $text = trim($m[1] !== '' ? $m[1] : ($m[2] ?? ''), " .!");
            if (!tesWorldNeedGuard() || $text === '') {
                return '';
            }
            // the ruler's own words, not lower-cased: cut the same length from the original line
            $pos = mb_strpos($t, $text);
            $orig = $pos !== false ? mb_substr($line, $pos, mb_strlen($text)) : $text;
            $hold = tesGodGuardAddRumor('Говорят, будто ' . trim($orig, " .!") . '.');
            $GLOBALS['TES_COURT_NOW'] = true;
            return " *слух пущен по холду {$hold}: «" . mb_substr($orig, 0, 100) . "» — скоро о нём заговорят; подтверди коротко*";
        }
        if ($to !== '' && stripos($to, 'Narrator') === false && preg_match('/(?<![\p{L}])(спой|сыграй|сложи|сочини|исполни)\p{L}*\s+(?:\p{L}+\s+){0,3}?(?:про\s+меня|обо\s+мне|балладу|песню|песнь|оду)/u', $t)) {
            $db = $GLOBALS['db'];
            $occ = $db->fetchOne("SELECT coalesce(occupation::text, '') AS o FROM public.core_npc_master WHERE npc_name = '" . $db->escape($to) . "' LIMIT 1");
            if (!preg_match('/(бард|bard|менестрел|певец|певиц|скальд|singer|minstrel)/iu', strval($occ['o'] ?? ''))) {
                return '';
            }
            $facts = [];
            $nick = strval(tesWatchGet('player_nick')['value']);
            if ($nick !== '') {
                $facts[] = 'народ зовёт его ' . $nick;
            }
            $has = fn(string $tb) => !empty($db->fetchOne("SELECT to_regclass('public.{$tb}') AS t")['t']);
            if ($has('tes_court')) {
                tesTreasuryEnsure();  // the verdict column
                foreach ((array)$db->fetchAll("SELECT defendant, verdict FROM public.tes_court WHERE closed AND verdict <> '' ORDER BY id DESC LIMIT 2") as $c) {
                    if (is_array($c)) {
                        $facts[] = "на суде {$c['defendant']} {$c['verdict']}";
                    }
                }
            }
            if ($has('tes_legends')) {
                foreach ((array)$db->fetchAll("SELECT god, deed FROM public.tes_legends ORDER BY id DESC LIMIT 2") as $l) {
                    if (is_array($l)) {
                        $facts[] = "{$l['god']} {$l['deed']}";
                    }
                }
            }
            $d = tesFameDeeds();
            if ($d['reveler'] > 0) {
                $facts[] = 'закатывал пиры';
            }
            // the fact is a sentence ("Шаман — Ярл Вайтрана. Это признано…"): only the title itself
            $fact = strval(tesWorldFacts()['player_title'] ?? '');
            $title = preg_match('/—\s*([^.]{3,40})\./u', $fact, $tm) ? trim($tm[1]) : 'правитель';
            return ' *спой о ' . strval($GLOBALS['PLAYER_NAME'] ?? 'правителе') . " ({$title}) короткую балладу — четыре-шесть строк, в рифму, с припевом, по правде"
                . ($facts ? ': ' . implode('; ', array_slice($facts, 0, 6)) : '') . '; можно с лестью или с издёвкой — в твоём характере*';
        }
        return '';
    }
}
