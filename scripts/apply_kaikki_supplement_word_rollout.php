<?php

declare(strict_types=1);

/**
 * Applique en registre le DELTA des formes françaises non admises ajoutées par le complément
 * kaikki (D-051/D-052) : storage/dictionary_fr.sqlite est passée de 838 180 à 844 961 termes
 * (+6 781, toutes is_french=1/is_ods8=0/is_ods9=0 -- toponymes/noms propres/registre marqué,
 * voir docs/DECISIONS.md D-051), mais storage/seo_fr.sqlite (dernière écriture 2026-09-01,
 * AVANT ce rebuild) ne les couvre pas encore : la famille word_french_not_admitted y est
 * toujours à son ancien total (435 120).
 *
 * DIFFÉRENT de scripts/apply_full_word_rollout.php (qui réapplique les DEUX familles au
 * complet, en réassignant tous les sitemap_fragment depuis zéro par ordre alphabétique) :
 * ce script-ci n'insère QUE les lignes réellement nouvelles, et REMPLIT D'ABORD le dernier
 * fragment invalid-french déjà partiellement occupé plutôt que de tout réassigner --
 * decision produit explicite (coordinateur, 2026-09-02) : éviter le bruit inutile sur les
 * sitemap_fragment de ~435 120 lignes déjà en place (aucun changement de robots/canonical
 * n'en découlerait de toute façon, mais autant ne pas le provoquer sans raison). word_admitted
 * n'est pas concerné par ce script : son total (403 060) est inchangé par D-051/D-052.
 *
 * Comment le delta est identifié (jamais supposé) :
 *   1. lit `terms` (storage/dictionary_fr.sqlite, lecture seule) : toutes les formes
 *      is_ods8=0 AND is_ods9=0 (441 901 attendues) ;
 *   2. lit `registry` (storage/seo_fr.sqlite) : toutes les routes déjà en famille
 *      word_french_not_admitted (435 120 attendues), route_path -> forme normalisée ;
 *   3. delta = (1) moins (2) -- les formes présentes dans le dictionnaire mais absentes du
 *      registre. Comparaison faite en PHP (les deux bases sont des fichiers SQLite séparés,
 *      D-002/D-003, jamais de jointure inter-bases) ;
 *   4. CONTRÔLE CROISÉ INDÉPENDANT (pas une simple tautologie interne) : chaque forme du
 *      delta doit apparaître dans data/kaikki_supplement/{names,register}_definitions_final.csv
 *      (colonne `normalized`, déjà vérifiée par D-051 lui-même) -- si une seule forme du delta
 *      n'y figure pas, c'est le signal qu'un AUTRE changement non documenté a eu lieu entre
 *      temps (ou que ce script tourne sur un état inattendu) : le script s'arrête net plutôt
 *      que d'appliquer un delta dont l'origine n'est plus prouvée.
 *
 * Garde-fous R1/R3/R4/R5/R7 (mêmes conventions que apply_full_word_rollout.php et
 * apply_seo_batch.php) :
 *   - route_path = canonical_path partout (R3, jamais d'alias) ;
 *   - forme /mot/{slug}, slug = strtolower(normalized), normalized ^[A-Z]{2,15}$ (D-009/D-010)
 *     vérifié explicitement plutôt que supposé (R4) ;
 *   - robots = 'index,follow' uniquement pour des formes réellement présentes dans
 *     storage/dictionary_fr.sqlite (R5 ne s'applique pas au sens "compte de résultats" : ces
 *     pages n'ont pas de result_count, comme /mot/{mot} partout ailleurs) ;
 *   - note non vide sur chaque ligne (R7), reprend la formulation déjà utilisée pour les
 *     435 120 lignes existantes de la même famille (D-017), complétée de la référence D-051 ;
 *   - INSERT (jamais INSERT OR REPLACE) : une collision de route_path avec une ligne déjà
 *     présente ferait échouer la transaction plutôt que de la remplacer silencieusement --
 *     un tel cas ne devrait structurellement jamais se produire (le delta est calculé comme
 *     un complément du registre existant), donc une collision serait un vrai bug à ne pas
 *     masquer.
 *
 * Usage : php scripts/apply_kaikki_supplement_word_rollout.php
 *
 * Variables d'environnement (réservées aux tests, tests/Seo/, jamais définies en usage
 * normal -- même discipline que scripts/check_combinatorial_duplicates.php) :
 *     SCRABBLE_DICTIONARY_DB_PATH   defaut storage/dictionary_fr.sqlite
 *     SCRABBLE_SEO_DB_PATH          defaut storage/seo_fr.sqlite
 *     SCRABBLE_KAIKKI_SUPPLEMENT_DIR defaut data/kaikki_supplement
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "scripts/apply_kaikki_supplement_word_rollout.php ne s'execute qu'en CLI, hors ligne.\n");
    exit(1);
}

// Retient en memoire jusqu'a trois ensembles de l'ordre de 400-450k chaines courtes en
// parallele (non-admis dictionnaire, deja-en-registre, kaikki CSV) -- au-dela du defaut CLI
// (128 Mo) sur ce volume, mesure necessaire plutot que suppose sans danger.
ini_set('memory_limit', '512M');

const FRAGMENT_PREFIX = 'invalid-french';

// Plafond de production (docs/05 : 40 000 URL max par fragment) volontairement surchargeable
// -- reserve aux tests (tests/Seo/), meme convention que SCRABBLE_SEO_MAX_FRENCH_NOT_ADMITTED
// dans scripts/apply_seo_batch.php : permet d'exercer reellement le rollover d'un fragment a
// l'autre sans construire 40 000 lignes de fixture.
$fragmentSize = (int) (getenv('SCRABBLE_KAIKKI_FRAGMENT_SIZE') ?: 40_000);

$root = dirname(__DIR__);
$dictPath = getenv('SCRABBLE_DICTIONARY_DB_PATH') ?: $root . '/storage/dictionary_fr.sqlite';
$seoPath = getenv('SCRABBLE_SEO_DB_PATH') ?: $root . '/storage/seo_fr.sqlite';
$kaikkiDir = getenv('SCRABBLE_KAIKKI_SUPPLEMENT_DIR') ?: $root . '/data/kaikki_supplement';

if (!is_file($dictPath)) {
    fwrite(STDERR, "dictionnaire introuvable : {$dictPath}\n");
    exit(1);
}

if (!is_file($seoPath)) {
    fwrite(STDERR, "registre introuvable, lancer d'abord : php scripts/build_seo_registry.php\n");
    exit(1);
}

/**
 * @return array<string, true>
 */
