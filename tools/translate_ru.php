<?php
/*
 * Russian for the CHIM databases the owner sees in the web UI (2026-10-04: "переведи всю Огму
 * Инфиниум на русский", descriptions, actions, prompts). Run as www-data:
 *   php tools/translate_ru.php prompts|actions|descriptions|oghma|all [--limit N]
 * Everything is reversible:
 *   prompts      -> prompts.custom_prompt (Prompts Manager "Clear" returns the default)
 *   actions      -> core_action.description / return_message; English kept in tes_backup_core_action_en
 *   descriptions -> descriptions_custom (Russian game name from tes_game_index where known);
 *                   "delete all custom" in Description Manager returns the English set
 *   oghma        -> oghma.topic_desc / topic_desc_basic; English kept in tes_backup_oghma_en
 * Action NAMES stay as they are: the model calls actions by those names.
 * Model: google/gemini-2.5-flash through OpenRouter, the key of connector 8 (never printed).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$enginePath = '/var/www/html/HerikaServer/';
$GLOBALS['ENGINE_PATH'] = $enginePath;
chdir($enginePath);
require_once $enginePath . 'lib/runtime_bootstrap.php';
chimRuntimeBootstrap($enginePath, ['load_general_settings' => true, 'load_player_name' => true, 'load_narrator' => false]);
$db = $GLOBALS['db'];

$mode = $argv[1] ?? 'all';
$limit = 0;
foreach ($argv as $i => $a) {
    if ($a === '--limit') {
        $limit = intval($argv[$i + 1] ?? 0);
    }
}

function trKey(): string
{
    $row = $GLOBALS['db']->fetchOne("SELECT b.* FROM core_api_badge b JOIN core_llm_connector c ON c.api_badge_id = b.id WHERE c.id = 8");
    foreach (is_array($row) ? $row : [] as $v) {
        if (is_string($v) && str_starts_with($v, 'sk-or-')) {
            return $v;
        }
    }
    return '';
}

/** $items: id => English text. Returns id => Russian text (missing ids = failed). */
function trBatch(array $items, string $what): array
{
    static $key = null;
    $key = $key ?? trKey();
    if ($key === '' || !$items) {
        return [];
    }
    $system = "Ты переводчик для мода Skyrim (CHIM). Переведи значения JSON-объекта с английского на русский. "
        . "Контекст: {$what}. Правила: 1) Верни ТОЛЬКО JSON-объект с теми же ключами. "
        . "2) Имена, места, предметы, фракции — как в официальной русской локализации Skyrim (Вайтран, Виндхельм, Довакин, септимы, Братья Бури, Талмор, Седобородые, двемеры, Соратники). "
        . "3) Не трогай и не переводи: плейсхолдеры в фигурных скобках ({HERIKA_NAME}, {PLAYER_NAME}, {{config.cost_gold}} и т.п.), #HERIKA_NAME#, #PLAYER_NAME#, %s, имена действий в стиле Give_Gold_To / Take_Gold_From_#PLAYER_NAME# / TravelTo, ключи JSON и значения-перечисления внутри примеров JSON, теги в угловых скобках (<inventory>), английские названия полей ('target', 'item', 'amount', 'listener'), консольные команды. "
        . "4) Сохрани переносы строк, маркдаун, нумерацию и смысл полностью, ничего не сокращай и не добавляй. 5) Стиль — естественный русский.";
    $body = [
        'model' => 'google/gemini-2.5-flash',
        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => json_encode($items, JSON_UNESCAPED_UNICODE)]],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.2,
        'reasoning' => ['effort' => 'low'],
    ];
    for ($try = 0; $try < 3; $try++) {
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 240,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$key}"],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $resp = json_decode(strval($raw), true);
        $text = strval($resp['choices'][0]['message']['content'] ?? '');
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim($text)) ?? $text;
        $out = json_decode($text, true);
        if (is_array($out)) {
            $ok = [];
            foreach ($items as $id => $en) {
                $ru = $out[$id] ?? ($out[strval($id)] ?? null);
                if (is_string($ru) && trim($ru) !== '' && trPlaceholdersKept($en, $ru)) {
                    $ok[$id] = $ru;
                }
            }
            return $ok;
        }
        echo "  ! try {$try}: " . mb_substr(strval($raw), 0, 200) . "\n";
        sleep(3);
    }
    return [];
}

