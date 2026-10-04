<?php
/*
 * tes_world: the one the player talks to stands still (owner, 2026-10-04: "когда с ними говоришь пусть
 * стоят а не уходят"). CHIM puts its soft wait on a listener only while the player SITS
 * (AIAgentAIMind.GetIntoConversation: GetSitState()==0 -> return), so with the player standing people
 * kept walking their schedule mid-sentence. Bridge v9 "testalk on|off" does the same soft wait.
 *
 *  - every line the player says to an NPC: "testalk on" for him (at most once in 40 s per person);
 *  - 60 s without a word to him: "testalk off" - back to his own business.
 * The bridge itself leaves followers, fighters, people in scenes, sitting, held or given a routine.
 */

if (!function_exists('tesTalkHold')) {
    function tesTalkEnsure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $GLOBALS['db']->execQuery("CREATE TABLE IF NOT EXISTS public.tes_talk_hold (npc text PRIMARY KEY, ref text NOT NULL, sent_at timestamptz NOT NULL DEFAULT now(), last_at timestamptz NOT NULL DEFAULT now())");
    }

    /** The player has just said something to $npc. */
    function tesTalkHold(string $npc): void
    {
        $npc = trim($npc);
        if ($npc === '' || stripos($npc, 'Narrator') !== false || $npc === trim(strval($GLOBALS['PLAYER_NAME'] ?? ''))) {
            return;
        }
        if (function_exists('tesCrimeIsJailed') && tesCrimeIsJailed($npc)) {
            return;
        }
        $ref = tesWorldRefOf($npc);
        // the remembered bridge version only: tesBridgeVersion() would wait up to 5 s for the game
        // on a stale cache, on the player's line; others refresh it
        if ($ref === '' || !function_exists('tesWatchGet') || intval(tesWatchGet('bridge_ver')['value']) < 9) {
            return;
        }
        tesTalkEnsure();
        $db = $GLOBALS['db'];
        $e = $db->escape($npc);
        $row = $db->fetchOne("SELECT extract(epoch FROM now() - sent_at)::int AS age FROM public.tes_talk_hold WHERE npc = '{$e}'");
        if (!empty($row) && intval($row['age']) < 40) {
            $db->execQuery("UPDATE public.tes_talk_hold SET last_at = now() WHERE npc = '{$e}'");
            return;
        }
        tesWorldQueue(['prid ' . $ref, 'testalk on']);
        $db->execQuery("INSERT INTO public.tes_talk_hold (npc, ref) VALUES ('{$e}', '" . $db->escape($ref) . "')
            ON CONFLICT (npc) DO UPDATE SET ref = EXCLUDED.ref, sent_at = now(), last_at = now()");
    }

    /** Called on every request: let go whoever has not been spoken to for a minute. */
    function tesTalkTick(): void
    {
        tesTalkEnsure();
        $db = $GLOBALS['db'];
        $rows = $db->fetchAll("SELECT npc, ref FROM public.tes_talk_hold WHERE last_at < now() - interval '60 seconds' LIMIT 6");
        foreach (is_array($rows) ? $rows : [] as $r) {
            tesWorldQueue(['prid ' . $r['ref'], 'testalk off']);
            $db->execQuery("DELETE FROM public.tes_talk_hold WHERE npc = '" . $db->escape(strval($r['npc'])) . "'");
        }
    }
}
