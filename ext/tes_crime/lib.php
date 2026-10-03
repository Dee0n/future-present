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

    /** Jail of the hold the player is in: the crime faction's prisoner chest (FACT PLCN). */
    function tesCrimeJailRef(): string
    {
        $jails = ['вайтран' => '000267E8', 'истмарк' => '0003EF10', 'фолкрит' => '000EF437', 'хаафингар' => '0003EEFF',
            'хьялмарк' => '0003EF09', 'белый берег' => '0003EF12', 'предел' => '0003EF03', 'рифт' => '000A8F33'];
        $row = $GLOBALS['db']->fetchOne("SELECT data FROM eventlog WHERE type IN ('infoloc', 'request') AND data LIKE '%Hold:%' ORDER BY rowid DESC LIMIT 1");
        if (preg_match('/Hold:\s*([^,)]+)/u', strval($row['data'] ?? ''), $m)) {
            return $jails[mb_strtolower(trim($m[1]))] ?? $jails['вайтран'];
        }
        return $jails['вайтран'];
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
        if (!tesCrimeQueue(['prid ' . $refId, 'getitemcount 0000000F'])) {
            return [false, "«{$npc}»: канал игры недоступен"];
        }
        $player = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
        if ($guard !== '') {
            tesCrimeTell($guard, "(Именем ярла объяви {$npc} штраф {$amount} септимов за недостойное поведение с {$player}: пусть платит на месте или идёт в темницу. Одна-две короткие строгие фразы, обращайся к {$npc}.)");
        }
        return [true, "{$npc}: объявлен штраф {$amount} септимов" . ($guard !== '' ? " (требует {$guard})" : '') . '; не хватит золота — отправится в темницу'];
    }
}
