<?php
/*
 * tes_world: the market of information (roadmap «Дальше» #5). "Продай мне тайну, вот 100 золота", "что знаешь? плачу
 * 200", "расскажи, что видел, заплачу" - said to a person with a sum: the gold goes to him (only what the player
 * carries) and he tells something TRUE: first what he saw himself (his [Помнит] memory), else a death the hold's
 * witnesses saw, else what another person secretly wants. Under 30 septims he scoffs. No model call beyond the line.
 */

if (!function_exists('tesInfoSpoken')) {
    /** A true secret this person can tell, or ''. */
    function tesInfoSecret(string $npc): string
    {
        $db = $GLOBALS['db'];
        $r = $db->fetchOne("SELECT coalesce(npc_static_bio, '') AS b FROM public.core_npc_master WHERE npc_name = '" . $db->escape($npc) . "' LIMIT 1");
        $bio = strval($r['b'] ?? '');
        $pos = mb_strpos($bio, "[Помнит]");
        if ($pos !== false) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", mb_substr($bio, $pos + 8)))));
            $seen = array_values(array_filter($lines, fn($l) => mb_strpos($l, 'Видел') !== false));
            $pick = $seen ? end($seen) : ($lines ? end($lines) : '');
            if ($pick !== '') {
                return 'what you know yourself: ' . trim($pick, " -");
            }
        }
        if (!empty($db->fetchOne("SELECT to_regclass('public.tes_witness_seen') AS t")['t'])) {
            $w = $db->fetchOne("SELECT deed FROM public.tes_witness_seen ORDER BY created_at DESC LIMIT 1");
            if (!empty($w['deed'])) {
                return 'what you heard from eyewitnesses: ' . $w['deed'];
            }
        }
        $g = $db->fetchOne("SELECT npc_name, coalesce(goals::text, '') AS g FROM public.core_npc_master WHERE npc_name <> '" . $db->escape($npc) . "' AND length(coalesce(goals::text, '')) > 20 AND race NOT ILIKE '%реб%' ORDER BY random() LIMIT 1");
        if (!empty($g['npc_name'])) {
            $parts = array_values(array_filter(array_map('trim', preg_split('/(?:^|\s)[*•\-]\s+|\n+/u', trim(preg_replace('/[\[\]{}"]+/u', ' ', strval($g['g'])) ?? '')) ?: [])));
            return "what {$g['npc_name']} secretly wants: " . mb_substr($parts[0] ?? strval($g['g']), 0, 160);
        }
        return '';
    }

    function tesInfoSpoken(string $line, string $to): string
    {
        if ($to === '' || stripos($to, 'Narrator') !== false) {
            return '';
        }
        $t = mb_strtolower(str_replace('ё', 'е', $line));
        $asks = preg_match('/(?<![\p{L}])(продай\s+(?:мне\s+)?(?:тайну|секрет\p{L}*|сведения|слух\p{L}*)|что\s+(?:ты\s+)?знаешь|что\s+(?:ты\s+)?видел\p{L}*|расскажи,?\s+что\s+(?:ты\s+)?(?:знаешь|видел\p{L}*)|выдай\s+(?:мне\s+)?тайну)/u', $t);
        $pays = preg_match('/(?<![\p{L}])(заплачу|плачу|вот\s+(?:тебе\s+)?\d|за\s+\d|держи\s+\d|\d+\s*(?:септим|золот|монет))/u', $t);
        if (!$asks || !$pays) {
            return '';
        }
        $n = tesWorldSpokenAmount($line);
        if ($n <= 0) {
            return '';
        }
        if ($n < 30) {
            return " *the jarl offers only {$n} septims for a secret - scoff: for such pennies you tell nothing*";
        }
        $gold = function_exists('tesWorldPlayerGold') ? tesWorldPlayerGold() : -1;
        if ($gold >= 0 && $gold < $n) {
            return " *the jarl carries only {$gold} septims but promises {$n} - do not take his word, demand the money up front*";
        }
        $ref = tesWorldRefOf($to);
        $secret = tesInfoSecret($to);
        if ($ref === '' || $secret === '') {
            return ' *you have nothing to sell - say honestly you know nothing, take no money*';
        }
        tesWorldQueue(['player.removeitem 0000000F ' . $n, 'prid ' . $ref, 'additem 0000000F ' . $n]);
        $GLOBALS['TES_COURT_NOW'] = true;  // not an order for the agent
        return " *you took {$n} septims (already yours); quietly tell {$secret} - in your own words, in a low voice*";
    }
}
