<?php
/*
 * tes_papers: an NPC knows which papers the player handed over and what they say.
 *
 * Live 2026-10-04 00:48-01:05: the player gave the jarl four documents (eventlog "itemfound":
 * "Шаман gave 1 Купчая на дом to Ярл Балгруф Старший"), then heard "У меня нет никаких твоих
 * документов!" and, after Check_Inventory answered "5 Generic Note", "пять общих записок,
 * пустые листы". CHIM never shows an NPC the TEXT of a received paper; every note shares one
 * base form, so the inventory names them all "Generic Note"; and the hand-over line itself
 * falls out of the short context history.
 * For the speaking NPC: papers received from the player + their text, at the bottom of the
 * prompt, saying outright that the "Generic Note"s ARE these papers.
 */

try {
    $tesPapersNpc = strval($GLOBALS['HERIKA_NAME'] ?? '');
    $tesPapersPlayer = strval($GLOBALS['PLAYER_NAME'] ?? '');
    if ($tesPapersNpc !== '' && $tesPapersNpc !== 'The Narrator' && $tesPapersPlayer !== ''
        && isset($GLOBALS['db']) && function_exists('chimRegisterPromptInjection')) {
        require_once __DIR__ . '/lib.php';
        $lines = [];
        $budget = 1800;
        foreach (tesPapersReceived($tesPapersNpc, $tesPapersPlayer) as $title => $count) {
            if (!tesPapersIsPaper($title)) {
                continue;
            }
            $content = tesPapersText($title);
            $label = "«{$title}»" . ($count > 1 ? " ({$count} экз.)" : '');
            if ($content === '') {
                $lines[] = $label . ' — документ с таким названием; его точный текст на сервере не сохранился, суди по названию и словам игрока';
                continue;
            }
            $content = mb_substr($content, 0, max(100, min(600, $budget)));
            $budget -= mb_strlen($content);
            $lines[] = $label . ': ' . $content;
        }
        if ($lines) {
            chimRegisterPromptInjection('prompt_bottom', 'tes_papers',
                "ВАЖНО — БУМАГИ У ТЕБЯ НА РУКАХ. {$tesPapersPlayer} уже передал их тебе, они лежат у тебя и ты их прочёл:\n- "
                . implode("\n- ", $lines)
                . "\nВ списке вещей (инвентаре) эти бумаги называются «Generic Note» / «общая записка» — это ОНИ и есть, листы НЕ пустые. "
                . "Если раньше ты говорил, что бумаг нет, что это просто записки или пустые листы, — ты не разглядел; теперь ты их прочёл. "
                . "Не проси передать их снова и не отрицай, что они у тебя: отвечай по содержанию этих бумаг.", 98);
        }
    }
} catch (Throwable $e) {
    error_log('[tes_papers] ' . $e->getMessage());
}
