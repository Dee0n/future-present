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
        tesFestPush('say', "Instruction@{$name}@(Games are on at the jarl's feast. {$task} Say it in one or two short lively phrases in Russian, in character, aloud, without retelling these words.)@0", $delay);
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
        return ' *Games are declared: all through the feast a new amusement every few minutes (contests, a fools\' court, flytings, gold from the treasury); already begun, announce it*';
    }

    function tesFestStop(): string
    {
        tesFestEnsure();
        $db = $GLOBALS['db'];
        $was = $db->fetchOne("SELECT 1 AS x FROM public.tes_festival WHERE active LIMIT 1");
        $db->execQuery("UPDATE public.tes_festival SET active = false, now_text = '' WHERE active");
        $db->execQuery("UPDATE public.tes_fest_queue SET done = true WHERE NOT done");
        return empty($was) ? '' : ' *the Games are over at the jarl\'s word; the feast goes on without them*';
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
        return trim(strval($f['now_text'] ?? '')) !== '' ? ' Games are on at the feast now: ' . trim(strval($f['now_text'])) . ' You see it and may cheer, tease, argue.' : '';
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
        // Off (owner, 2026-10-07: "нахуй бури и шеогората"): no storm, no wonders by themselves during the Games.
        if (false && function_exists('tesSheoWonder') && !tesWorldQueueBusy()
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
            tesFestSay($herald['name'], 'You are the herald. Loudly announce to all that the jarl has ordered the Games to begin: drinking contests, a fools\' court, flytings and gold for the winners.', 2);
            foreach (array_slice($g, 0, 8) as $i => $x) {
                tesFestCmd(['prid ' . $x['ref'], 'additem 00034C5E 2'], 3);
                tesFestDrink($x['ref'], 8 + $i * 2);
            }
            tesFestSay($b['name'], 'Shout from the crowd what you expect of the Games and whom you will bet on.', 25);
            return 'the herald announced the start of the Games, everyone raised their mugs.';
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
            tesFestSay($a['name'], "You are in a drinking contest with {$b['name']} and {$c['name']}. Boast that you will outdrink both.", 4);
            tesFestSay($b['name'], "You are in a drinking contest with {$a['name']}. Snap back and down your mug.", 18);
            tesFestCmd(['player.pushactoraway ' . $c['ref'] . ' 3'], 36);
            tesFestSay($c['name'], 'You drank too much and have just fallen off your feet. Groan something from the floor.', 40);
            tesFestPay($a, 300, 'Игры: победитель выпивох ' . $a['name'], 44);
            tesFestSay($a['name'], "You outdrank everyone, {$c['name']} is down. You were handed 300 septims from the jarl - gloat.", 48);
            return "a drinking contest - {$a['name']}, {$b['name']} and {$c['name']} race to drink; {$c['name']} fell down, {$a['name']} won.";
        }
        if ($kind === 'court') {
            $charges = ['украл у курицы яйцо и высидел его сам', 'пел так, что в подземелье скис эль', 'три дня ходил в чужих сапогах и хвалил их', 'смотрел на ярла без должного восторга',
                'продал соседу его же собственную корову', 'чихнул во время тоста за ярла', 'научил ворону ругаться именем стюарда'];
            $charge = $charges[array_rand($charges)];
            tesFestNote("Суд дураков: {$a['name']} обвиняет {$b['name']} — «{$charge}». Приговор за тобой, ярл.");
            tesFestCmd(['prid ' . $b['ref'], 'moveto player'], 1);
            tesFestCmd(['prid ' . $a['ref'], 'moveto player'], 1);
            tesFestSay($a['name'], "A mock fools' court is on. You are the accuser: before everyone accuse {$b['name']} that he «{$charge}». Demand a harsh punishment from the jarl.", 4);
            tesFestSay($b['name'], "A mock fools' court is on. {$a['name']} accused you that you «{$charge}». Defend yourself absurdly and with passion.", 22);
            tesFestSay($c['name'], "A mock fools' court over {$b['name']} is on. Shout from the crowd what sentence you demand, and ask the jarl to decide.", 40);
            return "a fools' court - {$a['name']} accuses {$b['name']} that he «{$charge}»; everyone awaits the jarl's sentence.";
        }
        if ($kind === 'flyting') {
            tesFestNote("Перебранка: {$a['name']} против {$b['name']} — кто кого переругает. Победителя назови сам: ему 200 септимов.");
            tesFestCmd(['prid ' . $a['ref'], 'moveto player'], 1);
            tesFestCmd(['prid ' . $b['ref'], 'moveto player'], 1);
            tesFestSay($a['name'], "A flyting is on - an old Nord game of who insults the other more bitingly and funnily. Your rival is {$b['name']}. Start: insult him wittily and nastily, but no fighting.", 4);
            tesFestSay($b['name'], "A flyting is on. {$a['name']} has just insulted you. Answer more bitingly and funnily.", 22);
            tesFestSay($a['name'], "The flyting with {$b['name']} goes on. Finish him with a last barb and ask the jarl who won.", 42);
            return "a flyting - {$a['name']} and {$b['name']} insult each other in turn; the jarl will name the winner.";
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
            tesFestSay($a['name'], $paid > 0 ? 'The jarl has just handed the guests gold from the treasury, you got some too. Praise his generosity in your own way.' : 'The jarl wanted to hand out gold, but the treasury is empty. Joke about it.', 6);
            tesFestSay($b['name'], $paid > 0 ? 'The jarl handed the guests gold, but you think your neighbour got more. Grumble aloud.' : 'The treasury is empty, no gold was given. Grumble aloud.', 24);
            return $paid > 0 ? 'the jarl handed the guests gold from the treasury; some praise him, others count what the rest got.' : 'the jarl wanted to hand out gold, but the treasury is empty.';
        }
        if ($kind === 'strong') {
            $x = $men ? $pick($men, 0) : $a;
            $y = count($men) > 1 ? $pick($men, 1) : $b;
            tesFestNote("Силачи: {$x['name']} и {$y['name']} борются на руках. Победителю — 250 септимов.");
            tesFestCmd(['prid ' . $x['ref'], 'moveto player'], 1);
            tesFestCmd(['prid ' . $y['ref'], 'moveto player'], 1);
            tesFestSay($x['name'], "You are arm-wrestling {$y['name']} in front of everyone. Threaten to pin his arm.", 4);
            tesFestSay($y['name'], "You are arm-wrestling {$x['name']}. Grunt and do not give in.", 20);
            tesFestCmd(['player.pushactoraway ' . $y['ref'] . ' 2'], 34);
            tesFestPay($x, 250, 'Игры: силач ' . $x['name'], 36);
            tesFestSay($x['name'], "You pinned the arm of {$y['name']} so hard that he flew off the bench. You were given 250 septims - gloat.", 40);
            return "strongmen - {$x['name']} beat {$y['name']} at arm-wrestling; the loser flew off the bench.";
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
            tesFestSay($x['name'], "The guests are choosing the queen of the feast. Declare that the queen must be you, not {$y['name']}, and say why.", 4);
            tesFestSay($y['name'], "The guests are choosing the queen of the feast. {$x['name']} named herself. Object and ask the jarl to choose you.", 22);
            tesFestSay($c['name'], "The guests are choosing the queen of the feast between {$x['name']} and {$y['name']}. Shout whom you back and why.", 40);
            return "choosing the queen of the feast - {$x['name']} and {$y['name']} argue, the jarl chooses.";
        }
        if ($kind === 'tale') {
            tesFestNote("Страшная байка: {$a['name']} рассказывает, что живёт в этих стенах.");
            tesFestSay($a['name'], 'Tell the guests a short creepy tale of who walks at night in this very dungeon. Lower your voice.', 3);
            tesFestSay($b['name'], "{$a['name']} has just told a creepy tale about this dungeon. You feel uneasy - admit it or laugh it off.", 24);
            tesFestCmd(['player.pushactoraway ' . $c['ref'] . ' 1'], 38);
            tesFestSay($c['name'], 'The scary tale made your legs give way and you sank to the floor. Make the excuse that it is all the ale.', 42);
            return "a scary tale - {$a['name']} frightens the guests with a story of the dungeon, {$c['name']} sank to the floor.";
        }
        if ($kind === 'toast') {
            $toasts = ['to whoever invented ale', 'to the guard who never took an arrow in the knee', 'to dragons flying past', 'to the most crooked street of Вайтран', 'to those already under the table'];
            tesFestNote('Тосты по кругу: пьют все.');
            foreach ([$a, $b, $c] as $i => $x) {
                tesFestSay($x['name'], 'Your turn to give a toast. Raise your mug ' . $toasts[array_rand($toasts)] . ' - and add something of your own.', 3 + $i * 18);
            }
            foreach (array_slice($g, 0, 10) as $i => $x) {
                tesFestCmd(['prid ' . $x['ref'], 'additem 00034C5E 1'], 2);
                tesFestDrink($x['ref'], 10 + $i * 4);
            }
            return 'toasts round the table - the guests raise their mugs in turn, everyone drinks.';
        }
        // whisper
        tesFestNote("Шёпот: {$a['name']} хочет сказать тебе кое-что про {$b['name']}.");
        tesFestCmd(['prid ' . $a['ref'], 'moveto player'], 1);
        tesFestSay($a['name'], "Go up to the jarl and in a low voice tell him gossip about {$b['name']}: what he did at this feast or what he is plotting. Make it up yourself, plausibly.", 4);
        tesFestSay($b['name'], "You noticed {$a['name']} whispering something about you to the jarl. Protest aloud.", 30);
        return "{$a['name']} whispered gossip about {$b['name']} to the jarl; the latter noticed and is indignant.";
    }
}
