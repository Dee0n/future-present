<?php
/*
 * tes_world: the realm around the ruler (owner, 2026-10-04: "делай всё" after the list):
 *  1. a report in the game for every order and how it ended;
 *  2. undo ("верни как было"): the last orders are written down with what undoes them;
 *  3. a report of the realm every 40 minutes (treasury, prisoners, failed orders);
 *  4. what the ruler does becomes rumours (at most one per 3 minutes);
 *  5. plots: when three or more people hate the ruler enough, they whisper and the player is told;
 *  6. court posts: treasurer, executioner, jester, cupbearer, housecarl, adviser;
 *  7. public gatherings: "собери всех женщин" - the people around come to the player;
 *  8. the arena: "пусть X и Y сразятся" - a duel of two;
 *  9. the tax rate: "налог 20%" - what the traders pay, and how they take it.
 */

if (!function_exists('tesRealmAfterOrder')) {
    function tesRealmEnsure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = $GLOBALS['db'];
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_undo (id serial PRIMARY KEY, kind text NOT NULL, who text NOT NULL, ref text NOT NULL, undone boolean NOT NULL DEFAULT false, created_at timestamptz NOT NULL DEFAULT now())");
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_posts (role text PRIMARY KEY, npc text NOT NULL, since timestamptz NOT NULL DEFAULT now())");
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_gatherings (id serial PRIMARY KEY, what text NOT NULL, created_at timestamptz NOT NULL DEFAULT now())");
    }

    function tesRealmKindWord(string $kind): string
    {
        return ['strip' => 'раздет', 'kill' => 'казнён', 'jail' => 'отправлен в темницу', 'take' => 'отдал вещи', 'bring' => 'приведён', 'beg' => 'пошёл побираться',
            'post' => 'встал на пост', 'gather' => 'собраны', 'duel' => 'дерутся'][$kind] ?? $kind;
    }

    /** After an order has been sent: report, undo record, rumour. */
    function tesRealmAfterOrder(string $kind, string $who, string $ref, string $by, string $said): void
    {
        tesRealmEnsure();
        $db = $GLOBALS['db'];
        if (function_exists('tesWatchNotify') && $kind !== 'gather') {
            tesWatchNotify('Приказ: ' . $who . ' — ' . tesRealmKindWord($kind));
        }
        if (in_array($kind, ['strip', 'kill', 'jail', 'take', 'beg', 'post'], true) && $ref !== '') {
            $db->execQuery("INSERT INTO public.tes_undo (kind, who, ref) VALUES ('" . $db->escape($kind) . "', '" . $db->escape($who) . "', '" . $db->escape($ref) . "')");
        }
        // a rumour about it, not more often than every 3 minutes
        if (in_array($kind, ['kill', 'jail', 'strip', 'take', 'beg'], true) && function_exists('tesGodGuardAddRumor') && tesWatchGet('rumor_at')['age'] >= 180) {
            tesWatchSet('rumor_at', '1');
            $player = strval($GLOBALS['PLAYER_NAME'] ?? 'Правитель');
            $text = [
                'kill' => "{$player} казнил {$who}. Весь холд говорит об этом.",
                'jail' => "{$player} бросил {$who} в темницу.",
                'strip' => "По приказу {$player} {$who} раздели на глазах у всех.",
                'take' => "{$player} забрал у {$who} всё, что тот имел.",
                'beg' => "{$who} теперь просит милостыню на улице — так решил {$player}.",
            ][$kind];
            tesGodGuardAddRumor($text);
        }
    }

    /** "Верни как было": the last order that can be undone. Returns a note or ''. */
    function tesRealmUndo(): string
    {
        tesRealmEnsure();
        $db = $GLOBALS['db'];
        $r = $db->fetchOne("SELECT * FROM public.tes_undo WHERE undone = false AND created_at > now() - interval '30 minutes' ORDER BY id DESC LIMIT 1");
        if (empty($r)) {
            return ' *откатывать нечего — недавних приказов нет*';
        }
        $id = intval($r['id']);
        $ref = strval($r['ref']);
        $who = strval($r['who']);
        $db->execQuery("UPDATE public.tes_undo SET undone = true WHERE id = {$id}");
        $ver = function_exists('tesBridgeVersion') ? tesBridgeVersion() : 1;
        switch ($r['kind']) {
            case 'kill':
                tesWorldQueue(['prid ' . $ref, 'resurrect']);
                break;
            case 'jail':
                if (function_exists('tesCrimeUnjail')) {
                    tesCrimeUnjail($who, $ref);
                }
                break;
            case 'strip':
                if ($ver >= 5) {
                    tesWorldQueue(['prid ' . $ref, 'tesredress']);
                } else {
                    return " *вернуть одежду {$who} нельзя: мост в игре старый — после перезапуска игры сможешь*";
                }
                break;
            case 'take':
                if ($ver >= 5) {
                    tesWorldQueue(['prid ' . $ref, 'tesungive']);
                } else {
                    return " *вернуть вещи {$who} нельзя: мост в игре старый — после перезапуска игры сможешь*";
                }
                break;
            case 'beg':
            case 'post':
                tesWorldQueue(['prid ' . $ref, 'tesroutine reset']);
                break;
        }
        if (function_exists('tesWatchNotify')) {
            tesWatchNotify('Откат: ' . $who . ' — ' . tesRealmKindWord(strval($r['kind'])) . ' отменено');
        }
        return " *приказ про {$who} отменён: всё возвращено как было; подтверди*";
    }

    /** A realm report every 40 minutes while the player rules. */
    function tesRealmReport(): void
    {
        if (empty(tesWorldFacts()['player_title']) || tesWatchGet('realm_report')['age'] < 2400) {
            return;
        }
        tesWatchSet('realm_report', '1');
        $db = $GLOBALS['db'];
        $jailed = $db->fetchAll("SELECT npc FROM public.tes_crime_jail WHERE status = 'jailed' ORDER BY id DESC LIMIT 3");
        $names = [];
        foreach (is_array($jailed) ? $jailed : [] as $j) {
            $names[] = strval($j['npc']);
        }
        $failed = $db->fetchOne("SELECT count(*) AS n FROM public.tes_agent_tasks WHERE status = 'failed' AND created_at > now() - interval '40 minutes'");
        $line = 'Доклад: в казне ' . tesTreasuryBalance() . '; в темнице ' . ($names ? implode(', ', $names) : 'никого') . '; не исполнено приказов ' . intval($failed['n'] ?? 0);
        tesWatchNotify(mb_substr($line, 0, 190));
    }

    /** Plots: three or more who hate the ruler (anger >= 6) whisper; the player is told, once an hour. */
    function tesRealmPlots(): void
    {
        if (empty(tesWorldFacts()['player_title']) || tesWatchGet('plot_at')['age'] < 3600) {
            return;
        }
        $db = $GLOBALS['db'];
        $rows = $db->fetchAll("SELECT npc FROM public.tes_loyalty WHERE anger - extract(epoch FROM now() - updated_at) / 3600.0 >= 6 ORDER BY anger DESC LIMIT 5");
        if (!is_array($rows) || count($rows) < 3) {
            return;
        }
        tesWatchSet('plot_at', '1');
        $names = array_map(fn($r) => strval($r['npc']), $rows);
        tesWatchSet('plot_names', json_encode($names));
        tesWatchNotify('Донос: ' . implode(', ', array_slice($names, 0, 3)) . ' шепчутся о заговоре против тебя');
        if (function_exists('tesGodGuardAddRumor')) {
            tesGodGuardAddRumor('Шепчутся, что ' . implode(' и ', array_slice($names, 0, 2)) . ' недовольны ярлом и что-то замышляют.');
        }
    }

    /** One line for a conspirator's prompt, or ''. */
    function tesRealmPlotLine(string $me): string
    {
        $p = tesWatchGet('plot_names');
        if ($p['value'] === '' || $p['age'] > 7200) {
            return '';
        }
        $names = json_decode($p['value'], true);
        if (!is_array($names) || !in_array($me, $names, true)) {
            return '';
        }
        $others = array_values(array_diff($names, [$me]));
        return 'Ты в тайном заговоре против правителя вместе с ' . implode(', ', array_slice($others, 0, 2)) . ': вы ненавидите его, говорите осторожно и ищете случай; открыто не нападай.';
    }

    // ------------------------------------------------------------------ posts

    function tesRealmPostLine(string $me): string
    {
        tesRealmEnsure();
        $r = $GLOBALS['db']->fetchOne("SELECT role FROM public.tes_posts WHERE npc = '" . $GLOBALS['db']->escape($me) . "' LIMIT 1");
        if (empty($r['role'])) {
            return '';
        }
        $duty = [
            'казначей' => 'ведёшь казну правителя и докладываешь о ней: сейчас в казне ' . tesTreasuryBalance() . ' септимов',
            'палач' => 'исполняешь казни и наказания по слову правителя, без лишних слов',
            'шут' => 'развлекаешь правителя и двор шутками, в том числе дерзкими',
            'виночерпий' => 'прислуживаешь правителю за столом, подаёшь питьё и сплетничаешь',
            'хускарл' => 'личный телохранитель правителя: не отходишь от него и защищаешь',
            'советник' => 'советник правителя: даёшь совет по законам, казне и людям',
        ][strval($r['role'])] ?? '';
        return $duty !== '' ? 'Ты при дворе правителя ' . $r['role'] . ': ' . $duty . '.' : '';
    }

    /** The executioner carries out an execution when there is one. */
    function tesRealmExecutioner(): string
    {
        tesRealmEnsure();
        $r = $GLOBALS['db']->fetchOne("SELECT npc FROM public.tes_posts WHERE role = 'палач' LIMIT 1");
        return strval($r['npc'] ?? '');
    }

    // ---------------------------------------------------------------- spoken

    /**
     * Posts, gatherings, the arena, the tax, undo - the ruler's words. Returns a note or ''.
     * $to = who is spoken to.
     */
    function tesRealmSpoken(string $line, string $to): string
    {
        if (empty(tesWorldFacts()['player_title'])) {
            return '';
        }
        $t = mb_strtolower(str_replace('ё', 'е', $line));
        // undo
        if (preg_match('/(верни\p{L}*|откат\p{L}*|отмен\p{L}*)\s+(как\s+было|приказ|последн\p{L}*|все\s+назад|это)|как\s+было\s+верни|верни\s+все\s+как\s+было/u', $t)
            && !preg_match('/закон|указ|суд|безнаказ/u', $t)) {
            return tesRealmUndo();
        }
        // the tax rate: "налог 20 процентов", "подними налог", "отмени налог"
        if (preg_match('/налог\p{L}*/u', $t) && !preg_match('/сколько|собер\p{L}*\s+налог/u', $t)) {
            $cur = intval(tesWatchGet('tax_rate')['value'] ?: 100);
            if (preg_match('/(\d{1,3})\s*(%|процент)/u', $t, $m)) {
                $new = max(0, min(300, intval($m[1])));
            } elseif (preg_match('/(подним|повыс|увелич|больше)/u', $t)) {
                $new = min(300, $cur + 50);
            } elseif (preg_match('/(сниз|пониз|уменьш|меньше)/u', $t)) {
                $new = max(0, $cur - 50);
            } elseif (preg_match('/(отмен|убер|упраздн|сними)/u', $t)) {
                $new = 0;
            } else {
                return '';
            }
            tesWatchSet('tax_rate', strval($new));
            if ($new > $cur) {
                foreach (array_slice(tesRealmTraders(), 0, 4) as $trader) {
                    tesLoyaltyBump($trader, 0.5, 1.5);  // a heavier tax is remembered by the traders
                }
            }
            return " *налог теперь {$new}% от обычного — торговцы " . ($new > $cur ? 'ропщут' : ($new < $cur ? 'довольны' : 'не заметили')) . '; подтверди*';
        }
        // gatherings: "собери всех женщин", "созови народ"
        if (preg_match('/(?<![\p{L}])(собер\p{L}*|собрать|созов\p{L}*|созвать|согнать|согони\p{L}*)\s+(?:.*?)(всех|народ|жител\p{L}*|людей|женщин|баб|мужчин|мужик\p{L}*|горожан\p{L}*)/u', $t, $gm)) {
            $group = tesWorldGroup(mb_substr($t, mb_strpos($t, $gm[2])), $to);
            $done = [];
            foreach ($group as $name) {
                $ref = tesWorldRefOf($name);
                if ($ref === '' || tesWorldIsChild($name) || (function_exists('tesCrimeIsJailed') && tesCrimeIsJailed($name))) {
                    continue;
                }
                tesWorldQueue(['prid ' . $ref, 'moveto player']);
                $done[] = $name;
            }
            if ($done) {
                tesRealmEnsure();
                $GLOBALS['db']->execQuery("INSERT INTO public.tes_gatherings (what) VALUES ('" . $GLOBALS['db']->escape(mb_substr($line, 0, 120)) . "')");
                tesWatchNotify('Собраны: ' . implode(', ', array_slice($done, 0, 6)) . (count($done) > 6 ? ' и ещё ' . (count($done) - 6) : ''));
                return ' *по приказу ярла к нему согнали: ' . implode(', ', array_slice($done, 0, 6)) . '; реагируй как один из собравшихся*';
            }
            return ' *вокруг некого собирать*';
        }
        // the arena: "пусть X и Y сразятся", "X против Y"
        if (preg_match('/(сраз\p{L}+|подерут\p{L}+|драк\p{L}+|дуэл\p{L}+|бой\s+между|против)/u', $t) && function_exists('tesWorldDuel')) {
            $near = tesWorldNearbyNames(30);
            $found = [];
            foreach (preg_split('/[^\p{L}\-]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) as $i => $w) {
                $hit = tesWorldHeardName($w, $near) ?: ($i > 0 ? tesWorldKnownName($w) : '');
                if ($hit !== '' && !in_array($hit, $found, true) && tesWorldNorm($hit) !== tesWorldNorm(strval($GLOBALS['PLAYER_NAME'] ?? ''))) {
                    $found[] = $hit;
                }
            }
            if (count($found) >= 2 && !tesWorldIsChild($found[0]) && !tesWorldIsChild($found[1])) {
                tesWorldDuel($found[0], $found[1]);
                tesWatchNotify("Бой: {$found[0]} против {$found[1]}");
                return " *{$found[0]} и {$found[1]} сошлись в бою по слову ярла; зрители ждут исхода*";
            }
        }
        // posts: "назначаю Торгара палачом", "Фианна теперь казначей"
        if (preg_match('/(назнач\p{L}*|делаю|ставлю|будешь|будет|теперь)\s+(?:.*?)(казначе\p{L}*|палач\p{L}*|шут\p{L}*|виночерпи\p{L}*|хускарл\p{L}*|советник\p{L}*)/u', $t, $pm)) {
            $roleMap = ['казначе' => 'казначей', 'палач' => 'палач', 'шут' => 'шут', 'виночерпи' => 'виночерпий', 'хускарл' => 'хускарл', 'советник' => 'советник'];
            $role = '';
            foreach ($roleMap as $stem => $name) {
                if (mb_strpos($pm[2], $stem) === 0) {
                    $role = $name;
                }
            }
            $npc = $to;
            $near = tesWorldNearbyNames(30);
            foreach (preg_split('/[^\p{L}\-]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) as $i => $w) {
                $hit = tesWorldHeardName($w, $near) ?: ($i > 0 ? tesWorldKnownName($w) : '');
                if ($hit !== '' && tesWorldNorm($hit) !== tesWorldNorm(strval($GLOBALS['PLAYER_NAME'] ?? ''))) {
                    $npc = $hit;
                    break;
                }
            }
            if ($role !== '' && $npc !== '' && !tesWorldIsChild($npc)) {
                tesRealmEnsure();
                $db = $GLOBALS['db'];
                $db->execQuery("INSERT INTO public.tes_posts (role, npc) VALUES ('" . $db->escape($role) . "', '" . $db->escape($npc) . "') ON CONFLICT (role) DO UPDATE SET npc = EXCLUDED.npc, since = now()");
                tesWatchNotify("{$npc} назначен: {$role}");
                return " *{$npc} назначен {$role}ом при дворе ярла; прими это к сведению*";
            }
        }
        return '';
    }

    function tesRealmTraders(): array
    {
        $rows = $GLOBALS['db']->fetchAll("SELECT npc_name FROM public.core_npc_master WHERE position('Торгов' in occupation) > 0 OR position('торгов' in occupation) > 0 OR position('купец' in occupation) > 0 LIMIT 6");
        return array_map(fn($r) => strval($r['npc_name']), is_array($rows) ? $rows : []);
    }

    /** A gathering that is going on: for the prompt of those in the talk. */
    function tesRealmCrowdLine(): string
    {
        tesRealmEnsure();
        $g = $GLOBALS['db']->fetchOne("SELECT what FROM public.tes_gatherings WHERE created_at > now() - interval '10 minutes' ORDER BY id DESC LIMIT 1");
        return !empty($g['what']) ? 'Правитель созвал людей к себе («' . mb_substr(strval($g['what']), 0, 80) . '»): ты в толпе, смотришь и слушаешь.' : '';
    }
}
