<?php
/*
 * tes_world: facts of the world that every character knows.
 *
 * Owner, 2026-10-04: "я теперь ярл. почему мозг ебут мне?" - the Narrator jailed Balgruuf, but
 * nothing told the NPCs that the player now rules ("Да-да, ты ярл. И я дракон."). A title is not
 * a record in the game data (ROADMAP H): it is what people believe and act on. So it is kept
 * here and shown to every NPC and the Narrator at the bottom of the prompt.
 *
 * player.title <титул> (tes_god_guard) -> tesWorldSetTitle(). "player.title нет" clears it.
 */

if (!function_exists('tesWorldEnsureTable')) {
    function tesWorldEnsureTable(): void
    {
        $GLOBALS['db']->execQuery("
            CREATE TABLE IF NOT EXISTS public.tes_world_titles (
                key text PRIMARY KEY,
                fact text NOT NULL,
                gamets bigint NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        ");
    }

    /** Active facts. A fact dated after "now" (a save from before it was loaded) is not shown. */
    function tesWorldFacts(): array
    {
        $db = $GLOBALS['db'];
        $has = $db->fetchOne("SELECT to_regclass('public.tes_world_titles') AS t");
        if (empty($has['t'])) {
            return [];
        }
        $now = intval($GLOBALS['gameRequest'][2] ?? 0);
        $rows = $db->fetchAll("SELECT key, fact, gamets FROM public.tes_world_titles ORDER BY created_at");
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if ($now > 0 && intval($r['gamets']) > $now + 100000) {
                continue;
            }
            $out[$r['key']] = $r['fact'];
        }
        return $out;
    }

    /**
     * Names of the people around the player right now (near first, far ones last, max $max).
     * The player talks by voice and speech recognition mangles names ("из Ольда" for Изольда ->
     * a fine for Олфрид, "рилет", "ольхина", "Хеймс-кара" - live 2026-10-04); whoever has to
     * understand an order gets this list to match against.
     */
    function tesWorldNearbyNames(int $max = 14): array
    {
        $row = $GLOBALS['db']->fetchOne("SELECT data FROM eventlog WHERE type = 'infonpc_close' AND localts > " . (time() - 180) . " ORDER BY rowid DESC LIMIT 1");
        $near = [];
        $far = [];
        $player = mb_strtolower(strval($GLOBALS['PLAYER_NAME'] ?? ''));
        foreach (explode('/', strval($row['data'] ?? '')) as $name) {
            $isFar = mb_strpos($name, '(far away)') !== false;
            $name = trim(preg_replace('/\s*\((?:busy|far away)\)\s*/u', ' ', $name) ?? $name);
            $name = trim(preg_replace('/^\(?Context location:[^)]*\)\s*/u', '', $name) ?? $name);
            if ($name === '' || mb_strtolower($name) === $player || mb_strlen($name) > 60) {
                continue;
            }
            if ($isFar) {
                $far[] = $name;
            } else {
                $near[] = $name;
            }
        }
        return array_slice(array_values(array_unique(array_merge($near, $far))), 0, $max);
    }

    /** Set (or clear) the player's title. Returns [ok, message]. */
    function tesWorldSetTitle(string $title): array
    {
        tesWorldEnsureTable();
        $db = $GLOBALS['db'];
        $player = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
        $title = trim(str_replace(["\n", "\r", ';'], [' ', ' ', ','], $title));
        if ($title === '' || preg_match('/^(нет|никто|none|clear|снять)$/iu', $title)) {
            $db->execQuery("DELETE FROM public.tes_world_titles WHERE key = 'player_title'");
            return [true, "{$player}: титул снят"];
        }
        $now = intval($GLOBALS['gameRequest'][2] ?? 0);
        if ($now <= 0) {
            $row = $db->fetchOne("SELECT max(gamets) AS g FROM eventlog WHERE localts > " . (time() - 900));
            $now = intval($row['g'] ?? 0);
        }
        $fact = "{$player} — {$title}. Это признано и известно всем: стража, двор и жители подчиняются ему как носителю этого титула, "
            . 'обращаются к нему соответственно, его слово в делах этого титула — приказ. Прежний носитель титула власти больше не имеет. '
            . 'Его приказы исполняют, а не обсуждают: кто виновен и что справедливо, решает он; отказ, спор о законности или нравоучение в ответ на приказ — неповиновение.';
        $db->execQuery("INSERT INTO public.tes_world_titles (key, fact, gamets) VALUES ('player_title', '" . $db->escape($fact) . "', {$now})
            ON CONFLICT (key) DO UPDATE SET fact = EXCLUDED.fact, gamets = EXCLUDED.gamets, created_at = now()");
        $extra = '';
        if (function_exists('tesGodGuardAddRumor')) {
            $hold = tesGodGuardAddRumor("{$player} теперь {$title}. Это объявлено во всеуслышание.");
            $extra = "; по холду {$hold} пошла весть";
        }
        return [true, "{$player} теперь {$title} — это знают все персонажи{$extra}"];
    }
}
