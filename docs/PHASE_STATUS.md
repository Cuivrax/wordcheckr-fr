# PHASE_STATUS

## Phase Courante

```text
Toutes les phases fonctionnelles (1 à 6) sont livrées, plus un enrichissement
  hors plan (D-018), la refonte de la home (rapprochement du prototype réel),
  le hub /mots et le correctif F3 (état d'erreur visible). L'audit consolidé
  final a tourné quatre passes au total (voir reports/phases2-6-after.md pour
  le détail complet de chacune) :
    1re passe  code-reviewer NO GO (4 bloquants) / code-optimizer GO
    2e passe   bloquants de la 1re passe corrigés, C1 (faux négatifs
               "contenant/avec/sans" sans ancrage) découvert et corrigé
    3e passe   NO GO sur les deux audits : la correction C1 réintroduisait
               un parcours quasi complet de la table (CLAUDE.md, Interdits),
               exposé à tout crawl via ~1,67M liens auto-générés
    4e passe   corrections appliquées (fusion de requêtes, retrait des liens
               sans ancrage, fuite de processus dans les tests) --
               code-reviewer : GO. code-optimizer : GO (gain de la fusion
               confirmé indépendamment, -20 à -55 %, 0 divergence sur 100+
               comparaisons total/truncated). Non-bloquants restants listés
               dans reports/phases2-6-after.md, dont un point Phase 7 réel :
               RelationsFinder::containingWords() sur /mot/{mot} approche le
               budget TTFB sous charge concurrente (p95 mesuré 252 ms à 8
               processus simultanés, hors périmètre de cette passe)
```

Mis à jour le 2026-08-06.

Décision explicite prise en cours de route (demande utilisateur) : construire
un site fonctionnel d'abord, un seul audit consolidé groupé avant mise en
ligne plutôt qu'un audit complet après chaque phase. Les points non bloquants
relevés par les audits Phase 1 (I2-I8, dédoublement de composants CSS, copy
provisoire...) n'ont jamais été refermés individuellement — à couvrir par
l'audit consolidé.

### Récapitulatif des phases livrées

```text
Phase 1  socle, home, fiche mot            /, /mot/{mot}, /verifier/{mot}
         GO après correction de 3 bloquants critiques (C1-C3, entrées
         malformées) et 2 bloquants de contraste (design-consistency-reviewer)
Phase 2  solveur                            /jouer/{lettres}
         plafond de sécurité mesuré (signatures candidates), jamais de scan
Phase 3  contraintes de recherche           /mots/...
         postings écartés après mesure (355 Mo, toujours trop lent) au profit
         d'une approche bornée sur les index existants
Phase 4  fiches riches (relations)          10 catégories sur /mot/{mot}
         budget mesuré et tenu : 9 requêtes dictionnaire + 1 registre SEO
         pour un mot admis, 4 + 1 pour un français non admis (< 10 par base,
         voir D-003 pour la convention de comptage entre les deux bases)
Phase 5  autocomplétion                     GET /api/suggest, combobox ARIA
Phase 6  registre SEO                       storage/seo_fr.sqlite, D-017
         838 248 URL en index,follow (403 060 admis + 435 120 français non
         admis + 68 pages de structure, dont le hub /mots ajouté après D-017)
         — décision explicite du propriétaire du produit, contre l'avis
         initial de l'agent seo-registry (garde-fou de rôle légitime, voir
         D-017). Rien n'est visible par le vrai Google avant la Phase 7.
Home     refonte champ unifié (rapprochement du prototype, deux <form>
         distincts depuis le correctif F1) + liens contextuels vers
         /mots/... (maillage interne, signalé par seo-registry)
Hub      /mots (App\Search\ExploreHub) : 66 liens (longueur/commençant/
         terminant) + outil "Contenant" borné à 3 lettres, comptes
         précalculés hors ligne (list_counts) après mesure d'un problème de
         performance réel (~500-1000 ms en GROUP BY live, corrigé)
D-018    nature grammaticale, genre, liens de conjugaison sur /mot/{mot}
         (hors plan initial, demande utilisateur) — aucune définition (D-004
         toujours en vigueur)
F3       état d'erreur visible (WCAG 3.3.1) sur /verifier, /jouer, /mots :
         bandeau role="alert" au lieu d'une redirection silencieuse
C1       correction (audit final, bloquant) : recherches "contenant/avec/
         sans" sans ancrage renvoyaient des faux négatifs silencieux au-delà
         des 10 000 premiers mots alphabétiques — voir D-019
```

Base de production reconstruite (D-018) : 838 180 termes inchangés,
integrity_check = ok, déterminisme vérifié (reconstruction x2, comparaison
octet à octet). 17/17 fichiers de tests verts (`php tests/run.php`).

Note : le second passage `code-reviewer` sur la Phase 0 corrigée n'a jamais été
tracé formellement (historique conservé ci-dessous). Non bloquant pour la suite.

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
storage/dictionary_fr.sqlite      838 180 termes, 154,5 Mo à la sortie de la Phase 0,
                                   integrity ok (base reconstruite depuis, D-018 :
                                   172,6 Mo au 2026-08-06, mêmes 838 180 termes)
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
lancer l'audit consolidé avant Phase 7 (Production) :
  code-reviewer, code-optimizer, design-consistency-reviewer,
  seo-technical-auditor — un seul NO GO bloque la mise en ligne
```

## GO / NO GO

```text
Phase 0 — GO implicite (données inchangées depuis, jamais retracé formellement)
Phase 1 — GO (audit formel, 2 tours)
Phases 2-6, home, D-018 — jamais audités formellement, code fonctionnel et
  testé (17/17), en attente de l'audit consolidé ci-dessus
```