/** Every {placeholder}, #PLACEHOLDER# and %s of the English text is still in the Russian one. */
function trPlaceholdersKept(string $en, string $ru): bool
{
    preg_match_all('/\{\{?[A-Za-z_.]+\}?\}|#[A-Z_]+#|%s/u', $en, $m);
    foreach (array_unique($m[0]) as $p) {
        if (mb_strpos($ru, $p) === false) {
            return false;
        }
    }
    return true;
}

/** Already Russian: most of the letters are Cyrillic (an English text with a Russian example is not). */
function trCyr(string $s): bool
{
    $cyr = preg_match_all('/[А-Яа-яЁё]/u', $s);
    $lat = preg_match_all('/[A-Za-z]/u', preg_replace('/#[A-Z_]+#|\{[^}]*\}|[A-Za-z]+_[A-Za-z_#]+/u', '', $s) ?? $s);
    return $cyr > 0 && $cyr >= $lat;
}

function trRun(array $rows, int $size, string $what, callable $text, callable $save): void
{
    $done = 0;
    $failed = 0;
    foreach (array_chunk($rows, $size, true) as $chunk) {
        $items = [];
        foreach ($chunk as $id => $r) {
            $items[$id] = $text($r);
        }
        $ru = trBatch($items, $what);
        foreach ($chunk as $id => $r) {
            if (isset($ru[$id])) {
                $save($r, $ru[$id]);
                $done++;
            } else {
                $failed++;
            }
        }
        echo "  {$what}: {$done} done, {$failed} failed of " . count($rows) . "\n";
    }
}

if (in_array($mode, ['prompts', 'all'], true)) {
    // height_descriptions is a JSON config read by code, not text for a model
    $rows = $db->fetchAll("SELECT prompt_key, default_prompt FROM prompts WHERE coalesce(custom_prompt,'') = '' AND prompt_key <> 'height_descriptions' ORDER BY prompt_key" . ($limit ? " LIMIT {$limit}" : ''));
    $byId = [];
    foreach ($rows as $i => $r) {
        $byId['p' . $i] = $r;
    }
    trRun($byId, 4, 'системные промпты для языковой модели (инструкции персонажам и сервисам); результат должен оставаться рабочей инструкцией', fn($r) => strval($r['default_prompt']),
        function ($r, $ru) use ($db) {
            $db->execQuery("UPDATE prompts SET custom_prompt = '" . $db->escape($ru) . "', updated_at = now() WHERE prompt_key = '" . $db->escape($r['prompt_key']) . "'");
        });
}

if (in_array($mode, ['actions', 'all'], true)) {
    $db->execQuery("CREATE TABLE IF NOT EXISTS tes_backup_core_action_en AS SELECT code_name, action_name, description, return_message, now() AS saved_at FROM core_action");
    $rows = $db->fetchAll("SELECT code_name, description, return_message FROM core_action WHERE description <> '' ORDER BY code_name" . ($limit ? " LIMIT {$limit}" : ''));
    $byId = [];
    foreach ($rows as $r) {
        if (!trCyr(strval($r['description']))) {
            $byId['d_' . $r['code_name']] = $r + ['field' => 'description'];
        }
        if (trim(strval($r['return_message'])) !== '' && !trCyr(strval($r['return_message']))) {
            $byId['r_' . $r['code_name']] = $r + ['field' => 'return_message'];
        }
    }
    trRun($byId, 6, 'описания действий, которые видит языковая модель NPC (что делает действие и как его вызывать)', fn($r) => strval($r[$r['field']]),
        function ($r, $ru) use ($db) {
            $db->execQuery("UPDATE core_action SET {$r['field']} = '" . $db->escape($ru) . "', updated_at = now() WHERE code_name = '" . $db->escape($r['code_name']) . "'");
        });
}

