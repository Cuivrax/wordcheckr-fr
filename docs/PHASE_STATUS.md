# PHASE_STATUS

## Phase Courante

```text
Phase 0 — données : implémentée, en attente d'audit code-reviewer
```

Mis à jour le 2026-08-03.

## Livré

```text
schema.sql                        schéma canonique
scripts/lib/normalize.py          normalisation, score, signature, reversed
scripts/import_fr.py              import déterministe et rejouable
scripts/download_hbenbel.py       seconde source française
storage/dictionary_fr.sqlite      886 649 termes, 166,6 Mo, integrity ok
reports/                          6 rapports + audit Phase 0
8 agents installés dans .claude/agents/
PHP 8.4.24 local avec pdo_sqlite, sqlite3, mbstring, intl, OPcache
```

## Comptes De La Base

```text
termes                   886 649
admis ODS8               411 430
admis ODS9               412 101
ODS8 seulement                64
ODS9 seulement               735
ODS8 et ODS9             411 366
français non admis       474 484
collisions fusionnées     49 213
```

Quatre sources : ODS8 et patch ODS9 pour l'admissibilité, Kartmaan et hbenbel
pour la couche française non admise (D-014).

## Porte Phase 0

```text
integrity_check = ok                                          OK
comptes conformes aux prévisions de l'audit                   OK
déterminisme : deux exécutions, rapports au même sha256       OK
score = somme des tuiles, échantillon de 2 000                OK
7 requêtes témoins, toutes via index, 0,09 à 0,53 ms          OK
audit code-reviewer                                           EN ATTENTE
```

## Bloquants

```text
aucun bloquant technique
```

## Points À Trancher Avant La Phase 1

```text
QUEULEULEU, exemple emblématique du brief pour « français non admis », est
  absent des deux sources françaises — il n'existe que dans la locution
  « à la queue leu leu ». La microcopie doit choisir un autre exemple.
le rollout SEO doit être dimensionné sur 886 649 fiches, pas 412 000
```

## Prochaine Action

```text
lancer l'audit code-reviewer sur la Phase 0 (agent disponible à la
  prochaine session Claude Code)
puis ouvrir la Phase 1 — socle PHP, home et fiche mot
```

## GO / NO GO

```text
GO technique pour la Phase 1 sous réserve de l'audit code-reviewer
```
