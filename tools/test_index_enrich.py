"""Checks a TSV built by tools/game_index.py (Part 7 of docs/design/god-narrator.md).

Usage: python tools/test_index_enrich.py <new.tsv> [<old.tsv>]
<old.tsv> is an index built by the previous game_index.py; with it the row counts of the old
kinds are compared (< 1 % difference), without it that check is skipped.
"""
import json
import sys
from collections import Counter

NEW_KINDS = {"race", "shout", "word"}
fails = []


def check(ok, msg):
    print(("ok   " if ok else "FAIL ") + msg)
    if not ok:
        fails.append(msg)


def load(path):
    rows = {}
    kinds = Counter()
    for line in open(path, encoding="utf-8"):
        f = line.rstrip("\n").split("\t")
        if len(f) != 8:
            continue
        kinds[f[1]] += 1
        try:
            extra = json.loads(f[5].replace("\\\\", "\\"))
        except ValueError:
            extra = {}
        rows[f[0]] = (f[1], f[2], f[3], extra)
    return rows, kinds


rows, kinds = load(sys.argv[1])
by_edid = {}
for fid, (kind, edid, _n, _x) in rows.items():
    by_edid.setdefault((kind, edid), fid)

hc = rows.get(by_edid.get(("npc", "HousecarlWhiterun")), (None,) * 4)[3]
check(hc.get("sex") == "F" and hc.get("unique") is True, "HousecarlWhiterun: sex F, unique")
check(bool(hc.get("race")) and bool(hc.get("fac")), "HousecarlWhiterun: race and fac present")

for who, edid in (("Lucia", "BYOHUrchin_Lucia"), ("Mila Valentia", "MilaValentia")):
    x = rows.get(by_edid.get(("npc", edid)), (None,) * 4)[3]
    check(x.get("child") is True, f"{who} ({edid}): child")

cell = rows.get(by_edid.get(("cell", "WhiterunBanneredMare")), (None,) * 4)[3]
loc_id = by_edid.get(("location", "WhiterunBanneredMareLocation"))
check(loc_id is not None and cell.get("loc") == loc_id, "cell WhiterunBanneredMare: loc = WhiterunBanneredMareLocation")
chain, cur = [], loc_id
while cur in rows and cur not in chain and len(chain) < 20:
    chain.append(cur)
    cur = rows[cur][3].get("parent")
check(any(rows[c][1] == "WhiterunLocation" for c in chain), "location parent chain reaches WhiterunLocation")

check(kinds["shout"] >= 20, f"shout rows: {kinds['shout']} (>= 20)")
check(kinds["word"] > 0 and kinds["race"] > 0, f"word rows {kinds['word']}, race rows {kinds['race']}")

if len(sys.argv) > 2:
    _r, old = load(sys.argv[2])
    for k, n in sorted(old.items()):
        d = abs(kinds[k] - n) / n
        check(d < 0.01, f"kind {k}: old {n}, new {kinds[k]}")
    # template-inherited traits (TPLT + ACBS template flags) against the previous build
    old_rows, _k = load(sys.argv[2])
    check(kinds["npc"] == old["npc"], f"npc rows equal to old: {kinds['npc']}")
    n_child = sum(1 for r in rows.values() if r[0] == "npc" and r[3].get("child"))
    check(n_child >= 53, f"child NPCs: {n_child} (>= 53)")
    changed = [(f, old_rows[f][3], r[3]) for f, r in rows.items()
               if r[0] == "npc" and f in old_rows
               and any(old_rows[f][3].get(k) != r[3].get(k) for k in ("sex", "race", "fac"))]
    print(f"NPC rows with changed sex/race/fac: {len(changed)}")
    for f, a, b in changed[:: max(1, len(changed) // 5)][:5]:
        print(f"  {f} {rows[f][1]}: " + ", ".join(
            f"{k} {a.get(k)} -> {b.get(k)}" for k in ("sex", "race", "fac") if a.get(k) != b.get(k)))
    check(len(changed) > 0, "some NPC traits changed by templates")
    gh = by_edid.get(("npc", "REQ_Bandit_Loc_CrackedTuskKeep_Ghunzul"))
    check(gh is not None and not old_rows[gh][3].get("fac") and rows[gh][3].get("fac") == ["0001BCC0"],
          "REQ_Bandit_Loc_CrackedTuskKeep_Ghunzul: no own factions, template faction 0001BCC0 now set")
    check(sum(1 for r in rows.values() if r[3].get("tpl") == "lvln") > 0, "some NPCs marked tpl=lvln")
    check(not any("_tf" in r[3] or "_tplt" in r[3] or "_lvlo" in r[3] for r in rows.values()), "no temp keys leaked")
else:
    print("skip row-count comparison (no old.tsv given)")

print("ALL OK" if not fails else f"{len(fails)} FAILED")
sys.exit(1 if fails else 0)
