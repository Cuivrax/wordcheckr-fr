<?php

declare(strict_types=1);

// D-049 : detection de doublons (PARENT transitif, SOEUR intra-palier, EXTERNAL croise famille)
// + generation des 4 lots SEO "avec nue" (BARE) pour FR -- meme algorithme que le depot espagnol
// cousin (scripts/build_avec_bare_candidates.php, ES), adapte aux conventions FR.
//
// CORRECTIF REPRISE SUR INCIDENT (D-049, 2026-09-01) : 3 interruptions externes constatees au
// meme point relatif (tier 3, ~2000/2575 -- segment anormalement lent, 700-950s contre 70-150s
// pour un segment de taille comparable ailleurs, jamais explique avec certitude -- contention
// machine et/ou coupure externe, integrite des bases verifiee intacte a chaque fois). Le script
// sauvegarde desormais un CHECKPOINT (resolution + tiers dont la boucle principale est finie)
// toutes les 200 cles traitees, et reprend automatiquement depuis ce checkpoint au demarrage
// plutot que de tout recalculer -- idempotent : une cle deja presente dans $resolution n'est
// jamais retraitee, la passe SOEUR+finalisation d'un palier deja complet ne trouve plus aucun
// candidat 'candidate' et ne fait rien (sans danger a rejouer).
//
// Prealable : list_counts DOIT deja contenir avec_bare/avec_bare_pair/avec_bare_triple/
// avec_bare_quad (scripts/build_explore_hub_counts.php deja rejoue) ET OVER_BUDGET_KEYS deja
// finalisees dans les 3 builders -- les candidats hors budget restent MESURES ET SIGNALES ici
// mais JAMAIS exclus automatiquement de ce lot (decision produit confirmee le 2026-09-01,
// troncature/budget TTFB jamais un motif d'exclusion du registre sur ce projet).
//
// VERSE AU DEPOT (C-4, audit seo-technical-auditor, 2026-09-01) : ce script vivait uniquement
// dans un repertoire scratch hors depot pendant toute la campagne "publier maintenant, nettoyer
// apres" (D-049) -- ni rejouable, ni relisible par un tiers, verrou non auditable. Corrige :
// script et format de checkpoint desormais versionnes ; etat transitoire (checkpoint/verrou)
// ecrit dans storage/ (jamais versionne, D-007), jamais dans scripts/.
//
// FORMAT DU CHECKPOINT (storage/avec_bare_checkpoint.json) : objet JSON avec deux cles --
// "resolution" (map "{palier}:{lettres triees par :}" -> {type: parent|sibling|external|kept|
// candidate, winnerRoute, winnerFamily, externalUntested?:true}) et "tierMainLoopDone" (map
// palier(int) -> bool, indique si la boucle principale EXTERNAL de ce palier est terminee).
// Sauvegarde toutes les 200 cles traitees. Idempotent : une cle deja presente n'est jamais
// retraitee, un palier deja termine ne relance jamais sa boucle principale.
//
// ECHEANCE FERME (C-4/point 5, meme audit) : toute ligne du registre encore marquee
// "CONTROLE CROISE EXTERNE NON EFFECTUE" (voir notes des lots avec-bare-four-letters-2026-09-01
// et sa correction quarantine du meme jour) DOIT etre soit confirmee propre soit corrigee par ce
// balayage avant le 2026-09-08 (7 jours). Passe cette date sans que ce script ait tourne a
// completion, toute ligne encore non couverte doit basculer en noindex,follow via un nouveau lot
// de correction plutot que de rester en ligne indefiniment sans controle -- l'inaction doit
// fermer, pas laisser ouvert.

$root = dirname(__DIR__);
require "$root/app/bootstrap.php";

use App\Database\Connection;
use App\Search\DuplicatePageResolver;
use App\Search\WordListFilters;
use App\Search\WordListSolver;

$scratch = "$root/storage";

