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
    reversed     TEXT    NOT NULL
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

-- Empreintes des sources et paramètres du build. Aucune date d'exécution :
-- l'import doit rester déterministe et rejouable à l'identique.
-- "key" et "value" sont entre guillemets : ils ne sont pas réservés en SQLite,
-- mais le sont dans d'autres dialectes, ce qui fait crier les linters SQL sur
-- un fichier que plusieurs agents vont relire.
CREATE TABLE build_metadata (
    "key"   TEXT PRIMARY KEY,
    "value" TEXT NOT NULL
);
