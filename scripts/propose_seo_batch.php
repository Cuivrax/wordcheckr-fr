<?php

declare(strict_types=1);

/**
 * Propose un fichier de lot pour scripts/apply_seo_batch.php, avec des result_count REELS
 * calcules depuis storage/dictionary_fr.sqlite (Phase 6, docs/08) -- jamais tapes a la main.
 * Ce script ne modifie ni n'ouvre storage/seo_fr.sqlite : il ne fait que PROPOSER, en lecture
 * seule sur le dictionnaire (D-001). L'application reste un acte separe et explicite
 * (scripts/apply_seo_batch.php), qui revalide de toute facon chaque ligne independamment.
 *
 * Usage :
 *     php scripts/propose_seo_batch.php home > batch.php
 *     php scripts/propose_seo_batch.php length > batch.php
 *     php scripts/propose_seo_batch.php commencant > batch.php
 *     php scripts/propose_seo_batch.php terminant > batch.php
 *     php scripts/propose_seo_batch.php word_admitted --limit=2000 --offset=0 > batch.php
 *
 * Familles volontairement NON proposables par ce script, quelle que soit l'option :
 *   - word_french_not_admitted : "genuinely French, verified manually, useful, searched or
 *     frequent" ne peut pas se deduire d'une requete SQL -- ce script refuse categoriquement
 *     de generer cette famille, meme un seul mot (contrainte dure du projet : jamais en masse,
 *     et jamais automatiquement).
 *   - word_list_contenant / avec / sans / motif / combined / rack : combinaisons infinies,
 *     jamais indexables par defaut -- refuse ici aussi, en plus du refus dans
 *     scripts/apply_seo_batch.php (R4), pour qu'aucun outil de la chaine ne les propose meme
 *     par erreur de frappe sur le nom de famille.
 *
 * Chaque ligne proposee porte 'notes' commencant par "A COMPLETER" : ce script ne PREND
 * aucune decision de maillage interne reel, il expose juste la structure -- la note doit etre
 * relue et completee par un humain avant tout scripts/apply_seo_batch.php.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "scripts/propose_seo_batch.php ne s'execute qu'en CLI, hors ligne.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/Seo/Family.php';

use App\Seo\Family;

$args = array_slice($argv, 1);

if ($args === []) {
    fwrite(STDERR, "usage : php scripts/propose_seo_batch.php <home|length|commencant|terminant|word_admitted> [--limit=N] [--offset=N]\n");
    exit(1);
}

$kind = $args[0];
$limit = 2_000;
$offset = 0;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    }

    if (str_starts_with($arg, '--offset=')) {
        $offset = (int) substr($arg, strlen('--offset='));
    }
}

$forbidden = array_merge(Family::FRENCH_NOT_ADMITTED, Family::NEVER_SITEMAP);

if (in_array($kind, $forbidden, true) || $kind === 'word_french_not_admitted') {
    fwrite(STDERR, "refuse : '{$kind}' n'est jamais propose automatiquement par cet outil (voir l'entete du fichier).\n");
    exit(1);
}

$root = dirname(__DIR__);
$dictPath = $root . '/storage/dictionary_fr.sqlite';

if (!is_file($dictPath)) {
    fwrite(STDERR, "dictionnaire introuvable : {$dictPath}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dictPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA query_only = ON');

/** @var list<array<string, mixed>> $rows */
$rows = [];
$batchIdSuffix = $kind;

