<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\AvecBareThreeLettersLinksBuilder;
use Tests\Support\Assert;

/**
 * App\Search\AvecBareThreeLettersLinksBuilder (D-049, palier 2 -> 3 de l'ouverture en entonnoir
 * de "avec" SANS AUCUN ancrage) : maillage "avec {X} {Y}" bare -> "avec {X} {Y} {Z}" bare -- lu
 * depuis list_counts (list_type 'avec_bare_triple'), jamais un calcul sur `terms` au runtime.
 * Verifie par force brute sur la vraie base, meme methodologie que
 * AvecBareTwoLettersLinksBuilderTest.php -- etendue aux TROIS positions possibles de la paire
 * source dans le triplet trie stocke (contre deux pour le palier precedent).
 */
return function (): void {
    $dbPath = __DIR__ . '/../../storage/dictionary_fr.sqlite';
    Assert::true(is_file($dbPath), 'base manquante : ' . $dbPath);

    $connection = new Connection($dbPath);
    $pdo = $connection->pdo();
    $builder = new AvecBareThreeLettersLinksBuilder($connection);

    // --- Paire "au milieu de l'alphabet" (I, N) : trois zones possibles. ---
    $links = $builder->build('I', 'N');
    Assert::same(1, $links->queryCount, 'une seule requete triviale sur list_counts');
    Assert::true($links->links !== [], 'sanity check : des mots avec I, N et une autre lettre existent');

    foreach ($links->links as $link) {
        $letters = ['I', 'N', $link['letter']];
        sort($letters, SORT_STRING);

        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM terms WHERE instr(normalized, ?) > 0 AND instr(normalized, ?) > 0 AND instr(normalized, ?) > 0');
        $stmt->execute(['I', 'N', $link['letter']]);
        $expected = (int) $stmt->fetch()['c'];

        Assert::same($expected, $link['count'], "avec I, N et {$link['letter']} : verifie par force brute");
        Assert::same('/mots/avec/' . strtolower($letters[0]) . '/' . strtolower($letters[1]) . '/' . strtolower($letters[2]), $link['url']);
        Assert::true(!in_array($link['letter'], ['I', 'N'], true), 'le partenaire ne doit jamais etre une des deux lettres source');
        Assert::true($link['count'] > 0, 'R5 : aucune entree a 0 attendue');
    }

    for ($i = 1; $i < count($links->links); $i++) {
        Assert::true($links->links[$i - 1]['letter'] < $links->links[$i]['letter'], 'ordre alphabetique attendu');
    }

    $linksReversedOrder = $builder->build('N', 'I');
    Assert::same($links->links, $linksReversedOrder->links, 'ordre de saisie (I,N) vs (N,I) sans effet sur le resultat');

    // ============================================================================================
    // Coherence structurelle des quatre constantes d'exclusion.
    // ============================================================================================
    $reflection = new ReflectionClass(AvecBareThreeLettersLinksBuilder::class);
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
    // Verification EXHAUSTIVE du maillage sur les 325 paires reelles (list_type 'avec_bare_pair',
    // D-049) -- pas un echantillon.
    // ============================================================================================
    $pairAnchorsStatement = $pdo->query("SELECT list_key FROM list_counts WHERE list_type = 'avec_bare_pair'");
    $pairAnchors = [];
    foreach ($pairAnchorsStatement as $row) {
        $pairAnchors[] = explode(':', (string) $row['list_key'], 2);
    }
    Assert::same(325, count($pairAnchors), 'sanity check : 325 paires avec_bare_pair reelles');

    $expectedTriple = [];
    foreach ($pdo->query("SELECT list_key, count FROM list_counts WHERE list_type = 'avec_bare_triple'") as $row) {
        $expectedTriple[(string) $row['list_key']] = (int) $row['count'];
    }

    $excludedSet = $parentSet + $siblingSet + $externalSet + array_fill_keys($overBudgetKeys, true);
    $producedKeys = [];
    $totalLinksProduced = 0;

    foreach ($pairAnchors as [$x, $y]) {
        $anchorLinks = $builder->build($x, $y);
        Assert::same(1, $anchorLinks->queryCount, "queryCount doit rester 1 pour {$x}:{$y}");

        $previousLetter = null;
        foreach ($anchorLinks->links as $link) {
            $triple = [$x, $y, $link['letter']];
            sort($triple, SORT_STRING);
            $key = $triple[0] . ':' . $triple[1] . ':' . $triple[2];

            Assert::true(isset($expectedTriple[$key]), "cle produite par le builder absente de list_counts : {$key}");
            Assert::true(!isset($excludedSet[$key]), "cle exclue produite par erreur (doublon ou hors budget) : {$key}");
            Assert::same($expectedTriple[$key], $link['count'], "compte divergent pour {$key}");
            Assert::same(
                '/mots/avec/' . strtolower($triple[0]) . '/' . strtolower($triple[1]) . '/' . strtolower($triple[2]),
                $link['url'],
                "URL divergente pour {$key}"
            );
            Assert::true($link['count'] > 0, "R5 : aucune entree a 0 attendue ({$key})");

            if ($previousLetter !== null) {
                Assert::true($previousLetter < $link['letter'], "ordre alphabetique attendu pour {$x}:{$y}");
            }
            $previousLetter = $link['letter'];

            if (!isset($producedKeys[$key])) {
                $producedKeys[$key] = true;
                $totalLinksProduced++;
            }
        }
    }

    $expectedEligibleCount = count($expectedTriple) - count($excludedSet);
    Assert::same($expectedEligibleCount, $totalLinksProduced, 'total des triplets distincts produits doit egaler les lignes list_counts avec_bare_triple eligibles');

    foreach (array_keys($expectedTriple) as $key) {
        if (isset($excludedSet[$key])) {
            Assert::true(!isset($producedKeys[$key]), "ligne exclue (doublon ou hors budget) produite par erreur : {$key}");
        } else {
            Assert::true(isset($producedKeys[$key]), "ligne list_counts eligible jamais produite par le builder depuis aucune de ses pages source : {$key}");
        }
    }
};
