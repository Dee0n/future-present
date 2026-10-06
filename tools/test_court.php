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
    'Казнить!' => 'kill',
    'На плаху его' => 'kill',
    'Приговор — смерть' => 'kill',
    'Тебе грозит казнь.' => null,
    'За такое бывает казнь.' => null,
    'Казнь для тебя слишком мягко' => null,
    'Помнишь казнь Провентуса?' => null,
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

echo "== rumours travel ==\n";
require_once "$X/tes_world/rumors.php";
$map = tesRumorNeighbours();
$sym = true;
foreach ($map as $h => $ns) {
    foreach ($ns as $n) {
        $sym = $sym && in_array($h, $map[$n] ?? [], true);
    }
}
$check(count($map) === 9 && $sym, 'nine holds, every border goes both ways');
$far = tesRumorFarHolds('Хаафингар');
sort($far);
$check($far === ['Белый Берег', 'Вайтран', 'Фолкрит'], 'two steps from Хаафингар: ' . implode(', ', $far));
$src = 'Говорят, на суде Шаман Назим оштрафован на 500 септимов в казну.';
$d1 = tesRumorDistort($src, 'Вайтран', 1, 'Рифт');
$d2 = tesRumorDistort($src, 'Вайтран', 2, 'Винтерхолд');
$check($d1 === tesRumorDistort($src, 'Вайтран', 1, 'Рифт'), 'the same rumour reads the same in the same hold');
$check(strpos($d1, '500 ') === false && preg_match('/\d{4}/u', $d1) === 1, "numbers grow: {$d1}");
$check($d2 !== $d1 && mb_strlen($d2) <= 400, "two hops is told worse: {$d2}");

echo "== witnesses ==\n";
require_once "$X/tes_world/witness.php";
$line = '(beings in range:Хронгар (hostile),Ольфина Серая Грива,Тордрир Фурмендер [Стражник Вайтрана] (dead),Коза,Фротар,Хемгар [Стражник Вайтрана] (far away),Дагни)';
$seers = tesWitnessSeers($line);
$check($seers === ['Хронгар', 'Ольфина Серая Грива', 'Коза', 'Фротар', 'Дагни'], 'alive and near only: ' . implode(', ', $seers));
$check(!empty(tesWitnessPerson('Ольфина Серая Грива')) && empty(tesWitnessPerson('Коза')), 'a person is in the NPC table, a goat is not');

echo "== Sanguine ==\n";
require_once "$X/tes_world/sanguine.php";
$fav = tesSanguineFavor();
$check($fav >= 0 && $fav <= 100, "favour within 0-100: {$fav}");
$check(mb_strpos(tesSanguineVoice(), 'Сангвин') !== false, 'his voice is described for the Narrator');
$check(tesSanguineSpoken('Балгруф, налей мне', 'Ярл Балгруф Старший') === '', 'a line without his name is not his');

echo "== agent task undo ==\n";
require_once "$X/tes_agent/lib.php";
foreach ([
    '{npc:Ярл Балгруф Старший}.additem 000DABA7 1' => '{npc:Ярл Балгруф Старший}.removeitem 000DABA7 1',
    'player.removeitem 1A481AD7 1' => 'player.additem 1A481AD7 1',
    '{npc:Ярл Балгруф Старший}.addperk 2C3CDF4F' => '{npc:Ярл Балгруф Старший}.removeperk 2C3CDF4F',
    '{npc:Ярл Балгруф Старший}.setscale 1.5' => '{npc:Ярл Балгруф Старший}.setscale 1',
    '{npc:Назим}.kill' => '{npc:Назим}.resurrect',
    '{npc:Ярл Балгруф Старший}.moveto player' => null,
    '{npc:Ярл Балгруф Старший}.setav Speechcraft 100' => null,
] as $cmd => $want) {
    $got = tesAgentInverse($cmd);
    $check($got === $want, "undo of «{$cmd}» => " . var_export($got, true));
}

echo "== companions ==\n";
require_once "$X/tes_world/companion.php";
foreach (['Назим', 'Даника Свет Весны', 'Айрилет', 'Бренуин'] as $n) {
    echo "  info {$n}: " . tesCompanionNature($n) . ' / цель: ' . tesCompanionGoal($n) . "\n";
}
$check(tesCompanionDeedWeight('kill')[0] < 0 && tesCompanionDeedWeight('kill')[1] > 0, 'an execution: the decent lose trust, the cruel gain it');
$check(tesCompanionGoal('Кто-то Несуществующий') !== '', 'everyone gets a goal');
echo '  info companions now: ' . implode(', ', tesCompanionList()) . "\n";

echo "== living world ==\n";
require_once "$X/tes_world/world.php";
$check(tesWorldCraftOf('Адрианна Авениччи') === 'smith' && tesWorldCraftOf('Аркадия') === 'alchemist' && tesWorldCraftOf('Дорти') === '', 'masters: smith, alchemist; a child takes no orders');
$it = tesWorldItemByName('стальной меч');
$check(!empty($it['formid']), 'стальной меч found in the index: ' . json_encode($it, JSON_UNESCAPED_UNICODE));
$check(tesWorldCraftPrice('Эбонитовый меч') === 1500 && tesWorldCraftPrice('Стальной меч') === 150, 'price by material');
$check(tesWorldCraftSpoken('Сделай мне одолжение', 'Адрианна Авениччи') === '', '«сделай мне одолжение» is not an order');
echo '  info place now: ' . tesWorldPlaceNow() . "\n";

