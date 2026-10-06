<?php
/*
 * tes_world: the pantheon (roadmap stage G) - more gods after Sanguine (sanguine.php), each with his sphere, his
 * voice through the Narrator, his own favour (0-100) and what he really does in the game:
 *  - Аркей (life and death)   - heals the ruler; brings back the dead (not at once, and only grown people);
 *  - Кинарет (sky and nature) - the weather;
 *  - Мара (love)              - one person comes to love the ruler;
 *  - Хермеус Мора (knowledge) - tells a true secret of the world (a witness's memory, someone's goal) and teaches;
 *  - Клавикус Вайл (bargains) - gold now, twice as much taken back later: a deal with a debt;
 *  - Шеогорат (madness)       - a wonder of any kind (sheo.php).
 * What every god did goes into the book of legends (tes_legends): "что говорят легенды" reads it back.
 * Prayers cost favour when asked too often; a god who is not in the mood refuses. No extra model call.
 */

if (!function_exists('tesGodsSpoken')) {
    function tesGods(): array
    {
        return [
            'arkay' => ['name' => 'Аркей', 're' => 'арке[йяюе]\p{L}*', 'voice' => 'Отвечает Аркей, бог жизни и смерти: говори торжественно, сдержанно и строго, о круговороте жизни'],
            'kynareth' => ['name' => 'Кинарет', 're' => 'кинарет\p{L}*', 'voice' => 'Отвечает Кинарет, богиня неба и ветров: говори светло и певуче, о небе, ветре и дожде'],
            'mara' => ['name' => 'Мара', 're' => 'мар[аыеу](?![\p{L}])', 'voice' => 'Отвечает Мара, богиня любви: говори тепло и мягко, как мать'],
            'hermaeus' => ['name' => 'Хермеус Мора', 're' => 'хермеус\p{L}*(?:\s+мор\p{L}*)?|херм[еэ]ус\p{L}*', 'voice' => 'Отвечает Хермеус Мора, даэдрический принц знаний: говори зловеще, вкрадчиво, загадками, будто тысяча шёпотов'],
            'clavicus' => ['name' => 'Клавикус Вайл', 're' => 'клавикус\p{L}*(?:\s+вайл\p{L}*)?', 'voice' => 'Отвечает Клавикус Вайл, даэдрический принц сделок: говори как хитрый торгаш, льстиво, с мелким шрифтом в каждой фразе'],
            'sheogorath' => ['name' => 'Шеогорат', 're' => 'шеогорат\p{L}*|шеогорад\p{L}*', 'voice' => 'Отвечает Шеогорат, даэдрический принц безумия: говори безумно, прыгая с мысли на мысль, про сыр и бабочек'],
        ];
    }

    function tesGodFavor(string $god, int $delta = 0): int
    {
        tesWatchEnsure();
        $v = tesWatchGet('god_favor_' . $god)['value'];
        $f = $v === '' ? 50 : intval($v);
        if ($delta !== 0) {
            $f = max(0, min(100, $f + $delta));
            tesWatchSet('god_favor_' . $god, strval($f));
        }
        return $f;
    }

    /** The book of legends: what the gods did. */
    function tesLegend(string $god, string $deed): void
    {
        $db = $GLOBALS['db'];
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_legends (id serial PRIMARY KEY, god text NOT NULL, deed text NOT NULL, created_at timestamptz NOT NULL DEFAULT now())");
        $db->execQuery("INSERT INTO public.tes_legends (god, deed) VALUES ('" . $db->escape($god) . "', '" . $db->escape(mb_substr($deed, 0, 300)) . "')");
    }

    function tesLegendsText(int $n = 6): string
    {
        $db = $GLOBALS['db'];
        $has = $db->fetchOne("SELECT to_regclass('public.tes_legends') AS t");
        $rows = !empty($has['t']) ? $db->fetchAll("SELECT god, deed FROM public.tes_legends ORDER BY id DESC LIMIT {$n}") : [];
        $parts = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $parts[] = $r['god'] . ': ' . $r['deed'];
        }
        return $parts ? implode('; ', $parts) : 'в книге легенд пока пусто — боги ещё не вмешивались';
    }

    /** A named person near the ruler in the line (not the god's name, not the ruler). */
    function tesGodTarget(string $line): string
    {
        $player = tesWorldNorm(strval($GLOBALS['PLAYER_NAME'] ?? ''));
        $near = tesWorldNearbyNames(30);
        foreach (preg_split('/[^\p{L}\-]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) as $i => $w) {
            if (mb_strlen($w) < 3 || preg_match('/^(арке|кинарет|мар|хермеус|мора|клавикус|вайл|шеогорат|сангвин)/iu', $w)) {
                continue;
            }
            $hit = tesWorldHeardName($w, $near) ?: ($i > 0 ? tesWorldKnownName($w) : '');
            if ($hit !== '' && tesWorldNorm($hit) !== $player) {
                return $hit;
            }
        }
        return '';
    }

    /** The ruler prays to / speaks to a god. Returns a note for the line or ''. */
    function tesGodsSpoken(string $line, string $to): string
    {
        $t = mb_strtolower(str_replace('ё', 'е', $line));
        if (preg_match('/(что|какие)\s+(?:\p{L}+\s+){0,2}легенд\p{L}*|книг\p{L}*\s+легенд/u', $t)) {
            return ' *книга легенд: ' . tesLegendsText() . '; перескажи ярлу как сказитель*';
        }
        $god = '';
        foreach (tesGods() as $key => $g) {
            if (preg_match('/(?<![\p{L}])(?:' . $g['re'] . ')/u', $t)) {
                $god = $key;
                break;
            }
        }
        if ($god === '') {
            return '';
        }
        tesWorldNeedGuard();
        $G = tesGods()[$god];
        // asked too often: the favour drops; below 15 the god does not answer the prayer
        $askedAge = tesWatchGet('god_asked_' . $god)['age'];
        tesWatchSet('god_asked_' . $god, '1');
        if ($askedAge < 300) {
            tesGodFavor($god, -6);
        }
        $fav = tesGodFavor($god);
        $did = '';
        if ($fav < 15) {
            $did = 'ты не в духе и отказываешь ярлу — он слишком часто докучал тебе';
        } elseif ($god === 'arkay') {
            if (preg_match('/(?<![\p{L}])(воскрес\p{L}*|верни\s+(?:к\s+жизни|из\s+мертв)|оживи\p{L}*)/u', $t)) {
                $who = tesGodTarget($line);
                $ref = $who !== '' ? tesWorldRefOf($who) : '';
                $dead = $who !== '' ? $GLOBALS['db']->fetchOne("SELECT metadata->'activity_status'->>'is_dead' AS d FROM public.core_npc_master WHERE npc_name = '" . $GLOBALS['db']->escape($who) . "' LIMIT 1") : [];
                if ($ref === '' || tesChildSafeIsChildRef($ref)) {
                    $did = 'ярл не назвал, кого вернуть, — спроси имя';
                } elseif (strval($dead['d'] ?? '') !== 'true') {
                    $did = "{$who} и так жив — Аркей не трогает живых";  // resurrect on the living can reset them
                } else {
                    tesWorldQueue(['prid ' . $ref, 'resurrect']);
                    tesGodFavor($god, -15);  // death is not cheated for free
                    $did = "{$who} возвращён к жизни — но Аркей напомнит, что за всё платят";
                    tesLegend($G['name'], "вернул к жизни {$who} по молитве " . strval($GLOBALS['PLAYER_NAME'] ?? 'ярла'));
                }
            } else {
                tesWorldQueue(['player.restoreav health 10000', 'player.restoreav stamina 10000', 'player.restoreav magicka 10000']);
                tesGodFavor($god, -3);
                $did = 'раны ярла затянулись, силы вернулись';
                tesLegend($G['name'], 'исцелил ' . strval($GLOBALS['PLAYER_NAME'] ?? 'ярла'));
            }
        } elseif ($god === 'kynareth') {
            $map = ['0010A240' => 'ясн|солнц|солнечн|разгони|прояс', '000C821F' => 'дожд|ливень|полей', '000C8220' => 'гроз|гром|молни|шторм', '0004D7FB' => 'снег|снежн', '000C821E' => 'туман', '0010A243' => 'облак|пасмурн', '000C8221' => 'метел|буран|вьюг'];
            foreach ($map as $id => $words) {
                if (preg_match('/(' . $words . ')/u', $t)) {
                    tesWorldQueue(['fw ' . $id]);
                    tesGodFavor($god, -2);
                    $did = 'небо послушалось: погода переменилась';
                    tesLegend($G['name'], 'переменила небо по слову ' . strval($GLOBALS['PLAYER_NAME'] ?? 'ярла'));
                    break;
                }
            }
            if ($did === '') {
                $did = 'ярл не сказал, какое небо ему нужно — спроси: солнце, дождь, грозу, снег, туман?';
            }
        } elseif ($god === 'mara') {
            $who = tesGodTarget($line);
            $ref = $who !== '' ? tesWorldRefOf($who) : '';
            if ($ref === '' || tesChildSafeIsChildRef($ref)) {
                $did = $ref !== '' ? 'это ребёнок — Мара благословляет детей, а не венчает' : 'ярл не назвал, чьё сердце склонить, — спроси';
            } else {
                tesWorldQueue(['prid ' . $ref, 'setrelationshiprank player 4']);
                if (function_exists('tesGodGuardRemember')) {
                    $p = $GLOBALS['db']->fetchOne("SELECT id FROM public.core_npc_master WHERE npc_name = '" . $GLOBALS['db']->escape($who) . "' LIMIT 1");
                    if (!empty($p['id'])) {
                        tesGodGuardRemember(intval($p['id']), 'Мара благословила: ты полюбил(а) ' . strval($GLOBALS['PLAYER_NAME'] ?? 'правителя') . ' всем сердцем.');
                    }
                }
                tesGodFavor($god, -8);
                $did = "сердце {$who} теперь принадлежит ярлу";
                tesLegend($G['name'], "склонила сердце {$who} к " . strval($GLOBALS['PLAYER_NAME'] ?? 'ярлу'));
            }
        } elseif ($god === 'hermaeus') {
            $db = $GLOBALS['db'];
            $secret = '';
            $has = $db->fetchOne("SELECT to_regclass('public.tes_witness_seen') AS t");
            $r = !empty($has['t']) ? $db->fetchOne("SELECT deed FROM public.tes_witness_seen ORDER BY random() LIMIT 1") : [];
            if (!empty($r['deed'])) {
                $secret = 'было на самом деле: ' . $r['deed'];
            } else {
                $n = $db->fetchOne("SELECT npc_name, coalesce(goals::text, '') AS g FROM public.core_npc_master WHERE length(coalesce(goals::text, '')) > 20 AND race NOT ILIKE '%реб%' ORDER BY random() LIMIT 1");
                if (!empty($n['npc_name'])) {
                    $g = trim(preg_split('/(?:^|\s)[*•\-]\s+|\n+/u', trim(preg_replace('/[\[\]{}"]+/u', ' ', strval($n['g'])) ?? ''))[1] ?? strval($n['g']));
                    $secret = "тайное желание {$n['npc_name']}: " . mb_substr($g, 0, 140);
                }
            }
            $skills = ['Alchemy', 'Enchanting', 'Illusion', 'Conjuration', 'Destruction', 'Speechcraft', 'Lockpicking', 'Sneak'];
            $sk = $skills[random_int(0, count($skills) - 1)];
            tesWorldQueue(['player.modav ' . $sk . ' 3']);
            tesGodFavor($god, -4);
            $did = ($secret !== '' ? "ты открыл ярлу тайну ({$secret}) — скажи её загадкой, но так, чтобы понял; " : '') . 'и вложил в его голову знание (навык вырос)';
            tesLegend($G['name'], 'открыл тайну и одарил знанием ' . strval($GLOBALS['PLAYER_NAME'] ?? 'ярла'));
        } elseif ($god === 'clavicus') {
            $n = tesWorldSpokenAmount($line);
            $n = $n > 0 ? min($n, 20000) : 1000;
            $open = tesWatchGet('clavicus_debt')['value'];
            if ($open !== '' && intval($open) > 0) {
                $did = 'у ярла уже есть долг перед тобой — ' . intval($open) . ' септимов; напомни, что сделка в силе';
            } else {
                tesWorldQueue(['player.additem 0000000F ' . $n]);
                tesWatchSet('clavicus_debt', strval($n * 2));
                tesWatchSet('clavicus_due', strval(time() + 1200));
                tesGodFavor($god, 5);
                $did = "сделка заключена: ярл получил {$n} септимов сейчас, через двадцать минут ты заберёшь " . ($n * 2) . ' (из кошеля, а чего не хватит — из казны)';
                tesLegend($G['name'], "дал {$n} септимов в долг под двойную плату");
            }
        } elseif ($god === 'sheogorath' && function_exists('tesSheoWonder')) {
            $w = tesSheoWonder('', true);
            tesGodFavor($god, 3);
            $did = $w !== '' ? 'ты устроил: ' . $w : '';
            if ($w !== '') {
                tesLegend($G['name'], $w);
            }
        }
        if (stripos($to, 'Narrator') === false && $to !== '') {
            return ' *ярл воззвал к ' . $G['name'] . ($did !== '' ? '; что произошло: ' . $did : '') . '; отреагируй на это по-своему*';
        }
        return ' *' . $G['voice'] . '. Одна-две фразы.' . ($did !== '' ? ' Что уже произошло: ' . $did . '.' : '') . '*';
    }

    /** Clavicus collects his debt; the favours slowly return to the middle. */
    function tesGodsTick(): void
    {
        tesWatchEnsure();
        $debt = intval(tesWatchGet('clavicus_debt')['value']);
        $due = intval(tesWatchGet('clavicus_due')['value']);
        if ($debt > 0 && $due > 0 && time() >= $due) {
            tesWatchSet('clavicus_debt', '0');
            $gold = function_exists('tesWorldPlayerGold') ? tesWorldPlayerGold() : -1;
            $fromPurse = $gold < 0 ? $debt : min($gold, $debt);
            if ($fromPurse > 0) {
                tesWorldQueue(['player.removeitem 0000000F ' . $fromPurse]);
            }
            $rest = $debt - $fromPurse;
            if ($rest > 0 && function_exists('tesTreasuryAdd')) {
                tesTreasuryAdd(-$rest, 'долг Клавикусу Вайлу');
            }
            tesWatchNotify("Клавикус Вайл забрал долг: {$fromPurse} из кошеля" . ($rest > 0 ? ", {$rest} из казны" : ''));
            tesLegend('Клавикус Вайл', "взыскал долг {$debt} септимов");
        }
        // the gods wager on the ruler: Sanguine bets he throws a feast within the hour, Clavicus that he does not
        $bet = tesWatchGet('gods_bet');
        if ($bet['value'] === '' && tesWatchGet('gods_bet_next')['age'] >= 7200 && !empty(tesWorldFacts()['player_title'])) {
            tesWatchSet('gods_bet', '1');
            tesWatchSet('gods_bet_next', '1');
            tesWatchNotify('Сангвин и Клавикус Вайл поспорили: устроишь ли ты пир в ближайший час');
            tesLegend('Сангвин и Клавикус Вайл', 'поспорили, устроит ли ' . strval($GLOBALS['PLAYER_NAME'] ?? 'ярл') . ' пир в ближайший час');
        } elseif ($bet['value'] === '1' && $bet['age'] >= 3600) {
            $db = $GLOBALS['db'];
            $feast = $db->fetchOne("SELECT 1 AS x FROM public.tes_gatherings WHERE party AND created_at > now() - interval '65 minutes' LIMIT 1");
            tesWatchSet('gods_bet', '');
            $winner = !empty($feast) ? 'Сангвин' : 'Клавикус Вайл';
            if (function_exists('tesSanguineFavor')) {
                tesSanguineFavor(!empty($feast) ? 10 : -5);
            }
            tesGodFavor('clavicus', !empty($feast) ? -5 : 10);
            tesWatchNotify("Пари богов выиграл {$winner}");
            tesLegend($winner, 'выиграл пари богов о пире ' . strval($GLOBALS['PLAYER_NAME'] ?? 'ярла'));
            if (tesWorldNeedGuard()) {
                tesGodGuardAddRumor("Говорят, сами боги спорили о " . strval($GLOBALS['PLAYER_NAME'] ?? 'ярле') . ", и выиграл {$winner}.");
            }
        }
        // a person near the ruler prays aloud now and then (one model call in 40+ minutes)
        if (tesWatchGet('npc_prayer')['age'] >= 2400 && random_int(1, 100) <= 30 && function_exists('tesFestSay') && !(function_exists('tesWorldQueueBusy') && tesWorldQueueBusy())) {
            $people = function_exists('tesSheoPeople') ? tesSheoPeople() : [];
            if ($people) {
                tesWatchSet('npc_prayer', '1');
                $p = $people[0];
                $god = ['Маре', 'Аркею', 'Кинарет', 'Дибелле', 'Талосу', 'Зенитару'][random_int(0, 5)];
                tesFestSay(strval($p['name']), "Ты тихо молишься {$god} — о чём-то своём, что тебя сейчас тревожит. Скажи молитву вслух, одной-двумя фразами.", 1);
            }
        }
        if (tesWatchGet('god_favor_drift')['age'] >= 3600) {
            tesWatchSet('god_favor_drift', '1');
            foreach (array_keys(tesGods()) as $g) {
                $f = tesGodFavor($g);
                if ($f !== 50) {
                    tesGodFavor($g, $f < 50 ? 2 : -2);
                }
            }
        }
    }
}
