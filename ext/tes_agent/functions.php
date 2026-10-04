<?php
/*
 * tes_agent: hand-off from the Narrator to the background goal agent.
 *
 * The Narrator answers a big goal with GodCommand target "goal: <the goal in the player's
 * words>". This hook (registered before tes_god_guard's: ext/ is scanned alphabetically)
 * takes such an action out of the list and starts worker.php. Everything else passes on.
 */

require_once __DIR__ . '/lib.php';

$GLOBALS['action_post_process_fnct_ex'][] = function ($actions) {
    if (!is_array($actions) || !isset($GLOBALS['db'])) {
        return $actions;
    }
    foreach ($actions as $n => $action) {
        try {
            $parts = explode('|', strval($action));
            $call = explode('@', strval($parts[2] ?? ''));
            $code = function_exists('getFunctionCodeName') ? getFunctionCodeName($call[0]) : false;
            if (($code ?: $call[0]) !== 'GodCommand') {
                continue;
            }
            $raw = implode('@', array_slice($call, 1));
            $payload = function_exists('decodeFunctionExecutionParameterPayload')
                ? decodeFunctionExecutionParameterPayload($raw)
                : json_decode($raw, true);
            $text = is_array($payload) ? trim(strval($payload['target'] ?? '')) : trim($raw);
            // The Narrator sometimes writes a program instead of commands ("foreach {npc} in
            // nearby_actors.filter(...) { npc.unsummon() }", live 12:30-12:53, seven times, all
            // refused): that is a many-step goal - it goes to the agent with the player's words.
            if (preg_match('/^\s*(foreach|for|while|if).*(\{|:|in)/isu', $text)) {
                $said = trim(preg_replace('/^[^:]{1,40}:\s*/u', '', strval($GLOBALS['gameRequest'][3] ?? '')) ?? '');
                $text = 'goal: ' . ($said !== '' ? $said . ' (замысел рассказчика: ' . mb_substr($text, 0, 200) . ')' : $text);
            }
            if (!preg_match('/^\s*(goal|цель|ask|вопрос)\s*:\s*(.+)$/isu', $text, $m)) {
                continue;
            }
            // "ask:" = a question: the worker gets read-only tools, nothing in the world changes
            $readonly = in_array(mb_strtolower($m[1]), ['ask', 'вопрос'], true);
            [$ok, $message] = tesAgentStart($m[2], false, $readonly);
            error_log('[tes_agent] ' . ($ok ? 'started' : 'refused') . ": {$message} | goal: {$m[2]}");
            tesAgentNotify($ok ? ($readonly ? 'Нарратор выясняет: ' : 'Нарратор взялся за дело: ') . $m[2] : 'Нарратор: ' . $message);
            unset($actions[$n]);
        } catch (Throwable $e) {
            error_log('[tes_agent] ' . $e->getMessage());
        }
    }
    return $actions;
};
