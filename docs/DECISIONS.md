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

## D-009 — Règle De Normalisation

Date : 2026-08-03
Statut : accepté

Décision :

```text
1. ligatures  œ → oe,  Œ → OE,  æ → ae,  Æ → AE
2. NFD
3. suppression des caractères de catégorie Unicode Mn
4. majuscules
5. acceptation de ^[A-Z]{2,} uniquement ; tout le reste est rejeté et tracé
```

Raison :

```text
NFD ne décompose pas les ligatures — sans l’étape 1, 760 formes disparaissent
silencieusement, dont 288 mots admis ODS8 (OEIL, BOEUF, OEUF) qui se
retrouveraient privés de is_french
```

Conséquences :

```text
scripts/lib/normalize.py est la source unique de la règle
le runtime PHP doit réimplémenter exactement les mêmes étapes
tout écart entre les deux implémentations est un bug, pas une variante
```

## D-010 — Aucun Plafond De Longueur En Base

Date : 2026-08-03
Statut : accepté

Décision :

```text
base de production   toutes les formes, sans plafond de longueur
entrée du solveur    15 caractères maximum, validation de formulaire
```

Raison :

```text
un plafond à 15 lettres écarterait 9 105 mots ODS8 admis
le 15 est la contrainte du plateau et du chevalet, pas celle du dictionnaire
un mot long reste vérifiable et garde sa fiche, il n’est simplement jamais
  produit par le solveur
```

## D-011 — Dictionnaire Français Complet Dès Le Lancement

Date : 2026-08-03
Statut : accepté

Décision :

```text
toutes les formes Kartmaan retenues après filtrage entrent en base avec
is_french = 1, y compris les formes fléchies non admises
```

Raison :

```text
la distinction admis / non admis est la fonction centrale du site ; elle exige
la couverture française la plus large possible dès le lancement
```

Conséquences :

```text
440 184 formes françaises hors ODS, soit 852 349 termes au total
la base atteint 160 Mo — le double de l’ordre de grandeur évoqué au brief
le rollout SEO doit dimensionner ses lots sur ~852 000 fiches, pas ~412 000
une variante plus étroite reste disponible : exclure les formes attestées
  uniquement comme flex-* ramènerait la couche non-ODS à 76 384
```

## D-012 — Postings Reportés En Phase 2/3

Date : 2026-08-03
Statut : accepté

Décision :

```text
la Phase 0 livre les index du schéma, pas les tables de postings
```

Raison :

```text
un index de toutes les sous-chaînes pèserait ~587 Mo, plus que la source
les Phases 1, 4 et 5 n’en ont aucun besoin : normalized, reversed et signature
  suffisent, mesurés entre 0,10 et 0,97 ms
construire 61 Mo d’index avant de connaître les requêtes réelles reviendrait
  à optimiser sans mesure
```

Conséquences :

```text
écart assumé à l’étape 8 de docs/03, qui plaçait les postings en Phase 0
la structure sera choisie en Phase 2/3 sur benchmark de sélectivité
```

## D-013 — display_term Égal À normalized, Et Pas D’Index Sur is_french

Date : 2026-08-03
Statut : accepté

Décision :

```text
display_term = normalized sur toutes les lignes
aucun index sur is_french
aucun index simple sur normalized : la contrainte UNIQUE en crée déjà un
```

Raison :

```text
ODS8 ne contient aucun accent sur ses 411 430 entrées ; afficher une forme
  accentuée venue d’une autre source rendrait les fiches incohérentes entre
  elles selon leur provenance
les 46 232 collisions de normalisation deviennent de simples fusions de
  provenance, sans arbitrage d’affichage
is_french vaut 1 sur toutes les lignes de la base française : un index sur une
  colonne constante ne sert à rien et coûte ~18 Mo
```

Conséquences :

```text
la colonne display_term reste au schéma, partagé avec le futur site anglais,
  au coût mesuré d’environ 9 Mo de duplication
la colonne is_french reste au schéma pour la même raison
```
