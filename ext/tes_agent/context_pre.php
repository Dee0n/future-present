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
        $line = "MULTI-STEP requests or searches («сделай меня богом воровства», «подготовь Лидию к бою», «хочу этот дом и всё в нём») - "
            . "do not build commands yourself: GodCommand target \"goal: <the goal in the player's own Russian words>\" and say briefly that you take it on. "
            . "A QUESTION about the world with no exact answer - target \"ask: <question, in Russian too>\" (only finds out). "
            . "Perk points - \"player.perkpoints <number>\" (default 20); «прокачай всё» - \"goal: …\". Do not ask again about what is clear. "
            . "SEX (a real scene, adults only): \"{npc:Name}.sex [kind: blowjob, doggystyle, anal, cowgirl, kissing]\" - with the player, "
            . "\"{npc:A}.sex {npc:B}\" - two NPCs; a running scene switches; end - \"{npc:Name}.sex stop\". "
            . "FOLLOWER - \"{npc:Name}.follow\" / \".unfollow\". COPY of a person - \"{npc:Name}.clone [N]\" (not placeatme). Bring here - \"{npc:Name}.moveto player\". "
            . "Rumour/«пусти слух», skills and perks «полностью», «собери всех», «приказ страже на дежурство» - always goal (the agent has rumor, setav per skill, move_npc); do not just promise in words. "
            . "A command did not take - say so plainly and try another way, not «сказано — сделано». "
            . "WEATHER - GodCommand «fw <ID>» (0010A240 clear, 0010A243 cloudy, 000C821E fog, 000C821F rain, 000C8220 thunderstorm, 0004D7FB snow) and «set gamehour to N» for the hour: «ясный день» = both, not only the hour. "
            . "Freeze nobody (speedmult 0) unless asked; do exactly what was said. "
            . "Names from voice come distorted («арилет» = Айрилет) - take the closest known name among those nearby.";
        $running = tesAgentRunningTask();
        if ($running) {
            $line .= " A task is already running: «{$running['goal']}» (step {$running['steps']}) - do not start it again.";
        }
        chimRegisterPromptInjection('prompt_bottom', 'tes_agent', $line, 55);
    }
} catch (Throwable $e) {
    error_log('[tes_agent context_pre] ' . $e->getMessage());
}
