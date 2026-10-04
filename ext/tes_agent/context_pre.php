<?php
/*
 * tes_agent: one instruction for the Narrator - delegate multi-step goals to the agent -
 * plus the state of the running task, so it does not start the same goal twice.
 * Loaded inside a function by main.php: $GLOBALS only, never break the dialogue.
 */

try {
    $tesAgentIsNarrator = in_array(strval($GLOBALS['gameRequest'][0] ?? ''), ['narrator_inputtext', 'narration', 'narrator_welcome', 'narrator_quest_comment'], true)
        || strval($_GET['profile'] ?? '') === md5('The Narrator')
        || strval($GLOBALS['HERIKA_NAME'] ?? '') === 'The Narrator';
    if ($tesAgentIsNarrator && isset($GLOBALS['db']) && function_exists('chimRegisterPromptInjection')) {
        require_once __DIR__ . '/lib.php';
        // compact on purpose (2026-10-04, cost): sent with every Narrator request
        $line = "МНОГОШАГОВЫЕ просьбы или поиск («сделай меня богом воровства», «подготовь Лидию к бою», «хочу этот дом и всё в нём») — "
            . "не собирай команды сам: GodCommand target \"goal: <цель словами игрока>\" и коротко скажи, что берёшься. "
            . "ВОПРОС о мире без точного ответа — target \"ask: <вопрос>\" (только выяснит). "
            . "Очки способностей — \"player.perkpoints <число>\" (по умолчанию 20); «прокачай всё» — \"goal: …\". Понятное не переспрашивай. "
            . "СЕКС (настоящая сцена, только взрослые): \"{npc:Имя}.sex [вид: минет, раком, анал, наездница, поцелуй]\" — с игроком, "
            . "\"{npc:А}.sex {npc:Б}\" — двое NPC; идущая сцена переключится; конец — \"{npc:Имя}.sex стоп\". "
            . "СПУТНИК — \"{npc:Имя}.follow\" / \".unfollow\". КОПИЯ человека — \"{npc:Имя}.clone [N]\" (не placeatme). Привести — \"{npc:Имя}.moveto player\". "
            . "Никого не замораживай (speedmult 0) без просьбы; делай ровно сказанное. "
            . "Имена с голоса искажены («арилет» = Айрилет) — бери ближайшее знакомое из тех, кто рядом.";
        $running = tesAgentRunningTask();
        if ($running) {
            $line .= " Сейчас уже идёт задача: «{$running['goal']}» (шаг {$running['steps']}) — не запускай её снова.";
        }
        chimRegisterPromptInjection('prompt_bottom', 'tes_agent', $line, 55);
    }
} catch (Throwable $e) {
    error_log('[tes_agent context_pre] ' . $e->getMessage());
}
