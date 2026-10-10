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
else:
    print("skip row-count comparison (no old.tsv given)")

print("ALL OK" if not fails else f"{len(fails)} FAILED")
sys.exit(1 if fails else 0)
