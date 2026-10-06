<?php
/*
 * tes_world: the director's panel (roadmap A, "пульт режиссёра") - one page with what the world is doing:
 * the Narrator-agent's tasks, court and treasury, posts, companions, rumours by hold, legends, letters, witnesses,
 * prices, and an "undo" button (the same as «верни как было»).
 * Open: http://localhost:8081/HerikaServer/ext/tes_world/panel.php  (local network only)
 */

$ip = strval($_SERVER['REMOTE_ADDR'] ?? '');
if (!preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|::1$)/', $ip)) {
    http_response_code(403);
    exit('local network only');
}
chdir('/var/www/html/HerikaServer');
require_once 'conf/conf.php';
require_once 'lib/' . ($GLOBALS['DBDRIVER'] ?? 'postgresql') . '.class.php';
require_once 'lib/data_functions.php';
$GLOBALS['db'] = new sql();
$db = $GLOBALS['db'];
$pn = $db->fetchOne("SELECT value FROM conf_opts WHERE id = 'PLAYER_NAME'");
$GLOBALS['PLAYER_NAME'] = trim(strval($pn['value'] ?? 'Шаман'), '"') ?: 'Шаман';
require __DIR__ . '/lib.php';

$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'undo') {
    $flash = trim(strval(tesRealmUndo()), " *");
}

$h = fn($s) => htmlspecialchars(strval($s), ENT_QUOTES, 'UTF-8');
$has = fn(string $t) => !empty($db->fetchOne("SELECT to_regclass('public.{$t}') AS t")['t']);
$rows = function (string $sql) use ($db): array {
    try {
        $r = $db->fetchAll($sql);
        return is_array($r) ? $r : [];
    } catch (Throwable $e) {
        return [];
    }
};
function tesPanelTable(array $rows, array $cols, callable $h): string
{
    if (!$rows) {
        return '<p class="muted">пусто</p>';
    }
    $out = '<table><tr>' . implode('', array_map(fn($c) => '<th>' . $h($c) . '</th>', array_values($cols))) . '</tr>';
    foreach ($rows as $r) {
        $out .= '<tr>' . implode('', array_map(fn($k) => '<td>' . $h($r[$k] ?? '') . '</td>', array_keys($cols))) . '</tr>';
    }
    return $out . '</table>';
}

tesTreasuryEnsure();
$nick = strval(tesWatchGet('player_nick')['value']);
$sections = [];
$sections['Нарратор-агент: последние задачи'] = tesPanelTable($rows("SELECT to_char(created_at, 'HH24:MI') AS t, status, steps, round(cost::numeric, 4) AS cost, left(goal, 90) AS goal FROM public.tes_agent_tasks ORDER BY id DESC LIMIT 12"),
    ['t' => 'время', 'status' => 'статус', 'steps' => 'шаги', 'cost' => '$', 'goal' => 'задача'], $h);
$sections['Суд'] = tesPanelTable($rows("SELECT to_char(opened_at, 'MM-DD HH24:MI') AS t, defendant, left(charge, 60) AS charge, CASE WHEN closed THEN verdict ELSE 'идёт' END AS v FROM public.tes_court ORDER BY id DESC LIMIT 8"),
    ['t' => 'когда', 'defendant' => 'подсудимый', 'charge' => 'обвинение', 'v' => 'приговор'], $h);
$sections['Казна: ' . tesTreasuryBalance() . ' септимов'] = tesPanelTable($rows("SELECT to_char(created_at, 'MM-DD HH24:MI') AS t, delta, why FROM public.tes_treasury_log ORDER BY id DESC LIMIT 10"),
    ['t' => 'когда', 'delta' => '±', 'why' => 'за что'], $h);
