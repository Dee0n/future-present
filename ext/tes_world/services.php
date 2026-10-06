<?php
/*
 * tes_world: services without the dialogue menu and prices that follow events (roadmap D and H). Needs bridge 16.
 *  - said by voice to a person: "давай поторгуем / покажи товар" - the barter window opens; "научи меня / потренируй"
 *    - the training window; "прими подарок / хочу подарить" - the gift window. (A merchant trades, a trainer trains:
 *    the game itself decides whether the window has anything in it.)
 *  - prices: the ruler sets one ("эль теперь стоит 20 септимов"); during a feast drink costs twice as much. A price
 *    lives in the game one session only (SetGoldValue): the server sends the active ones again every 10 minutes and
 *    puts the old value back when it runs out.
 */

if (!function_exists('tesServiceSpoken')) {
    function tesServiceSpoken(string $line, string $to): string
    {
        if ($to === '' || stripos($to, 'Narrator') !== false) {
            return '';
        }
        $t = mb_strtolower(str_replace('ё', 'е', $line));
        $kind = '';
        if (preg_match('/(?<![\p{L}])(поторгуем|торговать|поторговать|покажи\s+(?:свой\s+|свои\s+)?товар\p{L}*|что\s+(?:у\s+тебя\s+)?(?:есть\s+)?(?:на\s+)?продаж\p{L}*|что\s+продаешь|хочу\s+(?:купить|продать)|давай\s+торг\p{L}*)(?![\p{L}])/u', $t)) {
            $kind = 'tesbarter';
        } elseif (preg_match('/(?<![\p{L}])(научи\s+меня|обучи\s+меня|потренируй|тренируй\s+меня|хочу\s+(?:учиться|научиться|тренироваться)|дай\s+урок)(?![\p{L}])/u', $t)) {
            $kind = 'testrain';
        } elseif (preg_match('/(?<![\p{L}])(прими\s+(?:мой\s+)?подар\p{L}*|хочу\s+(?:тебе\s+)?(?:что-то\s+)?подарить|держи\s+подарок)(?![\p{L}])/u', $t)) {
            $kind = 'tesgiftmenu';
        }
        if ($kind === '') {
            return '';
        }
        if (!function_exists('tesBridgeVersion') || tesBridgeVersion() < 16) {
            return ' *(окно торговли/обучения откроется после перезапуска игры — нужен новый мост) — ответь как обычно*';
        }
        $ref = tesWorldRefOf($to);
        if ($ref === '') {
            return '';
        }
        tesWorldQueue(['prid ' . $ref, $kind]);
        $GLOBALS['TES_COURT_NOW'] = true;  // not an order for the agent
        return [
            'tesbarter' => ' *ты раскладываешь свой товар перед ярлом — окно торговли уже открыто; скажи что-нибудь торговое*',
            'testrain' => ' *ты берёшься учить ярла — окно обучения уже открыто; скажи пару слов как наставник*',
            'tesgiftmenu' => ' *ярл хочет тебе что-то подарить — окно подарка открыто; отзовись*',
        ][$kind];
    }

    /** Prices back: one item ("эль") or all the ruler set. */
    function tesPriceUndo(string $phrase): string
    {
        tesPriceEnsure();
        $db = $GLOBALS['db'];
        $where = '';
        if ($phrase !== '' && function_exists('tesWorldItemByName')) {
            $item = tesWorldItemByName($phrase);
            if (!$item) {
                return '';
            }
            $where = " WHERE formid = '{$item['formid']}'";
        }
        $rows = $db->fetchAll("DELETE FROM public.tes_prices{$where} RETURNING formid, base, name");
        $cmds = [];
        $names = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $names[] = strval($r['name']);
            if ($r['base'] !== null) {
                $cmds[] = 'tesprice ' . hexdec(strval($r['formid'])) . ' ' . intval($r['base']);
            }
        }
        foreach (array_chunk($cmds, 8) as $chunk) {
            tesWorldQueue($chunk);
        }
        return $names ? ' *прежние цены вернулись: ' . implode(', ', array_slice($names, 0, 5)) . '; подтверди*' : ' *особых цен не было — менять нечего*';
    }

    function tesPriceEnsure(): void
    {
        $GLOBALS['db']->execQuery("CREATE TABLE IF NOT EXISTS public.tes_prices (formid text PRIMARY KEY, name text NOT NULL, gold int NOT NULL, base int, why text NOT NULL DEFAULT '',
            until_at timestamptz, sent_at timestamptz, updated_at timestamptz NOT NULL DEFAULT now())");
    }

    /** Set a price (until $minutes from now, 0 = until changed) and send it at once. */
    function tesPriceSet(string $formid, string $name, int $gold, string $why, int $minutes = 0): void
    {
        tesPriceEnsure();
        $db = $GLOBALS['db'];
        $until = $minutes > 0 ? "now() + interval '{$minutes} minutes'" : 'NULL';
        $db->execQuery("INSERT INTO public.tes_prices (formid, name, gold, why, until_at) VALUES ('{$formid}', '" . $db->escape($name) . "', {$gold}, '" . $db->escape($why) . "', {$until})
            ON CONFLICT (formid) DO UPDATE SET gold = EXCLUDED.gold, why = EXCLUDED.why, until_at = EXCLUDED.until_at, sent_at = NULL, updated_at = now()");
    }

    /** "эль теперь стоит 20 септимов", "цена на стальной меч 300" - said to anyone by the ruler. */
    function tesPriceSpoken(string $line): string
    {
        if (empty(tesWorldFacts()['player_title'])) {
            return '';
        }
        $t = mb_strtolower(str_replace('ё', 'е', $line));
        // "верни цену на эль", "отмени цены": the old prices come back
        if (preg_match('/(?:верни|отмени|сбрось|убери)\s+(?:\p{L}+\s+)?цен\p{L}*(?:\s+на\s+(.{3,40}?))?\s*(?:[.!]|$)/u', $t, $um)) {
            return tesPriceUndo(trim(strval($um[1] ?? '')));
        }
        // only a decree: bargaining ("эльфийский лук стоит 200, не больше?") must not change every trader's price
        if (preg_match('/\?\s*$/u', trim($t)) || !preg_match('/(?<![\p{L}])(теперь|отныне|указ\p{L}*|повелеваю|приказываю|устанавливаю|объявляю|пусть)(?![\p{L}])/u', $t)) {
            return '';
        }
        if (!preg_match('/(?:цен\p{L}*\s+(?:на\s+)?(.{3,40}?)\s+(?:теперь\s+|будет\s+|-\s*)?(\d{1,6})|(.{3,40}?)\s+(?:теперь\s+)?(?:стоит|стоят|будет\s+стоить|продавать\s+по)\s+(\d{1,6}))/u', $t, $m)) {
            return '';
        }
        $phrase = trim($m[1] !== '' ? $m[1] : $m[3]);
        $gold = intval($m[2] !== '' ? $m[2] : $m[4]);
        $phrase = trim(preg_replace('/^(?:пусть|теперь|отныне|а|и)\s+/u', '', $phrase) ?? $phrase);
        $phrase = trim(preg_replace('/^(?:теперь|отныне|указ\p{L}*|повелеваю|приказываю|устанавливаю|объявляю|пусть)\s+/u', '', $phrase) ?? $phrase);
        $item = function_exists('tesWorldItemByName') ? tesWorldItemByName($phrase) : [];
        // a one-word phrase ("меч") would pick an arbitrary item: only an exact name then
        if (!$item || (count(preg_split('/\s+/u', $phrase)) < 2 && mb_strtolower($item['name']) !== $phrase)) {
            return '';
        }
        if (!function_exists('tesBridgeVersion') || tesBridgeVersion() < 16) {
            return ' *цены ярл сможет менять после перезапуска игры (нужен новый мост) — скажи это*';
        }
        tesPriceSet($item['formid'], $item['name'], $gold, 'указ ярла');
        tesPriceTick(true);
        return " *по указу ярла {$item['name']} теперь стоит {$gold} септимов у всех торговцев; прими к сведению*";
    }

    /** Every 10 minutes (or at once): active prices sent again; ended ones put back; the feast makes drink dearer. */
    function tesPriceTick(bool $now = false): void
    {
        if (!function_exists('tesBridgeVersion') || tesBridgeVersion() < 16) {
            return;
        }
        $mark = sys_get_temp_dir() . '/tes_prices.ts';
        if (!$now && time() - intval(@file_get_contents($mark)) < 600) {
            return;
        }
        @file_put_contents($mark, strval(time()));
        tesPriceEnsure();
        $db = $GLOBALS['db'];
        // a feast: ale (REQ_Drink_Ale 00034C5E) twice its price while it lasts
        $feast = $db->fetchOne("SELECT 1 AS x FROM public.tes_gatherings WHERE party AND NOT released AND created_at > now() - interval '144 minutes' LIMIT 1");
        $ale = $db->fetchOne("SELECT 1 AS x FROM public.tes_prices WHERE formid = '00034C5E'");
        if (!empty($feast) && empty($ale)) {
            tesPriceSet('00034C5E', 'Эль', 10, 'гулянка: выпивка вдвое', 144);
        }
        // the base value comes back from the game's answer ("Эль 5 -> 10")
        $ans = $db->fetchAll("SELECT command, output FROM public.tes_god_console_log WHERE command LIKE 'tesprice %' AND created_at > now() - interval '15 minutes' ORDER BY id DESC LIMIT 20");
        foreach (is_array($ans) ? $ans : [] as $a) {
            if (preg_match('/^tesprice (\d+) \d+$/', strval($a['command']), $cm) && preg_match('/(\d+) -> \d+$/', strval($a['output']), $om)) {
                $fid = sprintf('%08X', intval($cm[1]));
                $db->execQuery("UPDATE public.tes_prices SET base = {$om[1]} WHERE formid = '{$fid}' AND base IS NULL AND {$om[1]} <> gold");
            }
        }
        $cmds = [];
        $ended = $db->fetchAll("DELETE FROM public.tes_prices WHERE until_at IS NOT NULL AND until_at < now() RETURNING formid, base, name");
        foreach (is_array($ended) ? $ended : [] as $e) {
            if ($e['base'] !== null) {
                $cmds[] = 'tesprice ' . hexdec(strval($e['formid'])) . ' ' . intval($e['base']);
            }
        }
        foreach ((array)$db->fetchAll("SELECT formid, gold FROM public.tes_prices") as $p) {
            if (is_array($p) && !empty($p['formid'])) {
                $cmds[] = 'tesprice ' . hexdec(strval($p['formid'])) . ' ' . intval($p['gold']);
            }
        }
        foreach (array_chunk($cmds, 8) as $chunk) {
            tesWorldQueue($chunk);
        }
    }
}
