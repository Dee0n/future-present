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
        $db->execQuery("ALTER TABLE public.tes_court ADD COLUMN IF NOT EXISTS closed boolean NOT NULL DEFAULT false");
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

    /** "В казне N. Последнее: +675 налог…, −250 Игры…" - for the one asked about the treasury. */
    function tesTreasuryReport(): string
    {
        tesTreasuryEnsure();
        $rows = $GLOBALS['db']->fetchAll("SELECT delta, why FROM public.tes_treasury_log ORDER BY id DESC LIMIT 6");
        $parts = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $d = intval($r['delta']);
            $parts[] = ($d >= 0 ? '+' : '−') . abs($d) . ' ' . trim(strval($r['why']));
        }
        $in = $GLOBALS['db']->fetchOne("SELECT coalesce(sum(delta) FILTER (WHERE delta > 0), 0) AS i, coalesce(-sum(delta) FILTER (WHERE delta < 0), 0) AS o FROM public.tes_treasury_log WHERE created_at > now() - interval '1 day'");
        return 'в казне ' . tesTreasuryBalance() . ' септимов; за сутки пришло ' . intval($in['i'] ?? 0) . ', ушло ' . intval($in['o'] ?? 0)
            . ($parts ? '; последнее: ' . implode(', ', $parts) : '');
    }

    /** Who is paid from the treasury: a named person, the one spoken to ("тебе"), or '' = the ruler. */
    function tesTreasuryPayee(string $line, string $to): string
    {
        $t = mb_strtolower(str_replace('ё', 'е', $line));
        if (preg_match('/(?<![\p{L}])(мне|себе\s+(?:я|ярлу)|ярлу)(?![\p{L}])/u', $t)) {
            return '';
        }
        $player = tesWorldNorm(strval($GLOBALS['PLAYER_NAME'] ?? ''));
        $near = tesWorldNearbyNames(30);
        foreach (preg_split('/[^\p{L}\-]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) as $i => $w) {
            if (mb_strlen($w) < 4 || preg_match('/^(казн|выда|дай|заплат|плати|септим|золот|монет|тысяч|сотн)/iu', $w)) {
                continue;
            }
            $hit = tesWorldHeardName($w, $near) ?: ($i > 0 ? tesWorldKnownName($w) : '');
            if ($hit !== '' && tesWorldNorm($hit) !== $player) {
                return $hit;
            }
        }
        if ($to !== '' && stripos($to, 'Narrator') === false && preg_match('/(?<![\p{L}])(тебе|себе|возьми\s+себе)(?![\p{L}])/u', $t)) {
            return $to;
        }
        return '';
    }

    /** The gold the player carries, asked of the game (-1 when it did not answer in ~5 s). */
    function tesWorldPlayerGold(): int
    {
        $db = $GLOBALS['db'];
        $max = $db->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_god_console_log");
        if (!tesWorldQueue(['player.getitemcount 0000000F'])) {
            return -1;
        }
        for ($i = 0; $i < 12; $i++) {
            usleep(400000);
            $r = $db->fetchOne("SELECT output FROM public.tes_god_console_log WHERE id > " . intval($max['m'] ?? 0) . " AND lower(command) = 'player.getitemcount 0000000f' ORDER BY id DESC LIMIT 1");
            if (!empty($r) && preg_match('/>>\s*(\d+)/', strval($r['output']), $m)) {
                return intval($m[1]);
            }
        }
        return -1;
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
        // (only forms of «казна»: «казн\p{L}*» took «казнь/казнить/казначей» for the treasury, and a sentence said in
        // the middle of a trial - «приговариваю к казни, а семья заплатит» - went to a payout instead)
        $trialNow = $GLOBALS['db']->fetchOne("SELECT 1 AS x FROM public.tes_court WHERE opened_at > now() - interval '10 minutes' AND NOT closed LIMIT 1");
        $sentenceNow = !empty($trialNow) && tesCourtVerdict($line) !== null;
        // "куда ушли деньги", "отчёт по казне", "на что потрачено": the last movements, not only the sum
        if (!$sentenceNow && preg_match('/(куда|на\s+что)\s+(?:\p{L}+\s+){0,2}(ушл\p{L}*|потрач\p{L}*|дел\p{L}*|растрат\p{L}*)|отч[её]т\p{L}*\s+(?:по\s+)?(?<![\p{L}])казн(?:а|ы|е|у|ой)(?![\p{L}])|расход\p{L}*\s+(?<![\p{L}])казн(?:а|ы|е|у|ой)(?![\p{L}])|(?<![\p{L}])казн(?:а|ы|е|у|ой)(?![\p{L}])\s+отч[её]т/u', $t)) {
            return ' *' . tesTreasuryReport() . '; перескажи ярлу коротко*';
        }
        if (!$sentenceNow && preg_match('/(сколько|что|как)\s+(там\s+)?(в\s+казне|денег\s+в\s+казне|(?<![\p{L}])казн(?:а|ы|е|у|ой)(?![\p{L}]))/u', $t)) {
            return ' *в казне сейчас ' . tesTreasuryBalance() . ' септимов; назови эту сумму ярлу*';
        }
        if (!$sentenceNow && preg_match('/(из\s+казны|(?<![\p{L}])казн(?:а|ы|е|у|ой)(?![\p{L}]))\s*.*(выда\p{L}*|дай|дайте|достань|возьми|отсчитай|заплат\p{L}*|плати|выплат\p{L}*|награ\p{L}*)|(выда\p{L}*|дай|дайте|достань|отсчитай|заплат\p{L}*|плати|выплат\p{L}*|награ\p{L}*)\s+.*из\s+казны/u', $t)) {
            $n = tesWorldSpokenAmount($line);
            $have = tesTreasuryBalance();
            if ($n <= 0) {
                return ' *ярл не назвал сумму из казны — переспроси, сколько*';
            }
            if ($have <= 0) {
                return ' *казна пуста — скажи ярлу об этом*';
            }
            $short = $n > $have;
            $n = min($n, $have);
            // to whom: "выдай Бренуину 300 из казны", "заплати стражникам из казны 50 каждому" - a named person (or
            // the one spoken to with "тебе/себе"); "мне" or nobody named - the ruler himself
            $whom = tesTreasuryPayee($line, $to);
            if ($whom !== '' && ($wr = tesWorldRefOf($whom)) !== '') {
                tesTreasuryAdd(-$n, 'выдано: ' . $whom);
                tesWorldQueue(['prid ' . $wr, 'additem 0000000F ' . $n]);
                return " *из казны выдано {$whom} {$n} септимов — золото уже у него" . ($short ? ' (больше в казне не было)' : '') . '; подтверди*';
            }
            tesTreasuryAdd(-$n, 'выдано ярлу');
            tesWorldQueue(['player.additem 0000000F ' . $n]);
            return " *из казны выдано ярлу {$n} септимов — золото уже у него" . ($short ? ' (это всё, что было)' : '') . '; подтверди это*';
        }
        if (!$sentenceNow && preg_match('/(в\s+казну|казне)\s*.*(полож\p{L}*|внес\p{L}*|внести|сдай|сдать|отдай)|(полож\p{L}*|внес\p{L}*|сдай|сдать)\s+.*в\s+казну/u', $t)) {
            $n = tesWorldSpokenAmount($line);
            if ($n > 0) {
                // only what he really carries (before: the sum was credited whether or not he had it)
                $gold = tesWorldPlayerGold();
                if ($gold >= 0 && $gold < $n) {
                    if ($gold <= 0) {
                        return ' *у ярла при себе нет золота — внести нечего; скажи ему это*';
                    }
                    $n = $gold;
                }
                tesWorldQueue(['player.removeitem 0000000F ' . $n]);
                $b = tesTreasuryAdd($n, 'внесено ярлом');
                return " *{$n} септимов внесено в казну (теперь {$b})" . ($gold >= 0 && $gold === $n ? ' — это всё, что было при нём' : '') . '; подтверди*';
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
        // --- court: "суд над Хеймскром", "судить Фаренгара", "будет суд", "я буду судить тебя"
        tesTreasuryEnsure();
        $db = $GLOBALS['db'];
        $isNarrator = (stripos($to, 'Narrator') !== false);
        $trialRe = '/(?<![\p{L}])(суд\s+над|суди\p{L}*|судить|начина\p{L}*\s+суд|открыва\p{L}*\s+суд|привед\p{L}*\s+(?:.*\s)?на\s+суд|(?:будет|идет|идёт|начал\p{L}*|начина\p{L}*|открыт\p{L}*)\s+суд|суд\s+(?:идет|идёт|начал\p{L}*|начина\p{L}*))(?![\p{L}])/u';
        $openCourt = $db->fetchOne("SELECT id, defendant FROM public.tes_court WHERE opened_at > now() - interval '10 minutes' AND NOT closed ORDER BY id DESC LIMIT 1");
        // the accused wandered off: "куда он пошёл", "привяжи его" - back to the ruler and tied to him
        if (!empty($openCourt['defendant']) && preg_match('/куда\s+\p{L}+\s+пош[её]л|привяж\p{L}*|сбеж\p{L}*|убеж\p{L}*|уходить|верни\p{L}*\s+его|приведи\p{L}*\s+его|ушел|ушёл/u', $t)) {
            $rf = tesWorldRefOf(strval($openCourt['defendant']));
            if ($rf !== '') {
                $vr = strval(tesWatchGet('court_ref')['value']);
                tesWorldQueue(['prid ' . $rf, 'moveto ' . ($vr !== '' ? $vr : 'player'), 'tesfollow 0', 'teshold ' . hexdec($rf)]);
                return ' *' . $openCourt['defendant'] . ' возвращён к ярлу и стоит на месте, пока идёт суд; подтверди*';
            }
        }
        // the sentence: "Вы приговариваетесь к казни" (live 2026-10-06 18:02:06 - nothing happened, the ruler had to
        // order a guard separately), "приговариваю к тюрьме на 10 дней", "штраф 500", "оправдан / свободен"
        if (!empty($openCourt['defendant'])) {
            $verdict = tesCourtVerdict($line);
            if ($verdict !== null) {
                return tesCourtSentence(intval($openCourt['id']), strval($openCourt['defendant']), $verdict, $line, $to);
            }
        }
        if (preg_match($trialRe, $t) && !preg_match('/(?<![\p{L}])не\s+(суди|судить)/u', $t)) {
            $who = '';
            // "я буду судить тебя", "над тобой будет суд": the one spoken to
            if (!$isNarrator && $to !== '' && preg_match('/(над\s+тобой|судить\s+тебя|тебя\s+(?:буду\s+|будем\s+)?суди|тебя\s+суд|суд\s+над\s+тобой|тебя\s+судят)/u', $t)) {
                $who = $to;
            }
            if ($who === '') {
                $near = tesWorldNearbyNames(30);
                foreach (preg_split('/[^\p{L}\-]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) as $i => $w) {
                    $hit = tesWorldHeardName($w, $near) ?: ($i > 0 ? tesWorldKnownName($w) : '');
                    if ($hit !== '' && $hit !== $to && tesWorldNorm($hit) !== tesWorldNorm(strval($GLOBALS['PLAYER_NAME'] ?? ''))) {
                        $who = $hit;
                        break;
                    }
                }
            }
            // "того стражника, который меня не защитил", "его": the last one the ruler accused
            if ($who === '') {
                // a bare "суд идёт" while a trial is going is only a remark: no second trial (live 17:00: "Заткнулась,
                // суд идёт" made Ольфина a defendant)
                if (!empty($openCourt['defendant'])) {
                    return '';
                }
                $last = trim(strval(tesWatchGet('court_last')['value']));
                if ($last !== '' && tesWatchGet('court_last')['age'] < 1800) {
                    $who = $last;
                }
            }
            if ($who === '' || tesWorldIsChild($who)) {
                return '';
            }
            tesWatchSet('court_last', $who);
            $open = $db->fetchOne("SELECT 1 AS x FROM public.tes_court WHERE defendant = '" . $db->escape($who) . "' AND opened_at > now() - interval '10 minutes' AND NOT closed LIMIT 1");
            if (empty($open)) {
                $charge = trim(preg_replace('/^.*?(?:за|обвиня\p{L}*\s+в)\s+/u', '', mb_substr($line, 0, 200), 1) ?? '');
                $charge = ($charge !== '' && $charge !== mb_substr($line, 0, 200)) ? $charge : '';
                $db->execQuery("INSERT INTO public.tes_court (defendant, charge) VALUES ('" . $db->escape($who) . "', '" . $db->escape(mb_substr($charge, 0, 160)) . "')");
                $ref = tesWorldRefOf($who);
                $venueRef = strval(tesWatchGet('court_ref')['value']);
                $venueName = strval(tesWatchGet('court_name')['value']);
                if ($ref !== '' && !(function_exists('tesCrimeIsJailed') && tesCrimeIsJailed($who))) {
                    // brought to the place of the court and tied to the ruler for the trial (live 16:53:
                    // "он куда-то пиздует… привяжи его ко мне")
                    tesWorldQueue(['prid ' . $ref, 'moveto ' . ($venueRef !== '' ? $venueRef : 'player'), 'tesfollow 0', 'teshold ' . hexdec($ref)]);
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

    /**
     * A sentence in the ruler's words, or null: ['kind' => kill|jail|fine|free, 'days' => N, 'amount' => N].
     * Only said while a trial is open; a mere mention ("не казню", "казнь — это слишком") is not one.
     */
    function tesCourtVerdict(string $line): ?array
    {
        // "за это казнь?" is a question, not a sentence
        if (preg_match('/\?\s*$/u', trim(preg_replace('/\*[^*]*\*/u', '', $line) ?? $line))) {
            return null;
        }
        $crime = __DIR__ . '/../tes_crime/lib.php';
        if (!function_exists('tesCrimeTerm') && is_readable($crime)) {
            require_once $crime;
        }
        $t = ' ' . mb_strtolower(str_replace('ё', 'е', $line)) . ' ';
        $L = '(?<![\p{L}])';
        $R = '(?![\p{L}])';
        // "не казню", "не будет казни", "без казни" - mercy, not death
        $noKill = '/' . $L . '(?:не|без)\s+(?:\p{L}+\s+)?(?:казн|убь|убив|смерт)/u';
        $noJail = '/' . $L . '(?:не|без)\s+(?:\p{L}+\s+)?(?:тюрьм|темниц|сажа|посаж)/u';
        if (preg_match('/' . $L . '(оправда\p{L}*|невинов\p{L}*|не\s+виновен|не\s+виновна|помилова\p{L}*|милую|прощаю|свобод(?:ен|на|ны)|отпустить|отпускаю|отпустите|снимаю\s+обвинени\p{L}*|обвинени\p{L}*\s+снят\p{L}*)' . $R . '/u', $t)) {
            return ['kind' => 'free'];
        }
        // only a real sentencing form: a bare «казнь» is a mention («тебе грозит казнь», «за такое бывает казнь»),
        // and a death sentence can not be taken back (the condemned leaves his factions, the fight is to the death)
        $deathForm = '(?:приговар\p{L}*|приговор\p{L}*)[\s\p{Pd}:,]+(?:\p{L}+\s+){0,4}?(?:к\s+)?(?:смертной\s+)?(?:казн\p{L}*|смерт\p{L}*)'
            . '|' . $L . '(?:к\s+(?:смертной\s+)?казни|к\s+смерти|на\s+плаху|на\s+виселицу|повесить\s+(?:его|ее|их)|обезглав\p{L}*|отрубить\s+(?:ему\s+|ей\s+)?голов\p{L}*'
            . '|казнить\s+(?:его|ее|их)|казните\s+(?:его|ее|их)|смерть\s+(?:ему|ей))' . $R
            . '|^\s*казнить(?:\s+(?:немедленно|сейчас|сразу))?[\s.!]*$';
        if (preg_match('/' . $deathForm . '/u', trim($t)) && !preg_match($noKill, $t)) {
            return ['kind' => 'kill'];
        }
        if (preg_match('/' . $L . '(к\s+(?:тюрьм\p{L}*|заключени\p{L}*|темниц\p{L}*)|в\s+тюрьму|в\s+темницу|за\s+решетку|посадить|сажаю|сажать|посажен\p{L}*|заключени\p{L}*|тюремн\p{L}*\s+срок\p{L}*|(?:\d+|\p{L}+)\s+(?:лет|года|год|дней|дня|суток|недел\p{L}*|месяц\p{L}*)\s+(?:тюрьмы|темницы|заключения))' . $R . '/u', $t) && !preg_match($noJail, $t)) {
            $days = function_exists('tesCrimeTerm') ? tesCrimeTerm($line) : 0;
            return ['kind' => 'jail', 'days' => max(1, min(3650, $days ?: 1))];
        }
        if (preg_match('/' . $L . '(штраф\p{L}*|оштраф\p{L}*|пен\p{L}+\s+в\s+казну|заплатит\p{L}*\s+в\s+казну)' . $R . '/u', $t)) {
            $n = tesWorldSpokenAmount($line);
            return $n > 0 ? ['kind' => 'fine', 'amount' => $n] : null;
        }
        return null;
    }

    /** Carry out the sentence, close the trial, let the hold know. Returns a note for the line. */
    function tesCourtSentence(int $courtId, string $who, array $v, string $line, string $to): string
    {
        $db = $GLOBALS['db'];
        $crime = __DIR__ . '/../tes_crime/lib.php';
        if (!function_exists('tesCrimeJail') && is_readable($crime)) {
            require_once $crime;
        }
        $db->execQuery("ALTER TABLE public.tes_court ADD COLUMN IF NOT EXISTS verdict text NOT NULL DEFAULT ''");
        tesWorldNeedGuard();
        $ref = tesWorldRefOf($who);
        $child = $ref !== '' && tesChildSafeIsChildRef($ref);
        $note = '';
        $kind = strval($v['kind']);
        if ($child && in_array($kind, ['kill', 'jail'], true)) {
            $kind = 'free';  // a child is neither executed nor jailed: let go
        }
        // the hold on the accused is taken off first in every case (he no longer stands tied for the trial)
        if ($ref !== '' && $kind !== 'jail') {
            tesWorldQueue(['prid ' . $ref, 'teshold 0', 'tesfollow 0']);
        }
        if ($kind === 'kill') {
            // the court's executioner, else the guard nearest to the condemned (never the condemned himself)
            $exec = function_exists('tesRealmExecutioner') ? tesRealmExecutioner() : '';
            if ($exec === '' || $exec === $who) {
                $exec = function_exists('tesCrimeNearestGuard') ? tesCrimeNearestGuard($who) : '';
            }
            if ($ref !== '' && ($exec === '' || !tesWorldDuel($exec, $who))) {
                tesWorldQueue(['prid ' . $ref, 'teskill', 'kill']);
                tesWorldVerifyAdd('kill', $who, $ref);
                $exec = '';
            }
            $text = "приговорён к казни";
            $note = $exec !== '' ? " *приговор: казнь {$who}; палач {$exec} уже идёт исполнять — бой до смерти*" : " *приговор: казнь {$who}; приговор исполнен*";
        } elseif ($kind === 'jail') {
            $days = intval($v['days'] ?? 1);
            if ($ref !== '' && function_exists('tesCrimeJail')) {
                $guard = function_exists('tesCrimeNearestGuard') ? tesCrimeNearestGuard($who) : '';
                tesCrimeJail($who, $ref, 'приговор суда правителя', $days, $guard);
            }
            $text = "приговорён к темнице на {$days} дн.";
            $note = " *приговор: {$who} — в темницу на {$days} дн.; стража уже ведёт*";
        } elseif ($kind === 'fine') {
            $n = intval($v['amount'] ?? 0);
            if ($ref !== '' && function_exists('tesCrimeFine')) {
                tesCrimeFine($who, $ref, $n);  // paid -> the treasury (tes_crime/preprocessing.php); unpaid -> jail
            }
            $text = "оштрафован на {$n} септимов в казну";
            $note = " *приговор: {$who} платит штраф {$n} септимов в казну; не заплатит — в темницу*";
        } else {
            if ($ref !== '' && !(function_exists('tesCrimeIsJailed') && tesCrimeIsJailed($who))) {
                tesWorldQueue(['prid ' . $ref, 'setrestrained 0', 'resetai']);
            }
            $text = $child ? 'отпущен (ребёнка не судят)' : 'оправдан и отпущен';
            $note = " *приговор: {$who} " . ($child ? 'отпущен — детей не казнят и не сажают' : 'оправдан и свободен') . '*';
        }
        $GLOBALS['TES_COURT_NOW'] = true;  // preprocessing.php: the same words are not run again as a plain order
        // «верни как было» undoes a sentence too (realm.php tesRealmUndo: kill -> resurrect, jail -> released)
        if ($ref !== '' && in_array($kind, ['kill', 'jail'], true) && function_exists('tesRealmEnsure')) {
            tesRealmEnsure();
            $db->execQuery("INSERT INTO public.tes_undo (kind, who, ref) VALUES ('{$kind}', '" . $db->escape($who) . "', '" . $db->escape($ref) . "')");
        }
        $db->execQuery("UPDATE public.tes_court SET closed = true, verdict = '" . $db->escape($text) . "' WHERE id = {$courtId}");
        tesWatchEnsure();
        tesWatchSet('court_last', '');
        if (function_exists('tesGodGuardAddRumor')) {
            $player = strval($GLOBALS['PLAYER_NAME'] ?? 'Правитель');
            tesGodGuardAddRumor("Говорят, на суде {$player} {$who} {$text}.");
        }
        tesWatchNotify("Суд окончен: {$who} {$text}");
        if (function_exists('tesWorldRememberPlace')) {
            tesWorldRememberPlace("здесь судили {$who}: {$text}");
        }
        error_log("[tes_world court] sentence: {$who} {$text} | {$line}");
        return $note;
    }

    /**
     * Turn $ref to face the player (owner, 2026-10-04: "пусть лицом ко мне смотрит"): positions of both
     * from the console (getpos), the heading is atan2(dx, dy) - Skyrim's Z angle counts from +Y, clockwise -
     * then "setangle z". Works with any bridge. Returns true when the order was sent.
     */
    function tesWorldFacePlayer(string $ref): bool
    {
        $db = $GLOBALS['db'];
        $max = $db->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_god_console_log");
        if (!tesWorldQueue(['prid 00000014', 'getpos x', 'getpos y', 'prid ' . $ref, 'getpos x', 'getpos y'])) {
            return false;
        }
        $vals = ['p' => [], 'n' => []];
        for ($i = 0; $i < 16; $i++) {
            usleep(400000);
            $rows = $db->fetchAll("SELECT command, output FROM public.tes_god_console_log WHERE id > " . intval($max['m'] ?? 0) . " ORDER BY id LIMIT 40");
            $who = '';
            $vals = ['p' => [], 'n' => []];
            foreach (is_array($rows) ? $rows : [] as $r) {
                $c = strtolower(trim(strval($r['command'])));
                if ($c === 'prid 00000014') {
                    $who = 'p';
                } elseif ($c === 'prid ' . strtolower($ref)) {
                    $who = 'n';
                } elseif ($who !== '' && preg_match('/^getpos ([xy])$/', $c, $m) && preg_match('/>>\s*(-?\d+(?:\.\d+)?)/', strval($r['output']), $v)) {
                    $vals[$who][$m[1]] = floatval($v[1]);
                }
            }
            if (count($vals['p']) === 2 && count($vals['n']) === 2) {
                break;
            }
        }
        if (count($vals['p']) !== 2 || count($vals['n']) !== 2) {
            return false;
        }
        $dx = $vals['p']['x'] - $vals['n']['x'];
        $dy = $vals['p']['y'] - $vals['n']['y'];
        $deg = fmod(rad2deg(atan2($dx, $dy)) + 360.0, 360.0);
        return tesWorldQueue(['prid ' . $ref, 'setangle z ' . round($deg, 1)]);
    }

    /** A trial lasts 10 minutes: then the accused is let go (no longer tied to the ruler). */
    function tesCourtTick(): void
    {
        tesTreasuryEnsure();
        $db = $GLOBALS['db'];
        // while the trial goes on the accused faces the ruler, turned again every 30 s
        $open = $db->fetchOne("SELECT defendant FROM public.tes_court WHERE NOT closed AND opened_at > now() - interval '10 minutes' ORDER BY id DESC LIMIT 1");
        // ...and the court is quiet: no chatter of the others among themselves (owner, 17:01: "под руку
        // Ольфина пиздит" - the people around kept talking over the trial, 32 lines in 5 minutes)
        $savedChat = tesWatchGet('chatter_court')['value'];
        if (!empty($open['defendant']) && $savedChat === '' && tesWatchGet('chatter_saved')['value'] === '') {
            $meta = $db->fetchOne("SELECT metadata->>'RECHAT_P' AS p, metadata->>'BORED_EVENT' AS b FROM public.core_profiles WHERE id = 1");
            tesWatchSet('chatter_court', json_encode(['p' => $meta['p'] ?? '10', 'b' => $meta['b'] ?? '3']));
            $db->execQuery("UPDATE public.core_profiles SET metadata = jsonb_set(jsonb_set(metadata, '{RECHAT_P}', '0'::jsonb), '{BORED_EVENT}', '0'::jsonb) WHERE id = 1");
        } elseif (empty($open['defendant']) && $savedChat !== '') {
            $s = json_decode($savedChat, true) ?: ['p' => '10', 'b' => '3'];
            if (tesWatchGet('chatter_saved')['value'] === '') {  // the budget guard keeps it off by itself
                $db->execQuery("UPDATE public.core_profiles SET metadata = jsonb_set(jsonb_set(metadata, '{RECHAT_P}', '" . intval($s['p']) . "'::jsonb), '{BORED_EVENT}', '" . intval($s['b']) . "'::jsonb) WHERE id = 1");
            }
            tesWatchSet('chatter_court', '');
        }
        if (!empty($open['defendant']) && tesWatchGet('face_at')['age'] >= 30) {
            tesWatchSet('face_at', '1');
            $fr = tesWorldRefOf(strval($open['defendant']));
            if ($fr !== '') {
                tesWorldFacePlayer($fr);
            }
        }
        $rows = $db->fetchAll("SELECT id, defendant FROM public.tes_court WHERE NOT closed AND opened_at < now() - interval '10 minutes' LIMIT 4");
        foreach (is_array($rows) ? $rows : [] as $r) {
            $db->execQuery("UPDATE public.tes_court SET closed = true WHERE id = " . intval($r['id']));
            $rf = tesWorldRefOf(strval($r['defendant']));
            if ($rf !== '' && !(function_exists('tesCrimeIsJailed') && tesCrimeIsJailed(strval($r['defendant'])))) {
                tesWorldQueue(['prid ' . $rf, 'teshold 0', 'tesfollow 0', 'setrestrained 0', 'resetai']);
            }
        }
    }

    /** One line about a trial that is going on, for the prompt of anyone in the talk, or ''. */
    function tesCourtLine(string $me): string
    {
        if (empty(tesWorldFacts()['player_title'])) {
            return '';
        }
        tesTreasuryEnsure();
        $c = $GLOBALS['db']->fetchOne("SELECT defendant, charge FROM public.tes_court WHERE opened_at > now() - interval '10 minutes' AND NOT closed ORDER BY id DESC LIMIT 1");
        if (empty($c['defendant'])) {
            return '';
        }
        $d = strval($c['defendant']);
        $venue = trim(strval(tesWatchGet('court_name')['value']));
        $charge = trim(strval($c['charge'])) !== '' ? ', обвинение: ' . trim(strval($c['charge'])) : '';
        if ($me === $d) {
            return "Тебя судит правитель{$charge}. Оправдывайся, умоляй или дерзи — в характере; приговор — его слово.";
        }
        return "Идёт суд правителя над {$d}" . ($venue !== '' ? " ({$venue})" : '') . "{$charge}. Ты присутствуешь молча: говори только если правитель или судья спросил тебя, одной короткой фразой; между собой не болтайте.";
    }
}
