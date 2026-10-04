<?php
/*
 * tes_world: the ruler's treasury and court (owner, 2026-10-04: "суд и казну сам думай").
 *
 * Treasury - a number kept by the server (not a chest): fines that were paid, a tax from the
 * town's traders every 15 minutes while the player rules, and what the ruler puts in himself.
 * "Выдай мне из казны 500" gives that gold to the player, "положи в казну 1000" takes it from him,
 * "сколько в казне" is told to the NPC spoken to (he states it).
 *
 * Court - "суд над Хеймскром", "судить Фаренгара": the accused is brought to the player, and for
 * 10 minutes everyone in the talk knows a trial is going on, what the charge is, and that the
 * sentence is the ruler's word (the usual words then do it: посадить, казнить, штраф, отпустить).
 */

if (!function_exists('tesTreasuryAdd')) {
    function tesTreasuryEnsure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = $GLOBALS['db'];
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_treasury (id int PRIMARY KEY DEFAULT 1, balance bigint NOT NULL DEFAULT 0, updated_at timestamptz NOT NULL DEFAULT now())");
        $db->execQuery("INSERT INTO public.tes_treasury (id, balance) VALUES (1, 0) ON CONFLICT (id) DO NOTHING");
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_treasury_log (id serial PRIMARY KEY, delta bigint NOT NULL, why text NOT NULL DEFAULT '', created_at timestamptz NOT NULL DEFAULT now())");
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_court (id serial PRIMARY KEY, defendant text NOT NULL, charge text NOT NULL DEFAULT '', opened_at timestamptz NOT NULL DEFAULT now())");
    }

    function tesTreasuryBalance(): int
    {
        tesTreasuryEnsure();
        $r = $GLOBALS['db']->fetchOne("SELECT balance FROM public.tes_treasury WHERE id = 1");
        return intval($r['balance'] ?? 0);
    }

    function tesTreasuryAdd(int $delta, string $why): int
    {
        tesTreasuryEnsure();
        $db = $GLOBALS['db'];
        $db->execQuery("UPDATE public.tes_treasury SET balance = greatest(0, balance + {$delta}), updated_at = now() WHERE id = 1");
        $db->execQuery("INSERT INTO public.tes_treasury_log (delta, why) VALUES ({$delta}, '" . $db->escape(mb_substr($why, 0, 120)) . "')");
        return tesTreasuryBalance();
    }

    /** Taxes from the traders: every 15 minutes while the player rules. */
    function tesTreasuryTax(): void
    {
        if (empty(tesWorldFacts()['player_title'])) {
            return;
        }
        tesWatchEnsure();
        if (tesWatchGet('tax_at')['age'] < 900) {
            return;
        }
        tesWatchSet('tax_at', '1');
        $traders = 8 + random_int(0, 4);
        $rate = tesWatchGet('tax_rate')['value'];
        $sum = intval($traders * (35 + random_int(0, 40)) * (($rate === '' ? 100 : intval($rate)) / 100));
        if ($sum <= 0) {
            return;  // the tax is lifted
        }
        $balance = tesTreasuryAdd($sum, "налог с торговцев ({$traders})");
        tesWatchNotify("В казну поступил налог {$sum} септимов. Всего в казне: {$balance}");
    }

    /**
     * The ruler's words about the treasury and the court. Returns a note for the NPC's line
     * (appended to what he hears) or ''. $to = who is spoken to.
     */
    function tesCourtSpoken(string $line, string $to): string
    {
        $t = mb_strtolower(str_replace('ё', 'е', $line));
        // "включи безнаказанность" / "выключи безнаказанность": the player's crimes are not
        // reported (no bounty, the guards do not turn on him); on by default (owner's wish)
        if (preg_match('/безнаказанн\p{L}*|закон\p{L}*\s+(на\s+меня\s+)?не\s+действ\p{L}*/u', $t)) {
            tesWatchEnsure();
            $off = (bool)preg_match('/(выключ|отключ|убер|отмен|сними|хватит)/u', $t);
            tesWatchSet('impunity', $off ? '0' : '1');
            if (tesBridgeVersion() >= 4) {
                tesWorldQueue(['tesimpunity ' . ($off ? '0' : '1')]);
            }
            return $off ? ' *безнаказанность ярла отменена — его преступления снова видны страже*' : ' *безнаказанность ярла включена — ему сходит с рук что угодно*';
        }
        if (empty(tesWorldFacts()['player_title']) || $to === '') {
            return '';
        }
        // --- treasury
        if (preg_match('/(сколько|что|как)\s+(там\s+)?(в\s+казне|денег\s+в\s+казне|казн\p{L}*)/u', $t)) {
            return ' *в казне сейчас ' . tesTreasuryBalance() . ' септимов; назови эту сумму ярлу*';
        }
        if (preg_match('/(из\s+казны|казн\p{L}*)\s*.*(выда\p{L}*|дай|дайте|достань|возьми|отсчитай)|(выда\p{L}*|дай|дайте|достань|отсчитай)\s+.*из\s+казны/u', $t)) {
            $n = tesWorldSpokenAmount($line);
            $have = tesTreasuryBalance();
            if ($n <= 0) {
                return ' *ярл не назвал сумму из казны — переспроси, сколько*';
            }
            $n = min($n, $have);
            if ($n <= 0) {
                return ' *казна пуста — скажи ярлу об этом*';
            }
            tesTreasuryAdd(-$n, 'выдано ярлу');
            tesWorldQueue(['player.additem 0000000F ' . $n]);
            return " *из казны выдано ярлу {$n} септимов — золото уже у него; подтверди это*";
        }
        if (preg_match('/(в\s+казну|казне)\s*.*(полож\p{L}*|внес\p{L}*|внести|сдай|сдать|отдай)|(полож\p{L}*|внес\p{L}*|сдай|сдать)\s+.*в\s+казну/u', $t)) {
            $n = tesWorldSpokenAmount($line);
            if ($n > 0) {
                tesWorldQueue(['player.removeitem 0000000F ' . $n]);
                $b = tesTreasuryAdd($n, 'внесено ярлом');
                return " *{$n} септимов внесено в казну (теперь {$b}); подтверди*";
            }
        }
        // --- the court's place: "суд будет в зале ярла", "суд здесь" - where the player stands now
        // (owner, 2026-10-04: "суд будет там где я назначу")
        if (preg_match('/суд\p{L}*\s+(?:будет|пройд[её]т|теперь|здесь|тут|проводи\p{L}*)\s*(?:в|на|у|во)?\s*(.{0,40})/u', $t, $vm) && !preg_match('/суд\p{L}*\s+над|судить/u', $t)) {
            $place = trim(preg_replace('/[.!?,…].*$/u', '', $vm[1]) ?? '');
            $place = $place !== '' ? $place : 'здесь';
            tesWatchEnsure();
            tesWatchSet('court_name', $place);
            $placed = 'на месте, где ты стоишь';
            if (function_exists('tesBridgeVersion') && tesBridgeVersion() >= 6) {
                $max = $GLOBALS['db']->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_god_console_log");
                tesWorldQueue(['tesplace here']);
                for ($i = 0; $i < 12; $i++) {
                    usleep(400000);
                    $r = $GLOBALS['db']->fetchOne("SELECT output FROM public.tes_god_console_log WHERE id > " . intval($max['m'] ?? 0) . " AND command = 'tesplace here' ORDER BY id DESC LIMIT 1");
                    if (!empty($r) && preg_match('/^\s*(\d+)\s*$/', strval($r['output']), $pm)) {
                        tesWatchSet('court_ref', strtoupper(str_pad(dechex(intval($pm[1])), 8, '0', STR_PAD_LEFT)));
                        $placed = 'в отмеченной точке';
                        break;
                    }
                }
            } else {
                tesWatchSet('court_ref', '');  // an old bridge cannot set a mark: the accused is brought to the player
            }
            tesWatchNotify("Место суда: {$place}");
            return " *суд ярла будет проходить: {$place} ({$placed}); подтверди это*";
        }
        // --- court: "суд над Хеймскром", "судить Фаренгара"
        if (preg_match('/(?<![\p{L}])(суд\s+над|суди\p{L}*|судить|начина\p{L}*\s+суд|открыва\p{L}*\s+суд|привед\p{L}*\s+(?:.*\s)?на\s+суд)(?![\p{L}])/u', $t) && !preg_match('/(?<![\p{L}])не\s+(суди|судить)/u', $t)) {
            $who = '';
            $near = tesWorldNearbyNames(30);
            foreach (preg_split('/[^\p{L}\-]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) as $i => $w) {
                $hit = tesWorldHeardName($w, $near) ?: ($i > 0 ? tesWorldKnownName($w) : '');
                if ($hit !== '' && $hit !== $to && tesWorldNorm($hit) !== tesWorldNorm(strval($GLOBALS['PLAYER_NAME'] ?? ''))) {
                    $who = $hit;
                    break;
                }
            }
            if ($who === '' || tesWorldIsChild($who)) {
                return '';
            }
            tesTreasuryEnsure();
            $db = $GLOBALS['db'];
            $open = $db->fetchOne("SELECT 1 AS x FROM public.tes_court WHERE defendant = '" . $db->escape($who) . "' AND opened_at > now() - interval '10 minutes' LIMIT 1");
            if (empty($open)) {
                $charge = trim(preg_replace('/^.*?(?:за|обвиня\p{L}*\s+в)\s+/u', '', mb_substr($line, 0, 200), 1) ?? '');
                $charge = ($charge !== '' && $charge !== mb_substr($line, 0, 200)) ? $charge : '';
                $db->execQuery("INSERT INTO public.tes_court (defendant, charge) VALUES ('" . $db->escape($who) . "', '" . $db->escape(mb_substr($charge, 0, 160)) . "')");
                $ref = tesWorldRefOf($who);
                $venueRef = strval(tesWatchGet('court_ref')['value']);
                $venueName = strval(tesWatchGet('court_name')['value']);
                if ($ref !== '' && !(function_exists('tesCrimeIsJailed') && tesCrimeIsJailed($who))) {
                    tesWorldQueue(['prid ' . $ref, 'moveto ' . ($venueRef !== '' ? $venueRef : 'player')]);
                    if ($venueRef === '') {
                        tesWorldVerifyAdd('bring', $who, $ref);
                    }
                }
                $where = $venueName !== '' ? " ({$venueName})" : '';
                return " *суд над {$who} открыт{$where} — подсудимого ведут в место суда; приговор скажет ярл*";
            }
        }
        return '';
    }

    /** One line about a trial that is going on, for the prompt of anyone in the talk, or ''. */
    function tesCourtLine(string $me): string
    {
        if (empty(tesWorldFacts()['player_title'])) {
            return '';
        }
        tesTreasuryEnsure();
        $c = $GLOBALS['db']->fetchOne("SELECT defendant, charge FROM public.tes_court WHERE opened_at > now() - interval '10 minutes' ORDER BY id DESC LIMIT 1");
        if (empty($c['defendant'])) {
            return '';
        }
        $d = strval($c['defendant']);
        $venue = trim(strval(tesWatchGet('court_name')['value']));
        $charge = trim(strval($c['charge'])) !== '' ? ', обвинение: ' . trim(strval($c['charge'])) : '';
        if ($me === $d) {
            return "Тебя судит правитель{$charge}. Оправдывайся, умоляй или дерзи — в характере; приговор — его слово.";
        }
        return "Идёт суд правителя над {$d}" . ($venue !== '' ? " ({$venue})" : '') . "{$charge}. Ты присутствуешь: свидетельствуй по своему знанию и ждёшь приговора правителя.";
    }
}
