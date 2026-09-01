<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\AvecBareFourLettersLinksBuilder;
use Tests\Support\Assert;

/**
 * App\Search\AvecBareFourLettersLinksBuilder (D-049, palier 3 -> 4 de l'ouverture en entonnoir
 * de "avec" SANS AUCUN ancrage, dernier palier borne de cette famille) : maillage
 * "avec {X} {Y} {Z}" bare -> "avec {W} {X} {Y} {Z}" bare -- lu depuis list_counts (list_type
 * 'avec_bare_quad'), jamais un calcul sur `terms` au runtime. Verifie par force brute sur la
 * vraie base, meme methodologie que AvecBareThreeLettersLinksBuilderTest.php -- etendue aux
 * QUATRE positions possibles du triplet source dans le quadruplet trie stocke.
 */
return function (): void {
    $dbPath = __DIR__ . '/../../storage/dictionary_fr.sqlite';
    Assert::true(is_file($dbPath), 'base manquante : ' . $dbPath);

    $connection = new Connection($dbPath);
    $pdo = $connection->pdo();
    $builder = new AvecBareFourLettersLinksBuilder($connection);

    // --- Triplet "au milieu de l'alphabet" (I, N, S) : quatre zones possibles. ---
    $links = $builder->build('I', 'N', 'S');
    Assert::same(1, $links->queryCount, 'une seule requete triviale sur list_counts');

    foreach ($links->links as $link) {
        $letters = ['I', 'N', 'S', $link['letter']];
        sort($letters, SORT_STRING);

        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM terms WHERE instr(normalized, ?) > 0 AND instr(normalized, ?) > 0 AND instr(normalized, ?) > 0 AND instr(normalized, ?) > 0');
        $stmt->execute(['I', 'N', 'S', $link['letter']]);
        $expected = (int) $stmt->fetch()['c'];

        Assert::same($expected, $link['count'], "avec I, N, S et {$link['letter']} : verifie par force brute");
        Assert::same('/mots/avec/' . strtolower($letters[0]) . '/' . strtolower($letters[1]) . '/' . strtolower($letters[2]) . '/' . strtolower($letters[3]), $link['url']);
        Assert::true(!in_array($link['letter'], ['I', 'N', 'S'], true), 'le partenaire ne doit jamais etre une des trois lettres source');
        Assert::true($link['count'] > 0, 'R5 : aucune entree a 0 attendue');
    }

    for ($i = 1; $i < count($links->links); $i++) {
        Assert::true($links->links[$i - 1]['letter'] < $links->links[$i]['letter'], 'ordre alphabetique attendu');
    }

    $linksReversedOrder = $builder->build('S', 'I', 'N');
    Assert::same($links->links, $linksReversedOrder->links, 'ordre de saisie sans effet sur le resultat');

    // ============================================================================================
    // Coherence structurelle des quatre constantes d'exclusion.
    // ============================================================================================
    $reflection = new ReflectionClass(AvecBareFourLettersLinksBuilder::class);
    $duplicateParentKeys = $reflection->getConstant('DUPLICATE_PARENT_KEYS');
    $siblingDuplicateKeys = $reflection->getConstant('SIBLING_DUPLICATE_KEYS');
    $externalDuplicateKeys = $reflection->getConstant('EXTERNAL_DUPLICATE_KEYS');
    $overBudgetKeys = $reflection->getConstant('OVER_BUDGET_KEYS');
    foreach (['DUPLICATE_PARENT_KEYS' => $duplicateParentKeys, 'SIBLING_DUPLICATE_KEYS' => $siblingDuplicateKeys, 'EXTERNAL_DUPLICATE_KEYS' => $externalDuplicateKeys, 'OVER_BUDGET_KEYS' => $overBudgetKeys] as $label => $keys) {
        Assert::same(count($keys), count(array_unique($keys)), "aucun doublon dans $label elle-meme");
    }
    $parentSet = array_fill_keys($duplicateParentKeys, true);
    $siblingSet = array_fill_keys($siblingDuplicateKeys, true);
    $externalSet = array_fill_keys($externalDuplicateKeys, true);
    Assert::same(0, count(array_intersect_key($parentSet, $siblingSet)), 'DUPLICATE_PARENT_KEYS et SIBLING_DUPLICATE_KEYS doivent rester deux ensembles disjoints');
    Assert::same(0, count(array_intersect_key($parentSet, $externalSet)), 'DUPLICATE_PARENT_KEYS et EXTERNAL_DUPLICATE_KEYS doivent rester deux ensembles disjoints');
    Assert::same(0, count(array_intersect_key($siblingSet, $externalSet)), 'SIBLING_DUPLICATE_KEYS et EXTERNAL_DUPLICATE_KEYS doivent rester deux ensembles disjoints');

    // ============================================================================================
    // Verification EXHAUSTIVE du maillage sur les triplets reels (list_type 'avec_bare_triple',
    // D-049) -- pas un echantillon.
    // ============================================================================================
    $tripleAnchorsStatement = $pdo->query("SELECT list_key FROM list_counts WHERE list_type = 'avec_bare_triple'");
    $tripleAnchors = [];
    foreach ($tripleAnchorsStatement as $row) {
        $tripleAnchors[] = explode(':', (string) $row['list_key'], 3);
    }

    $expectedQuad = [];
    foreach ($pdo->query("SELECT list_key, count FROM list_counts WHERE list_type = 'avec_bare_quad'") as $row) {
        $expectedQuad[(string) $row['list_key']] = (int) $row['count'];
    }

    $excludedSet = $parentSet + $siblingSet + $externalSet + array_fill_keys($overBudgetKeys, true);
    $producedKeys = [];
    $totalLinksProduced = 0;

    foreach ($tripleAnchors as [$x, $y, $z]) {
        $anchorLinks = $builder->build($x, $y, $z);
        Assert::same(1, $anchorLinks->queryCount, "queryCount doit rester 1 pour {$x}:{$y}:{$z}");

        $previousLetter = null;
        foreach ($anchorLinks->links as $link) {
            $quad = [$x, $y, $z, $link['letter']];
            sort($quad, SORT_STRING);
            $key = $quad[0] . ':' . $quad[1] . ':' . $quad[2] . ':' . $quad[3];

            Assert::true(isset($expectedQuad[$key]), "cle produite par le builder absente de list_counts : {$key}");
            Assert::true(!isset($excludedSet[$key]), "cle exclue produite par erreur (doublon ou hors budget) : {$key}");
            Assert::same($expectedQuad[$key], $link['count'], "compte divergent pour {$key}");
            Assert::same(
                '/mots/avec/' . strtolower($quad[0]) . '/' . strtolower($quad[1]) . '/' . strtolower($quad[2]) . '/' . strtolower($quad[3]),
                $link['url'],
                "URL divergente pour {$key}"
            );
            Assert::true($link['count'] > 0, "R5 : aucune entree a 0 attendue ({$key})");

            if ($previousLetter !== null) {
                Assert::true($previousLetter < $link['letter'], "ordre alphabetique attendu pour {$x}:{$y}:{$z}");
            }
            $previousLetter = $link['letter'];

            if (!isset($producedKeys[$key])) {
                $producedKeys[$key] = true;
                $totalLinksProduced++;
            }
        }
    }

    $expectedEligibleCount = count($expectedQuad) - count($excludedSet);
    Assert::same($expectedEligibleCount, $totalLinksProduced, 'total des quadruplets distincts produits doit egaler les lignes list_counts avec_bare_quad eligibles');

    foreach (array_keys($expectedQuad) as $key) {
        if (isset($excludedSet[$key])) {
            Assert::true(!isset($producedKeys[$key]), "ligne exclue (doublon ou hors budget) produite par erreur : {$key}");
        } else {
            Assert::true(isset($producedKeys[$key]), "ligne list_counts eligible jamais produite par le builder depuis aucune de ses pages source : {$key}");
        }
    }
};
