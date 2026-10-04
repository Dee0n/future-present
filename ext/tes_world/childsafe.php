<?php
/*
 * Children are never undressed - enforced where the commands leave for the game, whoever built the sequence.
 * Live 2026-10-04: Люсия (a child) stood without clothes: the jail's own check asked a function of another plugin
 * ("function_exists('tesGodGuardIsChild') && …") and, when that plugin was not loaded, took her for an adult.
 * Every queue of ours runs its commands through tesChildSafeCommands(): after "prid <a child>" anything that takes
 * clothes off or starts a scene is dropped. Included by tes_world/lib.php and tes_crime/lib.php.
 */

if (!function_exists('tesChildSafeCommands')) {
    function tesChildSafeIsChildRef(string $ref): bool
    {
        static $cache = [];
        $ref = strtoupper(trim($ref));
        if (!isset($cache[$ref])) {
            $db = $GLOBALS['db'];
            $row = $db->fetchOne("SELECT race FROM public.core_npc_master WHERE upper(refid) = '" . $db->escape($ref) . "' LIMIT 1");
            $cache[$ref] = (bool)preg_match('/реб[её]нок|child/iu', strval($row['race'] ?? ''));
        }
        return $cache[$ref];
    }

    function tesChildSafeCommands(array $commands): array
    {
        $out = [];
        $child = false;
        foreach ($commands as $c) {
            $s = trim(strval($c));
            if (preg_match('/^prid\s+([0-9A-Fa-f]{8})$/', $s, $m)) {
                $child = tesChildSafeIsChildRef($m[1]);
            } elseif (preg_match('/^player\./i', $s)) {
                // a command on the player, not on the selected one
            } elseif ($child && preg_match('/^(unequipall|unequipitem|removeallitems|removeitem|tesjailbox\s+in|teslove|tesswapworn|tesgive)\b/i', $s)) {
                error_log('[childsafe] dropped for a child: ' . $s);
                continue;
            }
            // "teslove <partner>", "tesswapworn <other>": the other one must not be a child either
            if (preg_match('/^(teslove|tesswapworn)\s+(\d{3,10})\b/i', $s, $m) && tesChildSafeIsChildRef(sprintf('%08X', intval($m[2])))) {
                error_log('[childsafe] dropped, the other one is a child: ' . $s);
                continue;
            }
            $out[] = $c;
        }
        return $out;
    }
}
