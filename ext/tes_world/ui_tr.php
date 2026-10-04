<?php
/*
 * Russian for third-party web pages of the server (first of all SHARMAT's settings page, owner
 * 2026-10-04: "все настройки шармата… на русский переведи"). ui_ru.js on the page sends the
 * English texts it shows; this answers with Russian ones - from the table tes_ui_tr, and what
 * is not there yet is translated once (google/gemini-2.5-flash, the key of connector 8) and kept.
 * Only the interface: the page's text, never the values in its fields (prompts stay as they are).
 * Local requests only.
 */
header('Content-Type: application/json; charset=utf-8');
$ip = strval($_SERVER['REMOTE_ADDR'] ?? '');
if (!preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|::1$)/', $ip)) {
    http_response_code(403);
    echo '{}';
    exit;
}
$in = json_decode(strval(file_get_contents('php://input')), true);
$strings = array_values(array_unique(array_filter(array_map('strval', (array)($in['strings'] ?? [])), fn($s) => trim($s) !== '' && mb_strlen($s) <= 2000)));
$strings = array_slice($strings, 0, 400);
if (!$strings) {
    echo '{}';
    exit;
}
$enginePath = '/var/www/html/HerikaServer/';
$GLOBALS['ENGINE_PATH'] = $enginePath;
chdir($enginePath);
require_once $enginePath . 'lib/runtime_bootstrap.php';
chimRuntimeBootstrap($enginePath, ['load_general_settings' => false, 'load_player_name' => false, 'load_narrator' => false]);
$db = $GLOBALS['db'];
$db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_ui_tr (src text PRIMARY KEY, ru text NOT NULL, created_at timestamptz NOT NULL DEFAULT now())");

$out = [];
$missing = [];
foreach (array_chunk($strings, 200) as $chunk) {
    $list = implode(',', array_map(fn($s) => "'" . $db->escape($s) . "'", $chunk));
    foreach ($db->fetchAll("SELECT src, ru FROM public.tes_ui_tr WHERE src IN ({$list})") ?: [] as $r) {
        $out[$r['src']] = $r['ru'];
    }
}
foreach ($strings as $s) {
    if (!isset($out[$s])) {
        $missing[] = $s;
    }
}

function tesUiKey(): string
{
    $row = $GLOBALS['db']->fetchOne("SELECT b.* FROM core_api_badge b JOIN core_llm_connector c ON c.api_badge_id = b.id WHERE c.id = 8");
    foreach (is_array($row) ? $row : [] as $v) {
        if (is_string($v) && str_starts_with($v, 'sk-or-')) {
            return $v;
        }
    }
    return '';
}

$key = $missing ? tesUiKey() : '';
foreach (array_chunk($missing, 60) as $chunk) {
    if ($key === '') {
        break;
    }
    $items = [];
    foreach ($chunk as $i => $s) {
        $items['s' . $i] = $s;
    }
    $body = [
        'model' => 'google/gemini-2.5-flash',
        'messages' => [
            ['role' => 'system', 'content' => 'Ты переводишь интерфейс веб-страницы настроек мода Skyrim (SHARMAT — NSFW-надстройка для ИИ-персонажей CHIM) с английского на русский. Верни ТОЛЬКО JSON-объект с теми же ключами. Коротко и естественно, как в интерфейсе программ. Не переводи: названия модов и программ (SHARMAT, CHIM, OStim, SexLab, OBody, MO2, Skyrim), плейсхолдеры в фигурных скобках, #ИМЕНА#, переменные, HTML, URL, числа и единицы. Сохраняй пробелы по краям и знаки препинания.'],
            ['role' => 'user', 'content' => json_encode($items, JSON_UNESCAPED_UNICODE)],
        ],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.1,
        'reasoning' => ['effort' => 'low'],
    ];
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$key}"],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $resp = json_decode(strval(curl_exec($ch)), true);
    curl_close($ch);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim(strval($resp['choices'][0]['message']['content'] ?? ''))) ?? '';
    $ru = json_decode($text, true);
    if (!is_array($ru)) {
        continue;
    }
    foreach ($chunk as $i => $s) {
        $t = $ru['s' . $i] ?? null;
        if (is_string($t) && trim($t) !== '') {
            $out[$s] = $t;
            $db->execQuery("INSERT INTO public.tes_ui_tr (src, ru) VALUES ('" . $db->escape($s) . "', '" . $db->escape($t) . "') ON CONFLICT (src) DO NOTHING");
        }
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
