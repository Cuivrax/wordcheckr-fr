<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\AvecBareTwoLettersLinksBuilder;
use Tests\Support\Assert;

/**
 * App\Search\AvecBareTwoLettersLinksBuilder (D-049, palier 1 -> 2 de l'ouverture en entonnoir de
 * "avec" SANS AUCUN ancrage) : maillage "avec {X}" bare -> "avec {X} {Y}" bare -- lu depuis
 * list_counts (list_type 'avec_bare_pair', precalcule par scripts/build_explore_hub_counts.php),
 * jamais un calcul sur `terms` au runtime. Verifie par force brute sur la vraie base (lecture
 * seule), meme methodologie que AvecTwoLettersLinksBuilderTest.php -- sans la dimension longueur.
 */
return function (): void {
    $dbPath = __DIR__ . '/../../storage/dictionary_fr.sqlite';
    Assert::true(is_file($dbPath), 'base manquante : ' . $dbPath);

    $connection = new Connection($dbPath);
    $pdo = $connection->pdo();
    $builder = new AvecBareTwoLettersLinksBuilder($connection);

    // --- Sanity check : "avec A" doit trouver des partenaires dans les deux zones possibles
    // --- (avant A dans l'alphabet -- aucune, A est la premiere lettre -- et apres A). ---
    $linksA = $builder->build('A');
    Assert::same(1, $linksA->queryCount, 'une seule requete triviale sur list_counts');
    Assert::true($linksA->links !== [], 'sanity check : des mots avec A et une autre lettre existent');

    foreach ($linksA->links as $link) {
        $letters = ['A', $link['letter']];
        sort($letters, SORT_STRING);

        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM terms WHERE instr(normalized, ?) > 0 AND instr(normalized, ?) > 0');
        $stmt->execute(['A', $link['letter']]);
        $expected = (int) $stmt->fetch()['c'];

        Assert::same($expected, $link['count'], "avec A et {$link['letter']} : verifie par force brute");
        Assert::same('/mots/avec/' . strtolower($letters[0]) . '/' . strtolower($letters[1]), $link['url']);
        Assert::true($link['letter'] !== 'A', 'le partenaire ne doit jamais etre la lettre source');
        Assert::true($link['count'] > 0, 'R5 : aucune entree a 0 attendue');
    }

    for ($i = 1; $i < count($linksA->links); $i++) {
        Assert::true($linksA->links[$i - 1]['letter'] < $linksA->links[$i]['letter'], 'ordre alphabetique attendu');
    }

    // --- Reciprocite : (I, N) doit se retrouver dans les deux sens. ---
    $linksI = $builder->build('I');
    $nFromI = array_values(array_filter($linksI->links, static fn (array $l): bool => $l['letter'] === 'N'));
    $linksN = $builder->build('N');
    $iFromN = array_values(array_filter($linksN->links, static fn (array $l): bool => $l['letter'] === 'I'));

    Assert::true(count($nFromI) === 1 && count($iFromN) === 1, 'I->N et N->I doivent tous deux exister');
    Assert::same($nFromI[0]['count'], $iFromN[0]['count'], 'meme compte, quel que soit le sens de lecture');
    Assert::same($nFromI[0]['url'], $iFromN[0]['url'], 'meme URL canonique, quel que soit le sens de lecture');

    // ============================================================================================
    // Coherence structurelle des quatre constantes d'exclusion (D-049) -- meme pattern de
    // verification (reflection + disjonction) que AvecFourLettersLinksBuilderTest.php.
    // ============================================================================================
    $reflection = new ReflectionClass(AvecBareTwoLettersLinksBuilder::class);
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
    // Verification EXHAUSTIVE du maillage sur les 26 lettres reelles (list_type 'avec_bare',
    // D-049) -- pas un echantillon.
    // ============================================================================================
    $singleAnchorsStatement = $pdo->query("SELECT list_key FROM list_counts WHERE list_type = 'avec_bare'");
    $singleAnchors = [];
    foreach ($singleAnchorsStatement as $row) {
        $singleAnchors[] = (string) $row['list_key'];
    }
    Assert::same(26, count($singleAnchors), 'sanity check : 26 lettres avec_bare reelles (D-049)');

    $expectedPair = [];
    foreach ($pdo->query("SELECT list_key, count FROM list_counts WHERE list_type = 'avec_bare_pair'") as $row) {
        $expectedPair[(string) $row['list_key']] = (int) $row['count'];
    }
    Assert::same(325, count($expectedPair), 'sanity check : 325 paires avec_bare_pair reelles (C(26,2), D-049)');

    $excludedSet = $parentSet + $siblingSet + $externalSet + array_fill_keys($overBudgetKeys, true);
    $producedKeys = [];
    $totalLinksProduced = 0;

    foreach ($singleAnchors as $letter) {
        $anchorLinks = $builder->build($letter);
        Assert::same(1, $anchorLinks->queryCount, "queryCount doit rester 1 pour {$letter}");

        $previousLetter = null;
        foreach ($anchorLinks->links as $link) {
            $pair = [$letter, $link['letter']];
            sort($pair, SORT_STRING);
            $key = $pair[0] . ':' . $pair[1];

            Assert::true(isset($expectedPair[$key]), "cle produite par le builder absente de list_counts : {$key}");
            Assert::true(!isset($excludedSet[$key]), "cle exclue produite par erreur (doublon ou hors budget) : {$key}");
            Assert::same($expectedPair[$key], $link['count'], "compte divergent pour {$key}");
            Assert::same('/mots/avec/' . strtolower($pair[0]) . '/' . strtolower($pair[1]), $link['url'], "URL divergente pour {$key}");
            Assert::true($link['count'] > 0, "R5 : aucune entree a 0 attendue ({$key})");

            if ($previousLetter !== null) {
                Assert::true($previousLetter < $link['letter'], "ordre alphabetique attendu pour {$letter}");
            }
            $previousLetter = $link['letter'];

            if (!isset($producedKeys[$key])) {
                $producedKeys[$key] = true;
                $totalLinksProduced++;
            }
        }
    }

    $expectedEligibleCount = count($expectedPair) - count($excludedSet);
    Assert::same($expectedEligibleCount, $totalLinksProduced, 'total des paires distinctes produites doit egaler les lignes list_counts avec_bare_pair eligibles');

    foreach (array_keys($expectedPair) as $key) {
        if (isset($excludedSet[$key])) {
            Assert::true(!isset($producedKeys[$key]), "ligne exclue (doublon ou hors budget) produite par erreur : {$key}");
        } else {
            Assert::true(isset($producedKeys[$key]), "ligne list_counts eligible jamais produite par le builder depuis aucune de ses deux ancres : {$key}");
        }
    }
};
