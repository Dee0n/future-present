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
                $d = intdiv(levenshtein($want, $form) + 1, 2);  // Cyrillic letters are two bytes each
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
                    $d = intdiv(levenshtein($joined, $form) + 1, 2);
                    $alone = min(intdiv(levenshtein(tesWorldNorm($m[1]), $form) + 1, 2), intdiv(levenshtein(tesWorldNorm($m[2]), $form) + 1, 2));
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
