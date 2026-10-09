<?php
/*
 * tes_no_attack: an NPC does not attack the player just because the model picked "Attack"
 * in a heated talk.
 *
 * Live 2026-10-04 00:49: the player asked the jarl "за что штраф?" and the model answered with
 * Attack@Шаман - Balgruuf, his housecarl and the guards went for a level-1 player in the
 * jarl's own hall. An attack on the player is let through only when the NPC is ALREADY
 * fighting (fresh game data) or really hates the player (relationship <= -60). Otherwise the
 * action is dropped and the NPC is told to use words or the law (ArrestPlayer / AddBounty).
 * Attacks on other NPCs and creatures are not touched.
 */

$GLOBALS['action_post_process_fnct_ex'][] = function ($actions) {
    if (!is_array($actions) || !isset($GLOBALS['db'])) {
        return $actions;
    }
    $player = mb_strtolower(trim(strval($GLOBALS['PLAYER_NAME'] ?? '')));
    foreach ($actions as $n => $action) {
        try {
            $parts = explode('|', strval($action));
            $call = explode('@', strval($parts[2] ?? ''));
            $code = function_exists('getFunctionCodeName') ? getFunctionCodeName($call[0]) : false;
            if (($code ?: $call[0]) !== 'Attack') {
                continue;
            }
            $raw = implode('@', array_slice($call, 1));
            $payload = json_decode($raw, true);
            $target = mb_strtolower(trim(is_array($payload) ? strval($payload['target'] ?? '') : $raw));
            $target = trim(preg_replace('/\s*\[refid:[^\]]*\]/iu', '', $target) ?? $target);
            if ($player === '' || ($target !== $player && !in_array($target, ['player', 'игрок'], true))) {
                continue;
            }
            $actor = trim(strval($parts[0] ?? ''));
            if ($actor === 'The Narrator') {
                continue;
            }
            $db = $GLOBALS['db'];
            $row = $db->fetchOne("SELECT metadata->'activity_status' AS act, extended_data->'relationships'->'Player' AS rel FROM public.core_npc_master WHERE npc_name = '" . $db->escape($actor) . "' LIMIT 1");
            $act = json_decode(strval($row['act'] ?? ''), true) ?: [];
            $rel = json_decode(strval($row['rel'] ?? ''), true) ?: [];
            $fighting = !empty($act['is_in_combat']) || !empty($act['is_attacking']);
            $hates = isset($rel['aff']) && intval($rel['aff']) <= -60;
            if ($fighting || $hates) {
                continue;
            }
            unset($actions[$n]);
            error_log("[tes_no_attack] dropped {$actor} -> Attack on the player (not in combat, aff " . ($rel['aff'] ?? 'n/a') . ')');
            $text = '(You do not attack the player with weapons over words. If he is guilty, act by law: fine him (Add_Bounty), '
                . 'arrest him (Arrest_Player) or send him off with words. One short line.)';
            $db->insert('responselog', [
                'localts' => time(), 'sent' => 0, 'actor' => 'rolemaster', 'text' => '',
                'action' => 'rolecommand|Instruction@' . str_replace(['@', '|'], ' ', $actor) . '@' . $text . '@0', 'tag' => '',
            ]);
        } catch (Throwable $e) {
            error_log('[tes_no_attack] ' . $e->getMessage());
        }
    }
    return $actions;
};
