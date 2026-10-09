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
            $label = "«{$title}»" . ($count > 1 ? " ({$count} copies)" : '');
            if ($content === '') {
                $lines[] = $label . ' - a document with this title; its exact text was not kept on the server, judge by the title and the player\'s words';
                continue;
            }
            $content = mb_substr($content, 0, max(100, min(600, $budget)));
            $budget -= mb_strlen($content);
            $lines[] = $label . ': ' . $content;
        }
        if ($lines) {
            chimRegisterPromptInjection('prompt_bottom', 'tes_papers',
                "IMPORTANT - YOU HOLD THE PAPERS. {$tesPapersPlayer} has already handed them to you, they are with you and you have read them:\n- "
                . implode("\n- ", $lines)
                . "\nIn the item list (inventory) these papers are called «Generic Note» / «общая записка» - these ARE them, the sheets are NOT blank. "
                . "If you said earlier that there are no papers, that they are mere notes or blank sheets - you had not looked closely; now you have read them. "
                . "Do not ask to hand them over again and do not deny you have them: answer by the content of these papers.", 98);
            // The system-block line alone lost to the NPC's own earlier denials (live 01:08, Gemini
            // 2.5 Flash, 100k-char prompt: the contract text "заплатившему 500 000 септимов" was in
            // the prompt and the answer was still "нет никаких купчих на полмиллиона"). When the
            // player is talking about the papers, repeat them in the LAST message, right before
            // the answer.
            $tesPapersSaid = mb_strtolower(strval($GLOBALS['gameRequest'][3] ?? ''));
            if (isset($GLOBALS['request']) && is_string($GLOBALS['request'])
                && preg_match('/бумаг|документ|договор|купч|заявлен|записк|прочит|прочт|читай|почита|распис|500|полмиллион|пятьсот/u', $tesPapersSaid)) {
                $GLOBALS['request'] = "(Before answering reread the papers {$tesPapersPlayer} handed you - they are in your hands, they are the «Generic Note» among your things:\n- "
                    . implode("\n- ", $lines)
                    . "\nAnswer strictly by their text: what is written there is the truth. Forget your earlier words «бумаг нет / там пусто» - you had not read them then.) "
                    . $GLOBALS['request'];
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_papers] ' . $e->getMessage());
}
