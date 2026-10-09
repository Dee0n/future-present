<?php
/*
 * tes_estate: the bridge reports "tesbuyhouse <args>@@<result>" as a tes_god_console
 * request. ext/ is scanned alphabetically, so this runs BEFORE tes_god_console (which stores
 * the line and terminates): here the seller hears the result and the player sees it.
 */

if (strtolower(strval($GLOBALS['gameRequest'][0] ?? '')) === 'tes_god_console') {
    try {
        $message = implode('|', array_slice($GLOBALS['gameRequest'], 3));
        if (str_starts_with($message, 'tesfurnish@@') && isset($GLOBALS['db'])) {
            require_once __DIR__ . '/lib.php';
            $result = substr($message, 12);
            tesEstateEnsureTable();
            $db = $GLOBALS['db'];
            $sale = $db->fetchOne("SELECT id, seller, house FROM public.tes_estate_sales WHERE command = 'tesfurnish' AND result = '' ORDER BY id DESC LIMIT 1");
            if (!empty($sale['id']) && preg_match('/furnished (\d+) rooms for (\d+) gold, already had (\d+), could not afford (\d+)/', $result, $m)) {
                $db->execQuery("UPDATE public.tes_estate_sales SET result = '" . $db->escape($result) . "' WHERE id = " . intval($sale['id']));
                [$bought, $spent, $had, $poor] = [intval($m[1]), intval($m[2]), intval($m[3]), intval($m[4])];
                if ($bought > 0) {
                    tesEstateNotify("Обстановка «{$sale['house']}»: комнат {$bought}, потрачено {$spent}");
                }
                $text = $bought > 0
                    ? "The furnishings for «{$sale['house']}» are ordered and already in place: {$bought} rooms, the game took {$spent} septims."
                    : ($had > 0 && $poor === 0 ? "In «{$sale['house']}» everything you sell is already bought." : "Buying the furnishings failed.");
                if ($poor > 0) {
                    $text .= " The player lacked gold for {$poor} room(s).";
                }
                tesEstateTell($sale['seller'], "({$text} Say it briefly, 1-2 sentences, nothing made up.)");
            }
        }
        if (str_starts_with($message, 'tesbuyhouse ') && isset($GLOBALS['db'])) {
            require_once __DIR__ . '/lib.php';
            [$cmd, $result] = array_pad(explode('@@', $message, 2), 2, '');
            tesEstateEnsureTable();
            $db = $GLOBALS['db'];
            $sale = $db->fetchOne("SELECT id, seller, house FROM public.tes_estate_sales WHERE command = '" . $db->escape(trim($cmd))
                . "' AND result = '' ORDER BY id DESC LIMIT 1");
            if (!empty($sale['id'])) {
                $db->execQuery("UPDATE public.tes_estate_sales SET result = '" . $db->escape($result) . "' WHERE id = " . intval($sale['id']));
                if (preg_match('/^sold for (\d+), gold left (\d+)/', $result, $m)) {
                    tesEstateNotify("Куплен дом: {$sale['house']} за {$m[1]} септимов");
                    $prepaidNote = '';
                    if (str_ends_with(trim($cmd), ' prepaid')) {
                        $taken = 0;
                        foreach ((array)$db->fetchAll("SELECT fullcall FROM actions_issued WHERE actorname = '" . $db->escape($sale['seller']) . "' AND action ILIKE 'TakeGoldFromPlayer%'") as $r) {
                            if (preg_match('/TakeGoldFromPlayer@\D*(\d+)/', strval($r['fullcall']), $tm)) {
                                $taken += intval($tm[1]);
                            }
                        }
                        $change = $taken - intval($m[1]);
                        $prepaidNote = " The payment is counted from the money you took earlier ({$taken})."
                            . ($change > 0 ? " You owe the player {$change} septims change - return it now with the action Give_Gold_To (target: игрок, item: {$change})." : '');
                    }
                    tesEstateTell($sale['seller'], "(The deal is done: «{$sale['house']}» now belongs to the player; the game gave him the key, the decorating guide and the rights to the house for {$m[1]} septims.{$prepaidNote} Say so briefly, 1-2 sentences, do not repeat what was said before, do not lead him anywhere.)");
                } elseif (preg_match('/not enough gold: has (\d+), price (\d+)/', $result, $m)) {
                    tesEstateTell($sale['seller'], "(The deal fell through: the player has {$m[1]} septims and «{$sale['house']}» costs {$m[2]}. Say it in one sentence, no discounts of your own will.)");
                } elseif (str_contains($result, 'already owns')) {
                    tesEstateTell($sale['seller'], "(«{$sale['house']}» already belongs to the player. Say it in one sentence.)");
                } else {
                    tesEstateTell($sale['seller'], "(Making the sale of «{$sale['house']}» failed: {$result}. Admit it in one sentence.)");
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[tes_estate preprocessing] ' . $e->getMessage());
    }
}
