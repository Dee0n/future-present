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

// SHARMAT (ext/aiagent_nsfw) knows children only by English names and "child" in the race; here
// the race is «Ребенок». Its "Update" button replaces common.php - the Russian child check is put
// back whenever the file changes (tools/sharmat_child_patch.py does the same by hand).
try {
    $tesSharmat = __DIR__ . '/../aiagent_nsfw/common.php';
    if (is_file($tesSharmat)) {
        $tesMark = sys_get_temp_dir() . '/tes_sharmat_common.mtime';
        clearstatcache(true, $tesSharmat);
        $tesMtime = strval(filemtime($tesSharmat));
        if (@file_get_contents($tesMark) !== $tesMtime) {
            $src = strval(file_get_contents($tesSharmat));
            $a = "    if (in_array(strtolower(\$actorName), aiagentNsfwChildNameBlocklist(), true)) { return true; }";
            if (strpos($src, 'tesWorldIsChild') === false && strpos($src, $a) !== false) {
                $add = "\n    // TES-Speech-Adapter: Russian race «Ребенок» and Cyrillic names (see ext/tes_world/postrequest.php)\n"
                    . "    if (function_exists('tesWorldIsChild') && tesWorldIsChild(\$actorName)) { return true; }\n"
                    . "    if (function_exists('tesGodGuardIsChild') && tesGodGuardIsChild('{npc:' . \$actorName . '}')) { return true; }\n"
                    . "    if (isset(\$GLOBALS['db'])) {\n"
                    . "        \$tesRow = \$GLOBALS['db']->fetchOne(\"SELECT race FROM core_npc_master WHERE npc_name = '\" . \$GLOBALS['db']->escape(\$actorName) . \"' LIMIT 1\");\n"
                    . "        \$tesRace = (string)(\$tesRow['race'] ?? '');\n"
                    . "        if (\$tesRace !== '' && (mb_stripos(\$tesRace, 'ребен') !== false || mb_stripos(\$tesRace, 'ребён') !== false || stripos(\$tesRace, 'child') !== false)) { return true; }\n"
                    . "    }";
                $patched = str_replace($a, $a . $add, $src);
                if (file_put_contents($tesSharmat . '.tmp', $patched) !== false && rename($tesSharmat . '.tmp', $tesSharmat)) {
                    error_log('[tes_world] SHARMAT child check put back into common.php');
                }
                clearstatcache(true, $tesSharmat);
                $tesMtime = strval(filemtime($tesSharmat));
            } elseif (strpos($src, 'tesWorldIsChild') === false) {
                error_log('[tes_world] SHARMAT common.php changed shape - Russian child check NOT applied, see tools/sharmat_child_patch.py');
            }
            @file_put_contents($tesMark, $tesMtime);
        }
    }
    // the settings page in Russian (ui_ru.js + ui_tr.php), put back after SHARMAT updates
    $tesSharmatUi = __DIR__ . '/../aiagent_nsfw/config_manager.php';
    if (is_file($tesSharmatUi)) {
        $tesUiMark = sys_get_temp_dir() . '/tes_sharmat_ui.mtime';
        clearstatcache(true, $tesSharmatUi);
        $tesUiMtime = strval(filemtime($tesSharmatUi));
        if (@file_get_contents($tesUiMark) !== $tesUiMtime) {
            $ui = strval(file_get_contents($tesSharmatUi));
            $tag = '<script src="/HerikaServer/ext/tes_world/ui_ru.js"></script>';
            if (strpos($ui, 'ui_ru.js') === false) {
                $pos = strripos($ui, '</body>');
                $ui = $pos !== false ? substr($ui, 0, $pos) . $tag . "\n" . substr($ui, $pos) : $ui . "\n" . $tag . "\n";
                if (file_put_contents($tesSharmatUi . '.tmp', $ui) !== false && rename($tesSharmatUi . '.tmp', $tesSharmatUi)) {
                    error_log('[tes_world] SHARMAT settings page: Russian put back');
                }
                clearstatcache(true, $tesSharmatUi);
                $tesUiMtime = strval(filemtime($tesSharmatUi));
            }
            @file_put_contents($tesUiMark, $tesUiMtime);
        }
    }
} catch (Throwable $e) {
    error_log('[tes_world sharmat] ' . $e->getMessage());
}

