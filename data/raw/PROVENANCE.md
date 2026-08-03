# Provenance Des Données Brutes

Ce dossier est exclu de Git. Les fichiers y sont reconstitués à l'identique grâce aux
empreintes ci-dessous.

## ods8.json

```text
nom d'origine   scrabble-french-FR-ODS8.json
renommé le      2026-08-03
date du fichier 2025-01-25
taille          6 239 871 octets
sha256          7536456c64848a426265bafb5a315d5b9682db9dbe0f14fbc1dd0ad2748846ec
```

Renommé en `ods8.json` : c'est le nom attendu par `scripts/verify_data_pack.py`,
`docs/03_SOURCES_ET_IMPORT_DATA.md` et `.gitignore`.

Structure vérifiée :

```json
{"words": ["AA", "AALENIEN", "..."]}
```

```text
411 430 entrées
411 430 distinctes (aucun doublon)
charset strictement A-Z majuscules, aucun accent, aucun espace, aucune ponctuation
longueurs de 2 à 21 caractères
```

Fourni par l'utilisateur. Les droits d'usage des données ODS relèvent de l'utilisateur.

## french_dict.db

```text
source          https://huggingface.co/datasets/Kartmaan/french-dictionary
fichier         french_dict.db
téléchargé le   2026-08-03
taille          282 763 264 octets
sha256          ce3ee53429d8d08a6a56c3e25d62f5451a56d99db496cc1fdac9dc427cf721e9
licence         CC BY-SA 4.0, dérivé de données Wiktionnaire
obtention       scripts/download_french_dictionary.ps1 (écrit aussi le .sha256)
```

L'attribution et la licence doivent être préservées. Ce fichier ne doit jamais être publié
dans le dossier web ni copié dans la base de production.

Schéma constaté (`PRAGMA quick_check` = ok) :

```sql
CREATE TABLE mots (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    forme       TEXT NOT NULL,
    pos         TEXT,
    definitions TEXT NOT NULL,
    gender      TEXT DEFAULT NULL
);
CREATE INDEX idx_forme ON mots(forme);
```

```text
1 000 747 lignes
  895 090 valeurs distinctes de forme
```

`pos` porte l'étiquette grammaticale et permet le filtrage : `NP` pour les noms propres,
`Loc*` et `loc-*` pour les locutions, `flex-*` pour les formes fléchies.
`definitions` est un JSON de gloses — il n'est jamais copié en production (D-004).

## hbenbel/ — seconde source française (D-014)

```text
source          https://github.com/hbenbel/French-Dictionary
téléchargé le   2026-08-03
obtention       python scripts/download_hbenbel.py
licence dépôt   MIT
licence données CC BY-SA — extraites de kaikki.org, dérivé du Wiktionnaire
```

```text
dictionary.csv   4 869 842 o   b9fc59fe615a1ed9e89d27ddfb4226b175b5f6c075abbc535be077a40738b2b4
adj.csv          1 331 447 o   482f870d6da61f0426248961ba3a2660f2a536275040a8a930fb4b38c13c71a5
noun.csv         1 637 824 o   ef9b89416885d5a957fae887a2094f51efba8f9ff7ae878aa700bd13729fa4db
verb.csv        22 726 815 o   a480f803adafa99b77be469f7262e3d76a3a226058b4d25fa3bc1c8542d518e8
adv.csv             66 068 o   b6521cfcae6ed78156987b7dabba8bf0a0900ff383257c3986b4cc0cc642b655
```

```text
404 849 formes brutes distinctes
352 529 retenues après normalisation et filtrage
 34 300 absentes de la base construite depuis ODS8 + ODS9 + Kartmaan
```

`dictionary.csv` est une liste plate sans en-tête. Les quatre autres fichiers ont un
en-tête `form,tags`.

Cette source n'a **pas** d'étiquette `NP` : ses noms propres et ses sigles se trouvent dans
`noun.csv`. La casse de la forme d'origine est le seul marqueur disponible et sert de filtre
(D-014).

## data/ods9/

Livré avec le pack de lancement, empreintes dans `data/ods9/manifest.json`.
`ods9_patch.sqlite` : `integrity_check` = ok, 1091 additions / 64 removals /
10 keep_overrides / 46 modifications.

Ce pack est le delta actuellement disponible. Il ne constitue pas une certification
officielle d'exhaustivité ODS9.
