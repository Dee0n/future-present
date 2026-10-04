import io, sys
p = sys.argv[1]
s = io.open(p, encoding='utf-8').read()
if 'tesWorldIsChild' in s:
    print('already patched'); sys.exit(0)
a = "    if (in_array(strtolower($actorName), aiagentNsfwChildNameBlocklist(), true)) { return true; }"
assert a in s, 'anchor 1'
s = s.replace(a, a + """
    // TES-Speech-Adapter (2026-10-04): a Russian game - the race is «Ребенок», names are Cyrillic,
    // so the English blocklist and "child" in the race never match. The adapter's own checks
    // (tes_world / tes_god_guard) and CHIM's NPC table decide too.
    if (function_exists('tesWorldIsChild') && tesWorldIsChild($actorName)) { return true; }
    if (function_exists('tesGodGuardIsChild') && tesGodGuardIsChild('{npc:' . $actorName . '}')) { return true; }
    if (isset($GLOBALS['db'])) {
        $tesRow = $GLOBALS['db']->fetchOne("SELECT race FROM core_npc_master WHERE npc_name = '" . $GLOBALS['db']->escape($actorName) . "' LIMIT 1");
        $tesRace = (string)($tesRow['race'] ?? '');
        if ($tesRace !== '' && (mb_stripos($tesRace, 'ребен') !== false || mb_stripos($tesRace, 'ребён') !== false || stripos($tesRace, 'child') !== false)) { return true; }
    }""", 1)
b = "            if ($race !== '' && strpos($race, 'child') !== false) { return true; }"
assert b in s, 'anchor 2'
s = s.replace(b, "            if ($race !== '' && (strpos($race, 'child') !== false || mb_stripos($race, 'ребен') !== false || mb_stripos($race, 'ребён') !== false)) { return true; }", 1)
io.open(p + '.orig-before-tes', 'w', encoding='utf-8').write(io.open(p, encoding='utf-8').read())
io.open(p, 'w', encoding='utf-8', newline='\n').write(s)
print('patched')
