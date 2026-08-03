# PHASE_STATUS

## Phase Courante

```text
Phase 0 — données : corrections du premier NO GO appliquées, en attente de
  nouvel audit code-reviewer
```

Mis à jour le 2026-08-03.

## Premier Audit — NO GO

Le premier audit `code-reviewer` de la Phase 0 a rendu **NO GO**. Les données étaient
correctement importées, mais quatre défauts de traçabilité et de cohérence bloquaient, plus un
défaut de fond (I2) qui exigeait une décision.

```text
I2  bloquant de fond   9 105 formes ODS8 de plus de 15 lettres marquées admises
                       à tort — corrigé, voir D-010 révisée ci-dessous
B1  traçabilité        rapport AFTER absent                     — corrigé
B2  traçabilité        reports/query-plans/ jamais écrit         — corrigé
B3  cohérence          comptes périmés dans DECISIONS/CLAUDE.md — corrigé
B4  cohérence          import-summary.json : champ manquant et
                       addition d'unités hétérogènes            — corrigé
I1  non bloquant       schema.sql:31 orientait vers un LIKE
                       qui force un SCAN                        — corrigé
```

## Livré

```text
schema.sql                        schéma canonique
scripts/lib/normalize.py          normalisation, score, signature, reversed,
                                   plafond de 15 lettres (D-010 révisée)
scripts/import_fr.py              import déterministe et rejouable
scripts/download_hbenbel.py       seconde source française
scripts/bench_queries.py          plans de requêtes persistés
storage/dictionary_fr.sqlite      838 180 termes, 154,5 Mo, integrity ok
reports/                          rapports + audit BEFORE + rapport AFTER
reports/query-plans/phase0.md     7 requêtes témoins, plans et timings
8 agents installés dans .claude/agents/
PHP 8.4.24 local avec pdo_sqlite, sqlite3, mbstring, intl, OPcache
```

## Comptes De La Base — Vérifiés Exhaustivement, Pas Par Échantillon

```text
termes                   838 180
admis ODS8               402 325   (valeur officielle Larousse, édition 2020)
admis ODS9               402 996   (= ODS8 et ODS9 + ODS9 seulement)
ODS8 seulement                64
ODS9 seulement               735
ODS8 et ODS9             402 261
français non admis       435 120
collisions fusionnées     48 319
```

Quatre sources : ODS8 et patch ODS9 pour l'admissibilité, Kartmaan et hbenbel pour la couche
française non admise (D-014). Aucune forme de plus de 15 lettres en base (D-010 révisée) —
injouable sur un plateau, et source d'une erreur factuelle si elle avait été affichée comme
admise.

## Porte Phase 0

```text
integrity_check = ok                                              OK
comptes vérifiés exhaustivement (838 180 lignes, pas un échantillon) OK
score/signature/reversed : 0 divergence sur les 838 180 lignes     OK
déterminisme : fichier .sqlite reconstruit BYTE-IDENTIQUE          OK
7 requêtes témoins, toutes via index, persistées dans
  reports/query-plans/phase0.md, médiane 0,07 à 0,18 ms            OK
rapport AFTER écrit                                                OK
comptes des fichiers partagés resynchronisés                       OK
audit code-reviewer (nouveau tour)                                 EN ATTENTE
```

## Bloquants

```text
aucun bloquant technique restant après correction
```

## Points À Trancher Avant La Phase 1

```text
QUEULEULEU, exemple emblématique du brief pour « français non admis », est
  absent des deux sources françaises — il n'existe que dans la locution
  « à la queue leu leu ». Remplaçants vérifiés en base : GHOSTER (recommandé),
  MACRONISTE. Voir docs/08_PROMPTS_PHASES.md.
le rollout SEO doit être dimensionné sur ~838 000 fiches, pas ~412 000
```

## Prochaine Action

```text
relancer l'audit code-reviewer sur la Phase 0 corrigée
puis ouvrir la Phase 1 — socle PHP, home et fiche mot
```

## GO / NO GO

```text
NO GO du premier tour — corrections appliquées, en attente du second verdict
```
