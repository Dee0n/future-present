"""Named references (EDID) inside one interior cell, with their 'initially disabled' flag
and enable parent. Usage: cellrefs.py <cell formid hex>"""
import struct, sys, zlib
sys.path.insert(0, r"\\wsl.localhost\DwemerAI4Skyrim3\home\dwemer\TES-Speech-Adapter\tools")
import game_index as gi
GAME = r"G:\SteamLibrary\steamapps\common\Skyrim Special Edition"
files = gi.mo2_files(GAME, "RFAD_SE")
cell_id = int(sys.argv[1], 16)
for name in ["Skyrim.esm", "HearthFires.esm", "Unofficial Skyrim Special Edition Patch.esp"]:
    data = open(files[name.lower()], "rb").read()
    hsize = struct.unpack_from("<I", data, 4)[0]

    def walk(off, end, incell):
        while off < end:
            typ = data[off:off + 4]; size = struct.unpack_from("<I", data, off + 4)[0]
            if typ == b"GRUP":
                label = data[off + 8:off + 12]; gtype = struct.unpack_from("<i", data, off + 12)[0]
                if gtype == 0 and label != b"CELL":
                    off += size; continue
                inside = incell
                if gtype in (6, 8, 9):
                    inside = (struct.unpack_from("<I", label)[0] & 0xFFFFFF) == cell_id
                    if gtype == 6 and not inside:
                        off += size; continue
                walk(off + 24, off + size, inside)
                off += size; continue
            flags, fid = struct.unpack_from("<II", data, off + 8)
            body = data[off + 24:off + 24 + size]; off += 24 + size
            if typ != b"REFR" or not incell: continue
            if flags & 0x40000:
                try: body = zlib.decompress(body[4:])
                except zlib.error: continue
            subs = list(gi.subrecords(body))
            edid = next((gi.text(v) for t, v in subs if t == b"EDID"), "")
            xesp = next((struct.unpack_from("<I", v)[0] for t, v in subs if t == b"XESP"), None)
            if edid or (flags & 0x800 and xesp is None):
                print(f"{name[:12]:12} {fid & 0xFFFFFF:06X} disabled={bool(flags & 0x800)!s:5} parent={('%06X' % (xesp & 0xFFFFFF)) if xesp else '-':6} {edid}")
    walk(24 + hsize, len(data), False)