// GARDE-FOU (D-049, 2026-09-01) : le cron de securite a relance une 2e instance en double a
// deux reprises malgre une consigne explicite de verifier qu'un process tourne deja (verification
// par liste de process, pas fiable -- fenetre de course entre la verification et le lancement).
// Verrou fichier exclusif : une 2e instance qui demarre pendant qu'une 1re tourne deja se
// termine immediatement plutot que de courir sur le meme checkpoint.
$lockPath = "$scratch/avec_bare_candidates.lock";
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "une autre instance de ce script tourne deja (verrou $lockPath) -- arret immediat, pas de doublon.\n");
    exit(1);
}

$checkpointPath = "$scratch/avec_bare_checkpoint.json";
$dictConnection = new Connection("$root/storage/dictionary_fr.sqlite");
$dictPdo = $dictConnection->pdo();
$seoPdo = new PDO("sqlite:$root/storage/seo_fr.sqlite");
$ceiling = WordListSolver::ROW_EXAMINATION_CEILING;

$t0 = microtime(true);
function elapsed(float $t0): string { return number_format(microtime(true) - $t0, 1) . 's'; }

// --- Charge les 4 paliers depuis les fichiers de comptes deja calcules ---
$tiers = [
    1 => json_decode(file_get_contents("$scratch/avec_single_counts.json"), true),
    2 => json_decode(file_get_contents("$scratch/avec_pair_counts.json"), true),
    3 => json_decode(file_get_contents("$scratch/avec_triple_counts.json"), true),
    4 => json_decode(file_get_contents("$scratch/avec_quad_counts.json"), true),
];
foreach ($tiers as $tier => $entries) {
    echo "[" . elapsed($t0) . "] palier $tier : " . count($entries) . " candidats charges\n";
}

// --- Reprise sur checkpoint (si present) ---
$resolution = [];
$tierMainLoopDone = [1 => false, 2 => false, 3 => false, 4 => false];
if (is_file($checkpointPath)) {
    $checkpoint = json_decode(file_get_contents($checkpointPath), true);
    $resolution = $checkpoint['resolution'] ?? [];
    foreach ($checkpoint['tierMainLoopDone'] ?? [] as $t => $done) {
        $tierMainLoopDone[(int) $t] = (bool) $done;
    }
    echo "[" . elapsed($t0) . "] CHECKPOINT trouve : " . count($resolution) . " cles deja resolues, paliers principaux termines : "
        . implode(',', array_keys(array_filter($tierMainLoopDone))) . "\n";
} else {
    echo "[" . elapsed($t0) . "] aucun checkpoint, depart a zero\n";
}

function saveCheckpoint(string $path, array $resolution, array $tierMainLoopDone): void
{
    file_put_contents($path, json_encode(['resolution' => $resolution, 'tierMainLoopDone' => $tierMainLoopDone]));
}

$solver = new WordListSolver($dictConnection);
$solverReflection = new ReflectionClass($solver);

/** @return array{0: string, 1: list<int|string>} */
function fullSetWhereClause(ReflectionClass $solverReflection, WordListSolver $solver, WordListFilters $filters): array
{
    $isExact = $solverReflection->getMethod('isExactMode')->invoke($solver, $filters);
    if ($isExact) {
        return $solverReflection->getMethod('exactWhereClause')->invoke($solver, $filters);
    }
    $anchor = $solverReflection->getMethod('anchorClause')->invoke($solver, $filters);
    [$anchorWhere, $anchorParams, , $anchorType] = $anchor;
    $extra = $solverReflection->getMethod('extraPredicates')->invoke($solver, $filters, $anchorType);
    [$extraWhere, $extraParams] = $extra;
    $conditions = array_values(array_filter([$anchorWhere, $extraWhere], static fn (string $c): bool => $c !== ''));
    return [implode(' AND ', $conditions), [...$anchorParams, ...$extraParams]];
}

