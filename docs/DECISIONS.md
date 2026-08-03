# DECISIONS

## D-001 — PHP 8.4 Et SQLite

Date : 2026-08-02  
Statut : accepté

Décision :

```text
PHP 8.4 sans framework
SQLite local en lecture seule au runtime
```

Raison :

```text
compatibilité o2switch
déploiement simple
aucun daemon applicatif
```

## D-002 — Une Base Par Langue Et Par Site

Date : 2026-08-02  
Statut : accepté

Décision :

```text
dictionary_fr.sqlite pour le site français
dictionary_en.sqlite pour le futur site anglais
```

Raison :

```text
taille
licences
scores
autocomplétion
sitemaps
déploiements indépendants
```

## D-003 — Registre SEO Séparé

Date : 2026-08-02  
Statut : accepté

Décision :

```text
seo_fr.sqlite et seo_en.sqlite séparés des dictionnaires
```

## D-004 — Aucune Définition En Production

Date : 2026-08-02  
Statut : accepté

Décision :

```text
la base publique conserve les formes et les indicateurs, pas les définitions
```

## D-005 — Registre SEO Source Unique

Date : 2026-08-02  
Statut : accepté

Décision :

```text
aucune route n’est indexable par défaut
```

## D-006 — Toutes Les Formes Françaises Retenues Ont Une Fiche

Date : 2026-08-02  
Statut : accepté

Décision :

```text
toute forme simple conservée après filtrage avec is_french = 1 possède une
fiche publique ; l’ouverture aux sitemaps se fait progressivement
```

## D-007 — Scripts De Build En Python, Runtime En PHP

Date : 2026-08-03
Statut : accepté

Décision :

```text
scripts/*.py     import, build, vérification — hors ligne uniquement
app/ et public/  runtime — PHP 8.4 exclusivement, SQLite en lecture seule
```

Raison :

```text
les scripts d’import ne tournent jamais en production
Python est disponible immédiatement et traite 412 000 lignes sans friction
le runtime reste strictement conforme à D-001
```

Conséquences :

```text
aucune dépendance Python n’atteint l’hébergement o2switch
le périmètre de data-engine devient scripts/import_*.py et scripts/build_*.py
la base produite est un artefact, jamais versionnée
```

## D-008 — Le Pack De Lancement Est Promu À La Racine

Date : 2026-08-03
Statut : accepté

Décision :

```text
le contenu de scrabble-light-claude-launch-pack/ remonte à la racine du dépôt
les 8 agents vivent dans .claude/agents/, source unique
CLAUDE.md devient le point d’entrée de toute session
```

Raison :

```text
les documents et les définitions d’agents citaient déjà docs/, data/, scripts/
la double localisation des agents avait produit un MANIFEST.json périmé et un
README annonçant à tort que les agents d’audit manquaient
```

Conséquences :

```text
data/raw/scrabble-french-FR-ODS8.json renommé en data/raw/ods8.json
empreintes et provenance consignées dans data/raw/PROVENANCE.md
documents d’amorçage conservés dans docs/archive/
```
