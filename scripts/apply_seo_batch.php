<?php

declare(strict_types=1);

/**
 * Applique un lot de rollout au registre storage/seo_fr.sqlite (Phase 6, docs/08).
 *
 * Hors ligne uniquement (CLI). Aucun lot n'est fourni ni appliqué par ce dépôt à ce stade --
 * ce script est l'OUTIL générique qui appliquera un lot une fois qu'il aura été validé
 * explicitement (CLAUDE.md : "arrête-toi et attends validation explicite avant de figer un
 * premier lot de rollout concret"). Le construire maintenant, sans l'exécuter sur un lot réel,
 * permet de vérifier que les règles dures sont appliquées par l'OUTIL et pas seulement décrites
 * en prose -- un lot mal formé échoue à l'exécution, pas seulement à la relecture humaine.
 *
 * Usage :
 *     php scripts/apply_seo_batch.php path/to/batch.php [--force]
 *
 * Format d'un fichier de lot (PHP, jamais exécuté au runtime) : retourne un tableau
 *
 *   [
 *       'batch_id' => 'admitted-2026-08-10-pilot',
 *       'added_at' => '2026-08-10',           // optionnel, defaut = date du jour (UTC)
 *       'rows' => [
 *           [
 *               'route_path' => '/mot/poser',
 *               'family' => App\Seo\Family::WORD_ADMITTED,
 *               'robots' => 'index,follow',
 *               'canonical_path' => '/mot/poser',   // optionnel, defaut = route_path
 *               'sitemap_fragment' => 'words-0001', // optionnel, defaut = null
 *               'result_count' => null,             // optionnel, uniquement pour /mots/...
 *               'notes' => 'mot tres frequent, maillage riche (relations + recherches liees)',
 *           ],
 *           // ...
 *       ],
 *   ]
 *
 * Règles dures appliquées ICI, en plus de la contrainte CHECK du schéma (robots fermé à deux
 * valeurs) -- un lot qui viole l'une d'entre elles est refusé EN BLOC, aucune ligne n'est
 * écrite (transaction unique) :
 *
 *   R1  route_path doit commencer par '/', family doit être une valeur connue
 *       (App\Seo\Family::ALL), robots doit être 'index,follow' ou 'noindex,follow'.
 *   R2  aucun doublon de route_path DANS le lot.
 *   R3  une ligne 'index,follow' dont canonical_path != route_path est refusée -- ce registre
 *       ne sert jamais de mécanisme d'alias indexable (chaque permutation non canonique est
 *       déjà éliminée par une redirection 301 en amont, WordListFilters::canonicalPath() /
 *       TermPage::$slug / RackPage::$slug -- si canonical_path diverge ici, c'est que la route
 *       n'est PAS le gagnant canonique, donc jamais 'index,follow').
 *   R4  une famille de App\Seo\Family::NEVER_SITEMAP (contenant, avec, sans, motif, listes
 *       combinées, jouer/{lettres} -- combinaisons infinies, docs/05 n'en documente d'ailleurs
 *       aucun fragment de sitemap) ne peut JAMAIS recevoir robots='index,follow' ni
 *       sitemap_fragment non nul, quel que soit le lot.
 *   R5  result_count === 0 avec robots='index,follow' est refusé (page à résultat vide jamais
 *       indexable). result_count === 1 N'EST PAS refusé (docs/05 : jamais sur le seul compteur)
 *       -- seulement compté séparément dans le rapport imprimé par ce script.
 *   R6  une famille de App\Seo\Family::FRENCH_NOT_ADMITTED ne peut pas dépasser
 *       App\Seo\Family::MAX_BATCH_SIZE_FRENCH_NOT_ADMITTED lignes 'index,follow' dans UN SEUL
 *       lot ("Never propose indexing these in bulk"), et chaque ligne de cette famille doit
 *       porter une note non vide (attestation de vérification manuelle -- genuinely French,
 *       absent d'ODS8/ODS9, utile, recherché ou fréquent).
 *   R7  toute ligne 'index,follow' doit porter une note non vide décrivant au moins son
 *       maillage interne prévu -- attestation humaine, PAS une vérification automatique du
 *       graphe de liens (hors de portée de ce script ; relève de l'audit seo-technical-auditor).
 *
 * Sans --force, une ligne dont route_path existe déjà en base avec un batch_id DIFFÉRENT est
 * refusée (protège l'historique d'un lot précédent contre un écrasement accidentel par un
 * lot mal ciblé). --force autorise explicitement le remplacement.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "scripts/apply_seo_batch.php ne s'execute qu'en CLI, hors ligne.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/Seo/Family.php';

use App\Seo\Family;

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$args = array_values(array_filter($args, static fn (string $a): bool => $a !== '--force'));

if (count($args) !== 1) {
    fwrite(STDERR, "usage : php scripts/apply_seo_batch.php path/to/batch.php [--force]\n");
    exit(1);
}

$batchPath = $args[0];

if (!is_file($batchPath)) {
    fwrite(STDERR, "fichier de lot introuvable : {$batchPath}\n");
    exit(1);
}

$batch = require $batchPath;

if (!is_array($batch) || !isset($batch['batch_id'], $batch['rows']) || !is_array($batch['rows'])) {
    fwrite(STDERR, "format de lot invalide : attendu ['batch_id' => string, 'rows' => list<array>]\n");
    exit(1);
}

$batchId = (string) $batch['batch_id'];
$addedAt = isset($batch['added_at']) ? (string) $batch['added_at'] : gmdate('Y-m-d');
$rows = $batch['rows'];

if ($rows === []) {
    fwrite(STDERR, "lot vide : aucune ligne a appliquer\n");
    exit(1);
}

// SCRABBLE_SEO_MAX_FRENCH_NOT_ADMITTED : meme reserve aux tests que SCRABBLE_SEO_DB_PATH
// (tests/Seo/BuildScriptsTest.php) -- permet de verifier le mecanisme de refus R6 sans
// generer des centaines de milliers de lignes reelles pour depasser le plafond de
// production (Family::MAX_BATCH_SIZE_FRENCH_NOT_ADMITTED, D-017).
$maxFrenchNotAdmitted = getenv('SCRABBLE_SEO_MAX_FRENCH_NOT_ADMITTED') !== false
    ? (int) getenv('SCRABBLE_SEO_MAX_FRENCH_NOT_ADMITTED')
    : Family::MAX_BATCH_SIZE_FRENCH_NOT_ADMITTED;

$errors = [];
$seenPaths = [];
$normalizedRows = [];
$frenchNotAdmittedIndexCount = 0;

foreach ($rows as $i => $row) {
    if (!is_array($row)) {
        $errors[] = "ligne {$i} : pas un tableau";

        continue;
    }

    $routePath = $row['route_path'] ?? null;
    $family = $row['family'] ?? null;
    $robots = $row['robots'] ?? null;
    $canonicalPath = $row['canonical_path'] ?? $routePath;
    $sitemapFragment = $row['sitemap_fragment'] ?? null;
    $resultCount = array_key_exists('result_count', $row) ? $row['result_count'] : null;
    $notes = $row['notes'] ?? '';

    $label = "ligne {$i} (" . (is_string($routePath) ? $routePath : '?') . ')';

    // R1
    if (!is_string($routePath) || !str_starts_with($routePath, '/')) {
        $errors[] = "{$label} : route_path doit commencer par '/'";

        continue;
    }

    if (!is_string($family) || !Family::isValid($family)) {
        $errors[] = "{$label} : family inconnue ou absente";

        continue;
    }

    if (!in_array($robots, ['index,follow', 'noindex,follow'], true)) {
        $errors[] = "{$label} : robots doit valoir 'index,follow' ou 'noindex,follow'";

        continue;
    }

    // R2
    if (isset($seenPaths[$routePath])) {
        $errors[] = "{$label} : route_path en double dans ce lot";

        continue;
    }
    $seenPaths[$routePath] = true;

    // R3
    if ($robots === 'index,follow' && $canonicalPath !== $routePath) {
        $errors[] = "{$label} : 'index,follow' avec un canonical_path different de route_path -- alias indexable refuse (R3)";

        continue;
    }

    // R4
    if (Family::forbidsSitemap($family)) {
        if ($robots === 'index,follow') {
            $errors[] = "{$label} : famille {$family} ne peut jamais etre 'index,follow' -- combinaison infinie (R4)";

            continue;
        }

        if ($sitemapFragment !== null) {
            $errors[] = "{$label} : famille {$family} ne peut jamais avoir de sitemap_fragment (R4)";

            continue;
        }
    }

    // R5
    if ($resultCount === 0 && $robots === 'index,follow') {
        $errors[] = "{$label} : result_count = 0 avec 'index,follow' -- page a resultat vide jamais indexable (R5)";

        continue;
    }

    // R7
    if ($robots === 'index,follow' && trim((string) $notes) === '') {
        $errors[] = "{$label} : 'index,follow' sans note de maillage interne -- attestation requise (R7)";

        continue;
    }

    // R6 (comptage, verifie apres la boucle une fois le total connu)
    if ($robots === 'index,follow' && Family::isFrenchNotAdmitted($family)) {
        $frenchNotAdmittedIndexCount++;

        if (trim((string) $notes) === '') {
            $errors[] = "{$label} : forme francaise non admise 'index,follow' sans attestation de verification manuelle (R6)";

            continue;
        }
    }

    $normalizedRows[] = [
        'route_path' => $routePath,
        'family' => $family,
        'robots' => $robots,
        'canonical_path' => (string) $canonicalPath,
        'sitemap_fragment' => $sitemapFragment !== null ? (string) $sitemapFragment : null,
        'result_count' => $resultCount !== null ? (int) $resultCount : null,
        'notes' => (string) $notes,
    ];
}

// R6, plafond global du lot.
if ($frenchNotAdmittedIndexCount > $maxFrenchNotAdmitted) {
    $errors[] = sprintf(
        "lot refuse (R6) : %d lignes 'index,follow' en francais non admis, plafond %d par lot",
        $frenchNotAdmittedIndexCount,
        $maxFrenchNotAdmitted,
    );
}

if ($errors !== []) {
    fwrite(STDERR, "lot refuse, " . count($errors) . " erreur(s) :\n");

    foreach ($errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }

    exit(1);
}

$root = dirname(__DIR__);
// SCRABBLE_SEO_DB_PATH : meme reserve aux tests que dans scripts/build_seo_registry.php.
$dbPath = getenv('SCRABBLE_SEO_DB_PATH') ?: $root . '/storage/seo_fr.sqlite';

if (!is_file($dbPath)) {
    fwrite(STDERR, "registre introuvable, lancer d'abord : php scripts/build_seo_registry.php\n{$dbPath}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

if (!$force) {
    $checkStatement = $pdo->prepare('SELECT batch_id FROM registry WHERE route_path = ?');

    foreach ($normalizedRows as $row) {
        $checkStatement->execute([$row['route_path']]);
        $existing = $checkStatement->fetch();

        if ($existing !== false && $existing['batch_id'] !== $batchId) {
            fwrite(STDERR, sprintf(
                "route_path '%s' existe deja sous le lot '%s' -- utiliser --force pour remplacer\n",
                $row['route_path'],
                $existing['batch_id'] ?? '(aucun)',
            ));
            exit(1);
        }
    }
}

$pdo->beginTransaction();

$insert = $pdo->prepare(
    'INSERT OR REPLACE INTO registry '
    . '(route_path, family, robots, canonical_path, sitemap_fragment, batch_id, result_count, notes, added_at) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

foreach ($normalizedRows as $row) {
    $insert->execute([
        $row['route_path'],
        $row['family'],
        $row['robots'],
        $row['canonical_path'],
        $row['sitemap_fragment'],
        $batchId,
        $row['result_count'],
        $row['notes'],
        $addedAt,
    ]);
}

$pdo->commit();

$totalCount = $pdo->query('SELECT COUNT(*) c FROM registry')->fetch()['c'];
$indexCount = $pdo->query("SELECT COUNT(*) c FROM registry WHERE robots = 'index,follow'")->fetch()['c'];
$singleResultCount = $pdo->query('SELECT COUNT(*) c FROM registry WHERE result_count = 1')->fetch()['c'];

echo "lot '{$batchId}' applique : " . count($normalizedRows) . " ligne(s)\n";
echo "registre apres application : {$totalCount} lignes au total, {$indexCount} en 'index,follow'\n";
echo "pages a exactement 1 resultat dans le registre (toutes familles) : {$singleResultCount}\n";
