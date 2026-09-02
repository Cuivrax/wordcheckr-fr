<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\SuffixAvecLinksBuilder;
use App\Search\WordListFilters;
use Tests\Support\Assert;

/**
 * App\Search\SuffixAvecLinksBuilder (D-045, famille entierement nouvelle Family::
 * WORD_LIST_TERMINANT_WITH_LETTER, symetrique de App\Search\PrefixAvecLinksBuilder cote
 * suffixe) : depuis une page /mots/terminant/{X} (deja indexee), liens vers chaque variante
 * /mots/terminant/{X}/avec/{Y} qui a au moins un resultat -- lu depuis list_counts (list_type
 * 'end_with'), jamais un calcul sur `terms` au runtime.
 */
return function (): void {
    $dbPath = __DIR__ . '/../../storage/dictionary_fr.sqlite';
    Assert::true(is_file($dbPath), 'base manquante : ' . $dbPath);

    $connection = new Connection($dbPath);
    $pdo = $connection->pdo();
    $builder = new SuffixAvecLinksBuilder($connection);

    $alphabet = str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ');

    // --- Collapse D-032 verifie explicitement sur les 26 cas diagonaux. ---
    foreach ($alphabet as $x) {
        $parentUrl = WordListFilters::fromPath('terminant/' . strtolower($x))?->canonicalUrl();
        $diagonalUrl = WordListFilters::fromPath('terminant/' . strtolower($x) . '/avec/' . strtolower($x))?->canonicalUrl();
        Assert::same($parentUrl, $diagonalUrl, "terminant/{$x}/avec/{$x} doit collapser vers la page parente (D-032)");
    }

    // --- Cas representatif : suffixe A (35 252 mots). ---
    $linksA = $builder->build('A');
    Assert::same(1, $linksA->queryCount);
    Assert::true($linksA->links !== [], 'sanity check : des mots terminant par A avec une autre lettre existent');

    foreach ($linksA->links as $link) {
        Assert::true($link['letter'] !== 'A', 'A (lettre de fin) ne doit jamais apparaitre comme lettre "avec" -- degenere, D-032');

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) c FROM terms WHERE normalized LIKE '%A' AND instr(normalized, ?) > 0"
        );
        $stmt->execute([$link['letter']]);
        Assert::same((int) $stmt->fetch()['c'], $link['count'], "A avec {$link['letter']} : verifie par force brute");
        Assert::same('/mots/terminant/a/avec/' . strtolower($link['letter']), $link['url']);
        Assert::true($link['count'] > 0, 'R5 : aucune entree a 0 attendue');
    }

    for ($i = 1; $i < count($linksA->links); $i++) {
        Assert::true($linksA->links[$i - 1]['letter'] < $linksA->links[$i]['letter'], 'ordre alphabetique attendu');
    }

    // sanity check : list_counts ne contient jamais la ligne degeneree "A:A".
    $diagonalRow = $pdo->query("SELECT COUNT(*) c FROM list_counts WHERE list_type = 'end_with' AND list_key = 'A:A'")->fetch()['c'];
    Assert::same(0, (int) $diagonalRow, 'sanity check : A:A absent de list_counts (exclu au precalcul, pas au builder)');

    // --- Aucun doublon PARENT/SOEUR trouve sur cet axe (D-045, verifie et non suppose). ---
    $reflection = new ReflectionClass(SuffixAvecLinksBuilder::class);
    Assert::same(0, count($reflection->getConstant('DUPLICATE_CONTENT_KEYS')), 'aucun doublon parent attendu sur ce palier (D-045)');
    Assert::same(0, count($reflection->getConstant('SIBLING_DUPLICATE_KEYS')), 'aucun doublon soeur attendu sur ce palier (D-045)');

    // --- Doublons de contenu CROISES avec une famille EXTERIEURE (D-047, balayage generique
    // post-D-045/D-046) -- constante remplie apres coup, ce test etait reste perime (attendait
    // encore 0 exclusion), corrige ici plutot que suppose vrai. Voir docs/DECISIONS.md D-047.
    $externalDuplicateKeys = $reflection->getConstant('EXTERNAL_DUPLICATE_KEYS');
    Assert::same(
        ['B:F', 'B:Q', 'B:V', 'B:Z', 'C:W', 'J:C', 'J:F', 'J:H', 'J:T', 'J:Z', 'P:J', 'Q:B', 'Q:F', 'Q:Z', 'U:W', 'V:G', 'V:P', 'V:Y', 'W:B', 'W:F', 'W:P', 'W:Q', 'W:V'],
        $externalDuplicateKeys,
        'exactement 23 doublons croises avec une famille exterieure attendus (D-047)'
    );
    $externalDuplicateSet = array_fill_keys($externalDuplicateKeys, true);

    foreach ($externalDuplicateKeys as $key) {
        [$suffix, $letter] = explode(':', $key, 2);
        $links = $builder->build($suffix);
        $found = array_values(array_filter($links->links, static fn (array $l): bool => $l['letter'] === $letter));
        Assert::true($found === [], "{$key} est un doublon de contenu croise (D-047) -- ne doit jamais etre produit");
    }

    // --- Verification EXHAUSTIVE du maillage sur les 26 pages sources reelles. ---
    $expectedStatement = $pdo->query("SELECT list_key, count FROM list_counts WHERE list_type = 'end_with'");
    $expected = [];
    foreach ($expectedStatement as $row) {
        $expected[(string) $row['list_key']] = (int) $row['count'];
    }
    Assert::same(631, count($expected), 'sanity check : 631 lignes end_with reelles (D-045, D-051/D-052 -- etait 621)');

    foreach (array_keys($expected) as $key) {
        [$suffix, $letter] = explode(':', $key, 2);
        Assert::true($suffix !== $letter, "ligne degeneree trouvee dans list_counts, aurait du etre exclue au precalcul : {$key}");
    }

    $producedKeys = [];
    $totalLinksProduced = 0;

    foreach ($alphabet as $suffix) {
        $links = $builder->build($suffix);
        Assert::same(1, $links->queryCount, "queryCount doit rester 1 pour {$suffix}");

        $previousLetter = null;
        foreach ($links->links as $link) {
            $key = $suffix . ':' . $link['letter'];

            Assert::true(isset($expected[$key]), "cle produite par le builder absente de list_counts : {$key}");
            Assert::true(!isset($externalDuplicateSet[$key]), "cle doublon croise (D-047) produite par erreur, aurait du etre exclue : {$key}");
            Assert::same($expected[$key], $link['count'], "compte divergent pour {$key}");
            Assert::same('/mots/terminant/' . strtolower($suffix) . '/avec/' . strtolower($link['letter']), $link['url']);
            Assert::true($link['count'] > 0, "R5 : aucune entree a 0 attendue ({$key})");

            if ($previousLetter !== null) {
                Assert::true($previousLetter < $link['letter'], "ordre alphabetique attendu pour {$suffix}");
            }
            $previousLetter = $link['letter'];

            Assert::true(!isset($producedKeys[$key]), "doublon de lien produit : {$key}");
            $producedKeys[$key] = true;
            $totalLinksProduced++;
        }
    }

    $expectedEligibleCount = count($expected) - count($externalDuplicateSet);
    Assert::same(608, $expectedEligibleCount, 'sanity check : 608 lignes eligibles (631 brutes - 23 doublons croises famille exterieure D-047, D-051/D-052 -- etait 598/621)');
    Assert::same($expectedEligibleCount, $totalLinksProduced, 'total des liens produits doit egaler les lignes list_counts end_with eligibles');
    Assert::same($expectedEligibleCount, count($producedKeys), 'aucun doublon, chaque cle eligible produite une seule fois');

    foreach (array_keys($expected) as $key) {
        if (isset($externalDuplicateSet[$key])) {
            Assert::true(!isset($producedKeys[$key]), "ligne exclue (doublon croise D-047) produite par erreur : {$key}");
        } else {
            Assert::true(isset($producedKeys[$key]), "ligne list_counts eligible jamais produite par le builder depuis sa page source : {$key}");
        }
    }
};
