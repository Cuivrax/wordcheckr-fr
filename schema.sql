-- Scrabble Light — schéma de production français.
-- Fichier canonique, sous contrôle de la session principale.
-- Produit par scripts/import_fr.py dans storage/dictionary_fr.sqlite.
-- Ouvert en lecture seule au runtime. Aucune définition n'y est copiée (D-004).

CREATE TABLE terms (
    id           INTEGER PRIMARY KEY,
    display_term TEXT    NOT NULL,
    normalized   TEXT    NOT NULL UNIQUE,

    is_french    INTEGER NOT NULL DEFAULT 0 CHECK (is_french IN (0, 1)),
    is_ods8      INTEGER NOT NULL DEFAULT 0 CHECK (is_ods8   IN (0, 1)),
    is_ods9      INTEGER NOT NULL DEFAULT 0 CHECK (is_ods9   IN (0, 1)),

    score        INTEGER NOT NULL,
    length       INTEGER NOT NULL CHECK (length >= 2),
    signature    TEXT    NOT NULL,
    reversed     TEXT    NOT NULL,

    -- D-018 : nature grammaticale et genre, sur CHAQUE terme (admis ou non), independants
    -- du statut Scrabble. Source : data/raw/french_dict.db (Kartmaan), colonnes pos/gender,
    -- lignes NP et locutions exclues (meme regle que le filtre d'import francais). NULL si
    -- aucune ligne Kartmaan retenue ne couvre ce terme (~12,3 % des lignes, formes venues
    -- uniquement de hbenbel ou des additions ODS9) -- absence de donnee, pas une erreur.
    --
    -- pos/pos_secondary : jeu ferme de 9 codes, reduit depuis les valeurs brutes Kartmaan
    -- (flex-nom -> N, flex-verb -> V, flex-adj/adj-num/adj-pos/adj-int/adj-excl -> Adj,
    -- flex-adv -> Adv, flex-interj -> Interj, pronom-*/flex-pronom -> Pronom, prep -> Prep,
    -- conj-coord/flex-conj -> Conj, art-part -> Art). Deux colonnes seulement : mesure sur
    -- les 838 180 lignes, un seul pos ne suffit qu'a 87,9 % des termes couverts (homographes
    -- frequents nom/adjectif de gentile, nom/verbe type TABLE/tabler) -- le troisieme sens
    -- eventuel (0,37 % des termes) est perdu, juge negligeable plutot que sur-ingenierie.
    -- Premier et second pos distincts rencontres dans l'ordre "id" des lignes Kartmaan
    -- (meme convention de determinisme que ORDER BY forme, id de load_kartmaan()).
    --
    -- gender : calcule independamment de pos/pos_secondary -- premiere ligne (ordre id) ou
    -- pos = 'N' et gender non vide, meme si 'N' n'est pas retenu comme sens primaire (couvre
    -- TABLE : pos primaire V, gender du sens nom rapporte quand meme). Trois valeurs
    -- reelles constatees dans la source, pas deux : 'e' = epicene (meme forme aux deux
    -- genres, ex. ENFANT, ELEVE, ARTISTE), 1 861 lignes Kartmaan, jamais associee a un
    -- pos autre que 'N'.
    pos           TEXT DEFAULT NULL
        CHECK (pos IN ('N','V','Adj','Adv','Pronom','Prep','Conj','Interj','Art') OR pos IS NULL),
    pos_secondary TEXT DEFAULT NULL
        CHECK (pos_secondary IN ('N','V','Adj','Adv','Pronom','Prep','Conj','Interj','Art') OR pos_secondary IS NULL),
    gender        TEXT DEFAULT NULL
        CHECK (gender IN ('m','f','e') OR gender IS NULL)
);

-- La contrainte UNIQUE sur normalized crée déjà son propre index.
-- Un CREATE INDEX supplémentaire sur cette seule colonne serait redondant :
-- il est délibérément absent.

-- Longueur puis ordre alphabétique : /mots/7-lettres et ses paginations.
CREATE INDEX idx_terms_length_normalized ON terms(length, normalized);

-- Anagrammes exactes, et point de départ des anagrammes ±1 lettre.
CREATE INDEX idx_terms_signature ON terms(signature);

-- Suffixes : /mots/terminant/tion interroge reversed par PLAGE, jamais par LIKE.
--
--   correct   WHERE reversed >= 'NOIT' AND reversed < 'NOIU'   -> index, 0,20 ms
--   interdit  WHERE reversed LIKE 'NOIT%'                      -> SCAN complet
--
-- LIKE est insensible à la casse par défaut dans SQLite : l'optimiseur ne peut
-- pas l'adosser à un index BINARY, et la requête dégénère en balayage des
-- 850 000 lignes. La même règle vaut pour les préfixes sur normalized.
CREATE INDEX idx_terms_reversed ON terms(reversed);

