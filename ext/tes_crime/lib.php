<?php
/*
 * tes_crime: the law applies to NPCs the way it does to the player.
 *
 * Owner, 2026-10-04: "им дают штраф, пытаются посадить как ГГ, садят в тюрьму".
 * {npc:X}.fine N (tes_god_guard) -> tesCrimeFine():
 *   1. the nearest guard is told to demand the fine out loud;
 *   2. the game is asked how much gold the NPC carries (console "getitemcount", read back
 *      through the bridge's report - works with any bridge version);
 *   3. preprocessing.php gets the answer: enough gold -> it is taken; not enough -> the NPC
 *      is moved into the hold's jail and held there ({npc:X}.unjail lets him go);
 *   4. the guard and the NPC are told what happened and say it in their own words.
 */

if (!function_exists('tesCrimeFine')) {
    function tesCrimeEnsureTable(): void
    {
        $GLOBALS['db']->execQuery("
            CREATE TABLE IF NOT EXISTS public.tes_crime_fines (
                id bigserial PRIMARY KEY,
                created_at timestamptz NOT NULL DEFAULT now(),
                npc text NOT NULL,
                refid text NOT NULL,
                amount bigint NOT NULL,
                guard text NOT NULL DEFAULT '',
                jail_ref text NOT NULL DEFAULT '',
                status text NOT NULL DEFAULT 'asked',   -- asked, paid, jailed
                gold bigint
            )
        ");
    }

    function tesCrimeQueue(array $commands): bool
    {
        $db = $GLOBALS['db'];
        $quest = $db->fetchOne("SELECT quest_key FROM public.skyrim_quest_instances ORDER BY quest_key LIMIT 1");
        if (empty($quest['quest_key'])) {
            return false;
        }
        $db->insert('skyrim_quest_action_outbox', [
            'quest_key' => $quest['quest_key'], 'beat_id' => 'tes_crime', 'action_type' => 'console_command_sequence',
            'payload_json' => json_encode(['type' => 'console_command_sequence', 'commands' => $commands]),
        ]);
        return true;
    }

    function tesCrimeTell(string $npc, string $instruction): void
    {
        $instruction = trim(str_replace(['@', '|', "\n", "\r"], [' ', '/', ' ', ' '], $instruction));
        $GLOBALS['db']->insert('responselog', [
            'localts' => time(), 'sent' => 0, 'actor' => 'rolemaster', 'text' => '',
            'action' => 'rolecommand|Instruction@' . str_replace(['@', '|'], ' ', $npc) . '@' . mb_substr($instruction, 0, 600) . '@0', 'tag' => '',
        ]);
    }

    /**
     * A command to an NPC in CHIM's own wire format ("Имя|command|MoveTo@Цель") - the same thing the
     * NPC's model sends when it decides to walk somewhere. Works with any bridge version.
     */
    function tesCrimeNpcCommand(string $npc, string $command): void
    {
        $GLOBALS['db']->insert('responselog', [
            'localts' => time(), 'sent' => 0, 'actor' => str_replace(['|', '@'], ' ', $npc), 'text' => '',
            'action' => 'command|' . str_replace(["\n", "\r", '|'], ' ', $command), 'tag' => '',
        ]);
    }

    /** Where the arrested is told to walk: the building that holds the hold's jail. */
    function tesCrimeJailPlace(): string
    {
        $row = $GLOBALS['db']->fetchOne("SELECT data FROM eventlog WHERE type IN ('infoloc', 'request') AND data LIKE '%Context location:%' ORDER BY rowid DESC LIMIT 1");
        $loc = strval($row['data'] ?? '');
        if (preg_match('/Hold:\s*Вайтран/u', $loc)) {
            return preg_match('/Context location:\s*Драконий Предел/u', $loc) ? 'Драконий Предел - Подземелье' : 'Драконий Предел';
        }
        return '';
    }

    function tesCrimeNotify(string $text): void
    {
        $text = trim(str_replace(['@', '|', "\n", "\r"], [' ', '/', ' ', ' '], $text));
        $GLOBALS['db']->insert('responselog', [
            'localts' => time(), 'sent' => 0, 'actor' => 'rolemaster', 'text' => '',
            'action' => 'rolecommand|DebugNotification@' . mb_substr($text, 0, 200), 'tag' => '',
        ]);
    }

    /*
     * Jails per hold, from Skyrim.esm (2026-10-04): [inside, outside].
     * inside  = the PrisonMarker reference (base STAT 00000004) in the jail cell - where the game
     *           itself puts the arrested player;
     * outside = the crime faction's exterior jail marker (FACT JAIL) - where the game lets the
     *           player out after serving the time.
     */
    function tesCrimeJails(): array
    {
        return [
            'вайтран' => ['000267E4', '000267E5'], 'истмарк' => ['00058CF8', '0003EF18'], 'фолкрит' => ['0003EF07', '0003EF17'],
            'хаафингар' => ['0003EEFE', '0003EF19'], 'хьялмарк' => ['0003EF08', '0003EF1A'], 'белый берег' => ['0003EF11', '0003EF1B'],
            'предел' => ['0003EF04', '0003EF57'], 'рифт' => ['00045D5B', '0003EF58'], 'винтерхолд' => ['0003EF14', '0003EF59'],
        ];
    }

    /** [inside, outside] for the hold the player is in now (Whiterun when unknown). */
    function tesCrimeJailOfHold(): array
    {
        $jails = tesCrimeJails();
        $row = $GLOBALS['db']->fetchOne("SELECT data FROM eventlog WHERE type IN ('infoloc', 'request') AND data LIKE '%Hold:%' ORDER BY rowid DESC LIMIT 1");
        if (preg_match('/Hold:\s*([^,)]+)/u', strval($row['data'] ?? ''), $m)) {
            return $jails[mb_strtolower(trim($m[1]))] ?? $jails['вайтран'];
        }
        return $jails['вайтран'];
    }

    function tesCrimeJailRef(): string
    {
        return tesCrimeJailOfHold()[0];
    }

    define('TES_CRIME_RAGS', '0003C9FE');   // REQ_Cloth_Prisoner_Body "Домотканая одежда"
    define('TES_CRIME_WRAPS', '0003CA00');  // REQ_Cloth_Prisoner_Feet "Ножные обмотки"
    define('TES_CRIME_DAY', 10000000);      // gamets per game day (game seconds = gamets * 0.00864)
    define('TES_CRIME_MAX_DAYS', 3650);     // "пожизненно" = ten game years

    /**
     * The term in game days from words: "на 5 дней", "на неделю", "на месяц", "на три года",
     * "пожизненно". 0 = no term said. Owner, 2026-10-04: "в тюрьме день сидят, а не сколько
     * сказано" - every path read only "N сут/дн" digits and fell back to one day.
     */
    function tesCrimeTerm(string $text): int
    {
        $t = mb_strtolower(str_replace('ё', 'е', $text));
        if (preg_match('/(пожизненн|навсегда|навечно|до конца (жизни|дней)|до смерти)/u', $t)) {
            return TES_CRIME_MAX_DAYS;
        }
        $words = ['один' => 1, 'одну' => 1, 'одна' => 1, 'два' => 2, 'две' => 2, 'три' => 3, 'четыре' => 4, 'пять' => 5, 'шесть' => 6,
            'семь' => 7, 'восемь' => 8, 'девять' => 9, 'десять' => 10, 'пятнадцать' => 15, 'двадцать' => 20, 'тридцать' => 30,
            'сорок' => 40, 'пятьдесят' => 50, 'сто' => 100, 'двести' => 200, 'пол' => 0.5];
        $units = ['(?:сут\p{L}*|дн\p{L}*|день|дня)' => 1, 'недел\p{L}*' => 7, 'месяц\p{L}*' => 30, '(?:год\p{L}*|лет)' => 365];
        $num = '(\d+|' . implode('|', array_keys($words)) . ')';
        foreach ($units as $u => $mult) {
            if (preg_match('/(?<![\p{L}\d])' . $num . '\s*' . $u . '/u', $t, $m)) {
                $n = is_numeric($m[1]) ? intval($m[1]) : $words[$m[1]];
                return max(1, min(TES_CRIME_MAX_DAYS, intval(round($n * $mult))));
            }
            if (preg_match('/(?<![\p{L}])на\s+(?:одн[иу]\s+)?' . $u . '/u', $t)) {
                return max(1, min(TES_CRIME_MAX_DAYS, $mult));
            }
        }
        return 0;
    }

    function tesCrimeEnsureJailTable(): void
    {
        $GLOBALS['db']->execQuery("
            CREATE TABLE IF NOT EXISTS public.tes_crime_jail (
                id bigserial PRIMARY KEY,
                created_at timestamptz NOT NULL DEFAULT now(),
                npc text NOT NULL,
                refid text NOT NULL,
                inside_ref text NOT NULL,
                outside_ref text NOT NULL,
                jailed_gamets bigint NOT NULL DEFAULT 0,
                release_gamets bigint NOT NULL DEFAULT 0,
                reason text NOT NULL DEFAULT '',
                status text NOT NULL DEFAULT 'jailed',   -- jailed, released
                last_hold timestamptz
            )
        ");
        // escort: stage catch (the guard goes to him) -> walk (he is led to the cell) -> in
        $GLOBALS['db']->execQuery("ALTER TABLE public.tes_crime_jail ADD COLUMN IF NOT EXISTS stage text NOT NULL DEFAULT 'in',
            ADD COLUMN IF NOT EXISTS stage_at timestamptz NOT NULL DEFAULT now(), ADD COLUMN IF NOT EXISTS guard_ref text NOT NULL DEFAULT ''");
    }

    /** Is this NPC (by name) under arrest or in a cell right now? */
    function tesCrimeIsJailed(string $npc): bool
    {
        static $names = null;
        if ($names === null) {
            $names = [];
            $has = $GLOBALS['db']->fetchOne("SELECT to_regclass('public.tes_crime_jail') AS t");
            if (!empty($has['t'])) {
                $rows = $GLOBALS['db']->fetchAll("SELECT npc FROM public.tes_crime_jail WHERE status = 'jailed'");
                foreach (is_array($rows) ? $rows : [] as $r) {
                    $names[trim(strval($r['npc']))] = true;
                }
            }
        }
        return isset($names[trim($npc)]);
    }

    function tesCrimeGamets(): int
    {
        $g = intval($GLOBALS['gameRequest'][2] ?? 0);
        if ($g <= 0) {
            $row = $GLOBALS['db']->fetchOne("SELECT max(gamets) AS g FROM eventlog WHERE localts > " . (time() - 600));
            $g = intval($row['g'] ?? 0);
        }
        return $g;
    }

    /**
     * A prisoner cannot walk. Owner, 2026-10-04 15:10: "все неписи с камер сбегают" - "restrained"
     * and a package near the prison marker were not enough: the Whiterun cell has a way out, and a
     * sandboxing NPC finds it. Speed 0 holds whatever package the game or CHIM gives him (the
     * carry-weight nudge makes the engine re-read the speed). tesCrimeFreeLegs() undoes it.
     */
    function tesCrimeLegIrons(): array
    {
        // 17:15: speedmult 0 made the prisoners "walk in place" (the sandbox package kept sending them
        // somewhere and the legs did not move). teshold's SetDontMove holds them without that.
        return [];
    }

    function tesCrimeFreeLegs(): array
    {
        return ['setav speedmult 100', 'modav carryweight 1', 'modav carryweight -1'];
    }

    /**
     * How a prisoner is held, by what the game's bridge can do. v11+: "teshold" (CHIM's do-nothing package,
     * SetDontMove, the AI stays on). Older bridges switched the AI OFF for it, and an actor without AI freezes
     * in the T-pose (owner, 22:08: "люди в тюрьме в T позе"): there the AI is given back and he lives around
     * the cell ("tesroutine at"); the warden brings back whoever walks out.
     */
    function tesCrimeHoldCmd(string $insideRef): array
    {
        $ver = function_exists('tesWatchGet') ? intval(tesWatchGet('bridge_ver')['value']) : 0;
        if ($ver >= 11) {
            return ['teshold ' . hexdec($insideRef)];
        }
        return ['teshold 0', 'tesroutine at ' . hexdec($insideRef)];
    }
    /** Console sequence that puts the actor into the cell: prisoner clothes, cannot leave. */
    function tesCrimeJailCommands(string $ref, string $insideRef): array
    {
        // Owner, 2026-10-04: "в тюрьму полностью раздевать, всю броню снимать, оружие забирать и в
        // домотканую одежду и ножные обмотки". unequipall alone left everything in the inventory and
        // the prisoner put his armour back on. tesjailbox in (bridge): what he wears is remembered,
        // EVERYTHING he carries goes into his own hidden chest; tesjailbox out gives it back and
        // dresses him again. teshold: the cell becomes his schedule (without it the game walked
        // Хеймскр back to his statue). A bridge without these prints "not found" and goes on.
        // Children are held but never undressed.
        $child = function_exists('tesGodGuardIsChild') && tesGodGuardIsChild($ref);
        $strip = $child ? [] : ['tesjailbox in', 'unequipall',
            'additem ' . TES_CRIME_RAGS . ' 1', 'equipitem ' . TES_CRIME_RAGS . ' 1',
            'additem ' . TES_CRIME_WRAPS . ' 1', 'equipitem ' . TES_CRIME_WRAPS . ' 1'];
        return array_merge(['prid ' . $ref, 'stopcombat', 'moveto ' . $insideRef], $strip, array_merge(['setrestrained 1'], tesCrimeHoldCmd($insideRef)), tesCrimeLegIrons());
    }

    /** Put an escaped prisoner back: he already wears the rags, so only move and hold. */
    function tesCrimeHoldCommands(string $ref, string $insideRef): array
    {
        $child = function_exists('tesGodGuardIsChild') && tesGodGuardIsChild($ref);
        return array_merge(['prid ' . $ref, 'stopcombat', 'moveto ' . $insideRef], $child ? [] : ['equipitem ' . TES_CRIME_RAGS . ' 1'],
            array_merge(['setrestrained 1'], tesCrimeHoldCmd($insideRef)), tesCrimeLegIrons());
    }

    /**
     * Jail an NPC for $days game days. Returns [ok, message].
     * Owner, 2026-10-04: "система тюрьмы должна быть с провожаниями, а не тп в тюрьму… идти до них,
     * ловить". With a guard around it is an arrest on foot: the guard goes to him (bridge
     * tesfollow), then he is led to the cell (tesescort - CHIM's travel package to the prison
     * marker), and only there he is stripped and locked up (tesCrimeEscortTick). No guard near,
     * or a bridge without these commands - straight into the cell, as before.
     */
    function tesCrimeJail(string $npc, string $refId, string $reason = '', int $days = 1, string $guard = ''): array
    {
        $refId = strtoupper(trim($refId));
        if (!preg_match('/^[0-9A-F]{8}$/', $refId)) {
            return [false, "«{$npc}»: не знаю его RefID — посадить не могу"];
        }
        tesCrimeEnsureJailTable();
        $db = $GLOBALS['db'];
        [$inside, $outside] = tesCrimeJailOfHold();
        $guard = ($guard !== '' && $guard !== $npc && mb_stripos($guard, 'Стражник') !== false) ? $guard : tesCrimeNearestGuard($npc);
        $guardRef = '';
        if ($guard !== '' && function_exists('tesGodGuardResolveNpcLoose') && class_exists('RelationshipManager')) {
            $g = tesGodGuardResolveNpcLoose($guard);
            $guardRef = strtoupper(trim(strval($g['refid'] ?? '')));
        }
        // From a spoken order (preprocessing) tes_god_guard's resolver is not loaded yet: the guard was never
        // found, nobody walked the prisoner and he was teleported into the cell (owner, 17:07: "тп в
        // темницу, а не проводит"). The exact name straight from the table then.
        if ($guard !== '' && !preg_match('/^[0-9A-F]{8}$/', $guardRef)) {
            $gr = $db->fetchOne("SELECT refid FROM public.core_npc_master WHERE npc_name = '" . $db->escape($guard) . "' LIMIT 1");
            $guardRef = strtoupper(trim(strval($gr['refid'] ?? '')));
        }
        $escort = preg_match('/^[0-9A-F]{8}$/', $guardRef) && $guardRef !== $refId;
        $first = $escort ? ['prid ' . $refId, 'stopcombat', 'prid ' . $guardRef, 'tesfollow ' . hexdec($refId)] : tesCrimeJailCommands($refId, $inside);
        if (!tesCrimeQueue($first)) {
            return [false, "«{$npc}»: канал игры недоступен"];
        }
        if ($escort) {
            tesCrimeNpcCommand($guard, 'MoveTo@' . $npc);  // the guard walks up to him
        }
        $now = tesCrimeGamets();
        $db->execQuery("UPDATE public.tes_crime_jail SET status = 'released' WHERE status = 'jailed' AND refid = '{$refId}'");
        $db->execQuery("ALTER TABLE public.tes_crime_jail ADD COLUMN IF NOT EXISTS release_at timestamptz");
        $db->insert('tes_crime_jail', ['npc' => $npc, 'refid' => $refId, 'inside_ref' => $inside, 'outside_ref' => $outside,
            'jailed_gamets' => $now, 'release_gamets' => $now + TES_CRIME_DAY * max(1, $days), 'reason' => mb_substr($reason, 0, 300),
            'stage' => $escort ? 'catch' : 'in', 'guard_ref' => $escort ? $guardRef : '']);
        // real-time term: a game day = 72 real minutes (timescale 20), capped at a year of real time
        $db->execQuery("UPDATE public.tes_crime_jail SET release_at = now() + (" . min(max(1, $days) * 72, 525600) . " * interval '1 minute') WHERE id = (SELECT max(id) FROM public.tes_crime_jail WHERE refid = '{$refId}')");
        tesCrimeNotify($escort ? "{$guard} идёт арестовывать: {$npc}" : "{$npc} в темнице на " . max(1, $days) . ' сут.');
        return [true, $escort
            ? "{$npc}: {$guard} идёт за ним и ведёт в темницу пешком; там его разденут, переоденут в тюремное и запрут на " . max(1, $days) . ' сут.'
            : "{$npc}: посажен в камеру, переодет в тюремное, выйдет через " . max(1, $days) . ' игровые сутки (раньше — {npc:Имя}.unjail)'];
    }

    /** Moves the arrests on foot along: catch -> walk -> in. Called after every request. */
    function tesCrimeEscortTick(): void
    {
        $db = $GLOBALS['db'];
        $col = $db->fetchOne("SELECT 1 AS x FROM information_schema.columns WHERE table_name = 'tes_crime_jail' AND column_name = 'stage'");
        if (empty($col)) {
            return;
        }
        $rows = $db->fetchAll("SELECT *, extract(epoch FROM now() - stage_at)::int AS age FROM public.tes_crime_jail WHERE status = 'jailed' AND stage IN ('catch', 'walk') ORDER BY id LIMIT 10");
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = intval($row['id']);
            $ref = strval($row['refid']);
            $guardName = '';
            if (preg_match('/^[0-9A-F]{8}$/', strval($row['guard_ref']))) {
                $g = $db->fetchOne("SELECT npc_name FROM public.core_npc_master WHERE upper(refid) = '" . $db->escape(strval($row['guard_ref'])) . "' LIMIT 1");
                $guardName = strval($g['npc_name'] ?? '');
            }
            if ($row['stage'] === 'catch' && intval($row['age']) >= 12) {
                // he is led away: the bridge's package (straight to the cell) and, for a bridge
                // without it, CHIM's own TravelTo to the building of the jail; the guard follows
                tesCrimeQueue(['prid ' . $ref, 'stopcombat', 'tesescort ' . hexdec(strval($row['inside_ref']))]);
                $place = tesCrimeJailPlace();
                if ($place !== '') {
                    tesCrimeNpcCommand(strval($row['npc']), 'TravelTo@' . $place);
                }
                if ($guardName !== '') {
                    tesCrimeNpcCommand($guardName, 'Follow@' . strval($row['npc']));
                }
                $db->execQuery("UPDATE public.tes_crime_jail SET stage = 'walk', stage_at = now() WHERE id = {$id}");
                tesCrimeNotify("{$row['npc']}: ведут в темницу");
            } elseif ($row['stage'] === 'walk' && intval($row['age']) >= 240) {
                // owner, 2026-10-04: "почему тп в темницу, а не сопроводят" - 75 s was shorter than the
                // walk from the street to the Dragonsreach basement; the teleport is only a fallback now
                tesCrimeQueue(tesCrimeJailCommands($ref, strval($row['inside_ref'])));
                if (preg_match('/^[0-9A-F]{8}$/', strval($row['guard_ref']))) {
                    tesCrimeQueue(['prid ' . $row['guard_ref'], 'tesfollow 0', 'tesunfollow']);
                }
                if ($guardName !== '') {
                    tesCrimeNpcCommand($guardName, 'Relax@');  // CHIM's own way to end the Follow
                }
                $db->execQuery("UPDATE public.tes_crime_jail SET stage = 'in', stage_at = now(), last_hold = now() WHERE id = {$id}");
                tesCrimeNotify("{$row['npc']} в темнице");
            }
        }
    }

    /** Let an NPC out: own clothes back (the prisoner rags are taken away), to the street or to the player. */
    function tesCrimeRelease(array $row, bool $toPlayer): void
    {
        $ref = strval($row['refid']);
        tesCrimeQueue(array_merge(['prid ' . $ref, 'teshold 0', 'setrestrained 0'], tesCrimeFreeLegs(), ['unequipitem ' . TES_CRIME_RAGS, 'removeitem ' . TES_CRIME_RAGS . ' 1',
            'unequipitem ' . TES_CRIME_WRAPS, 'removeitem ' . TES_CRIME_WRAPS . ' 1', 'tesjailbox out',
            'moveto ' . ($toPlayer ? 'player' : strval($row['outside_ref'])), 'resetai']));
        $GLOBALS['db']->execQuery("UPDATE public.tes_crime_jail SET status = 'released' WHERE id = " . intval($row['id']));
    }

    function tesCrimeUnjail(string $npc, string $refId): array
    {
        tesCrimeEnsureJailTable();
        $db = $GLOBALS['db'];
        $refId = strtoupper(trim($refId));
        $row = $db->fetchOne("SELECT * FROM public.tes_crime_jail WHERE status = 'jailed' AND refid = '" . $db->escape($refId) . "' ORDER BY id DESC LIMIT 1");
        if (empty($row['id'])) {
            // not in the registry (jailed by an older command): still free him
            $row = ['id' => 0, 'refid' => $refId, 'outside_ref' => tesCrimeJailOfHold()[1]];
        }
        tesCrimeRelease($row, true);
        return [true, "{$npc}: выпущен из темницы и возвращён к игроку"];
    }

    /**
     * Guards that are busy with something and must not be given another job (owner, 17:08: "если он делает
     * одно — то не отвлекается на другое": Джон was to take Арнбьорна to jail and the law round sent him
     * after Данника). Busy: leading a prisoner (escort stages), on a round, in a scene, holding a trial.
     */
    function tesCrimeBusyGuards(): array
    {
        $db = $GLOBALS['db'];
        $busy = [];
        $rows = $db->fetchAll("SELECT guard_ref FROM public.tes_crime_jail WHERE status = 'jailed' AND stage IN ('catch', 'walk') AND guard_ref <> '' AND stage_at > now() - interval '6 minutes'");
        foreach (is_array($rows) ? $rows : [] as $r) {
            $n = $db->fetchOne("SELECT npc_name FROM public.core_npc_master WHERE upper(refid) = '" . $db->escape(strtoupper(strval($r['guard_ref']))) . "' LIMIT 1");
            if (!empty($n['npc_name'])) {
                $busy[strval($n['npc_name'])] = true;
            }
        }
        $tbl = $db->fetchOne("SELECT 1 AS x FROM information_schema.tables WHERE table_name = 'tes_world_patrols'");
        if (!empty($tbl)) {
            foreach ($db->fetchAll("SELECT guard FROM public.tes_world_patrols WHERE created_at > now() - interval '2 minutes' AND guard <> '' AND stage IN ('walk', 'look')") ?: [] as $r) {
                $busy[strval($r['guard'])] = true;
            }
        }
        $tbl = $db->fetchOne("SELECT 1 AS x FROM information_schema.tables WHERE table_name = 'tes_agent_tasks'");
        if (!empty($tbl)) {
            foreach ($db->fetchAll("SELECT goal FROM public.tes_agent_tasks WHERE status = 'fast' AND goal LIKE 'love: %' AND created_at > now() - interval '4 minutes'") ?: [] as $r) {
                if (preg_match('/^love: (.+?) \+ (.+?) \[/u', strval($r['goal']), $m)) {
                    $busy[trim($m[1])] = true;
                    $busy[trim($m[2])] = true;
                }
            }
        }
        return array_keys($busy);
    }

    /** A guard standing near the player right now (not the accused, not a busy one), or ''. */
    function tesCrimeNearestGuard(string $except): string
    {
        $row = $GLOBALS['db']->fetchOne("SELECT data FROM eventlog WHERE type = 'infonpc_close' ORDER BY rowid DESC LIMIT 1");
        $busy = tesCrimeBusyGuards();
        foreach (explode('/', strval($row['data'] ?? '')) as $name) {
            $name = trim($name);
            if ($name === '' || $name === $except || mb_strpos($name, '(far away)') !== false) {
                continue;
            }
            $clean = trim(preg_replace('/(\s*\([a-z ]+\))+\s*$/u', '', $name) ?? $name);
            if (in_array($clean, $busy, true)) {
                continue;
            }
            if (mb_stripos($clean, 'Стражник') !== false || mb_stripos($clean, 'Guard') !== false) {
                return $clean;
            }
        }
        return '';
    }

    /** Start a fine. Returns [ok, message] for the god journal. */
    function tesCrimeFine(string $npc, string $refId, int $amount): array
    {
        $refId = strtoupper(trim($refId));
        if (!preg_match('/^[0-9A-F]{8}$/', $refId)) {
            return [false, "«{$npc}»: не знаю его RefID — штраф не выписать"];
        }
        tesCrimeEnsureTable();
        $db = $GLOBALS['db'];
        $guard = tesCrimeNearestGuard($npc);
        $db->insert('tes_crime_fines', ['npc' => $npc, 'refid' => $refId, 'amount' => $amount, 'guard' => $guard, 'jail_ref' => tesCrimeJailRef()]);
        // "getav health" first: a bridge from before 2026-10-04 reports an answer equal to the
        // previous console line as empty, and two gold counts in a row are often equal
        if (!tesCrimeQueue(['prid ' . $refId, 'getav health', 'getitemcount 0000000F'])) {
            return [false, "«{$npc}»: канал игры недоступен"];
        }
        $player = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
        if ($guard !== '') {
            tesCrimeTell($guard, "(Именем ярла объяви {$npc} штраф {$amount} септимов за недостойное поведение с {$player}: пусть платит на месте или идёт в темницу. Одна-две короткие строгие фразы, обращайся к {$npc}.)");
        }
        return [true, "{$npc}: объявлен штраф {$amount} септимов" . ($guard !== '' ? " (требует {$guard})" : '') . '; не хватит золота — отправится в темницу'];
    }
}
