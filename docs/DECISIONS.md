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

Convention de budget de requêtes (ajout, audit final, constat I2) :

```text
« moins de 10 requêtes SQLite indexées par fiche mot » (CLAUDE.md) s'entend
  par base : moins de 10 sur le dictionnaire (dictionary_fr.sqlite), la
  requête de résolution du registre SEO (seo_fr.sqlite, App\Seo\Registry::
  resolve(), 1 requête systématique via $render()) est comptée séparément
raison de la séparation : deux bases physiquement distinctes (cette
  décision), ouvertes par deux connexions PDO indépendantes, sans jointure
  possible entre elles — les compter ensemble n'aurait pas de sens
  opérationnel (le budget vise le coût du dictionnaire, la table à 838 180
  lignes, pas le registre à faible volume)
mesuré : la requête registre coûte 0,035 ms (code-optimizer, EXPLAIN QUERY
  PLAN : SEARCH registry USING INDEX), négligeable dans tous les cas
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
Python est disponible immédiatement et traite 1,4 million de lignes source sans friction
le runtime reste strictement conforme à D-001
```

Conséquences :

```text
aucune dépendance Python n’atteint l’hébergement o2switch
le périmètre de data-engine devient scripts/import_*.py et scripts/build_*.py
la base produite est un artefact, jamais versionnée
```

Amendement (audit final, code-reviewer, constat M4) :

