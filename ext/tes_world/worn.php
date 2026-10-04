<?php
/*
 * tes_world: the truth about clothes (owner, 23:04: "пиздят они насчёт одежды").
 * The model of an NPC says "я разделась" / "я уже оделся" by mood: it does not know what is on the body. The game
 * does ("tesstate" prints "worn BODY=…"). Here the answers are remembered per NPC and told back in his prompt:
 *   - the player speaks to an NPC: his state is asked of the game (at most once in 20 s per person);
 *   - every game request: new "tesstate" answers are read into public.tes_npc_worn;
 *   - his prompt: "по-настоящему на тебе сейчас: …" or "на тебе НЕТ одежды" (a state younger than 15 minutes).
 * And the laws are kept in order: a list on request, a repeal by number or by topic, no junk laws.
 */

if (!function_exists('tesWornAsk')) {
    function tesWornEnsure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $GLOBALS['db']->execQuery("CREATE TABLE IF NOT EXISTS public.tes_npc_worn (npc text PRIMARY KEY, naked boolean NOT NULL, worn text NOT NULL DEFAULT '', at timestamptz NOT NULL DEFAULT now())");
    }

    /** The game prints a name with or without its "[post]" tag: one key for both. */
    function tesWornKey(string $name): string
    {
        return trim(preg_replace('/\s*\[[^\]]*\]/u', '', $name) ?? $name);
    }

    /**
     * Called by tesWorldQueue: a sequence that changes what somebody wears makes every remembered state older than
     * it a guess (better silence than "ты одет" a second after unequipall), and the game is asked again right after.
     */
    function tesWornTouched(array $commands): array
    {
        $extra = [];
        $ref = '';
        foreach ($commands as $c) {
            $c = strval($c);
            if (preg_match('/^prid\s+([0-9A-Fa-f]{8})$/', $c, $m)) {
                $ref = strtoupper($m[1]);
            } elseif ($ref !== '' && preg_match('/^(unequipall|unequipitem|equipitem|removeallitems|tesdressbest|tesswapworn|tesoutfit|teslove)\b/i', $c)) {
                $extra[$ref] = true;
            }
        }
        if (!$extra) {
            return $commands;
        }
        tesWatchSet('worn_dirty', '1');
        foreach (array_keys($extra) as $r) {
            $commands[] = 'prid ' . $r;
            $commands[] = 'tesstate';
        }
        return $commands;
    }

    /** Ask the game what $npc wears now (the answer is read by tesWornIngest on a later request). */
    function tesWornAsk(string $npc): void
    {
        $npc = trim($npc);
        if ($npc === '' || stripos($npc, 'Narrator') !== false || tesWorldIsChild($npc)) {
            return;
        }
        $key = 'worn_ask_' . substr(md5($npc), 0, 10);
        $last = tesWatchGet($key);
        if ($last['value'] !== '' && $last['age'] < 20) {
            return;
        }
        $ref = tesWorldRefOf($npc);
        if ($ref === '') {
            return;
        }
        tesWatchSet($key, '1');
        tesWorldQueue(['prid ' . $ref, 'tesstate']);
    }

    /** Read the new "tesstate" answers of the game into tes_npc_worn. */
    function tesWornIngest(): void
    {
        tesWornEnsure();
        $db = $GLOBALS['db'];
        $last = tesWatchGet('worn_last_id');
        $from = $last['value'] !== '' ? intval($last['value']) : intval(($db->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_god_console_log")['m'] ?? 0)) - 200;
        $rows = $db->fetchAll("SELECT id, output FROM public.tes_god_console_log WHERE id > " . max(0, $from) . " AND command = 'tesstate' ORDER BY id LIMIT 60");
        $maxId = $from;
        foreach (is_array($rows) ? $rows : [] as $r) {
            $maxId = max($maxId, intval($r['id']));
            $out = strval($r['output']);
            $name = tesWornKey(explode(';', $out, 2)[0]);
            if ($name === '' || mb_strlen($name) > 70 || strpos($out, '; worn') === false && strpos($out, 'level') === false) {
                continue;
            }
            $worn = preg_match('/;\s*worn\s*(.*)$/us', $out, $m) ? trim($m[1]) : '';
            $worn = trim(preg_replace('/;\s*right=.*$/us', '', $worn) ?? $worn);
            $naked = strpos($worn, 'BODY=') === false;
            $db->execQuery("INSERT INTO public.tes_npc_worn (npc, naked, worn, at) VALUES ('" . $db->escape($name) . "', " . ($naked ? 'true' : 'false') . ", '" . $db->escape(mb_substr($worn, 0, 300)) . "', now())"
                . " ON CONFLICT (npc) DO UPDATE SET naked = EXCLUDED.naked, worn = EXCLUDED.worn, at = now()");
        }
        if ($maxId > $from || $last['value'] === '') {
            tesWatchSet('worn_last_id', strval($maxId));
        }
    }

    /** One line of truth for the prompt of $me, or ''. */
    function tesWornLine(string $me): string
    {
        tesWornEnsure();
        $db = $GLOBALS['db'];
        $row = $db->fetchOne("SELECT naked, worn, extract(epoch from now() - at)::int AS age FROM public.tes_npc_worn WHERE npc = '" . $db->escape(tesWornKey($me)) . "'");
        if (empty($row) || intval($row['age']) > 900) {
            return '';
        }
        // somebody's clothes were changed after this was seen (and the game has not answered yet): say nothing
        $dirty = tesWatchGet('worn_dirty');
        if ($dirty['value'] !== '' && $dirty['age'] <= intval($row['age'])) {
            return '';
        }
        $mins = max(0, intval(round(intval($row['age']) / 60)));
        $ago = $mins <= 0 ? 'только что проверено' : "проверено {$mins} мин назад";
        if (filter_var($row['naked'], FILTER_VALIDATE_BOOLEAN)) {
            return "ПРАВДА О ТВОЕЙ ОДЕЖДЕ ({$ago}): на тебе сейчас НЕТ одежды и брони — ты раздет(а). Не говори, что одет(а) или оделся/оделась; если тебя просят одеться, а вещей у тебя нет — скажи, как есть.";
        }
        $worn = preg_replace('/#\d+/', '', strval($row['worn'])) ?? '';
        $worn = trim(preg_replace('/\s*(BODY|Feet|Hands|Circlet|Amulet|ring)=/u', ', ', ' ' . $worn) ?? $worn, ' ,');
        return "ПРАВДА О ТВОЕЙ ОДЕЖДЕ ({$ago}): на тебе надето: {$worn}. Ты НЕ раздет(а); не говори, что разделся/разделась или что на тебе ничего нет, если только это не обман, о котором ты знаешь сам.";
    }

    // ---------------------------------------------------------------- the laws in order

    /** A numbered list of the laws in force, for the ruler's question. */
    function tesLawsList(): string
    {
        $laws = array_values(tesWorldLaws());
        if (!$laws) {
            return ' *законов сейчас нет*';
        }
        $out = [];
        foreach ($laws as $i => $fact) {
            $out[] = ($i + 1) . '. ' . trim(preg_replace('/^Закон правителя \([^)]*\):\s*/u', '', $fact) ?? $fact);
        }
        return ' *действующие законы: ' . implode(' | ', $out) . '*';
    }

    /** "Отмени закон номер 2" / "отмени закон про раздевание" - returns a note or ''. */
    function tesLawsRepeal(string $t): string
    {
        $keys = array_keys(tesWorldLaws());
        if (!$keys) {
            return '';
        }
        $drop = [];
        if (preg_match('/(?:закон\s+)?(?:номер|№|n)\s*(\d{1,2})|закон\s+(\d{1,2})(?![\p{L}\d])/u', $t, $m)) {
            $i = intval($m[1] !== '' ? $m[1] : ($m[2] ?? 0));
            if ($i >= 1 && $i <= count($keys)) {
                $drop[] = $keys[$i - 1];
            }
        } elseif (preg_match('/закон\p{L}*\s+(?:про|о|об|насчет|насчёт)\s+(\p{L}{4,})/u', $t, $m)) {
            $stem = mb_substr($m[1], 0, max(4, min(6, mb_strlen($m[1]) - 2)));  // "раздевании" -> "раздев" (the law says "раздевает")
            foreach (tesWorldLaws() as $k => $fact) {
                if (mb_strpos(mb_strtolower($fact), $stem) !== false) {
                    $drop[] = $k;
                }
            }
        }
        if (!$drop) {
            return '';
        }
        $db = $GLOBALS['db'];
        foreach ($drop as $k) {
            $db->execQuery("DELETE FROM public.tes_world_titles WHERE key = '" . $db->escape($k) . "'");
        }
        return ' *закон отменён (' . count($drop) . '); это уже сделано*';
    }

    /** Orders to "execute the law" and the like are not laws: they came from the agent's own wording. */
    function tesLawsIsJunk(string $law): bool
    {
        return (bool)preg_match('/^\s*(приказать|приказываю|исполнить|исполнять|выполнить|выполнять|следить\s+за\s+исполнением|заставить)\p{L}*/iu', $law)
            || (bool)preg_match('/(исполнить|исполнять|выполнить)\s+(новый\s+)?закон/iu', $law);
    }
}
