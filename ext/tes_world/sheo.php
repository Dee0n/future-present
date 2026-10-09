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
    function tesSheoWonder(string $only = '', bool $force = false): string
    {
        tesFestEnsure();
        $last = tesWatchGet('sheo_at');
        if (!$force && $last['value'] !== '' && $last['age'] < 5) {
            return '';
        }
        tesWatchSet('sheo_at', '1');
        $p = tesSheoPeople();
        $n = count($p);
        $kinds = ['cheese', 'sweetroll', 'mead', 'chickens', 'hares', 'cow', 'mammoth', 'deer', 'veg', 'army', 'vermin', 'boom', 'gems', 'zoo', 'megafeast', 'herd'];
        if ($n >= 2) {
            $kinds = array_merge($kinds, ['giant', 'dance', 'laugh', 'ovation', 'band', 'knock', 'drunk', 'kneel', 'statue', 'fly', 'sizes', 'speed', 'tornado', 'freeze', 'tinyall', 'giantall', 'ball']);
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
        } elseif ($kind === 'gems') {
            // owner, 00:20: "ещё ещё ещё ещё больше"
            tesWorldQueue(['player.placeatme 0000000F 90', 'player.placeatme 00063B47 6', 'player.placeatme 00063B42 6', 'player.placeatme 00063B43 6', 'player.placeatme 00063B44 6']);
            $what = 'золотой дождь — монеты и самоцветы градом по полу, хватай кто успеет';
        } elseif ($kind === 'zoo') {
            tesWorldQueue(['player.placeatme 00101581 1', 'player.placeatme 00023A90 2', 'player.placeatme 000CF89D 3', 'player.placeatme 000A91A0 10', 'player.placeatme 0006DC9D 6', 'player.placeatme 0004359C 3']);
            $what = 'зверинец разом — мамонт, коровы, олени, козы, куры и зайцы в одной куче';
        } elseif ($kind === 'megafeast') {
            tesWorldQueue(['player.placeatme 00064B33 6', 'player.placeatme 00064B3D 16', 'player.placeatme 00064B43 10', 'player.placeatme 000E8947 10', 'player.placeatme 00034C5D 14', 'player.placeatme 00064B41 12']);
            $what = 'пир с небес — сыр, рулеты, пироги, жареные куры и мёд валятся кучей';
        } elseif ($kind === 'herd') {
            tesWorldQueue(['player.placeatme 00101581 3']);
            $what = 'три мамонта разом — в зале стало тесно';
        } elseif ($kind === 'tornado') {
            foreach ([0, 5, 10] as $d) {
                $cmds = [];
                shuffle($p);
                foreach (array_slice($p, 0, 7) as $x) {
                    $cmds[] = 'player.pushactoraway ' . $x['ref'] . ' 16';
                }
                tesFestCmd($cmds, $d);
            }
            $what = 'смерч — гостей трижды подряд подбрасывает и швыряет о стены';
        } elseif ($kind === 'freeze') {
            foreach (array_chunk($some(12), 6) as $ci => $chunk) {
                $on = [];
                $off = [];
                foreach ($chunk as $x) {
                    array_push($on, 'prid ' . $x['ref'], 'setav paralysis 1');
                    array_push($off, 'prid ' . $x['ref'], 'setav paralysis 0');
                }
                tesWorldQueue($on);
                tesFestCmd($off, 14 + $ci * 5);
            }
            $what = 'все разом окаменели на полуслове (оживут через четверть минуты)';
        } elseif ($kind === 'tinyall' || $kind === 'giantall') {
            $scale = $kind === 'tinyall' ? '0.35' : '1.9';
            foreach (array_chunk($some(12), 6) as $ci => $chunk) {
                $cmds = [];
                $back = [];
                foreach ($chunk as $x) {
                    array_push($cmds, 'prid ' . $x['ref'], 'setscale ' . $scale);
                    array_push($back, 'prid ' . $x['ref'], 'setscale 1');
                }
                tesWorldQueue($cmds);
                tesFestCmd($back, 75 + $ci * 8);
            }
            $what = $kind === 'tinyall' ? 'все гости стали ростом с кошку — толпа карликов под ногами' : 'все гости выросли в великанов — головами в потолок';
        } elseif ($kind === 'ball') {
            // everything at once: a band, the rest dance, then an ovation and a toast
            foreach ($some(3) as $i => $x) {
                tesSheoIdleLater($x['ref'], ['00096F8D', '00096F8C', '00096F8B'][$i], 1);
            }
            foreach (array_slice($p, 3, 10) as $i => $x) {
                tesSheoIdleLater($x['ref'], ['000F7C8A', '000F7C8B', '00103653'][$i % 3], 3 + intdiv($i, 3));
                tesSheoIdleLater($x['ref'], ['00066374', '00066375'][$i % 2], 22 + intdiv($i, 3));
            }
            tesWorldQueue(['player.placeatme 00034C5D 12']);
            $what = 'бал безумцев — оркестр играет, все пляшут, потом орут и хлопают, мёд рекой';
        } elseif ($kind === 'mammoth') {
            // owner, 00:17: "ещё веселее!!!" - bigger things. EncMammothTamedNoAggro: it does not attack.
            tesWorldQueue(['player.placeatme 00101581 1']);
            $what = 'посреди зала стоит мамонт — живой, настоящий, и никуда не торопится';
        } elseif ($kind === 'deer') {
            tesWorldQueue(['player.placeatme 000CF89D 4', 'player.placeatme 00023A91 2']);
            $what = 'через зал промчалось стадо оленей и лосей';
        } elseif ($kind === 'veg') {
            tesWorldQueue(['player.placeatme 00064B41 18', 'player.placeatme 00064B42 14', 'player.placeatme 00064B43 6']);
            $what = 'грянул овощной залп — картошка, помидоры и пироги во все стороны';
        } elseif ($kind === 'army') {
            tesWorldQueue(['player.placeatme 000A91A0 24']);
            $what = 'куриное войско — две дюжины кур разом';
        } elseif ($kind === 'vermin') {
            tesWorldQueue(['player.placeatme 00023AB7 4']);
            $what = 'из углов полезли злокрысы — бей их, кто смел';
        } elseif ($kind === 'boom') {
            tesWorldQueue(['player.placeatme 0010F928 1', 'player.placeatme 0010F928 1', 'player.placeatme 0010F928 1']);
            $what = 'над головами трижды грохнуло и полыхнуло зелёным';
        } elseif ($kind === 'fly') {
            $cmds = [];
            foreach ($some(8) as $x) {
                $cmds[] = 'player.pushactoraway ' . $x['ref'] . ' 14';
            }
            tesWorldQueue($cmds);
            $what = 'гостей швырнуло под потолок и размело по стенам';
        } elseif ($kind === 'sizes') {
            foreach (array_chunk($some(12), 6) as $ci => $chunk) {
                $cmds = [];
                $back = [];
                foreach ($chunk as $x) {
                    $cmds[] = 'prid ' . $x['ref'];
                    $cmds[] = 'setscale ' . ([0.4, 0.55, 0.7, 1.35, 1.6, 1.85][random_int(0, 5)]);
                    $back[] = 'prid ' . $x['ref'];
                    $back[] = 'setscale 1';
                }
                tesWorldQueue($cmds);
                tesFestCmd($back, 90 + $ci * 8);
            }
            $what = 'всех перекосило — кто по колено, кто под потолок (на полторы минуты)';
        } elseif ($kind === 'speed') {
            $cmds = [];
            $back = [];
            foreach ($some(6) as $x) {
                array_push($cmds, 'prid ' . $x['ref'], 'setav speedmult 350', 'modav carryweight 0.1');
                array_push($back, 'prid ' . $x['ref'], 'setav speedmult 100', 'modav carryweight -0.1');
            }
            tesWorldQueue($cmds);
            tesFestCmd($back, 50);
            $what = 'шестеро гостей носятся как ошпаренные — быстрее лошади';
        } elseif ($kind === 'giant') {
            [$a, $b] = [$p[0], $p[1]];
            tesWorldQueue(['prid ' . $a['ref'], 'setscale 1.7', 'prid ' . $b['ref'], 'setscale 0.45']);
            tesFestCmd(['prid ' . $a['ref'], 'setscale 1', 'prid ' . $b['ref'], 'setscale 1'], 120);
            tesFestSay($a['name'], "A wonder just swelled you to a giant's height, and {$b['name']} shrank to a dwarf. Say how it feels from above.", 5);
            tesFestSay($b['name'], "A wonder shrank you to a dwarf, and {$a['name']} grew into a giant. Protest from below.", 22);
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
            tesFestSay($a['name'], 'You just lay half a minute as a petrified statue and came back to life. Say what you saw while you were stone.', 30);
            $what = "{$a['name']} окаменел и рухнул статуей (оживёт через полминуты)";
        }
        if ($what === '') {
            return '';
        }
        tesFestNote('Чудо: ' . $what . '.', 1);
        // somebody says a word about it - not the same person every time, and never about orders or clothes
        if ($n >= 1 && !in_array($kind, ['giant', 'statue'], true)) {
            $w = $p[$n - 1];
            tesFestSay($w['name'], "A wonder just happened: {$what}. React in your own way - delight, cursing or a joke.", 7);
        }
        error_log('[tes_world] wonder: ' . $kind . ' - ' . $what);
        return $what;
    }

    /** The ruler's words: "весели нас", "ваббаджек", "чуди", "твори", "хаос", "удиви". */
    function tesSheoSpoken(string $t): string
    {
        // Off (owner, 2026-10-07: "нахуй бури и шеогората"): no wonders and no storms on the ruler's words.
        // The wonders themselves stay for Sanguine's pranks (sanguine.php).
        tesWatchSet('sheo_storm', '0');
        return '';
        // "пусть пиздец начнётся", "устрой ад", "жги": five minutes of wonders one after another; "хватит чудес" ends it
        if (preg_match('/(?<![\p{L}])(хватит|довольно|прекрати\p{L}*|останови\p{L}*|уймись|угомони\p{L}*)\s+(?:\p{L}+\s+){0,2}?(чуд\p{L}*|пиздец\p{L}*|хаос\p{L}*|безуми\p{L}*)/u', $t)) {
            tesWatchSet('sheo_storm', '0');
            return ' *the wonders ceased at the jarl\'s word*';
        }
        if (preg_match('/(?<![\p{L}])(пиздец\p{L}*|апокалипсис\p{L}*|светопреставлени\p{L}*)\s+(?:\p{L}+\s+){0,2}?(начн\p{L}*|начина\p{L}*|устро\p{L}*|давай)|(?:устро\p{L}*|начина\p{L}*|начн\p{L}*|давай|хочу)\s+(?:\p{L}+\s+){0,2}?(пиздец\p{L}*|ад(?![\p{L}])|апокалипсис\p{L}*|хаос\p{L}*|безуми\p{L}*)|(?<![\p{L}])жги(?![\p{L}])/u', $t)) {
            tesWatchSet('sheo_storm', '1');
            $what = tesSheoWonder();
            return ' *at the jarl\'s word a storm of wonders began - five minutes, one after another' . ($what !== '' ? "; the first: {$what}" : '') . '; this is really happening, react to it*';
        }
        // "ещё веселее", "ещё", "давай ещё": the storm again, from the start
        if (preg_match('/(?<![\p{L}])(еще|ещё)\s+(весел\p{L}*|больше|сильнее|жестче|жёстче|еще|ещё)|давай\s+(еще|ещё)|мало(?![\p{L}])/u', $t)) {
            tesWatchSet('sheo_storm', '1');
        }
        if (!preg_match('/(?<![\p{L}])(еще\s+весел\p{L}*|ещё\s+весел\p{L}*|ваб+адж\p{L}*|вабаджек\p{L}*|весели\p{L}*|развесел\p{L}*|повесели\p{L}*|чуди\p{L}*|чудо|чудес\p{L}*|начуди\p{L}*|твори\p{L}*|сотвори\p{L}*|натвори\p{L}*|хаос\p{L}*|безуми\p{L}*|безумств\p{L}*|удиви\p{L}*|скучно|скука|невесело|не\s+весело)(?![\p{L}])/u', $t)) {
            return '';
        }
        $only = '';
        foreach (['сыр' => 'cheese', 'рулет' => 'sweetroll', 'мед' => 'mead', 'кур' => 'chickens', 'заяц' => 'hares', 'зайц' => 'hares', 'коров' => 'cow', 'великан' => 'giant', 'карлик' => 'giant',
            'мамонт' => 'mammoth', 'олен' => 'deer', 'овощ' => 'veg', 'картош' => 'veg', 'войск' => 'army', 'крыс' => 'vermin', 'взрыв' => 'boom', 'лета' => 'fly', 'полет' => 'fly',
            'пляс' => 'dance', 'танц' => 'dance', 'смех' => 'laugh', 'хохот' => 'laugh', 'музык' => 'band', 'оркестр' => 'band', 'стату' => 'statue', 'колен' => 'kneel'] as $stem => $k) {
            if (mb_strpos($t, $stem) !== false) {
                $only = $k;
                break;
            }
        }
        $what = tesSheoWonder($only);
        return $what !== '' ? " *at the jarl's word a wonder happened: {$what}; it has really happened already - react to it, do not promise and do not suggest undressing*" : '';
    }
}