```text
6 scripts de build ultérieurs sont en PHP, pas en Python : apply_full_word_
  rollout.php, apply_seo_batch.php, build_explore_hub_counts.php, build_seo_
  registry.php, build_sitemaps.php, propose_seo_batch.php — tous opèrent sur
  storage/seo_fr.sqlite ou construisent des artefacts déjà dérivés du
  dictionnaire (registre SEO, sitemaps, comptes du hub /mots), jamais
  l'import brut des sources externes (ods8.json, hbenbel, Kartmaan), qui
  reste en Python
raison du choix PHP pour ceux-ci : réutilisent directement les classes du
  runtime (App\Search\WordListFilters, App\Seo\Family...) plutôt que de
  dupliquer leur logique dans un second langage — cohérence de comportement
  entre le calcul hors ligne et la lecture au runtime jugée plus importante
  que l'uniformité de langage du build
principe non modifié : tous gardés par `PHP_SAPI !== 'cli'` (jamais
  atteignables par le web), jamais exécutés au runtime, jamais de dépendance
  supplémentaire sur o2switch — seule la lettre de « scripts/*.py » était
  devenue inexacte, pas l'esprit de la décision (build hors ligne, runtime
  en lecture seule, D-001 intact)
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

## D-016 — Lecture Seule PHP Au Runtime : Trois Verrous Indépendants

Date : 2026-08-03
Statut : accepté

Décision :

```text
chaque connexion PDO SQLite runtime combine trois verrous indépendants :
  SQLITE_OPEN_READONLY (drapeau d'ouverture PDO)
  PDO::SQLITE_ATTR_READONLY_STATEMENT (PHP 8.4)
  PRAGMA query_only = ON
une instance par requête HTTP, jamais persistée (app/Database/Connection.php)
```

Raison :

```text
contrainte dure D-001 (SQLite local, ouvert en lecture seule au runtime) --
  aucune écriture ne doit être possible depuis public/, même par erreur de code
testé explicitement : écriture bloquée sur les trois verrous, fichier absent
  jamais créé, coût d'ouverture mesuré ~0,2-0,3 ms
```

Note de traçabilité : décision proposée dans `reports/phase1a-after.md` (Phase 1a,
agent data-engine) mais jamais migrée dans ce document — trouvé et corrigé lors de
l'audit final (code-reviewer, constat I7/M3). Aucun changement de comportement,
seulement la consignation d'une décision déjà implémentée et déjà en vigueur depuis
la Phase 1a.

## D-017 — Indexation Complète Des Formes Françaises Non Admises

Date : 2026-08-04  
Statut : accepté

Décision :

```text
storage/seo_fr.sqlite couvre au lancement les 838 180 fiches mot (403 060
  admises + 435 120 françaises non admises), plus les 67 pages de structure
  (home, longueur, commençant, terminant) : toutes en index,follow
  mise à jour (audit final, constat I7) : le hub /mots (App\Search\ExploreHub,
  ajouté après cette décision lors de la refonte de la home) porte le total
  réel à 838 248 lignes / 68 pages de structure — non anticipé ici, corrigé
  dans docs/PHASE_STATUS.md, aucun changement de politique d'indexation
le plafond MAX_BATCH_SIZE_FRENCH_NOT_ADMITTED (app/Seo/Family.php) passe de
  50 à 500 000 — l'attestation ligne par ligne (notes non vide, R6/R7) reste
  obligatoire, seul le volume par lot change
```

Raison :

```text
le site répond à deux questions symétriques : « ce mot est-il admis ? » et
  « ce mot est-il non admis ? » (docs/01_MASTER_BRIEF.md) — un visiteur qui
  cherche un mot sur Google ne sait jamais à l'avance dans lequel des deux
  cas il tombe
exclure les formes non admises de l'indexation rend le site introuvable
  précisément pour le cas d'usage où l'incertitude de l'utilisateur est la
  plus grande — constaté sur un exemple réel (DTC : présent en base,
  is_french=1, is_ods8=0, is_ods9=0)
ces pages ne sont pas du contenu vide ou dupliqué : badge, titre, score,
  tuiles et réponse directe sont rendus pour les trois statuts
  (app/View/word.php) — seul le bloc de relations (Phase 4) manque au non
  admis, pas la page entière
```

Contexte du désaccord :

```text
l'agent seo-registry a refusé d'appliquer ce lot, conformément à son propre
  garde-fou (.claude/agents/seo-registry.md : « never propose indexing
  these in bulk », « never propose indexing an entire word family at once
  without discussing batch size first ») — refus légitime, pas un
  dysfonctionnement : un message de coordinateur relayant une décision
  n'est pas une autorisation suffisante pour lever un garde-fou de rôle
le lot a donc été préparé et appliqué directement par la session
  principale (scripts/apply_full_word_rollout.php), à la demande explicite
  et informée du propriétaire du produit, après explication du compromis
  SEO habituel (contenu peu différencié en volume) et vérification que
  l'échantillon aléatoire de formes non admises contient un mélange réel
  (conjugaisons rares type REBULGARISAI, mais aussi DTC)
```

Conséquences :

```text
sans risque réel au moment de la décision : le site n'est pas encore
  déployé (Phase 7 — Production non commencée), rien de ce qui est écrit
  dans storage/seo_fr.sqlite n'est vu par le vrai Google avant une mise en
  ligne effective
le séquencement réel du rollout (quelles URL sont effectivement poussées
  en premier sur o2switch, avec point de contrôle Search Console entre
  deux vagues) reste une décision de la Phase 7, distincte de celle-ci —
  le registre local complet ne préjuge pas du calendrier de mise en ligne
scripts/build_sitemaps.php chargeait le registre entier en mémoire
  (fetchAll) avant traitement — épuisait la limite CLI par défaut (128 Mo)
  à cette échelle ; corrigé en lecture en flux (curseur PDO), jamais plus
  d'un fragment (40 000 URL max) retenu en mémoire à la fois
```

## D-018 — Nature Grammaticale, Genre Et Liens De Conjugaison

Date : 2026-08-04  
Statut : accepté

Décision :

```text
terms gagne trois colonnes nullables : pos, pos_secondary (jeu fermé de 9
  codes : N, V, Adj, Adv, Pronom, Prep, Conj, Interj, Art), gender (m/f/e)
nouvelle table verb_forms (lemma_normalized, form_normalized, tense,
  person) : liens de conjugaison pour les verbes fiables uniquement
D-004 reste pleinement en vigueur — aucune définition, aucune glose,
  aucun exemple d'usage n'est importé ni affiché
```

Raison :

```text
un joueur qui vérifie un mot veut souvent savoir ce que c'est, ne serait-ce
  que pour l'expliquer à un adversaire — un vrai besoin produit, distinct
  du besoin de définition en prose
nature grammaticale et genre sont des données factuelles structurées
  (data/raw/french_dict.db, Kartmaan, colonnes pos/gender), pas du texte
  éditorial — aucun risque de droit d'auteur, aucune génération par IA,
  aucun risque d'hallucination
```

Source et fiabilité :

```text
pos/gender : data/raw/french_dict.db (Kartmaan), lignes NP et loc* déjà
  exclues comme le fait scripts/import_fr.py pour l'import français
  734 622 termes sur 838 180 (87,7 %) ont au moins une ligne exploitable ;
  le reste (hbenbel/ODS9 seuls) a pos = NULL, une absence de donnée, pas
  une erreur
  un seul pos ne suffit qu'à 87,9 % des termes (homographes réels :
  TABLE nom/verbe, gentilés nom/adjectif) — d'où pos_secondary plutôt
  qu'une simplification à un seul champ
  gender a une troisième valeur non anticipée au départ : 'e' (épicène,
  ex. ENFANT, ÉLÈVE, ARTISTE), conservée plutôt que supprimée ou forcée à
  m/f — la faire disparaître aurait été une erreur factuelle du même
  ordre que celle corrigée par D-010

