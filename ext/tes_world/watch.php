<?php
/*
 * tes_world: the owner's watch over the setup (2026-10-04).
 *  - budget: the OpenRouter key's limit and usage every 10 minutes; a warning in the game when
 *    little is left; below 5 % the chatter between NPCs (RECHAT_P, BORED_EVENT) is switched off
 *    and put back when the limit is raised. Today the old key hit its limit at 15:24 and the game
 *    fell silent without a word.
 *  - self-check: a tes* command that the game's bridge does not know ("Script command ... not
 *    found") means the game runs an old bridge - told once an hour.
 *  - loyalty: fear/anger of a person towards the ruler grow with what is ordered done to him and
 *    fade by themselves; they colour how he answers an order.
 */

if (!function_exists('tesWatchNotify')) {
    function tesWatchNotify(string $text): void
    {
        if (function_exists('tesCrimeNotify')) {
            tesCrimeNotify($text);
        } elseif (function_exists('tesAgentNotify')) {
            tesAgentNotify($text);
        }
        error_log('[tes_world watch] ' . $text);
    }

    function tesWatchEnsure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = $GLOBALS['db'];
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_watch (key text PRIMARY KEY, value text NOT NULL DEFAULT '', updated_at timestamptz NOT NULL DEFAULT now())");
        $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_loyalty (npc text PRIMARY KEY, fear real NOT NULL DEFAULT 0, anger real NOT NULL DEFAULT 0, updated_at timestamptz NOT NULL DEFAULT now())");
    }

    function tesWatchGet(string $key): array
    {
        $r = $GLOBALS['db']->fetchOne("SELECT value, extract(epoch FROM now() - updated_at)::int AS age FROM public.tes_watch WHERE key = '" . $GLOBALS['db']->escape($key) . "'");
        return ['value' => strval($r['value'] ?? ''), 'age' => isset($r['age']) ? intval($r['age']) : PHP_INT_MAX];
    }

    function tesWatchSet(string $key, string $value): void
    {
        $db = $GLOBALS['db'];
        $db->execQuery("INSERT INTO public.tes_watch (key, value) VALUES ('" . $db->escape($key) . "', '" . $db->escape($value) . "') ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()");
    }

    function tesWatchTick(): void
    {
        tesWatchEnsure();
        $db = $GLOBALS['db'];
        // --- budget: every 10 minutes
        if (tesWatchGet('budget_check')['age'] >= 600) {
            tesWatchSet('budget_check', '1');
            $badge = $db->fetchOne("SELECT api_key FROM public.core_api_badge WHERE id = 1");
            $key = trim(strval($badge['api_key'] ?? ''));
            if (strpos($key, 'sk-or-') === 0) {
                $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 6, 'ignore_errors' => true, 'header' => "Authorization: Bearer {$key}\r\n"]]);
                $raw = @file_get_contents('https://openrouter.ai/api/v1/auth/key', false, $ctx);
                $j = is_string($raw) ? json_decode($raw, true) : null;
                $d = is_array($j) ? ($j['data'] ?? null) : null;
                if (!is_array($d)) {
                    tesWatchNotify('Ключ OpenRouter не отвечает: проверь ключ и лимит');
                } else {
                    $limit = isset($d['limit']) && $d['limit'] !== null ? floatval($d['limit']) : null;
                    $usage = floatval($d['usage'] ?? 0);
                    $left = $limit !== null ? ($d['limit_remaining'] ?? ($limit - $usage)) : null;
                    $left = $left !== null ? floatval($left) : null;
                    tesWatchSet('budget_state', json_encode(['limit' => $limit, 'usage' => $usage, 'left' => $left]));
                    if ($limit !== null && $limit > 0 && $left !== null) {
                        $share = $left / $limit;
                        $saved = tesWatchGet('chatter_saved')['value'];
                        if ($share < 0.05 && $saved === '') {
                            $meta = $db->fetchOne("SELECT metadata->>'RECHAT_P' AS p, metadata->>'BORED_EVENT' AS b FROM public.core_profiles WHERE id = 1");
                            tesWatchSet('chatter_saved', json_encode(['p' => $meta['p'] ?? '10', 'b' => $meta['b'] ?? '3']));
                            $db->execQuery("UPDATE public.core_profiles SET metadata = jsonb_set(jsonb_set(metadata, '{RECHAT_P}', '0'::jsonb), '{BORED_EVENT}', '0'::jsonb) WHERE id = 1");
                            tesWatchNotify(sprintf('Лимит ключа почти кончился (осталось $%.2f): болтовню NPC между собой выключил', $left));
                        } elseif ($share < 0.2 && tesWatchGet('budget_warn')['age'] >= 3600) {
                            tesWatchSet('budget_warn', '1');
                            tesWatchNotify(sprintf('Лимит ключа OpenRouter: осталось $%.2f из $%.2f', $left, $limit));
                        } elseif ($share >= 0.2 && $saved !== '') {
                            $s = json_decode($saved, true) ?: ['p' => '10', 'b' => '3'];
                            $db->execQuery("UPDATE public.core_profiles SET metadata = jsonb_set(jsonb_set(metadata, '{RECHAT_P}', '" . intval($s['p']) . "'::jsonb), '{BORED_EVENT}', '" . intval($s['b']) . "'::jsonb) WHERE id = 1");
                            tesWatchSet('chatter_saved', '');
                            tesWatchNotify('Лимит ключа вернулся: болтовню NPC включил обратно');
                        }
                    }
                }
            }
        }
        // --- the three guards who were calmed with aggression 0 (16:23) must fight again: tried once a
        // minute while they are out of reach ("not found"), up to 15 times
        $fix = tesWatchGet('aggr_fix');
        if (intval($fix['value']) > 0 && $fix['age'] >= 60 && function_exists('tesWorldQueue')) {
            tesWatchSet('aggr_fix', strval(intval($fix['value']) - 1));
            $cmds = [];
            foreach (['000D0FF9', '000D0FF8', '000D0FF7'] as $gr) {
                array_push($cmds, 'prid ' . $gr, 'setav aggression 1', 'resetai');
            }
            tesWorldQueue($cmds);
        }
        // --- impunity, for a game with the old bridge too: whoever has just gone for the player in
        // combat (the guards: bounty 200 in Whiterun, live 16:14-16:23) gets the bounty cleared and
        // is stopped. Owner: "какого хуя они меня пиздят". Every 20 s at most.
        if (tesWatchGet('impunity')['value'] !== '0' && tesWatchGet('imp_guard')['age'] >= 20) {
            $pn = trim(strval($GLOBALS['PLAYER_NAME'] ?? ''));
            $ev = $db->fetchAll("SELECT data FROM eventlog WHERE type = 'infoaction' AND localts > " . (time() - 90) . " AND data LIKE '%engages combat with%' ORDER BY rowid DESC LIMIT 12");
            $who = [];
            foreach (is_array($ev) ? $ev : [] as $e) {
                if (preg_match('/\)\s*(.+?) engages combat with (.+)$/u', strval($e['data']), $em) || preg_match('/^(.+?) engages combat with (.+)$/u', strval($e['data']), $em)) {
                    $target = trim($em[2]);
                    if ($target === 'The Narrator' || ($pn !== '' && $target === $pn)) {
                        $who[trim($em[1])] = true;
                    }
                }
            }
            if ($who) {
                tesWatchSet('imp_guard', '1');
                $cmds = [];
                if (tesBridgeVersion() >= 7) {
                    $cmds[] = 'tespeace';  // the whole cell stops, Whiterun's guards are allies of the player's faction
                }
                foreach (array_slice(array_keys($who), 0, 6) as $name) {
                    $ref = tesWorldRefOf($name);
                    if ($ref === '') {
                        continue;
                    }
                    $isGuard = (bool)preg_match('/Стражник|Командир|Хускарл/u', $name);
                    if (!$isGuard && function_exists('tesCrimeNearestGuard') && function_exists('tesWorldDuel')) {
                        // a townsman is beating the ruler (live 16:27, Анориат): the nearest guard goes for HIM
                        // (owner: "меня бьют а стража бездействует")
                        $defender = tesCrimeNearestGuard($name);
                        if ($defender !== '' && tesWorldDuel($defender, $name)) {
                            tesWatchNotify("Стража {$defender} бросилась на {$name}, напавшего на тебя");
                            continue;
                        }
                    }
                    array_push($cmds, 'prid ' . $ref, 'setcrimegold 0', 'stopcombat', 'resetai');
                }
                if ($cmds && function_exists('tesWorldQueue')) {
                    tesWorldQueue($cmds);
                    error_log('[tes_world watch] impunity: stopped ' . implode(', ', array_keys($who)));
                }
            }
        }
        // --- impunity: crimes of the player are not reported (Game.SetPlayerReportCrime) - on by
        // default, put again every 3 minutes (the flag is not kept by a save), needs bridge 4
        $imp = tesWatchGet('impunity');
        if ($imp['value'] !== '0' && tesWatchGet('impunity_sent')['age'] >= 180) {
            tesWatchSet('impunity_sent', '1');
            if (tesBridgeVersion() >= 4 && function_exists('tesWorldQueue')) {
                // + member of the Whiterun crime faction: its guards and citizens take him for one of their own
                // (live 16:14-16:35: they kept attacking him with the bounty at 0 and crime reporting off)
                tesWorldQueue(array_merge(['tesimpunity 1', 'player.addfac 000267EA 0'], tesBridgeVersion() >= 7 ? ['tespeace'] : []));
            }
        }
        // --- self-check: an old bridge in the game
        if (tesWatchGet('bridge_check')['age'] >= 3600) {
            $old = $db->fetchOne("SELECT command FROM public.tes_god_console_log WHERE created_at > now() - interval '10 minutes' AND output LIKE 'Script command \"tes%not found.%' ORDER BY id DESC LIMIT 1");
            if (!empty($old['command'])) {
                tesWatchSet('bridge_check', '1');
                tesWatchNotify('Игра работает на старом мосте (команда «' . mb_substr(strval($old['command']), 0, 30) . '» не найдена): перезапусти игру');
            }
        }
    }

    /**
     * Which bridge the game runs: 2+ knows "tesroutine at <ref>" and the strong teskill, 1 is the old
     * one (a command it does not know is answered "not found"; an old "tesroutine at N" would be
     * read as "here" and anchor the person to the player). Asked of the game, remembered 10 minutes.
     */
    function tesBridgeVersion(): int
    {
        tesWatchEnsure();
        $db = $GLOBALS['db'];
        $known = tesWatchGet('bridge_ver');
        if ($known['value'] !== '' && $known['age'] < 600) {
            return intval($known['value']);
        }
        $max = $db->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_god_console_log");
        if (function_exists('tesWorldQueue')) {
            tesWorldQueue(['tesversion']);
        }
        for ($i = 0; $i < 12; $i++) {
            usleep(400000);
            $r = $db->fetchOne("SELECT output FROM public.tes_god_console_log WHERE id > " . intval($max['m'] ?? 0) . " AND command = 'tesversion' ORDER BY id DESC LIMIT 1");
            if (!empty($r)) {
                $v = preg_match('/^\s*(\d+)\s*$/', strval($r['output']), $m) ? intval($m[1]) : 1;
                tesWatchSet('bridge_ver', strval($v));
                return $v;
            }
        }
        return $known['value'] !== '' ? intval($known['value']) : 1;  // no answer: the game is not listening - treat as old
    }

    /** Fear/anger of $npc towards the ruler, faded by 1 point per hour. */
    function tesLoyaltyOf(string $npc): array
    {
        tesWatchEnsure();
        $db = $GLOBALS['db'];
        $r = $db->fetchOne("SELECT fear, anger, extract(epoch FROM now() - updated_at) / 3600.0 AS hours FROM public.tes_loyalty WHERE npc = '" . $db->escape($npc) . "'");
        if (empty($r)) {
            return ['fear' => 0.0, 'anger' => 0.0];
        }
        $h = max(0.0, floatval($r['hours']));
        return ['fear' => max(0.0, floatval($r['fear']) - $h), 'anger' => max(0.0, floatval($r['anger']) - $h)];
    }

    function tesLoyaltyBump(string $npc, float $fear, float $anger): void
    {
        $cur = tesLoyaltyOf($npc);
        $db = $GLOBALS['db'];
        $f = min(10.0, $cur['fear'] + $fear);
        $a = min(10.0, $cur['anger'] + $anger);
        $db->execQuery("INSERT INTO public.tes_loyalty (npc, fear, anger) VALUES ('" . $db->escape($npc) . "', {$f}, {$a}) ON CONFLICT (npc) DO UPDATE SET fear = {$f}, anger = {$a}, updated_at = now()");
    }

    /** One line for the NPC's prompt, or ''. */
    function tesLoyaltyLine(string $npc): string
    {
        $l = tesLoyaltyOf($npc);
        $out = [];
        if ($l['fear'] >= 6) {
            $out[] = 'ты в ужасе от правителя: дрожишь, умоляешь, подчиняешься почти без слов';
        } elseif ($l['fear'] >= 3) {
            $out[] = 'ты побаиваешься правителя и подчиняешься с оглядкой';
        }
        if ($l['anger'] >= 6) {
            $out[] = 'ты ненавидишь правителя: огрызаешься, ворчишь сквозь зубы, тянешь время, но приказ всё же исполняешь';
        } elseif ($l['anger'] >= 3) {
            $out[] = 'ты затаил обиду на правителя и это слышно в голосе';
        }
        return $out ? 'Сейчас ' . implode('; ', $out) . '.' : '';
    }
}
