<?php
/*
 * tes_world: overhearing and an economy that answers the rule (roadmap «Дальше» #4, #8).
 *  - What people say to EACH OTHER near the ruler (eventlog "chat", "(talking to X)" between two persons) - the
 *    juiciest line of the last minutes (the ruler, executions, gold, secrets, love, plots) becomes a rumour of the hold
 *    and a memory of the place. At most once in 10 minutes; children's talk is never spread.
 *  - The tax: at 150 % and more the traders grumble and drink costs more; executions of the last day drive traders out
 *    of town (fewer pay the tax - court.php tesTreasuryTax reads tesEconomyTraderLoss()).
 * No model call.
 */

if (!function_exists('tesOverhearTick')) {
    function tesOverhearTick(): void
    {
        $mark = sys_get_temp_dir() . '/tes_overhear.ts';
        if (time() - intval(@file_get_contents($mark)) < 300) {
            return;
        }
        @file_put_contents($mark, strval(time()));
        tesWatchEnsure();
        if (tesWatchGet('overhear_at')['age'] < 600) {
            return;
        }
        $db = $GLOBALS['db'];
        $player = trim(strval($GLOBALS['PLAYER_NAME'] ?? ''));
        $rows = $db->fetchAll("SELECT data FROM eventlog WHERE type = 'chat' AND localts > " . (time() - 600) . " ORDER BY rowid DESC LIMIT 60");
        $best = null;
        $bestScore = 0;
        foreach (is_array($rows) ? $rows : [] as $r) {
            $d = strval($r['data']);
            if (!preg_match('/^(?:\([^)]*\)\s*)?([^:()]{2,60}):\s*(.{12,220}?)\s*\(talking to ([^)]{2,60})\)\s*$/us', $d, $m)) {
                continue;
            }
            [$who, $line, $to] = [trim($m[1]), trim($m[2]), trim($m[3])];
            if ($who === $player || $to === $player || stripos($who . $to, 'Narrator') !== false) {
                continue;  // only what two persons said to each other, not to the ruler
            }
            $wr = tesWorldRefOf($who);
            if ($wr === '' || tesChildSafeIsChildRef($wr)) {
                continue;
            }
            $l = mb_strtolower($line);
            $score = preg_match_all('/(ярл|шаман|казн|казнил|золот|септим|тайн|секрет|убил|убийц|люблю|любит|ненавиж|заговор|предат|украл|сбеж|боюсь|спит\s+с|измен|деньги)/u', $l);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$who, $to, $line];
            }
        }
        if ($best === null || $bestScore < 2 || !tesWorldNeedGuard()) {
            return;
        }
        tesWatchSet('overhear_at', '1');
        [$who, $to, $line] = $best;
        tesGodGuardAddRumor("Подслушали, как {$who} говорил {$to}: «" . mb_substr(trim($line, ' .'), 0, 160) . '».');
        if (function_exists('tesWorldRememberPlace')) {
            tesWorldRememberPlace("{$who} шептался тут с {$to}: «" . mb_substr($line, 0, 100) . '»');
        }
    }

    /** Traders gone from town after the executions of the last day (0-6). */
    function tesEconomyTraderLoss(): int
    {
        $db = $GLOBALS['db'];
        $n = 0;
        try {
            if (!empty($db->fetchOne("SELECT to_regclass('public.tes_world_duels') AS t")['t'])) {
                $db->execQuery("ALTER TABLE public.tes_world_duels ADD COLUMN IF NOT EXISTS sentence boolean NOT NULL DEFAULT true");
                $r = $db->fetchOne("SELECT count(*) AS n FROM public.tes_world_duels WHERE sentence AND created_at > now() - interval '1 day'");
                $n += intval($r['n'] ?? 0);
            }
            $r = $db->fetchOne("SELECT count(*) AS n FROM public.tes_agent_tasks WHERE status = 'fast' AND goal LIKE 'kill: %' AND created_at > now() - interval '1 day'");
            $n += intval($r['n'] ?? 0);
        } catch (Throwable $e) {
        }
        return min(6, intdiv($n, 2));
    }

    /** For a trader's prompt: what the tax and the executions do to his trade, or ''. */
    function tesEconomyLine(string $me): string
    {
        if ($me === '' || stripos($me, 'Narrator') !== false || empty(tesWorldFacts()['player_title'])) {
            return '';
        }
        $db = $GLOBALS['db'];
        $o = $db->fetchOne("SELECT coalesce(occupation::text, '') AS o FROM public.core_npc_master WHERE npc_name = '" . $db->escape($me) . "' LIMIT 1");
        if (!preg_match('/(торгов|купец|лавк|merchant|trader|shopkeeper|innkeeper|трактир)/iu', strval($o['o'] ?? ''))) {
            return '';
        }
        $rate = tesWatchGet('tax_rate')['value'];
        $rate = $rate === '' ? 100 : intval($rate);
        $loss = tesEconomyTraderLoss();
        $parts = [];
        if ($rate >= 150) {
            $parts[] = "the ruler's {$rate}% tax chokes trade - you grumble about it and raised your prices";
        } elseif ($rate <= 50) {
            $parts[] = "the ruler's tax is only {$rate}% - trade is brisk, you are content";
        }
        if ($loss >= 2) {
            $parts[] = 'after the recent executions several traders left town, buyers are fewer, you fear for your shop';
        }
        return $parts ? 'You are a trader: ' . implode('; ', $parts) . '.' : '';
    }

    /** At a high tax drink costs more (services.php prices, bridge 16): ale 1.5x while the tax stays >= 150 %. */
    function tesEconomyTick(): void
    {
        if (!function_exists('tesPriceSet') || !function_exists('tesBridgeVersion') || tesBridgeVersion() < 16) {
            return;
        }
        if (tesWatchGet('econ_check')['age'] < 900) {
            return;
        }
        tesWatchSet('econ_check', '1');
        $rate = tesWatchGet('tax_rate')['value'];
        if ($rate !== '' && intval($rate) >= 150) {
            tesPriceSet('00034C5E', 'Эль', 8, 'высокий налог', 30);
        }
    }
}
