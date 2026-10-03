<?php
/*
 * tes_papers: Check_Inventory answers "5 Generic Note" for the papers an NPC holds (all CHIM
 * notes share one base form; their titles live on the individual references). Rewrite that
 * part of the funcret with the real titles of the papers the player gave this NPC, before the
 * line is logged and shown to the model.
 */

try {
    if (strtolower(strval($GLOBALS['gameRequest'][0] ?? '')) === 'funcret' && isset($GLOBALS['db'])
        && strpos(strval($GLOBALS['gameRequest'][3] ?? ''), 'Generic Note') !== false) {
        $tesPapersData = strval($GLOBALS['gameRequest'][3]);
        $tesPapersParts = explode('@', $tesPapersData);
        if (($tesPapersParts[1] ?? '') === 'CheckInventory' && trim(strval($tesPapersParts[2] ?? '')) !== '') {
            require_once __DIR__ . '/lib.php';
            $tesPapersOwner = trim($tesPapersParts[2]);
            $tesPapersPlayer = strval($GLOBALS['PLAYER_NAME'] ?? '');
            if ($tesPapersPlayer === '') {
                $row = $GLOBALS['db']->fetchOne("SELECT value FROM public.core_player WHERE id = 'player_name'");
                $tesPapersPlayer = strval($row['value'] ?? '');
            }
            $named = [];
            foreach (tesPapersReceived($tesPapersOwner, $tesPapersPlayer) as $title => $count) {
                if (tesPapersIsPaper($title)) {
                    $named[$title] = $count;
                }
            }
            if ($named) {
                $GLOBALS['gameRequest'][3] = preg_replace_callback('/(\d+) Generic Note/', function ($m) use ($named) {
                    $total = intval($m[1]);
                    $out = [];
                    foreach ($named as $title => $count) {
                        $take = min($count, $total);
                        if ($take <= 0) {
                            break;
                        }
                        $out[] = "{$take} документ «{$title}» (исписанная бумага, получена от игрока)";
                        $total -= $take;
                    }
                    if ($total > 0) {
                        $out[] = "{$total} записка";
                    }
                    return implode(',', $out);
                }, $tesPapersData) ?? $tesPapersData;
                if (isset($gameRequest) && is_array($gameRequest)) {
                    $gameRequest[3] = $GLOBALS['gameRequest'][3];
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_papers preprocessing] ' . $e->getMessage());
}