switch ($kind) {
    case 'home':
        $rows[] = [
            'route_path' => '/',
            'family' => Family::HOME,
            'robots' => 'index,follow',
            'canonical_path' => '/',
            'sitemap_fragment' => 'core-0001',
            'result_count' => null,
            'notes' => 'A COMPLETER : page d\'accueil, cible de tout lien "MOT DIRECT" du header sur chaque page du site (maillage total = 100% des pages).',
        ];
        break;

    case 'length':
        $statement = $pdo->query('SELECT length, COUNT(*) n FROM terms GROUP BY length ORDER BY length');

        foreach ($statement->fetchAll() as $row) {
            $n = (int) $row['n'];

            if ($n === 0) {
                continue;
            }

            $len = (int) $row['length'];
            $rows[] = [
                'route_path' => "/mots/{$len}-lettres",
                'family' => Family::WORD_LIST_LENGTH,
                'robots' => 'index,follow',
                'canonical_path' => "/mots/{$len}-lettres",
                'sitemap_fragment' => 'letters-0001',
                'result_count' => $n,
                'notes' => "A COMPLETER : liste de tous les mots de {$len} lettres ({$n} resultats), atteinte depuis la recherche liee \"longueur\" de chaque fiche mot de cette longueur.",
            ];
        }
        break;

    case 'commencant':
        $statement = $pdo->query("SELECT substr(normalized,1,1) c, COUNT(*) n FROM terms GROUP BY c ORDER BY c");

        foreach ($statement->fetchAll() as $row) {
            $n = (int) $row['n'];

            if ($n === 0) {
                continue;
            }

            $letter = strtolower($row['c']);
            $rows[] = [
                'route_path' => "/mots/commencant/{$letter}",
                'family' => Family::WORD_LIST_COMMENCANT,
                'robots' => 'index,follow',
                'canonical_path' => "/mots/commencant/{$letter}",
                'sitemap_fragment' => 'starts-0001',
                'result_count' => $n,
                'notes' => "A COMPLETER : liste des mots commencant par {$row['c']} ({$n} resultats), atteinte depuis la recherche liee \"commencant par\" de chaque fiche mot correspondante.",
            ];
        }
        break;

    case 'terminant':
        $statement = $pdo->query("SELECT substr(reversed,1,1) c, COUNT(*) n FROM terms GROUP BY c ORDER BY c");

        foreach ($statement->fetchAll() as $row) {
            $n = (int) $row['n'];

            if ($n === 0) {
                continue;
            }

            $letter = strtolower($row['c']);
            $rows[] = [
                'route_path' => "/mots/terminant/{$letter}",
                'family' => Family::WORD_LIST_TERMINANT,
                'robots' => 'index,follow',
                'canonical_path' => "/mots/terminant/{$letter}",
                'sitemap_fragment' => 'ends-0001',
                'result_count' => $n,
                'notes' => "A COMPLETER : liste des mots terminant par {$row['c']} ({$n} resultats), atteinte depuis la recherche liee \"terminant par\" de chaque fiche mot correspondante.",
            ];
        }
        break;

    case 'word_admitted':
        $statement = $pdo->prepare(
            'SELECT normalized FROM terms WHERE is_ods8 = 1 OR is_ods9 = 1 ORDER BY normalized LIMIT ? OFFSET ?'
        );
        $statement->execute([$limit, $offset]);
        $fragmentIndex = 1;
        $countInFragment = 0;

        foreach ($statement->fetchAll() as $i => $row) {
            if ($countInFragment >= 40_000) {
                $fragmentIndex++;
                $countInFragment = 0;
            }
            $countInFragment++;

            $slug = strtolower($row['normalized']);
            $rows[] = [
                'route_path' => "/mot/{$slug}",
                'family' => Family::WORD_ADMITTED,
                'robots' => 'index,follow',
                'canonical_path' => "/mot/{$slug}",
                'sitemap_fragment' => sprintf('words-%04d', $fragmentIndex),
                'result_count' => null,
                'notes' => "A COMPLETER : mot admis ODS8/ODS9, fiche atteinte depuis les listes /mots/... deja indexees et les relations d'autres fiches mot.",
            ];
        }
        $batchIdSuffix = sprintf('word_admitted-offset%d-limit%d', $offset, $limit);
        break;

    default:
        fwrite(STDERR, "famille inconnue ou non proposable automatiquement : {$kind}\n");
        exit(1);
}

$batchId = $batchIdSuffix . '-proposed-' . gmdate('Y-m-d');
$export = var_export(['batch_id' => $batchId, 'added_at' => gmdate('Y-m-d'), 'rows' => $rows], true);

echo "<?php\n\ndeclare(strict_types=1);\n\n";
echo "// PROPOSITION generee par scripts/propose_seo_batch.php -- NON appliquee.\n";
echo '// ' . count($rows) . " ligne(s). Relire chaque 'notes', ajuster sitemap_fragment si necessaire\n";
echo "// (40 000 URL max par fragment, docs/05), PUIS lancer scripts/apply_seo_batch.php sur ce\n";
echo "// fichier -- jamais avant validation humaine explicite du lot (CLAUDE.md).\n\n";
echo "return {$export};\n";

fwrite(STDERR, sprintf("%d ligne(s) proposee(s) pour '%s' (redirige stdout vers un fichier pour les conserver)\n", count($rows), $kind));