// Rumors go into EVERY prompt (25 of them = ~1.7K tokens, with duplicates: owner, 2026-10-04,
// "расход огромный"). Once a minute: duplicates out, at most 8 newest stay PER HOLD (a character sees only the
// rumours of his own hold; rumours.php sends copies to the other holds) - backup in tes_backup_rumors.
try {
    $tesRumorMark = sys_get_temp_dir() . '/tes_rumors_trim.ts';
    if (isset($GLOBALS['db']) && (time() - intval(@file_get_contents($tesRumorMark))) > 60) {
        @file_put_contents($tesRumorMark, strval(time()));
        $db = $GLOBALS['db'];
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_backup_rumors AS SELECT *, now() AS saved_at FROM public.rumors WHERE false");
        $db->execQuery("INSERT INTO public.tes_backup_rumors SELECT r.*, now() FROM public.rumors r WHERE r.id IN ("
            . "SELECT id FROM (SELECT id, row_number() OVER (PARTITION BY content ORDER BY id DESC) AS dup, row_number() OVER (PARTITION BY hold ORDER BY id DESC) AS pos FROM public.rumors) t WHERE dup > 1 OR pos > 8)"
            . " AND r.id NOT IN (SELECT id FROM public.tes_backup_rumors)");
        $db->execQuery("DELETE FROM public.rumors WHERE id IN ("
            . "SELECT id FROM (SELECT id, row_number() OVER (PARTITION BY content ORDER BY id DESC) AS dup, row_number() OVER (PARTITION BY hold ORDER BY id DESC) AS pos FROM public.rumors) t WHERE dup > 1 OR pos > 8)");
    }
} catch (Throwable $e) {
    error_log('[tes_world rumors] ' . $e->getMessage());
}

