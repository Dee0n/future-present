<?php
/*
 * tes_papers helpers: which papers the player handed to an NPC (eventlog "itemfound").
 */

if (!function_exists('tesPapersReceived')) {
    /** [title => count] of things the player gave this NPC that are papers (not gear/food). */
    function tesPapersReceived(string $npc, string $player): array
    {
        $db = $GLOBALS['db'];
        $rows = $db->fetchAll("SELECT data FROM eventlog WHERE type = 'itemfound' AND data LIKE '" . $db->escape($player)
            . " gave %' AND data LIKE '% to " . $db->escape($npc) . "%' ORDER BY rowid DESC LIMIT 40");
        $titles = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            // CHIM values every spawned note at "(value 5 gold)" / 10000 on the shared base; real
            // items are filtered below by "is there a document with this title"
            if (preg_match('/ gave (\d+) (.+?) to ' . preg_quote($npc, '/') . '/u', strval($r['data']), $m)) {
                $titles[trim($m[2])] = ($titles[trim($m[2])] ?? 0) + intval($m[1]);
            }
        }
        return $titles;
    }

    /** Text of a paper by title: CHIM books -> own tes_documents -> the god guard's log. '' if lost. */
    function tesPapersText(string $title): string
    {
        $db = $GLOBALS['db'];
        $t = $db->escape($title);
        $book = $db->fetchOne("SELECT content FROM books WHERE title = '{$t}' AND length(content) > 10 AND content NOT LIKE 'Title:%' ORDER BY length(content) DESC LIMIT 1");
        $content = trim(preg_replace('/\s+/u', ' ', strip_tags(strval($book['content'] ?? ''))) ?? '');
        if ($content === '') {
            $own = $db->fetchOne("SELECT content FROM public.tes_documents WHERE title = '{$t}' ORDER BY id DESC LIMIT 1");
            $content = trim(preg_replace('/\s+/u', ' ', strval($own['content'] ?? '')) ?? '');
        }
        if ($content === '') {
            $logged = $db->fetchOne("SELECT raw_text FROM public.tes_god_guard_log WHERE raw_text LIKE '%document {$t}:%' ORDER BY id DESC LIMIT 1");
            if (preg_match('/document\s+' . preg_quote($title, '/') . '\s*:\s*(.+?)(?=;\s*(?:player|\{npc|\{near)|$)/su', strval($logged['raw_text'] ?? ''), $dm)) {
                $content = trim(preg_replace('/\s+/u', ' ', $dm[1]) ?? '');
            }
        }
        return $content;
    }

    /** Is this title a paper (a document/letter/diary), not a sword? */
    function tesPapersIsPaper(string $title): bool
    {
        $db = $GLOBALS['db'];
        $t = $db->escape($title);
        $hit = $db->fetchOne("SELECT 1 AS x FROM books WHERE title = '{$t}' LIMIT 1");
        if (!empty($hit)) {
            return true;
        }
        $hit = $db->fetchOne("SELECT 1 AS x FROM public.tes_documents WHERE title = '{$t}' LIMIT 1");
        return !empty($hit);
    }
}
