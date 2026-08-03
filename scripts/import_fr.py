#!/usr/bin/env python3
"""Construit storage/dictionary_fr.sqlite depuis les trois sources françaises.

Hors ligne uniquement (D-007). La base est recréée intégralement à chaque
exécution : elle n'est jamais mise à jour en place. Deux exécutions successives
produisent des rapports au sha256 identique — c'est un test de la porte Phase 0.

Ordre de fusion, conforme à docs/03_SOURCES_ET_IMPORT_DATA.md §4 :

    1. formes Kartmaan filtrées      is_french = 1
    1 bis. formes hbenbel filtrées   is_french = 1   (D-014)
    2. ODS8                          is_ods8 = 1, is_ods9 = 1, is_french = 1
    3. retraits ODS9                 is_ods9 = 0
    4. keep_overrides ODS9           is_ods9 = 1
    5. additions ODS9                is_ods9 = 1, is_ods8 = 0 si absent d'ODS8
    6. score, length, signature, reversed
    7. index, ANALYZE, VACUUM, integrity_check
    8. rapports

Usage :
    python scripts/import_fr.py [--dry-run]
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import sqlite3
import sys
from collections import Counter, defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from lib.normalize import (  # noqa: E402
    MIN_LENGTH,
    is_valid,
    normalize,
    reverse,
    score,
    signature,
)

ROOT = Path(__file__).resolve().parents[1]
ODS8_PATH = ROOT / "data" / "raw" / "ods8.json"
KARTMAAN_PATH = ROOT / "data" / "raw" / "french_dict.db"
HBENBEL_DIR = ROOT / "data" / "raw" / "hbenbel"
HBENBEL_FILES = ("dictionary.csv", "adj.csv", "noun.csv", "verb.csv", "adv.csv")
ODS9_PATH = ROOT / "data" / "ods9" / "ods9_patch.sqlite"
SCHEMA_PATH = ROOT / "schema.sql"
TARGET_PATH = ROOT / "storage" / "dictionary_fr.sqlite"
REPORTS = ROOT / "reports"

# pos Kartmaan écartés : noms propres et locutions.
# Le filtre s'applique LIGNE par ligne, pas forme par forme — une forme est
# retenue dès qu'au moins une de ses lignes passe. « école » porte une ligne NP
# et une ligne N : elle doit être conservée.
POS_PROPER_NOUN = "NP"


def sha256_of(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def rejection_rule(form: str, pos: str | None, normalized: str) -> str | None:
    """Renvoie la règle de rejet, ou None si la forme est retenue.

    L'ordre des tests est significatif : il détermine sous quelle règle un rejet
    est comptabilisé quand plusieurs s'appliquent.
    """
    if pos == POS_PROPER_NOUN:
        return "nom propre (pos = NP)"
    if pos and pos.lower().startswith("loc"):
        return "locution (pos = Loc*/loc-*)"
    if " " in form:
        return "espace"
    if "-" in form:
        return "trait d'union"
    if "'" in form or "’" in form:
        return "apostrophe"
    if any(ch.isdigit() for ch in form):
        return "chiffre"
    if len(normalized) < MIN_LENGTH:
        return "moins de %d lettres" % MIN_LENGTH
    if not is_valid(normalized):
        return "caractere hors A-Z apres normalisation"
    return None


def load_ods8() -> list[str]:
    with ODS8_PATH.open(encoding="utf-8") as handle:
        words = json.load(handle)["words"]
    if not all(is_valid(word) for word in words):
        raise SystemExit("ODS8 contient des formes non conformes a ^[A-Z]{2,}$")
    return words


def load_ods9() -> dict[str, list[str]]:
    connection = sqlite3.connect("file:%s?mode=ro" % ODS9_PATH.as_posix(), uri=True)
    try:
        integrity = connection.execute("PRAGMA integrity_check").fetchone()[0]
        if integrity != "ok":
            raise SystemExit("ods9_patch.sqlite : integrity_check = %s" % integrity)
        return {
            table: sorted(
                row[0] for row in connection.execute(
                    "SELECT normalized FROM %s" % table
                )
            )
            for table in ("additions", "removals", "keep_overrides")
        }
    finally:
        connection.close()


def load_kartmaan() -> tuple[dict[str, set[str]], Counter, list[tuple[str, str, str]]]:
    """Retourne (formes retenues par clé normalisée, compteur de rejets, échantillon).

    Le parcours est trié par (forme, id) pour rester déterministe quel que soit
    l'ordre physique des pages SQLite.
    """
    connection = sqlite3.connect("file:%s?mode=ro" % KARTMAAN_PATH.as_posix(), uri=True)
    kept: dict[str, set[str]] = defaultdict(set)
    rejected: Counter = Counter()
    samples: list[tuple[str, str, str]] = []
    seen_rejected: set[tuple[str, str]] = set()
    try:
        query = "SELECT forme, pos FROM mots ORDER BY forme, id"
        for form, pos in connection.execute(query):
            normalized = normalize(form)
            rule = rejection_rule(form, pos, normalized)
            if rule is not None:
                rejected[rule] += 1
                key = (rule, form)
                if key not in seen_rejected:
                    seen_rejected.add(key)
                    samples.append((rule, form, pos or ""))
                continue
            kept[normalized].add(form)
    finally:
        connection.close()
    samples.sort()
    return kept, rejected, samples


def load_hbenbel() -> tuple[dict[str, set[str]], Counter, list[tuple[str, str, str]]]:
    """Charge le dictionnaire hbenbel (D-014).

    hbenbel n'a pas d'étiquette `NP` : ses noms propres et ses sigles sont noyés
    dans noun.csv. La CASSE de la forme d'origine est le seul marqueur
    disponible — `Aberdonien`, `Ewok`, `ADN`, `AVC` commencent par une
    majuscule, un nom commun français jamais. Cette règle implémente donc les
    exclusions « noms propres » et « sigles » exigées par docs/03 §5.
    """
    forms: dict[str, set[str]] = defaultdict(set)
    rejected: Counter = Counter()
    samples: list[tuple[str, str, str]] = []
    seen_rejected: set[tuple[str, str]] = set()
    origins: dict[str, set[str]] = defaultdict(set)

    for name in HBENBEL_FILES:
        path = HBENBEL_DIR / name
        category = path.stem
        with path.open(encoding="utf-8", newline="") as handle:
            if category == "dictionary":
                # Liste plate, une forme par ligne, sans en-tête.
                raw_forms = (line.strip() for line in handle)
            else:
                raw_forms = (
                    (row.get("form") or "").strip() for row in csv.DictReader(handle)
                )
            for form in raw_forms:
                if form:
                    origins[form].add(category)

    for form in sorted(origins):
        normalized = normalize(form)
        rule = rejection_rule(form, None, normalized)
        if rule is None and form[:1].isupper():
            rule = "majuscule initiale (nom propre ou sigle)"
        if rule is not None:
            rejected[rule] += 1
            key = (rule, form)
            if key not in seen_rejected:
                seen_rejected.add(key)
                samples.append((rule, form, "+".join(sorted(origins[form]))))
            continue
        forms[normalized].add(form)

    samples.sort()
    return forms, rejected, samples


def build_terms(
    ods8: list[str],
    ods9: dict[str, list[str]],
    kartmaan: dict[str, set[str]],
    hbenbel: dict[str, set[str]],
) -> tuple[dict[str, dict], dict[str, int]]:
    """Applique l'ordre de fusion et renvoie (termes, compteurs d'effet)."""
    terms: dict[str, dict] = {}
    effects: dict[str, int] = {}

    # 1. Kartmaan — couche française.
    for normalized in kartmaan:
        terms[normalized] = {"is_french": 1, "is_ods8": 0, "is_ods9": 0}

    # 1 bis. hbenbel — complément français (D-014).
    created_by_hbenbel = 0
    for normalized in hbenbel:
        if normalized not in terms:
            terms[normalized] = {"is_french": 1, "is_ods8": 0, "is_ods9": 0}
            created_by_hbenbel += 1
    effects["hbenbel_forms_absent_from_kartmaan"] = created_by_hbenbel

    # 2. ODS8 — admis dans les deux éditions par défaut.
    #    Un mot admis est français par construction : is_french = 1 sans
    #    consulter les sources françaises, qui ne couvrent pas tout ODS8.
    created_by_ods8 = 0
    for word in ods8:
        entry = terms.get(word)
        if entry is None:
            entry = terms[word] = {"is_french": 1, "is_ods8": 0, "is_ods9": 0}
            created_by_ods8 += 1
        entry["is_ods8"] = 1
        entry["is_ods9"] = 1
    effects["ods8_rows_absent_from_french_sources"] = created_by_ods8

    # 3. Retraits ODS9.
    removed = 0
    for normalized in ods9["removals"]:
        entry = terms.get(normalized)
        if entry is not None and entry["is_ods9"] == 1:
            entry["is_ods9"] = 0
            removed += 1
    effects["ods9_removals_applied"] = removed

    # 4. keep_overrides — garde-fou explicite.
    #    Effet attendu : 0. Les 10 homographes sont disjoints des 64 retraits ;
    #    ils ne sont donc jamais passés à 0 par l'étape précédente. Un compteur
    #    non nul signale que les sources ont changé.
    restored = 0
    for normalized in ods9["keep_overrides"]:
        entry = terms.get(normalized)
        if entry is not None and entry["is_ods9"] == 0:
            entry["is_ods9"] = 1
            restored += 1
    effects["ods9_keep_overrides_applied"] = restored

    # 5. Additions ODS9.
    added_new = 0
    for normalized in ods9["additions"]:
        entry = terms.get(normalized)
        if entry is None:
            entry = terms[normalized] = {"is_french": 1, "is_ods8": 0, "is_ods9": 0}
            added_new += 1
        entry["is_ods9"] = 1
    effects["ods9_additions_creating_a_term"] = added_new

    return terms, effects


def write_database(terms: dict[str, dict], metadata: dict[str, str]) -> None:
    TARGET_PATH.parent.mkdir(parents=True, exist_ok=True)
    if TARGET_PATH.exists():
        TARGET_PATH.unlink()

    connection = sqlite3.connect(TARGET_PATH)
    try:
        connection.executescript(SCHEMA_PATH.read_text(encoding="utf-8"))
        rows = (
            (
                index,
                normalized,          # display_term = normalized, sans exception
                normalized,
                entry["is_french"],
                entry["is_ods8"],
                entry["is_ods9"],
                score(normalized),
                len(normalized),
                signature(normalized),
                reverse(normalized),
            )
            for index, (normalized, entry) in enumerate(sorted(terms.items()), start=1)
        )
        connection.executemany(
            "INSERT INTO terms (id, display_term, normalized, is_french, is_ods8,"
            " is_ods9, score, length, signature, reversed)"
            " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            rows,
        )
        connection.executemany(
            "INSERT INTO build_metadata (key, value) VALUES (?, ?)",
            sorted(metadata.items()),
        )
        connection.commit()
        connection.execute("ANALYZE")
        connection.commit()
        connection.execute("VACUUM")
    finally:
        connection.close()


def write_csv(path: Path, header: list[str], rows) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(header)
        writer.writerows(rows)


def write_json(path: Path, payload: dict) -> None:
    path.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="analyse les sources et affiche le resume, sans rien ecrire",
    )
    args = parser.parse_args()

    required = [ODS8_PATH, KARTMAAN_PATH, ODS9_PATH, SCHEMA_PATH]
    required += [HBENBEL_DIR / name for name in HBENBEL_FILES]
    for path in required:
        if not path.exists():
            raise SystemExit("source manquante : %s" % path)

    ods8 = load_ods8()
    ods9 = load_ods9()
    kartmaan, rejected, rejected_samples = load_kartmaan()
    hbenbel, hb_rejected, hb_samples = load_hbenbel()
    terms, effects = build_terms(ods8, ods9, kartmaan, hbenbel)

    # Les collisions se calculent sur l'union des formes sources : deux graphies
    # venues de sources différentes qui se rejoignent après normalisation sont
    # une fusion au même titre que deux graphies d'une même source.
    merged_forms: dict[str, set[str]] = defaultdict(set)
    for normalized, forms in kartmaan.items():
        merged_forms[normalized] |= forms
    for normalized, forms in hbenbel.items():
        merged_forms[normalized] |= forms
    collisions = {
        normalized: sorted(forms)
        for normalized, forms in merged_forms.items()
        if len(forms) > 1
    }

    status = Counter()
    for entry in terms.values():
        if entry["is_ods8"] and entry["is_ods9"]:
            status["ods8_and_ods9"] += 1
        elif entry["is_ods8"]:
            status["ods8_only"] += 1
        elif entry["is_ods9"]:
            status["ods9_only"] += 1
        else:
            status["french_non_ods"] += 1

    summary = {
        "kartmaan_distinct_normalized": len(kartmaan),
        "kartmaan_rejected": sum(rejected.values()),
        "hbenbel_distinct_normalized": len(hbenbel),
        "hbenbel_rejected": sum(hb_rejected.values()),
        "french_distinct_normalized": len(merged_forms),
        "french_rejected": sum(rejected.values()) + sum(hb_rejected.values()),
        "ods8_rows": len(ods8),
        "ods9_additions": len(ods9["additions"]),
        "ods9_removals": len(ods9["removals"]),
        "ods8_only": status["ods8_only"],
        "ods9_only": status["ods9_only"],
        "ods8_and_ods9": status["ods8_and_ods9"],
        "french_non_ods": status["french_non_ods"],
        "normalization_collisions": len(collisions),
        "terms_total": len(terms),
        "merge_effects": effects,
        "rejections_by_rule_kartmaan": dict(sorted(rejected.items())),
        "rejections_by_rule_hbenbel": dict(sorted(hb_rejected.items())),
    }

    if args.dry_run:
        print(json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True))
        print("\n--dry-run : aucune ecriture", file=sys.stderr)
        return 0

    metadata = {
        "language": "fr",
        "schema": "terms v1",
        "source_ods8_sha256": sha256_of(ODS8_PATH),
        "source_ods9_sha256": sha256_of(ODS9_PATH),
        "source_kartmaan_sha256": sha256_of(KARTMAAN_PATH),
        "terms_total": str(len(terms)),
    }
    for name in HBENBEL_FILES:
        metadata["source_hbenbel_%s_sha256" % Path(name).stem] = sha256_of(
            HBENBEL_DIR / name
        )
    write_database(terms, metadata)

    REPORTS.mkdir(parents=True, exist_ok=True)
    write_json(REPORTS / "import-summary.json", summary)
    write_json(
        REPORTS / "ods8-ods9-status-counts.json",
        {
            "ods8_only": status["ods8_only"],
            "ods9_only": status["ods9_only"],
            "ods8_and_ods9": status["ods8_and_ods9"],
            "french_non_ods": status["french_non_ods"],
            "ods8_total": status["ods8_only"] + status["ods8_and_ods9"],
            "ods9_total": status["ods9_only"] + status["ods8_and_ods9"],
            "terms_total": len(terms),
        },
    )
    write_csv(
        REPORTS / "normalization-collisions.csv",
        ["normalized", "source_forms_count", "source_forms"],
        (
            (normalized, len(forms), " | ".join(forms))
            for normalized, forms in sorted(collisions.items())
        ),
    )
    write_csv(
        REPORTS / "rejected-forms.csv",
        ["source", "rule", "form", "pos_or_origin"],
        sorted(
            [("kartmaan",) + row for row in rejected_samples]
            + [("hbenbel",) + row for row in hb_samples]
        ),
    )
    write_csv(
        REPORTS / "duplicates.csv",
        ["normalized", "kept_display_term", "merged_source_forms"],
        (
            (normalized, normalized, " | ".join(forms))
            for normalized, forms in sorted(collisions.items())
        ),
    )

    connection = sqlite3.connect("file:%s?mode=ro" % TARGET_PATH.as_posix(), uri=True)
    try:
        integrity = connection.execute("PRAGMA integrity_check").fetchone()[0]
        quick = connection.execute("PRAGMA quick_check").fetchone()[0]
    finally:
        connection.close()
    (REPORTS / "sqlite-integrity.txt").write_text(
        "integrity_check: %s\nquick_check: %s\nbytes: %d\n"
        % (integrity, quick, TARGET_PATH.stat().st_size),
        encoding="utf-8",
    )

    print(json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True))
    print(
        "\nbase : %s (%.1f Mo)\nintegrity_check : %s"
        % (TARGET_PATH, TARGET_PATH.stat().st_size / 1e6, integrity),
        file=sys.stderr,
    )
    return 0 if integrity == "ok" else 1


if __name__ == "__main__":
    raise SystemExit(main())