try {
    if (isset($GLOBALS['db']) && empty($GLOBALS['TES_WORLD_POST'])) {
        $GLOBALS['TES_WORLD_POST'] = true;
        require_once __DIR__ . '/lib.php';
        tesWorldDuelTick();
        foreach (['tesWorldVerifyTick', 'tesWatchTick', 'tesTreasuryTax', 'tesRealmReport', 'tesRealmPlots', 'tesCourtTick', 'tesTalkTick', 'tesRealmGatherTick', 'tesErrandTick', 'tesRumorSpreadTick', 'tesWitnessTick', 'tesSanguineTick', 'tesCompanionTick', 'tesGodsTick', 'tesPriceTick', 'tesFameTick', 'tesOverhearTick', 'tesEconomyTick'] as $tesWorldTick) {
            try {
                if (function_exists($tesWorldTick)) {
                    $tesWorldTick();
                }
            } catch (Throwable $e) {
                error_log("[tes_world {$tesWorldTick}] " . $e->getMessage());
            }
        }
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

            $db->execQuery("ALTER TABLE public.tes_world_patrols ADD COLUMN IF NOT EXISTS looked_at timestamptz, ADD COLUMN IF NOT EXISTS enforced boolean NOT NULL DEFAULT false");
            // 1a. a guard who walked up looks at her: what is she wearing? (the game answers tesstate "; worn BODY=…")
            $walk = $db->fetchOne("SELECT * FROM public.tes_world_patrols WHERE stage = 'walk' AND created_at > now() - interval '3 minutes' ORDER BY id DESC LIMIT 1");
            if (!empty($walk['id']) && strtotime(strval($walk['created_at'])) <= time() - 15) {
                $ref = tesWorldRefOf(strval($walk['target']));
                if ($ref !== '' && !tesWorldIsChild(strval($walk['target']))) {
                    tesWorldQueue(['prid ' . $ref, 'tesstate']);
                    $db->execQuery("UPDATE public.tes_world_patrols SET stage = 'look', looked_at = now() WHERE id = " . intval($walk['id']));
                } else {
                    $db->execQuery("UPDATE public.tes_world_patrols SET stage = 'done' WHERE id = " . intval($walk['id']));
                }
            }
            // 1b. the answer is in: naked - fine; dressed for the first time - undressed by force;
            //     dressed AGAIN after having been forced before - the guard takes her to the Whiterun jail
            //     (owner, 2026-10-04: "все кто должен быть в тюрьме должны быть за решёткой в Вайтране")
            $look = $db->fetchOne("SELECT * FROM public.tes_world_patrols WHERE stage = 'look' ORDER BY id DESC LIMIT 1");
            if (!empty($look['id'])) {
                $short = trim(preg_replace('/\s*\[[^\]]*\]/u', '', strval($look['target'])) ?? '');
                $ans = $db->fetchOne("SELECT output FROM public.tes_god_console_log WHERE command = 'tesstate' AND output LIKE '" . $db->escape($short) . "; level%' AND created_at >= '" . $db->escape(strval($look['looked_at'])) . "' ORDER BY id DESC LIMIT 1");
                $waited = time() - strtotime(strval($look['looked_at']));
                if (!empty($ans['output']) || $waited > 45) {
                    $dressed = !empty($ans['output']) ? (bool)preg_match('/; worn.*\bBODY=/u', strval($ans['output'])) : true;  // no answer: act as before
                    $ref = tesWorldRefOf(strval($look['target']));
                    $before = $db->fetchOne("SELECT 1 AS x FROM public.tes_world_patrols WHERE target = '" . $db->escape(strval($look['target'])) . "' AND enforced AND id <> " . intval($look['id']) . " AND created_at > now() - interval '12 hours' LIMIT 1");
                    $verdict = '';
                    if ($ref !== '' && $dressed && !empty($before) && function_exists('tesCrimeJail') && !(function_exists('tesCrimeIsJailed') && tesCrimeIsJailed(strval($look['target'])))) {
                        [$ok, $msg] = tesCrimeJail(strval($look['target']), $ref, 'снова нарушила закон правителя (ходит одетой)', 30, strval($look['guard']));  // a day was served within the hour (time jumps) - she "escaped"
                        $verdict = $ok ? 'в темницу' : 'темница не вышла: ' . $msg;
                        $db->execQuery("UPDATE public.tes_world_patrols SET enforced = true WHERE id = " . intval($look['id']));
                    } elseif ($ref !== '' && $dressed) {
                        tesWorldQueue(['prid ' . $ref, 'unequipall']);
                        if (function_exists('tesCrimeNotify')) {
                            tesCrimeNotify("{$look['guard']}: закон правителя — {$look['target']} раздета");
                        }
                        $verdict = 'раздета';
                        $db->execQuery("UPDATE public.tes_world_patrols SET enforced = true WHERE id = " . intval($look['id']));
                    } else {
                        $verdict = 'уже без одежды';
                    }
                    error_log("[tes_world] round: {$look['guard']} -> {$look['target']}: {$verdict}");
                    if ($verdict !== 'в темницу' && function_exists('tesCrimeNpcCommand')) {
                        tesCrimeNpcCommand(strval($look['guard']), 'Relax@');  // back to duty (a jailing guard escorts her)
                    }
                    $db->execQuery("UPDATE public.tes_world_patrols SET stage = 'done' WHERE id = " . intval($look['id']));
                }
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

            // 3. laws the round does not understand: the agent, quietly, at most once in 10 minutes.
            //    OFF since 2026-10-04 evening: 9 of 10 such rounds failed at the step limit and left
            //    half-done orders in the world (owner: "полный бред который нейронка ... делала").
            //    Set TES_WORLD_AGENT_PATROL to true to bring it back.
            $otherLaws = array_values(array_filter($tesWorldLaws, fn($l) => $l !== $nudeLaw));
            if (defined('TES_WORLD_AGENT_PATROL') && TES_WORLD_AGENT_PATROL && $otherLaws && function_exists('tesAgentStart') && function_exists('tesAgentRunningTask')) {
                $recentAgent = $db->fetchOne("SELECT 1 AS x FROM public.tes_world_patrols WHERE stage = 'agent' AND created_at > now() - interval '600 seconds' LIMIT 1");
                $near = tesWorldNearbyNames(20);
                if (empty($recentAgent) && !tesAgentRunningTask() && $guard !== '' && count($near) >= 2) {
                    $db->insert('tes_world_patrols', ['guard' => $guard, 'stage' => 'agent']);
                    $player = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
                    tesAgentStart("Патруль закона. Правитель — {$player}. Действующие законы:\n- " . implode("\n- ", $otherLaws)
                        . "\nNearby now: " . implode(', ', $near) . ". Guard on watch: {$guard}."
                        . " Check the adults in this list the law applies to. No violators - finish at once."
                        . " For a violator: order to guard {$guard} - demand aloud that the law be obeyed; then enforce by force what the law requires."
                        . " Whoever was already forced before and violates again - to jail (jail for 1 day). Do not touch children (race «Ребенок»), the player or the guards themselves. Kill nobody. Do not move the player. At most three violators per round.", false, false, true, true);
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_world post] ' . $e->getMessage());
}