$loadKaikkiNormalizedSet = static function (string $dir): array {
    $set = [];

    foreach (['names_definitions_final.csv', 'register_definitions_final.csv'] as $fileName) {
        $path = $dir . '/' . $fileName;

        if (!is_file($path)) {
            fwrite(STDERR, "fichier kaikki manquant, controle croise impossible : {$path}\n");
            exit(1);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            fwrite(STDERR, "lecture impossible : {$path}\n");
            exit(1);
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');

        if ($header === false || $header[0] !== 'normalized') {
            fwrite(STDERR, "en-tete inattendu (colonne 'normalized' absente) : {$path}\n");
            exit(1);
        }

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }

            $set[$row[0]] = true;
        }

        fclose($handle);
    }

    return $set;
};

$kaikkiNormalized = $loadKaikkiNormalizedSet($kaikkiDir);
printf("controle croise : %d formes distinctes lues depuis data/kaikki_supplement/*.csv (D-051 : 6 781 attendues)\n", count($kaikkiNormalized));

$dict = new PDO('sqlite:' . $dictPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dict->exec('PRAGMA query_only = ON');

$seo = new PDO('sqlite:' . $seoPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// --- 1. Formes non admises reelles du dictionnaire (ordre alphabetique, jamais un fetchAll --
// stream direct dans un tableau associatif, meme volume que apply_full_word_rollout.php). ---
$nonAdmittedOrdered = [];
$statement = $dict->query('SELECT normalized FROM terms WHERE is_ods8 = 0 AND is_ods9 = 0 ORDER BY normalized');
foreach ($statement as $row) {
    $nonAdmittedOrdered[] = $row['normalized'];
}
unset($statement);

// --- 2. Formes deja en registre pour cette famille. ---
$alreadyInRegistry = [];
$existingStatement = $seo->query("SELECT route_path FROM registry WHERE family = 'word_french_not_admitted'");
foreach ($existingStatement as $row) {
    // route_path = '/mot/{slug}' -- slug retire, remis en majuscules pour comparer a
    // `normalized` (D-009). str_starts_with verifie plutot que suppose la forme.
    $routePath = $row['route_path'];
    if (!str_starts_with($routePath, '/mot/')) {
        fwrite(STDERR, "route_path inattendue pour word_french_not_admitted : {$routePath}\n");
        exit(1);
    }
    $alreadyInRegistry[strtoupper(substr($routePath, strlen('/mot/')))] = true;
}
unset($existingStatement);

printf("dictionnaire (non admis) : %d formes -- registre (word_french_not_admitted) : %d formes deja appliquees\n", count($nonAdmittedOrdered), count($alreadyInRegistry));

// --- 3. Delta, ordre alphabetique preserve (la requete source etait deja ORDER BY normalized). ---
$newWords = [];
foreach ($nonAdmittedOrdered as $normalized) {
    if (!isset($alreadyInRegistry[$normalized])) {
        $newWords[] = $normalized;
    }
}

if ($newWords === []) {
    echo "aucune forme nouvelle a appliquer -- registre deja a jour.\n";
    exit(0);
}

printf("delta calcule : %d formes nouvelles a appliquer\n", count($newWords));

// --- 4. Controle croise independant contre la source documentee (D-051) : chaque forme du
// delta doit provenir du complement kaikki, jamais un delta d'origine inconnue applique
// silencieusement. ---
$unverified = [];
foreach ($newWords as $normalized) {
    if (!isset($kaikkiNormalized[$normalized])) {
        $unverified[] = $normalized;
    }
}

if ($unverified !== []) {
    fwrite(STDERR, sprintf(
        "ABANDON : %d forme(s) du delta absente(s) des CSV kaikki (D-051) -- origine non prouvee, exemples : %s\n",
        count($unverified),
        implode(', ', array_slice($unverified, 0, 20)),
    ));
    exit(1);
}

echo "controle croise : 100% du delta provient bien du complement kaikki documente (D-051).\n";

// --- 5. Forme de route_path validee explicitement (R4 : jamais suppose). ---
foreach ($newWords as $normalized) {
    if (preg_match('/^[A-Z]{2,15}$/', $normalized) !== 1) {
        fwrite(STDERR, "forme normalisee hors gabarit D-009/D-010 : {$normalized}\n");
        exit(1);
    }
}

// --- 6. Continuation du dernier fragment invalid-french partiel (pas de reassignation des
// lignes deja appliquees). ---
$fragmentCounts = [];
$fragmentStatement = $seo->query(
    "SELECT sitemap_fragment, COUNT(*) c FROM registry WHERE family = 'word_french_not_admitted' "
    . 'GROUP BY sitemap_fragment ORDER BY sitemap_fragment'
);
foreach ($fragmentStatement as $row) {
    $fragmentCounts[$row['sitemap_fragment']] = (int) $row['c'];
}
unset($fragmentStatement);

$lastFragmentIndex = 0;
$lastFragmentCount = 0;

foreach ($fragmentCounts as $fragmentName => $count) {
    if (preg_match('/^' . preg_quote(FRAGMENT_PREFIX, '/') . '-(\d{4})$/', $fragmentName, $matches) !== 1) {
        fwrite(STDERR, "nom de fragment inattendu pour word_french_not_admitted : {$fragmentName}\n");
        exit(1);
    }
    $index = (int) $matches[1];
    if ($index > $lastFragmentIndex) {
        $lastFragmentIndex = $index;
        $lastFragmentCount = $count;
    }
}

if ($lastFragmentIndex === 0) {
    // Registre vide pour cette famille (cas des tests, ou d'un registre neuf) : demarre a 1.
    $lastFragmentIndex = 1;
    $lastFragmentCount = 0;
}

printf("continuation : dernier fragment %s-%04d a %d/%d URL\n", FRAGMENT_PREFIX, $lastFragmentIndex, $lastFragmentCount, $fragmentSize);

// --- 7. Insertion, une seule transaction, jamais INSERT OR REPLACE (une collision est un
// bug reel a ne pas masquer -- voir docblock). ---
$addedAt = gmdate('Y-m-d');
$batchId = 'word_french_not_admitted-kaikki-supplement-' . $addedAt;
$notes = 'Forme francaise du complement kaikki (D-051/D-052, toponymes/noms propres/registre '
    . 'marque -- curation manuelle verifiee, 0 collision avec ODS8/ODS9, is_french=1). Meme '
    . 'politique que les 435 120 formes francaises non admises deja indexees (D-017) : page '
    . 'utile pour repondre a "ce mot est-il admis ?" cote negatif. Atteinte depuis les listes '
    . '/mots/... deja indexees (longueur, commencant, terminant), les relations d\'autres '
    . 'fiches mot (Phase 4, RelationsFinder etendu aux non-admis par D-050), et la navigation '
    . 'mot precedent/suivant.';

$insert = $seo->prepare(
    'INSERT INTO registry '
    . '(route_path, family, robots, canonical_path, sitemap_fragment, batch_id, result_count, notes, added_at) '
    . 'VALUES (?, \'word_french_not_admitted\', \'index,follow\', ?, ?, ?, NULL, ?, ?)'
);

$seo->beginTransaction();

$fragmentIndex = $lastFragmentIndex;
$countInFragment = $lastFragmentCount;
$insertedCount = 0;
/** @var array<string, int> */
$fragmentsUsed = [];

foreach ($newWords as $normalized) {
    if ($countInFragment >= $fragmentSize) {
        $fragmentIndex++;
        $countInFragment = 0;
    }
    $countInFragment++;
    $insertedCount++;

    $slug = strtolower($normalized);
    $routePath = '/mot/' . $slug;
    $fragment = sprintf('%s-%04d', FRAGMENT_PREFIX, $fragmentIndex);
    $fragmentsUsed[$fragment] = ($fragmentsUsed[$fragment] ?? 0) + 1;

    $insert->execute([$routePath, $routePath, $fragment, $batchId, $notes, $addedAt]);
}

$seo->commit();

printf("%d ligne(s) inseree(s), batch_id = %s\n", $insertedCount, $batchId);
foreach ($fragmentsUsed as $fragment => $count) {
    printf("  %s : +%d URL (total apres application : %d/%d)\n", $fragment, $count, ($fragmentCounts[$fragment] ?? 0) + $count, $fragmentSize);
}

$totalCount = $seo->query('SELECT COUNT(*) c FROM registry')->fetch()['c'];
$indexCount = $seo->query("SELECT COUNT(*) c FROM registry WHERE robots = 'index,follow'")->fetch()['c'];
$familyCount = $seo->query("SELECT COUNT(*) c FROM registry WHERE family = 'word_french_not_admitted'")->fetch()['c'];

printf("registre apres application : %d lignes au total, %d en 'index,follow', %d dans word_french_not_admitted\n", $totalCount, $indexCount, $familyCount);
