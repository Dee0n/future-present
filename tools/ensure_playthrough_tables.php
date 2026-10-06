<?php
/*
 * Create every table listed in ext/tes_world/playthrough_tables.txt (the plugins create them lazily) so that CHIM's
 * Playthrough Save finds them all. Run before applying patches/herika-playthrough-ext-tables.patch:
 *   runuser -u www-data -- php tools/ensure_playthrough_tables.php
 */
chdir('/var/www/html/HerikaServer');
require_once 'conf/conf.php';
require_once 'lib/' . ($GLOBALS['DBDRIVER'] ?? 'postgresql') . '.class.php';
require_once 'lib/data_functions.php';
$GLOBALS['db'] = new sql();
$X = dirname(__DIR__) . '/ext';
require "$X/tes_world/lib.php";
require_once "$X/tes_agent/lib.php";
tesAgentEnsureTable();
tesTreasuryEnsure();
tesRealmEnsure();
tesWatchEnsure();
tesCompanionEnsure();
tesWorldLivingEnsure();
tesSanguineEnsure();
tesPriceEnsure();
$db = $GLOBALS['db'];
$db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_agent_undo (id bigserial PRIMARY KEY, task_id bigint NOT NULL, command text NOT NULL, inverse text, undone boolean NOT NULL DEFAULT false, created_at timestamptz NOT NULL DEFAULT now())");
$db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_legends (id serial PRIMARY KEY, god text NOT NULL, deed text NOT NULL, created_at timestamptz NOT NULL DEFAULT now())");
$db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_witness_seen (key text PRIMARY KEY, deed text NOT NULL DEFAULT '', created_at timestamptz NOT NULL DEFAULT now())");
$db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_rumor_spread (src_id int NOT NULL, hold text NOT NULL, hop int NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (src_id, hold))");
$missing = [];
foreach (file("$X/tes_world/playthrough_tables.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $t = trim(preg_replace('/#.*$/', '', $l));
    if ($t === '') continue;
    $r = $db->fetchOne("SELECT to_regclass('public.{$t}') AS x");
    if (empty($r['x'])) $missing[] = $t;
}
echo $missing ? 'still missing (created on first use in game): ' . implode(', ', $missing) . "\n" : "all tables exist\n";
