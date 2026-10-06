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
            WHERE status IN ('queued', 'running') AND updated_at > now() - interval '5 minutes'
            ORDER BY id DESC LIMIT 1
        ");
        return is_array($row) && !empty($row['id']) ? $row : null;
    }

    /** Create a task row and start the detached worker. Returns [ok, message]. */
    function tesAgentStart(string $goal, bool $dry = false, bool $readonly = false, bool $quick = false, bool $silent = false): array
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
                . "', 'waiting', '" . ($dry ? 'dry ' : '') . ($readonly ? 'readonly ' : '') . ($quick ? 'quick ' : '') . ($silent ? 'silent' : '') . "') RETURNING id");
            return [true, 'задача #' . intval($row['id'] ?? 0) . " в очереди за #{$running['id']}"];
        }
        $row = $db->fetchOne("INSERT INTO public.tes_agent_tasks (goal) VALUES ('" . $db->escape(mb_substr($goal, 0, 1000)) . "') RETURNING id");
        $id = intval($row['id'] ?? 0);
        if ($id <= 0) {
            return [false, 'не удалось создать задачу'];
        }
        tesAgentSpawn($id, $dry, $readonly, $quick, $silent);
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
            tesAgentSpawn(intval($next['id']), strpos(strval($next['opts']), 'dry') !== false, strpos(strval($next['opts']), 'readonly') !== false, strpos(strval($next['opts']), 'quick') !== false, strpos(strval($next['opts']), 'silent') !== false);
        }
    }

    function tesAgentSpawn(int $id, bool $dry, bool $readonly, bool $quick = false, bool $silent = false): void
    {
        $worker = __DIR__ . '/worker.php';
        $log = '/var/www/html/HerikaServer/log/tes_agent_' . $id . '.log';
        // setsid + nohup: the worker must outlive this HTTP request (SNQE pattern).
        // Absolute php: under Apache, PATH is minimal and PHP_BINARY is not the CLI.
        exec('setsid nohup /usr/bin/php ' . escapeshellarg($worker) . ' --task ' . $id . ($dry ? ' --dry' : '') . ($readonly ? ' --readonly' : '') . ($quick ? ' --quick' : '') . ($silent ? ' --silent' : '')
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

if (!function_exists('tesAgentInverse')) {
    /**
     * The command that undoes one god command ("{npc:X}.additem F 5" -> "{npc:X}.removeitem F 5"), or null when it
     * can not be undone (moveto, placeatme, setav without the old value...). Roadmap: "откат задач агента".
     */
    function tesAgentInverse(string $one): ?string
    {
        $one = trim($one);
        if (!preg_match('/^(\{npc:[^}]+\}|player|[0-9A-Fa-f]{8})\.(\w+)\s*(.*)$/u', $one, $m)) {
            return null;
        }
        [$who, $cmd, $args] = [$m[1], strtolower($m[2]), trim($m[3])];
        $a = preg_split('/\s+/u', $args, -1, PREG_SPLIT_NO_EMPTY);
        $pairs = ['additem' => 'removeitem', 'removeitem' => 'additem', 'addspell' => 'removespell', 'removespell' => 'addspell',
            'addperk' => 'removeperk', 'removeperk' => 'addperk', 'addshout' => 'removeshout'];
        if (isset($pairs[$cmd]) && $a) {
            return "{$who}.{$pairs[$cmd]} {$args}";
        }
        if ($cmd === 'addfac' && $a) {
            return "{$who}.removefac {$a[0]}";
        }
        if ($cmd === 'setscale') {
            return "{$who}.setscale 1";
        }
        if (in_array($cmd, ['kill', 'teskill'], true)) {
            return "{$who}.resurrect";
        }
        if ($cmd === 'setessential' && $a) {
            return "{$who}.setessential " . ($a[0] === '0' ? '1' : '0');
        }
        if ($cmd === 'unequipall') {
            return "{$who}.tesredress";
        }
        if ($cmd === 'tesgive') {
            return "{$who}.tesungive";
        }
        return null;
    }

    /** After a task step was sent: what undoes it, in order (table tes_agent_undo). */
    function tesAgentJournal(int $taskId, string $kept): void
    {
        if ($taskId <= 0 || trim($kept) === '') {
            return;
        }
        $db = $GLOBALS['db'];
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_agent_undo (id bigserial PRIMARY KEY, task_id bigint NOT NULL, command text NOT NULL, inverse text, undone boolean NOT NULL DEFAULT false, created_at timestamptz NOT NULL DEFAULT now())");
        foreach (preg_split('/\s*;\s*/u', $kept, -1, PREG_SPLIT_NO_EMPTY) as $one) {
            $inv = tesAgentInverse($one);
            $db->execQuery("INSERT INTO public.tes_agent_undo (task_id, command, inverse) VALUES ({$taskId}, '" . $db->escape(mb_substr($one, 0, 400)) . "', "
                . ($inv === null ? 'NULL' : "'" . $db->escape(mb_substr($inv, 0, 400)) . "'") . ")");
        }
    }

    /**
     * Undo the last task of the agent (30 minutes): the inverse commands in reverse order, as console sequences.
     * Returns [what was undone, what could not be] or null when there is no such task.
     */
    function tesAgentUndoLast(): ?array
    {
        $db = $GLOBALS['db'];
        $has = $db->fetchOne("SELECT to_regclass('public.tes_agent_undo') AS t");
        if (empty($has['t'])) {
            return null;
        }
        $t = $db->fetchOne("SELECT task_id, max(created_at) AS at FROM public.tes_agent_undo WHERE NOT undone AND created_at > now() - interval '30 minutes' GROUP BY task_id ORDER BY max(created_at) DESC LIMIT 1");
        if (empty($t['task_id'])) {
            return null;
        }
        $tid = intval($t['task_id']);
        $rows = $db->fetchAll("SELECT id, command, inverse FROM public.tes_agent_undo WHERE task_id = {$tid} AND NOT undone ORDER BY id DESC");
        $db->execQuery("UPDATE public.tes_agent_undo SET undone = true WHERE task_id = {$tid}");
        $cmds = [];
        $done = [];
        $not = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $inv = strval($r['inverse'] ?? '');
            if ($inv === '' || !preg_match('/^(\{npc:([^}]+)\}|player|([0-9A-Fa-f]{8}))\.(.+)$/u', $inv, $m)) {
                $not[] = strval($r['command']);
                continue;
            }
            if ($m[1] === 'player') {
                $cmds[] = ['player.' . $m[4]];
            } else {
                $ref = $m[2] !== '' && function_exists('tesWorldRefOf') ? tesWorldRefOf($m[2]) : strtoupper(strval($m[3] ?? ''));
                if ($ref === '') {
                    $not[] = strval($r['command']);
                    continue;
                }
                $cmds[] = ['prid ' . $ref, $m[4]];
            }
            $done[] = strval($r['command']);
        }
        // sequences of at most ~10 commands (bridge-console-races), a "prid X; cmd" pair never split
        $chunk = [];
        foreach ($cmds as $g) {
            if (count($chunk) + count($g) > 10 && function_exists('tesWorldQueue')) {
                tesWorldQueue($chunk);
                $chunk = [];
            }
            $chunk = array_merge($chunk, $g);
        }
        if ($chunk && function_exists('tesWorldQueue')) {
            tesWorldQueue($chunk);
        }
        $goal = $db->fetchOne("SELECT goal FROM public.tes_agent_tasks WHERE id = {$tid}");
        return ['task' => strval($goal['goal'] ?? "#{$tid}"), 'done' => $done, 'not' => $not];
    }
}