echo "== pantheon ==\n";
require_once "$X/tes_world/gods.php";
$found = function (string $line): string {
    $t = mb_strtolower($line);
    foreach (tesGods() as $k => $g) {
        if (preg_match('/(?<![\p{L}])(?:' . $g['re'] . ')/u', $t)) {
            return $k;
        }
    }
    return '';
};
foreach (['Аркей, исцели меня' => 'arkay', 'Кинарет, пошли дождь' => 'kynareth', 'Мара, пусть Ольфина полюбит меня' => 'mara',
    'Хермеус Мора, открой тайну' => 'hermaeus', 'Клавикус Вайл, дай 1000 в долг' => 'clavicus', 'Шеогорат, повесели' => 'sheogorath',
    'Мне снился кошмар' => '', 'Марамаль придёт' => ''] as $line => $want) {
    $check($found($line) === $want, "«{$line}» => '" . $found($line) . "'");
}
$check(tesGodTarget('Мара, пусть Ольфина полюбит меня') === 'Ольфина Серая Грива', 'Мара: the person named is found');

echo "== services and prices (bridge 16) ==\n";
require_once "$X/tes_world/services.php";
$src = file_get_contents("$X/tes_world/services.php");
$check(strpos($src, "tesBridgeVersion() < 16") !== false, 'services wait for bridge 16 (a game on the old bridge gets a polite note)');
$psc = file_get_contents(dirname(__DIR__) . '/papyrus/TESGodConsoleReport/Source/Scripts/AIAgentQuestProgressionBridge.psc');
$check(strpos($psc, 'tesversion@@16') !== false && strpos($psc, 'Function TESService') !== false && strpos($psc, 'Function TESPrice') !== false, 'the bridge source has v16: tesbarter/testrain/tesgiftmenu/tesprice');
foreach (['Давай поторгуем' => 'tesbarter', 'Покажи свой товар' => 'tesbarter', 'Научи меня' => 'testrain', 'Прими подарок' => 'tesgiftmenu', 'Как дела?' => ''] as $line => $want) {
    $t = mb_strtolower($line);
    $got = preg_match('/(?<![\p{L}])(поторгуем|покажи\s+(?:свой\s+|свои\s+)?товар\p{L}*)(?![\p{L}])/u', $t) ? 'tesbarter'
        : (preg_match('/научи\s+меня/u', $t) ? 'testrain' : (preg_match('/прими\s+(?:мой\s+)?подар/u', $t) ? 'tesgiftmenu' : ''));
    $check($got === $want, "«{$line}» => '{$got}'");
}

foreach (['Эльфийский лук стоит 200, не больше?', 'Этот меч стоит 300', 'Эльфийский лук стоит 200', 'Меч теперь стоит 300'] as $line) {
    $check(tesPriceSpoken($line) === '', "bargaining / one word is not a decree: «{$line}»");
}

echo "== gods' voices ==\n";
foreach (array_merge(['sanguine' => 'maledrunk'], array_map(fn($g) => strval($g['voicewav'] ?? ''), tesGods())) as $k => $v) {
    $check($v !== '' && is_file("/home/dwemer/f5-tts/voices/{$v}.wav"), "{$k}: voice {$v}.wav exists in F5-TTS");
}
$check(tesPriceSpoken('Сколько стоит эль?') === '', 'a question about a price is not a decree');

echo "== fame ==\n";
require_once "$X/tes_world/fame.php";
$check(tesFameNick(['cruel' => 9, 'mercy' => 2, 'generous' => 1, 'reveler' => 6, 'pious' => 0, 'mad' => 0]) === 'Кровавый', 'nine cruel deeds over six feasts: Кровавый');
$check(tesFameNick(['cruel' => 3, 'mercy' => 2, 'generous' => 1, 'reveler' => 4, 'pious' => 0, 'mad' => 0]) === '', 'nothing stands out yet: no nickname');
$check(tesFameNick(['cruel' => 5, 'mercy' => 0, 'generous' => 0, 'reveler' => 5, 'pious' => 0, 'mad' => 0]) === '', 'a tie: no nickname');
$ballad = tesFameSpoken('Спой про меня балладу', 'Микаэль');
$check(mb_strpos($ballad, 'балладу') !== false, 'a bard sings of the ruler: ' . mb_substr($ballad, 0, 120));
$check(tesFameSpoken('Спой про меня', 'Ярл Балгруф Старший') === '', 'the jarl is not a bard');
echo '  info deeds now: ' . json_encode(tesFameDeeds()) . ' -> «' . tesFameNick(tesFameDeeds()) . "»\n";

echo $fail ? "\n{$fail} FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
