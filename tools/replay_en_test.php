<?php
/*
 * Replay logged NPC requests with an English prompt head and see that the NPC still answers in Russian.
 *   php tools/replay_en_test.php <english_prompt_head.txt> [N=3]        (run as user dwemer; costs about $0.008 a sample)
 * Takes the last N requests of different NPCs from HerikaServer/log/context_sent_to_llm.log, swaps the
 * <roleplay_instructions> block for the English text (#HERIKA_NAME# filled in), and for each sample:
 *   - counts prompt tokens of the Russian and of the English request (max_tokens 1, messages only);
 *   - sends the English request with the logged parameters and prints the reply and its Cyrillic share.
 * Reads the OpenRouter key from core_api_badge id=1 and never prints it. Writes nothing anywhere.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$headFile = $argv[1] ?? '';
$n = max(1, intval($argv[2] ?? 3));
if (!is_file($headFile)) {
    fwrite(STDERR, "usage: php replay_en_test.php <english_prompt_head.txt> [N]\n");
    exit(2);
}
$head = trim(file_get_contents($headFile));
$key = trim(strval(shell_exec("psql -d dwemer -Atc \"select api_key from core_api_badge where id=1\"")));
if ($key === '') {
    fwrite(STDERR, "no key\n");
    exit(2);
}
$raw = file_get_contents('/var/www/html/HerikaServer/log/context_sent_to_llm.log');
$entries = preg_split('/\n(?=\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d\n=\n)/', $raw);
$samples = [];
foreach (array_reverse($entries) as $e) {
    // the request is a var_export: from "array (" at the start of a line to the first ")" alone on a line
    if (!preg_match('/^array \(\n.*?\n\)$/ms', $e, $am)) {
        continue;
    }
    try {
        $req = eval('return ' . $am[0] . ';');
    } catch (Throwable $t) {
        continue;
    }
    if (!is_array($req) || empty($req['messages'][0]['content']) || !is_string($req['messages'][0]['content'])) {
        continue;
    }
    $sys = $req['messages'][0]['content'];
    if (!preg_match('~<roleplay_instructions>\s*Ты — (.+?), живой житель~us', $sys, $m)) {
        continue;
    }
    if (isset($samples[$m[1]])) {
        continue;
    }
    $samples[$m[1]] = $req;
    if (count($samples) >= $n) {
        break;
    }
}
if (!$samples) {
    echo "no NPC request with a Russian prompt head in the log\n";
    exit(1);
}

function tesReplayCall(array $payload, string $key): array
{
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    $out = curl_exec($ch);
    curl_close($ch);
    return json_decode(strval($out), true) ?: ['error' => substr(strval($out), 0, 300)];
}
function tesReplayCyr(string $t): array
{
    return [preg_match_all('/[А-Яа-яЁё]/u', $t), preg_match_all('/[A-Za-z]/', $t)];
}

$cost = 0.0;
$bad = 0;
foreach ($samples as $name => $req) {
    $ru = $req;
    $en = $req;
    $en['messages'][0]['content'] = preg_replace('~<roleplay_instructions>.*?</roleplay_instructions>~us',
        "<roleplay_instructions>\n" . str_replace(['\\', '$'], ['\\\\', '\\$'], str_replace('#HERIKA_NAME#', $name, $head)) . "\n</roleplay_instructions>", $en['messages'][0]['content'], 1);
    $tok = [];
    foreach (['ru' => $ru, 'en' => $en] as $k => $r) {
        $d = tesReplayCall(['model' => $r['model'], 'messages' => $r['messages'], 'max_tokens' => 1, 'reasoning' => ['enabled' => false]], $key);
        $tok[$k] = intval($d['usage']['prompt_tokens'] ?? 0);
        $cost += floatval($d['usage']['cost'] ?? 0);
    }
    $full = $en;
    $full['stream'] = false;
    unset($full['stream_options']);
    $d = tesReplayCall($full, $key);
    $cost += floatval($d['usage']['cost'] ?? 0);
    $text = strval($d['choices'][0]['message']['content'] ?? '');
    $spoken = $text;
    $j = json_decode($text, true);
    if (is_array($j)) {
        $spoken = implode(' ', array_filter(array_map(fn($v) => is_string($v) ? $v : '', [$j['message'] ?? '', $j['response'] ?? '', $j['speech'] ?? ''])));
        if ($spoken === '') {
            $spoken = $text;
        }
    }
    [$c, $l] = tesReplayCyr($spoken);
    $ok = $c > 0 && $c >= 4 * $l;
    $bad += $ok ? 0 : 1;
    echo str_repeat('=', 90), "\n", $name, "\n";
    echo "prompt tokens: russian head {$tok['ru']}, english head {$tok['en']}, saved ", $tok['ru'] - $tok['en'], ' (', $tok['ru'] ? round(100 * ($tok['ru'] - $tok['en']) / $tok['ru'], 1) : 0, "%)\n";
    echo 'reply (', $ok ? 'RUSSIAN' : 'NOT RUSSIAN', ", cyrillic {$c}, latin {$l}): ", mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 600), "\n";
    if (isset($d['error'])) {
        echo 'error: ', is_string($d['error']) ? $d['error'] : json_encode($d['error'], JSON_UNESCAPED_UNICODE), "\n";
    }
}
echo str_repeat('=', 90), "\n", count($samples), ' samples, ', $bad, ' not Russian, cost $', number_format($cost, 4), "\n";
exit($bad ? 1 : 0);
