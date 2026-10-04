<?php
/*
 * tes_world, after every request:
 *  - executions that the fight did not finish are finished (lib.php tesWorldDuelTick);
 *  - the law patrol. Owner, 2026-10-04: "если приказ - они следят за исполнением приказа". While
 *    the ruler has standing laws and a guard is near the player, the goal agent is sent, quietly,
 *    at most once in 3 minutes, to look at the people around and make that guard enforce the law:
 *    demand first, force or jail after.
 */

try {
    if (isset($GLOBALS['db']) && empty($GLOBALS['TES_WORLD_POST'])) {
        $GLOBALS['TES_WORLD_POST'] = true;
        require_once __DIR__ . '/lib.php';
        tesWorldDuelTick();
        $tesWorldType = strval($GLOBALS['gameRequest'][0] ?? '');
        $tesWorldLaws = tesWorldLaws();
        if ($tesWorldLaws && in_array($tesWorldType, ['inputtext', 'inputtext_s', 'rechat', 'request', 'infonpc_close', 'bored'], true)
            && function_exists('tesAgentStart') && function_exists('tesAgentRunningTask')) {
            $db = $GLOBALS['db'];
            $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_world_patrols (id bigserial PRIMARY KEY, created_at timestamptz NOT NULL DEFAULT now(), guard text NOT NULL DEFAULT '')");
            $recent = $db->fetchOne("SELECT 1 AS x FROM public.tes_world_patrols WHERE created_at > now() - interval '180 seconds' LIMIT 1");
            if (empty($recent) && !tesAgentRunningTask()) {
                $near = tesWorldNearbyNames(20);
                $guard = '';
                foreach ($near as $n) {
                    if (mb_stripos($n, 'Стражник') !== false) {
                        $guard = $n;
                        break;
                    }
                }
                if ($guard !== '' && count($near) >= 2) {
                    $db->insert('tes_world_patrols', ['guard' => $guard]);
                    $player = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
                    tesAgentStart("Патруль закона. Правитель — {$player}. Действующие законы:\n- " . implode("\n- ", $tesWorldLaws)
                        . "\nРядом сейчас: " . implode(', ', $near) . ". Следит стражник {$guard}."
                        . " Проверь взрослых из этого списка, к кому закон относится (get_state покажет, что на человеке надето; npc_info — пол и расу). Нарушителей нет — сразу finish."
                        . " Нарушителю: order стражнику {$guard} — вслух потребовать исполнить закон; затем исполни силой то, чего требует закон (раздеть — console «{npc:Имя}.unequipall»)."
                        . " Того, кто уже был принуждён раньше и снова нарушает (remember об этом есть в его памяти), — в темницу (jail на 1 сутки). Принудил — запиши нарушителю remember одной строкой."
                        . " Детей (раса «Ребенок»), игрока и самих стражников не трогай. Никого не убивай. Не больше трёх нарушителей за обход.", false, false, true, true);
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_world post] ' . $e->getMessage());
}
