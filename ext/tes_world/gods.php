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
    /**
     * The god's own voice for this answer (F5-TTS has the game's Daedra voices: maleuniquesheogorath, …). Set through
     * CHIM's XTTS_TEXTMODIFIER hook - it runs right before the voice is picked for every sentence, so nothing
     * (the Narrator's own settings) can put the Narrator's voice back in between.
     */
    function tesWorldGodVoice(string $voice, string $filter = ''): void
    {
        if ($voice === '') {
            return;
        }
        $GLOBALS['TES_GOD_VOICE'] = $voice;
        $GLOBALS['PATCH_OVERRIDE_VOICE'] = $voice;
        // The audio filter of the god (CHIM's server-side preset, tts_filter_presets.php): without it the Narrator's
        // preset lies on every god. Set here and again in the hook, for the same reason as the voice.
        $GLOBALS['TES_GOD_FILTER'] = $filter;
        if ($filter !== '' && function_exists('setActiveTtsFilterPreset')) {
            setActiveTtsFilterPreset($filter);
        }
        if (empty($GLOBALS['TES_GOD_VOICE_HOOK'])) {
            $GLOBALS['TES_GOD_VOICE_HOOK'] = true;
            $GLOBALS['HOOKS']['XTTS_TEXTMODIFIER'][] = function ($s) {
                if (!empty($GLOBALS['TES_GOD_VOICE'])) {
                    $GLOBALS['PATCH_OVERRIDE_VOICE'] = $GLOBALS['TES_GOD_VOICE'];
                }
                if (!empty($GLOBALS['TES_GOD_FILTER']) && function_exists('setActiveTtsFilterPreset')) {
                    setActiveTtsFilterPreset($GLOBALS['TES_GOD_FILTER']);
                }
                return $s;
            };
        }
    }

    function tesGods(): array
    {
        return [
            'arkay' => ['voicewav' => 'maleuniquearngeir', 'filter' => 'measured', 'name' => 'Аркей', 're' => 'арке[йяюе]\p{L}*', 'voice' => 'Аркей answers, god of life and death: speak solemnly, restrained and stern, of the cycle of life'],
            'kynareth' => ['voicewav' => 'femaleeventoned', 'filter' => 'ethereal', 'name' => 'Кинарет', 're' => 'кинарет\p{L}*', 'voice' => 'Кинарет answers, goddess of sky and winds: speak brightly and melodiously, of sky, wind and rain'],
            'mara' => ['voicewav' => 'femaleoldkindly', 'filter' => 'warm', 'name' => 'Мара', 're' => 'мар[аыеу](?![\p{L}])', 'voice' => 'Мара answers, goddess of love: speak warmly and gently, like a mother'],
            'hermaeus' => ['voicewav' => 'maleuniquehermaeusmora', 'filter' => 'haunted', 'name' => 'Хермеус Мора', 're' => 'хермеус\p{L}*(?:\s+мор\p{L}*)?|херм[еэ]ус\p{L}*', 'voice' => 'Хермеус Мора answers, Daedric Prince of knowledge: speak ominously, insinuatingly, in riddles, like a thousand whispers'],
            'clavicus' => ['voicewav' => 'maleuniqueclavicusvile', 'filter' => 'drawling', 'name' => 'Клавикус Вайл', 're' => 'клавикус\p{L}*(?:\s+вайл\p{L}*)?', 'voice' => 'Клавикус Вайл answers, Daedric Prince of bargains: speak like a sly huckster, flattering, with fine print in every phrase'],
            // Шеогорат removed from the pantheon (owner, 2026-10-07: "нахуй бури и шеогората")
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
        return $parts ? implode('; ', $parts) : 'the book of legends is empty so far - the gods have not intervened yet';
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
            return ' *book of legends: ' . tesLegendsText() . '; retell it to the jarl as a storyteller*';
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
            $did = 'you are out of humour and refuse the jarl - he pestered you too often';
        } elseif ($god === 'arkay') {
            if (preg_match('/(?<![\p{L}])(воскрес\p{L}*|верни\s+(?:к\s+жизни|из\s+мертв)|оживи\p{L}*)/u', $t)) {
                $who = tesGodTarget($line);
                $ref = $who !== '' ? tesWorldRefOf($who) : '';
                $dead = $who !== '' ? $GLOBALS['db']->fetchOne("SELECT metadata->'activity_status'->>'is_dead' AS d FROM public.core_npc_master WHERE npc_name = '" . $GLOBALS['db']->escape($who) . "' LIMIT 1") : [];
                if ($ref === '' || tesChildSafeIsChildRef($ref)) {
                    $did = 'the jarl did not name whom to bring back - ask the name';
                } elseif (strval($dead['d'] ?? '') !== 'true') {
                    $did = "{$who} is alive already - Аркей does not touch the living";  // resurrect on the living can reset them
                } else {
                    tesWorldQueue(['prid ' . $ref, 'resurrect']);
                    tesGodFavor($god, -15);  // death is not cheated for free
                    $did = "{$who} is brought back to life - but Аркей reminds that everything has a price";
                    tesLegend($G['name'], "вернул к жизни {$who} по молитве " . strval($GLOBALS['PLAYER_NAME'] ?? 'ярла'));
                }
            } else {
                tesWorldQueue(['player.restoreav health 10000', 'player.restoreav stamina 10000', 'player.restoreav magicka 10000']);
                tesGodFavor($god, -3);
                $did = 'the jarl\'s wounds closed, his strength returned';
                tesLegend($G['name'], 'исцелил ' . strval($GLOBALS['PLAYER_NAME'] ?? 'ярла'));
            }
        } elseif ($god === 'kynareth') {
            $map = ['0010A240' => 'ясн|солнц|солнечн|разгони|прояс', '000C821F' => 'дожд|ливень|полей', '000C8220' => 'гроз|гром|молни|шторм', '0004D7FB' => 'снег|снежн', '000C821E' => 'туман', '0010A243' => 'облак|пасмурн', '000C8221' => 'метел|буран|вьюг'];
            foreach ($map as $id => $words) {
                if (preg_match('/(' . $words . ')/u', $t)) {
                    tesWorldQueue(['fw ' . $id]);
                    tesGodFavor($god, -2);
                    $did = 'the sky obeyed: the weather changed';
                    tesLegend($G['name'], 'переменила небо по слову ' . strval($GLOBALS['PLAYER_NAME'] ?? 'ярла'));
                    break;
                }
            }
            if ($did === '') {
                $did = 'the jarl did not say what sky he wants - ask: sun, rain, thunderstorm, snow, fog?';
            }
        } elseif ($god === 'mara') {
            $who = tesGodTarget($line);
            $ref = $who !== '' ? tesWorldRefOf($who) : '';
            if ($ref === '' || tesChildSafeIsChildRef($ref)) {
                $did = $ref !== '' ? 'that is a child - Мара blesses children, not weds them' : 'the jarl did not name whose heart to sway - ask';
            } else {
                tesWorldQueue(['prid ' . $ref, 'setrelationshiprank player 4']);
                if (function_exists('tesGodGuardRemember')) {
                    $p = $GLOBALS['db']->fetchOne("SELECT id FROM public.core_npc_master WHERE npc_name = '" . $GLOBALS['db']->escape($who) . "' LIMIT 1");
                    if (!empty($p['id'])) {
                        tesGodGuardRemember(intval($p['id']), 'Мара благословила: ты полюбил(а) ' . strval($GLOBALS['PLAYER_NAME'] ?? 'правителя') . ' всем сердцем.');
                    }
                }
                tesGodFavor($god, -8);
                $did = "the heart of {$who} now belongs to the jarl";
                tesLegend($G['name'], "склонила сердце {$who} к " . strval($GLOBALS['PLAYER_NAME'] ?? 'ярлу'));
            }
        } elseif ($god === 'hermaeus') {
            $db = $GLOBALS['db'];
            $secret = '';
            $has = $db->fetchOne("SELECT to_regclass('public.tes_witness_seen') AS t");
            $r = !empty($has['t']) ? $db->fetchOne("SELECT deed FROM public.tes_witness_seen ORDER BY random() LIMIT 1") : [];
            if (!empty($r['deed'])) {
                $secret = 'it really happened: ' . $r['deed'];
            } else {
                $n = $db->fetchOne("SELECT npc_name, coalesce(goals::text, '') AS g FROM public.core_npc_master WHERE length(coalesce(goals::text, '')) > 20 AND race NOT ILIKE '%реб%' ORDER BY random() LIMIT 1");
                if (!empty($n['npc_name'])) {
                    $g = trim(preg_split('/(?:^|\s)[*•\-]\s+|\n+/u', trim(preg_replace('/[\[\]{}"]+/u', ' ', strval($n['g'])) ?? ''))[1] ?? strval($n['g']));
                    $secret = "secret wish of {$n['npc_name']}: " . mb_substr($g, 0, 140);
                }
            }
            $skills = ['Alchemy', 'Enchanting', 'Illusion', 'Conjuration', 'Destruction', 'Speechcraft', 'Lockpicking', 'Sneak'];
            $sk = $skills[random_int(0, count($skills) - 1)];
            tesWorldQueue(['player.modav ' . $sk . ' 3']);
            tesGodFavor($god, -4);
            $did = ($secret !== '' ? "you revealed a secret to the jarl ({$secret}) - say it as a riddle, yet so he understands; " : '') . 'you also put knowledge into his head (a skill grew)';
            tesLegend($G['name'], 'открыл тайну и одарил знанием ' . strval($GLOBALS['PLAYER_NAME'] ?? 'ярла'));
        } elseif ($god === 'clavicus') {
            $n = tesWorldSpokenAmount($line);
            $n = $n > 0 ? min($n, 20000) : 1000;
            $open = tesWatchGet('clavicus_debt')['value'];
            if ($open !== '' && intval($open) > 0) {
                $did = 'the jarl already owes you ' . intval($open) . ' septims; remind him the deal stands';
            } else {
                tesWorldQueue(['player.additem 0000000F ' . $n]);
                tesWatchSet('clavicus_debt', strval($n * 2));
                tesWatchSet('clavicus_due', strval(time() + 1200));
                tesGodFavor($god, 5);
                $did = "deal struck: the jarl got {$n} septims now, in twenty minutes you take back " . ($n * 2) . ' (from his purse, the shortfall from the treasury)';
                tesLegend($G['name'], "дал {$n} септимов в долг под двойную плату");
            }
        } elseif ($god === 'sheogorath' && function_exists('tesSheoWonder')) {
            $w = tesSheoWonder('', true);
            tesGodFavor($god, 3);
            $did = $w !== '' ? 'you caused: ' . $w : '';
            if ($w !== '') {
                tesLegend($G['name'], $w);
            }
        }
        if (stripos($to, 'Narrator') === false && $to !== '') {
            return ' *the jarl called upon ' . $G['name'] . ($did !== '' ? '; what happened: ' . $did : '') . '; react in your own way*';
        }
        tesWorldGodVoice(strval($G['voicewav'] ?? ''), strval($G['filter'] ?? ''));
        return ' *' . $G['voice'] . '. One or two sentences.' . ($did !== '' ? ' Already happened: ' . $did . '.' : '') . '*';
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
                $god = ['Мара', 'Аркей', 'Кинарет', 'Дибелла', 'Талос', 'Зенитар'][random_int(0, 5)];
                tesFestSay(strval($p['name']), "You quietly pray to {$god} - about something of your own that troubles you now. Say the prayer aloud, in one or two sentences.", 1);
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
