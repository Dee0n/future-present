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
            // The system-block line alone lost to the NPC's own earlier denials (live 01:08, Gemini
            // 2.5 Flash, 100k-char prompt: the contract text "заплатившему 500 000 септимов" was in
            // the prompt and the answer was still "нет никаких купчих на полмиллиона"). When the
            // player is talking about the papers, repeat them in the LAST message, right before
            // the answer.
            $tesPapersSaid = mb_strtolower(strval($GLOBALS['gameRequest'][3] ?? ''));
            if (isset($GLOBALS['request']) && is_string($GLOBALS['request'])
                && preg_match('/бумаг|документ|договор|купч|заявлен|записк|прочит|прочт|читай|почита|распис|500|полмиллион|пятьсот/u', $tesPapersSaid)) {
                $GLOBALS['request'] = "(Перед ответом перечитай бумаги, которые {$tesPapersPlayer} тебе передал — они у тебя в руках, это и есть «Generic Note» из твоих вещей:\n- "
                    . implode("\n- ", $lines)
                    . "\nОтвечай строго по их тексту: что в них написано, то и правда. Свои прежние слова «бумаг нет / там пусто» забудь — ты тогда не прочёл.) "
                    . $GLOBALS['request'];
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_papers] ' . $e->getMessage());
}