conjugaison : data/raw/hbenbel/verb.csv, 362 462 lignes forme+tags. Une
  pré-vérification transmise supposait un fichier organisé en blocs
  contigus par verbe — vérifié FAUX à l'implémentation : c'est un tri
  alphabétique global sur la forme, toutes formes de tous les verbes
  mélangées (0 inversion sur les 362 461 lignes). Un appariement par
  adjacence aurait mal attribué massivement les conjugaisons pour tout
  verbe au radical alphabétiquement voisin d'un autre
  corrigé par appariement au plus long préfixe commun (recherche
  dichotomique sur les 6 697 infinitifs connus) : 98,24 % d'appariements
  uniques sur les 348 366 lignes conjuguées
  limite mesurée et acceptée : les verbes supplétifs/fortement irréguliers
  s'apparient de façon confiante mais FAUSSE sur ce critère (ex. « suis »
  → SUIVRE au lieu d'ÊTRE, « vais » → VAIRONNER au lieu d'ALLER) — détecté
  par un seuil sur le nombre de formes que chaque lemme s'attribue à
  lui-même (médiane 50-51 formes/verbe fiable) : tout lemme sous 20 formes
  propres est exclu de verb_forms, 281 lemmes sur 6 697 (4,2 %), liste
  complète dans reports/verb-lemmas-excluded.csv — dont ÊTRE, AVOIR,
  ALLER, DEVOIR, VALOIR, VOIR, ASSEOIR, GÉSIR et la famille
  TENIR/VENIR/COURIR/CUIRE (radical alterné)
  une table d'exceptions saisie à la main pour ces verbes a été envisagée
  et écartée par défaut : introduirait des données non dérivées de la
  source mesurée, disproportionné pour "quelques liens simples, pas de
  surcharge" — pas de données fausses vaut mieux qu'un lien de
  conjugaison erroné, même au prix de l'absence de cette section sur les
  verbes les plus courants
  résidu accepté, non éliminé : ce seuil détecte les verbes entièrement
  peu fiables, pas les mismatches partiels sur un verbe par ailleurs
  fiable (ex. le futur de FAIRE continue de s'apparier à tort à FERIER) —
  ce résidu pollue la fiche du verbe voisin innocent, jamais celle du
  verbe irrégulier lui-même (qui se retrouve juste sans données)
```

Périmètre exclu, explicitement :

```text
paradigme de conjugaison complet (~50 formes/verbe) : sélection
  représentative seulement — présent/futur/imparfait (indicatif, 6
  personnes) + participe présent + participe passé (forme de base, sans
  accord), jusqu'à 20 formes/verbe
accord adjectif/nom (flex-adj/flex-nom) : hors périmètre de cette passe,
  seule la nature grammaticale (pos/gender) s'applique à ces mots, pas de
  liens d'accord
```

Conséquences :

```text
storage/dictionary_fr.sqlite reconstruite : 838 180 termes inchangés
  (integrity_check = ok), 734 622 avec pos, 123 563 lignes verb_forms
  déterminisme vérifié : reconstruction x2, comparaison octet à octet
budget runtime : 9 requêtes indexées sur dictionary_fr.sqlite par fiche
  pour un mot admis (8 existantes + 1), 4 pour un mot français non admis
  (3 + 1) — sous la limite de moins de 10 requêtes DICTIONNAIRE (CLAUDE.md).
  Aucune requête supplémentaire pour pos/pos_secondary/gender (colonnes
  ajoutées au SELECT déjà exécuté par TermLookup).
  reports/query-plans/d018-conjugation.md : toutes les requêtes passent
  par un index, aucun SCAN TABLE
  correction de traçabilité (audit final, code-reviewer, constat I2) :
  depuis la Phase 6, chaque fiche exécute EN PLUS 1 requête indexée sur
  seo_fr.sqlite (App\Seo\Registry::resolve()), soit 10 requêtes SQLite
  au total tous fichiers confondus — mesurée à 0,035 ms (code-optimizer),
  sans impact de performance. Ce chiffre-ci n'était pas compté ci-dessus ;
  voir D-003 pour la convention de budget retenue entre les deux bases
test de non-régression explicite : SUIS/SOMMES/SONT/VAIS/VONT ne doivent
  jamais apparaître comme forme conjuguée de SUIVRE/SOMMER/SONORISER/
  VAIRONNER/VOTER (tests/Search/ConjugationLookupTest.php)
rendu (app/View/) : à faire dans un second temps, hors périmètre de
  cette décision — data-engine livre la donnée, frontend l'affiche
```

## D-019 — Recherche "Contenant/Avec/Sans" Sans Ancrage : Correction Et Compromis De Performance Assumé

Date : 2026-08-06
Statut : accepté

Contexte :

```text
la refonte de la home et l'ajout du hub /mots (cette session) exposent en
  premier plan des recherches "contenant"/"avec"/"sans" SANS aucune
  longueur/début/fin fournis en complément (outil "Contenant" du hub,
  champs "Contient la suite"/"Lettres obligatoires"/"Sans les lettres" du
  constructeur home, liens "Voir les N mots" des fiches mots)
App\Search\WordListSolver::solveBounded() bornait alors le panier ANCRÉ
  (vide dans ce cas) à ROW_EXAMINATION_CEILING lignes AVANT d'appliquer
  ces prédicats -- pas après. Sans ancrage, "avant" = les 10 000 premiers
  mots de la base dans l'ordre alphabétique complet, pas un sous-ensemble
  pertinent : un mot comme "contenant XYL" (270 correspondances réelles,
  aucune dans les 10 000 premiers mots alphabétiques) répondait "0 mot
  trouvé" -- faux négatif silencieux, trouvé par l'audit final
  (code-reviewer, constat C1, bloquant)
```

Décision :

```text
anchorClause() (index) et extraPredicates() (SQL pur, non indexé) sont
  désormais combinés en UNE SEULE clause WHERE, appliquée ensemble à la
  fois pour le comptage de plafond et pour la récupération -- même
  principe que RelationsFinder::containingWords() (Phase 4, déjà validé) :
  le LIMIT porte sur le nombre de CORRESPONDANCES trouvées, jamais sur
  les lignes lues avant filtrage
optimisation mesurée dans la foulée : le prédicat "avec" utilisait
  LENGTH(normalized) - LENGTH(REPLACE(normalized, ?, '')) pour compter les
  occurrences, y compris quand une seule occurrence suffit (minCount = 1,
  cas majoritaire) -- remplacé par instr(normalized, ?) > 0 dans ce cas
  précis (~4x plus rapide, REPLACE() alloue une nouvelle chaîne à chaque
  appel), LENGTH/REPLACE conservé uniquement pour minCount >= 2 (lettre
  répétée exigée)
```

Correction de cadrage (3e passe d'audit, code-reviewer, bloquant C-1) : la première version de
cette décision présentait le dépassement ci-dessous comme un compromis de PERFORMANCE (budget
TTFB, une cible à respecter au mieux). C'était incomplet : CLAUDE.md range « scan complet de la
table (~838 000 lignes) au runtime » dans la section **Interdits** (même registre que
« React/Vue/SPA »), pas dans les cibles de performance -- une règle absolue, pas un objectif
négociable. `WordListSolver.php` applique d'ailleurs déjà cette règle pour rejeter `/mots` seul
(aucune contrainte du tout). Techniquement, le plan `EXPLAIN QUERY PLAN` de la requête sans
ancrage n'est pas un `SCAN TABLE` littéral (il passe par l'index couvrant
`sqlite_autoindex_terms_1`, confirmé par code-optimizer) -- mais le coût est fonctionnellement
identique à un parcours complet (~95 ms plancher structurel mesuré pour 838 180 lignes, même
sans aucun résultat). Deux atténuations ont donc été ajoutées après cette relecture, voir
« Décision (complément) » ci-dessous.

Compromis assumé, mesuré et accepté en connaissance de cause :

```text
avec ancrage (longueur/début/fin présent) : rapide dans la quasi-totalité
  des cas (0,9 à 80 ms mesurés) -- UNE régression trouvée par code-optimizer
  (3e passe) sur le cas pathologique déjà documenté dans phase3.md
  (longueur = 11, prédicat "avec" à lettres répétées et correspondance quasi
  nulle) : 14,7 ms avant C1, ~220 ms après C1 (première version de cette
  décision affirmait à tort "1 à 35 ms, inchangé" -- non re-mesuré à
  l'époque). Résolu par le complément ci-dessous (fusion des requêtes) :
  re-mesuré à 58,8 ms médiane après complément
sans aucun ancrage : la requête doit parcourir une grande partie des
  838 180 lignes pour garantir un résultat correct (elle ne peut plus
  s'arrêter tôt sur un sous-ensemble arbitraire) -- mesuré initialement
  entre 240 ms et 335 ms sur les cas les plus défavorables, au-dessus du
  budget TTFB p95 < 250 ms de CLAUDE.md ; ramené à 120-195 ms médiane après
  le complément ci-dessous (fusion des requêtes), plancher structurel
  ~95 ms incompressible sans index dédié (voir "suite à donner")
alternative écartée : plafonner aussi les lignes EXAMINÉES (pas seulement
  les correspondances) pour ce cas précis garantirait une latence basse,
  mais réintroduirait une forme atténuée du même défaut (un motif rare
  pourrait de nouveau être sous-compté au-delà du plafond d'examen) --
  jugé pire que le compromis retenu : mieux vaut une réponse correcte
  occasionnellement lente qu'une réponse rapide parfois fausse
```

Décision (complément, 3e passe d'audit -- deux atténuations à coût nul, aucune fonctionnalité
retirée) :

```text
1. Fusion des deux requêtes de solveBounded() en une seule quand l'ancrage
   est déjà l'ordre d'affichage (normalized) -- la requête de plafond et
   celle de récupération exécutaient exactement le même parcours pour n'en
   extraire qu'un booléen (constat code-optimizer, I-1). LIMIT
   CEILING + 1 sur la requête de récupération, truncated déduit du nombre
   de lignes rendues, la ligne surnuméraire retirée par array_pop(). Gain
   mesuré : 35 à 50 % sur les cas sans ancrage, corrige AUSSI la régression
   du cas ancré pathologique ci-dessus (14,666 ms -> ~220 ms -> 58,8 ms).
   Non appliqué à l'ancrage sur suffixe (reversed) : l'ordre d'ancrage y
   diffère de l'ordre d'affichage, chemin déjà rapide (27-53 ms), reste à
   2 requêtes plutôt que de risquer une erreur de tri
2. Retrait des liens "/mots/contenant/{sous-chaîne}" SANS ancrage
   auto-générés sur CHAQUE fiche de mot admis (RelationsFinder::
   relatedSearches(), 2 liens inconditionnels par mot ; app/View/word.php,
   1 lien conditionnel pour la catégorie "mot inséré") -- c'était la partie
   réellement grave du problème, pas la latence en elle-même : mesuré à
   ~1 675 000 liens follow distincts, émis depuis 403 060 pages toutes
   index,follow (D-017), chacun déclenchant le parcours coûteux dès qu'un
   robot le FETCH pour découvrir son noindex (Family::WORD_LIST_CONTENANT
   reste noindex,follow, mais ça ne dispense pas du coût de la requête).
   Un crawl normal du site aurait donc sollicité ce chemin des centaines de
   milliers de fois -- risque d'épuisement du pool de workers PHP sur
   hébergement mutualisé, pas seulement un dépassement de budget TTFB
   occasionnel pour un utilisateur isolé. L'outil "Contenant" du hub /mots
   et le champ "Contient la suite" du constructeur home restent inchangés
   (saisie humaine volontaire, jamais générée en masse) : aucune
   fonctionnalité utilisable directement par un visiteur n'est retirée
```

Raison de choisir la correction (résultats justes) plutôt que la restriction (exiger un ancrage) :

```text
le hub /mots et le constructeur home promettent explicitement une
  recherche "Contenant"/"avec"/"sans" utilisable seule (jusqu'à 3 lettres
  pour "Contenant", voir app/View/explore-hub.php) -- restreindre l'UI
  aurait supprimé une fonctionnalité déjà livrée à l'utilisateur plutôt
  que de corriger le bug qui la rendait fausse
un résultat lent mais juste est un problème de performance, mesurable et
  isolable ; un résultat rapide mais faux est un problème de confiance
  silencieux, qu'aucune mesure de performance ne révèle jamais
  le retrait des liens auto-générés (complément ci-dessus) réduit
  l'exposition réelle à une saisie humaine volontaire, seul contexte où
  "occasionnellement lent" redevient une description honnête
```

Suite à donner, non bloquante :

```text
re-mesurer en Phase 7 après déploiement réel sur o2switch, sous charge
  concurrente (plusieurs workers), pour confirmer ou infirmer l'ampleur du
  dépassement résiduel en conditions réelles -- voir reports/query-plans/
  phase3-c1-fix.md pour le protocole de mesure proposé
plancher structurel mesuré ~95 ms pour un parcours complet de l'index
  normalized sans aucun ancrage (COUNT() sans prédicat, ou instr() qui ne
  matche jamais) -- incompressible par une fusion de requêtes ou une
  optimisation de prédicat, seul un index dédié (trigrammes/lettres,
  D-012) ou un ancrage obligatoire y échapperait. Si la marge résiduelle se
  révèle insuffisante en Phase 7, ces deux pistes déjà écartées restent
  disponibles pour réexamen
```
