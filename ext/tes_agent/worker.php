<?php
/*
 * tes_agent worker: the goal loop of the god agent (docs/narrator-agent.md).
 *
 *   php worker.php --task <id>            run a task created by tesAgentStart()
 *   php worker.php --goal "<text>" [--dry] create + run (testing); --dry sends nothing to
 *                                         the game: writes are only validated by the guard,
 *                                         game reads answer "dry-run"
 *
 * Loop: LLM (native tool calling, OpenRouter) -> typed tool -> the SERVER builds the console
 * text, every write goes through tes_god_guard exactly like a Narrator GodCommand -> one
 * game command batch at a time, wait for its tes_god_console report -> result back to the
 * LLM. "finish" is not trusted: the worker itself checks the expectations the model lists
 * (item counts, perks, skills, quest stages) and sends it back to work if any fail.
 */

if (php_sapi_name() !== 'cli') {
    exit;
}

const TES_AGENT_MAX_STEPS = 60;
const TES_AGENT_MAX_SECONDS = 600;
const TES_AGENT_GAME_TIMEOUT = 25;
const TES_AGENT_MODELS = [
    ['model' => 'deepseek/deepseek-v4-flash', 'connector' => 8, 'extra' => ['reasoning' => ['enabled' => false]]],
    ['model' => 'google/gemini-3.8-flash', 'connector' => 12, 'extra' => ['reasoning' => ['effort' => 'minimal']]],
];

$enginePath = '/var/www/html/HerikaServer/';
$GLOBALS['ENGINE_PATH'] = $enginePath;
chdir($enginePath);
require_once $enginePath . 'lib/runtime_bootstrap.php';
chimRuntimeBootstrap($enginePath, ['load_general_settings' => true, 'load_player_name' => true, 'load_narrator' => true]);
require_once $enginePath . 'lib/chat_helper_functions.php';
require_once $enginePath . 'lib/data_functions.php';
$GLOBALS['gameRequest'] = $GLOBALS['gameRequest'] ?? ['tes_agent', time(), 0, ''];
require_once $enginePath . 'functions/functions.php';  // herikaQueueGodCommands + ext functions (tes_god_guard)
require_once __DIR__ . '/lib.php';
if (!class_exists('RelationshipManager') && is_readable($enginePath . 'lib/relationship_manager.php')) {
    require_once $enginePath . 'lib/relationship_manager.php';  // tesGodGuardResolveNpcLoose needs it
}

$db = $GLOBALS['db'];
$args = getopt('', ['task:', 'goal:', 'dry', 'readonly', 'quick', 'local', 'silent']);
$tesLocalFirst = $db->fetchOne("SELECT value FROM conf_opts WHERE id = 'TES_AGENT_LOCAL_FIRST'");
$tesLocalOnly = $db->fetchOne("SELECT value FROM conf_opts WHERE id = 'TES_AGENT_LOCAL_ONLY'");
$GLOBALS['TES_AGENT_LOCAL_ONLY'] = trim(strval($tesLocalOnly['value'] ?? ''), '"') === '1';
$GLOBALS['TES_AGENT_LOCAL_FIRST'] = isset($args['local']) || trim(strval($tesLocalFirst['value'] ?? ''), '"') === '1';
// --quick: an order passed on by an NPC. Live 2026-10-04 02:49-03:00: such tasks ran 20-45 steps
// each (one spent 45 steps on an unkillable man) while five plain orders waited behind them.
// (cost, same day: four of six quick tasks ran all 18 steps at ~8K tokens each and failed - now 10)
$maxSteps = isset($args['quick']) ? 10 : TES_AGENT_MAX_STEPS;
$maxSeconds = isset($args['quick']) ? 90 : TES_AGENT_MAX_SECONDS;
$dry = isset($args['dry']);
// --readonly: a QUESTION, not a deed ("ask: ..." from the Narrator). Dry run 2026-10-03: asked
// "what is on my quest list", the agent teleported the player and tried to move a quest
// stage. In this mode the write tools are simply not offered.
$readonly = isset($args['readonly']);
tesAgentEnsureTable();
if (!empty($args['goal'])) {
    $row = $db->fetchOne("INSERT INTO public.tes_agent_tasks (goal) VALUES ('" . $db->escape($args['goal']) . "') RETURNING id");
    $taskId = intval($row['id']);
} else {
    $taskId = intval($args['task'] ?? 0);
}
$GLOBALS['TES_AGENT_TASK_ID'] = $taskId;
$task = $db->fetchOne("SELECT * FROM public.tes_agent_tasks WHERE id = {$taskId}");
if (!$task) {
    fwrite(STDERR, "no task {$taskId}\n");
    exit(1);
}
$db->execQuery("UPDATE public.tes_agent_tasks SET status = 'running', pid = " . getmypid() . ", updated_at = now() WHERE id = {$taskId}");
// whatever way this worker ends (finish, limit, crash) - the next order in line starts
register_shutdown_function(function () use ($taskId) {
    try {
        $GLOBALS['db']->execQuery("UPDATE public.tes_agent_tasks SET status = 'failed', result = 'исполнитель прервался' WHERE id = {$taskId} AND status = 'running'");
        tesAgentStartNext();
    } catch (Throwable $e) {
        error_log('[tes_agent] next: ' . $e->getMessage());
    }
});
echo "task #{$taskId}" . ($dry ? ' (dry)' : '') . ": {$task['goal']}\n";
$GLOBALS['TES_AGENT_GOAL'] = strval($task['goal']);

/* ------------------------------------------------------------------ LLM */

function tesAgentApiKey(int $connectorId): string
{
    $row = $GLOBALS['db']->fetchOne("SELECT b.* FROM core_api_badge b JOIN core_llm_connector c ON c.api_badge_id = b.id WHERE c.id = {$connectorId}");
    foreach (is_array($row) ? $row : [] as $v) {
        if (is_string($v) && str_starts_with($v, 'sk-or-')) {
            return $v;
        }
    }
    return '';
}

/**
 * The local model (LM Studio on the Windows host, OpenAI-compatible, model qwen/qwen3.5-4b -
 * docs/local-llm.md). Used when the cloud gives nothing (no key, no money, no network), or first
 * when --local is passed or conf_opts TES_AGENT_LOCAL_FIRST = 1.
 */
function tesAgentLocalUrl(): string
{
    static $url = null;
    if ($url === null) {
        $host = trim(strval(@shell_exec("ip route 2>/dev/null | awk '/default/ {print \$3; exit}'")));
        $url = preg_match('/^[0-9.]+$/', $host) ? "http://{$host}:1234/v1/chat/completions" : '';
    }
    return $url;
}