// ---- Table de hachage compte -> [(famille, route_path)] pour TOUTES les familles
// combinatoires actuellement index,follow. ----
$excludedFamilies = ['home', 'word_admitted', 'word_french_not_admitted', 'word_list_contenant', 'word_list_avec', 'word_list_sans', 'word_list_motif', 'rack'];
$allFamilies = $seoPdo->query('SELECT DISTINCT family FROM registry')->fetchAll(PDO::FETCH_COLUMN);
$combinatorialFamilies = array_values(array_diff($allFamilies, $excludedFamilies));

$relevantCounts = [];
foreach ($tiers as $entries) {
    foreach ($entries as $count) {
        $relevantCounts[$count] = true;
    }
}

$ph = implode(',', array_fill(0, count($combinatorialFamilies), '?'));
$stmt = $seoPdo->prepare("SELECT route_path, family, result_count FROM registry WHERE family IN ($ph) AND robots = 'index,follow'");
$stmt->execute($combinatorialFamilies);

$byCount = [];
$rowCount = 0;
$prunedCount = 0;
foreach ($stmt as $r) {
    $rc = $r['result_count'];
    if ($rc === null) { continue; }
    $rc = (int) $rc;
    if (!isset($relevantCounts[$rc])) { $prunedCount++; continue; }
    $byCount[$rc][] = ['route_path' => $r['route_path'], 'family' => $r['family']];
    $rowCount++;
}
echo "[" . elapsed($t0) . "] table de hachage externe : $rowCount lignes pertinentes (" . $prunedCount . " elaguees), " . count($byCount) . " comptes distincts\n";

// Verification EXPLICITE de la piste "segment lent" : combien de candidats externes par compte
// (le pire cas determine le cout du pire candidat -- utile pour diagnostiquer un futur ralentissement).
$maxCandidatesPerCount = 0;
foreach ($byCount as $candidates) { $maxCandidatesPerCount = max($maxCandidatesPerCount, count($candidates)); }
echo "[" . elapsed($t0) . "] pire cas : $maxCandidatesPerCount candidats externes pour un seul compte\n";

$externalWhereCache = [];
$externalFingerprintCache = []; // count => [fingerprint => ['route_path'=>..., 'family'=>...]], partage entre paliers

/** @return array{0: string, 1: list<int|string>}|null */
function fetchExternalWhereClause(string $routePath, ReflectionClass $solverReflection, WordListSolver $solver, array &$cache): ?array
{
    if (array_key_exists($routePath, $cache)) { return $cache[$routePath]; }
    if (!str_starts_with($routePath, '/mots')) { return $cache[$routePath] = null; }
    $filters = WordListFilters::fromPath(substr($routePath, strlen('/mots')));
    if ($filters === null || $filters->canonicalUrl() !== $routePath) { return $cache[$routePath] = null; }
    return $cache[$routePath] = fullSetWhereClause($solverReflection, $solver, $filters);
}

/** @param list<string> $letters */
function externalRouteIsSubsetOf(string $routePath, array $letters, ReflectionClass $solverReflection, WordListSolver $solver, PDO $dictPdo, array &$cache): bool
{
    $clause = fetchExternalWhereClause($routePath, $solverReflection, $solver, $cache);
    if ($clause === null) { return false; }
    [$where, $params] = $clause;
    $whereSql = $where === '' ? '' : "({$where}) AND ";

    $missingConditions = array_map(static fn (string $l): string => 'instr(normalized, ?) = 0', $letters);
    $missingWhere = implode(' OR ', $missingConditions);
    $stmt = $dictPdo->prepare("SELECT COUNT(*) FROM terms WHERE {$whereSql}({$missingWhere})");
    $stmt->execute([...$params, ...$letters]);

    return ((int) $stmt->fetchColumn()) === 0;
}

/** @param list<string> $letters */
function bareUrl(array $letters): ?string
{
    $path = 'avec/' . implode('/', array_map('strtolower', $letters));
    return WordListFilters::fromPath($path)?->canonicalUrl();
}