$sections['Двор'] = tesPanelTable($rows("SELECT role, npc, to_char(since, 'MM-DD HH24:MI') AS t FROM public.tes_posts ORDER BY since"), ['role' => 'должность', 'npc' => 'кто', 't' => 'с'], $h);
if ($has('tes_companion')) {
    $sections['Спутники'] = tesPanelTable($rows("SELECT npc, trust, CASE WHEN left_at IS NULL THEN '' ELSE 'ушёл' END AS gone, left(grudges, 80) AS grudges, left(goal, 60) AS goal FROM public.tes_companion ORDER BY updated_at DESC LIMIT 10"),
        ['npc' => 'спутник', 'trust' => 'доверие', 'gone' => '', 'grudges' => 'обиды', 'goal' => 'цель'], $h);
}
$sections['Слухи по холдам'] = tesPanelTable($rows("SELECT hold, type, left(content, 140) AS content FROM public.rumors ORDER BY id DESC LIMIT 16"), ['hold' => 'холд', 'type' => 'откуда', 'content' => 'слух'], $h);
if ($has('tes_legends')) {
    $sections['Книга легенд'] = tesPanelTable($rows("SELECT to_char(created_at, 'MM-DD HH24:MI') AS t, god, deed FROM public.tes_legends ORDER BY id DESC LIMIT 10"), ['t' => 'когда', 'god' => 'бог', 'deed' => 'деяние'], $h);
}
if ($has('tes_witness_seen')) {
    $sections['Свидетели видели'] = tesPanelTable($rows("SELECT to_char(created_at, 'MM-DD HH24:MI') AS t, deed FROM public.tes_witness_seen ORDER BY created_at DESC LIMIT 8"), ['t' => 'когда', 'deed' => 'что'], $h);
}
if ($has('tes_world_letters')) {
    $sections['Письма и курьеры'] = tesPanelTable($rows("SELECT to_char(due_at, 'MM-DD HH24:MI') AS t, sender, title, CASE WHEN sent THEN 'доставлено' ELSE 'в пути' END AS s FROM public.tes_world_letters ORDER BY id DESC LIMIT 8"),
        ['t' => 'когда', 'sender' => 'от кого', 'title' => 'письмо', 's' => ''], $h);
}
if ($has('tes_prices')) {
    $sections['Цены'] = tesPanelTable($rows("SELECT name, gold, base, why FROM public.tes_prices ORDER BY updated_at DESC"), ['name' => 'товар', 'gold' => 'цена', 'base' => 'была', 'why' => 'почему'], $h);
}
$gods = [];
foreach (['sanguine' => 'Сангвин'] + array_map(fn($g) => $g['name'], function_exists('tesGods') ? tesGods() : []) as $k => $name) {
    $v = $k === 'sanguine' ? tesWatchGet('sanguine_favor')['value'] : tesWatchGet('god_favor_' . $k)['value'];
    $gods[] = ['god' => $name, 'favor' => $v === '' ? ($k === 'sanguine' ? 40 : 50) : intval($v)];
}
$sections['Милость богов'] = tesPanelTable($gods, ['god' => 'бог', 'favor' => 'милость 0–100'], $h);
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="20"><title>Пульт режиссёра</title>
<style>
:root{--bg:#f6f4ef;--fg:#222;--muted:#777;--card:#fff;--line:#e2ded5;--acc:#8a5a14}
@media (prefers-color-scheme:dark){:root{--bg:#16161a;--fg:#e8e6e1;--muted:#8d8a84;--card:#1f1f25;--line:#33333b;--acc:#e0b060}}
body{margin:0;padding:16px;background:var(--bg);color:var(--fg);font:14px/1.45 system-ui,sans-serif}
h1{margin:0 0 4px;font-size:20px} .sub{color:var(--muted);margin-bottom:14px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(420px,1fr));gap:14px}
section{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px;overflow-x:auto}
h2{font-size:15px;margin:0 0 8px;color:var(--acc)} table{border-collapse:collapse;width:100%}
th,td{text-align:left;padding:4px 6px;border-bottom:1px solid var(--line);vertical-align:top} th{color:var(--muted);font-weight:500}
.muted{color:var(--muted)} .flash{background:var(--card);border:1px solid var(--acc);padding:8px 12px;border-radius:8px;margin-bottom:12px}
button{background:var(--acc);color:var(--bg);border:0;border-radius:8px;padding:8px 14px;font-weight:600;cursor:pointer}
@media (max-width:480px){.grid{grid-template-columns:1fr}}
</style></head><body>
<h1>Пульт режиссёра</h1>
<div class="sub"><?= $h($GLOBALS['PLAYER_NAME']) ?><?= $nick !== '' ? ' ' . $h($nick) : '' ?> · обновляется каждые 20 с · <?= date('H:i:s') ?></div>
<?php if ($flash !== ''): ?><div class="flash"><?= $h($flash) ?></div><?php endif; ?>
<form method="post" onsubmit="return confirm('Откатить последний приказ, приговор или задачу Нарратора?')"><input type="hidden" name="action" value="undo"><button>Откатить последнее («верни как было»)</button></form>
<p></p>
<div class="grid">
<?php foreach ($sections as $title => $html): ?><section><h2><?= $h($title) ?></h2><?= $html ?></section>
<?php endforeach; ?>
</div></body></html>
