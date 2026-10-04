<?php
/*
 * tes_world: wonders (owner, 2026-10-05 00:07: "весели нас… Ваббаджек, что-нибудь делай уже" - Балгруф, made a
 * Sheogorath by the Narrator, only said "Ой-ей-ей" and offered to undress people who were undressed already).
 * A wonder is something that HAPPENS in the game: cheese and sweetrolls fall out of the air, chickens, hares and a
 * cow appear, a guest becomes a giant and another a dwarf (for two minutes), people dance, laugh, play music,
 * kneel, stagger drunk, get knocked off their feet, stand as a statue. Nobody is hurt and nothing is taken off.
 *  - the ruler asks for it ("весели", "ваббаджек", "чуди", "твори", "хаос", "безумие", "удиви") - one wonder at once;
 *  - while the Games of the feast run - one by itself every ~100 s.
 * The steps go through the Games' queue (festival.php): tes_fest_queue, kinds cmd / idle / say / note.
 */

if (!function_exists('tesSheoWonder')) {
    /** Play an idle of Skyrim.esm on $ref through CHIM's command channel (the console "playidle" plays nothing). */
    function tesSheoIdle(string $ref, string $idle): void
    {
        if (!class_exists('SkyrimCommandBuilder') && is_readable('/var/www/html/HerikaServer/lib/scriptproxy_papyrus.php')) {
            require_once '/var/www/html/HerikaServer/lib/scriptproxy_papyrus.php';
        }
        if (class_exists('SkyrimCommandBuilder')) {
            $b = new SkyrimCommandBuilder();
            $b->send(cmd: $b->Actor->PlayIdle('0x' . $ref, '0x' . strtolower($idle)));
        }
    }

    function tesSheoIdleLater(string $ref, string $idle, int $delay): void
    {
        tesFestPush('idle', $ref . '|' . $idle, $delay);
    }

    /** The grown people around the ruler: the guests of the feast, else those the game shows near him. */
    function tesSheoPeople(): array
    {
        $g = function_exists('tesFestGuests') ? tesFestGuests() : [];
        if (count($g) >= 3) {
            return $g;
        }
        $db = $GLOBALS['db'];
        $out = [];
        foreach (tesWorldNearbyNames(30) as $n) {
            $r = $db->fetchOne("SELECT npc_name, upper(refid) AS refid, gender, race FROM public.core_npc_master WHERE npc_name = '" . $db->escape(trim($n)) . "' LIMIT 1");
            if (empty($r['refid']) || !preg_match('/^[0-9A-F]{8}$/', strval($r['refid'])) || preg_match('/реб[её]нок|child/iu', strval($r['race']))) {
                continue;
            }
            $out[] = ['name' => strval($r['npc_name']), 'ref' => strval($r['refid']), 'female' => strtolower(strval($r['gender'])) === 'female', 'old' => false];
        }
        shuffle($out);
        return $out;
    }

    /** One wonder, now. Returns what happened (for the note in the ruler's line and the log), '' when none. */
    function tesSheoWonder(string $only = ''): string
    {
        tesFestEnsure();
        $last = tesWatchGet('sheo_at');
        if ($last['value'] !== '' && $last['age'] < 6) {
            return '';
        }
        tesWatchSet('sheo_at', '1');
        $p = tesSheoPeople();
        $n = count($p);
        $kinds = ['cheese', 'sweetroll', 'mead', 'chickens', 'hares', 'cow'];
        if ($n >= 2) {
            $kinds = array_merge($kinds, ['giant', 'dance', 'laugh', 'ovation', 'band', 'knock', 'drunk', 'kneel', 'statue']);
        }
        // not the same wonder twice in a row
        $prev = tesWatchGet('sheo_kind')['value'];
        $kinds = array_values(array_filter($kinds, fn($k) => $k !== $prev));
        $kind = ($only !== '' && in_array($only, $kinds, true)) ? $only : $kinds[array_rand($kinds)];
        tesWatchSet('sheo_kind', $kind);
        $some = fn(int $k) => array_slice($p, 0, min($k, $n));
        $what = '';
        if ($kind === 'cheese') {
            tesWorldQueue(['player.placeatme 00064B33 5', 'player.placeatme 00064B31 9']);
            $what = 'с потолка посыпался сыр — круги и ломти, прямо под ноги';
        } elseif ($kind === 'sweetroll') {
            tesWorldQueue(['player.placeatme 00064B3D 14', 'player.placeatme 00064B30 6']);
            $what = 'из воздуха посыпались сладкие рулеты и пирожные';
        } elseif ($kind === 'mead') {
            tesWorldQueue(['player.placeatme 00034C5D 10', 'player.placeatme 000508CA 6']);
            $what = 'на пол выкатились бутылки мёда — берите, пока не разбили';
        } elseif ($kind === 'chickens') {
            tesWorldQueue(['player.placeatme 000A91A0 8']);
            $what = 'по залу забегали куры — целая стая, из ниоткуда';
        } elseif ($kind === 'hares') {
            tesWorldQueue(['player.placeatme 0006DC9D 7']);
            $what = 'под ногами заметались зайцы';
        } elseif ($kind === 'cow') {
            tesWorldQueue(['player.placeatme 00023A90 1', 'player.placeatme 0004359C 2']);
            $what = 'посреди зала стоит корова, а с ней две козы';
        } elseif ($kind === 'giant') {
            [$a, $b] = [$p[0], $p[1]];
            tesWorldQueue(['prid ' . $a['ref'], 'setscale 1.7', 'prid ' . $b['ref'], 'setscale 0.45']);
            tesFestCmd(['prid ' . $a['ref'], 'setscale 1', 'prid ' . $b['ref'], 'setscale 1'], 120);
            tesFestSay($a['name'], "Тебя только что чудом раздуло до роста великана, а {$b['name']} усох до карлика. Скажи, каково тебе сверху.", 5);
            tesFestSay($b['name'], "Ты чудом усох до карлика, а {$a['name']} вырос в великана. Возмутись снизу.", 22);
            $what = "{$a['name']} вырос в великана, а {$b['name']} усох до карлика (на две минуты)";
        } elseif ($kind === 'dance') {
            foreach ($some(7) as $i => $x) {
                tesSheoIdleLater($x['ref'], ['000F7C8A', '000F7C8B', '00103653'][$i % 3], 1 + $i);
            }
            $what = 'ноги гостей сами пошли в пляс — пляшут все, кто стоял рядом';
        } elseif ($kind === 'laugh') {
            foreach ($some(8) as $i => $x) {
                tesSheoIdleLater($x['ref'], '00075C5F', 1 + $i);
            }
            $what = 'на гостей напал хохот — смеются и не могут остановиться';
        } elseif ($kind === 'ovation') {
            foreach ($some(10) as $i => $x) {
                tesSheoIdleLater($x['ref'], ['00066374', '00066375', '000D8730', '000F7C8C'][$i % 4], 1 + intdiv($i, 2));
            }
            $what = 'гости разом грянули овацию — хлопают и орут';
        } elseif ($kind === 'band') {
            foreach ($some(3) as $i => $x) {
                tesSheoIdleLater($x['ref'], ['00096F8D', '00096F8C', '00096F8B'][$i], 1 + $i);
            }
            foreach (array_slice($p, 3, 5) as $i => $x) {
                tesSheoIdleLater($x['ref'], ['000F7C8A', '000F7C8B', '00103653'][$i % 3], 5 + $i);
            }
            $names = implode(', ', array_map(fn($x) => $x['name'], $some(3)));
            $what = "заиграл оркестр: {$names} — лютня, флейта и барабан, остальные пляшут";
        } elseif ($kind === 'knock') {
            $cmds = [];
            foreach ($some(5) as $x) {
                $cmds[] = 'player.pushactoraway ' . $x['ref'] . ' 4';
            }
            tesWorldQueue($cmds);
            $what = 'невидимая рука раскидала гостей по полу, как кегли';
        } elseif ($kind === 'drunk') {
            foreach ($some(6) as $i => $x) {
                tesSheoIdleLater($x['ref'], '000CEFD0', 1 + $i);
                tesSheoIdleLater($x['ref'], '000CEFD1', 70 + $i);
            }
            $what = 'гостей разом развезло — шатаются, будто выпили по бочке';
        } elseif ($kind === 'kneel') {
            foreach ($some(9) as $i => $x) {
                tesSheoIdleLater($x['ref'], '000E8E52', 1 + intdiv($i, 2));
                tesSheoIdleLater($x['ref'], '000E8E53', 28 + intdiv($i, 2));
            }
            $what = 'все вокруг рухнули на колени перед ярлом — сами не поняли как';
        } elseif ($kind === 'statue') {
            $a = $p[0];
            tesWorldQueue(['prid ' . $a['ref'], 'setav paralysis 1', 'player.pushactoraway ' . $a['ref'] . ' 1']);
            tesFestCmd(['prid ' . $a['ref'], 'setav paralysis 0'], 25);
            tesFestSay($a['name'], 'Ты только что полминуты пролежал окаменевшей статуей и ожил. Скажи, что ты видел, пока был камнем.', 30);
            $what = "{$a['name']} окаменел и рухнул статуей (оживёт через полминуты)";
        }
        if ($what === '') {
            return '';
        }
        tesFestNote('Чудо: ' . $what . '.', 1);
        // somebody says a word about it - not the same person every time, and never about orders or clothes
        if ($n >= 1 && !in_array($kind, ['giant', 'statue'], true)) {
            $w = $p[$n - 1];
            tesFestSay($w['name'], "Только что случилось чудо: {$what}. Отзовись на это по-своему — восторг, ругань или шутка.", 7);
        }
        error_log('[tes_world] wonder: ' . $kind . ' - ' . $what);
        return $what;
    }

    /** The ruler's words: "весели нас", "ваббаджек", "чуди", "твори", "хаос", "удиви". */
    function tesSheoSpoken(string $t): string
    {
        if (!preg_match('/(?<![\p{L}])(ваб+адж\p{L}*|вабаджек\p{L}*|весели\p{L}*|развесел\p{L}*|повесели\p{L}*|чуди\p{L}*|чудо|чудес\p{L}*|начуди\p{L}*|твори\p{L}*|сотвори\p{L}*|натвори\p{L}*|хаос\p{L}*|безуми\p{L}*|безумств\p{L}*|удиви\p{L}*|скучно|скука|невесело|не\s+весело)(?![\p{L}])/u', $t)) {
            return '';
        }
        $only = '';
        foreach (['сыр' => 'cheese', 'рулет' => 'sweetroll', 'мед' => 'mead', 'кур' => 'chickens', 'заяц' => 'hares', 'зайц' => 'hares', 'коров' => 'cow', 'великан' => 'giant', 'карлик' => 'giant',
            'пляс' => 'dance', 'танц' => 'dance', 'смех' => 'laugh', 'хохот' => 'laugh', 'музык' => 'band', 'оркестр' => 'band', 'стату' => 'statue', 'колен' => 'kneel'] as $stem => $k) {
            if (mb_strpos($t, $stem) !== false) {
                $only = $k;
                break;
            }
        }
        $what = tesSheoWonder($only);
        return $what !== '' ? " *по слову ярла случилось чудо: {$what}; это уже произошло на самом деле — отзовись на это, не обещай и не предлагай раздеваться*" : '';
    }
}
