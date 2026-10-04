<?php
/*
 * tes_world, after every request:
 *  - executions that the fight did not finish are finished (lib.php tesWorldDuelTick);
 *  - the guard's round. Owner, 2026-10-04: "если приказ - они следят за исполнением приказа",
 *    then "стражники нихуя не делают … а именно типа ходить дежурить". The silent agent check
 *    (once in 3 minutes) found people and changed nothing anyone could see. Now, while the ruler
 *    has standing laws, the guard nearest to the player walks his round: once a minute he goes
 *    up to one person the law is about (CHIM's own MoveTo), and when he is there the law is
 *    carried out on that person. Each person is checked at most once in 30 minutes.
 *    Laws this round does not understand still go to the agent, at most once in 10 minutes.
 */

try {
    if (isset($GLOBALS['db']) && empty($GLOBALS['TES_WORLD_POST'])) {
        $GLOBALS['TES_WORLD_POST'] = true;
        require_once __DIR__ . '/lib.php';
        tesWorldDuelTick();
        $tesWorldType = strval($GLOBALS['gameRequest'][0] ?? '');
        $tesWorldLaws = tesWorldLaws();
        // laws are the ruler's: only while the player holds a title (owner: "только в случае если
        // игрок ярл и он издаёт законы")
        if ($tesWorldLaws && !empty(tesWorldFacts()['player_title']) && in_array($tesWorldType, ['inputtext', 'inputtext_s', 'rechat', 'request', 'infonpc_close', 'infonpc', 'bored'], true)) {
            $db = $GLOBALS['db'];
            $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_world_patrols (id bigserial PRIMARY KEY, created_at timestamptz NOT NULL DEFAULT now(), guard text NOT NULL DEFAULT '')");
            $db->execQuery("ALTER TABLE public.tes_world_patrols ADD COLUMN IF NOT EXISTS target text NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS stage text NOT NULL DEFAULT 'agent', ADD COLUMN IF NOT EXISTS law text NOT NULL DEFAULT ''");
            $crime = __DIR__ . '/../tes_crime/lib.php';
            if (!function_exists('tesCrimeNpcCommand') && is_readable($crime)) {
                require_once $crime;
            }
            // what the round can carry out by itself: "women go naked"
            $nudeLaw = '';
            foreach ($tesWorldLaws as $law) {
                if (preg_match('/(женщин|баб|девуш|девок|дам)/iu', $law) && preg_match('/(гол|раздет|нагишом|без одежд|одежд)/iu', $law)) {
                    $nudeLaw = $law;
                }
            }

            // 1. a guard who walked up: the law is done on the spot, the guard goes back to duty
            $walk = $db->fetchOne("SELECT * FROM public.tes_world_patrols WHERE stage = 'walk' AND created_at > now() - interval '3 minutes' ORDER BY id DESC LIMIT 1");
            if (!empty($walk['id']) && strtotime(strval($walk['created_at'])) <= time() - 15) {
                $ref = tesWorldRefOf(strval($walk['target']));
                if ($ref !== '' && !tesWorldIsChild(strval($walk['target']))) {
                    tesWorldQueue(['prid ' . $ref, 'unequipall']);
                    if (function_exists('tesCrimeNotify')) {
                        tesCrimeNotify("{$walk['guard']}: закон правителя — {$walk['target']} раздета");
                    }
                    error_log("[tes_world] round: {$walk['guard']} enforced the law on {$walk['target']}");
                }
                if (function_exists('tesCrimeNpcCommand')) {
                    tesCrimeNpcCommand(strval($walk['guard']), 'Relax@');
                }
                $db->execQuery("UPDATE public.tes_world_patrols SET stage = 'done' WHERE id = " . intval($walk['id']));
            }

            // 2. a new round step, at most once a minute
            $recent = $db->fetchOne("SELECT 1 AS x FROM public.tes_world_patrols WHERE created_at > now() - interval '60 seconds' LIMIT 1");
            $guard = function_exists('tesCrimeNearestGuard') ? tesCrimeNearestGuard('') : '';
            if (empty($recent) && $guard !== '' && $nudeLaw !== '' && function_exists('tesCrimeNpcCommand')) {
                $target = '';
                foreach (tesWorldGroup('всех женщин', $guard) as $woman) {
                    $checked = $db->fetchOne("SELECT 1 AS x FROM public.tes_world_patrols WHERE target = '" . $db->escape($woman) . "' AND created_at > now() - interval '30 minutes' LIMIT 1");
                    if (empty($checked) && !(function_exists('tesCrimeIsJailed') && tesCrimeIsJailed($woman))) {
                        $target = $woman;
                        break;
                    }
                }
                if ($target !== '') {
                    $db->insert('tes_world_patrols', ['guard' => $guard, 'target' => $target, 'stage' => 'walk', 'law' => mb_substr($nudeLaw, 0, 300)]);
                    tesCrimeNpcCommand($guard, 'MoveTo@' . $target);
                    error_log("[tes_world] round: {$guard} goes to check {$target}");
                }
            }

            // 3. laws the round does not understand: the agent, quietly, at most once in 10 minutes
            $otherLaws = array_values(array_filter($tesWorldLaws, fn($l) => $l !== $nudeLaw));
            if ($otherLaws && function_exists('tesAgentStart') && function_exists('tesAgentRunningTask')) {
                $recentAgent = $db->fetchOne("SELECT 1 AS x FROM public.tes_world_patrols WHERE stage = 'agent' AND created_at > now() - interval '600 seconds' LIMIT 1");
                $near = tesWorldNearbyNames(20);
                if (empty($recentAgent) && !tesAgentRunningTask() && $guard !== '' && count($near) >= 2) {
                    $db->insert('tes_world_patrols', ['guard' => $guard, 'stage' => 'agent']);
                    $player = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
                    tesAgentStart("Патруль закона. Правитель — {$player}. Действующие законы:\n- " . implode("\n- ", $otherLaws)
                        . "\nРядом сейчас: " . implode(', ', $near) . ". Следит стражник {$guard}."
                        . " Проверь взрослых из этого списка, к кому закон относится. Нарушителей нет — сразу finish."
                        . " Нарушителю: order стражнику {$guard} — вслух потребовать исполнить закон; затем исполни силой то, чего требует закон."
                        . " Того, кто уже был принуждён раньше и снова нарушает, — в темницу (jail на 1 сутки). Детей (раса «Ребенок»), игрока и самих стражников не трогай. Никого не убивай. Игрока не перемещай. Не больше трёх нарушителей за обход.", false, false, true, true);
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_world post] ' . $e->getMessage());
}
