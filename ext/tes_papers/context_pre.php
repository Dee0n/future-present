<?php
/*
 * tes_papers: an NPC knows which papers the player handed over and what they say.
 *
 * Live 2026-10-04 00:48-00:56: the player gave the jarl four documents (eventlog "itemfound":
 * "Шаман gave 1 Купчая на дом to Ярл Балгруф Старший"), then for ten minutes heard "У меня нет
 * никаких твоих документов!". CHIM never shows an NPC the TEXT of a received paper, and the
 * hand-over line itself falls out of the short context history.
 * For the speaking NPC: papers received from the player (eventlog, survives only while that
 * save timeline does) + their text from the books table, at the bottom of the prompt.
 */

try {
    $tesPapersNpc = strval($GLOBALS['HERIKA_NAME'] ?? '');
    $tesPapersPlayer = strval($GLOBALS['PLAYER_NAME'] ?? '');
    if ($tesPapersNpc !== '' && $tesPapersNpc !== 'The Narrator' && $tesPapersPlayer !== ''
        && isset($GLOBALS['db']) && function_exists('chimRegisterPromptInjection')) {
        $db = $GLOBALS['db'];
        $rows = $db->fetchAll("SELECT data FROM eventlog WHERE type = 'itemfound' AND data LIKE '" . $db->escape($tesPapersPlayer)
            . " gave %' AND data LIKE '% to " . $db->escape($tesPapersNpc) . "%' ORDER BY rowid DESC LIMIT 40");
        $titles = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (preg_match('/ gave \d+ (.+?) to ' . preg_quote($tesPapersNpc, '/') . '/u', strval($r['data']), $m)) {
                $titles[trim($m[1])] = ($titles[trim($m[1])] ?? 0) + 1;
            }
        }
        $lines = [];
        $budget = 1800;
        foreach ($titles as $title => $count) {
            // only real papers: something the books table has text for
            $book = $db->fetchOne("SELECT content FROM books WHERE title = '" . $db->escape($title) . "' AND length(content) > 10 AND content NOT LIKE 'Title:%' ORDER BY length(content) DESC LIMIT 1");
            $content = trim(preg_replace('/\s+/u', ' ', strip_tags(strval($book['content'] ?? ''))) ?? '');
            if ($content === '') {
                try {  // the guard's own durable copy (tesGodGuardMakeDocument)
                    $own = $db->fetchOne("SELECT content FROM public.tes_documents WHERE title = '" . $db->escape($title) . "' ORDER BY id DESC LIMIT 1");
                    $content = trim(preg_replace('/\s+/u', ' ', strval($own['content'] ?? '')) ?? '');
                } catch (Throwable $e) {
                    $content = '';  // table not created yet
                }
            }
            if ($content === '') {
                // CHIM drops its books rows; the god guard's log still has the document command
                // ("….document <title>: <text>") for papers made through the Narrator / NPCs.
                $logged = $db->fetchOne("SELECT raw_text FROM public.tes_god_guard_log WHERE raw_text LIKE '%document " . $db->escape($title) . ":%' ORDER BY id DESC LIMIT 1");
                if (preg_match('/document\s+' . preg_quote($title, '/') . '\s*:\s*(.+?)(?=;\s*(?:player|\{npc|\{near)|$)/su', strval($logged['raw_text'] ?? ''), $dm)) {
                    $content = trim(preg_replace('/\s+/u', ' ', $dm[1]) ?? '');
                }
            }
            if ($content === '') {
                $lines[] = "«{$title}»" . ($count > 1 ? " ({$count} экз.)" : '') . ' (текст не сохранился на сервере — прочти бумагу сам и перескажи)';
                continue;
            }
            $content = mb_substr($content, 0, min(600, $budget));
            $budget -= mb_strlen($content);
            $lines[] = "«{$title}»" . ($count > 1 ? " ({$count} экз.)" : '') . ": {$content}";
            if ($budget <= 0) {
                break;
            }
        }
        if ($lines) {
            chimRegisterPromptInjection('prompt_bottom', 'tes_papers',
                "БУМАГИ, КОТОРЫЕ {$tesPapersPlayer} УЖЕ ПЕРЕДАЛ ТЕБЕ (они у тебя на руках, ты их прочёл; не говори, что их нет, и не проси передать снова):\n- "
                . implode("\n- ", $lines), 70);
        }
    }
} catch (Throwable $e) {
    error_log('[tes_papers] ' . $e->getMessage());
}
