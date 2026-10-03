<?php
/*
 * tes_agent shared helpers: the task table and the hand-off (spawn the worker).
 * Used by functions.php (inside CHIM requests) and worker.php (CLI).
 */

if (!function_exists('tesAgentEnsureTable')) {
    function tesAgentEnsureTable(): void
    {
        $GLOBALS['db']->execQuery("
            CREATE TABLE IF NOT EXISTS public.tes_agent_tasks (
                id bigserial PRIMARY KEY,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                goal text NOT NULL,
                status text NOT NULL DEFAULT 'queued',  -- queued, running, done, failed, gave_up
                steps int NOT NULL DEFAULT 0,
                cost numeric NOT NULL DEFAULT 0,
                pid int,
                result text NOT NULL DEFAULT '',
                transcript jsonb NOT NULL DEFAULT '[]'
            )
        ");
    }

    /** The running task, if any (a dead worker's row older than 15 min does not count). */
    function tesAgentRunningTask(): ?array
    {
        tesAgentEnsureTable();
        $row = $GLOBALS['db']->fetchOne("
            SELECT id, goal, steps, status FROM public.tes_agent_tasks
            WHERE status IN ('queued', 'running') AND updated_at > now() - interval '15 minutes'
            ORDER BY id DESC LIMIT 1
        ");
        return is_array($row) && !empty($row['id']) ? $row : null;
    }

    /** Create a task row and start the detached worker. Returns [ok, message]. */
    function tesAgentStart(string $goal, bool $dry = false, bool $readonly = false, bool $quick = false): array
    {
        $goal = trim(preg_replace('/\s+/u', ' ', $goal) ?? $goal);
        if (mb_strlen($goal) < 3) {
            return [false, 'пустая цель'];
        }
        $db = $GLOBALS['db'];
        $running = tesAgentRunningTask();
        if ($running) {
            // One worker at a time (they read the same console log), but an order given meanwhile
            // must not be lost. Live 2026-10-04 02:35-02:45: six orders in ten minutes got
            // "уже идёт задача" and nothing happened. They wait in line; the worker starts the
            // next one when it ends (tesAgentStartNext).
            $db->execQuery("ALTER TABLE public.tes_agent_tasks ADD COLUMN IF NOT EXISTS opts text NOT NULL DEFAULT ''");
            $waiting = $db->fetchOne("SELECT count(*) AS n FROM public.tes_agent_tasks WHERE status = 'waiting' AND created_at > now() - interval '15 minutes'");
            if (intval($waiting['n'] ?? 0) >= 6) {
                return [false, "очередь полна: идёт задача #{$running['id']} и ещё 6 ждут"];
            }
            $row = $db->fetchOne("INSERT INTO public.tes_agent_tasks (goal, status, opts) VALUES ('" . $db->escape(mb_substr($goal, 0, 1000))
                . "', 'waiting', '" . ($dry ? 'dry ' : '') . ($readonly ? 'readonly ' : '') . ($quick ? 'quick' : '') . "') RETURNING id");
            return [true, 'задача #' . intval($row['id'] ?? 0) . " в очереди за #{$running['id']}"];
        }
        $row = $db->fetchOne("INSERT INTO public.tes_agent_tasks (goal) VALUES ('" . $db->escape(mb_substr($goal, 0, 1000)) . "') RETURNING id");
        $id = intval($row['id'] ?? 0);
        if ($id <= 0) {
            return [false, 'не удалось создать задачу'];
        }
        tesAgentSpawn($id, $dry, $readonly, $quick);
        return [true, "задача #{$id} запущена"];
    }

    /** Start the oldest waiting task, if nothing runs. Called by the worker when it ends. */
    function tesAgentStartNext(): void
    {
        $db = $GLOBALS['db'];
        $has = $db->fetchOne("SELECT 1 AS x FROM information_schema.columns WHERE table_name = 'tes_agent_tasks' AND column_name = 'opts'");
        if (empty($has) || tesAgentRunningTask()) {
            return;
        }
        $next = $db->fetchOne("UPDATE public.tes_agent_tasks SET status = 'queued', updated_at = now() WHERE id = (
            SELECT id FROM public.tes_agent_tasks WHERE status = 'waiting' AND created_at > now() - interval '15 minutes' ORDER BY id LIMIT 1
        ) RETURNING id, opts");
        if (!empty($next['id'])) {
            tesAgentSpawn(intval($next['id']), strpos(strval($next['opts']), 'dry') !== false, strpos(strval($next['opts']), 'readonly') !== false, strpos(strval($next['opts']), 'quick') !== false);
        }
    }

    function tesAgentSpawn(int $id, bool $dry, bool $readonly, bool $quick = false): void
    {
        $worker = __DIR__ . '/worker.php';
        $log = '/var/www/html/HerikaServer/log/tes_agent_' . $id . '.log';
        // setsid + nohup: the worker must outlive this HTTP request (SNQE pattern).
        // Absolute php: under Apache, PATH is minimal and PHP_BINARY is not the CLI.
        exec('setsid nohup /usr/bin/php ' . escapeshellarg($worker) . ' --task ' . $id . ($dry ? ' --dry' : '') . ($readonly ? ' --readonly' : '') . ($quick ? ' --quick' : '')
            . ' > ' . escapeshellarg($log) . ' 2>&1 &');
    }

    /** Short DebugNotification in the top-left corner of the game. */
    function tesAgentNotify(string $text): void
    {
        $text = trim(str_replace(['@', '|', "\n", "\r"], [' at ', '/', ' ', ' '], $text));
        $GLOBALS['db']->insert('responselog', [
            'localts' => time(), 'sent' => 0, 'actor' => 'rolemaster', 'text' => '',
            'action' => 'rolecommand|DebugNotification@' . mb_substr($text, 0, 200), 'tag' => '',
        ]);
    }

    /** Make the Narrator speak (the game sends an "instruction" request back, normal pipeline). */
    function tesAgentNarratorSay(string $instruction, int $taskId): void
    {
        $instruction = trim(str_replace(['@', '|', "\n", "\r"], [' at ', '/', ' ', ' '], $instruction));
        $GLOBALS['db']->insert('responselog', [
            'localts' => time(), 'sent' => 0, 'actor' => 'rolemaster', 'text' => '',
            'action' => 'rolecommand|Instruction@The Narrator@' . mb_substr($instruction, 0, 900) . '@0',  // task id 0, as processor/comm.php does
            'tag' => '',
        ]);
    }
}
