<?php
/*
 * tes_world: errands - "иди купи себе богатую одежду" (owner, 2026-10-04 21:47: "команда иди купи себе
 * одежду. он пойдет к торговцу, пусть покупает что-то что я скажу … нарратор пусть тоже умеет").
 *
 * Said to an NPC ("Бренуин, иди купи себе …") or to the Narrator ("пусть Бренуин купит себе …"):
 *  1. what: "богат/роскошн/дорог" - the jarl's set (Богатое одеяние + Сапоги с оковкой, JarlClothesOutfit03);
 *     "красив/нарядн/приличн" or plain "одежду" - FineClothesOutfit01 (Красивая одежда + Красивые сапоги);
 *     otherwise the named thing from the game index ("стальные сапоги");
 *  2. he walks to the hold's clothes seller (bridge tesescort - a travel package, through doors);
 *  3. near him (getdistance < 400) or after 3 minutes (then moved there): pays with his OWN gold (the
 *     trader gets it), the things go into his pack and are put on; a set becomes his default outfit;
 *  4. tesescort 0 - back to his own life.
 */

if (!function_exists('tesErrandSpoken')) {
    function tesErrandEnsure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $GLOBALS['db']->execQuery("CREATE TABLE IF NOT EXISTS public.tes_errands (id serial PRIMARY KEY, npc text NOT NULL, ref text NOT NULL,
            trader text NOT NULL DEFAULT '', trader_ref text NOT NULL DEFAULT '', items text NOT NULL, outfit text NOT NULL DEFAULT '',
            what text NOT NULL DEFAULT '', price int NOT NULL DEFAULT 0, stage text NOT NULL DEFAULT 'walk', last_id bigint NOT NULL DEFAULT 0,
            asked_at timestamptz, created_at timestamptz NOT NULL DEFAULT now())");
    }

    /** The clothes seller of the hold the player is in (by name, only if the game knows him). */
    function tesErrandTrader(): array
    {
        $hold = mb_strtolower(function_exists('tesWorldCurrentHold') ? tesWorldCurrentHold() : '');
        $byHold = [
            'вайтран' => ['Белетор'],
            'хаафингар' => ['Тааринэ', 'Эндари', 'Белетор'],
            'рифт' => ['Бирна', 'Белетор'],
            'предел' => ['Ленор', 'Белетор'],
            'истмарк' => ['Ревин Садри', 'Белетор'],
        ];
        $cands = [];
        foreach ($byHold as $h => $names) {
            if ($hold !== '' && mb_strpos($hold, mb_substr($h, 0, 5)) === 0) {
                $cands = $names;
            }
        }
        $cands = array_merge($cands, function_exists('tesRealmTraders') ? tesRealmTraders() : [], ['Белетор']);
        foreach ($cands as $name) {
            $ref = tesWorldRefOf($name);
            if ($ref !== '') {
                return [$name, $ref];
            }
        }
        return ['', ''];
    }

    /** What to buy from the words: [items (hex FormIDs), outfit (hex or ''), what, price]. */
    function tesErrandGoods(string $t): array
    {
        if (preg_match('/(богат\p{L}*|роскошн\p{L}*|дорог\p{L}*|знатн\p{L}*|как\s+(?:ярл|дворян)\p{L}*)/u', $t)) {
            return [['000CEE76', '000CEE78'], '000DAB7A', 'богатое одеяние и сапоги с оковкой', 600];
        }
        // a named thing: the words after "купи (себе)", longest phrase first, clothing or armour from the index
        if (preg_match('/куп\p{L}*\s+(?:себе\s+)?((?:\p{L}+\s*){1,4})/u', $t, $m)) {
            $words = array_values(array_filter(preg_split('/\s+/u', trim($m[1])), fn($w) => mb_strlen($w) > 2 && !in_array($w, ['одежду', 'одежда', 'что-нибудь', 'нибудь', 'новую', 'новые', 'новый', 'себе'], true)));
            for ($n = count($words); $n >= 1; $n--) {
                $stem = implode(' ', array_map(fn($w) => mb_substr($w, 0, max(4, mb_strlen($w) - 2)), array_slice($words, 0, $n)));
                if ($stem === '') {
                    continue;
                }
                $like = '%' . str_replace(' ', '%', $stem) . '%';
                $row = $GLOBALS['db']->fetchOne("SELECT formid, name, (extra->>'value')::int AS v FROM public.tes_game_index WHERE kind = 'item' AND extra->>'rec' = 'ARMO'"
                    . " AND name_lc LIKE '" . $GLOBALS['db']->escape($like) . "' AND coalesce((extra->>'value')::int, 0) > 0 ORDER BY (extra->>'value')::int LIMIT 1");
                if (!empty($row['formid'])) {
                    return [[strtoupper(strval($row['formid']))], '', strval($row['name']), max(10, intval($row['v']) * 2)];
                }
            }
        }
        return [['00086991', '00086993'], '0009D5E0', 'красивая одежда и сапоги', 250];
    }

    /**
     * "иди купи себе …" to $to, or "пусть X купит себе …" to the Narrator ($to = '' or 'The Narrator').
     * Returns a note for the prompt, or ''.
     */
    function tesErrandSpoken(string $line, string $to): string
    {
        $t = mb_strtolower(str_replace('ё', 'е', $line));
        if (!preg_match('/(?<![\p{L}])(куп(?:и|ите|ит|ил)|покупай|прикупи|закупи)(?![\p{L}])/u', $t) || preg_match('/(?<![\p{L}])не\s+(?:\p{L}+\s+)?куп/u', $t)) {
            return '';
        }
        // only clothes and armour: "купи мне меч" is not this errand
        if (!preg_match('/(одеж\p{L}*|наряд\p{L}*|сапог\p{L}*|ботин\p{L}*|туфл\p{L}*|рубах\p{L}*|плать\p{L}*|шуб\p{L}*|плащ\p{L}*|шап\p{L}*|капюшон\p{L}*|перчат\p{L}*|брон\p{L}*|доспех\p{L}*|кирас\p{L}*|шлем\p{L}*|одеяни\p{L}*|мант\p{L}*|робу|роба)/u', $t)) {
            return '';
        }
        $who = (stripos($to, 'Narrator') === false) ? trim($to) : '';
        // a named person in the line wins ("пусть Бренуин купит", "Балгруф, купи Бренуину")
        $near = tesWorldNearbyNames(30);
        foreach (preg_split('/[^\p{L}\-]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) as $i => $word) {
            $hit = tesWorldHeardName($word, $near) ?: ($i > 0 ? tesWorldKnownName($word) : '');
            if ($hit !== '' && $hit !== $who && tesWorldNorm($hit) !== tesWorldNorm(strval($GLOBALS['PLAYER_NAME'] ?? ''))) {
                $who = $hit;
                break;
            }
        }
        if ($who === '' || tesWorldIsChild($who)) {
            return '';
        }
        $ref = tesWorldRefOf($who);
        if ($ref === '') {
            return '';
        }
        tesErrandEnsure();
        $db = $GLOBALS['db'];
        $busy = $db->fetchOne("SELECT 1 AS x FROM public.tes_errands WHERE npc = '" . $db->escape($who) . "' AND stage <> 'done' AND created_at > now() - interval '10 minutes'");
        if (!empty($busy)) {
            return " *{$who} уже идёт за покупкой*";
        }
        [$items, $outfit, $what, $price] = tesErrandGoods($t);
        [$trader, $traderRef] = tesErrandTrader();
        $db->execQuery("INSERT INTO public.tes_errands (npc, ref, trader, trader_ref, items, outfit, what, price, stage) VALUES ('"
            . $db->escape($who) . "', '{$ref}', '" . $db->escape($trader) . "', '{$traderRef}', '" . implode(',', $items) . "', '{$outfit}', '"
            . $db->escape($what) . "', {$price}, '" . ($traderRef !== '' ? 'walk' : 'buy') . "')");
        if ($traderRef !== '') {
            tesWorldQueue(['prid ' . $ref, 'teshold 0', 'tesescort ' . hexdec($traderRef)]);
        }
        error_log("[tes_world] errand: {$who} goes to buy {$what} from " . ($trader ?: 'nobody') . " ({$price})");
        return " *{$who} " . ($trader !== '' ? "идёт к торговцу ({$trader})" : 'идёт') . " покупать себе {$what} на свои деньги — это уже происходит*";
    }

    /** On every request: walking errands - near the trader (or 3 minutes gone) -> the purchase. */
    function tesErrandTick(): void
    {
        tesErrandEnsure();
        $db = $GLOBALS['db'];
        $rows = $db->fetchAll("SELECT *, extract(epoch FROM now() - created_at)::int AS age, extract(epoch FROM now() - coalesce(asked_at, created_at))::int AS asked_age"
            . " FROM public.tes_errands WHERE stage IN ('walk', 'buy') AND created_at > now() - interval '15 minutes' ORDER BY id LIMIT 3");
        foreach (is_array($rows) ? $rows : [] as $e) {
            $id = intval($e['id']);
            $buy = $e['stage'] === 'buy';
            if (!$buy) {
                // the latest distance answer to this trader after the last question
                $log = $db->fetchAll("SELECT command, output FROM public.tes_god_console_log WHERE id > " . intval($e['last_id']) . " ORDER BY id LIMIT 80");
                $seen = false;
                foreach (is_array($log) ? $log : [] as $l) {
                    $cmd = strtolower(trim(strval($l['command'])));
                    if ($cmd === 'prid ' . strtolower(strval($e['ref']))) {
                        $seen = true;
                    } elseif ($seen && strpos($cmd, 'getdistance') === 0 && preg_match('/GetDistance >> ([0-9.]+)/', strval($l['output']), $dm)) {
                        $buy = floatval($dm[1]) < 400.0;
                    }
                }
                if (!$buy && intval($e['age']) >= 180) {
                    tesWorldQueue(['prid ' . $e['ref'], 'moveto ' . $e['trader_ref']]);  // stuck on the way: there he is
                    $buy = true;
                }
                if (!$buy) {
                    if (intval($e['age']) >= 15 && intval($e['asked_age']) >= 12) {
                        $max = $db->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_god_console_log");
                        tesWorldQueue(['prid ' . $e['ref'], 'getdistance ' . $e['trader_ref']]);
                        $db->execQuery("UPDATE public.tes_errands SET asked_at = now(), last_id = " . intval($max['m'] ?? 0) . " WHERE id = {$id}");
                    }
                    continue;
                }
            }
            // the purchase: his gold to the trader, the things to him, put on; a set becomes his default outfit
            $cmds = ['prid ' . $e['ref'], 'removeitem 0000000F ' . intval($e['price'])];
            foreach (array_filter(explode(',', strval($e['items']))) as $item) {
                $cmds[] = 'additem ' . $item . ' 1';
                $cmds[] = 'equipitem ' . $item;
            }
            if ($e['outfit'] !== '') {
                $cmds[] = 'tesoutfit ' . hexdec($e['outfit']);
            }
            if ($e['trader_ref'] !== '') {
                $cmds[] = 'tesescort 0';
                $cmds[] = 'prid ' . $e['trader_ref'];
                $cmds[] = 'additem 0000000F ' . intval($e['price']);
            }
            tesWorldQueue($cmds);
            $db->execQuery("UPDATE public.tes_errands SET stage = 'done' WHERE id = {$id}");
            if (function_exists('tesWatchNotify')) {
                tesWatchNotify("{$e['npc']} купил себе: {$e['what']} ({$e['price']} зол.)");
            }
            error_log("[tes_world] errand done: {$e['npc']} bought {$e['what']}");
        }
    }
}
