<?php
/*
 * tes_world: the living world (roadmap stage H).
 *  - Craftsmen take orders: "Адрианна, скуй мне стальной меч" - the price is paid, ~10 minutes later a courier
 *    brings the thing and a letter from the master.
 *  - Letters: a real readable paper in the player's inventory (tes_god_guard document = CHIM's spawnBook), brought
 *    by a courier: the master's note, a companion who left, a witness who wants silver for silence.
 *  - Places remember: what happened somewhere (a death, a sentence, a feast, a prank of Sanguine) is told to whoever
 *    speaks there later - "это место помнит".
 * No model call is made here.
 */

if (!function_exists('tesWorldLetterTick')) {
    function tesWorldLivingEnsure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = $GLOBALS['db'];
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_world_letters (id serial PRIMARY KEY, due_at timestamptz NOT NULL, sender text NOT NULL, title text NOT NULL,
            body text NOT NULL, items text NOT NULL DEFAULT '', sent boolean NOT NULL DEFAULT false, created_at timestamptz NOT NULL DEFAULT now())");
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_craft_orders (id serial PRIMARY KEY, master text NOT NULL, item text NOT NULL, formid text NOT NULL, price int NOT NULL,
            letter_id int, created_at timestamptz NOT NULL DEFAULT now())");
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_place_memory (id serial PRIMARY KEY, place text NOT NULL, what text NOT NULL, created_at timestamptz NOT NULL DEFAULT now())");
    }

    /** A letter that a courier brings in $minutes (with things, "formid:count,..."). Returns its id. */
    function tesWorldLetter(string $sender, string $title, string $body, int $minutes = 0, string $items = ''): int
    {
        tesWorldLivingEnsure();
        $db = $GLOBALS['db'];
        $r = $db->fetchOne("INSERT INTO public.tes_world_letters (due_at, sender, title, body, items) VALUES (now() + interval '" . max(0, $minutes) . " minutes', '"
            . $db->escape(mb_substr($sender, 0, 80)) . "', '" . $db->escape(mb_substr($title, 0, 60)) . "', '" . $db->escape(mb_substr($body, 0, 1500)) . "', '" . $db->escape($items) . "') RETURNING id");
        return intval($r['id'] ?? 0);
    }

    /** Every request: letters that are due are brought (the paper, the things, a note on the screen). */
    function tesWorldLetterTick(): void
    {
        $db = $GLOBALS['db'];
        $has = $db->fetchOne("SELECT to_regclass('public.tes_world_letters') AS t");
        if (empty($has['t'])) {
            return;
        }
        $rows = $db->fetchAll("UPDATE public.tes_world_letters SET sent = true WHERE id IN (SELECT id FROM public.tes_world_letters WHERE NOT sent AND due_at <= now() ORDER BY id LIMIT 2 FOR UPDATE SKIP LOCKED) RETURNING *");
        foreach (is_array($rows) ? $rows : [] as $l) {
            $cmds = [];
            foreach (array_filter(explode(',', strval($l['items']))) as $it) {
                [$fid, $n] = array_pad(explode(':', $it), 2, '1');
                if (preg_match('/^[0-9A-Fa-f]{8}$/', $fid)) {
                    $cmds[] = 'player.additem ' . strtoupper($fid) . ' ' . max(1, intval($n));
                }
            }
            if ($cmds) {
                tesWorldQueue($cmds);
            }
            if (tesWorldNeedGuard() && function_exists('tesGodGuardMakeDocument')) {
                tesGodGuardMakeDocument('player', strval($l['title']) . ': ' . strval($l['body']) . "\n\n— " . strval($l['sender']));
            }
            tesWatchNotify('Курьер принёс письмо от ' . $l['sender'] . ($cmds ? ' и посылку' : '') . ': «' . $l['title'] . '»');
        }
    }

    // ------------------------------------------------------------------ craftsmen

    /** What the master makes: smith / alchemist / tailor / enchanter, or '' (children never take orders). */
    function tesWorldCraftOf(string $npc): string
    {
        $db = $GLOBALS['db'];
        $r = $db->fetchOne("SELECT coalesce(occupation::text, '') AS o, race FROM public.core_npc_master WHERE npc_name = '" . $db->escape($npc) . "' LIMIT 1");
        if (empty($r) || preg_match('/реб[её]нок|child/iu', strval($r['race']))) {
            return '';
        }
        $o = mb_strtolower(strval($r['o']));
        foreach (['smith' => '(кузн|blacksmith|smith|оружейн)', 'alchemist' => '(алхим|alchem|apothec)', 'tailor' => '(портн|tailor|clothier|швея)', 'enchanter' => '(зачаров|enchant|court wizard|придворн\p{L}* маг)'] as $k => $re) {
            if (preg_match('/' . $re . '/u', $o)) {
                return $k;
            }
        }
        return '';
    }

    /** The thing by its Russian name in the game index: exact name first, else the shortest name containing it. */
    function tesWorldItemByName(string $name): array
    {
        $db = $GLOBALS['db'];
        $lc = mb_strtolower(trim($name));
        if (mb_strlen($lc) < 3) {
            return [];
        }
        $e = $db->escape($lc);
        $r = $db->fetchOne("SELECT formid, name FROM public.tes_game_index WHERE kind = 'item' AND name_lc = '{$e}' ORDER BY length(editor_id) LIMIT 1");
        if (empty($r)) {
            $r = $db->fetchOne("SELECT formid, name FROM public.tes_game_index WHERE kind = 'item' AND position('{$e}' in name_lc) > 0 AND name_lc NOT LIKE '%сломан%' ORDER BY length(name), length(editor_id) LIMIT 1");
        }
        return !empty($r['formid']) ? ['formid' => strtoupper(strval($r['formid'])), 'name' => strval($r['name'])] : [];
    }

    /** A fair price by what it is made of. */
    function tesWorldCraftPrice(string $item): int
    {
        $t = mb_strtolower($item);
        foreach (['драконь' => 4000, 'даэдрич' => 3000, 'эбонит' => 1500, 'стеклян' => 900, 'эльфийск' => 500, 'двемерск' => 400, 'орочь' => 300, 'стальн' => 150, 'железн' => 60,
            'зелье' => 70, 'яд' => 80, 'одежд' => 80, 'платье' => 120, 'роба' => 150, 'кольцо' => 300, 'амулет' => 350, 'ожерель' => 350] as $w => $p) {
            if (mb_strpos($t, $w) !== false) {
                return $p;
            }
        }
        return 200;
    }

    /** "скуй мне стальной меч" said to a master. Returns a note for the line or ''. */
    function tesWorldCraftSpoken(string $line, string $to): string
    {
        if ($to === '' || stripos($to, 'Narrator') !== false) {
            return '';
        }
        $t = mb_strtolower(str_replace('ё', 'е', $line));
        if (!preg_match('/(?<![\p{L}])(скуй|выкуй|сковать|сделай|изготовь|смастери|свари|сшей|пошей|зачаруй|закажу|заказываю|хочу\s+заказать)(?:\s+(?:мне|для\s+меня|нам))?\s+(.{3,60}?)\s*(?:[.!,?]|$)/u', $t, $m)) {
            if (preg_match('/(?<![\p{L}])(готов|где)\p{L}*\s+(?:\p{L}+\s+){0,2}заказ/u', $t)) {
                return tesWorldCraftStatus($to);
            }
            return '';
        }
        $craft = tesWorldCraftOf($to);
        if ($craft === '') {
            return '';
        }
        $phrase = trim(preg_replace('/^(?:новый|новую|новое|хороший|хорошую|хорошее|пару|один|одну)\s+/u', '', $m[2]) ?? $m[2]);
        $item = tesWorldItemByName($phrase);
        if (!$item) {
            // "стальной меч" in the accusative: try the plain forms
            $item = tesWorldItemByName(preg_replace(['/ую(?![\p{L}])/u', '/юю(?![\p{L}])/u', '/у(?![\p{L}])/u'], ['ая', 'яя', 'а'], $phrase) ?? $phrase);
        }
        if (!$item) {
            return '';  // «сделай мне одолжение» is talk, not an order
        }
        $price = tesWorldCraftPrice($item['name']);
        $gold = function_exists('tesWorldPlayerGold') ? tesWorldPlayerGold() : -1;
        if ($gold >= 0 && $gold < $price) {
            return " *{$item['name']} costs {$price} septims, but the jarl carries {$gold} - say you won't take the job without payment*";
        }
        tesWorldLivingEnsure();
        $db = $GLOBALS['db'];
        tesWorldQueue(['player.removeitem 0000000F ' . $price]);
        $minutes = $craft === 'alchemist' ? 6 : 10;
        $letter = tesWorldLetter($to, 'Заказ готов', "Ваш заказ исполнен: {$item['name']}. Сделано на совесть, оплата {$price} септимов получена. Обращайтесь ещё.", $minutes, $item['formid'] . ':1');
        $db->execQuery("INSERT INTO public.tes_craft_orders (master, item, formid, price, letter_id) VALUES ('" . $db->escape($to) . "', '" . $db->escape($item['name']) . "', '{$item['formid']}', {$price}, {$letter})");
        $GLOBALS['TES_COURT_NOW'] = true;  // not run again as an order to the agent
        return " *you took the order: {$item['name']} for {$price} septims (the money is already yours); ready in about {$minutes} minutes, you send it by courier; say so*";
    }

    function tesWorldCraftStatus(string $master): string
    {
        tesWorldLivingEnsure();
        $db = $GLOBALS['db'];
        $r = $db->fetchOne("SELECT o.item, l.due_at, l.sent FROM public.tes_craft_orders o LEFT JOIN public.tes_world_letters l ON l.id = o.letter_id WHERE o.master = '" . $db->escape($master) . "' ORDER BY o.id DESC LIMIT 1");
        if (empty($r)) {
            return ' *the jarl has no order with you - say so*';
        }
        if (strval($r['sent']) === 't') {
            return " *the order ({$r['item']}) is already sent by courier - say so*";
        }
        $left = max(1, intdiv(strtotime(strval($r['due_at'])) - time() + 59, 60));
        return " *the order ({$r['item']}) is still in work, about {$left} min left - say so*";
    }

    // ------------------------------------------------------------------ places remember

    /** Where the player is now (the location of the last context line). */
    function tesWorldPlaceNow(): string
    {
        $db = $GLOBALS['db'];
        $r = $db->fetchOne("SELECT data FROM eventlog WHERE data LIKE '%(Context location:%' ORDER BY rowid DESC LIMIT 1");
        return preg_match('/Context location:\s*([^,]+?)\s*,\s*Hold:/u', strval($r['data'] ?? ''), $m) ? trim($m[1]) : '';
    }

    function tesWorldRememberPlace(string $what, string $place = ''): void
    {
        $place = $place !== '' ? $place : tesWorldPlaceNow();
        if ($place === '' || trim($what) === '') {
            return;
        }
        tesWorldLivingEnsure();
        $db = $GLOBALS['db'];
        $db->execQuery("INSERT INTO public.tes_place_memory (place, what) VALUES ('" . $db->escape($place) . "', '" . $db->escape(mb_substr(trim($what), 0, 200)) . "')");
    }

    /** "Это место помнит: …" for whoever speaks here - at most the last two things, older than 10 minutes. */
    function tesWorldPlaceLine(): string
    {
        $db = $GLOBALS['db'];
        $has = $db->fetchOne("SELECT to_regclass('public.tes_place_memory') AS t");
        $place = !empty($has['t']) ? tesWorldPlaceNow() : '';
        if ($place === '') {
            return '';
        }
        $rows = $db->fetchAll("SELECT what, extract(epoch FROM now() - created_at) AS age FROM public.tes_place_memory WHERE place = '" . $db->escape($place) . "' AND created_at < now() - interval '10 minutes' ORDER BY id DESC LIMIT 2");
        $parts = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $h = intval($r['age']) / 3600;
            $when = $h < 2 ? 'recently' : ($h < 30 ? 'the other day' : 'long ago');
            $parts[] = trim(strval($r['what']), ' .') . " ({$when})";
        }
        return $parts ? "This place remembers: " . implode('; ', $parts) . '. Locals know of it and may bring it up in passing.' : '';
    }
}