-- Familles restreintes à une édition, en ordre alphabétique.
-- Index couvrants : ils servent aussi bien le filtre que le tri.
CREATE INDEX idx_terms_ods8 ON terms(is_ods8, normalized);
CREATE INDEX idx_terms_ods9 ON terms(is_ods9, normalized);

-- Pas d'index sur is_french : la colonne vaut 1 sur toutes les lignes de la base
-- française — un terme présent est français par construction, la distinction
-- utile est portée par is_ods8 et is_ods9. La colonne reste au schéma car le
-- code est partagé avec le futur site anglais, où l'équivalent is_english
-- n'aura pas la même constance.

-- D-018 : liens de conjugaison verbale, construits depuis data/raw/hbenbel/verb.csv.
--
-- La source est un tri alphabetique global sur "form", PAS des blocs contigus par verbe
-- (une pre-verification affirmant le contraire s'est revelee fausse a la verification
-- directe -- voir reports/ pour le detail). L'association forme -> lemme est reconstruite
-- au build par plus-long-prefixe-commun contre la liste des infinitifs connus ; les lemmes
-- dont trop peu de formes s'apparient sur eux-memes (verbes suppletifs -- ETRE, AVOIR,
-- ALLER, DEVOIR, VALOIR, VOIR... -- own_count < 20 mesure, 281 lemmes sur 6 697) sont
-- exclus plutot que de risquer un lien de conjugaison faux -- voir scripts/import_fr.py.
--
-- Selection representative, pas le paradigme complet (~50 formes/verbe dans la source) :
-- present, futur simple, imparfait (indicatif, 6 personnes chacun), participe present,
-- participe passe (forme de base seule, sans accord de genre/nombre -- hors perimetre,
-- comme flex-adj/flex-nom). Jusqu'a 20 formes par lemme retenu.
--
-- lemma_normalized et form_normalized referencent tous deux terms.normalized ; garanti par
-- construction du script de build (D-010 applique automatiquement : toute forme ou lemme de
-- plus de 15 lettres est deja absent de terms, donc jamais insere ici). FOREIGN KEY
-- documentaire -- non applique par SQLite sans PRAGMA foreign_keys, absent au runtime
-- (lecture seule, D-001) ; la coherence est garantie par le script de build, pas par le
-- moteur.
CREATE TABLE verb_forms (
    id               INTEGER PRIMARY KEY,
    lemma_normalized TEXT NOT NULL REFERENCES terms(normalized),
    form_normalized  TEXT NOT NULL REFERENCES terms(normalized),
    tense            TEXT NOT NULL
        CHECK (tense IN ('present', 'future', 'imperfect', 'participle_present', 'participle_past')),
    person           TEXT
        CHECK (person IN ('1s', '2s', '3s', '1p', '2p', '3p') OR person IS NULL),

    UNIQUE (lemma_normalized, form_normalized, tense, person)
);

-- Formes conjuguees d'un lemme ("POSER a pour formes POSERA, POSE, ...").
CREATE INDEX idx_verbforms_lemma ON verb_forms(lemma_normalized);
-- Lemme d'une forme conjuguee ("POSERA est une forme de POSER") -- lookup inverse.
CREATE INDEX idx_verbforms_form ON verb_forms(form_normalized);

-- Comptes precalcules de la page hub /mots (longueur, commencant, terminant -- 66 lignes
-- fixes), produite hors ligne par scripts/build_explore_hub_counts.php, jamais au runtime.
-- Un GROUP BY sur substr(normalized,1,1)/substr(reversed,1,1) n'a aucun index disponible sur
-- l'expression calculee : 245 ms et 215 ms mesures sur les 838 180 lignes reelles (SCAN
-- complet + TEMP B-TREE), tres au-dessus du budget TTFB p95 < 250 ms pour une seule page
-- (CLAUDE.md). Lue par App\Search\ExploreHubBuilder en une requete triviale (1 ms, 66
-- lignes), jamais un GROUP BY sur `terms` au runtime.
CREATE TABLE list_counts (
    list_type TEXT    NOT NULL CHECK (list_type IN ('length', 'start', 'end')),
    list_key  TEXT    NOT NULL,
    count     INTEGER NOT NULL,

    PRIMARY KEY (list_type, list_key)
);

-- Empreintes des sources et paramètres du build. Aucune date d'exécution :
-- l'import doit rester déterministe et rejouable à l'identique.
-- "key" et "value" sont entre guillemets : ils ne sont pas réservés en SQLite,
-- mais le sont dans d'autres dialectes, ce qui fait crier les linters SQL sur
-- un fichier que plusieurs agents vont relire.
CREATE TABLE build_metadata (
    "key"   TEXT PRIMARY KEY,
    "value" TEXT NOT NULL
);