function tesAgentLlm(array $messages, array $tools, float &$cost): ?array
{
    // reasoning_effort none: left to think, Qwen3.5 spends the whole answer budget on it (measured)
    $local = ['model' => 'qwen/qwen3.5-4b', 'local' => true, 'extra' => ['reasoning_effort' => 'none']];
    // Owner, 2026-10-04: the local model was tried and removed the same night. It is used only when
    // asked for explicitly (--local / TES_AGENT_LOCAL_FIRST) - never as a silent fallback.
    $models = !empty($GLOBALS['TES_AGENT_LOCAL_FIRST']) ? array_merge([$local], TES_AGENT_MODELS) : TES_AGENT_MODELS;
    if (!empty($GLOBALS['TES_AGENT_LOCAL_ONLY'])) {
        $models = [$local];  // owner 2026-10-04: no cloud anywhere
    }
    foreach ($models as $cfg) {
        $isLocal = !empty($cfg['local']);
        $key = $isLocal ? 'local' : tesAgentApiKey($cfg['connector']);
        $url = $isLocal ? tesAgentLocalUrl() : 'https://openrouter.ai/api/v1/chat/completions';
        if ($key === '' || $url === '') {
            continue;
        }
        $body = array_merge(['model' => $cfg['model'], 'messages' => $messages, 'tools' => $tools,
            'tool_choice' => 'auto', 'max_tokens' => 1500, 'temperature' => 0.3], $isLocal ? [] : ['usage' => ['include' => true]], $cfg['extra']);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => $isLocal ? 180 : 90, CURLOPT_CONNECTTIMEOUT => $isLocal ? 3 : 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$key}"],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
        $t0 = microtime(true);
        $resp = json_decode(strval(curl_exec($ch)), true);
        $msg = $resp['choices'][0]['message'] ?? null;
        if (is_array($msg)) {
            $cost += floatval($resp['usage']['cost'] ?? 0);
            if ($isLocal) {
                echo sprintf("  (local %.1fs, in %d out %d tokens)
", microtime(true) - $t0, intval($resp['usage']['prompt_tokens'] ?? 0), intval($resp['usage']['completion_tokens'] ?? 0));
                unset($msg['reasoning_content'], $msg['reasoning']);
                $msg['content'] = trim(preg_replace('/<think>.*?<\/think>/us', '', strval($msg['content'] ?? '')) ?? '');
            }
            return $msg;
        }
        echo "  ! {$cfg['model']}: " . mb_substr(json_encode($resp['error'] ?? $resp, JSON_UNESCAPED_UNICODE), 0, 300) . "\n";
    }
    return null;
}

/* ------------------------------------------------------------------ game I/O */

