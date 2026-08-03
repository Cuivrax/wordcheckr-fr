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
source     https://huggingface.co/datasets/Kartmaan/french-dictionary
fichier    french_dict.db (~283 Mo)
licence    CC BY-SA 4.0, dérivé de données Wiktionnaire
obtention  scripts/download_french_dictionary.ps1 (écrit aussi le .sha256)
```

L'attribution et la licence doivent être préservées. Ce fichier ne doit jamais être publié
dans le dossier web ni copié dans la base de production.

Statut : à télécharger.

## data/ods9/

Livré avec le pack de lancement, empreintes dans `data/ods9/manifest.json`.
`ods9_patch.sqlite` : `integrity_check` = ok, 1091 additions / 64 removals /
10 keep_overrides / 46 modifications.

Ce pack est le delta actuellement disponible. Il ne constitue pas une certification
officielle d'exhaustivité ODS9.
