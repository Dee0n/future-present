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

    /** Console sequence that puts the actor into the cell: prisoner clothes, cannot leave. */
    function tesCrimeJailCommands(string $ref, string $insideRef): array
    {
        // teshold (bridge, 2026-10-04): the cell becomes his schedule - without it the game walked
        // Хеймскр back to his statue every few minutes. A bridge without the command prints an
        // "unknown command" line and goes on.
        return ['prid ' . $ref, 'stopcombat', 'moveto ' . $insideRef, 'unequipall',
            'additem ' . TES_CRIME_RAGS . ' 1', 'equipitem ' . TES_CRIME_RAGS . ' 1',
            'additem ' . TES_CRIME_WRAPS . ' 1', 'equipitem ' . TES_CRIME_WRAPS . ' 1',
            'setrestrained 1', 'teshold ' . hexdec($insideRef)];
    }

    /** Put an escaped prisoner back: he already wears the rags, so only move and hold. */
    function tesCrimeHoldCommands(string $ref, string $insideRef): array
    {
        return ['prid ' . $ref, 'stopcombat', 'moveto ' . $insideRef, 'equipitem ' . TES_CRIME_RAGS . ' 1',
            'setrestrained 1', 'teshold ' . hexdec($insideRef)];
    }

    /** Jail an NPC for $days game days. Returns [ok, message]. */
    function tesCrimeJail(string $npc, string $refId, string $reason = '', int $days = 1): array
    {
        $refId = strtoupper(trim($refId));
        if (!preg_match('/^[0-9A-F]{8}$/', $refId)) {
            return [false, "«{$npc}»: не знаю его RefID — посадить не могу"];
        }
        tesCrimeEnsureJailTable();
        $db = $GLOBALS['db'];
        [$inside, $outside] = tesCrimeJailOfHold();
        if (!tesCrimeQueue(tesCrimeJailCommands($refId, $inside))) {
            return [false, "«{$npc}»: канал игры недоступен"];
        }
        $now = tesCrimeGamets();
        $db->execQuery("UPDATE public.tes_crime_jail SET status = 'released' WHERE status = 'jailed' AND refid = '{$refId}'");
        $db->insert('tes_crime_jail', ['npc' => $npc, 'refid' => $refId, 'inside_ref' => $inside, 'outside_ref' => $outside,
            'jailed_gamets' => $now, 'release_gamets' => $now + TES_CRIME_DAY * max(1, $days), 'reason' => mb_substr($reason, 0, 300)]);
        tesCrimeNotify("{$npc} в темнице на " . max(1, $days) . ' сут.');
        return [true, "{$npc}: посажен в камеру, переодет в тюремное, выйдет через " . max(1, $days) . ' игровые сутки (раньше — {npc:Имя}.unjail)'];
    }

    /** Let an NPC out: own clothes back (the prisoner rags are taken away), to the street or to the player. */
    function tesCrimeRelease(array $row, bool $toPlayer): void
    {
        $ref = strval($row['refid']);
        tesCrimeQueue(['prid ' . $ref, 'teshold 0', 'setrestrained 0', 'unequipitem ' . TES_CRIME_RAGS, 'removeitem ' . TES_CRIME_RAGS . ' 1',
            'unequipitem ' . TES_CRIME_WRAPS, 'removeitem ' . TES_CRIME_WRAPS . ' 1',
            'moveto ' . ($toPlayer ? 'player' : strval($row['outside_ref'])), 'resetai']);
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

    /** A guard standing near the player right now (not the accused), or ''. */
    function tesCrimeNearestGuard(string $except): string
    {
        $row = $GLOBALS['db']->fetchOne("SELECT data FROM eventlog WHERE type = 'infonpc_close' ORDER BY rowid DESC LIMIT 1");
        foreach (explode('/', strval($row['data'] ?? '')) as $name) {
            $name = trim($name);
            if ($name === '' || $name === $except || mb_strpos($name, '(far away)') !== false) {
                continue;
            }
            $clean = trim(preg_replace('/\s*\((?:busy|far away)\)\s*$/u', '', $name) ?? $name);
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
