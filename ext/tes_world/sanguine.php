<?php
/*
 * tes_world: the first god - Sanguine, Daedric Prince of revelry (roadmap stage G, first prototype).
 *
 * CHIM has one Narrator, so Sanguine speaks through it: a line that names him ("Сангвин, …") is answered in his
 * voice, and what he does really happens in the game:
 *  - "выпьем / угости / налей"        - ale for the player, the people around drink;
 *  - "спорим на 500 / пари на 500"   - a wager: two minutes later he decides; won - the stake doubled, lost - the
 *                                       stake is his and a prank on top (a wonder of sheo.php);
 *  - "подари / дар / одари"           - a gift with a catch; at high favour, once, his Rose;
 *  - by himself, now and then, in the ruler's hold: a prank and a rumour about it (no model call).
 * His favour (tes_watch 'sanguine_favor', 0-100) grows with feasts, drink and wagers and makes his luck kinder.
 * Nothing he does hurts anybody or takes clothes off; children are never touched (sheo.php/childsafe).
 */

if (!function_exists('tesSanguineSpoken')) {
    function tesSanguineFavor(int $delta = 0): int
    {
        tesWatchEnsure();
        $f = tesWatchGet('sanguine_favor')['value'];
        $f = $f === '' ? 40 : intval($f);
        if ($delta !== 0) {
            $f = max(0, min(100, $f + $delta));
            tesWatchSet('sanguine_favor', strval($f));
        }
        return $f;
    }

    /** A harmless wonder (food, animals, music, dancing) - for what he does unasked or as a catch. */
    function tesSanguineSafeWonder(): string
    {
        if (!function_exists('tesSheoWonder')) {
            return '';
        }
        $safe = ['cheese', 'sweetroll', 'mead', 'chickens', 'hares', 'dance', 'laugh', 'ovation', 'band', 'drunk'];
        return tesSheoWonder($safe[random_int(0, count($safe) - 1)], true);
    }

    function tesSanguineEnsure(): void
    {
        $GLOBALS['db']->execQuery("CREATE TABLE IF NOT EXISTS public.tes_sanguine_bets (id serial PRIMARY KEY, stake bigint NOT NULL, what text NOT NULL DEFAULT '', created_at timestamptz NOT NULL DEFAULT now(), settled boolean NOT NULL DEFAULT false, won boolean)");
    }

    /** How Sanguine talks - for the Narrator, put after the ruler's words. */
    function tesSanguineVoice(): string
    {
        $f = tesSanguineFavor();
        $mood = $f >= 70 ? 'ты в восторге от этого смертного и щедр' : ($f <= 25 ? 'смертный тебе наскучил, ты язвителен и ленив' : 'тебе любопытно, что ещё выкинет этот смертный');
        return 'Отвечает сам Сангвин, даэдрический принц разгула и порока, — говори его голосом: развязно, весело, с подвохом и намёками, '
            . 'обращайся к ярлу как к собутыльнику; ' . $mood . '. Одна-две фразы.';
    }

    /** The ruler names Sanguine. Returns a note for the line (Sanguine's voice + what happened) or ''. */
    function tesSanguineSpoken(string $line, string $to): string
    {
        $t = mb_strtolower(str_replace('ё', 'е', $line));
        if (!preg_match('/(?<![\p{L}])(сангвин\p{L}*|сангвинн\p{L}*|сан\s?гвин\p{L}*)(?![\p{L}])/u', $t)) {
            return '';
        }
        tesSanguineEnsure();
        $db = $GLOBALS['db'];
        $did = '';
        tesWorldNeedGuard();
        tesWatchEnsure();
        // "Сангвин, уймись / отстань / хватит" - no pranks of his own until "Сангвин, шали / снова твори"
        if (preg_match('/(?<![\p{L}])(уймись|угомонись|успокойся|отстань|отвали|хватит|не\s+шали|перестань|прекрати|довольно)(?![\p{L}])/u', $t)) {
            tesWatchSet('sanguine_quiet', '1');
            $did = 'ярл велел тебе уняться: сам ты больше не шалишь, пока он не позовёт — согласись, но обиженно';
        } elseif (preg_match('/(?<![\p{L}])(шали\p{L}*|снова\s+твори|возвращайся|можешь\s+шалить|балуй\p{L}*)(?![\p{L}])/u', $t)) {
            tesWatchSet('sanguine_quiet', '0');
            $did = 'ярл снова разрешил тебе шалить — обрадуйся';
        } elseif (preg_match('/(?<![\p{L}])(спор\p{L}*|пари|ставк\p{L}*|ставлю|забьемся|на\s+спор)(?![\p{L}])/u', $t)) {
            $n = tesWorldSpokenAmount($line);
            if ($n <= 0) {
                $did = 'ярл предложил пари, но не назвал ставку — потребуй сумму';
            } else {
                $open = $db->fetchOne("SELECT 1 AS x FROM public.tes_sanguine_bets WHERE NOT settled LIMIT 1");
                if (!empty($open)) {
                    $did = 'одно пари с ярлом уже идёт — пусть дождётся исхода';
                } else {
                    $gold = function_exists('tesWorldPlayerGold') ? tesWorldPlayerGold() : -1;
                    if ($gold >= 0 && $gold < $n) {
                        $did = "у ярла при себе только {$gold} септимов — ставка {$n} ему не по карману, высмей это";
                    } else {
                        $what = trim(preg_replace('/^.*?(?:спор\p{L}*|пари|на\s+спор)[,:]?\s*(?:что\s+)?/u', '', mb_substr($line, 0, 200), 1) ?? '');
                        $db->execQuery("INSERT INTO public.tes_sanguine_bets (stake, what) VALUES ({$n}, '" . $db->escape(mb_substr($what, 0, 160)) . "')");
                        tesWorldQueue(['player.removeitem 0000000F ' . $n]);
                        tesSanguineFavor(5);
                        $did = "пари принято: ставка {$n} септимов уже у тебя; исход ты объявишь через пару минут";
                    }
                }
            }
        } elseif (preg_match('/(?<![\p{L}])(выпь\p{L}*|выпить|угост\p{L}*|налей\p{L}*|наливай|бухн\p{L}*|бухать|выпивк\p{L}*|пир\p{L}*|гуля\p{L}*)(?![\p{L}])/u', $t)) {
            $cmds = ['player.additem 00034C5E 3'];
            tesWorldQueue($cmds);
            $n = 0;
            foreach (array_slice(function_exists('tesSheoPeople') ? tesSheoPeople() : [], 0, 4) as $p) {
                $ref = is_array($p) ? strval($p['ref'] ?? '') : tesWorldRefOf(strval($p));
                if ($ref !== '' && !tesChildSafeIsChildRef($ref) && function_exists('tesFestDrink')) {
                    tesFestDrink($ref, 2 + 3 * $n);
                    $n++;
                }
            }
            tesSanguineFavor(4);
            $did = 'у ярла в руках три эля от тебя' . ($n ? ", и {$n} человек вокруг уже пьют за тебя" : '');
        } elseif (preg_match('/(?<![\p{L}])(подар\p{L}*|дар|дары|одари\p{L}*|награ\p{L}*|дай\s+(?:мне\s+)?что\p{L}*)(?![\p{L}])/u', $t)) {
            $f = tesSanguineFavor();
            if ($f >= 75 && tesWatchGet('sanguine_rose')['value'] !== '1') {
                tesWatchSet('sanguine_rose', '1');
                tesWorldQueue(['player.additem 0001CB36 1']);
                $did = 'ты одарил ярла своей Розой — посохом, что призывает дремору; скажи, что это знак особой милости';
            } else {
                $gifts = [
                    [['player.additem 0000000F 300', 'player.additem 00034C5E 2'], '300 септимов и два эля — но золото пахнет кабаком, и ты хохочешь над этим'],
                    [['player.additem 00034C5E 6'], 'шесть элей — «пей, пока не увидишь меня дважды»'],
                    [['player.additem 0000000F 777'], '777 септимов — «на удачу, а удача у меня капризная»'],
                ];
                $g = $gifts[random_int(0, count($gifts) - 1)];
                tesWorldQueue($g[0]);
                $prank = tesSanguineSafeWonder();
                $did = 'ты одарил ярла: ' . $g[1] . ($prank !== '' ? '; и тут же подвох: ' . $prank : '');
            }
            tesSanguineFavor(-3);  // gifts are not free: he likes being amused, not asked
        } elseif (preg_match('/(?<![\p{L}])(чуд\p{L}*|повесели\p{L}*|развесели\p{L}*|пошали\p{L}*|шутк\p{L}*)(?![\p{L}])/u', $t) && function_exists('tesSheoWonder')) {
            $w = tesSheoWonder('', true);
            tesSanguineFavor(2);
            $did = $w !== '' ? 'ты устроил: ' . $w : '';
        } elseif (preg_match('/(?<![\p{L}])(как\s+(?:ты|дела)|что\s+скажешь|милост\p{L}*|благоскл\p{L}*|доволен)(?![\p{L}])/u', $t)) {
            $did = 'твоё расположение к ярлу: ' . tesSanguineFavor() . ' из 100 — скажи это по-своему, без цифр';
        }
        if ($did !== '' && function_exists('tesGodGuardAddRumor') && tesWatchGet('sanguine_rumor')['age'] >= 900) {
            tesWatchSet('sanguine_rumor', '1');
            tesGodGuardAddRumor('Говорят, ' . strval($GLOBALS['PLAYER_NAME'] ?? 'ярл') . ' водит дружбу с самим Сангвином, принцем разгула.');
        }
        if (stripos($to, 'Narrator') === false && $to !== '') {
            // said to a person: he is not the god - he hears the Prince laugh and reacts as himself
            return ' *откуда-то донёсся смех Сангвина, принца разгула' . ($did !== '' ? '; что произошло: ' . preg_replace('/(?<![\p{L}])ты(?![\p{L}])/u', 'Сангвин', $did) : '') . '; отреагируй на это по-своему*';
        }
        return ' *' . tesSanguineVoice() . ($did !== '' ? ' Что уже произошло: ' . $did . '.' : '') . '*';
    }

    /** Wagers are settled, and now and then Sanguine plays a prank in the ruler's hold by himself. */
    function tesSanguineTick(): void
    {
        $db = $GLOBALS['db'];
        tesWorldNeedGuard();
        $has = $db->fetchOne("SELECT to_regclass('public.tes_sanguine_bets') AS t");
        if (!empty($has['t'])) {
            $bet = $db->fetchOne("SELECT id, stake, what FROM public.tes_sanguine_bets WHERE NOT settled AND created_at < now() - interval '2 minutes' ORDER BY id LIMIT 1");
            if (!empty($bet['id'])) {
                $f = tesSanguineFavor();
                $won = random_int(1, 100) <= 35 + intdiv($f, 3);  // 35 % at no favour, ~68 % at full
                $stake = intval($bet['stake']);
                $db->execQuery("UPDATE public.tes_sanguine_bets SET settled = true, won = " . ($won ? 'true' : 'false') . " WHERE id = " . intval($bet['id']));
                if ($won) {
                    tesWorldQueue(['player.additem 0000000F ' . ($stake * 2)]);
                    tesWatchNotify("Сангвин проиграл пари: тебе " . ($stake * 2) . " септимов");
                    tesSanguineFavor(3);
                } else {
                    $prank = tesSanguineSafeWonder();
                    tesWatchNotify("Сангвин выиграл пари и забрал {$stake} септимов" . ($prank !== '' ? " — а ещё: {$prank}" : ''));
                    tesSanguineFavor(6);  // he loves winning
                }
                if (function_exists('tesGodGuardAddRumor')) {
                    tesGodGuardAddRumor('Говорят, ' . strval($GLOBALS['PLAYER_NAME'] ?? 'ярл') . ($won ? ' выиграл пари у Сангвина — ' . ($stake * 2) . ' септимов!' : " проиграл Сангвину пари на {$stake} септимов."));
                }
            }
        }
        // by himself: once in 25-40 minutes, only in the ruler's own hold, never during a trial or an execution
        if (empty(tesWorldFacts()['player_title']) || !function_exists('tesSheoWonder')) {
            return;
        }
        tesWatchEnsure();
        if (tesWatchGet('sanguine_quiet')['value'] === '1') {
            return;  // "Сангвин, уймись"
        }
        $next = intval(tesWatchGet('sanguine_next')['value']);
        if ($next === 0) {
            tesWatchSet('sanguine_next', strval(time() + random_int(1500, 2400)));
            return;
        }
        if (time() < $next) {
            return;
        }
        tesWatchSet('sanguine_next', strval(time() + random_int(1500, 2400)));
        $trial = $db->fetchOne("SELECT 1 AS x FROM public.tes_court WHERE NOT closed AND opened_at > now() - interval '10 minutes' LIMIT 1");
        if (!empty($trial) || (function_exists('tesWorldExecutionGoing') && tesWorldExecutionGoing()) || (function_exists('tesWorldQueueBusy') && tesWorldQueueBusy())) {
            return;
        }
        $w = tesSanguineSafeWonder();
        if ($w !== '') {
            tesWatchNotify("Где-то рядом смеётся Сангвин: {$w}");
            if (function_exists('tesGodGuardAddRumor') && tesWatchGet('sanguine_rumor')['age'] >= 900) {
                tesWatchSet('sanguine_rumor', '1');
                tesGodGuardAddRumor("Говорят, в замке творится чертовщина: {$w}. Не иначе Сангвин шалит.");
            }
        }
    }
}
