<?php
/*
 * tes_estate: SellHouse (core_action, settings/estate.sql) from a steward/jarl ->
 * bridge "tesbuyhouse <stage> <price global>" (vanilla purchase, gold checked in game).
 * The bridge's report comes back through preprocessing.php and the seller reacts to it.
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
            $code = $code ?: $call[0];
            if ($code !== 'SellHouse' && $code !== 'FurnishHouse') {
                continue;
            }
            unset($actions[$n]);
            $seller = trim(strval($parts[0] ?? ''));
            $raw = implode('@', array_slice($call, 1));
            $payload = function_exists('decodeFunctionExecutionParameterPayload')
                ? decodeFunctionExecutionParameterPayload($raw) : json_decode($raw, true);
            $houseName = is_array($payload) ? trim(strval($payload['target'] ?? '')) : trim($raw);
            $house = tesEstateFind($houseName, $seller);
            if ($code === 'FurnishHouse') {
                // all furnishings at once; the bridge checks gold per room and skips owned ones
                if (!$house || !tesEstateMaySell($house, $seller)) {
                    tesEstateTell($seller, '(You cannot furnish this house - it is not a house of your city. Say it in one sentence.)');
                    continue;
                }
                $command = tesEstateFurnishCommand($house, true);
                tesEstateEnsureTable();
                $db = $GLOBALS['db'];
                $recent = $db->fetchOne("SELECT 1 AS x FROM public.tes_estate_sales WHERE house = '" . $db->escape($house['title']) . "' AND command LIKE 'tesfurnish%' AND created_at > now() - interval '1 minute'");
                if ($command === '' || !empty($recent)) {
                    continue;
                }
                $db->insert('tes_estate_sales', ['seller' => $seller, 'house' => $house['title'], 'command' => 'tesfurnish']);
                $queued = function_exists('herikaQueueGodCommands') ? herikaQueueGodCommands($command) : 0;
                error_log("[tes_estate] {$seller} furnishes {$house['title']} (queued {$queued})");
                if ($queued === 0) {
                    tesEstateTell($seller, '(Ordering the furnishings failed - the game channel is unavailable. Say so honestly in one sentence, do not make up that it is done.)');
                }
                continue;
            }
            if (!$house) {
                tesEstateTell($seller, "(The sale is not made: no such house for sale. For sale: Дом теплых ветров, Высокий шпиль, Медовик, Влиндрел-холл, Хьерим. Say it in one sentence.)");
                continue;
            }
            if (!tesEstateMaySell($house, $seller)) {
                tesEstateTell($seller, "(You cannot sell «{$house['title']}» - it is not your city. Say whom to turn to, in one sentence.)");
                continue;
            }
            tesEstateEnsureTable();
            $db = $GLOBALS['db'];
            // one sale attempt per house per minute: the model repeats actions in rechat
            $recent = $db->fetchOne("SELECT 1 AS x FROM public.tes_estate_sales WHERE house = '" . $db->escape($house['title']) . "' AND created_at > now() - interval '1 minute'");
            if (!empty($recent)) {
                continue;
            }
            $prepaid = tesEstatePrepaid($seller) >= tesEstatePrice($house);
            $command = 'tesbuyhouse ' . $house['stage'] . ' ' . $house['price_global'] . ($prepaid ? ' prepaid' : '');
            // a seller who once "followed" the player keeps trailing them (CHIM follow flag)
            tesEstateQueueFor($seller, 'tesunfollow', 'tes_unfollow');
            $db->insert('tes_estate_sales', ['seller' => $seller, 'house' => $house['title'], 'command' => $command]);
            $queued = function_exists('herikaQueueGodCommands') ? herikaQueueGodCommands($command) : 0;
            error_log("[tes_estate] {$seller} sells {$house['title']}: {$command} (queued {$queued})");
            if ($queued === 0) {
                tesEstateTell($seller, '(Making the sale failed - the game channel is unavailable. Say honestly in one sentence that the house is NOT sold; do not make up that you handed over the keys.)');
            }
        } catch (Throwable $e) {
            error_log('[tes_estate] ' . $e->getMessage());
        }
    }
    return $actions;
};
