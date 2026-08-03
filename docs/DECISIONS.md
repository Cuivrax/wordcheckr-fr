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

## D-010 — Plafond De 15 Lettres En Base (Révisée)

Date : 2026-08-03, révisée le 2026-08-03
Statut : accepté

**Révision.** La version initiale de cette décision retenait toutes les formes sans plafond,
justifiée par « un plafond à 15 lettres écarterait 9 105 mots ODS8 admis ». Cette justification
était fausse : l'audit code-reviewer de la Phase 0 (NO GO, point I2) a établi que ces 9 105
formes ne sont pas des mots ODS8. L'ODS8 publié par Larousse compte 402 325 mots ; notre
fichier `data/raw/ods8.json` en compte 411 430 — l'écart exact est ces 9 105 formes, des
conjugaisons générées (`CINEMATOGRAPHIASSIONS`, `REAPPROVISIONNERAIENT`) absentes de l'ODS
réel. Les afficher comme « admises au Scrabble » aurait été une erreur factuelle sur le site.
Confirmé par une source externe : [Wikipedia, L'Officiel du jeu Scrabble](https://en.wikipedia.org/wiki/L%27Officiel_du_jeu_Scrabble),
402 325 mots pour l'édition 2020.

Décision :

```text
base de production   toutes les formes de 2 à 15 lettres, aucune au-delà
entrée du solveur    15 caractères maximum — même borne, cohérente
```

Raison :

```text
un mot de plus de 15 lettres ne peut jamais être posé sur un plateau standard :
  ce n’est pas seulement une limite de saisie, c’est une limite du jeu lui-même
le plafond sert aussi de contrôle d’intégrité de la source ODS8 : le nombre de
  formes retenues doit valoir exactement 402 325, vérifié par
  scripts/import_fr.py qui lève une erreur si ce n’est pas le cas
le patch ODS9 confirme la borne : ses 1091 additions, 64 retraits et
  10 keep_overrides ne contiennent aucune forme de plus de 15 lettres
```

Conséquences :

```text
ods8_rows passe de 411 430 à 402 325
9 105 formes de 16 à 21 lettres retirées de la base, plus 46 119 lignes
  Kartmaan et 12 054 formes hbenbel de même longueur, déjà écartées ailleurs
```

## D-011 — Dictionnaire Français Complet Dès Le Lancement

Date : 2026-08-03, comptes mis à jour le 2026-08-03 (D-010 révisée, D-014)
Statut : accepté

Décision :

```text
toutes les formes des sources françaises retenues après filtrage entrent en
base avec is_french = 1, y compris les formes fléchies non admises, dans la
limite du plafond de longueur (D-010)
```

Raison :

```text
la distinction admis / non admis est la fonction centrale du site ; elle exige
la couverture française la plus large possible dès le lancement
```

Conséquences, comptes vérifiés exhaustivement sur les 838 180 lignes de
`storage/dictionary_fr.sqlite` (pas un échantillon) :

```text
435 120 formes françaises hors ODS, 838 180 termes au total
la base atteint 154,5 Mo
le rollout SEO doit dimensionner ses lots sur ~838 000 fiches, pas ~412 000
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
ODS8 ne contient aucun accent sur ses entrées ; afficher une forme accentuée
  venue d’une autre source rendrait les fiches incohérentes entre elles selon
  leur provenance
les collisions de normalisation deviennent de simples fusions de provenance,
  sans arbitrage d’affichage (48 319 après D-010 révisée et D-014)
is_french vaut 1 sur toutes les lignes de la base française : un index sur une
  colonne constante ne sert à rien et coûte ~18 Mo
```

Conséquences :

```text
la colonne display_term reste au schéma, partagé avec le futur site anglais,
  au coût mesuré d’environ 9 Mo de duplication
la colonne is_french reste au schéma pour la même raison
```

## D-014 — Seconde Source Française : hbenbel/French-Dictionary

Date : 2026-08-03
Statut : accepté

Décision :

```text
data/raw/hbenbel/   dictionary.csv, adj.csv, noun.csv, verb.csv, adv.csv
source              https://github.com/hbenbel/French-Dictionary
obtention           python scripts/download_hbenbel.py
```

Raison :

```text
la couche française non admise reposait sur une source unique, dont les
  lacunes sont réelles
hbenbel apporte 34 300 formes absentes de la base, toutes en minuscule et
  porteuses d’une catégorie grammaticale
```

Filtrage propre à cette source :

```text
hbenbel n’a pas d’étiquette NP : ses noms propres et ses sigles sont noyés
dans noun.csv. La CASSE de la forme d’origine est le seul marqueur exploitable.
Toute forme à majuscule initiale est écartée — 2 987 rejets, dont Ewok,
Aberdonien, ADN, ARN, AVC, AOC. C’est ainsi que sont appliquées les exclusions
« noms propres » et « sigles » exigées par docs/03 §5.
```

Conséquences :

```text
838 180 termes au total, dont 435 120 français non admis, après D-010 révisée
base à 154,5 Mo
aucun crédit de source n’est publié (D-015)
```

Limite constatée :

```text
QUEULEULEU reste absent des deux sources. L’exemple emblématique du brief pour
« forme française non admise » n’existe dans aucune d’elles : il n’apparaît que
dans la locution « à la queue leu leu », écartée par la règle des espaces.
La microcopie doit choisir un autre exemple.
```

## D-015 — Aucun Crédit De Source Publié

Date : 2026-08-03
Statut : accepté

Décision :

```text
le site ne publie aucun crédit de source pour le dictionnaire français
ni page de licence, ni mention en pied de page, ni commentaire dans le HTML
```

Raison :

```text
la base de production est une construction propre : formes normalisées,
  indicateurs d’admissibilité, scores, signatures et dérivés
aucune définition, aucun texte éditorial et aucune structure de données
  d’origine ne sont repris
```

Conséquences :

```text
data/raw/PROVENANCE.md reste un document strictement interne, conservé pour la
  seule reproductibilité de l’import
les agents ne doivent pas ajouter de mention de source dans les templates,
  le footer ou les métadonnées
```

Réserve consignée, non bloquante :

```text
les mots isolés ne sont pas protégeables et aucune définition n’est reprise.
Le droit sui generis européen sur les bases de données porte toutefois sur
l’extraction substantielle d’une base, indépendamment du droit d’auteur.
Décision prise en connaissance de cause par le propriétaire du projet.
Ce document n’est pas un avis juridique.
```
