<?php
/*
 * tes_world: the Games of the feast (owner, 23:27: "устрой какую-то интересную долгую движуху").
 * While a feast is going on, every ~7 minutes a new number of the programme starts: a drinking match, a fools' court,
 * a flyting, gold from the treasury, strongmen, a queen of the feast, a dungeon tale, a toast round, a whisper.
 * Each number = a notice to the ruler (what is going on and what is asked of him), two or three guests told what to
 * say (the Narrator's own "Instruction" channel), and something that really happens in the game (drink, gold, a
 * guest knocked off his feet, somebody brought before the ruler). Nobody is hurt: no duels here.
 * The steps of a number are spread in time through public.tes_fest_queue; a step is claimed by one request only.
 */

if (!function_exists('tesFestTick')) {
    function tesFestEnsure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = $GLOBALS['db'];
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_festival (id serial PRIMARY KEY, started_at timestamptz NOT NULL DEFAULT now(), next_at timestamptz NOT NULL DEFAULT now(), stage int NOT NULL DEFAULT 0, active boolean NOT NULL DEFAULT true, now_text text NOT NULL DEFAULT '')");
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_fest_queue (id serial PRIMARY KEY, due_at timestamptz NOT NULL, kind text NOT NULL, payload text NOT NULL, done boolean NOT NULL DEFAULT false)");
    }

    function tesFestPush(string $kind, string $payload, int $delay): void
    {
        $db = $GLOBALS['db'];
        $db->execQuery("INSERT INTO public.tes_fest_queue (due_at, kind, payload) VALUES (now() + interval '" . max(0, $delay) . " seconds', '" . $db->escape($kind) . "', '" . $db->escape($payload) . "')");
    }

    /** $name says something of his own about $task, $delay seconds from now. */
    function tesFestSay(string $name, string $task, int $delay = 0): void
    {
        $task = str_replace('@', ' ', $task);
        tesFestPush('say', "Instruction@{$name}@(Идут Игры на гулянке ярла. {$task} Скажи это одной-двумя короткими живыми фразами по-русски, в своём характере, вслух, без пересказа этих слов.)@0", $delay);
    }

    function tesFestCmd(array $commands, int $delay = 0): void
    {
        // nobody is teleported to the ruler for a number (owner: "почему все без конца ко мне тп")
        $commands = array_values(array_filter($commands, fn($c) => $c !== 'moveto player'));
        if (count($commands) < 2 && strpos(strval($commands[0] ?? 'prid'), 'prid') === 0) {
            return;
        }
        tesFestPush('cmd', json_encode(array_values($commands)), $delay);
    }

    function tesFestNote(string $text, int $delay = 0): void
    {
        tesFestPush('note', $text, $delay);
    }

    function tesFestDrink(string $ref, int $delay = 0): void
    {
        tesFestPush('drink', $ref, $delay);
    }

    /** The guests of the feast that is going on: [['name', 'ref', 'female', 'old'], …]; [] when there is none. */
    function tesFestGuests(): array
    {
        $db = $GLOBALS['db'];
        $g = $db->fetchOne("SELECT refs FROM public.tes_gatherings WHERE party AND NOT released AND refs <> '' AND created_at > now() - interval '" . TES_REALM_PARTY_MIN . " minutes' ORDER BY id DESC LIMIT 1");
        $refs = array_values(array_filter(explode(',', strval($g['refs'] ?? ''))));
        if (!$refs) {
            return [];
        }
        $rows = $db->fetchAll("SELECT npc_name, upper(refid) AS refid, gender, race FROM public.core_npc_master WHERE upper(refid) IN ('" . implode("','", array_map(fn($r) => $db->escape(strtoupper($r)), $refs)) . "')");
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $race = strval($r['race']);
            if (preg_match('/реб[её]нок|child/iu', $race) || (function_exists('tesCrimeIsJailed') && tesCrimeIsJailed(strval($r['npc_name'])))) {
                continue;
            }
            $out[strval($r['refid'])] = ['name' => strval($r['npc_name']), 'ref' => strval($r['refid']), 'female' => strtolower(strval($r['gender'])) === 'female',
                'old' => (bool)preg_match('/old|стар/iu', $race)];
        }
        $out = array_values($out);
        shuffle($out);
        return $out;
    }

    /** Gold out of the treasury into a pocket (nothing when the treasury cannot pay). */
    function tesFestPay(array $who, int $sum, string $why, int $delay = 0): bool
    {
        if (!function_exists('tesTreasuryBalance') || tesTreasuryBalance() < $sum) {
            return false;
        }
        tesTreasuryAdd(-$sum, $why);
        tesFestCmd(['prid ' . $who['ref'], 'additem 0000000F ' . $sum], $delay);
        return true;
    }

    function tesFestStart(): string
    {
        tesFestEnsure();
        $guests = tesFestGuests();
        if (count($guests) < 3) {
            return '';
        }
        $db = $GLOBALS['db'];
        $db->execQuery("UPDATE public.tes_festival SET active = false WHERE active");
        $db->execQuery("UPDATE public.tes_fest_queue SET done = true WHERE NOT done");
        $db->execQuery("INSERT INTO public.tes_festival (next_at) VALUES (now())");
        return ' *объявлены Игры: всю гулянку каждые несколько минут — новая потеха (состязания, суд дураков, перебранки, золото из казны); это уже началось, объяви это*';
    }

    function tesFestStop(): string
    {
        tesFestEnsure();
        $db = $GLOBALS['db'];
        $was = $db->fetchOne("SELECT 1 AS x FROM public.tes_festival WHERE active LIMIT 1");
        $db->execQuery("UPDATE public.tes_festival SET active = false, now_text = '' WHERE active");
        $db->execQuery("UPDATE public.tes_fest_queue SET done = true WHERE NOT done");
        return empty($was) ? '' : ' *Игры окончены по слову ярла; гулянка продолжается без них*';
    }

    /** The ruler's words: "устрой игры / движуху / потеху", "хватит игр". */
    function tesFestSpoken(string $t): string
    {
        $what = '(?:игр\p{L}*|движух\p{L}*|состязани\p{L}*|потех\p{L}*|турнир\p{L}*)';
        if (preg_match('/(?<![\p{L}])(?:хватит|довольно|законч\p{L}*|заканчива\p{L}*|прекрат\p{L}*|останов\p{L}*|отмен\p{L}*)\s+(?:\p{L}+\s+){0,2}?' . $what . '/u', $t)) {
            return tesFestStop();
        }
        if (preg_match('/(?<![\p{L}])(?:устро\p{L}*|начина\p{L}*|начн\p{L}*|объяв\p{L}*|затей\p{L}*|замути\p{L}*|давай\p{L}*|хочу)\s+(?:\p{L}+\s+){0,3}?' . $what . '/u', $t)) {
            return tesFestStart();
        }
        return '';
    }

    /** For the prompt of a guest: what is going on at the feast right now. */
    function tesFestLine(): string
    {
        tesFestEnsure();
        $f = $GLOBALS['db']->fetchOne("SELECT now_text FROM public.tes_festival WHERE active ORDER BY id DESC LIMIT 1");
        return trim(strval($f['now_text'] ?? '')) !== '' ? ' Сейчас на гулянке идут Игры: ' . trim(strval($f['now_text'])) . ' Ты это видишь и можешь болеть, подначивать, спорить.' : '';
    }

    function tesFestTick(): void
    {
        tesFestEnsure();
        $db = $GLOBALS['db'];
        // 1. the steps that are due: each is claimed by one request
        $due = $db->fetchAll("UPDATE public.tes_fest_queue SET done = true WHERE id IN (SELECT id FROM public.tes_fest_queue WHERE NOT done AND due_at <= now() ORDER BY due_at, id LIMIT 4 FOR UPDATE SKIP LOCKED) RETURNING kind, payload");
        foreach (is_array($due) ? $due : [] as $q) {
            $kind = strval($q['kind']);
            $p = strval($q['payload']);
            if ($kind === 'say') {
                $db->insert('responselog', ['localts' => time(), 'sent' => 0, 'text' => $p, 'actor' => 'rolemaster', 'action' => 'rolecommand', 'tag' => '']);
            } elseif ($kind === 'cmd') {
                $cmds = json_decode($p, true);
                if (is_array($cmds) && $cmds) {
                    tesWorldQueue($cmds);
                }
            } elseif ($kind === 'note') {
                tesWatchNotify($p);
            } elseif ($kind === 'drink' && function_exists('tesRealmDrinkAnim')) {
                tesRealmDrinkAnim($p);
            } elseif ($kind === 'idle' && function_exists('tesSheoIdle') && strpos($p, '|') !== false) {
                [$iref, $iidle] = explode('|', $p, 2);
                tesSheoIdle($iref, $iidle);
            }
        }
        // a wonder by itself every ~100 s while the Games run (sheo.php)
        $act = $db->fetchOne("SELECT 1 AS x FROM public.tes_festival WHERE active LIMIT 1");
        // "пусть пиздец начнётся" (owner, 00:15): a storm - a wonder every ~18 s for five minutes, Games or not
        $storm = tesWatchGet('sheo_storm');
        $stormOn = $storm['value'] === '1' && $storm['age'] < 900;  // "ещё ещё ещё больше": 15 minutes, two at a time
        if (function_exists('tesSheoWonder') && !tesWorldQueueBusy()
            && (($stormOn && tesWatchGet('sheo_at')['age'] >= 7) || (!empty($act) && tesWatchGet('sheo_at')['age'] >= 100))) {
            if (tesSheoWonder() !== '' && $stormOn) {
                tesSheoWonder('', true);
            }
        }
        // 2. the next number of the programme
        $f = $db->fetchOne("SELECT id FROM public.tes_festival WHERE active AND next_at <= now() ORDER BY id DESC LIMIT 1");
        if (empty($f['id'])) {
            return;
        }
        $guests = tesFestGuests();
        if (count($guests) < 3) {
            $db->execQuery("UPDATE public.tes_festival SET active = false, now_text = '' WHERE id = " . intval($f['id']));  // the feast is over
            return;
        }
        $claim = $db->fetchAll("UPDATE public.tes_festival SET stage = stage + 1, next_at = now() + interval '" . (390 + random_int(0, 90)) . " seconds' WHERE id = " . intval($f['id']) . " AND active AND next_at <= now() RETURNING stage");
        if (empty($claim[0]['stage'])) {
            return;
        }
        $now = tesFestStage(intval($claim[0]['stage']), $guests);
        $db->execQuery("UPDATE public.tes_festival SET now_text = '" . $db->escape($now) . "' WHERE id = " . intval($f['id']));
        error_log('[tes_world] games, number ' . intval($claim[0]['stage']) . ': ' . $now);
    }

    /** One number of the programme; returns what is going on (for the guests' prompts and the log). */
    function tesFestStage(int $stage, array $g): string
    {
        $men = array_values(array_filter($g, fn($x) => !$x['female']));
        $pick = fn(array $pool, int $i) => $pool[$i % max(1, count($pool))];
        [$a, $b, $c] = [$g[0], $g[1], $g[2]];
        if ($stage === 1) {
            $herald = $men ? $men[0] : $a;
            tesFestNote('Игры начинаются! Всю гулянку — потехи одна за другой. Глашатай: ' . $herald['name']);
            tesFestSay($herald['name'], 'Ты глашатай. Громко объяви всем, что ярл повелел начать Игры: состязания выпивох, суд дураков, перебранки и золото победителям.', 2);
            foreach (array_slice($g, 0, 8) as $i => $x) {
                tesFestCmd(['prid ' . $x['ref'], 'additem 00034C5E 2'], 3);
                tesFestDrink($x['ref'], 8 + $i * 2);
            }
            tesFestSay($b['name'], 'Выкрикни из толпы, чего ты ждёшь от Игр и на кого поставишь.', 25);
            return 'глашатай объявил начало Игр, все подняли кружки.';
        }
        $numbers = ['drink', 'court', 'flyting', 'gold', 'strong', 'queen', 'tale', 'toast', 'whisper'];
        $kind = $numbers[($stage - 2) % count($numbers)];
        if ($kind === 'drink') {
            tesFestNote("Состязание выпивох: {$a['name']}, {$b['name']} и {$c['name']} пьют наперегонки. Победителю — 300 септимов из казны.");
            foreach ([$a, $b, $c] as $i => $x) {
                tesFestCmd(['prid ' . $x['ref'], 'moveto player', 'additem 00034C5E 6'], 1);
                foreach ([6, 14, 22, 30] as $d) {
                    tesFestDrink($x['ref'], $d + $i);
                }
            }
            tesFestSay($a['name'], "Ты состязаешься в питье с {$b['name']} и {$c['name']}. Похвались, что перепьёшь обоих.", 4);
            tesFestSay($b['name'], "Ты состязаешься в питье с {$a['name']}. Огрызнись и опрокинь кружку.", 18);
            tesFestCmd(['player.pushactoraway ' . $c['ref'] . ' 3'], 36);
            tesFestSay($c['name'], 'Ты перебрал и только что свалился с ног. Простони что-нибудь с пола.', 40);
            tesFestPay($a, 300, 'Игры: победитель выпивох ' . $a['name'], 44);
            tesFestSay($a['name'], "Ты перепил всех, {$c['name']} лежит. Тебе вручили 300 септимов от ярла — торжествуй.", 48);
            return "состязание выпивох — {$a['name']}, {$b['name']} и {$c['name']} пьют наперегонки; {$c['name']} свалился, победил {$a['name']}.";
        }
        if ($kind === 'court') {
            $charges = ['украл у курицы яйцо и высидел его сам', 'пел так, что в подземелье скис эль', 'три дня ходил в чужих сапогах и хвалил их', 'смотрел на ярла без должного восторга',
                'продал соседу его же собственную корову', 'чихнул во время тоста за ярла', 'научил ворону ругаться именем стюарда'];
            $charge = $charges[array_rand($charges)];
            tesFestNote("Суд дураков: {$a['name']} обвиняет {$b['name']} — «{$charge}». Приговор за тобой, ярл.");
            tesFestCmd(['prid ' . $b['ref'], 'moveto player'], 1);
            tesFestCmd(['prid ' . $a['ref'], 'moveto player'], 1);
            tesFestSay($a['name'], "Идёт шуточный суд дураков. Ты обвинитель: при всех обвини {$b['name']} в том, что он {$charge}. Требуй у ярла суровой кары.", 4);
            tesFestSay($b['name'], "Идёт шуточный суд дураков. {$a['name']} обвинил тебя в том, что ты {$charge}. Защищайся нелепо и с жаром.", 22);
            tesFestSay($c['name'], "Идёт шуточный суд дураков над {$b['name']}. Выкрикни из толпы, какого приговора ты требуешь, и попроси ярла решить.", 40);
            return "суд дураков — {$a['name']} обвиняет {$b['name']} в том, что тот {$charge}; все ждут приговора ярла.";
        }
        if ($kind === 'flyting') {
            tesFestNote("Перебранка: {$a['name']} против {$b['name']} — кто кого переругает. Победителя назови сам: ему 200 септимов.");
            tesFestCmd(['prid ' . $a['ref'], 'moveto player'], 1);
            tesFestCmd(['prid ' . $b['ref'], 'moveto player'], 1);
            tesFestSay($a['name'], "Идёт перебранка — старинная нордская потеха, кто кого обиднее и смешнее обругает. Твой соперник — {$b['name']}. Начни: обругай его складно и зло, но без драки.", 4);
            tesFestSay($b['name'], "Идёт перебранка. {$a['name']} только что тебя обругал. Ответь обиднее и смешнее.", 22);
            tesFestSay($a['name'], "Перебранка с {$b['name']} продолжается. Добей его последней колкостью и спроси ярла, кто победил.", 42);
            return "перебранка — {$a['name']} и {$b['name']} по очереди ругают друг друга; победителя назовёт ярл.";
        }
        if ($kind === 'gold') {
            $paid = 0;
            foreach (array_slice($g, 0, 12) as $x) {
                $sum = 40 + random_int(0, 80);
                if (tesFestPay($x, $sum, 'Игры: щедрость ярла', 3)) {
                    $paid += $sum;
                }
            }
            tesFestNote($paid > 0 ? "Щедрость ярла: гостям роздано {$paid} септимов из казны." : 'Щедрость ярла: казна пуста, гостям раздали только обещания.');
            tesFestSay($a['name'], $paid > 0 ? 'Ярл только что раздал гостям золото из казны, тебе тоже досталось. Восславь его щедрость по-своему.' : 'Ярл хотел раздать золото, но казна пуста. Пошути над этим.', 6);
            tesFestSay($b['name'], $paid > 0 ? 'Ярл раздал гостям золото, но тебе кажется, что соседу дали больше. Поворчи вслух.' : 'Казна пуста, золота не дали. Поворчи вслух.', 24);
            return $paid > 0 ? 'ярл раздал гостям золото из казны; одни славят, другие считают чужое.' : 'ярл хотел раздать золото, но казна пуста.';
        }
        if ($kind === 'strong') {
            $x = $men ? $pick($men, 0) : $a;
            $y = count($men) > 1 ? $pick($men, 1) : $b;
            tesFestNote("Силачи: {$x['name']} и {$y['name']} борются на руках. Победителю — 250 септимов.");
            tesFestCmd(['prid ' . $x['ref'], 'moveto player'], 1);
            tesFestCmd(['prid ' . $y['ref'], 'moveto player'], 1);
            tesFestSay($x['name'], "Ты борешься на руках с {$y['name']} на глазах у всех. Пригрози, что уложишь его руку.", 4);
            tesFestSay($y['name'], "Ты борешься на руках с {$x['name']}. Кряхти и не сдавайся.", 20);
            tesFestCmd(['player.pushactoraway ' . $y['ref'] . ' 2'], 34);
            tesFestPay($x, 250, 'Игры: силач ' . $x['name'], 36);
            tesFestSay($x['name'], "Ты уложил руку {$y['name']} так, что он слетел со скамьи. Тебе дали 250 септимов — торжествуй.", 40);
            return "силачи — {$x['name']} поборол {$y['name']} на руках, тот слетел со скамьи.";
        }
        if ($kind === 'queen') {
            $women = array_values(array_filter($g, fn($w) => $w['female']));
            if (count($women) < 2) {
                $women = [$a, $b];
            }
            [$x, $y] = [$women[0], $women[1]];
            tesFestNote("Королева гулянки: {$x['name']} и {$y['name']} спорят, кому ею быть. Выбери сам — скажи имя.");
            tesFestCmd(['prid ' . $x['ref'], 'moveto player'], 1);
            tesFestCmd(['prid ' . $y['ref'], 'moveto player'], 1);
            tesFestSay($x['name'], "Гости выбирают королеву гулянки. Объяви, что королевой должна быть ты, а не {$y['name']}, и скажи почему.", 4);
            tesFestSay($y['name'], "Гости выбирают королеву гулянки. {$x['name']} назвала себя. Возрази и попроси ярла выбрать тебя.", 22);
            tesFestSay($c['name'], "Гости выбирают королеву гулянки между {$x['name']} и {$y['name']}. Выкрикни, за кого ты, и почему.", 40);
            return "выборы королевы гулянки — {$x['name']} и {$y['name']} спорят, выбирает ярл.";
        }
        if ($kind === 'tale') {
            tesFestNote("Страшная байка: {$a['name']} рассказывает, что живёт в этих стенах.");
            tesFestSay($a['name'], 'Расскажи гостям короткую жуткую байку о том, кто по ночам ходит в этом самом подземелье. Понизь голос.', 3);
            tesFestSay($b['name'], "{$a['name']} только что рассказал жуткую байку про это подземелье. Тебе не по себе — признайся или отшутись.", 24);
            tesFestCmd(['player.pushactoraway ' . $c['ref'] . ' 1'], 38);
            tesFestSay($c['name'], 'От страшной байки у тебя подкосились ноги и ты осел на пол. Оправдайся, что это всё эль.', 42);
            return "страшная байка — {$a['name']} пугает гостей рассказом о подземелье, {$c['name']} осел на пол.";
        }
        if ($kind === 'toast') {
            $toasts = ['за того, кто придумал эль', 'за стражника, который ни разу не получил стрелу в колено', 'за то, чтобы драконы летали мимо', 'за самую кривую улицу Вайтрана', 'за тех, кто уже под столом'];
            tesFestNote('Тосты по кругу: пьют все.');
            foreach ([$a, $b, $c] as $i => $x) {
                tesFestSay($x['name'], 'Твой черёд говорить тост. Подними кружку ' . $toasts[array_rand($toasts)] . ' — и добавь от себя.', 3 + $i * 18);
            }
            foreach (array_slice($g, 0, 10) as $i => $x) {
                tesFestCmd(['prid ' . $x['ref'], 'additem 00034C5E 1'], 2);
                tesFestDrink($x['ref'], 10 + $i * 4);
            }
            return 'тосты по кругу — гости по очереди поднимают кружки, пьют все.';
        }
        // whisper
        tesFestNote("Шёпот: {$a['name']} хочет сказать тебе кое-что про {$b['name']}.");
        tesFestCmd(['prid ' . $a['ref'], 'moveto player'], 1);
        tesFestSay($a['name'], "Подойди к ярлу и вполголоса расскажи ему сплетню про {$b['name']}: что тот натворил на этой гулянке или что замышляет. Придумай сам, правдоподобно.", 4);
        tesFestSay($b['name'], "Ты заметил, что {$a['name']} шепчет ярлу что-то про тебя. Возмутись вслух.", 30);
        return "{$a['name']} нашептал ярлу сплетню про {$b['name']}, тот заметил и возмущён.";
    }
}
