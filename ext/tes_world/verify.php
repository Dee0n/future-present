<?php
/*
 * tes_world: closed loop for plain orders. Owner, 2026-10-04: an order was sent "blind" - the NPC
 * said "Как прикажете" and nothing happened. Every undress / kill / bring that tesWorldRunFast
 * has sent is checked in the game 25 s later and, if it did not take, sent once more (twice at
 * most). Stages: wait (until due_at) -> ask (probe sent, waiting for the console's answer) -> done.
 */

if (!function_exists('tesWorldVerifyAdd')) {
    function tesWorldVerifyEnsure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $GLOBALS['db']->execQuery("CREATE TABLE IF NOT EXISTS public.tes_order_checks (
            id serial PRIMARY KEY, kind text NOT NULL, who text NOT NULL, ref text NOT NULL,
            stage text NOT NULL DEFAULT 'wait', tries int NOT NULL DEFAULT 0,
            due_at timestamptz NOT NULL DEFAULT now() + interval '25 seconds',
            asked_at timestamptz, last_id bigint NOT NULL DEFAULT 0, created_at timestamptz NOT NULL DEFAULT now())");
    }

    /** Remember an order to check later. kind: strip | kill | bring */
    function tesWorldVerifyAdd(string $kind, string $who, string $ref): void
    {
        if (!in_array($kind, ['strip', 'kill', 'bring'], true) || $ref === '') {
            return;
        }
        tesWorldVerifyEnsure();
        $db = $GLOBALS['db'];
        $same = $db->fetchOne("SELECT 1 AS x FROM public.tes_order_checks WHERE kind = '" . $db->escape($kind) . "' AND ref = '" . $db->escape($ref) . "' AND stage <> 'done' LIMIT 1");
        if (empty($same)) {
            $db->execQuery("INSERT INTO public.tes_order_checks (kind, who, ref) VALUES ('" . $db->escape($kind) . "', '" . $db->escape($who) . "', '" . $db->escape($ref) . "')");
        }
    }

    function tesWorldVerifyCommands(string $kind, string $ref): array
    {
        return match ($kind) {
            'strip' => ['prid ' . $ref, 'unequipall'],
            'kill' => ['prid ' . $ref, 'teskill', 'kill'],
            default => ['prid ' . $ref, 'moveto player'],
        };
    }

    function tesWorldVerifyTick(): void
    {
        tesWorldVerifyEnsure();
        $db = $GLOBALS['db'];
        $rows = $db->fetchAll("SELECT *, extract(epoch FROM now() - asked_at)::int AS asked_age FROM public.tes_order_checks WHERE stage <> 'done' AND created_at > now() - interval '30 minutes' ORDER BY id LIMIT 6");
        foreach (is_array($rows) ? $rows : [] as $r) {
            $id = intval($r['id']);
            $ref = strval($r['ref']);
            $kind = strval($r['kind']);
            if ($r['stage'] === 'wait') {
                if (strtotime(strval($r['due_at'])) > time()) {
                    continue;
                }
                $probe = $kind === 'strip' ? 'tesstate' : ($kind === 'kill' ? 'getdead' : 'getdistance 20');
                $max = $db->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_god_console_log");
                tesWorldQueue(['prid ' . $ref, $probe]);
                $db->execQuery("UPDATE public.tes_order_checks SET stage = 'ask', asked_at = now(), last_id = " . intval($max['m'] ?? 0) . " WHERE id = {$id}");
                continue;
            }
            // ask: the probe's answer, the first one for this reference after the question
            $out = null;
            $log = $db->fetchAll("SELECT command, output FROM public.tes_god_console_log WHERE id > " . intval($r['last_id']) . " ORDER BY id LIMIT 60");
            $seen = false;
            foreach (is_array($log) ? $log : [] as $l) {
                $cmd = strtolower(trim(strval($l['command'])));
                if ($cmd === 'prid ' . strtolower($ref)) {
                    $seen = true;
                    continue;
                }
                if ($seen && preg_match('/^(tesstate|getdead|getdistance)/', $cmd)) {
                    $out = strval($l['output']);
                    break;
                }
            }
            if ($out === null) {
                if (intval($r['asked_age']) > 90) {
                    $db->execQuery("UPDATE public.tes_order_checks SET stage = 'done' WHERE id = {$id}");  // no answer: leave it
                }
                continue;
            }
            $bad = false;
            if ($kind === 'strip') {
                $bad = (bool)preg_match('/; worn.*\bBODY=/u', $out);
                if ($bad && function_exists('tesCrimeIsJailed') && tesCrimeIsJailed(strval($r['who']))) {
                    $bad = false;  // a prisoner wears the prison rags
                }
            } elseif ($kind === 'kill') {
                $bad = !preg_match('/GetDead >> 1/', $out);
            } else {
                $bad = !preg_match('/GetDistance >> ([0-9.]+)/', $out, $dm) || floatval($dm[1]) > 1500.0;
            }
            if ($bad && intval($r['tries']) < 2) {
                tesWorldQueue(tesWorldVerifyCommands($kind, $ref));
                $db->execQuery("UPDATE public.tes_order_checks SET stage = 'wait', tries = tries + 1, due_at = now() + interval '25 seconds' WHERE id = {$id}");
                error_log("[tes_world] order check: {$kind} {$r['who']} did not take - sent again (" . (intval($r['tries']) + 1) . ")");
            } else {
                $db->execQuery("UPDATE public.tes_order_checks SET stage = 'done' WHERE id = {$id}");
                if ($bad) {
                    error_log("[tes_world] order check: {$kind} {$r['who']} still not done after retries");
                }
            }
        }
    }
}
