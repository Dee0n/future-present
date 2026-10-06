<?php
/*
 * Read-only checks of the ruler's court, treasury, posts and the child guard (ext/tes_world).
 * Usage (inside the DwemerAI4Skyrim3 WSL distro):
 *   runuser -u www-data -- php tools/test_court.php
 * Nothing is written to the database and nothing is sent to the game.
 */

chdir('/var/www/html/HerikaServer');
require_once 'conf/conf.php';
require_once 'lib/' . ($GLOBALS['DBDRIVER'] ?? 'postgresql') . '.class.php';
$GLOBALS['db'] = new sql();
$GLOBALS['PLAYER_NAME'] = 'Шаман';
$X = dirname(__DIR__) . '/ext';
require "$X/tes_world/childsafe.php";
require "$X/tes_world/lib.php";
require "$X/tes_world/court.php";
require "$X/tes_world/realm.php";

$fail = 0;
$check = function (bool $ok, string $what) use (&$fail) {
    $fail += $ok ? 0 : 1;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
};

echo "== childsafe: nothing violent or undressing reaches a child ==\n";
// 0001A679 Фротар (child), 0001A677 Балгруф (adult), 000D0FF5 Джон (guard)
$out = tesChildSafeCommands(['prid 0001A679', 'teskill', 'kill', 'unequipall', 'prid 000D0FF5', 'tesduel ' . hexdec('0001A679'),
    'startcombat 0001A679', 'tesduel ' . hexdec('0001A677'), 'prid 0001A677', 'removefromallfactions', 'kill']);
$check($out === ['prid 0001A679', 'prid 000D0FF5', 'tesduel ' . hexdec('0001A677'), 'prid 0001A677', 'removefromallfactions', 'kill'],
    'child: kill/teskill/unequip/duel/startcombat dropped, adult kept -- ' . json_encode($out, JSON_UNESCAPED_UNICODE));

echo "== court: the ruler's sentence ==\n";
foreach ([
    'Вы приговариваетесь к казни.' => 'kill',
    'Приговариваю тебя к смертной казни через обезглавливание' => 'kill',
    'Казнить его.' => 'kill',
    'За это полагается казнь?' => null,
    'Я не казню тебя, живи.' => null,
    'Приговариваю к тюрьме на 10 дней' => 'jail:10',
    'Без казни обойдёмся, но в тюрьму на 3 дня' => 'jail:3',
    'Пять лет темницы тебе' => 'jail',
    'В тюрьму его на месяц' => 'jail',
    'Штраф пятьсот септимов в казну' => 'fine:500',
    'Оштрафовать на 1000' => 'fine:1000',
    'Ты оправдан, свободен.' => 'free',
    'Невиновен. Иди.' => 'free',
    'Помилован' => 'free',
    'Расскажи, что ты делал вчера' => null,
] as $line => $want) {
    $v = tesCourtVerdict($line);
    $got = $v === null ? null : $v['kind'];
    if ($v !== null && is_string($want) && strpos($want, ':') !== false) {
        $got .= ':' . ($v['days'] ?? $v['amount'] ?? '');
    }
    $check($got === $want, sprintf('%-55s => %s', $line, var_export($got, true)));
}

echo "== treasury: which words are about the treasury (patterns as in court.php) ==\n";
$src = file_get_contents("$X/tes_world/court.php");
preg_match_all("/if \(!\\\$sentenceNow && preg_match\('(.+?)', \\\$t\)\)/u", $src, $pm);
$check(count($pm[1]) === 4, 'four treasury patterns found (report, balance, payout, deposit)');
$names = ['report', 'balance', 'payout', 'deposit'];
$intent = function (string $line) use ($pm, $names): string {
    $t = mb_strtolower(str_replace('ё', 'е', $line));
    foreach ($pm[1] as $i => $re) {
        if (preg_match($re, $t)) {
            return $names[$i] ?? "#{$i}";
        }
    }
    return '';
};
foreach ([
    'Приговариваю к казни, а семья заплатит' => '',
    'Как казнить его?' => '',
    'Назначаю тебя казначеем' => '',
    'Выдай Бренуину 300 из казны' => 'payout',
    'Сколько в казне?' => 'balance',
    'Положи в казну тысячу' => 'deposit',
    'Отчёт по казне' => 'report',
    'Куда ушли деньги?' => 'report',
] as $line => $want) {
    $got = $intent($line);
    $check($got === $want, sprintf("%-45s => '%s'", $line, $got));
}

echo "== treasury: who is paid ==\n";
foreach ([
    ['Выдай Бренуину 300 из казны', 'Ярл Балгруф Старший', 'Бренуин'],
    ['Выдай мне из казны 500', 'Провентус Авениччи', ''],
    ['Из казны дай 200', 'Провентус Авениччи', ''],
    ['Возьми себе из казны 100 септимов', 'Провентус Авениччи', 'Провентус Авениччи'],
    ['Заплати Провентусу из казны тысячу', 'The Narrator', 'Провентус Авениччи'],
] as [$line, $to, $want]) {
    $got = tesTreasuryPayee($line, $to);
    $check($got === $want, sprintf("%-40s (to %s) => '%s'", $line, $to, $got));
}
echo '  info ' . tesTreasuryReport() . "\n";

echo "== court posts ==\n";
foreach (['снимаю торгара с должности палача' => 'палач', 'фианна больше не казначей' => 'казначей', 'кто при дворе' => ''] as $t => $want) {
    $check(tesRealmRoleOf($t) === $want, "role of «{$t}» => '" . tesRealmRoleOf($t) . "'");
}
echo '  info ' . tesRealmCourtList() . "\n";

echo $fail ? "\n{$fail} FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