// CORRECTIF PERF (D-049, 2026-09-01, en cours d'execution) : le compte le plus frequent
// (result_count=1, 17019 routes externes deja indexees -- verifie en direct sur seo_fr.sqlite,
// PAS le plafond de troncature 10000 comme suppose initialement) rendait le pas EXTERNAL
// ci-dessous catastrophique : une requete SQL live par (candidat, route externe), jusqu'a 17019
// requetes pour un seul candidat de count=1. Meme principe que le pas SOEUR plus bas (deja
// fingerprint sha1(GROUP_CONCAT) -- mots ordonnes) applique desormais aussi cote externe :
// meme cardinalite + sous-ensemble prouve <=> ensembles egaux, donc une egalite de fingerprint
// est une preuve suffisante. Precalcule UNE FOIS par valeur de compte (pas par candidat),
// partage entre paliers 3 et 4 (un compte revient souvent d'un palier a l'autre).
function wordSetFingerprint(string $whereSql, array $params, PDO $dictPdo): string
{
    $stmt = $dictPdo->prepare("SELECT GROUP_CONCAT(normalized) g FROM (SELECT normalized FROM terms WHERE {$whereSql} ORDER BY normalized)");
    $stmt->execute($params);
    return sha1((string) $stmt->fetchColumn());
}

// CORRECTIF PERF #2, TENTE PUIS ABANDONNE (D-049, 2026-09-01) : une version "filtre en memoire"
// (charger chaque panier de longueur une fois, filtrer en PHP plutot qu'en SQL) a ete ecrite et
// mesuree -- AUCUN gain reel confirme par un script de validation dedie (panier longueur=10,
// 125 434 mots : 52.6ms en memoire contre ~46-55ms mesures cote SQL pour un cas comparable,
// quasi identique) -- l'evaluation d'expression SQLite sur un scan indexe n'est pas plus lente
// que l'equivalent PHP ici, contrairement a l'hypothese initiale. Abandonnee avant relancement
// pour ne pas ajouter de complexite sans benefice mesure. Le correctif #1 ci-dessus (fingerprint
// mis en cache PAR COMPTE plutot que recalcule par candidat) reste l'optimisation retenue --
// gain reel et deja verifie (5/5 cas connus du checkpoint).

/**
 * @param list<array{route_path: string, family: string}> $externalRoutes
 * @return array<string, array{route_path: string, family: string}>
 */
function buildExternalFingerprints(array $externalRoutes, ReflectionClass $solverReflection, WordListSolver $solver, PDO $dictPdo, array &$whereCache): array
{
    $map = [];
    foreach ($externalRoutes as $ext) {
        $clause = fetchExternalWhereClause($ext['route_path'], $solverReflection, $solver, $whereCache);
        if ($clause === null) { continue; }
        [$where, $params] = $clause;
        $whereSql = $where === '' ? '1=1' : $where;
        $fp = wordSetFingerprint($whereSql, $params, $dictPdo);
        // Premiere route rencontree gardee pour un fingerprint donne -- meme ordre de lecture
        // SQL (registry) que la boucle EXTERNAL d'origine, donc meme candidat gagnant choisi.
        if (!isset($map[$fp])) { $map[$fp] = $ext; }
    }
    return $map;
}

/** @param list<string> $letters */
function candidateFingerprint(array $letters, PDO $dictPdo): string
{
    $conditions = array_map(static fn (string $l): string => 'instr(normalized, ' . $dictPdo->quote($l) . ') > 0', $letters);
    return wordSetFingerprint(implode(' AND ', $conditions), [], $dictPdo);
}