function tesAgentConsoleMaxId(): int
{
    $db = $GLOBALS['db'];
    $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_god_console_log (id bigserial PRIMARY KEY, created_at timestamptz NOT NULL DEFAULT now(), gamets bigint, command text NOT NULL, output text NOT NULL DEFAULT '')");
    return intval($db->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_god_console_log")['m'] ?? 0);
}

/** Wait for console reports newer than $sinceId: until $expected rows came (then 2 s quiet) or timeout. */
function tesAgentWaitReports(int $sinceId, int $expected): array
{
    $started = microtime(true);
    $rows = [];
    $lastNew = $started;
    while (microtime(true) - $started < TES_AGENT_GAME_TIMEOUT) {
        $got = $GLOBALS['db']->fetchAll("SELECT id, command, output FROM public.tes_god_console_log WHERE id > {$sinceId} ORDER BY id");
        if (count($got) > count($rows)) {
            $rows = $got;
            $lastNew = microtime(true);
        }
        if (count($rows) >= $expected && microtime(true) - $lastNew > 2) {
            break;
        }
        usleep(400000);
    }
    return array_map(fn($r) => ['command' => $r['command'], 'output' => $r['output']], $rows);
}

/** Read-only bridge/console reads: straight to the queue (nothing to guard). */
function tesAgentRead(array $commands, bool $dry): array
{
    if ($dry) {
        return ['dry_run' => true, 'note' => 'game not queried (dry run), treat values as unknown', 'commands' => $commands];
    }
    $since = tesAgentConsoleMaxId();
    $queued = herikaQueueGodCommands(implode('; ', $commands));
    if ($queued === 0) {
        return ['error' => 'could not queue'];
    }
    $reports = tesAgentWaitReports($since, count($commands));
    if (!$reports) {
        return ['error' => 'game did not answer in ' . TES_AGENT_GAME_TIMEOUT . ' s (pause, menu or loading?)'];
    }
    return ['reports' => $reports];
}

/** Writes: the same path as a Narrator GodCommand (tes_god_guard validate/resolve/autosave -> queue). */
function tesAgentWrite(string $text, bool $dry): array
{
    if ($dry) {
        $check = tesGodGuardValidate($text);
        return ['dry_run' => true, 'would_send' => $check['kept'], 'server' => $check['server'],
            'refused' => $check['reasons'],
            'note' => empty($check['reasons']) ? 'dry run: assume it succeeded, do not repeat' : 'refused by the guard'];
    }
    $db = $GLOBALS['db'];
    $since = tesAgentConsoleMaxId();
    $guardSince = intval($db->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_god_guard_log")['m'] ?? 0);
    $filtered = tesGodGuardFilterAction('The Narrator|command|GodCommand@' . json_encode(['target' => $text], JSON_UNESCAPED_UNICODE));
    $queued = 0;
    if ($filtered !== null) {
        $call = explode('@', explode('|', $filtered)[2] ?? '', 2);
        $kept = trim(strval(json_decode($call[1] ?? '', true)['target'] ?? ''));
        $queued = $kept === '' ? 0 : herikaQueueGodCommands($kept);
        if ($queued > 0 && function_exists('tesAgentJournal')) {
            tesAgentJournal(intval($GLOBALS['TES_AGENT_TASK_ID'] ?? 0), $kept);  // «верни как было» undoes the whole task
        }
    }
    $guard = $db->fetchAll("SELECT * FROM public.tes_god_guard_log WHERE id > {$guardSince} ORDER BY id");
    $verdicts = array_map(function ($g) {
        unset($g['id'], $g['created_at'], $g['raw_text']);
        return array_filter($g, fn($v) => $v !== null && $v !== '' && $v !== '[]' && $v !== '{}');
    }, $guard);
    $result = ['guard' => $verdicts];
    if ($queued > 0) {
        $result['reports'] = tesAgentWaitReports($since, $queued);
        if (!$result['reports']) {
            $result['warning'] = 'game sent no report in ' . TES_AGENT_GAME_TIMEOUT . ' s - verify the result by reading state';
        }
    }
    return $result;
}

/** Many writes: herikaQueueGodCommands takes at most 8 commands per text, so send in chunks of 8. */
function tesAgentWriteBatch(array $commands, bool $dry): array
{
    if (!$commands) {
        return ['error' => 'empty list'];
    }
    $out = [];
    foreach (array_chunk($commands, 8) as $chunk) {
        $out[] = tesAgentWrite(implode('; ', $chunk), $dry);
    }
    return count($out) === 1 ? $out[0] : ['batches' => $out];
}

function tesAgentNumber(array $res): ?float
{
    $out = strval(end($res['reports'])['output'] ?? '');
    return preg_match('/(-?\d+(?:\.\d+)?)\s*$/', trim($out), $m) ? floatval($m[1]) : null;
}

/* ------------------------------------------------------------------ tools */

function tesAgentWho(string $who): string
{
    $who = trim($who);
    return ($who === '' || preg_match('/^(player|игрок|me|я)$/iu', $who)) ? 'player' : '{npc:' . $who . '}';
}

function tesAgentFind(array $a): array
{
    $db = $GLOBALS['db'];
    $kinds = ['item' => "'item'", 'npc' => "'npc'", 'place' => "'cell','location'", 'perk' => "'perk'", 'spell' => "'spell'",
        'quest' => "'quest'", 'faction' => "'faction'", 'enchantment' => "'enchantment'", 'outfit' => "'outfit'"];
    $kind = strval($a['kind'] ?? 'item');
    $where = ['kind IN (' . ($kinds[$kind] ?? "'item'") . ')', "name <> ''"];
    foreach (preg_split('/\s+/u', mb_strtolower(trim(strval($a['query'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        $w = $db->escape($w);
        $where[] = "(name_lc LIKE '%{$w}%' OR editor_id_lc LIKE '%{$w}%')";
    }
    // Models often put the filters next to kind/query instead of inside "filters" (dry run
    // 2026-10-03: every filter silently ignored -> heavy daedric armour for "light thief armour").
    $f = array_merge(array_diff_key($a, array_flip(['kind', 'query', 'sort_by', 'limit', 'filters'])),
        is_array($a['filters'] ?? null) ? $a['filters'] : []);
    // Service and test records (REQ_NULL_*, test*, nonPlayable, "-", "0") are never what anyone wants.
    $where[] = "editor_id_lc NOT LIKE '%null%' AND editor_id_lc NOT LIKE '%test%' AND editor_id_lc NOT LIKE '%nonplayable%'"
        . " AND editor_id_lc NOT LIKE '%dummy%' AND name !~ '^[-0-9 .]*$' AND name NOT LIKE '%Test%'";  // C locale: no [[:alpha:]] for Cyrillic
    if ($kind === 'perk' && !empty($f['skill'])) {
        // Requiem names its perks REQ_<Skill>_<Perk> (REQ_Sneak_Stealth1, REQ_Pickpocket_NightlyThief)
        // The engine's own skill perk trees (AVIF records, any mod) give extra.skill = AV<Skill>.
        $skill = strtolower(preg_replace('/[^A-Za-z]/', '', strval($f['skill'])));
        $skill = ['evasion' => 'lightarmor', 'marksmanship' => 'marksman', 'archery' => 'marksman',
            'speech' => 'speechcraft'][$skill] ?? $skill;
        $where[] = "lower(extra->>'skill') = 'av" . $db->escape($skill) . "'";
    }
    // Summoned / bound gear vanishes, Non-Playable armour cannot be worn by the player.
    $where[] = "editor_id_lc NOT LIKE '%conjure%' AND editor_id_lc NOT LIKE '%bound%' AND extra->>'np' IS NULL";
    $recs = ['armor' => 'ARMO', 'weapon' => 'WEAP', 'potion' => 'ALCH', 'ammo' => 'AMMO', 'scroll' => 'SCRL', 'book' => 'BOOK', 'ingredient' => 'INGR'];
    if (!empty($f['type']) && isset($recs[$f['type']])) {
        $where[] = "extra->>'rec' = '{$recs[$f['type']]}'";
    }
    if (!empty($f['armor_class'])) {
        $where[] = "extra->>'armor' = '" . $db->escape(strval($f['armor_class'])) . "'";
    }
    $slots = ['head' => 30, 'body' => 32, 'hands' => 33, 'feet' => 37, 'amulet' => 35, 'ring' => 36, 'shield' => 39, 'circlet' => 42];
    if (!empty($f['slot']) && isset($slots[$f['slot']])) {
        $where[] = "extra->'slots' @> '[{$slots[$f['slot']]}]'";
    }
    if (!empty($f['weapon_type'])) {
        $where[] = "extra->>'wtype' = '" . $db->escape(strval($f['weapon_type'])) . "'";
    }
    if (isset($f['poison'])) {
        $where[] = $f['poison'] ? "extra->>'poison' = 'true'" : "extra->>'poison' IS NULL";
    }
    if (!empty($f['effect'])) {
        // LIKE is case-sensitive and the C locale cannot fold Cyrillic: try both first-letter cases.
        $eff = mb_strtolower(trim(strval($f['effect'])));
        $effUp = mb_strtoupper(mb_substr($eff, 0, 1)) . mb_substr($eff, 1);
        $where[] = "(extra->>'fx' LIKE '%" . $db->escape($eff) . "%' OR extra->>'fx' LIKE '%" . $db->escape($effUp) . "%')";
    }
    if (!empty($f['keyword'])) {
        $where[] = "extra->'kw' ? '" . $db->escape(strval($f['keyword'])) . "'";
    }
    if (!empty($f['enchanted'])) {
        $where[] = "extra ? 'ench'";
    }
    $sorts = ['armor_rating' => "(extra->>'ar')::float", 'damage' => "(extra->>'dmg')::float", 'value' => "(extra->>'value')::float"];
    $order = $sorts[strval($a['sort_by'] ?? '')] ?? 'length(name)';
    $dir = isset($sorts[strval($a['sort_by'] ?? '')]) ? 'DESC NULLS LAST' : 'ASC';
    $limit = max(1, min(25, intval($a['limit'] ?? 12)));
    $rows = $db->fetchAll("SELECT formid, editor_id, name, plugin, extra FROM public.tes_game_index WHERE " . implode(' AND ', $where) . " ORDER BY {$order} {$dir} LIMIT {$limit}");
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $r) {
        $x = json_decode(strval($r['extra']), true) ?: [];
        unset($x['kw']);  // long; filter by keyword instead
        if (!empty($x['fx'])) {
            $x['fx'] = array_map(fn($e) => trim(($e['n'] ?? '') . ' ' . ($e['m'] ?? '') . ($e['d'] ? " {$e['d']}s" : '')), $x['fx']);
        }
        if (!empty($x['ench'])) {
            $en = $db->fetchOne("SELECT name, extra FROM public.tes_game_index WHERE formid = '" . $db->escape($x['ench']) . "'");
            $enx = json_decode(strval($en['extra'] ?? ''), true) ?: [];
            $x['ench'] = trim(($en['name'] ?? '') . ': ' . implode(', ', array_map(fn($e) => ($e['n'] ?? '') . ' ' . ($e['m'] ?? ''), $enx['fx'] ?? [])));
        }
        $out[] = ['formid' => $r['formid'], 'name' => $r['name'], 'editor_id' => $r['editor_id'], 'plugin' => $r['plugin']] + $x;
    }
    return ['count' => count($out), 'results' => $out];
}

/* Server-side knowledge (CHIM database): answers at once, nothing is sent to the game. */

function tesAgentNpcInfo(string $name): array
{
    $row = function_exists('tesGodGuardResolveNpcLoose') ? tesGodGuardResolveNpcLoose(trim($name)) : null;
    if (!$row) {
        // not met yet: the game index answers at once (before, the model spent a second turn on find kind=npc -
        // logs 210-214: npc_info "Лидия" -> error -> find -> the same answer)
        $db = $GLOBALS['db'];
        $hit = $db->fetchOne("SELECT formid, name, editor_id, plugin FROM public.tes_game_index WHERE kind = 'npc' AND name_lc = '" . $db->escape(mb_strtolower(trim($name))) . "' LIMIT 1");
        return !empty($hit['formid'])
            ? ['name' => $hit['name'], 'base_formid' => $hit['formid'], 'editor_id' => $hit['editor_id'], 'plugin' => $hit['plugin'],
                'note' => 'never spoken to: no CHIM profile, this is game data; {npc:' . $hit['name'] . '} works in commands, state - get_state']
            : ['error' => "«{$name}» is neither among known people nor in the game index - check the name (find kind=npc with part of the name)"];
    }
    $full = $GLOBALS['db']->fetchOne("SELECT * FROM public.core_npc_master WHERE id = " . intval($row['id']));
    $meta = json_decode(strval($full['metadata'] ?? ''), true) ?: [];
    $ext = json_decode(strval($full['extended_data'] ?? ''), true) ?: [];
    $act = $meta['activity_status'] ?? [];
    $cut = fn($s, $n = 220) => mb_substr(trim(preg_replace('/\s+/u', ' ', strval($s)) ?? ''), 0, $n);
    return [
        'name' => $full['npc_name'], 'refid' => $full['refid'], 'gender' => $full['gender'], 'race' => $full['race'],
        'occupation' => $cut($full['occupation']), 'personality' => $cut($full['personality']), 'goals' => $cut($full['goals']),
        'stats' => $meta['stats'] ?? null, 'skills' => $meta['skills'] ?? null,
        'alive' => isset($act['is_dead']) ? !$act['is_dead'] : null,
        'doing' => $act['current_action'] ?? null, 'in_combat' => $act['is_in_combat'] ?? null,
        'relation_to_player' => $ext['relationships']['Player'] ?? null,
        'profile_locked' => intval($full['lock_profile'] ?? 0) === 1,
        'relationships_locked' => !empty($ext['relationships_locked']),
    ];
}

function tesAgentRelationships(array $a): array
{
    $rows = $GLOBALS['db']->fetchAll("SELECT npc_name, extended_data->'relationships'->'Player' AS rel FROM public.core_npc_master
        WHERE extended_data->'relationships'->'Player' IS NOT NULL");
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $r) {
        $rel = json_decode(strval($r['rel']), true) ?: [];
        $aff = intval($rel['aff'] ?? 0);
        if ((isset($a['min']) && $aff < intval($a['min'])) || (isset($a['max']) && $aff > intval($a['max']))) {
            continue;
        }
        $out[] = ['npc' => $r['npc_name'], 'aff' => $aff, 'type' => $rel['type'] ?? '', 'note' => mb_substr(strval($rel['note'] ?? ''), 0, 100)];
    }
    usort($out, fn($x, $y) => $x['aff'] <=> $y['aff']);
    return ['count' => count($out), 'results' => array_slice($out, 0, max(1, min(40, intval($a['limit'] ?? 25))))];
}

function tesAgentQuestLog(string $query): array
{
    $db = $GLOBALS['db'];
    $rows = $db->fetchAll("SELECT DISTINCT ON (id_quest) id_quest, name, editor_id, stage, status, briefing FROM public.quests ORDER BY id_quest, rowid DESC");
    $needle = mb_strtolower(trim($query));
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $r) {
        if ($needle !== '' && mb_strpos(mb_strtolower($r['name'] . ' ' . $r['briefing']), $needle) === false) {
            continue;
        }
        $out[] = ['name' => $r['name'], 'editor_id' => $r['editor_id'] ?: $r['id_quest'], 'stage' => $r['stage'], 'status' => $r['status'],
            'now' => mb_substr(trim(preg_replace('/\s+/u', ' ', strval($r['briefing'])) ?? ''), 0, 160)];
    }
    return ['count' => count($out), 'results' => array_slice($out, 0, 30)];
}

function tesAgentTools(): array
{
    $t = fn($name, $desc, $props, $req = []) => ['type' => 'function', 'function' => ['name' => $name, 'description' => $desc,
        'parameters' => ['type' => 'object', 'properties' => (object)$props, 'required' => $req]]];
    $who = ['type' => 'string', 'description' => 'player or NPC name'];
    $fid = ['type' => 'string', 'description' => 'FormID from find (8 hex)'];
    return [
        $t('find', 'Search the game data (all mods, stats after Requiem). Items carry stats: ar (armor), dmg, speed, weight, value, armor (light/heavy/clothing), slots, ench, fx (potion effects).', [
            'kind' => ['type' => 'string', 'enum' => ['item', 'npc', 'place', 'perk', 'spell', 'quest', 'faction', 'enchantment', 'outfit']],
            'query' => ['type' => 'string', 'description' => 'words of the name (Russian) or EditorID; may be empty when filters are set'],
            'filters' => ['type' => 'object', 'properties' => [
                'type' => ['type' => 'string', 'enum' => ['armor', 'weapon', 'potion', 'ammo', 'scroll', 'book', 'ingredient']],
                'armor_class' => ['type' => 'string', 'enum' => ['light', 'heavy', 'clothing']],
                'slot' => ['type' => 'string', 'enum' => ['head', 'body', 'hands', 'feet', 'amulet', 'ring', 'shield', 'circlet']],
                'weapon_type' => ['type' => 'string', 'enum' => ['dagger', 'sword', 'waraxe', 'mace', 'greatsword', 'battleaxe', 'bow', 'crossbow', 'staff']],
                'poison' => ['type' => 'boolean'], 'enchanted' => ['type' => 'boolean'],
                'effect' => ['type' => 'string', 'description' => 'part of the effect name (Russian), e.g. невидимость'],
                'keyword' => ['type' => 'string', 'description' => 'keyword EditorID, e.g. ArmorLight'],
                'skill' => ['type' => 'string', 'description' => 'for kind=perk: the skill of the tree (Sneak, Pickpocket, Lockpicking, LightArmor, OneHanded, Marksman, Alchemy, Speech...)'],
            ]],
            'sort_by' => ['type' => 'string', 'enum' => ['armor_rating', 'damage', 'value']],
            'limit' => ['type' => 'integer'],
        ], ['kind']),
        $t('get_state', 'Character state from the game: level, health, all skills, gold, perk points, worn items by slot and weapons in hands.', ['who' => $who]),
        $t('inspect_here', 'What is around the player in the current cell: name, owner, doors, containers, NPCs, locks.', []),
        $t('check', 'Check facts in the game. kind: item (count of the item on who), perk (has the perk: 1/0), spell (has the spell), skill (skill value, id = skill name, e.g. Sneak), stage (is the quest stage done: id = quest EditorID, stage).', [
            'kind' => ['type' => 'string', 'enum' => ['item', 'perk', 'spell', 'skill', 'stage']],
            'who' => $who, 'id' => ['type' => 'string'], 'stage' => ['type' => 'integer'],
        ], ['kind', 'id']),
        $t('give_items', 'Give a list of items (equip - put on / take in hand at once).', ['who' => $who, 'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'formid' => $fid, 'count' => ['type' => 'integer'], 'equip' => ['type' => 'boolean']], 'required' => ['formid']]]], ['items']),
        $t('remove_item', 'Take an item away.', ['who' => $who, 'formid' => $fid, 'count' => ['type' => 'integer']], ['formid']),
        $t('set_skills', 'Set base values of skills/attributes as a list: {"Sneak":100,"Lockpicking":100}. Names: OneHanded TwoHanded Marksman Block Smithing HeavyArmor LightArmor Pickpocket Lockpicking Sneak Alchemy Speechcraft Alteration Conjuration Destruction Illusion Restoration Enchanting Health Magicka Stamina.', ['who' => $who, 'values' => ['type' => 'object']], ['values']),
        $t('add_perks', 'Give perks by a list of FormIDs (from find kind=perk). Give the prerequisite perks of the tree too.', ['who' => $who, 'formids' => ['type' => 'array', 'items' => $fid]], ['formids']),
        $t('add_spell', 'Give a spell or ability.', ['who' => $who, 'formid' => $fid], ['formid']),
        $t('set_level', 'Set the player\'s level.', ['level' => ['type' => 'integer']], ['level']),
        $t('npc_info', 'What the server knows about a character WITHOUT asking the game: gender, race, occupation, personality, level, health, skills, alive or not and what they do, attitude to the player, whether the profile is locked.', ['npc' => ['type' => 'string']], ['npc']),
        $t('relationships', 'Who feels how about the player: NPCs with their attitude (-100..100). Filter min/max, e.g. max=-20 - those who hate.', ['min' => ['type' => 'integer'], 'max' => ['type' => 'integer'], 'limit' => ['type' => 'integer']]),
        $t('quest_log', 'The player\'s quest journal: name, stage, description.', ['query' => ['type' => 'string', 'description' => 'words of the name, may be empty']]),
        $t('set_relationship', 'An NPC\'s attitude (-100..100), type neutral/friend/romantic/lover/rival/enemy and a reason. Towards the player by default; to - towards another NPC (set at odds, befriend, make fall in love).', ['npc' => ['type' => 'string'], 'to' => ['type' => 'string', 'description' => 'name of the other NPC; empty = towards the player'], 'value' => ['type' => 'integer'], 'type' => ['type' => 'string'], 'reason' => ['type' => 'string']], ['npc', 'value', 'type']),
        $t('marry', 'Marry two characters (NPC with NPC).', ['a' => ['type' => 'string'], 'b' => ['type' => 'string']], ['a', 'b']),
        $t('remember', 'Plant a memory in a character (they will remember it and act on it).', ['npc' => ['type' => 'string'], 'text' => ['type' => 'string']], ['npc', 'text']),
        $t('change_character', 'Change an NPC\'s personality. instruction - a suggestion in words (rewrites personality, goals, speech style, occupation wholesale) OR field+text for one field (personality, occupation, speechstyle, goals, appearance). The profile gets locked against auto-rewrite.', ['npc' => ['type' => 'string'], 'instruction' => ['type' => 'string'], 'field' => ['type' => 'string'], 'text' => ['type' => 'string']], ['npc']),
        $t('heal', 'Heal fully: health, magicka, stamina, diseases, raise from knockout.', ['who' => $who]),
        $t('revive', 'Resurrect a dead NPC.', ['npc' => ['type' => 'string']], ['npc']),
        $t('kill', 'Kill an NPC.', ['npc' => ['type' => 'string']], ['npc']),
        $t('settle_here', 'Resettle an NPC: mode=here - now lives and spends the days where the player stands; mode=reset - back to the old routine.', ['npc' => ['type' => 'string'], 'mode' => ['type' => 'string', 'enum' => ['here', 'reset']]], ['npc', 'mode']),
        $t('rumor', 'Spread a rumour through the hold (its people will know it).', ['text' => ['type' => 'string']], ['text']),
        $t('write_document', 'A real paper into the inventory of the player or an NPC: deed, pass, letter.', ['to' => $who, 'title' => ['type' => 'string'], 'text' => ['type' => 'string']], ['title', 'text']),
        $t('give_house', 'Give the player a house by name (as in the game): ownership and the key.', ['house' => ['type' => 'string']], ['house']),
        $t('furnish_house', 'Buy the player all upgrades of a city house at once, free (Дом теплых ветров, Высокий шпиль, Медовик, Влиндрел-холл, Хьерим).', ['house' => ['type' => 'string']], ['house']),
        $t('order_npc', 'Make a character do or say something aloud (judges, scolds, apologises, leaves): they carry it out themselves.', ['npc' => ['type' => 'string'], 'what' => ['type' => 'string', 'description' => 'what they do and say, in one sentence']], ['npc', 'what']),
        $t('jail', 'Put a character into the jail of the current hold (release=true - let out and return to the player).', ['npc' => ['type' => 'string'], 'release' => ['type' => 'boolean']], ['npc']),
        $t('fine_npc', 'Fine a character by law, like the player: a guard demands payment; enough gold - pays, not enough - taken to jail.', ['npc' => ['type' => 'string'], 'amount' => ['type' => 'integer']], ['npc', 'amount']),
        $t('set_title', 'Give the player a title every character acknowledges, in Russian (ярл Вайтрана, тан, архимаг, глава гильдии). Empty or «нет» - remove.', ['title' => ['type' => 'string']], ['title']),
        $t('pardon', 'Clear the player\'s bounty in the current hold, reset the alarm and stop everyone fighting him.', []),
        $t('unfollow', 'The character stops following the player.', ['npc' => ['type' => 'string']], ['npc']),
        $t('set_world', 'Time of day and/or weather. weather: clear, cloudy, fog, rain, storm, snow, blizzard.', ['hour' => ['type' => 'number'], 'weather' => ['type' => 'string']]),
        $t('teleport_player', 'Move the player: place - to a place (name as in the game) OR to_npc - to a character.', ['place' => ['type' => 'string'], 'to_npc' => ['type' => 'string']]),
        $t('move_npc', 'Move an NPC to the player or to another character (to_npc).', ['npc' => ['type' => 'string'], 'to_npc' => ['type' => 'string']], ['npc']),
        $t('set_quest_stage', 'Set a quest stage (quest EditorID from find kind=quest and a stage number from its stages). Vanilla house purchase: HousePurchase 10 (Whiterun).', ['quest' => ['type' => 'string'], 'stage' => ['type' => 'integer']], ['quest', 'stage']),
        $t('claim_here', 'The current house/interior and everything in it becomes the player\'s property, locks open.', []),
        $t('console', 'Fallback: a raw Skyrim console command (checked by the guard). Only when no tool fits.', ['command' => ['type' => 'string']], ['command']),
        $t('finish', 'The goal is done. List the expectations the server will itself check in the game; if something does not match - the work goes on.', [
            'summary' => ['type' => 'string', 'description' => 'what was done, in Russian, brief'],
            'expect' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['item', 'perk', 'spell', 'skill', 'stage']],
                'who' => ['type' => 'string'], 'id' => ['type' => 'string'], 'stage' => ['type' => 'integer'],
                'min' => ['type' => 'number', 'description' => 'minimum (for skill/item); for perk/spell/stage - 1'],
            ], 'required' => ['kind', 'id']]],
        ], ['summary', 'expect']),
        $t('give_up', 'The goal cannot be done by the game\'s means - explain why.', ['reason' => ['type' => 'string']], ['reason']),
    ];
}

function tesAgentCheck(array $c, bool $dry): array
{
    $who = tesAgentWho(strval($c['who'] ?? 'player'));
    $id = trim(strval($c['id'] ?? ''));
    switch ($c['kind'] ?? '') {
        case 'item':  $cmd = "{$who}.getitemcount {$id}"; break;
        case 'perk':  $cmd = "{$who}.hasperk {$id}"; break;
        case 'spell': $cmd = "{$who}.hasspell {$id}"; break;
        case 'skill': $cmd = "{$who}.getbaseav {$id}"; break;
        case 'stage': $cmd = "getstagedone {$id} " . intval($c['stage'] ?? 0); break;
        default: return ['error' => 'unknown kind'];
    }
    $res = tesAgentRead([$cmd], $dry);
    $res['value'] = isset($res['reports']) ? tesAgentNumber($res) : null;
    return $res;
}

function tesAgentRun(string $name, array $a, bool $dry, array &$finishState)
{
    $who = tesAgentWho(strval($a['who'] ?? 'player'));
    $hex = fn($v) => strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', strval($v)));
    // free text inside a guard command: no separators, no placeholder braces
    $clean = fn(string $s) => trim(str_replace([';', "\n", "\r", '{', '}'], [',', ' ', ' ', '', ''], $s));
    $npc = fn(string $name) => '{npc:' . trim(str_replace(['{', '}', ';'], '', $name)) . '}';
    $fid = $hex($a['formid'] ?? '');
    if (in_array($name, ['remove_item', 'add_spell'], true) && !preg_match('/^[0-9A-F]{8}$/', $fid)) {
        return ['error' => 'formid must be 8 hex digits from find'];
    }
    switch ($name) {
        case 'find':
            return tesAgentFind($a);
        case 'get_state':
            // the player by RefID too: a bare "tesstate" reads whoever the console selected last (live
            // 2026-10-04 03:14: get_state player returned Ньяда Каменная Рука)
            $res = tesAgentRead([$who === 'player' ? '00000014.tesstate' : "{$who}.tesstate"], $dry);
            foreach ($res['reports'] ?? [] as $r) {
                if ($r['command'] === 'tesstate') {
                    // the bridge prints FormIDs in decimal ("worn body=Кираса#80156"); every
                    // command wants hex (live 2026-10-04: "unequipitem 80156" - item not found)
                    return ['state' => preg_replace_callback('/#(-?\d+)/', fn($m) => '#' . strtoupper(str_pad(dechex(intval($m[1]) & 0xFFFFFFFF), 8, '0', STR_PAD_LEFT)), $r['output'])];
                }
            }
            return $res;
        case 'inspect_here':
            return tesAgentRead(['tesinspect'], $dry);
        case 'check':
            return tesAgentCheck($a, $dry);
        case 'give_items':
            $cmds = [];
            $bad = [];
            foreach (is_array($a['items'] ?? null) ? $a['items'] : [] as $it) {
                $f = $hex(is_array($it) ? ($it['formid'] ?? '') : $it);
                if (!preg_match('/^[0-9A-F]{8}$/', $f)) {
                    $bad[] = $it;
                    continue;
                }
                $cmds[] = "{$who}.additem {$f} " . max(1, min(1000, intval($it['count'] ?? 1)));
                if (!empty($it['equip'])) {
                    $cmds[] = "{$who}.equipitem {$f}";
                }
            }
            return tesAgentWriteBatch($cmds, $dry) + ($bad ? ['bad_formids' => $bad] : []);
        case 'remove_item':
            return tesAgentWrite("{$who}.removeitem {$fid} " . max(1, intval($a['count'] ?? 1)), $dry);
        case 'set_skills':
            // actor value names (Speech/Archery/Evasion are menu or Requiem tree names, not AVs)
            $alias = ['speech' => 'Speechcraft', 'archery' => 'Marksman', 'marksmanship' => 'Marksman', 'evasion' => 'LightArmor'];
            $cmds = [];
            foreach (is_array($a['values'] ?? null) ? $a['values'] : [] as $skill => $value) {
                $skill = preg_replace('/[^A-Za-z]/', '', strval($skill));
                $skill = $alias[strtolower($skill)] ?? $skill;
                $cmds[] = "{$who}.setav {$skill} " . floatval($value);
            }
            return tesAgentWriteBatch($cmds, $dry);
        case 'add_perks':
            $cmds = [];
            foreach (is_array($a['formids'] ?? null) ? $a['formids'] : [$a['formids'] ?? ''] as $f) {
                $f = $hex($f);
                if (preg_match('/^[0-9A-F]{8}$/', $f)) {
                    $cmds[] = "{$who}.addperk {$f}";
                }
            }
            return tesAgentWriteBatch($cmds, $dry);
        case 'add_spell':
            return tesAgentWrite("{$who}.addspell {$fid}", $dry);
        case 'set_level':
            return tesAgentWrite('player.setlevel ' . max(1, min(500, intval($a['level'] ?? 1))), $dry);
        case 'npc_info':
            return tesAgentNpcInfo(strval($a['npc'] ?? ''));
        case 'relationships':
            return tesAgentRelationships($a);
        case 'quest_log':
            return tesAgentQuestLog(strval($a['query'] ?? ''));
        case 'set_relationship':
            $reason = $clean(strval($a['reason'] ?? ''));
            $to = $clean(strval($a['to'] ?? ''));
            $to = ($to === '' || preg_match('/^(player|игрок)$/iu', $to)) ? '' : 'to ' . $to . ' ';
            return tesAgentWrite($npc(strval($a['npc'] ?? '')) . '.relation ' . $to . intval($a['value'] ?? 0) . ' '
                . preg_replace('/[^a-z]/', '', strtolower(strval($a['type'] ?? 'neutral'))) . ' ' . $reason, $dry);
        case 'marry':
            return tesAgentWrite($npc(strval($a['a'] ?? '')) . '.marry ' . $clean(strval($a['b'] ?? '')), $dry);
        case 'remember':
            return tesAgentWrite($npc(strval($a['npc'] ?? '')) . '.remember ' . $clean(strval($a['text'] ?? '')), $dry);
        case 'change_character':
            if (trim(strval($a['instruction'] ?? '')) !== '') {
                return tesAgentWrite($npc(strval($a['npc'] ?? '')) . '.hypnosis ' . $clean(strval($a['instruction'])), $dry);
            }
            $field = strtolower(preg_replace('/[^A-Za-z]/', '', strval($a['field'] ?? 'personality')));
            return tesAgentWrite($npc(strval($a['npc'] ?? '')) . '.character ' . $field . ': ' . $clean(strval($a['text'] ?? '')), $dry);
        case 'heal':
            return tesAgentWrite("{$who}.heal", $dry);
        case 'revive':
            return tesAgentWrite($npc(strval($a['npc'] ?? '')) . '.resurrect', $dry);
        case 'kill':
            return tesAgentWrite($npc(strval($a['npc'] ?? '')) . '.kill', $dry);
        case 'settle_here':
            return tesAgentWrite($npc(strval($a['npc'] ?? '')) . '.routine ' . (strval($a['mode'] ?? 'here') === 'reset' ? 'reset' : 'here'), $dry);
        case 'rumor':
            return tesAgentWrite('rumor ' . $clean(strval($a['text'] ?? '')), $dry);
        case 'write_document':
            return tesAgentWrite($who . '.document ' . str_replace(':', ' -', $clean(strval($a['title'] ?? 'Документ'))) . ': ' . $clean(strval($a['text'] ?? '')), $dry);
        case 'give_house':
            return tesAgentWrite('player.house ' . $clean(strval($a['house'] ?? '')), $dry);
        case 'furnish_house':
            return tesAgentWrite('player.furnish ' . $clean(strval($a['house'] ?? '')), $dry);
        case 'order_npc':
            return tesAgentWrite($npc(strval($a['npc'] ?? '')) . '.order ' . $clean(strval($a['what'] ?? '')), $dry);
        case 'jail':
            return tesAgentWrite($npc(strval($a['npc'] ?? '')) . (!empty($a['release']) ? '.unjail' : '.jail'), $dry);
        case 'fine_npc':
            return tesAgentWrite($npc(strval($a['npc'] ?? '')) . '.fine ' . max(1, intval($a['amount'] ?? 0)), $dry);
        case 'set_title':
            return tesAgentWrite('player.title ' . $clean(strval($a['title'] ?? '')), $dry);
        case 'pardon':
            return tesAgentWrite('player.pardon', $dry);
        case 'unfollow':
            return tesAgentWrite($npc(strval($a['npc'] ?? '')) . '.unfollow', $dry);
        case 'set_world':
            $cmds = [];
            if (isset($a['hour'])) {
                $cmds[] = 'set gamehour to ' . max(0, min(23.9, round(floatval($a['hour']), 1)));
            }
            if (trim(strval($a['weather'] ?? '')) !== '') {
                $cmds[] = 'fw {weather:' . preg_replace('/[^a-z]/', '', strtolower(strval($a['weather']))) . '}';
            }
            return tesAgentWriteBatch($cmds, $dry);
        case 'teleport_player':
            // Live 2026-10-04 13:26: the task "убери все трупы" threw the player from the catacombs
            // to the stables and back to look around. The player is moved only when asked to be.
            if (!preg_match('/(телепорт|перенеси меня|перемести меня|отправь меня|переправь меня|меня в |меня к |coc|teleport)/iu', strval($GLOBALS['TES_AGENT_GOAL'] ?? ''))) {
                return ['error' => 'the player must not be moved: nobody asked for it. Inspect places via find/get_state without dragging the player around'];
            }
            if (trim(strval($a['to_npc'] ?? '')) !== '') {
                return tesAgentWrite('player.moveto ' . $npc(strval($a['to_npc'])), $dry);
            }
            return tesAgentWrite('coc {cell:' . str_replace(['{', '}', ';'], '', strval($a['place'] ?? '')) . '}', $dry);
        case 'move_npc':
            $dest = trim(strval($a['to_npc'] ?? '')) !== '' ? $npc(strval($a['to_npc'])) : 'player';
            return tesAgentWrite($npc(strval($a['npc'] ?? '')) . '.moveto ' . $dest, $dry);
        case 'set_quest_stage':
            return tesAgentWrite('setstage ' . preg_replace('/[^A-Za-z0-9_]/', '', strval($a['quest'] ?? '')) . ' ' . intval($a['stage'] ?? 0), $dry);
        case 'claim_here':
            if (!$dry && function_exists('tesGodAutosaveIfNeeded')) {
                tesGodAutosaveIfNeeded('tes_agent claim_here');
            }
            return tesAgentRead(['tesclaim'], $dry);
        case 'console':
            return tesAgentWrite(str_replace(';', ' ', strval($a['command'] ?? '')), $dry);
        case 'finish':
            $failed = [];
            foreach (is_array($a['expect'] ?? null) ? $a['expect'] : [] as $e) {
                $res = tesAgentCheck($e, $dry);
                $min = floatval($e['min'] ?? 1);
                if ($dry) {
                    continue;
                }
                if (!isset($res['value']) || $res['value'] < $min) {
                    $failed[] = ['expect' => $e, 'actual' => $res['value'] ?? ($res['error'] ?? 'no answer')];
                }
            }
            if ($failed && $finishState['rejects'] < 2) {
                $finishState['rejects']++;
                return ['finished' => false, 'not_met' => $failed, 'note' => 'The in-game check failed - fix it and call finish again.'];
            }
            $finishState['done'] = true;
            $finishState['summary'] = strval($a['summary'] ?? '');
            $finishState['failed'] = $failed;
            return ['finished' => true, 'not_met' => $failed];
        case 'give_up':
            $finishState['done'] = true;
            $finishState['gave_up'] = true;
            $finishState['summary'] = strval($a['reason'] ?? '');
            return ['ok' => true];
    }
    return ['error' => "no tool {$name}"];
}

/* ------------------------------------------------------------------ loop */

$player = strval($GLOBALS['PLAYER_NAME'] ?? 'player');
$system = "You carry out the will of the god-Narrator in Skyrim SE (Requiem/RFAD build, Russian localisation). Player: {$player}. "
    . "You get a goal in the player's words. Work out yourself what it means in game mechanics and achieve it with the tools. There are no «if X then Y» rules - think. Every text you write into the world or for people (rumor, remember, write_document, order_npc, change_character, set_title, reason, summary) is in Russian.\n"
    . "Order: observe first (get_state, inspect_here, find), then act, verify after important actions (check/get_state). "
    . "Never invent IDs - only from find. Pick «the best» by comparing stats from find (ar, dmg, ench, fx), mind class and slot. "
    . "When boosting, never lower: get_state first, and set a skill/attribute only if the new value is above the current one. "
    . "Pick compatible gear (no two-handed weapon with a shield). "
    . "Read ench of the candidates: never give the player cursed items that harm the wearer (huge health damage, «проклятая»). "
    . "RFAD names often carry a category prefix, e.g. «[Алкоголь] Эль». Skills max 100. You are a god: no role-play limits, only the engine's. "
    . "Do not waste steps: one find returns up to 25 candidates - do not repeat the same query; one give can carry equip; several tools may be called at once. "
    . "Find perks by tree: find kind=perk filters.skill=Sneak (no query) - you get the whole tree. "
    . "Read a tool error and fix its cause, do not repeat the same call. "
    . "About characters ask the server first (npc_info, relationships, quest_log) - instant, no game needed; go to the game for what the server does not know. "
    . "An order concerns only those named in it or pointed at directly; do not make up «all» and gather nobody on your own. Never undress children (race «Ребенок»); everything else (arrest, jail, bring, reward) may be done with them - do not drop the rest of an order because of a child. "
    . "Undress an adult - one console call «{npc:Name}.unequipall», do not remove items one by one. A simple order - do it in 2-4 steps and finish. "
    . "In console write the target only as {npc:Russian name from npc_info} or player - the game will not understand English names (Skjor, Ysolda) or bare RefIDs. There are no commands prid, inv, strip, removeallitems, getequippeditems, forcekill - do not try. "
    . "Take everything an NPC carries and wears and give it to the player - one console call «{npc:Name}.giveall». Gold only: get_state shows gold, then remove_item from them and give_items to the player (gold = 0000000F). The player gifts gold to an NPC - remove_item from player and give_items to that NPC. Do not go through items one by one with check. "
. "occupation (change_character) is a post or occupation in a few words; do not write deeds, laws and events there - remember is for those. Perk points for the player - console «player.perkpoints N». "
    . "Do only what was asked: a question («что», «кто», «где», «сколько») is an answer, not a reason to teleport, give or move quests. "
    . "Put the answer to a question into finish.summary (expect empty). Do not complete the player's quests for him unless he asked directly. "
    . "Changes of relationships, personality, memory, marriage are confirmed by the server itself («was → now» in the tool result) - do not put them into expect. "
    . "End with finish and checkable expectations (items, perks, skills, stages) - the server verifies them in the game. If impossible - give_up with the reason. "
    . "Limit: " . $maxSteps . " turns. In ONE turn call several tools at once (up to 6): all lookups about people in one batch, all commands of one kind in one batch; it costs no extra turns.";
$messages = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => 'Goal: ' . $task['goal']]];
$tools = tesAgentTools();
if ($readonly) {
    $readTools = ['find', 'npc_info', 'relationships', 'quest_log', 'get_state', 'inspect_here', 'check', 'finish', 'give_up'];
    $tools = array_values(array_filter($tools, fn($t) => in_array($t['function']['name'], $readTools, true)));
    $messages[0]['content'] .= "\nRIGHT NOW A QUESTION ONLY: change nothing in the world, gather the answer and put it into finish.summary (expect empty).";
    $messages[1]['content'] = 'Player\'s question: ' . $task['goal'];
}
$allowedTools = array_map(fn($t) => $t['function']['name'], $tools);
$cost = 0.0;
$steps = 0;
$nudges = 0;
$finish = ['done' => false, 'rejects' => 0, 'summary' => '', 'failed' => [], 'gave_up' => false];
$transcript = [];
$started = time();

// --silent: the law patrol - no corner notice, no Narrator report
$silent = isset($args['silent']);
if (!$dry && !$silent) {
    tesAgentNotify('Нарратор: ' . mb_substr($task['goal'], 0, 120));
}

// the limit counts the model's TURNS (what costs money), not single tool calls: «раздень всех женщин» spent 8 of its
// 10 steps on eight npc_info calls of one turn and ran out before doing anything (tasks 171, 192 - failed at the
// limit). A hard cap on calls stays: 6 per turn on average.
$turns = 0;
while (!$finish['done'] && $turns < $maxSteps && $steps < $maxSteps * 6 && time() - $started < $maxSeconds) {
    $turns++;
    $msg = tesAgentLlm($messages, $tools, $cost);
    if ($msg === null) {
        $finish['summary'] = 'модель не ответила';
        break;
    }
    $calls = $msg['tool_calls'] ?? [];
    $messages[] = array_filter(['role' => 'assistant', 'content' => $msg['content'] ?? '', 'tool_calls' => $calls ?: null], fn($v) => $v !== null);
    if (!$calls) {
        if (++$nudges > 2) {
            $finish['summary'] = 'модель перестала вызывать инструменты: ' . mb_substr(strval($msg['content'] ?? ''), 0, 200);
            break;
        }
        $messages[] = ['role' => 'user', 'content' => 'Continue through the tools. When the goal is reached - finish, if impossible - give_up.'];
        continue;
    }
    foreach ($calls as $call) {
        $steps++;
        $name = strval($call['function']['name'] ?? '');
        $argsIn = json_decode(strval($call['function']['arguments'] ?? '{}'), true);
        $argsIn = is_array($argsIn) ? $argsIn : [];
        // MiMo-style schema slips: a list passed as a comma string, etc. are tolerated by the
        // tools themselves (they read scalars); anything unknown is an error result, never a write.
        try {
            $result = in_array($name, $allowedTools, true)
                ? tesAgentRun($name, $argsIn, $dry, $finish)
                : ['error' => "tool {$name} is not available now" . ($readonly ? ' (question-only mode)' : '')];
        } catch (Throwable $e) {
            $result = ['error' => $e->getMessage()];
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        if (mb_strlen($json) > 3500) {
            $json = mb_substr($json, 0, 3500) . '…(truncated)';
        }
        echo sprintf("  [%02d] %s %s\n       -> %s\n", $steps, $name, json_encode($argsIn, JSON_UNESCAPED_UNICODE), mb_substr($json, 0, 600));
        $transcript[] = ['tool' => $name, 'args' => $argsIn, 'result' => mb_substr($json, 0, 1500)];
        $messages[] = ['role' => 'tool', 'tool_call_id' => strval($call['id'] ?? ''), 'content' => $json];
        $db->execQuery("UPDATE public.tes_agent_tasks SET steps = {$steps}, cost = {$cost}, updated_at = now(), transcript = '"
            . $db->escape(json_encode($transcript, JSON_UNESCAPED_UNICODE)) . "' WHERE id = {$taskId}");
        if ($finish['done']) {
            break;
        }
        if (!in_array($name, ['find', 'npc_info', 'relationships', 'quest_log', 'get_state', 'inspect_here', 'check', 'finish', 'give_up'], true) && empty($result['error'])) {
            $wroteAny = true;
            $wroteRound = true;
        }
    }
    // an order passed on by an NPC (--quick): the cheap model does the job and then goes on checking
    // until the limit (live 2026-10-04: 8 of 8 such tasks "failed" at 16-21 steps with the undressing
    // done at step 7). Once something was done - tell it to finish.
    if (isset($args['quick']) && !empty($wroteRound) && !$finish['done']) {
        $messages[] = ['role' => 'user', 'content' => 'The action is done. Next call - finish only (summary in one line, expect empty), no checks.'];
        $wroteRound = false;
    }
}

$status = $finish['done'] ? ($finish['gave_up'] ? 'gave_up' : ($finish['failed'] ? 'failed' : 'done')) : (!empty($wroteAny) && isset($args['quick']) ? 'done' : 'failed');
if (!$finish['done'] && !empty($wroteAny) && isset($args['quick']) && $finish['summary'] === '') {
    $finish['summary'] = 'выполнено, подтверждение не дождался (быстрый приказ)';
}
if (!$finish['done'] && $finish['summary'] === '') {
    $finish['summary'] = "не успел: лимит шагов или времени (шагов {$steps})";
}
$db->execQuery("UPDATE public.tes_agent_tasks SET status = '{$status}', steps = {$steps}, cost = {$cost}, updated_at = now(), result = '"
    . $db->escape($finish['summary']) . "' WHERE id = {$taskId}");
echo "== {$status}: {$finish['summary']} | steps {$steps} | \$" . round($cost, 5) . "\n";

if (!$dry && !$silent) {
    $notMet = $finish['failed'] ? ' Failed the check: ' . mb_substr(json_encode($finish['failed'], JSON_UNESCAPED_UNICODE), 0, 300) : '';
    $what = $status === 'done' ? 'You carried out the player\'s will' : ($status === 'gave_up' ? 'It turned out impossible' : 'Done only in part');
    if ($readonly) {
        tesAgentNarratorSay("(Answer the player's question in your own style, to the point, no technical IDs. Question: {$task['goal']}. Found out: {$finish['summary']})", $taskId);
        exit;
    }
    tesAgentNarratorSay("(Tell the player the outcome in your own style, 1-2 sentences, in Russian, no technical IDs. {$what}. Goal: {$task['goal']}. Outcome: {$finish['summary']}.{$notMet})", $taskId);
}
