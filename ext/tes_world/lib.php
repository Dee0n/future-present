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
        // The ruler's title and laws hold in HIS hold only (owner, 17:25: "в Солитьюде законы Вайтрана, утечка
        // контекста"): outside it nobody knows him as a jarl, no law is in force, no order is "from the ruler".
        $hold = tesWorldCurrentHold();
        $title = strval($out['player_title'] ?? '');
        if ($hold !== '' && $title !== '' && preg_match('/Ярл\s+(\p{L}+)/u', $title, $tm)) {
            $ruled = mb_strtolower($tm[1]);
            $here = mb_strtolower($hold);
            $same = mb_strpos($ruled, mb_substr($here, 0, 5)) === 0 || mb_strpos($here, mb_substr($ruled, 0, 5)) === 0;
            if (!$same) {
                foreach (array_keys($out) as $k) {
                    if ($k === 'player_title' || strpos($k, 'law_') === 0) {
                        unset($out[$k]);
                    }
                }
            }
        }
        return $out;
    }

    /** The hold the player is in now ("Хаафингар"), from the game's last request line; '' when unknown. */
    function tesWorldCurrentHold(): string
    {
        static $cache = null;
        if ($cache !== null && $cache[0] === time()) {
            return $cache[1];
        }
        $row = $GLOBALS['db']->fetchOne("SELECT data FROM eventlog WHERE type = 'request' AND data LIKE '%Hold:%' AND localts > " . (time() - 900) . " ORDER BY rowid DESC LIMIT 1");
        $hold = preg_match('/Hold:\s*([^,)]+)/u', strval($row['data'] ?? ''), $m) ? trim($m[1]) : '';
        $cache = [time(), $hold];
        return $hold;
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

    /**
     * Whom a line said to nobody is about: the partner of the scene started in the last
     * 10 minutes if still around, else the only living person near the player; '' otherwise.
     */
    function tesWorldCompanion(): string
    {
        $db = $GLOBALS['db'];
        $row = $db->fetchOne("SELECT data FROM eventlog WHERE type = 'infonpc_close' AND localts > " . (time() - 180) . " ORDER BY rowid DESC LIMIT 1");
        $player = mb_strtolower(strval($GLOBALS['PLAYER_NAME'] ?? ''));
        $alive = [];
        foreach (explode('/', strval($row['data'] ?? '')) as $name) {
            if (preg_match('/\((dead|far away)\)/u', $name)) {
                continue;
            }
            $name = trim(preg_replace('/\s*\([a-z ]+\)\s*/u', ' ', $name) ?? $name);
            if ($name !== '' && mb_strtolower($name) !== $player && mb_strlen($name) <= 60) {
                $alive[$name] = true;
            }
        }
        $last = $db->fetchOne("SELECT goal FROM public.tes_agent_tasks WHERE status = 'fast' AND goal LIKE 'love: %' AND created_at > now() - interval '10 minutes' ORDER BY id DESC LIMIT 1");
        if (preg_match('/^love: (.+) \+ игрок/u', strval($last['goal'] ?? ''), $m) && isset($alive[$m[1]])) {
            return $m[1];
        }
        return count($alive) === 1 ? strval(array_key_first($alive)) : '';
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
                // by the consonants: speech recognition garbles the vowels most ("Ормбьерн", "Оранверн"
                // for Арнбьорн - live 16:53, the court could not find the accused)
                $skelWant = preg_replace('/[аеёиоуыэюяь]/u', '', $want) ?? $want;
                $skelForm = preg_replace('/[аеёиоуыэюяь]/u', '', $form) ?? $form;
                $skel = (mb_strlen($skelForm) >= 4 && mb_strlen($skelWant) >= 3) ? tesWorldLev($skelWant, $skelForm) : 9;
                $ok = $piece
                    || ($capital && ($d <= 1 || ($d === 2 && $len >= 6 && mb_substr($want, 0, 1) === mb_substr($form, 0, 1))))
                    || ($capital && $len >= 6 && $skel <= 1)
                    || (!$capital && $d <= 1 && $len >= 6);
                if ($ok && !$piece && $d > 2) {
                    $d = 2;  // a consonant match is a good one, not a poor one
                }
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
            'strip' => '(?:раздеть|раздень\p{L}*|раздева\p{L}*|срыва\p{L}*\s+(?:одежд\p{L}*\s+)?(?:с|со)(?=\s)|(?:снять|сними\p{L}*|сорвать|сорви\p{L}*|стащить|стащи\p{L}*)\s+(?:всю\s+|вс[её]\s+)?(?:одежду|броню|вещи|наряд)?\s*(?:с|со)(?=\s))',
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
        if ($kind === '') {
            return null;
        }
        // "убить всех жителей", "арестовать всех молодых женщин", "раздеть всех баб" (live 15:41-15:49:
        // five such orders went to the agent and all ran out of steps) - the people around
        if (preg_match('/^(всех|все|всю|каждого|каждую)(?![\p{L}])/iu', trim($rest))) {
            $group = tesWorldGroup($rest, $actor);
            return $group ? ['kind' => $kind, 'targets' => $group] : null;
        }
        if (preg_match('/(?<![\p{L}])(всех|все|всю|каждого|каждую|кого|если|или|пока|потом|затем)(?![\p{L}])/iu', $rest)) {
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

    /**
     * The people an order to "всех …" means: the living around the player (CHIM's last lists of
     * who is near), never the player, the one ordered, guards, the jarl's court or children.
     * "женщин/баб/девушек" - women only, "мужчин/мужиков" - men only. At most 8.
     */
    function tesWorldGroup(string $words, string $actor = ''): array
    {
        $db = $GLOBALS['db'];
        $w = mb_strtolower($words);
        $sex = preg_match('/(женщин|баб|девуш|девок|девиц|дам)/u', $w) ? 'female' : (preg_match('/(мужчин|мужик|парн)/u', $w) ? 'male' : '');
        $player = mb_strtolower(strval($GLOBALS['PLAYER_NAME'] ?? ''));
        $seen = [];
        $dead = [];
        foreach ($db->fetchAll("SELECT data FROM eventlog WHERE type IN ('infonpc', 'infonpc_close') AND localts > " . (time() - 120) . " ORDER BY rowid DESC LIMIT 6") ?: [] as $row) {
            $list = preg_replace('/^.*beings in range:/u', '', strval($row['data'])) ?? '';
            foreach (preg_split('/[,\/]/u', rtrim($list, ')')) as $name) {
                $isDead = mb_strpos($name, '(dead)') !== false;
                if (mb_strpos($name, '(far away)') !== false) {
                    continue;
                }
                $name = trim(preg_replace('/\s*\((?:dead|busy|restrained|[a-z ]+)\)\s*/u', ' ', $name) ?? $name);
                if ($name === '' || mb_strlen($name) > 60) {
                    continue;
                }
                if ($isDead) {
                    $dead[$name] = true;
                } else {
                    $seen[$name] = true;
                }
            }
        }
        $out = [];
        foreach (array_keys($seen) as $name) {
            if (isset($dead[$name]) || mb_strtolower($name) === $player || $name === $actor
                || preg_match('/(Стражник|Стражница|Ярл |Управител|Хускарл|Командир|Придворн)/u', $name) || tesWorldIsChild($name)) {
                continue;
            }
            $row = $db->fetchOne("SELECT gender, occupation FROM core_npc_master WHERE npc_name = '" . $db->escape($name) . "' LIMIT 1");
            if ($sex !== '' && strval($row['gender'] ?? '') !== $sex) {
                continue;
            }
            if (preg_match('/(страж|ярл|хускарл|управител)/iu', strval($row['occupation'] ?? ''))) {
                continue;
            }
            if (tesWorldRefOf($name) !== '') {
                $out[] = $name;
            }
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
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
        // speech recognition often writes a name small ("Подай мне айрилет", live 13:11): a long
        // word is taken as a name too, the match below is still by the name's own stem
        if (mb_strlen($word) < (preg_match('/^\p{Lu}/u', $word) ? 5 : 6)) {
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
            // "оденься (в богатое)", first: live 21:42 "Бренуин, оденься в богатую одежду … ты хули бухаешь на улице,
            // побираешься" sent him to beg - the reproach "побираешься" was read as the order
            'dress' => '(оденься|оденьтесь|одевайся|одевайтесь|одеться|одеваться|приоденься|приоденьтесь|переоденься|переоденьтесь|нарядись|нарядитесь|принаряд\p{L}*|надень\p{L}*|надева\p{L}*|надеть|прикрой\s+(?:свой\s+)?срам|прикройся|одень\s+на\s+себя|облачись|облачитесь|приведи\s+себя\s+в\s+порядок)',
            // "иди на улице подбираться" (live 13:18): lives as a beggar around the market - only the
            // imperative ("побирайся", "иди побирайся"), never "ты побираешься"
            'beg' => '(побирайся|побирайтесь|подбирайся|попрошайничай\p{L}*|(?:иди|идите|ступай|пош[её]л|пошла)\s+(?:\p{L}+\s+){0,3}?(?:побира|подбира|попрошайнича)\p{L}*|(?:проси|просить|клянч\p{L}*)\s+(?:\p{L}+\s+){0,2}?(?:милостын\p{L}*|подаяни\p{L}*|мелочь|деньги)|пош[её]л\s+(?:\p{L}+\s+){0,2}(?:отсюда|вон|прочь)|уходи|уйди|свали\p{L}*|исчезни|пошла\s+(?:\p{L}+\s+){0,2}(?:отсюда|вон|прочь))',
            // "стой", "стоять на месте", "не уходи", "жди здесь": he stays where he is (live 16:57: the accused
            // kept walking off); "свободен", "можешь идти": let go
            'stay' => '(стой(?:те)?(?=[\s!.,?]|$)|стоять|не\s+уходи|не\s+двигайся|не\s+иди|оставайся|оставайтесь|жди\s+(?:здесь|тут|меня)|ждите\s+(?:здесь|тут))',
            'free' => '(свобод(?:ен|на|ны)|можешь\s+идти|можете\s+идти|иди\s+куда\s+хочешь|отпускаю)',
            'face' => '(смотри\s+на\s+меня|(?:повернись|повернитесь|обернись)\s+(?:ко\s+мне|лицом)|лицом\s+ко\s+мне|смотри\s+мне\s+в\s+лицо)',
            'post' => '(?:охраняй\p{L}*(?!\s+(?:меня|мен[яе]))|дежурь\p{L}*|стереги\p{L}*|сторожи\p{L}*|патрулируй\p{L}*|патрулиров\p{L}*|обходи\p{L}*\s+город)',
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
        // "Балгруф, отдай Бренуину свою одежду, а ты ходи в его" / "поменяйтесь одеждой" (live 21:43: the agent
        // undressed both and reported done): the one spoken to and the named one swap what they wear
        // any word order and wording: "поменяйтесь/обменяйтесь/махнитесь/поменяй(ся) … одеждой|нарядами|шмотками",
        // "отдай/дай X свою одежду|наряд", "отдай свою одежду X, а сам ходи в его", "пусть X и Y поменяются одеждой"
        $clothesWord = '(?:одежд\p{L}*|наряд\p{L}*|шмот\p{L}*|тряпк\p{L}*|вещ\p{L}*|облачени\p{L}*|рубах\p{L}*|плать\p{L}*|броню|брон\p{L}*)';
        $swapVerb = '(?:поменя\p{L}*|обменя\p{L}*|махни\p{L}*|махнис\p{L}*|смен\p{L}*|поменя\p{L}*ся|меняйтесь|меняйся|разменя\p{L}*)';
        $giveVerb = '(?:отдай\p{L}*|отдав\p{L}*|дай\p{L}*|подари\p{L}*|передай\p{L}*)';
        if ($addressee !== ''
            && ((preg_match('/(?<![\p{L}])' . $swapVerb . '(?![\p{L}])/iu', $t) && preg_match('/(?<![\p{L}])' . $clothesWord . '(?![\p{L}])/iu', $t))
                || (preg_match('/(?<![\p{L}])' . $giveVerb . '(?![\p{L}])/iu', $t) && preg_match('/(?<![\p{L}])' . $clothesWord . '(?![\p{L}])/iu', $t)
                    && !preg_match('/(?<![\p{L}])(мне|ко\s+мне|для\s+меня)(?![\p{L}])/iu', $t)))
            && !preg_match('/(?<![\p{L}])(куп\p{L}*|продай\p{L}*|принеси\p{L}*|найди\p{L}*)(?![\p{L}])/iu', $t)) {
            $namesNear = tesWorldNearbyNames(30);
            foreach (preg_split('/[^\p{L}\-]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) as $i => $word) {
                $hit = tesWorldHeardName($word, $namesNear) ?: tesWorldKnownName($word);
                if ($hit !== '' && $hit !== $addressee && tesWorldNorm($hit) !== tesWorldNorm(strval($GLOBALS['PLAYER_NAME'] ?? ''))) {
                    return ['kind' => 'swap', 'targets' => [$hit]];
                }
            }
        }
        $self = (bool)preg_match('/(?<![\p{L}])(раздевайся|раздевайтесь|разденься|снимай\s+с\s+себя|сними\s+с\s+себя|снять\s+с\s+себя|скидывай\s+одежду)(?![\p{L}])/iu', $t);
        // "Отдавай мясо мне", "Отдай всё": the one spoken to hands over everything he carries (live
        // 03:14-03:17, 13:23, 18:41: "Но это же все мои запасы!" - and nothing moved). The agent
        // burned 18 steps on the same order and failed.
        if ($kind === '' && !$self && $addressee !== ''
            && preg_match('/(?<![\p{L}])(отдай\p{L}*|отдавай\p{L}*)(?![\p{L}])/iu', $t)
            && preg_match('/(?<![\p{L}])(отдай\p{L}*|отдавай\p{L}*)(?:\s+\p{L}+){0,2}?\s+(все|всё|мясо|деньги|денег|золото|вещи|товар\p{L}*|запасы)(?![\p{L}])/iu', $t)
            && !preg_match('/(?<![\p{L}])(ску[йе]\p{L}*|сковать|сдела\p{L}*|принеси\p{L}*|найди\p{L}*|купи\p{L}*|создай\p{L}*|приготов\p{L}*|добуд\p{L}*|сварить|свари)(?![\p{L}])/iu', $t)
            && !preg_match('/(?<![\p{L}])не\s+(\p{L}+\s+)?(отдавай|отдай)/iu', $t)) {
            // (live 19:09: "скуй мне оружие и отдай мне его" emptied Йорлунд: "отдай мне" alone is not
            // "give everything" - only a named thing in bulk is, and never when something is to be made first)
            return ['kind' => 'take', 'targets' => [$addressee]];
        }
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
        if (!$targets && in_array($kind, ['dress', 'beg', 'post', 'stay', 'free', 'face'], true) && $addressee !== '') {
            return ['kind' => $kind, 'targets' => [$addressee]];
        }
        if (!$targets && $kind !== '') {
            // bare "Раздеть." said to a woman - her; "Казнить её!" - whoever the last such order was about
            // (live 13:12 "Казнить ее!" and 13:19 "раздеть." came to nothing)
            if ($kind === 'strip' && $addressee !== '' && preg_match('/^[\s\p{P}]*(раздеть|раздень|раздевай)[\s\p{P}]*$/iu', $line)) {
                return ['kind' => 'strip', 'targets' => [$addressee]];
            }
            if (preg_match('/(?<![\p{L}])(е[её]|его|их)(?![\p{L}])/iu', $t) && isset($GLOBALS['db'])) {
                $last = $GLOBALS['db']->fetchOne("SELECT goal FROM public.tes_agent_tasks WHERE status = 'fast' AND created_at > now() - interval '10 minutes' AND goal ~ '^(kill|strip|jail|bring|take): ' ORDER BY id DESC LIMIT 1");
                if (!empty($last['goal']) && preg_match('/^\w+: (.+)$/u', strval($last['goal']), $lm)) {
                    return ['kind' => $kind, 'targets' => [trim($lm[1])]];
                }
            }
        }
        if ($kind === '' || !$targets || count($targets) > 4) {
            return null;
        }
        return ['kind' => $kind, 'targets' => array_keys($targets)];
    }

    /** Does the ruler's line read as an order to do something (not a question, not talk)? */
    function tesWorldLooksLikeOrder(string $line): bool
    {
        $t = mb_strtolower(str_replace('ё', 'е', trim($line)));
        if ($t === '' || mb_strlen($t) < 8 || mb_strpos($t, '?') !== false) {
            return false;
        }
        if (preg_match('/(?<![\p{L}])не\s+(\p{L}+\s+)?(надо|нужно|делай|трогай|убивай|сажай|раздевай)/u', $t)) {
            return false;
        }
        return (bool)preg_match('/(?<![\p{L}])(иди|идите|пойди|пойдите|ступай\p{L}*|веди|отведи\p{L}*|проводи\p{L}*|сопроводи\p{L}*|собери\p{L}*|собрать|созови\p{L}*|согнать|согони\p{L}*'
            . '|принеси\p{L}*|отнеси\p{L}*|отдай\p{L}*|отдавай\p{L}*|верни\p{L}*|дай|дайте|передай\p{L}*|найди\p{L}*|сходи|сбегай|отпусти\p{L}*|освободи\p{L}*|накажи\p{L}*|оштрафуй\p{L}*'
            . '|оденься|оденьтесь|одень\p{L}*|одеть|развлекись|развлекайся|развлекайтесь|потрахай\p{L}*|трахни\p{L}*|трахай\p{L}*|выеби\p{L}*|отсоси\p{L}*|поласкай\p{L}*|займись|займитесь'
            . '|следи\p{L}*|охраняй\p{L}*|патрулируй\p{L}*|дежурь\p{L}*|стереги\p{L}*|стой\s+тут|жди\s+здесь|ждите|следуй\p{L}*|охраняйте|заставь\p{L}*|принуди\p{L}*|приказываю|исполняй\p{L}*|исполнять|выполняй\p{L}*'
            . '|выгони\p{L}*|прогони\p{L}*|убери\p{L}*|унеси\p{L}*|открой\p{L}*|закрой\p{L}*|заплати\p{L}*|выплати\p{L}*|купи\p{L}*|продай\p{L}*'
            . '|сделай\p{L}*|поставь\p{L}*|клонируй\p{L}*|создай\p{L}*|измени\p{L}*|поменя\p{L}*|включи\p{L}*|выключи\p{L}*|делай\p{L}*|приступ\p{L}*|пусть|брысь|прочь|вон|пошл\p{L}*|пошё\p{L}*'
            . '|подойди\p{L}*|встань|вставай|ложись|ложитесь|сядь|садись|останови\p{L}*|заморозь\p{L}*|разморозь\p{L}*|вылечи\p{L}*|воскреси\p{L}*|оживи\p{L}*|выпусти\p{L}*|привяжи\p{L}*|свяжи\p{L}*'
            . '|займись|исправ\p{L}*|суй|вставь\p{L}*|засунь\p{L}*|надень\p{L}*|надеть|сними\p{L}*|снимай\p{L}*|раздень\p{L}*|раздеть|раздевай\p{L}*|срывай\p{L}*|срыв\p{L}*|казни\p{L}*|убей\p{L}*|убить)(?![\p{L}])/u', $t);
    }

    /**
     * Hand the ruler's plain words to the goal agent as an order through $actor. Live 2026-10-04
     * (13:26-18:43, dozens of lines): "Торгар, развлекись с Фианной", "Оденься", "Отдай мясо",
     * "Собери всех в таверне" - the NPC said "Как прикажете" and its model called no action, so
     * nothing happened. The agent is started from the words themselves, not from the NPC's choice.
     */
    function tesWorldAgentOrder(string $said, string $actor): string
    {
        if (!function_exists('tesAgentStart') || !function_exists('tesAgentRunningTask')) {
            return '';
        }
        $db = $GLOBALS['db'];
        $facts = tesWorldFacts();
        if (empty($facts['player_title']) || $actor === '' || stripos($actor, 'Narrator') !== false || tesWorldIsChild($actor)) {
            return '';
        }
        $order = trim(preg_replace('/^[^:]{1,40}:\s*/u', '', trim(preg_replace('/\s*\(Talking to [^)]*\)\s*$/u', '', $said) ?? $said)) ?? $said);
        if (!tesWorldLooksLikeOrder($order)) {
            return '';
        }
        // one such start per 25 s, and never the same words twice in 3 minutes
        $recent = $db->fetchOne("SELECT 1 AS x FROM public.tes_agent_tasks WHERE created_at > now() - interval '25 seconds' AND goal LIKE 'Приказ правителя%' LIMIT 1");
        $dup = $db->fetchOne("SELECT 1 AS x FROM public.tes_agent_tasks WHERE created_at > now() - interval '3 minutes' AND goal LIKE '%" . $db->escape(mb_substr($order, 0, 50)) . "%' LIMIT 1");
        if (!empty($recent) || !empty($dup)) {
            return '';
        }
        $player = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
        $title = mb_substr($facts['player_title'], 0, mb_strpos($facts['player_title'] . '.', '.'));
        $around = implode(', ', tesWorldNearbyNames());
        $context = ($around !== '' ? " Рядом сейчас: {$around} — искажённое имя это тот из них, чьё имя ближе по звучанию." : '');
        [$ok] = tesAgentStart("Приказ правителя ({$title}), отданный через {$actor}: {$order}. Дословно ярл сказал (с голоса, имена могут быть исковерканы): «" . mb_substr($order, 0, 300) . "».{$context}"
            . " Правитель — {$player}. Исполнитель {$actor}: исполни приказ ЕГО руками — {npc:{$actor}}.moveto/follow/escort к цели, раздеть — unequipall, одеть — equipitem, секс между двумя — console «{npc:Имя}.teslove <refid партнёра> теги», отдать вещи — tesgive, собрать людей — {npc:Имя}.moveto player (по одному) либо moveto на место. Приказы про тюрьму/штраф — своими инструментами. Делай РОВНО приказанное и ничего сверх. Не выходит с двух попыток — give_up с причиной.", false, false, true);
        return $ok ? 'агент запущен' : '';
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
                tesWorldVerifyAdd('strip', $who, $ref);
            } elseif ($fast['kind'] === 'bring') {
                if (function_exists('tesCrimeIsJailed') && tesCrimeIsJailed($who)) {
                    $done[] = "{$who}: сидит в темнице — его не приводят, а выпускают";
                    continue;
                }
                tesWorldQueue(['prid ' . $ref, 'moveto player']);
                tesWorldVerifyAdd('bring', $who, $ref);
            } elseif ($fast['kind'] === 'dress') {
                // bridge 10 "tesdressbest": the best of his OWN things first; "богато" with nothing rich of his own -
                // Богатое одеяние + Сапоги с оковкой (JarlClothesOutfit03) as his new default outfit
                // "рваный балахон надень", "в лохмотья" - the beggar's rags of RfaD (балахон, сапоги, шапка), what
                // the owner asked for by name (live 21:56-21:58: Балгруф stayed in his own clothes four times)
                if (preg_match('/(рван\p{L}*|лохмот\p{L}*|тряпк\p{L}*|обмотк\p{L}*|нищенск\p{L}*|бомжацк\p{L}*)/iu', $said)) {
                    $rags = ['00013105', '00013106'];
                    if (preg_match('/(шапк\p{L}*|головн\p{L}*)/iu', $said)) {
                        $rags[] = '00013104';
                    }
                    $cmds = ['prid ' . $ref, 'unequipall'];
                    foreach ($rags as $r) {
                        $cmds[] = 'additem ' . $r . ' 1';
                        $cmds[] = 'equipitem ' . $r;
                    }
                    tesWorldQueue($cmds);
                    continue;
                }
                $rich = (bool)preg_match('/(богат\p{L}*|роскошн\p{L}*|дорог\p{L}*|наряд\p{L}*|нарядн\p{L}*|знатн\p{L}*|как\s+(?:ярл|дворян))/iu', $said);
                if (function_exists('tesBridgeVersion') && tesBridgeVersion() >= 10) {
                    tesWorldQueue(['prid ' . $ref, 'tesdressbest ' . ($rich ? 'rich' : 'any')]);
                } elseif ($rich) {
                    tesWorldQueue(['prid ' . $ref, 'unequipall', 'additem 000CEE76 1', 'equipitem 000CEE76', 'additem 000CEE78 1', 'equipitem 000CEE78', 'tesoutfit ' . hexdec('000DAB7A')]);
                } else {
                    tesWorldQueue(['prid ' . $ref, 'tesredress']);
                }
            } elseif ($fast['kind'] === 'swap') {
                // $by and $who swap what they wear (bridge 10 "tesswapworn <other>")
                $byRef = tesWorldRefOf($by);
                if ($byRef === '' || tesWorldIsChild($who) || tesWorldIsChild($by) || !function_exists('tesBridgeVersion') || tesBridgeVersion() < 10) {
                    $done[] = "{$who}: обмен одеждой — нужен мост v10 (перезапуск игры)";
                    continue;
                }
                tesWorldQueue(['prid ' . $byRef, 'tesswapworn ' . hexdec($ref)]);
            } elseif ($fast['kind'] === 'beg') {
                // beggar's life around the market: linked to Бренуин (0002C90F), the town's beggar;
                // undressed too if the ruler said so. Needs the bridge's "tesroutine at" (pex 42272+).
                if (tesWorldIsChild($who)) {
                    continue;
                }
                if (preg_match('/Стражник|Хускарл|Командир/u', $who) && !preg_match('/побира|подбира|милостын|попрошай/iu', $said)) {
                    $done[] = "{$who}: страж — «уйди» не значит нищенствовать";
                    continue;
                }
                if (function_exists('tesBridgeVersion') && tesBridgeVersion() < 2) {
                    $done[] = "{$who}: мост в игре старый — «жить у места» заработает после перезапуска игры";
                    continue;
                }
                $cmds = ['prid ' . $ref];
                if (preg_match('/раздев|голый|голым|догола|нищ/iu', $said)) {
                    $cmds[] = 'unequipall';
                }
                $cmds[] = 'tesroutine at ' . hexdec('0002C90F');
                tesWorldQueue($cmds);
            } elseif ($fast['kind'] === 'face') {
                // turns to the ruler once now; during a trial the court's tick keeps him turned
                if (tesWorldIsChild($who) || !function_exists('tesWorldFacePlayer')) {
                    continue;
                }
                tesWorldFacePlayer($ref);
            } elseif ($fast['kind'] === 'stay' || $fast['kind'] === 'free') {
                // stands where he is (the bridge's hold: sandbox around himself, he does not move) / is let go
                if (tesWorldIsChild($who)) {
                    continue;
                }
                if (function_exists('tesCrimeIsJailed') && tesCrimeIsJailed($who)) {
                    $done[] = "{$who}: в темнице — там он и так на месте";
                    continue;
                }
                tesWorldQueue($fast['kind'] === 'stay'
                    ? ['prid ' . $ref, 'tesfollow 0', 'teshold ' . hexdec($ref)]
                    : ['prid ' . $ref, 'teshold 0', 'tesfollow 0', 'setrestrained 0', 'resetai']);
            } elseif ($fast['kind'] === 'post') {
                // a post or a round: "охраняй здесь" - around where the player stands now (works with
                // every bridge); "патрулируй город" - around the market (Карлотта Валентия's stall)
                if (tesWorldIsChild($who)) {
                    continue;
                }
                if (preg_match('/(здесь|тут|рядом|у\s+меня|у\s+трона|у\s+входа)/iu', $said)) {
                    tesWorldQueue(['prid ' . $ref, 'tesroutine here']);
                } elseif (function_exists('tesBridgeVersion') && tesBridgeVersion() < 2) {
                    $done[] = "{$who}: мост в игре старый — патруль заработает после перезапуска игры";
                    continue;
                } else {
                    tesWorldQueue(['prid ' . $ref, 'tesroutine at ' . hexdec('0001A675')]);
                }
            } elseif ($fast['kind'] === 'take') {
                if (tesWorldIsChild($who)) {
                    continue;
                }
                tesWorldQueue(['prid ' . $ref, 'tesgive all']);
            } elseif ($fast['kind'] === 'kill') {
                // the executioner of the court, when there is one, does it himself
                $exec = function_exists('tesRealmExecutioner') ? tesRealmExecutioner() : '';
                if ($exec !== '' && $exec !== $who) {
                    $guard = $exec;
                }
                if ($guard === '' || !tesWorldDuel($guard, $who)) {
                    tesWorldQueue(['prid ' . $ref, 'teskill', 'kill']);
                    tesWorldVerifyAdd('kill', $who, $ref);
                }
            } elseif ($fast['kind'] === 'jail' && function_exists('tesCrimeJail')) {
                $days = function_exists('tesCrimeTerm') ? tesCrimeTerm($said) : 0;
                tesCrimeJail($who, $ref, "арестован по приказу правителя через {$by}", max(1, min(3650, $days ?: 1)), $guard);
            } else {
                continue;
            }
            // what is done to a person is remembered by him: fear and anger towards the ruler
            if (function_exists('tesLoyaltyBump')) {
                $bump = ['strip' => [2.0, 1.5], 'jail' => [3.0, 3.0], 'kill' => [4.0, 2.0], 'take' => [1.0, 2.5], 'bring' => [0.5, 0.3], 'beg' => [1.5, 3.0]][$fast['kind']] ?? [0.0, 0.0];
                if ($bump[0] > 0) {
                    tesLoyaltyBump($who, $bump[0], $bump[1]);
                }
                if ($by !== '' && $by !== $who && $fast['kind'] !== 'bring') {
                    tesLoyaltyBump($by, 0.3, 0.5);  // the one who carried it out likes it little
                }
            }
            if (function_exists('tesRealmAfterOrder')) {
                tesRealmAfterOrder(strval($fast['kind']), $who, $ref, $by, $said);
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
            // not bare "ебать/еблан" (swearing - "Так ебать, я твой гость" started a scene with Элисиф, live
            // 17:34) and not "страх"; "займись/развлеки" alone is any task ("займись делом")
            'vaginalsex' => 'startvaginal|vaginal|вагин|в киск|в пизд|(?<![\p{L}])(?:трах\p{L}*|потрах\p{L}*|оттрах\p{L}*|выеб\p{L}*|поеб(?:и|у|ем|ём)\p{L}*|еби|ебу|ебём|ебем|ебись)(?![\p{L}])|секс|удовлетвор|ублажа',
            'analsex' => 'startanal|anal|анал|в зад|в жоп|в поп',
            'blowjob' => 'startblowjob|blowjob|минет|отсос|соси|сосат|в рот',
            'deepthroating,blowjob' => 'deepthroat|глубок\\w* (минет|глотк)|в горло|в глотку',
            'handjob' => 'handjob|рукой|дроч|подроч',
            'footjob' => 'footjob|ногами|ступн',
            'boobjob' => 'boobjob|между груд|сиськами|грудью',
            'vulvallicking,vulvaleating,cunnilingus,lickingvagina' => 'cunnilingus|кун+и|кунилинг|куннилинг|лиз|вылиж|вылеж',
            'vaginalfingering' => 'fingering|пальц',
            'rimjob' => 'rimjob|римминг|анилингус',
            'facial' => 'facial|на лицо',
            'cumonchest' => 'cumonchest|chestcum|на грудь',
            'vulvalrubbing,rubbingclitoris' => 'rubbingclitoris|клитор',
            'missionary' => 'missionary|миссионер|сверху на ней|лицом к лицу',
            'reversecowgirl' => 'reversecowgirl|обратн\\w* наездниц',
            'cowgirl' => 'cowgirl|наездниц|верхом|сядь на',
            'doggystyle' => 'doggystyle|раком|сзади|по-собачьи|на четвереньк',
            'facesitting' => 'facesitting|на лицо сяд|сядь на лицо',
            'sixtynine,69' => 'start69|sixtynine|69|шестьдесят девять',
            'grindingpenis,buttjob' => 'grinding|buttjob|потрис|трись',
            'thighjob' => 'thighjob|между б[её]дер|б[её]драми',
            'cuddling,cuddle,hug,hugging' => 'cuddle|hugging|обним',
            'kissing,frenchkissing' => 'kissing|поцел|целуй|целов',
        ];
        // the more specific kinds are listed so that they win over plain "sex"
        $order = ['deepthroating,blowjob', 'reversecowgirl', 'facesitting', 'sixtynine,69', 'analsex', 'blowjob', 'handjob', 'footjob', 'boobjob',
            'vulvallicking,vulvaleating,cunnilingus,lickingvagina', 'vaginalfingering', 'rimjob', 'facial', 'cumonchest', 'vulvalrubbing,rubbingclitoris', 'missionary',
            'cowgirl', 'doggystyle', 'grindingpenis,buttjob', 'thighjob', 'cuddling,cuddle,hug,hugging', 'kissing,frenchkissing', 'vaginalsex'];
        foreach ($order as $tags) {
            // isset: a key renamed in the map only would give an empty pattern, which matches every line
            if (isset($map[$tags]) && preg_match('/(' . $map[$tags] . ')/u', $w)) {
                return $tags;
            }
        }
        return '';
    }

    /**
     * The tags plus up to three concrete scenes of that kind ("tags|Id1,Id2,Id3") - TESLove takes
     * one of them when OStim's lookup by action finds nothing (live 2026-10-04 14:53: "куни"
     * started the plain standing scene and never changed it).
     */
    function tesWorldLoveArg(string $tags): string
    {
        static $scenes = null;
        if ($tags === '') {
            return '';
        }
        $scenes = $scenes ?? (is_file(__DIR__ . '/scenes.php') ? (array)(include __DIR__ . '/scenes.php') : []);
        $ids = $scenes[$tags] ?? [];
        if (!$ids) {
            return $tags;
        }
        shuffle($ids);
        return $tags . '|' . implode(',', array_slice($ids, 0, 3));
    }

    /**
     * The scene this NPC is in with the player right now, in words ("куннилингус"), or ''.
     * From the bridge's reports: "scene started: <NPC> and <player> [...]" in the last 6 minutes
     * with no "scene ended" after it. Live 2026-10-04 15:00: in the middle of the scene Айрилет
     * said "я не трахаюсь с тобой, я стою на посту" - her model knew nothing about it.
     */
    function tesWorldSceneWith(string $npc): string
    {
        $db = $GLOBALS['db'];
        $row = $db->fetchOne("SELECT id, command FROM public.tes_god_console_log WHERE created_at > now() - interval '6 minutes' AND command LIKE 'teslove %'"
            . " AND output LIKE 'scene started: " . $db->escape($npc) . " and %' ORDER BY id DESC LIMIT 1");
        if (empty($row['id'])) {
            return '';
        }
        $ended = $db->fetchOne("SELECT 1 AS x FROM public.tes_god_console_log WHERE id > " . intval($row['id']) . " AND command = 'teslove stop' AND output = 'scene ended' LIMIT 1");
        if (!empty($ended)) {
            return '';
        }
        $kinds = ['vulval' => 'он ласкает тебя языком между ног', 'cunnilingus' => 'он ласкает тебя языком между ног', 'deepthroat' => 'ты берёшь его глубоко в рот',
            'blowjob' => 'ты ласкаешь его ртом', 'handjob' => 'ты ласкаешь его рукой', 'boobjob' => 'ты ласкаешь его грудью', 'analsex' => 'он берёт тебя сзади, в зад',
            'doggystyle' => 'он берёт тебя сзади', 'cowgirl' => 'ты сверху на нём', 'missionary' => 'он на тебе, лицом к лицу', 'kissing' => 'вы целуетесь',
            'cuddling' => 'вы обнимаетесь', 'vaginalsex' => 'он в тебе'];
        foreach ($kinds as $tag => $words) {
            if (strpos(strval($row['command']), $tag) !== false) {
                return $words;
            }
        }
        return 'вы занимаетесь любовью';
    }

    /**
     * The third person named in a line said to $to: one of the people around, not $to and not the
     * player ("Сигрид, трахни Айрилет" - the scene is of those two, the player is not in it;
     * owner, 2026-10-04 15:10). Names come mangled from speech ("рилет"): a word of 4+ letters
     * that is the tail or the head of a name, or one-two letters away from it. '' = nobody.
     */
    function tesWorldThirdPerson(string $line, string $to): string
    {
        $toN = tesWorldNorm(tesWorldShortName($to));
        $playerN = tesWorldNorm(strval($GLOBALS['PLAYER_NAME'] ?? ''));
        $best = '';
        $bestScore = 99;
        foreach (preg_split('/[^\p{L}]+/u', $line) ?: [] as $word) {
            $w = tesWorldNorm($word);
            $len = mb_strlen($w);
            if ($len < 4) {
                continue;
            }
            foreach (tesWorldNearbyNames(30) as $name) {
                $n = tesWorldNorm(tesWorldShortName($name));
                if ($n === '' || $n === $toN || $n === $playerN || mb_strlen($n) < 4) {
                    continue;
                }
                // case endings: compare with the name and with the word cut to the name's length
                $d = min(tesWorldLev($w, $n), tesWorldLev(mb_substr($w, 0, mb_strlen($n)), $n));
                if (mb_strlen($n) > $len && mb_substr($n, -$len) === $w) {
                    $d = 1;  // "рилет" is the tail of "айрилет"
                }
                // the addressee's own mangled name ("Сигрит" for Сигрид) is not a third person
                if ($toN !== '' && min(tesWorldLev($w, $toN), tesWorldLev(mb_substr($w, 0, mb_strlen($toN)), $toN)) <= $d) {
                    continue;
                }
                if ($d <= (mb_strlen($n) >= 6 ? 2 : 1) && $d < $bestScore) {
                    $best = $name;
                    $bestScore = $d;
                }
            }
        }
        return $best;
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
require_once __DIR__ . '/verify.php';
require_once __DIR__ . '/watch.php';
require_once __DIR__ . '/court.php';
require_once __DIR__ . '/realm.php';
require_once __DIR__ . '/talk.php';
require_once __DIR__ . '/errand.php';
