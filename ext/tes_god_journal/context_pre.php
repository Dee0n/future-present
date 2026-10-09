<?php
/*
 * tes_god_journal: tells the Narrator what really happened to its recent
 * God_Command console commands, so it stops claiming results it cannot know.
 *
 * Read-only. Source of truth:
 *  - skyrim_quest_action_outbox rows with beat_id 'chim_god_command'
 *    (status 'applied' only means the game DISPATCHED the command);
 *  - core_npc_master.metadata.activity_status (is_dead + timestamp) for
 *    resurrect / kill, accepted only when the status is newer than the command.
 *
 * Loaded by main.php via requireFilesRecursively(..., "context_pre.php"),
 * i.e. inside a function: use $GLOBALS only. Any error here must not break
 * dialogue, hence the Throwable guard.
 */

if (!function_exists('tesGodJournalIsNarratorTurn')) {
    function tesGodJournalIsNarratorTurn(): bool
    {
        $type = strval($GLOBALS["gameRequest"][0] ?? '');
        if (in_array($type, ["narrator_inputtext", "narration", "narrator_welcome", "narrator_quest_comment"], true)) {
            return true;
        }
        if (strval($_GET["profile"] ?? '') === md5('The Narrator')) {
            return true;
        }
        return strval($GLOBALS["HERIKA_NAME"] ?? '') === 'The Narrator';
    }

    // herikaQueueGodCommands() (core patch) attaches outbox rows to the first
    // skyrim_quest_instances row. A quest-engine reset empties that table, and then
    // every God_Command is silently dropped. Keep an inert service quest ('000_' sorts
    // first) so the channel always exists: active=false and run_state 'inactive' keep
    // it out of the quest engine; console actions never change quest state on ack.
    function tesGodJournalEnsureChannel(): void
    {
        $db = $GLOBALS["db"];
        $db->execQuery("
            INSERT INTO public.skyrim_quest_definitions (quest_key, quest_editor_id, title, source_plugin, active)
            VALUES ('000_tes_god_channel', 'TESGodChannel', 'TES god console channel (service row)', 'tes_god_journal', false)
            ON CONFLICT (quest_key) DO NOTHING
        ");
        $db->execQuery("
            INSERT INTO public.skyrim_quest_instances (quest_key, quest_editor_id, run_state)
            VALUES ('000_tes_god_channel', 'TESGodChannel', 'inactive')
            ON CONFLICT (quest_key) DO NOTHING
        ");
    }

    function tesGodJournalNpc(string $refId): array
    {
        $db = $GLOBALS["db"];
        $ref = $db->escape(strtoupper($refId));
        $row = $db->fetchOne("SELECT npc_name, metadata::text AS meta FROM public.core_npc_master WHERE upper(refid) = '{$ref}' LIMIT 1");
        if (!is_array($row)) {
            return ['name' => $refId, 'status' => null];
        }
        $meta = json_decode(strval($row['meta'] ?? ''), true);
        $status = is_array($meta) ? ($meta['activity_status'] ?? null) : null;
        if (is_string($status)) {
            $status = json_decode($status, true);
        }
        return ['name' => strval($row['npc_name'] ?? $refId), 'status' => is_array($status) ? $status : null];
    }

    // Console lines logged by ext/tes_god_console for these commands within 3 minutes
    // after queueing. null = no report (override bridge not installed, or not run yet).
    function tesGodJournalConsole(array $commands, float $createdEpoch): ?array
    {
        $db = $GLOBALS["db"];
        $table = $db->fetchOne("SELECT to_regclass('public.tes_god_console_log') IS NOT NULL AS ok");
        if (!is_array($table) || !in_array($table['ok'] ?? '', [true, 't', 'true', 1, '1'], true) || $createdEpoch <= 0) {
            return null;
        }
        $found = false;
        $outputs = [];
        foreach ($commands as $command) {
            $c = $db->escape(trim(strval($command)));
            if ($c === '') {
                continue;
            }
            $row = $db->fetchOne("
                SELECT output FROM public.tes_god_console_log
                WHERE command = '{$c}'
                  AND created_at BETWEEN to_timestamp({$createdEpoch}) AND to_timestamp({$createdEpoch}) + interval '3 minutes'
                ORDER BY id ASC LIMIT 1
            ");
            if (!is_array($row) || !array_key_exists('output', $row)) {
                continue;
            }
            $found = true;
            $out = trim(strval($row['output']));
            if ($out !== '') {
                $outputs[] = $out;
            }
        }
        if (!$found) {
            return null;
        }
        $error = '';
        foreach ($outputs as $out) {
            if (preg_match('/not found|missing|invalid|error|unknown|could not|failed|no reference|not saved/i', $out)) {
                $error = mb_substr($out, 0, 120);
                break;
            }
        }
        return ['error' => $error, 'output' => mb_substr(implode(' / ', $outputs), 0, 160)];
    }

    function tesGodJournalLine(array $row): string
    {
        $payload = json_decode(strval($row['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $commands = isset($payload['commands']) && is_array($payload['commands'])
            ? $payload['commands']
            : [strval($payload['command'] ?? '')];

        $allCommands = $commands;
        $refId = '';
        $nearName = '';
        if (preg_match('/^prid\s+([0-9A-Fa-f]{8})$/', trim(strval($commands[0] ?? '')), $m)) {
            $refId = strtoupper($m[1]);
            array_shift($commands);
        } elseif (preg_match('/^tesnear\s+(.+)$/u', trim(strval($commands[0] ?? '')), $m)) {
            $nearName = trim($m[1]);  // actor found by name in game (ext/tes_god_guard)
            array_shift($commands);
        }
        $commandText = trim(implode('; ', array_map('strval', $commands)));
        $npc = $refId !== '' ? tesGodJournalNpc($refId) : ['name' => '', 'status' => null];
        $who = $npc['name'] !== '' ? $npc['name'] : $nearName;
        $label = mb_substr(($who !== '' ? $who . ': ' : '') . $commandText, 0, 220);

        $status = strtolower(strval($row['status'] ?? ''));
        $ageSec = intval($row['age_sec'] ?? 0);
        if ($status === 'pending') {
            return $ageSec > 20
                ? "{$label} - NOT executed yet (game paused or the world is not taking commands)."
                : "{$label} - queued, no result yet.";
        }
        if ($status !== 'applied') {
            $reason = trim(strval($row['result_text'] ?? ''));
            return "{$label} - FAILED" . ($reason !== '' ? " ({$reason})" : '') . '.';
        }

        // Real console output, when the TESGodConsoleReport bridge override is installed
        // (ext/tes_god_console stores it). An error line beats every other signal.
        $console = tesGodJournalConsole($allCommands, floatval($row['created_epoch'] ?? 0));
        if ($console !== null && $console['error'] !== '') {
            return "{$label} - FAILED, console said: «{$console['error']}».";
        }
        $consoleNote = ($console !== null && $console['output'] !== '') ? " Console: «{$console['output']}»." : '';

        // Dispatched. Only life/death can be checked from the server side.
        $expectDead = null;
        if (preg_match('/^resurrect\b/i', $commandText)) {
            $expectDead = false;
        } elseif (preg_match('/^kill\b/i', $commandText)) {
            $expectDead = true;
        }
        if ($expectDead === null || $refId === '') {
            return $console !== null
                ? "{$label} - executed by the game, no console errors.{$consoleNote}"
                : "{$label} - sent to the world, result cannot be checked.";
        }
        // activity_status.timestamp is not epoch time (seen: 3.6e13), so freshness is
        // judged in game time: the status must be newer than the game time at which
        // the command was queued (last eventlog gamets before created_at).
        $activity = $npc['status'];
        $seenGamets = is_array($activity) ? intval($activity['gamets'] ?? 0) : 0;
        $sentGamets = intval($row['sent_gamets'] ?? 0);
        if ($seenGamets <= 0 || $sentGamets <= 0 || $seenGamets <= $sentGamets) {
            return $console !== null
                ? "{$label} - executed by the game with no console errors, but no fresh data on {$npc['name']}.{$consoleNote}"
                : "{$label} - sent, no fresh data on {$npc['name']}, not verified.";
        }
        $isDead = !empty($activity['is_dead']);
        if ($isDead === $expectDead) {
            return "{$label} - done, verified: " . ($isDead ? 'dead.' : 'alive.');
        }
        return "{$label} - FAILED: " . ($isDead ? 'still dead.' : 'still alive.');
    }

    function tesGodJournalBuild(): string
    {
        // 10 minutes, not 30 (cost, 2026-10-04): the journal went to ~3.5K tokens in every Narrator request
        $minutes = max(1, intval($GLOBALS["TES_GOD_JOURNAL_MINUTES"] ?? 10));
        $rows = $GLOBALS["db"]->fetchAll("
            SELECT o.payload_json::text AS payload_json, o.status,
                   COALESCE(o.result_json::text, '') AS result_json,
                   (SELECT e.gamets FROM public.eventlog e
                     WHERE e.localts <= extract(epoch FROM o.created_at)
                     ORDER BY e.localts DESC LIMIT 1) AS sent_gamets,
                   extract(epoch FROM (now() - o.created_at))::int AS age_sec,
                   extract(epoch FROM o.created_at) AS created_epoch
            FROM public.skyrim_quest_action_outbox o
            WHERE o.beat_id = 'chim_god_command'
              AND o.created_at > now() - interval '{$minutes} minutes'
            ORDER BY o.id DESC
            LIMIT 6
        ");
        $rows = is_array($rows) ? $rows : [];
        $lines = [];
        // Refusals by ext/tes_god_guard (table exists once the guard has seen a command).
        $guardTable = $GLOBALS["db"]->fetchOne("SELECT to_regclass('public.tes_god_guard_log') IS NOT NULL AS ok");
        if (is_array($guardTable) && in_array($guardTable['ok'] ?? '', [true, 't', 'true', 1, '1'], true)) {
            $refusals = $GLOBALS["db"]->fetchAll("
                SELECT verdict, reasons, kept_text FROM public.tes_god_guard_log
                WHERE verdict IN ('blocked', 'partial', 'repeat', 'server')
                  AND created_at > now() - interval '{$minutes} minutes'
                ORDER BY id DESC LIMIT 4
            ");
            foreach (array_reverse(is_array($refusals) ? $refusals : []) as $refusal) {
                $label = ['repeat' => 'repeat not sent', 'server' => 'DONE (CHIM memory)'][$refusal['verdict']] ?? 'BLOCKED';
                foreach (array_filter(explode("\n", strval($refusal['reasons'] ?? ''))) as $reason) {
                    $lines[] = "- " . (mb_strpos($reason, 'урезано') !== false ? 'CHANGED' : $label) . ": " . mb_substr($reason, 0, 190) . '.';
                }
            }
            // ext/tes_god_guard's ScriptProxy channel (equip/resurrect-kill) - a real
            // Papyrus call was sent, not just queued as a console command. Its own query
            // and LIMIT, separate from the refusals above (2026-09-29: sharing one LIMIT 4
            // let repeated ScriptProxy dispatches push a real refusal reason off the list).
            $spRows = $GLOBALS["db"]->fetchAll("
                SELECT kept_text FROM public.tes_god_guard_log
                WHERE verdict = 'scriptproxy' AND created_at > now() - interval '{$minutes} minutes'
                ORDER BY id DESC LIMIT 3
            ");
            foreach (array_reverse(is_array($spRows) ? $spRows : []) as $spRow) {
                $kept = strval($spRow['kept_text'] ?? '');
                if (preg_match('/^\{npc:([0-9A-Fa-f]{8})\}\.(.+)$/', $kept, $m)) {
                    $who = tesGodJournalNpc($m[1])['name'];
                    $kept = "{$who}: {$m[2]}";
                }
                $lines[] = "- SENT (ScriptProxy, result is not verified): {$kept}.";
            }
            // Explicit find/search answers (verdict 'search'): exact names the Narrator
            // asked the game index for - use them verbatim in the NEXT command.
            $searches = $GLOBALS["db"]->fetchAll("
                SELECT kept_text FROM public.tes_god_guard_log
                WHERE verdict = 'search' AND created_at > now() - interval '{$minutes} minutes'
                ORDER BY id DESC LIMIT 3
            ");
            foreach (array_reverse(is_array($searches) ? $searches : []) as $searchRow) {
                $lines[] = '- SEARCH: ' . strval($searchRow['kept_text']) . '.';
            }
        }
        // A recent autosave (ext/tes_god_guard's tesGodAutosaveIfNeeded, queued before a
        // hard-to-undo change) is worth one mention, so the Narrator can say honestly that
        // there is a rollback point if asked, without claiming it for every minor command.
        $autosave = $GLOBALS["db"]->fetchOne("
            SELECT status, applied_at IS NOT NULL AS done FROM public.skyrim_quest_action_outbox
            WHERE beat_id = 'tes_autosave' AND created_at > now() - interval '{$minutes} minutes'
            ORDER BY id DESC LIMIT 1
        ");
        if (is_array($autosave)) {
            // Postgres hands booleans back as the strings 't'/'f': !empty('f') is true in
            // PHP (a non-empty string), so that naive check always read as "done".
            $done = in_array($autosave['done'] ?? '', [true, 't', 'true', 1, '1'], true);
            $lines[] = $done
                ? '- Autosave made before this big world change (can be rolled back by loading the autosave).'
                : '- Autosave before this change was requested, but the game has not confirmed it yet (pause or waiting).';
        }
        // People created during play (FFxxxxxx) that the game reported in the last 3 hours:
        // clones, summons, Create_New_NPC. Leftovers pile up unless the Narrator removes them.
        $created = [];
        $events = $GLOBALS["db"]->fetchAll("
            SELECT data FROM public.eventlog
            WHERE type = 'addnpc' AND localts > extract(epoch FROM now()) - 10800
            ORDER BY localts DESC LIMIT 50
        ");
        foreach (is_array($events) ? $events : [] as $event) {
            $parts = explode('@', strval($event['data'] ?? ''));
            if (str_starts_with(strtoupper(trim(strval($parts[4] ?? ''))), 'FF') && trim(strval($parts[0])) !== '') {
                $created[trim($parts[0])] = true;
            }
        }
        if (empty($rows) && empty($lines) && empty($created)) {
            return '';
        }
        foreach (array_reverse($rows) as $row) {
            $result = json_decode(strval($row['result_json'] ?? ''), true);
            $row['result_text'] = is_array($result)
                ? mb_substr(implode(' ', array_filter(array_map(
                    static function ($v) { return is_scalar($v) ? trim(strval($v)) : ''; },
                    $result
                ))), 0, 80)
                : '';
            $lines[] = '- ' . tesGodJournalLine($row);
        }
        if (!empty($created)) {
            // at most 8 names (cost): the list grew to dozens after mass spawns
            $createdNames = array_keys($created);
            $lines[] = '- Created during play: ' . implode(', ', array_slice($createdNames, 0, 8))
                . (count($createdNames) > 8 ? ' and others: ' . (count($createdNames) - 8) : '')
                . '. Remove the extras: {near:Name}.unsummon.';
        }
        // Roadmap B: a hard stop after a run of failures, not just a soft suggestion in the
        // closing rule below (the model can and does ignore that and keeps retrying variants
        // of the same blocked command - seen in the log: five different NPC names refused in
        // a row as unknown, all in one reply).
        $streak = function_exists('tesGodGuardFailureStreak') ? tesGodGuardFailureStreak($minutes) : 0;
        if ($streak >= 3) {
            $lines[] = "- STOP: {$streak} commands in a row have failed. Do not invent another variant of the same request. "
                . 'In one sentence honestly say you cannot do it (or what exactly is missing - e.g. the exact name) and wait for a new request from the player.';
        }
        return "## Journal of your god commands (server-verified, last {$minutes} min)\n"
            . implode("\n", array_slice($lines, -14)) . "\n"
            . "Do not say it worked unless it says «done». «BLOCKED» - the problem is in the specific command named in the reason: "
            . "fix it and retry; do not say «не найден» unless the reason is about that. «FAILED» - admit it in one sentence and try another way; "
            . "«not verified» - do not claim the result. If you did something other than what was asked, admit it and do what was needed.";
    }
}

try {
    if (isset($GLOBALS["db"]) && function_exists('chimRegisterPromptInjection') && tesGodJournalIsNarratorTurn()) {
        tesGodJournalEnsureChannel();
        $tesGodJournal = tesGodJournalBuild();
        if ($tesGodJournal !== '') {
            chimRegisterPromptInjection('prompt_bottom', 'tes_god_journal', $tesGodJournal, 50);
        }
    }
} catch (Throwable $e) {
    error_log('[tes_god_journal] ' . $e->getMessage());
}