$stats = ['ceiling_exceeded' => [1 => 0, 2 => 0, 3 => 0, 4 => 0], 'parent' => 0, 'sibling' => 0, 'external' => 0, 'kept' => [1 => 0, 2 => 0, 3 => 0, 4 => 0]];
// Recompte les stats deja connues depuis le checkpoint (pour un resume final coherent).
foreach ($resolution as $globalKey => $r) {
    [$t] = explode(':', $globalKey, 2);
    $t = (int) $t;
    if ($r['type'] === 'parent') { $stats['parent']++; }
    elseif ($r['type'] === 'external') { $stats['external']++; }
    elseif ($r['type'] === 'sibling') { $stats['sibling']++; }
    elseif ($r['type'] === 'kept') { $stats['kept'][$t]++; }
}

foreach ([1, 2, 3, 4] as $tier) {
    if ($tierMainLoopDone[$tier]) {
        echo "[" . elapsed($t0) . "] tier $tier : boucle principale deja terminee (checkpoint), passe direct a SOEUR+finalisation\n";
    } else {
        $tierStart = microtime(true);
        $processed = 0;
        $skipped = 0;
        $tierSize = count($tiers[$tier]);
        $subTier = $tier - 1;

        foreach ($tiers[$tier] as $key => $count) {
            $globalKey = "{$tier}:{$key}";
            $processed++;

            if (isset($resolution[$globalKey])) {
                $skipped++;
                if ($processed % 500 === 0) {
                    $e = microtime(true) - $tierStart;
                    echo "  [" . elapsed($t0) . "] tier $tier : $processed/$tierSize (" . round(100 * $processed / max(1, $tierSize), 1) . "%) en " . round($e, 1) . "s (dont $skipped deja resolues via checkpoint)\n";
                }
                continue;
            }

            $letters = explode(':', $key);

            if ($processed % 200 === 0) {
                saveCheckpoint($checkpointPath, $resolution, $tierMainLoopDone);
            }
            if ($processed % 500 === 0) {
                $e = microtime(true) - $tierStart;
                echo "  [" . elapsed($t0) . "] tier $tier : $processed/$tierSize (" . round(100 * $processed / max(1, $tierSize), 1) . "%) en " . round($e, 1) . "s\n";
            }

            if ($count > $ceiling) { $stats['ceiling_exceeded'][$tier]++; }

            // --- 1. PARENT transitif ---
            $parentResolved = null;
            if ($tier > 1) {
                foreach ($letters as $omit) {
                    $subLetters = array_values(array_diff($letters, [$omit]));
                    $subKey = implode(':', $subLetters);
                    if (($tiers[$subTier][$subKey] ?? null) === $count) {
                        $sub = $resolution["{$subTier}:{$subKey}"] ?? null;
                        $parentResolved = [
                            'type' => 'parent',
                            'winnerRoute' => $sub['winnerRoute'] ?? bareUrl($subLetters),
                            'winnerFamily' => $sub['winnerFamily'] ?? ('word_list_avec_bare_' . $subTier),
                        ];
                        break;
                    }
                }
            }
            if ($parentResolved !== null) {
                $resolution[$globalKey] = $parentResolved;
                $stats['parent']++;
                continue;
            }

            // --- 2. EXTERNAL (fingerprint, cf. correctif perf ci-dessus) ---
            $externalWinner = null;
            if (isset($byCount[$count])) {
                if (!isset($externalFingerprintCache[$count])) {
                    $externalFingerprintCache[$count] = buildExternalFingerprints($byCount[$count], $solverReflection, $solver, $dictPdo, $externalWhereCache);
                }
                $myFp = candidateFingerprint($letters, $dictPdo);
                $candidateExternal = $externalFingerprintCache[$count][$myFp] ?? null;
                if ($candidateExternal !== null) {
                    $myUrl = bareUrl($letters);
                    if ($myUrl !== null) {
                        $winnerPath = DuplicatePageResolver::resolveDuplicateWinner([$myUrl, $candidateExternal['route_path']]);
                        if ($winnerPath !== $myUrl) {
                            $externalWinner = ['type' => 'external', 'winnerRoute' => $winnerPath, 'winnerFamily' => $candidateExternal['family']];
                        }
                    }
                }
            }
            if ($externalWinner !== null) {
                $resolution[$globalKey] = $externalWinner;
                $stats['external']++;
                continue;
            }

            $resolution[$globalKey] = ['type' => 'candidate', 'winnerRoute' => null, 'winnerFamily' => null];
        }

        $tierMainLoopDone[$tier] = true;
        saveCheckpoint($checkpointPath, $resolution, $tierMainLoopDone);
        echo "[" . elapsed($t0) . "] tier $tier : boucle principale terminee ($skipped deja resolues via checkpoint, " . ($tierSize - $skipped) . " traitees cette execution)\n";
    }

    // --- 3. SIBLING (idempotent : ne trouve plus rien si deja fait) ---
    $byCountThisTier = [];
    foreach ($tiers[$tier] as $key => $count) {
        $globalKey = "{$tier}:{$key}";
        if (($resolution[$globalKey]['type'] ?? null) !== 'candidate') { continue; }
        $byCountThisTier[$count][] = $key;
    }

    foreach ($byCountThisTier as $count => $keys) {
        if (count($keys) < 2) { continue; }
        $fingerprints = [];
        foreach ($keys as $key) {
            $letters = explode(':', $key);
            $conditions = array_map(static fn (string $l): string => 'instr(normalized, ' . $dictPdo->quote($l) . ') > 0', $letters);
            $where = implode(' AND ', $conditions);
            $row = $dictPdo->query("SELECT GROUP_CONCAT(normalized) g FROM (SELECT normalized FROM terms WHERE {$where} ORDER BY normalized)")->fetch();
            $fingerprints[$key] = sha1((string) $row['g']);
        }
        $byFingerprint = [];
        foreach ($fingerprints as $key => $fp) { $byFingerprint[$fp][] = $key; }
        foreach ($byFingerprint as $groupKeys) {
            if (count($groupKeys) < 2) { continue; }
            sort($groupKeys, SORT_STRING);
            $winnerKey = $groupKeys[0];
            $winnerUrl = bareUrl(explode(':', $winnerKey));
            foreach (array_slice($groupKeys, 1) as $loserKey) {
                $resolution["{$tier}:{$loserKey}"] = ['type' => 'sibling', 'winnerRoute' => $winnerUrl, 'winnerFamily' => 'word_list_avec_bare_' . $tier];
                $stats['sibling']++;
            }
        }
    }

    foreach ($tiers[$tier] as $key => $count) {
        $globalKey = "{$tier}:{$key}";
        if (($resolution[$globalKey]['type'] ?? null) === 'candidate') {
            $letters = explode(':', $key);
            $resolution[$globalKey] = ['type' => 'kept', 'winnerRoute' => bareUrl($letters), 'winnerFamily' => 'word_list_avec_bare_' . $tier];
            $stats['kept'][$tier]++;
        }
    }

    saveCheckpoint($checkpointPath, $resolution, $tierMainLoopDone);
    echo "[" . elapsed($t0) . "] tier $tier traite : " . count($tiers[$tier]) . " candidats, {$stats['kept'][$tier]} gardes\n";
}