if (in_array($mode, ['descriptions', 'all'], true)) {
    // load order of the masters, for the full FormID in tes_game_index
    $masters = ['Skyrim.esm' => '00', 'Update.esm' => '01', 'Dawnguard.esm' => '02', 'HearthFires.esm' => '03', 'Dragonborn.esm' => '04'];
    $rows = $db->fetchAll("SELECT d.plugin, d.baseid, d.name, d.description FROM descriptions d LEFT JOIN descriptions_custom c ON c.plugin = d.plugin AND c.baseid = d.baseid WHERE c.baseid IS NULL ORDER BY d.plugin, d.baseid" . ($limit ? " LIMIT {$limit}" : ''));
    $byId = [];
    foreach ($rows as $i => $r) {
        $game = '';
        if (isset($masters[$r['plugin']]) && preg_match('/^[0-9A-Fa-f]{8}$/', $r['baseid'])) {
            $full = $masters[$r['plugin']] . strtoupper(substr($r['baseid'], 2));
            $g = $db->fetchOne("SELECT name FROM tes_game_index WHERE formid = '{$full}' AND name <> '' LIMIT 1");
            $game = strval($g['name'] ?? '');
        }
        $byId['i' . $i] = $r + ['game_name' => $game];
    }
    trRun($byId, 25, 'описания предметов, заклинаний и фракций Skyrim: формат «Название ||| описание», верни так же «Название ||| описание»; название — официальное русское из локализации', fn($r) => $r['name'] . ' ||| ' . $r['description'],
        function ($r, $ru) use ($db) {
            [$name, $desc] = array_map('trim', array_pad(explode('|||', $ru, 2), 2, ''));
            if ($desc === '') {
                $desc = $name;
                $name = '';
            }
            $name = $r['game_name'] !== '' ? $r['game_name'] : ($name !== '' ? $name : $r['name']);
            $db->execQuery("INSERT INTO descriptions_custom (plugin, baseid, name, description) VALUES ('" . $db->escape($r['plugin']) . "', '" . $db->escape($r['baseid']) . "', '"
                . $db->escape($name) . "', '" . $db->escape($desc) . "') ON CONFLICT (plugin, baseid) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description");
        });
}

if (in_array($mode, ['oghma', 'all'], true)) {
    $db->execQuery("CREATE TABLE IF NOT EXISTS tes_backup_oghma_en AS SELECT topic, topic_desc, topic_desc_basic, now() AS saved_at FROM oghma");
    $db->execQuery("INSERT INTO tes_backup_oghma_en (topic, topic_desc, topic_desc_basic, saved_at) SELECT o.topic, o.topic_desc, o.topic_desc_basic, now() FROM oghma o LEFT JOIN tes_backup_oghma_en b ON b.topic = o.topic WHERE b.topic IS NULL");
    $rows = $db->fetchAll("SELECT topic, topic_desc, coalesce(topic_desc_basic,'') AS topic_desc_basic FROM oghma ORDER BY topic" . ($limit ? " LIMIT {$limit}" : ''));
    $byId = [];
    foreach ($rows as $r) {
        if (!trCyr(strval($r['topic_desc'])) && trim(strval($r['topic_desc'])) !== '') {
            $byId['a_' . $r['topic']] = $r + ['field' => 'topic_desc'];
        }
        if (trim($r['topic_desc_basic']) !== '' && !trCyr($r['topic_desc_basic'])) {
            $byId['b_' . $r['topic']] = $r + ['field' => 'topic_desc_basic'];
        }
    }
    trRun($byId, 8, 'статьи энциклопедии мира Skyrim (Огма Инфиниум): что знает персонаж о месте, человеке, книге, событии', fn($r) => strval($r[$r['field']]),
        function ($r, $ru) use ($db) {
            $db->execQuery("UPDATE oghma SET {$r['field']} = '" . $db->escape($ru) . "', updated_at = now() WHERE topic = '" . $db->escape($r['topic']) . "'");
        });
    // the full-text index is built from these texts (lib/oghma_aliases.php)
    if (is_file($GLOBALS['ENGINE_PATH'] . 'lib/oghma_aliases.php')) {
        require_once $GLOBALS['ENGINE_PATH'] . 'lib/oghma_aliases.php';
        if (function_exists('chimOghmaNativeVectorSql')) {
            $db->execQuery('UPDATE public.oghma SET native_vector = ' . chimOghmaNativeVectorSql());
            echo "  oghma: full-text index rebuilt\n";
        }
    }
}
echo "finished {$mode}\n";
