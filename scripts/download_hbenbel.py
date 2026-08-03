#!/usr/bin/env python3
"""Télécharge le dictionnaire français hbenbel dans data/raw/hbenbel/.

Source : https://github.com/hbenbel/French-Dictionary
Dépôt sous licence MIT, mais les données sont extraites de kaikki.org, lui-même
dérivé du Wiktionnaire : les obligations CC BY-SA s'appliquent aux données,
comme pour Kartmaan. L'attribution doit être portée sur le site.

Usage :
    python scripts/download_hbenbel.py
"""

from __future__ import annotations

import hashlib
import urllib.request
from pathlib import Path

BASE_URL = "https://raw.githubusercontent.com/hbenbel/French-Dictionary/master/dictionary/"
FILES = ("dictionary.csv", "adj.csv", "noun.csv", "verb.csv", "adv.csv")
DEST = Path(__file__).resolve().parents[1] / "data" / "raw" / "hbenbel"


def main() -> int:
    DEST.mkdir(parents=True, exist_ok=True)
    for name in FILES:
        target = DEST / name
        partial = target.with_suffix(target.suffix + ".part")
        with urllib.request.urlopen(BASE_URL + name) as response:
            payload = response.read()
        partial.write_bytes(payload)
        partial.replace(target)
        digest = hashlib.sha256(payload).hexdigest()
        print("%-16s %10d o  %s" % (name, len(payload), digest))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