echo "\n[" . elapsed($t0) . "] resume : parent={$stats['parent']} sibling={$stats['sibling']} external={$stats['external']} garde=" . array_sum($stats['kept']) . "\n";
foreach ([1, 2, 3, 4] as $t) {
    echo "  ceiling depasse tier $t : {$stats['ceiling_exceeded'][$t]}\n";
}

file_put_contents("$scratch/avec_bare_resolution.json", json_encode($resolution));
echo "[" . elapsed($t0) . "] resolution ecrite dans avec_bare_resolution.json\n";

// ---- Generation des 4 lots SEO ----
$familyNames = [1 => 'word_list_avec_bare_single_letter', 2 => 'word_list_avec_bare_two_letters', 3 => 'word_list_avec_bare_three_letters', 4 => 'word_list_avec_bare_four_letters'];
$fragmentPrefixes = [1 => 'avec-bare-single', 2 => 'avec-bare-two', 3 => 'avec-bare-three', 4 => 'avec-bare-four'];
$batchNames = [1 => 'single-letters', 2 => 'two-letters', 3 => 'three-letters', 4 => 'four-letters'];

foreach ([1, 2, 3, 4] as $tier) {
    $rows = [];
    $fragmentIndex = 1;
    $fragmentCount = 0;
    $sitemapFragment = sprintf('%s-%04d', $fragmentPrefixes[$tier], $fragmentIndex);

    foreach ($tiers[$tier] as $key => $count) {
        $globalKey = "{$tier}:{$key}";
        $res = $resolution[$globalKey];
        $letters = explode(':', $key);
        $myUrl = bareUrl($letters);
        if ($myUrl === null) { continue; }

        if ($res['type'] === 'kept') {
            $note = 'Palier ' . $tier . ' de "avec" SANS AUCUN ancrage (BARE, ni longueur ni prefixe ni suffixe) -- '
                . 'demande produit explicite 2026-09-01 (volume de recherche reel, preuve Semrush, voir '
                . 'docs/DECISIONS.md D-049). Maillage entrant reel : ';
            $note .= $tier === 1
                ? 'App\\Search\\ExploreHub::$byWith depuis le hub /mots (deja indexe, D-017).'
                : ('App\\Search\\AvecBare' . ($tier === 2 ? 'Two' : ($tier === 3 ? 'Three' : 'Four')) . 'LettersLinksBuilder depuis la page bare palier ' . ($tier - 1) . ' deja indexee.');
            if ($count > $ceiling) {
                $note .= sprintf(' ATTENTION resultat tronque a %d (WordListSolver::ROW_EXAMINATION_CEILING) -- total reel inconnu au-dela de ce plafond, decision d\'inclusion prise explicitement (la troncature n\'est jamais un motif d\'exclusion sur ce projet, voir docs/DECISIONS.md D-049), pas une omission.', $ceiling);
            }
            $rows[] = [
                'route_path' => $myUrl,
                'family' => $familyNames[$tier],
                'robots' => 'index,follow',
                'canonical_path' => $myUrl,
                'sitemap_fragment' => $sitemapFragment,
                'result_count' => $count,
                'notes' => $note,
            ];
            $fragmentCount++;
            if ($fragmentCount >= 40000) {
                $fragmentIndex++;
                $sitemapFragment = sprintf('%s-%04d', $fragmentPrefixes[$tier], $fragmentIndex);
                $fragmentCount = 0;
            }
        } else {
            $methodLabel = $res['type'] === 'external' ? 'sous-ensemble ancre' : ($res['type'] === 'sibling' ? 'empreinte SQL GROUP_CONCAT+sha1' : 'egalite de compte, preuve de sous-ensemble');
            $rows[] = [
                'route_path' => $myUrl,
                'family' => $familyNames[$tier],
                'robots' => 'noindex,follow',
                'canonical_path' => $res['winnerRoute'],
                'sitemap_fragment' => null,
                'result_count' => $count,
                'notes' => "doublon de contenu exact ({$res['type']}) avec {$res['winnerRoute']} (meme ensemble de mots, verification par $methodLabel) -- canonical vers la forme gagnante.",
            ];
        }
    }

    $batchFile = "$root/scripts/seo-batches/avec-bare-{$batchNames[$tier]}-2026-09-01.php";
    $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export(['batch_id' => "avec-bare-{$batchNames[$tier]}-2026-09-01", 'added_at' => '2026-09-01', 'rows' => $rows], true) . ";\n";
    file_put_contents($batchFile, $php);
    echo "[" . elapsed($t0) . "] ecrit : $batchFile (" . count($rows) . " lignes)\n";
}
