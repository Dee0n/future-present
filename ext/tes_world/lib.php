<?php
/*
 * tes_world: facts of the world that every character knows.
 *
 * Owner, 2026-10-04: "я теперь ярл. почему мозг ебут мне?" - the Narrator jailed Balgruuf, but
 * nothing told the NPCs that the player now rules ("Да-да, ты ярл. И я дракон."). A title is not
 * a record in the game data (ROADMAP H): it is what people believe and act on. So it is kept
 * here and shown to every NPC and the Narrator at the bottom of the prompt.
 *
 * player.title <титул> (tes_god_guard) -> tesWorldSetTitle(). "player.title нет" clears it.
 */

if (!function_exists('tesWorldEnsureTable')) {
    function tesWorldEnsureTable(): void
    {
        $GLOBALS['db']->execQuery("
            CREATE TABLE IF NOT EXISTS public.tes_world_titles (
                key text PRIMARY KEY,
                fact text NOT NULL,
                gamets bigint NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        ");
    }

    /** Active facts. A fact dated after "now" (a save from before it was loaded) is not shown. */
    function tesWorldFacts(): array
    {
        $db = $GLOBALS['db'];
        $has = $db->fetchOne("SELECT to_regclass('public.tes_world_titles') AS t");
        if (empty($has['t'])) {
            return [];
        }
        $now = intval($GLOBALS['gameRequest'][2] ?? 0);
        $rows = $db->fetchAll("SELECT key, fact, gamets FROM public.tes_world_titles ORDER BY created_at");
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if ($now > 0 && intval($r['gamets']) > $now + 100000) {
                continue;
            }
            $out[$r['key']] = $r['fact'];
        }
        return $out;
    }

    /**
     * Names of the people around the player right now (near first, far ones last, max $max).
     * The player talks by voice and speech recognition mangles names ("из Ольда" for Изольда ->
     * a fine for Олфрид, "рилет", "ольхина", "Хеймс-кара" - live 2026-10-04); whoever has to
     * understand an order gets this list to match against.
     */
    function tesWorldNearbyNames(int $max = 14): array
    {
        $row = $GLOBALS['db']->fetchOne("SELECT data FROM eventlog WHERE type = 'infonpc_close' AND localts > " . (time() - 180) . " ORDER BY rowid DESC LIMIT 1");
        $near = [];
        $far = [];
        $player = mb_strtolower(strval($GLOBALS['PLAYER_NAME'] ?? ''));
        foreach (explode('/', strval($row['data'] ?? '')) as $name) {
            $isFar = mb_strpos($name, '(far away)') !== false;
            $name = trim(preg_replace('/\s*\((?:busy|far away|restrained|[a-z ]+)\)\s*/u', ' ', $name) ?? $name);
            $name = trim(preg_replace('/^\(?Context location:[^)]*\)\s*/u', '', $name) ?? $name);
            if ($name === '' || mb_strtolower($name) === $player || mb_strlen($name) > 60) {
                continue;
            }
            if ($isFar) {
                $far[] = $name;
            } else {
                $near[] = $name;
            }
        }
        return array_slice(array_values(array_unique(array_merge($near, $far))), 0, $max);
    }

    /** Lower case, ё -> е, letters and digits only. */
    function tesWorldNorm(string $s): string
    {
        return preg_replace('/[^a-zа-я0-9]+/u', '', str_replace('ё', 'е', mb_strtolower($s))) ?? '';
    }

    /**
     * Edit distance in LETTERS. PHP's levenshtein() counts bytes, and two Cyrillic letters often
     * differ in one byte only - "Какого" came out two "letters" away from "Кадорд" and was
     * rewritten into the guard's name (live 2026-10-04 13:33).
     */
    function tesWorldLev(string $a, string $b): int
    {
        static $map = [];
        $conv = function (string $s) use (&$map): string {
            $out = '';
            foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                if (!isset($map[$ch])) {
                    $map[$ch] = chr(33 + (count($map) % 200));
                }
                $out .= $map[$ch];
            }
            return $out;
        };
        return levenshtein($conv($a), $conv($b));
    }

    /**
     * A name as it was heard -> the full name of someone around, or '' when nobody is close enough.
     * Capitalised words (speech recognition marks what it took for a name) may differ by a letter
     * or two; lower-case ones only when they are a piece of the name ("рилет" -> Айрилет) - a
     * plain word must not turn into a person ("сади" is not Садия).
     */
    function tesWorldHeardName(string $word, array $names): string
    {
        $want = tesWorldNorm($word);
        $len = mb_strlen($want);
        if ($len < 4) {
            return '';
        }
        $capital = (bool)preg_match('/^\p{Lu}/u', trim($word));
        $best = '';
        $bestD = 99;
        foreach ($names as $name) {
            $plain = trim(preg_replace('/\s*\[[^\]]*\]/u', '', $name) ?? $name);
            foreach (preg_split('/\s+/u', $plain) as $part) {
                $form = tesWorldNorm($part);
                $fl = mb_strlen($form);
                if ($fl < 4) {
                    continue;
                }
                if ($form === $want) {
                    return $name;
                }
                $d = tesWorldLev($want, $form);
                $piece = $len >= 5 && $fl > $len && $fl - $len <= 3 && mb_strpos($form, $want) !== false;
                $ok = $piece
                    || ($capital && ($d <= 1 || ($d === 2 && $len >= 6 && mb_substr($want, 0, 1) === mb_substr($form, 0, 1))))
                    || (!$capital && $d <= 1 && $len >= 6);
                if ($ok && ($piece ? 0 : $d) < $bestD) {
                    $bestD = $piece ? 0 : $d;
                    $best = $name;
                }
            }
        }
        return $best;
    }

    /** First word of a full name, the way people call each other ("Кадорд те Глутон [..]" -> "Кадорд"). */
    function tesWorldShortName(string $name): string
    {
        $plain = trim(preg_replace('/\s*\[[^\]]*\]/u', '', $name) ?? $name);
        $first = preg_split('/\s+/u', $plain)[0] ?? $plain;
        return in_array(mb_strtolower($first), ['ярл', 'командир', 'капитан', 'легат'], true) ? $plain : $first;
    }

    /**
     * The player's spoken line with mangled names put right ("Будален, казните рилет!" ->
     * "Будолен, казните Айрилет!"). Only names of people around are used. Returns [text, changes].
     */
    function tesWorldFixHeardNames(string $text, array $names): array
    {
        $changes = [];
        $player = tesWorldNorm(strval($GLOBALS['PLAYER_NAME'] ?? ''));
        // two words that are one name: "из Ольда" -> Изольда, "Хеймс-кара" -> Хеймскр
        $pair = function ($m) use ($names, &$changes) {
            $joined = tesWorldNorm($m[1] . $m[2]);
            foreach ($names as $name) {
                foreach (preg_split('/\s+/u', trim(preg_replace('/\s*\[[^\]]*\]/u', '', $name) ?? $name)) as $part) {
                    $form = tesWorldNorm($part);
                    $d = tesWorldLev($joined, $form);
                    $alone = min(tesWorldLev(tesWorldNorm($m[1]), $form), tesWorldLev(tesWorldNorm($m[2]), $form));
                    // the two pieces together must be at least as close to the name as either alone
                    if (mb_strlen($form) >= 6 && $d <= (mb_strlen($form) >= 7 ? 2 : 1) && $d <= $alone && $alone > 0) {
                        $changes[] = "{$m[0]} → " . tesWorldShortName($name);
                        return tesWorldShortName($name);
                    }
                }
            }
            return $m[0];
        };
        // a hyphen first (surely one word), then a space
        $text = preg_replace_callback('/(?<![\p{L}])(\p{L}{2,8})-(\p{L}{2,8})(?![\p{L}])/u', $pair, $text) ?? $text;
        $text = preg_replace_callback('/(?<![\p{L}])(\p{L}{2,6})\s+(\p{L}{3,8})(?![\p{L}])/u', $pair, $text) ?? $text;
        $text = preg_replace_callback('/(?<![\p{L}])\p{L}{4,}(?![\p{L}])/u', function ($m) use ($names, $player, &$changes) {
            $norm = tesWorldNorm($m[0]);
            if ($norm === $player) {
                return $m[0];
            }
            $hit = tesWorldHeardName($m[0], $names);
            if ($hit === '') {
                return $m[0];
            }
            // a word that IS the name of someone known (just not standing here) is not a slip:
            // "Сигрид" became "Сигурд" because only Сигурд was near (live 2026-10-04 14:19)
            $known = tesWorldKnownName(mb_convert_case($m[0], MB_CASE_TITLE));
            if ($known !== '' && $known !== $hit) {
                return $m[0];
            }
            // already that name, in any case form ("Садию", "Фаренгара") - leave the grammar alone
            foreach (preg_split('/\s+/u', trim(preg_replace('/\s*\[[^\]]*\]/u', '', $hit) ?? $hit)) as $part) {
                $form = tesWorldNorm($part);
                $stem = mb_substr($form, 0, max(4, mb_strlen($form) - 1));
                if (mb_strpos($norm, $stem) === 0 && abs(mb_strlen($norm) - mb_strlen($form)) <= 2) {
                    return $m[0];
                }
            }
            $short = tesWorldShortName($hit);
            $changes[] = "{$m[0]} → {$short}";
            return $short;
        }, $text) ?? $text;
        return [$text, $changes];
    }

    /**
     * An order simple enough to be done at once, without the goal agent and its queue:
     * bring / undress / execute / take everything from - people named in it. Returns
     * ['kind' => bring|strip|kill|take, 'targets' => [full names]], or null when the order is
     * anything else (the agent takes it then).
     * Live 2026-10-04 13:11-13:14: "Подать Айрилет" ran 140 s, "Привести Фаренгара" waited behind it.
     */
    function tesWorldFastOrder(string $order, string $actor = ''): ?array
    {
        $o = trim(preg_replace('/[.!…]+$/u', '', trim($order)) ?? $order);
        $verbs = [
            'bring' => '(?:привести|приведи\p{L}*|позвать|позови\p{L}*|подать|подай\p{L}*|доставить|доставь\p{L}*|притащить|притащи\p{L}*|вызвать|призвать)',
            'strip' => '(?:раздеть|раздень\p{L}*|раздева\p{L}*|(?:снять|сними\p{L}*|сорвать|сорви\p{L}*|стащить|стащи\p{L}*)\s+(?:всю\s+|вс[её]\s+)?(?:одежду|броню|вещи|наряд)?\s*(?:с|со)(?=\s))',
            'kill' => '(?:казнить|казни\p{L}*|убить|убей\p{L}*)',
            'take' => '(?:забрать|забери\p{L}*|отобрать|отбери\p{L}*|изъять|взять)\s+вс[её]\s+у',
            'jail' => '(?:посадить|посади\p{L}*|арестовать|арестуй\p{L}*|(?:бросить|брось\p{L}*|кинуть|кинь\p{L}*|отправить|отправь\p{L}*|заключить)(?=.*(?:тюрьм|темниц|камер)))(?:\s+в\s+(?:тюрьму|темницу|камеру))?',
        ];
        $kind = '';
        $rest = '';
        foreach ($verbs as $k => $re) {
            if (preg_match('/^' . $re . '\s+(.+)$/iu', $o, $m)) {
                $kind = $k;
                $rest = $m[1];
                break;
            }
        }
        if ($kind === '' || preg_match('/(?<![\p{L}])(всех|все|всю|каждого|каждую|кого|если|или|пока|потом|затем)(?![\p{L}])/iu', $rest)) {
            return null;
        }
        // "раздеть меня", "разделась сама": the one who was told is the one meant
        if ($actor !== '' && preg_match('/^(меня|себя|сам[аи]?|самого себя|саму себя)(?![\p{L}])/iu', trim($rest))) {
            return ['kind' => $kind, 'targets' => [$actor]];
        }
        $player = strval($GLOBALS['PLAYER_NAME'] ?? '');
        // what comes after the people: "… к Шаману", "… догола", "… ярлу"
        $rest = preg_replace('/\s+(к|ко)\s+.+$/iu', '', $rest) ?? $rest;
        $rest = preg_replace('/\s+(в\s+(тюрьму|темницу|камеру)|на\s+\S+\s+(сут|дн|день|дня|год|лет)\p{L}*|на\s+(сутки|день|год)|за\s+).*$/iu', '', $rest) ?? $rest;
        $rest = preg_replace('/\s+и\s+(отдать|отдай|передать|передай|принести|принеси|вернуть|верни)(?![\p{L}]).*$/iu', '', $rest) ?? $rest;
        $rest = preg_replace('/\s+(догола|донага|до\s*гола|до нитки|полностью|до конца|сюда|немедленно|сейчас же|ярлу|мне)(?![\p{L}]).*$/iu', '', $rest) ?? $rest;
        if (mb_strlen($player) >= 4) {
            $rest = preg_replace('/\s+' . preg_quote(mb_substr($player, 0, mb_strlen($player) - 1), '/') . '\p{L}*.*$/iu', '', $rest) ?? $rest;
        }
        $names = tesWorldNearbyNames(30);
        $targets = [];
        foreach (preg_split('/\s*,\s*|\s+и\s+/u', trim($rest)) as $piece) {
            $piece = trim($piece);
            if ($piece === '' || mb_strlen($piece) > 60) {
                return null;
            }
            $found = '';
            foreach (preg_split('/\s+/u', $piece) as $word) {
                $found = tesWorldHeardName(mb_convert_case($word, MB_CASE_TITLE), $names) ?: $found;
            }
            if ($found === '' && function_exists('tesGodGuardResolveNpcLoose') && class_exists('RelationshipManager')) {
                // not around: a known person by name, in any case form
                foreach (array_unique([$piece, preg_replace('/(ом|ой|ем|а|я|у|ю|е|ы|и)$/u', '', $piece), preg_replace('/у$/u', 'а', $piece), preg_replace('/ю$/u', 'я', $piece)]) as $try) {
                    $row = $try !== '' && mb_strlen($try) >= 4 ? tesGodGuardResolveNpcLoose($try) : null;
                    if ($row && !empty($row['npc_name'])) {
                        $found = strval($row['npc_name']);
                        break;
                    }
                }
            }
            if ($found === '') {
                return null;
            }
            $targets[$found] = true;
        }
        if (!$targets || count($targets) > 4) {
            return null;
        }
        return ['kind' => $kind, 'targets' => array_keys($targets)];
    }

    /** One console sequence into the game (same channel as tes_crime). */
    function tesWorldQueue(array $commands): bool
    {
        $db = $GLOBALS['db'];
        $quest = $db->fetchOne("SELECT quest_key FROM public.skyrim_quest_instances ORDER BY quest_key LIMIT 1");
        if (empty($quest['quest_key'])) {
            return false;
        }
        $db->insert('skyrim_quest_action_outbox', [
            'quest_key' => $quest['quest_key'], 'beat_id' => 'tes_world', 'action_type' => 'console_command_sequence',
            'payload_json' => json_encode(['type' => 'console_command_sequence', 'commands' => $commands]),
        ]);
        return true;
    }

    function tesWorldRefOf(string $name): string
    {
        $db = $GLOBALS['db'];
        $row = $db->fetchOne("SELECT refid FROM public.core_npc_master WHERE npc_name = '" . $db->escape(trim($name)) . "' LIMIT 1");
        if (empty($row['refid']) && function_exists('tesGodGuardResolveNpcLoose') && class_exists('RelationshipManager')) {
            $row = tesGodGuardResolveNpcLoose($name);
        }
        $ref = strtoupper(trim(strval($row['refid'] ?? '')));
        return preg_match('/^[0-9A-F]{8}$/', $ref) ? $ref : '';
    }

    /**
     * An execution is a fight, not a switch (owner, 2026-10-04: "казнь - реальная драка и убить").
     * The executioner attacks the condemned (bridge tesduel takes "essential" off first); if the
     * condemned is still alive 45 s later, tesWorldDuelTick() ends it. Returns false when there is
     * nobody to do it - the caller then kills at once.
     */
    function tesWorldDuel(string $executioner, string $victim): bool
    {
        $ex = tesWorldRefOf($executioner);
        $vi = tesWorldRefOf($victim);
        if ($ex === '' || $vi === '' || $ex === $vi) {
            return false;
        }
        $db = $GLOBALS['db'];
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_world_duels (id bigserial PRIMARY KEY, created_at timestamptz NOT NULL DEFAULT now(),
            victim text NOT NULL, victim_ref text NOT NULL, executioner_ref text NOT NULL, done boolean NOT NULL DEFAULT false)");
        $again = $db->fetchOne("SELECT 1 AS x FROM public.tes_world_duels WHERE victim_ref = '{$vi}' AND NOT done LIMIT 1");
        if (!empty($again)) {
            return true;
        }
        if (!tesWorldQueue(['prid ' . $vi, 'setrestrained 0', 'prid ' . $ex, 'tesduel ' . hexdec($vi), 'startcombat ' . $vi])) {
            return false;
        }
        $db->insert('tes_world_duels', ['victim' => $victim, 'victim_ref' => $vi, 'executioner_ref' => $ex]);
        return true;
    }

    function tesWorldDuelTick(): void
    {
        $db = $GLOBALS['db'];
        $has = $db->fetchOne("SELECT to_regclass('public.tes_world_duels') AS t");
        if (empty($has['t'])) {
            return;
        }
        $rows = $db->fetchAll("SELECT * FROM public.tes_world_duels WHERE NOT done AND created_at < now() - interval '45 seconds' ORDER BY id LIMIT 5");
        foreach (is_array($rows) ? $rows : [] as $r) {
            $db->execQuery("UPDATE public.tes_world_duels SET done = true WHERE id = " . intval($r['id']));
            $dead = $db->fetchOne("SELECT metadata->'activity_status'->>'is_dead' AS d FROM public.core_npc_master WHERE upper(refid) = '" . $db->escape($r['victim_ref']) . "' LIMIT 1");
            tesWorldQueue(['prid ' . $r['executioner_ref'], 'stopcombat']);
            if (strval($dead['d'] ?? '') !== 'true') {
                tesWorldQueue(['prid ' . $r['victim_ref'], 'teskill', 'kill']);
                error_log("[tes_world] execution of {$r['victim']}: the fight did not end it in 45 s - finished");
            }
        }
    }

    /** "полмиллиона", "сто тысяч", "500 000", "2 миллиона" -> number, 0 when there is none. */
    function tesWorldSpokenAmount(string $text): int
    {
        $t = ' ' . str_replace('ё', 'е', mb_strtolower($text)) . ' ';
        if (preg_match('/пол\s*-?\s*(миллиона|ляма|лимона|млн)/u', $t)) {
            return 500000;
        }
        if (preg_match('/полтора\s+(миллиона|ляма|лимона)/u', $t)) {
            return 1500000;
        }
        if (preg_match('/пол\s*-?\s*тысячи/u', $t)) {
            return 500;
        }
        $mult = function (string $unit): int {
            if (preg_match('/^(миллиард|млрд)/u', $unit)) {
                return 1000000000;
            }
            return preg_match('/^(миллион|млн|лям|лимон)/u', $unit) ? 1000000 : (preg_match('/^(тысяч|тыщ|штук|косар|к$)/u', $unit) ? 1000 : 1);
        };
        if (preg_match('/(\d[\d\s]{0,12}\d|\d)\s*(миллиард\p{L}*|млрд|миллион\p{L}*|млн|лям\p{L}*|лимон\p{L}*|тысяч\p{L}*|тыщ\p{L}*)?/u', $t, $m)) {
            return intval(preg_replace('/\s+/u', '', $m[1])) * $mult(strval($m[2] ?? ''));
        }
        $words = ['один' => 1, 'одну' => 1, 'два' => 2, 'две' => 2, 'три' => 3, 'четыре' => 4, 'пять' => 5, 'шесть' => 6, 'семь' => 7, 'восемь' => 8,
            'девять' => 9, 'десять' => 10, 'двадцать' => 20, 'тридцать' => 30, 'сорок' => 40, 'пятьдесят' => 50, 'сто' => 100, 'сотню' => 100,
            'двести' => 200, 'триста' => 300, 'пятьсот' => 500];
        foreach ($words as $w => $n) {
            if (preg_match('/[^\p{L}]' . $w . '\s+(миллион\p{L}*|лям\p{L}*|лимон\p{L}*|тысяч\p{L}*|тыщ\p{L}*)/u', $t, $m)) {
                return $n * $mult($m[1]);
            }
        }
        if (preg_match('/[^\p{L}](миллион|лям|лимон)[^\p{L}]/u', $t)) {
            return 1000000;
        }
        if (preg_match('/[^\p{L}](тысячу|тыщу|косарь)[^\p{L}]/u', $t)) {
            return 1000;
        }
        foreach ($words as $w => $n) {
            if (preg_match('/[^\p{L}]' . $w . '\s+(септим|золот|монет)/u', $t)) {
                return $n;
            }
        }
        return 0;
    }

    /** Standing laws of the ruler (kept with the titles: every character sees them as facts). */
    function tesWorldLaws(): array
    {
        $out = [];
        foreach (tesWorldFacts() as $key => $fact) {
            if (strpos($key, 'law_') === 0) {
                $out[$key] = $fact;
            }
        }
        return $out;
    }

    function tesWorldAddLaw(string $law): void
    {
        tesWorldEnsureTable();
        $db = $GLOBALS['db'];
        $law = trim(str_replace(["\n", "\r"], ' ', $law));
        foreach (tesWorldLaws() as $fact) {
            similar_text(mb_strtolower($fact), mb_strtolower($law), $pct);
            if ($pct >= 60) {
                return;
            }
        }
        $now = intval($GLOBALS['gameRequest'][2] ?? 0);
        $db->execQuery("INSERT INTO public.tes_world_titles (key, fact, gamets) VALUES ('law_" . substr(md5($law), 0, 8) . "', '"
            . $db->escape('Закон правителя (стража следит за исполнением и принуждает нарушителей): ' . mb_substr($law, 0, 400)) . "', {$now}) ON CONFLICT (key) DO NOTHING");
    }

    function tesWorldClearLaws(): int
    {
        $n = count(tesWorldLaws());
        $GLOBALS['db']->execQuery("DELETE FROM public.tes_world_titles WHERE key LIKE 'law\\_%'");
        return $n;
    }

    function tesWorldIsChild(string $name): bool
    {
        $db = $GLOBALS['db'];
        $row = $db->fetchOne("SELECT race FROM public.core_npc_master WHERE npc_name = '" . $db->escape(trim($name)) . "' LIMIT 1");
        return (bool)preg_match('/реб[её]нок|child/iu', strval($row['race'] ?? ''));
    }

    /** Full name of a known person for a spoken word ("Фаренгара" -> "Фаренгар Тайный Огонь"), or ''. */
    function tesWorldKnownName(string $word): string
    {
        static $all = null;
        if ($all === null) {
            $rows = $GLOBALS['db']->fetchAll("SELECT npc_name FROM public.core_npc_master WHERE refid ~ '^[0-9A-Fa-f]{8}$'");
            $all = array_map(fn($r) => strval($r['npc_name']), is_array($rows) ? $rows : []);
        }
        if (!preg_match('/^\p{Lu}/u', $word) || mb_strlen($word) < 5) {
            return '';
        }
        $want = tesWorldNorm($word);
        foreach ($all as $name) {
            $first = tesWorldNorm(tesWorldShortName($name));
            if (mb_strlen($first) < 5 || mb_strpos($first, ' ') !== false) {
                continue;
            }
            // the name itself or the name with a case ending
            $stem = mb_substr($first, 0, mb_strlen($first) - (preg_match('/[аяйь]$/u', $first) ? 1 : 0));
            if ($want === $first || (mb_strpos($want, $stem) === 0 && mb_strlen($want) - mb_strlen($stem) <= 2)) {
                return $name;
            }
        }
        return '';
    }

    /**
     * The order in the ruler's own words, when it is one of the plain kinds and people are named:
     * ['kind' => bring|strip|kill|jail, 'targets' => [...]] or null. The NPC's model does not
     * always pass an order on (live 2026-10-04 13:30-13:33: "Кадорд, исполнять приказ" four times,
     * nothing happened) - what the ruler plainly said is done whether the NPC called the action or not.
     */
    function tesWorldSpokenOrder(string $line, string $addressee): ?array
    {
        $t = ' ' . str_replace('ё', 'е', $line) . ' ';
        $verbs = [
            'kill' => '(казни\p{L}*|убей\p{L}*|убить|убейте|прикончи\p{L}*)',
            'jail' => '(посади\p{L}*|сади|садите|сажай\p{L}*|арестуй\p{L}*|арестовать|в\s+тюрьму|в\s+темницу|за\s+решетку)',
            'strip' => '(раздень\p{L}*|раздевай|раздевайте|раздеть|сорви\p{L}*|срывай\p{L}*|сорвать\s+одежд\p{L}*|снимай\s+с|сними\s+с|снять\s+одежду\s+с)',
            'bring' => '(приведи\p{L}*|привести|позови\p{L}*|подай\p{L}*|притащи\p{L}*|доставь\p{L}*)',
        ];
        $kind = '';
        foreach ($verbs as $k => $re) {
            if (preg_match('/(?<![\p{L}])' . $re . '(?![\p{L}])/iu', $t, $m, PREG_OFFSET_CAPTURE)) {
                // "не убивай", "не надо сажать"
                $before = mb_strtolower(substr($t, max(0, $m[0][1] - 24), min(24, $m[0][1])));
                if (preg_match('/(?<![\p{L}])не(\s+\p{L}+)?\s*$/u', $before)) {
                    continue;
                }
                $kind = $k;
                break;
            }
        }
        $self = (bool)preg_match('/(?<![\p{L}])(раздевайся|раздевайтесь|разденься|снимай\s+с\s+себя|сними\s+с\s+себя|снять\s+с\s+себя|скидывай\s+одежду)(?![\p{L}])/iu', $t);
        if ($kind === '' && !$self) {
            return null;
        }
        if (preg_match('/(?<![\p{L}])(всех|каждого|каждую|если|когда)(?![\p{L}])|(?<![\p{L}])все\s+(женщин|мужчин|страж|люд|жител)/iu', $t)) {
            return null;  // a law or a condition - the agent's business
        }
        $near = tesWorldNearbyNames(30);
        $targets = [];
        foreach (preg_split('/[^\p{L}\-]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) as $i => $word) {
            $hit = tesWorldHeardName($word, $near);
            if ($hit === '' && $i > 0) {
                $hit = tesWorldKnownName($word);
            }
            if ($hit !== '' && $hit !== $addressee && tesWorldNorm($hit) !== tesWorldNorm(strval($GLOBALS['PLAYER_NAME'] ?? ''))) {
                $targets[$hit] = true;
            }
        }
        if (!$targets && $self && $addressee !== '') {
            return ['kind' => 'strip', 'targets' => [$addressee]];
        }
        if ($kind === '' || !$targets || count($targets) > 4) {
            return null;
        }
        return ['kind' => $kind, 'targets' => array_keys($targets)];
    }

    /**
     * Carry out a plain order now. $by = who was told (he does it if he is a man-at-arms, otherwise
     * the nearest guard). One and the same order is done once in 90 s, whichever way it came -
     * from the ruler's words or from the NPC's Carry_Out_Order. Returns what was done, for the log.
     */
    function tesWorldRunFast(array $fast, string $by, string $order): string
    {
        $db = $GLOBALS['db'];
        $done = [];
        if (!class_exists('RelationshipManager') && is_readable('/var/www/html/HerikaServer/lib/relationship_manager.php')) {
            require_once '/var/www/html/HerikaServer/lib/relationship_manager.php';
        }
        $crime = __DIR__ . '/../tes_crime/lib.php';
        if (!function_exists('tesCrimeJail') && is_readable($crime)) {
            require_once $crime;
        }
        $said = strval($GLOBALS['gameRequest'][3] ?? '') . ' ' . $order;
        foreach ($fast['targets'] as $who) {
            $key = $fast['kind'] . ': ' . $who;
            $once = $db->fetchOne("SELECT 1 AS x FROM public.tes_agent_tasks WHERE created_at > now() - interval '90 seconds' AND status = 'fast' AND goal = '" . $db->escape($key) . "' LIMIT 1");
            $ref = tesWorldRefOf($who);
            if (!empty($once) || $ref === '') {
                continue;
            }
            $db->execQuery("INSERT INTO public.tes_agent_tasks (goal, status, result) VALUES ('" . $db->escape($key) . "', 'fast', '" . $db->escape(mb_substr("через {$by}: {$order}", 0, 300)) . "')");
            $guard = '';
            if (function_exists('tesCrimeNearestGuard')) {
                $guard = (preg_match('/стражник|командир|хускарл/iu', $by) && $by !== $who) ? $by : tesCrimeNearestGuard($who);
            }
            if ($fast['kind'] === 'strip') {
                if (tesWorldIsChild($who)) {
                    $done[] = "{$who}: ребёнок — не раздевают";
                    continue;
                }
                tesWorldQueue(['prid ' . $ref, 'unequipall']);
            } elseif ($fast['kind'] === 'bring') {
                if (function_exists('tesCrimeIsJailed') && tesCrimeIsJailed($who)) {
                    $done[] = "{$who}: сидит в темнице — его не приводят, а выпускают";
                    continue;
                }
                tesWorldQueue(['prid ' . $ref, 'moveto player']);
            } elseif ($fast['kind'] === 'take') {
                if (tesWorldIsChild($who)) {
                    continue;
                }
                tesWorldQueue(['prid ' . $ref, 'tesgive all']);
            } elseif ($fast['kind'] === 'kill') {
                if ($guard === '' || !tesWorldDuel($guard, $who)) {
                    tesWorldQueue(['prid ' . $ref, 'teskill', 'kill']);
                }
            } elseif ($fast['kind'] === 'jail' && function_exists('tesCrimeJail')) {
                $days = function_exists('tesCrimeNumberNear') ? tesCrimeNumberNear($said, '(?:сут|дн|день|дня)') : 0;
                $years = function_exists('tesCrimeNumberNear') ? tesCrimeNumberNear($said, '(?:год|лет)') : 0;
                tesCrimeJail($who, $ref, "арестован по приказу правителя через {$by}", max(1, min(365, $years > 0 ? 365 : ($days ?: 1))), $guard);
            } else {
                continue;
            }
            $done[] = $key;
        }
        return implode('; ', $done);
    }

    /**
     * OStim action / scene tags for what was asked. $what is a MinAI command name
     * ("ExtCmdStartBlowjob") or free words, Russian or English ("минет", "сзади", "anal").
     * '' = no particular kind (OStim's own start). The pairs follow MinAI's own table.
     */
    function tesWorldLoveTags(string $what): string
    {
        $w = mb_strtolower(str_replace('ё', 'е', $what));
        $map = [
            'vaginalsex' => 'startvaginal|vaginal|вагин|в киск|в пизд|трах|ебат|ебл|секс',
            'analsex' => 'startanal|anal|анал|в зад|в жоп|в поп',
            'blowjob' => 'startblowjob|blowjob|минет|отсос|соси|сосат|в рот',
            'deepthroat' => 'deepthroat|глубок\\w* (минет|глотк)|в горло|в глотку',
            'handjob' => 'handjob|рукой|дроч|подроч',
            'footjob' => 'footjob|ногами|ступн',
            'boobjob' => 'boobjob|между груд|сиськами|грудью',
            'cunnilingus,lickingvagina' => 'cunnilingus|кунилинг|куннилинг|лиз|вылиж',
            'vaginalfingering' => 'fingering|пальц',
            'rimjob' => 'rimjob|римминг|анилингус',
            'facial' => 'facial|на лицо',
            'cumonchest' => 'cumonchest|chestcum|на грудь',
            'rubbingclitoris' => 'rubbingclitoris|клитор',
            'missionary' => 'missionary|миссионер|сверху на ней|лицом к лицу',
            'reversecowgirl' => 'reversecowgirl|обратн\\w* наездниц',
            'cowgirl' => 'cowgirl|наездниц|верхом|сядь на',
            'doggystyle' => 'doggystyle|раком|сзади|по-собачьи|на четвереньк',
            'facesitting' => 'facesitting|на лицо сяд|сядь на лицо',
            'sixtynine,69' => 'start69|sixtynine|69|шестьдесят девять',
            'grindingpenis,buttjob' => 'grinding|buttjob|потрис|трись',
            'thighjob' => 'thighjob|между б[её]дер|б[её]драми',
            'cuddling' => 'cuddle|hugging|обним',
            'frenchkissing' => 'kissing|поцел|целуй|целов',
        ];
        // the more specific kinds are listed so that they win over plain "sex"
        $order = ['deepthroat', 'reversecowgirl', 'facesitting', 'sixtynine,69', 'analsex', 'blowjob', 'handjob', 'footjob', 'boobjob',
            'cunnilingus,lickingvagina', 'vaginalfingering', 'rimjob', 'facial', 'cumonchest', 'rubbingclitoris', 'missionary',
            'cowgirl', 'doggystyle', 'grindingpenis,buttjob', 'thighjob', 'cuddling', 'frenchkissing', 'vaginalsex'];
        foreach ($order as $tags) {
            if (preg_match('/(' . $map[$tags] . ')/u', $w)) {
                return $tags;
            }
        }
        return '';
    }

    /** Set (or clear) the player's title. Returns [ok, message]. */
    function tesWorldSetTitle(string $title): array
    {
        tesWorldEnsureTable();
        $db = $GLOBALS['db'];
        $player = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
        $title = trim(str_replace(["\n", "\r", ';'], [' ', ' ', ','], $title));
        if ($title === '' || preg_match('/^(нет|никто|none|clear|снять)$/iu', $title)) {
            $db->execQuery("DELETE FROM public.tes_world_titles WHERE key = 'player_title'");
            return [true, "{$player}: титул снят"];
        }
        $now = intval($GLOBALS['gameRequest'][2] ?? 0);
        if ($now <= 0) {
            $row = $db->fetchOne("SELECT max(gamets) AS g FROM eventlog WHERE localts > " . (time() - 900));
            $now = intval($row['g'] ?? 0);
        }
        $fact = "{$player} — {$title}. Это признано и известно всем: стража, двор и жители подчиняются ему как носителю этого титула, "
            . 'обращаются к нему соответственно, его слово в делах этого титула — приказ. Прежний носитель титула власти больше не имеет. '
            . 'Его приказы исполняют, а не обсуждают: кто виновен и что справедливо, решает он; отказ, спор о законности или нравоучение в ответ на приказ — неповиновение.';
        $db->execQuery("INSERT INTO public.tes_world_titles (key, fact, gamets) VALUES ('player_title', '" . $db->escape($fact) . "', {$now})
            ON CONFLICT (key) DO UPDATE SET fact = EXCLUDED.fact, gamets = EXCLUDED.gamets, created_at = now()");
        $extra = '';
        if (function_exists('tesGodGuardAddRumor')) {
            $hold = tesGodGuardAddRumor("{$player} теперь {$title}. Это объявлено во всеуслышание.");
            $extra = "; по холду {$hold} пошла весть";
        }
        return [true, "{$player} теперь {$title} — это знают все персонажи{$extra}"];
    }
}
