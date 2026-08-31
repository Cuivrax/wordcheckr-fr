<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\SuffixAvecThreeLettersLinksBuilder;
use Tests\Support\Assert;

/**
 * App\Search\SuffixAvecThreeLettersLinksBuilder (D-045, palier 3 de Family::
 * WORD_LIST_TERMINANT_WITH_LETTER, symetrique de App\Search\PrefixAvecThreeLettersLinksBuilder
 * cote suffixe) : depuis "terminant/{X}/avec/{Y}/{Z}" (deja indexee, palier 2), liens vers
 * chaque variante "terminant/{X}/avec/{Y}/{Z}/{W}" qui a au moins un resultat -- lu depuis
 * list_counts (list_type 'end_with_triple'), jamais un calcul sur `terms` au runtime.
 */
return function (): void {
    $dbPath = __DIR__ . '/../../storage/dictionary_fr.sqlite';
    Assert::true(is_file($dbPath), 'base manquante : ' . $dbPath);

    $connection = new Connection($dbPath);
    $pdo = $connection->pdo();
    $builder = new SuffixAvecThreeLettersLinksBuilder($connection);

    // --- Cas representatif verifie par force brute : terminant/A/avec/B/C. ---
    $linksABC = $builder->build('A', 'B', 'C');
    Assert::same(1, $linksABC->queryCount);
    Assert::true($linksABC->links !== [], 'sanity check : des mots terminant par A avec B, C et une 4e lettre existent');

    foreach ($linksABC->links as $link) {
        Assert::true(!in_array($link['letter'], ['A', 'B', 'C'], true), 'la lettre produite ne doit jamais etre le suffixe ni les lettres source');

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) c FROM terms WHERE normalized LIKE '%A' AND instr(normalized, 'B') > 0 AND instr(normalized, 'C') > 0 AND instr(normalized, ?) > 0"
        );
        $stmt->execute([$link['letter']]);
        Assert::same((int) $stmt->fetch()['c'], $link['count'], "A+B+C+{$link['letter']} : verifie par force brute");

        $expectedLetters = ['B', 'C', $link['letter']];
        sort($expectedLetters);
        Assert::same(
            '/mots/terminant/a/avec/' . strtolower($expectedLetters[0]) . '/' . strtolower($expectedLetters[1]) . '/' . strtolower($expectedLetters[2]),
            $link['url']
        );
        Assert::true($link['count'] > 0, 'R5 : aucune entree a 0 attendue');
    }

    for ($i = 1; $i < count($linksABC->links); $i++) {
        Assert::true($linksABC->links[$i - 1]['letter'] < $linksABC->links[$i]['letter'], 'ordre alphabetique attendu');
    }

    // --- Reflection : les deux listes figees ont les comptes reels calcules pour D-045. ---
    $reflection = new ReflectionClass(SuffixAvecThreeLettersLinksBuilder::class);
    $duplicateParentKeys = $reflection->getConstant('DUPLICATE_PARENT_KEYS');
    $siblingDuplicateKeys = $reflection->getConstant('SIBLING_DUPLICATE_KEYS');
    Assert::same(3567, count($duplicateParentKeys), 'exactement 3567 doublons parent attendus (D-045)');
    Assert::same(2319, count($siblingDuplicateKeys), 'exactement 2319 doublons soeurs attendus (D-045)');
    Assert::same(count($duplicateParentKeys), count(array_unique($duplicateParentKeys)), 'aucun doublon dans la liste figee elle-meme');
    Assert::same(count($siblingDuplicateKeys), count(array_unique($siblingDuplicateKeys)), 'aucun doublon dans la liste figee elle-meme');
    Assert::same(0, count(array_intersect($duplicateParentKeys, $siblingDuplicateKeys)), 'les deux listes doivent rester disjointes');

    // --- Cas connu, verifie manuellement (rapport de tache D-045) : A:B:J:W est un doublon
    // --- PARENT exact contre 'A:J:W' -- ne doit jamais apparaitre depuis terminant/a/avec/j/w. ---
    Assert::true(in_array('A:B:J:W', $duplicateParentKeys, true), 'sanity check : A:B:J:W fait bien partie de la liste figee');
    $linksAJW = $builder->build('A', 'J', 'W');
    $foundB = array_values(array_filter($linksAJW->links, static fn (array $l): bool => $l['letter'] === 'B'));
    Assert::true($foundB === [], 'A:B:J:W est un doublon parent exact (D-045) -- ne doit jamais etre produit depuis terminant/a/avec/j/w');

    // --- Toutes les cles des deux listes figees respectent le format et l'ordre attendus. ---
    foreach (array_merge($duplicateParentKeys, $siblingDuplicateKeys) as $key) {
        $parts = explode(':', $key);
        Assert::same(4, count($parts), "format de cle invalide : {$key}");
        [$suffix, $l1, $l2, $l3] = $parts;
        Assert::true($l1 < $l2 && $l2 < $l3, "lettres non ordonnees dans la cle figee : {$key}");
        Assert::true($suffix !== $l1 && $suffix !== $l2 && $suffix !== $l3, "cle degeneree (D-032) trouvee dans une liste figee : {$key}");
    }

    // --- R5 sur quelques triplets representatifs. ---
    foreach ([['A', 'B', 'C'], ['E', 'S', 'T'], ['S', 'A', 'B']] as [$suffix, $l1, $l2]) {
        $links = $builder->build($suffix, $l1, $l2);
        foreach ($links->links as $link) {
            Assert::true($link['count'] > 0, "R5 : aucune entree a 0 attendue ({$suffix}:{$l1}:{$l2}:{$link['letter']})");
        }
    }
};
